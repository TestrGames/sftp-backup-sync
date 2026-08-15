# SFTP Backup Sync

Pelican Panel plugin that copies every completed server backup to a
destination the **server owner configures themselves** — SFTP or
WebDAV (e.g. Nextcloud) — in addition to wherever Pelican already
stores it (Wings local disk or S3). Fully self-service: each server
owner (or a subuser explicitly granted the `backup_sync.update`
permission) manages their own destination from the client area —
Server → **Backup Sync** in the sidebar — with no admin involvement per
server.

## How it works

- Panel doesn't create backups itself — Wings does, then reports back
  via a webhook that fires the Laravel event `App\Events\Server\BackupCompleted`.
  This plugin listens for that event (`src/Listeners/QueueSftpBackupForward.php`)
  and, if the server has sync enabled, queues a job.
- The job (`src/Jobs/PushBackupToSftp.php`) resolves a temporary download
  link the same way Pelican's own restore/download flow does
  (`BackupAdapterService::get($backup->backupHost->schema)->getDownloadLink()`),
  streams the backup to a temp file, then streams it up to the
  configured destination — via `league/flysystem-sftp-v3` for SFTP or
  `league/flysystem-webdav` for WebDAV. Nothing is ever fully buffered
  in memory.
- Per-server config lives in its own table (`sftp_backup_targets`), one
  row per server, with `password` / `private_key` / `passphrase` stored
  using Laravel's `encrypted` Eloquent cast (AES via `APP_KEY` — same
  mechanism the `generic-oidc-providers` plugin uses for OAuth client
  secrets).
- Every sync attempt (auto or manual) is logged to `backup_sftp_sync_logs`
  (`backup_id`, `target_id`, `status`, `error`, `synced_at`), which is
  also how the manual "sync missing backups" action knows which backups
  are already synced vs. still pending.
- The settings page (`src/Filament/Server/Pages/BackupSync.php`) is
  registered directly into the client "server" Filament panel via
  `SftpBackupSyncPlugin::register(Panel $panel)` →
  `$panel->discoverPages(...)`, the same mechanism the official
  `player-counter` plugin uses to add a page to that panel. Access is
  gated by a custom subuser permission (`backup_sync.update`, registered
  via `Subuser::registerCustomPermissions()`); the server owner always
  passes this check (owners bypass permission checks in
  `ServerPolicy::before()`), a subuser needs it explicitly granted.

## Why no OneDrive / Google Drive

Both require an OAuth2 login flow (no username+password / app-password
option), which is a materially bigger feature — a registered OAuth app
per provider, a connect/callback route, and refresh-token storage and
rotation per user. Not implemented here. If you want either without
writing that flow: run `rclone serve sftp` in front of an `rclone`
remote configured for OneDrive/Google Drive/anything else `rclone`
supports, and point this plugin's SFTP fields at that bridge — it works
unmodified since the plugin just speaks generic SFTP.

## Requirements

- **A running queue worker.** The upload happens in a queued job, not
  inline — if you don't already have `php artisan queue:work` (or
  Horizon, or a systemd/supervisor unit) running for the panel, backups
  will never actually get pushed; they'll just sit queued. Most
  production Pelican installs already run a queue worker for mail/other
  core features, but double-check.

## Install

1. Download the latest `sftp-backup-sync.zip` from
   [Releases](https://github.com/TestrGames/sftp-backup-sync/releases)
   and upload it via the panel's plugin import screen (Admin → Plugins
   → Import). Or `wget`/`curl` it directly into
   `/var/www/pelican/plugins/sftp-backup-sync` on the panel server and
   unzip.
2. `php artisan p:plugin:install` and pick `sftp-backup-sync` (skip if
   installed via the UI — the panel runs `composer require` for both
   Flysystem adapters and the plugin's migrations for you as part of
   that step).
3. `php artisan optimize:clear`
4. As a server owner: open a server → **Backup Sync** in the sidebar →
   pick SFTP or WebDAV, fill in the connection details, toggle "Forward
   backups", Save.
5. To let a subuser manage this themselves, grant them the "Backup
   Sync" permission group when editing their subuser access (Server →
   Users → *subuser* → permissions list — it shows up automatically
   once the plugin is installed).

## Updating

Once installed from a release that has `update_url` set in `plugin.json`
(everything from v1.1.0 onward), the panel checks
[`update.json`](update.json) for a newer version and shows an **Update**
button in Admin → Plugins when one exists — no manual re-upload needed.

To publish a new version:

1. Bump `"version"` in `plugin.json`.
2. Commit, then tag and push: `git tag vX.Y.Z && git push origin vX.Y.Z`
   (the tag must match `plugin.json`'s version exactly, e.g. `1.2.0` →
   `v1.2.0` — the release workflow checks this and fails otherwise).
3. [`.github/workflows/release.yml`](.github/workflows/release.yml) takes
   it from there: builds the zip, creates the GitHub Release with it
   attached, and rewrites `update.json` to point at it — all pushed back
   to `main` automatically.

`raw.githubusercontent.com` caches for a few minutes, so the panel might
not see a brand-new release immediately.

## Upgrading from 1.0.0 (admin-only SFTP tab)

1.1.0 moves configuration out of Admin → Servers → *server* entirely
and into the client panel, and adds WebDAV as a second protocol. Any
targets configured under 1.0.0 keep working unchanged (same table, new
`protocol`/`base_url` columns default to the old SFTP-only behavior) —
just re-upload the plugin and re-run install; no data is lost. The
admin-side tab and header action are gone; use the client panel instead.

## Notes / caveats

- One destination per server (not multiple), and it's SFTP *or* WebDAV,
  not both at once.
- The remote layout is `<remote_path>/<server-uuid>/<backup-uuid>.tar.gz`.
- If a sync fails, the error is stored on both the log row and the
  target's `last_error` column; check `storage/logs/laravel.log` for the
  full exception, or query `backup_sftp_sync_logs`.
- Failed jobs retry up to 3 times (`PushBackupToSftp::$tries`), then stay
  failed — rerun via the "Sync missing backups now" action once the
  underlying issue (credentials, disk space, network) is fixed.
