# Install the upload-ready release

Download `neo-podcast-generator-<version>.zip` from Releases. The ZIP contains the
PHP application and its runtime dependencies; no build or Composer command is
needed on your hosting account. GitHub's Source code downloads are for developers
and VPS/server builds, not this installation procedure.

Download the matching `.zip.sha256` file as well. Before extracting, verify the
ZIP with `sha256sum --check neo-podcast-generator-<version>.zip.sha256` on Linux,
or compare `Get-FileHash neo-podcast-generator-<version>.zip -Algorithm SHA256`
with the checksum file in PowerShell. The installed version is recorded in
`_neo/VERSION`. Never upload the example PodcastGenerator distribution over Neo;
these are separate applications and this package does not migrate legacy data.

## Requirements

Use PHP 8.4 or newer within PHP 8.x, with PDO SQLite, DOM, Fileinfo, sessions, and
HTTPS. SQLite needs writable local storage with filesystem locking; network
storage is unsupported. Allow PHP at least 128 MiB memory, 6 MiB POST bodies,
5 MiB file uploads, and sufficient execution time for inspecting an MP3. Your
host's request, disk, bandwidth, and execution limits still apply. MP3 uploads use
1 MiB chunks; the configured total-file limit defaults to 10 GiB and should be
lowered to suit your hosting account. Allow space for the upload and final copy.

Shared mode publishes MP3 files without shell commands, cron, or a worker.
Automatic MOV/MP4-to-MP3 conversion requires the full server installation.

## Upload and configure

1. Extract the ZIP into your domain's web root, or a subfolder such as `podcast`.
   Include hidden `.htaccess` files. Keep the `_neo` directory intact.
2. Apply the web-server rules below **before** entering secrets or completing
   setup. Private application files and media must never be directly accessible.
3. Using the hosting file manager, copy `_neo/config.example.php` to
   `_neo/config.php`. Keep `NEO_MODE` set to `shared`, supply your own random
   `NEO_SETUP_TOKEN` of at least 20 characters, and set `NEO_BASE_PATH` to
   `/podcast` for that subfolder or an empty string for the domain root.
4. Set `NEO_DATA` to a writable absolute directory, preferably outside the web
   root. Otherwise the default `_neo/var` is protected by the supplied server
   rules. Grant write access only to the PHP account; do not use world-writable
   permissions. Keep application code read-only where your host supports it.
5. Keep `NEO_SECURE_COOKIES` set to `1`. Visit your HTTPS installation URL and
   complete setup using the token. Set the public show URL to that same origin
   and configured path, for example `https://example.com/podcast`.
6. Upload artwork in Settings, create an episode, upload an MP3, and publish it.
   Check the audio feed and play/download its enclosure from another device.

Environment variables override the PHP configuration when supplied by a host.
Setup locks after the administrator is created. Never distribute a configured
copy containing your token, credentials, database, or media.

## Apache 2.4

The ZIP includes `.htaccess` rules for both root and subfolder installations.
Enable `mod_rewrite` and allow the included `Options`, `DirectoryIndex`, rewrite,
and authorization directives (`AllowOverride All` in a dedicated directory is
one option). Ensure the host permits PHP execution for `index.php`.

Only `index.php`, `app.css`, and `app.js` are served directly. All application
routes use the front controller. If you receive a 500 response immediately after
uploading, ask your host to check its override permissions and error log.

## Nginx with PHP-FPM

Nginx does not read `.htaccess`. Uploading files alone cannot configure it.
Ask your provider to apply the included `_neo/nginx-root.conf` or
`_neo/nginx-subfolder.conf`, or apply it through your control panel's custom
Nginx configuration. These are snippets for an existing HTTPS `server` block.

Set the document root and PHP-FPM socket for your account. For a subfolder, place
the supplied regex locations before any generic PHP locations, and replace
`/podcast` consistently when using another folder. Retain the exact front
controller location and private-directory denials. Check `nginx -t` before
reloading when you administer the server yourself. Avoid conflicting existing
locations; do not enable a generic PHP handler inside the application directory.

## Verify protection

Requests to `_neo/config.php`, `_neo/var/neo.sqlite`, `_neo/var/uploads/`, and
`_neo/vendor/` must return 403 or 404, including after upgrades. Draft media must
require an administrator session. If your provider cannot apply the required
rules, the installation is not supported on that hosting configuration.

## Updates, backups, and recovery

Put the installation into maintenance at the web-server/control-panel level and
wait for active uploads and requests to finish. Back up the full data directory
(including SQLite WAL/SHM files if present) and `_neo/config.php` using the hosting
file manager or provider backup service. Store backups outside the public site.

Extract the new release over the application files. Releases never contain
`config.php` or `var`, so your configuration and data remain intact. Reapply/check
web-server rules if they changed. Remove maintenance mode, visit the application
to apply migrations, then verify login, episodes, feeds, playback, and private-file
denials. Rollback requires the matching previous application and data backup.

For a move to a server, retain the configuration and complete data directory,
point `NEO_DATA` to that directory, choose server mode, and configure the worker
and FFmpeg. Root deployments retain an empty `NEO_BASE_PATH`.
