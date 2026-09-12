# Architecture

`public/index.php` boots the application. Composer loads `Neo` classes from `src/`; a fallback autoloader supports tests before Composer installation. `App` routes HTTP, templates render escaped HTML, and `public/app.js` handles uploads and status polling. No SPA or Node build is required.

## Storage

SQLite uses WAL, foreign keys, a busy timeout, and versioned migrations. Use a local filesystem with advisory locks; network volumes and multi-host workers are unsupported.

- `settings`: canonical HTTPS origin, show identity, category, artwork.
- `admins`: hashed password. Sessions are tied to the stored credential hash so password changes revoke existing sessions.
- `episodes`: permanent UUID, plain-text notes, metadata, publication state/date, current audio/video references.
- `assets`: generated filename, episode, role, MIME, bytes, duration.
- `uploads`: ID, extension, total size, committed offset, lifecycle state.
- `jobs`: queued/processing/ready/failed, source upload, error, timestamps.
- `health`: worker heartbeat. `login_attempts`: throttling by direct client address.

Migrations execute under an immediate transaction. Add numbered migrations and their application branches in `Store`; never rewrite a released migration.

## Upload and processing

1. Save episode metadata. The browser fingerprints the entire source via per-chunk SHA-256 digests before starting/resuming an upload.
2. Write each chunk at the committed offset. Reject mismatches; truncate uncommitted trailing bytes on resume. User filenames never become filesystem paths.
3. Finalization atomically queues a job and is idempotent. A partial unique index prevents multiple active jobs for an episode.
4. The worker takes a process-wide nonblocking filesystem lock, then transactionally claims work. Abandoned processing rows are reclaimed only after acquiring the lock. Multiple workers serialize.
5. ffprobe checks allowed demuxers and streams. Videos require real video plus usable audio; MP3 uploads require genuine mono/stereo MP3. File and pipe are the only allowed protocols.
6. Select the default audio track, otherwise the first. Extract to a temporary MP3, probe it, rename it, and attach both assets transactionally. Output uses libmp3lame, 192 kbps, 44.1 kHz, stereo. No loudness normalization or video transcoding.
7. Failures stay retryable and preserve published asset references. Finalizing a new upload supersedes failed jobs. Conversion timeout is six hours; probe timeout is two minutes.

The worker updates its heartbeat during extraction. Process arguments are arrays, without a shell. Crashes can leave unreferenced files for garbage collection. Original upload parts are removed only after successful finalization.

## Publication and delivery

Publication requires title, notes, ready audio, all referenced files, and no unresolved job. A video enters both feeds only after extraction. Replacement swaps the current pair while preserving GUID and initial publication date. Published metadata edits take effect immediately; scheduling is not implemented.

Both feeds use `urn:uuid:<episode-id>` and exactly one enclosure per item. Distinct titles/self-links distinguish feeds. DOM serializes RSS from a consistent snapshot; ETags support conditional GET. Drafts and failed new episodes are excluded.

`/media/<id>` authorizes current assets of published episodes. `/admin/media/<id>` authorizes private previews. Production sends `X-Accel-Redirect` to Nginx’s internal location, which handles HEAD, caching, and ranges without occupying PHP for the download. PHP provides a streaming fallback for local tests. Unpublishing cannot revoke already downloaded or cached copies.

## HTTP interfaces

Admin/API mutations require a session and form `csrf` or `X-CSRF-Token`. Setup/login require CSRF; setup also requires the server token.

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `/api/uploads` | POST | JSON `episode_id`, `name`, `size`; returns ID, offset, total |
| `/api/uploads/{id}` | GET | Resume metadata |
| `/api/uploads/{id}` | PUT | Raw chunk and decimal `Upload-Offset`; returns committed offset |
| `/api/uploads/{id}/finish` | POST | Idempotently queue upload |
| `/api/episodes/{id}/status` | GET | Processing state/error |
| `/admin/episodes/{id}` | POST | Save metadata |
| `/admin/episodes/{id}/{publish,unpublish,delete,retry}` | POST | Lifecycle action |
| `/feeds/{audio,video}.xml` | GET/HEAD | Public RSS |
| `/media/{id}` | GET/HEAD | Published media with ranges |

Validation errors return 422, invalid CSRF 403, and unauthenticated API reads 401. HTML errors are escaped; API errors are JSON. SQLite, uploads, backups, and credentials remain outside the web root.
