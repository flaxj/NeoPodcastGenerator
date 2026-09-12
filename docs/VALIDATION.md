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
