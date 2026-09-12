# Neo Podcast Generator

A self-hosted PHP podcast publisher with a public website, private studio, and separate audio and video RSS feeds. Upload MP3, or upload MOV/MP4 and let the worker create its MP3 automatically.

## First release

- One show and one administrator; responsive archive, search, pagination, players, downloads, and subscription links.
- Show settings/artwork; draft, edit, publish, unpublish, replace, retry, and delete workflows.
- Authenticated resumable uploads: 8 MiB chunks, 10 GiB default file limit.
- Original video preserved; extracted MP3 is 192 kbps, 44.1 kHz, stereo.
- Permanent `/feeds/audio.xml` and `/feeds/video.xml`. Paired episodes share a GUID and publish together.
- PHP 8.4, SQLite migrations, FFmpeg, Docker Compose, Nginx, PHP-FPM, and background worker.
- Backup, restore guidance, storage cleanup, password recovery, and real-media integration tests.

## Install on a VPS

Requires Docker Engine with Compose, a domain, an HTTPS reverse proxy, and local disk storage. Allow at least two copies of an uploaded video during processing plus MP3 output; replacements and backups need additional space. Two CPU cores and 2 GiB RAM are a starting point, not a capacity guarantee.

1. Copy `.env.example` to `.env`. Set `NEO_SETUP_TOKEN` to a random secret of at least 20 characters (for example, generate one with `openssl rand -hex 32`). Do not commit `.env`.
2. Run `docker compose up -d --build`.
3. Route your public HTTPS domain to `http://127.0.0.1:8080` on the VPS. See [deployment](docs/DEPLOYMENT.md).
4. Visit `https://your-domain/setup`. Enter the setup token, show information, and a 12–72 byte administrator password. The worker must be online.
5. Upload square JPG/PNG artwork (1400–3000 pixels, under 5 MB) in **Settings**.
6. Create a draft, add media, wait for **Ready**, preview it, then select **Publish episode**.

Keep `NEO_SECURE_COOKIES=1` in production. HTTP will not retain the secure administrator cookie. `NEO_SECURE_COOKIES=0` is for loopback development only. The show URL must be a dedicated HTTPS origin without a path.

## Distribution

| Feed | Content |
| --- | --- |
| `/feeds/audio.xml` | MP3-only episodes and generated MP3 from published videos |
| `/feeds/video.xml` | Original MOV/MP4 from published video episodes |

Submit the **audio feed** to Spotify. After an episode appears, add its original video in Spotify for Creators. See [Spotify workflow](docs/SPOTIFY.md). Neo does not submit to Spotify automatically or implement its Distribution API.

MOV/MP4 support does not guarantee playback for every codec. Neo preserves originals and provides download links when browser playback is unavailable.

## Development and tests

```sh
docker compose -f compose.test.yaml run --build --rm test
```

Alternatively, with PHP 8.4, PDO SQLite, DOM, Fileinfo, FFmpeg, and ffprobe installed:

```sh
composer install
composer test
```

Tests synthesize short media fixtures, exercise conversion/publication, and launch a loopback PHP server for HTTP tests. Artifacts go into ignored `test-results/`. Override binary paths with `FFMPEG_BIN` and `FFPROBE_BIN`. Production media is offloaded to Nginx after authorization.

For a disposable visual preview, set `NEO_DATA` to a new directory under `test-results/`, run `php tests/preview.php /path/to/test-video.mp4`, then start `php -S 127.0.0.1:8787 -t public public/router.php` with the same data directory and `NEO_SECURE_COOKIES=0`. Run `php bin/worker.php` separately with the same environment. Never use the preview credentials in production.

See [architecture](docs/ARCHITECTURE.md), [operations](docs/OPERATIONS.md), and [validation](docs/VALIDATION.md).

## License and origin

GPL-3.0-only. This ground-up implementation is inspired by [Podcast Generator](https://github.com/PodcastGenerator/PodcastGenerator) and its publishing workflows. It is independent and does not claim endorsement by Podcast Generator or Spotify. See [NOTICE](NOTICE) and [LICENSE](LICENSE).

Legacy migration, livestreams, third-party themes/plugins, analytics, multiple shows, scheduling, and direct platform APIs are outside this release.
