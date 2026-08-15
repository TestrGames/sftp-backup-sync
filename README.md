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

- Save / "Sync missing backups" / Connect / Disconnect are **plain HTML
  buttons wired to `wire:click`** (`BackupSync::actionsBarHtml()`), not
  a Filament `Action`. Three different first-party ways of rendering a
  Filament Action on this page (`Section::footerActions()`, page header
  actions, and the schema-level `Actions::make()` component) all failed
  to render anything on this panel install — the common factor across
  all three is that they render a `Filament\Actions\Action`, which
  something about this install/version can't do on this page type, even
  though every ordinary form field renders and reacts fine. Rather than
  keep guessing at Filament/Pelican internals, this sidesteps the Action
  system entirely and uses Livewire's own `wire:click` directly on raw
  HTML — the same mechanism the live protocol-switching on this form
  already proves works — at the cost of losing Filament's built-in
  confirmation modals (replaced with a plain JS `confirm()`) and exact
  button styling.
- Panel doesn't create backups itself — Wings does, then reports back
  via a webhook that fires the Laravel event `App\Events\Server\BackupCompleted`.
  This plugin listens for that event (`src/Listeners/QueueSftpBackupForward.php`)
  and, if the server has sync enabled, queues a job.
- The job (`src/Jobs/PushBackupToSftp.php`) resolves a temporary download
  link the same way Pelican's own restore/download flow does
  (`BackupAdapterService::get($backup->backupHost->schema)->getDownloadLink()`),
  streams the backup to a temp file, then streams it up to the
  configured destination. SFTP uses `league/flysystem-sftp-v3`. WebDAV
  is plain HTTP (`MKCOL` per path segment, then `PUT`) via Laravel's
  `Http` client, not a library — `league/flysystem-webdav`
  (`Sabre\DAV\Client` underneath) reliably mis-handled directory
  creation against a real Nextcloud instance in testing (misreporting
  "already exists" as a hard failure, or vice versa, with no useful
  underlying cause exposed), while plain `curl` against the exact same
  URLs always worked — so that's exactly what this does instead. Nothing
  is ever fully buffered in memory for either protocol.
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

## OneDrive / Google Drive setup (one-time, admin only)

Both use OAuth2, so unlike SFTP/WebDAV there's a one-time setup step
before any server owner can connect their account: you register an
OAuth app with Microsoft/Google, and paste its client ID/secret into
the plugin's own settings page (Admin → Plugins → SFTP Backup Sync →
Settings). This is a *global* one-time step, not per server or per
user — once it's done, every server owner just clicks "Connect" and
logs into their own account.

**OneDrive:**
1. [portal.azure.com](https://portal.azure.com) → *App registrations* → *New registration*.
2. Add a **Web** platform redirect URI. The plugin's settings page shows
   you the exact URL to use (it's derived from your panel's own
   `APP_URL`, something like `https://your-panel/plugin/sftp-backup-sync/onedrive/callback`).
3. Under *API permissions*, add the delegated Microsoft Graph
   permissions `Files.ReadWrite` and `offline_access` (the latter is
   required — without it Microsoft never issues a refresh token, and
   the connection dies after ~1 hour).
4. Under *Certificates & secrets*, create a new client secret.
5. Paste the *Application (client) ID* and the secret's *value* (not its
   ID) into the plugin settings page.

**Google Drive:**
1. [console.cloud.google.com](https://console.cloud.google.com) → *APIs
   & Services* → *Credentials* → *Create credentials* → *OAuth client
   ID* → type **Web application**.
2. Add the authorized redirect URI shown on the plugin's settings page
   (`https://your-panel/plugin/sftp-backup-sync/google_drive/callback`).
3. Under *APIs & Services* → *Library*, enable the **Google Drive API**
   for the project.
4. If the OAuth consent screen is in "Testing" mode, every user who
   wants to connect their Drive has to be added as a test user first (or
   publish the app / verify it for unlimited users).
5. Paste the client ID and secret into the plugin settings page.

The plugin only ever requests the narrow `drive.file` scope for Google
(it can only see files it created itself, not your whole Drive) and
`Files.ReadWrite` for OneDrive.

## How the OAuth connect flow works

- Routes are registered via a `RouteServiceProvider`
  (`src/Providers/SftpBackupSyncRoutesProvider.php`) under `web` + `auth`
  middleware — the plugin auto-discovery mechanism only auto-wires
  `src/Providers/*` as generic service providers, so route registration
  has to happen explicitly, and needs those two middleware groups
  itself (bare plugin routes get none by default) for `$request->user()`
  to resolve to the logged-in panel user at all.
- Clicking "Connect" (`src/Http/Controllers/OAuthConnectController.php::connect()`)
  checks you can manage that server, stashes `server_id`/`user_id` and a
  random `state` value in the **session**, then redirects to
  Microsoft/Google's real login screen.
- The provider redirects back to a callback route; the handler checks
  the returned `state` against the one stored in session (CSRF
  protection — nothing about which server this is for ever round-trips
  through the browser or the OAuth `state` param itself, it only ever
  comes from the server-side session), exchanges the `code` for tokens,
  and stores them encrypted on the server's `sftp_backup_targets` row.
- The sync job refreshes the access token automatically when it's
  within a minute of expiring, using the stored refresh token — you
  never have to reconnect unless you explicitly disconnect, or the
  provider revokes the grant.

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

- One destination per server (not multiple), and it's one protocol at a
  time — SFTP, WebDAV, OneDrive, or Google Drive.
- For SFTP/WebDAV the remote layout is
  `<remote_path>/<server-uuid>/<backup-uuid>.tar.gz`. OneDrive uses the
  same nested path (Graph API auto-creates missing folders). Google
  Drive has no real path concept, so `remote_path` there is treated as
  a single folder name, resolved or created once per upload — nested
  `a/b/c` style paths also work, just a bit more API round-trips.
- If a sync fails, the error is stored on both the log row and the
  target's `last_error` column; check `storage/logs/laravel.log` for the
  full exception, or query `backup_sftp_sync_logs`.
- Failed jobs retry up to 3 times (`PushBackupToSftp::$tries`), then stay
  failed — rerun via the "Sync missing backups now" action once the
  underlying issue (credentials, disk space, network, or a revoked
  OAuth grant) is fixed.
