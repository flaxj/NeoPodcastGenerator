# Operations and recovery

## Backup

```sh
docker compose exec app php bin/console.php backup /app/var/backups/2026-09-12
docker compose cp app:/app/var/backups/2026-09-12 ./neo-backup-2026-09-12
```

Use a new directory each time and retain an off-server copy. Backup takes the worker lock, creates a consistent SQLite snapshot using `VACUUM INTO`, and copies referenced media/upload sources. It refuses while the worker processes a job; retry when idle. Metadata edits and new uploads may continue: media is immutable, and cleanup uses the same lock.

Only directories containing `BACKUP-COMPLETE` are complete. Failed backups can leave partial directories; choose a new destination when retrying. Backups include hashed credentials and all media; restrict access. Preserve `.env` and the application version separately.

## Restore

1. Stop app/worker writes. Keep the existing volume intact for rollback.
2. Create a fresh volume; copy `neo.sqlite`, `assets/`, and `uploads/` from a completed backup. Set UID/GID 33 (`www-data`). Set the volume root and `assets/` to mode 0755 so Nginx can traverse finalized media, `uploads/` to 0700, and the database to 0600. Media files must be readable by Nginx (0644). Do not overlay old WAL/SHM files.
3. Point all three Compose services to the restored volume and start the matching application version with production HTTPS/cookie settings.
4. Verify settings, login, counts, feeds, playback/ranges, and pending job recovery before switching traffic.

Upload sources may contain bytes beyond the saved offset; resume truncates them. Abandoned processing jobs are reclaimed on worker restart. Restoring the original database preserves listener GUIDs.

## Cleanup

Replacement/deletion removes public access but retains old files until garbage collection. Inspect the dry run:

```sh
docker compose exec app php bin/console.php gc
docker compose exec app php bin/console.php gc --apply
```

Cleanup takes the worker lock and a database write transaction. It removes unreferenced assets, superseded artwork, temporary outputs, and unreferenced upload parts. Current draft/published assets and incomplete upload sources are preserved. Abandoned uploads remain resumable; delete the owning draft when no longer needed, then clean up. Backup directories are never cleaned automatically.

## Password recovery

Prepare a protected local file containing a new 12–72 byte password, then send it through standard input:

```sh
docker compose exec -T app php bin/console.php reset-password < /secure/path/new-password.txt
```

Existing sessions are invalid on their next request. Remove the temporary plaintext file using your normal secure file-management process. V1 does not provide email password resets.

## Troubleshooting

| Symptom | Action |
| --- | --- |
| Login does not persist | Use HTTPS; secure cookies are enabled by default. |
| Setup rejects token | Check exact token, minimum 20 characters; recreate containers after changing environment. |
| Upload stays queued | Run `console.php check`, inspect worker logs, restart worker. |
| Conversion failed | Inspect episode error; check source audio/codecs, disk space, FFmpeg/libmp3lame. Retry or upload corrected media. |
| Video will not play | Download original or supply H.264/AAC MP4; Neo does not transcode video. |
| Upload interrupted | Reselect the same file; fingerprint validation resumes from the committed offset. |
| Disk full | Free space, inspect cleanup, retry. Allow room for source, finalized original, and MP3. |
| Feed lacks episode | Check publication, notes, ready assets, unresolved jobs. Audio-only never enters video RSS. |
| Spotify lacks video | Upload original in Spotify for Creators after audio ingestion. |
| Worker exits | Inspect logs for missing FFmpeg, database permissions, or disk errors. |
| Login throttled | Wait 15 minutes; shared proxy users can share a throttle bucket. |

Container stderr holds logs. Bounded media errors shown to the administrator can include server paths; review them before sharing publicly.
