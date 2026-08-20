# SFTP Backup Sync

A [Pelican Panel](https://pelican.dev) plugin that automatically copies every
completed server backup to a destination **each server owner picks and
configures themselves** — no admin setup required per server.

[![Latest release](https://img.shields.io/github/v/release/TestrGames/sftp-backup-sync?label=release)](https://github.com/TestrGames/sftp-backup-sync/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## What it does

Backups already land wherever Pelican stores them (Wings local disk or S3).
This plugin sends an automatic *second copy* somewhere the server owner
controls — their own SFTP server, their own Nextcloud, their own OneDrive or
Google Drive. Nobody else on the panel can see or touch that configuration.

- **Self-service** — configured entirely from the client area
  (Server → **Backup Sync**), not the admin panel
- **Four destinations** — SFTP, WebDAV (Nextcloud etc.), OneDrive, Google Drive
- **Automatic** — every completed backup is queued for sync the moment it finishes
- **Catches up on old backups** — "Sync missing backups now" handles anything
  made before the destination was configured
- **Visible status** — a panel on the page shows the last successful sync, the
  last error (the real cause, not a generic wrapper message), and recent
  attempt history
- **Discord notifications** — optional per-server webhook, separately
  toggleable for successes and failures
- **Delegatable** — grant a subuser the "Backup Sync" permission if the owner
  wants to share the responsibility

## Install

1. Grab the latest `sftp-backup-sync.zip` from
   [Releases](https://github.com/TestrGames/sftp-backup-sync/releases)
2. Admin → Plugins → **Import** → upload the zip
3. `php artisan optimize:clear`
4. As a server owner: open a server → **Backup Sync** in the sidebar → pick a
   protocol, fill in the details, toggle **Forward backups**, Save

That covers SFTP and WebDAV. OneDrive and Google Drive need one extra
admin-only setup step first — see below.

<details>
<summary><strong>Setting up OneDrive / Google Drive (one-time, admin only)</strong></summary>

<br>

Both use OAuth2, so before any server owner can connect their account, an
admin registers an app with Microsoft/Google **once** and pastes its client
ID/secret into the plugin's own settings page
(Admin → Plugins → SFTP Backup Sync → Settings). This is global, not
per-server — after this one-time step, every server owner just clicks
"Connect" and logs into their own account.

**OneDrive**

1. [portal.azure.com](https://portal.azure.com) → *App registrations* → *New registration*
2. Add a **Web** redirect URI — the plugin's settings page shows the exact
   URL to use (based on your panel's `APP_URL`, something like
   `https://your-panel/plugin/sftp-backup-sync/onedrive/callback`)
3. Under *API permissions*, add delegated Microsoft Graph permissions
   `Files.ReadWrite` and `offline_access` (the second one is required —
   without it, Microsoft never issues a refresh token and the connection
   dies after ~1 hour)
4. Under *Certificates & secrets*, create a client secret
5. Paste the *Application (client) ID* and the secret's *value* into the
   plugin settings page

**Google Drive**

1. [console.cloud.google.com](https://console.cloud.google.com) →
   *APIs & Services* → *Credentials* → *Create credentials* →
   *OAuth client ID* → type **Web application**
2. Add the redirect URI shown on the plugin's settings page
   (`https://your-panel/plugin/sftp-backup-sync/google_drive/callback`)
3. Under *APIs & Services* → *Library*, enable the **Google Drive API**
4. If the OAuth consent screen is in "Testing" mode, add each user who wants
   to connect as a test user (or publish/verify the app for everyone)
5. Paste the client ID and secret into the plugin settings page

The plugin only ever requests the narrow `drive.file` scope for Google (it
can only see files it created itself, not your whole Drive) and
`Files.ReadWrite` for OneDrive.

</details>

## Requirements

A running queue worker. The upload happens in a background job — if
`php artisan queue:work` (or Horizon, or a systemd/supervisor unit) isn't
running for the panel, backups will sit queued and never actually get
pushed. Most production Pelican installs already run one for mail and other
core features.

## Updating

The panel checks [`update.json`](update.json) for a newer version and shows
an **Update** button in Admin → Plugins when one exists. Two caching layers
can delay that showing up:

| Layer | Delay | Force it |
|---|---|---|
| Panel's own update check | 10 minutes | `php artisan tinker --execute="cache()->forget('plugins.sftp-backup-sync.update');"` |
| GitHub's CDN serving `update.json` | a few minutes | just wait, nothing to force |

After updating, **restart the queue worker** —
`php artisan queue:restart` (or restart the container). It's a long-running
process that keeps old job code loaded in memory until restarted,
separately from anything `optimize:clear` touches.

<details>
<summary>Publishing a new version (maintainers)</summary>

<br>

1. Bump `"version"` in `plugin.json`
2. Commit, then `git tag vX.Y.Z && git push origin vX.Y.Z` (must match
   `plugin.json`'s version exactly)
3. [`.github/workflows/release.yml`](.github/workflows/release.yml) takes it
   from there — builds the zip, creates the GitHub Release, and rewrites
   `update.json`, all pushed back to `main` automatically

</details>

<details>
<summary><strong>How it works, technically</strong></summary>

<br>

- **Trigger** — Panel doesn't create backups itself, Wings does, then
  reports back via a webhook that fires the Laravel event
  `App\Events\Server\BackupCompleted`. A listener
  (`src/Listeners/QueueSftpBackupForward.php`) queues a job if the server
  has sync enabled.
- **Transfer** — the job (`src/Jobs/PushBackupToSftp.php`) resolves a
  temporary download link the same way Pelican's own restore/download flow
  does, streams the backup to a temp file, then streams it to the
  destination. Nothing is ever fully buffered in memory.
  - SFTP uses `league/flysystem-sftp-v3`.
  - WebDAV is plain HTTP (`MKCOL` per path segment, then `PUT`) via
    Laravel's `Http` client — deliberately not a library.
    `league/flysystem-webdav` (`Sabre\DAV\Client` underneath) unreliably
    handled directory creation against a real Nextcloud instance during
    testing; plain `curl` against the exact same URLs always worked, so
    that's what this does instead.
  - OneDrive/Google Drive use the Microsoft Graph / Drive REST APIs
    directly, via resumable, chunked upload sessions.
- **Storage** — per-server config lives in `sftp_backup_targets`, one row
  per server. `password`/`private_key`/`passphrase`/OAuth tokens are stored
  using Laravel's `encrypted` Eloquent cast (AES via `APP_KEY`).
- **History** — every sync attempt is logged to `backup_sftp_sync_logs`,
  which drives both the status panel and "Sync missing backups now"
  (it needs to know what's already synced).
- **Page registration** — the settings page is registered into the client
  "server" Filament panel via `SftpBackupSyncPlugin::register(Panel $panel)`
  → `$panel->discoverPages(...)`. Access is gated by a custom subuser
  permission (`backup_sync.update`); the server owner always passes it
  (owners bypass permission checks), a subuser needs it explicitly granted.
- **UI buttons** — Save / "Sync missing backups" / Connect / Disconnect are
  plain HTML wired to Livewire's `wire:click`, not a Filament `Action`.
  Three different first-party ways of rendering a Filament Action on this
  page — footer actions, header actions, and the schema-level
  `Actions::make()` component — all failed to render anything on the panel
  install this was built against. Rather than keep fighting that, this uses
  `wire:click` directly, at the cost of losing Filament's built-in
  confirmation modals (replaced with a plain JS `confirm()`).
- **OAuth flow** — routes are registered via a `RouteServiceProvider` under
  `web`+`auth` middleware. Clicking "Connect" stashes `server_id`/`user_id`
  and a random `state` value in the session, then redirects to
  Microsoft/Google. The callback checks `state` against session (CSRF
  protection — which server this is for never round-trips through the
  browser), exchanges the code for tokens, and stores them encrypted. The
  sync job refreshes the access token automatically once it's within a
  minute of expiring.
- **Discord notifications** — sent as a rich embed via a plain webhook POST
  (`Http::post($webhookUrl, ['embeds' => [...]])`), no Discord SDK. Success
  fires inline in `handle()` (runs once, doesn't retry). Failure fires from
  the job's `failed()` method instead of the `catch` block in `handle()` —
  `failed()` is a Laravel queue lifecycle hook that runs exactly once,
  after *all* retries (`$tries = 3`) are exhausted, so a flaky destination
  doesn't send three pings for one real failure. Sending the notification
  itself is wrapped in its own try/catch — a broken webhook URL can never
  fail or retry the sync job.

</details>

<details>
<summary>Upgrading from 1.0.0 (the old admin-only SFTP tab)</summary>

<br>

1.1.0 moved configuration out of Admin → Servers → *server* entirely and
into the client panel, and added WebDAV as a second protocol. Any targets
configured under 1.0.0 keep working unchanged — just update normally, no
data is lost. The admin-side tab and header action are gone; use the client
panel instead.

</details>

## Notes

- One destination per server, one protocol at a time.
- Remote layout: `<remote_path>/<server-uuid>/<backup-uuid>.tar.gz` for
  SFTP/WebDAV/OneDrive. Google Drive has no real path concept, so
  `remote_path` is treated as a folder name, resolved or created on first
  upload.
- If a sync fails, the full error — including the real underlying cause,
  not just a generic wrapper message — is stored on the sync log and on the
  target's `last_error`, visible right on the Backup Sync page.
- Failed jobs retry up to 3 times, then stay failed — rerun with "Sync
  missing backups now" once the underlying issue is fixed.
- Discord notifications are per-server (own webhook per destination), off
  by default for successes and on by default for failures — toggle either
  independently on the Backup Sync page.

## License

MIT — see [LICENSE](LICENSE).
