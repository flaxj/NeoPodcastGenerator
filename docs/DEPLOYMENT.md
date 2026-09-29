# Deployment

For upload-ready Apache/Nginx releases, see [shared hosting](SHARED-HOSTING.md).
This page describes the full source/server build with FFmpeg and its worker.

## HTTPS

Compose binds Nginx to `127.0.0.1:8080`. Put a host-level TLS reverse proxy in front. Example Caddyfile:

```caddyfile
podcast.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Configure DNS, a publicly trusted certificate, and ports 80/443. If Caddy runs in a container, join the Compose network and proxy to `web:80`, not its own loopback. Do not expose PHP-FPM.

The edge proxy must allow at least 8 MiB request bodies. Nginx allows 12 MiB and PHP’s form limits are 10/12 MiB. The 10 GiB maximum is enforced across chunks. Raise `NEO_MAX_UPLOAD` only with adequate disk space.

The application generates RSS URLs from the configured canonical origin, not arbitrary Host/forwarded headers. Login throttling uses the direct address; proxy users may share a bucket. If enabling Nginx real-IP support, trust only the specific proxy and ensure it overwrites forwarding headers.

## Services

App and worker share the `neo-data` volume, SQLite database, media, and lock. Nginx mounts media read-only and serves static assets from `public`. The app image runs as `www-data`. Keep one worker; more workers do not increase v1 throughput.

```sh
docker compose up -d --build
docker compose ps
docker compose exec app php bin/console.php check
docker compose logs --tail=100 app worker web
```

Health checks return nonzero if the worker heartbeat is stale. Monitor this and public feed/media reachability.

## Updates

1. Create and copy a backup off-server.
2. Stop with `docker compose down` without `-v`.
3. Update application files, then `docker compose up -d --build`.
4. Verify logs, worker health, both feeds, and an existing enclosure. Migrations apply at boot.

`docker compose down -v` removes production data. Schema rollbacks require the matching backup and application version.

## Deployment checks

Run `docker compose -f compose.test.yaml run --build --rm test`. After deployment, check from another machine:

```sh
curl -I https://podcast.example.com/feeds/audio.xml
curl -I https://podcast.example.com/feeds/video.xml
curl -H 'Range: bytes=0-1023' -D headers.txt -o media-sample.bin https://podcast.example.com/media/ASSET_UUID
```

Use an actual feed enclosure URL. Expect 206, correct Content-Range, and media MIME. Confirm direct `/_protected/filename.mp3` requests return 404. Complete a real upload through the proxy and verify both feeds before inviting subscribers.
