# Validation record

## Automated checks

The local suite runs on PHP 8.4.25 and FFmpeg/ffprobe 9.0.1 for Windows. It creates real fixtures, not mocked conversion. Coverage includes:

- MP3, MOV, MP4, original-byte preservation, MP3 codec/bitrate/rate/channels.
- Missing audio, corrupt media, interrupted uploads, offsets, size/extension/path rejection, retries, job recovery, worker locking.
- Atomic replacement, failed replacement retention, GUID stability, XML escaping, enclosure MIME/length, draft exclusion, unpublishing.
- SQLite persistence, database/media backup, restoration equivalence, garbage collection.
- HTTP setup lock, password hashing, login/logout, CSRF, anonymous denial, private previews, session revocation.
- HTTP uploads/publication, public players/search, distribution, RSS ETag/HEAD, media conditional/range/HEAD, and unpublished asset protection.

The final local run passed 117 assertions. PHP syntax, JavaScript syntax, and strict Composer manifest validation also passed. Run `php tests/run.php` for the current assertion total. Artifacts and logs remain in ignored `test-results/`.

## Browser checks

Desktop and 390-pixel mobile layouts were visually inspected. A disposable draft was created through the browser, uploaded as MP4, processed by the live worker, and published. The interface uses real application data.

## Environment-dependent checks

Docker Engine is unavailable on the implementation workstation. Dockerfile, Compose, and CI tests are supplied, but an actual Nginx/PHP-FPM deployment has not run locally. Local HTTP tests cover PHP streaming; Nginx `X-Accel-Redirect` needs the deployment smoke checks.

`bash tests/stack.sh` (Linux, Docker Compose, curl, OpenSSL, Python 3) runs the full stack in a randomly named disposable Compose project. It tests HTTPS with a locally trusted test certificate, Nginx ranges, direct internal-path denial, restart persistence, and backup restoration. Its cleanup removes only its own test volumes. CI runs this after the PHP integration suite. This script has been added but not executed on the Windows workstation.

The optional WSL Bash syntax-check request was rejected by automatic approval review because the review service reported a usage limit. That action was not retried.

Public HTTPS/DNS/certificate reachability, full-size 10 GiB uploads under load, server capacity, and Spotify account ingestion/video replacement require the destination environment and have not been claimed as locally verified. CI runs the real-media suite inside the PHP image on Linux.
# Upload-ready package validation

With PHP 8.4 (PDO SQLite, DOM, Fileinfo, Zip), Composer, FFmpeg, Docker and Bash:

```sh
composer install --no-dev --classmap-authoritative
php tests/package.php
bash tests/package-stack.sh apache
bash tests/package-stack.sh apache /podcast
bash tests/package-stack.sh nginx
bash tests/package-stack.sh nginx /podcast
```

Each test builds/extracts the release ZIP, verifies its allowlist, runs MP3
processing with process execution disabled, installs it on the selected web
server, and tests setup, publishing, private-file protection, media delivery,
subfolder URLs, and upgrade preservation. The web containers contain neither
Composer nor FFmpeg. Fixtures are generated outside the application container.
The release workflow requires all four configurations and the existing server
suite to pass before publishing. Logs are retained under `test-results/`.

## Release 1.0.0 local checks (2026-09-28)

The Windows PHP 8.4.25 run passed all 117 server assertions and all 25 shared-mode
checks against the extracted 1.0.0 ZIP, with process execution disabled for shared
mode. PHP syntax checks passed. The packaging regression checks cover relocated
autoloading, complete runtime files, checksum/version, sorted entries, normalized
permissions/timestamps, repeat builds, CRLF normalization, Composer suffix
normalization, timezone-independent ZIP headers, and rejection of mismatched dependencies or missing inputs without
overwriting the previous release. Linux CI additionally checks symlink rejection.
Composer validation reports the intentional exact getID3 version constraint as a
warning; the dependency remains pinned for packaging.

Apache/Nginx root and subfolder deployment checks require Docker and are gated in
the release workflow; they were not run on this Windows workstation. The workflow
publishes the exact versioned ZIP and checksum, downloads them again, and compares
their bytes with the build outputs before verifying the downloaded checksum.

GitHub Linux CI subsequently passed all four Apache/Nginx installation/upgrade
configurations and the server regression/HTTPS stack tests. The Windows and Linux
`0.0.0-test` archives matched SHA-256
`cbbb7b564a48f3d96e9a19457875a73546a3406cd3e72b852d6c4a4faa55fece`
after normalizing the C runtime timezone used by libzip, as well as PHP's timezone.
