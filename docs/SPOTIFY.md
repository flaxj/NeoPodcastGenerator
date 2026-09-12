# Spotify audio and video workflow

1. Complete show identity, owner email, language, category, explicit status, and artwork.
2. Publish at least one episode; videos must finish MP3 extraction.
3. Copy the **audio** URL from **Distribution**: `https://your-domain/feeds/audio.xml`.
4. Add or claim your externally hosted show in Spotify for Creators using that feed. Complete ownership verification. The RSS owner email must be one you control.
5. Wait for the audio episode to appear.
6. Open its menu in Spotify for Creators on the web, choose **Upload video**, select the original, review, and publish there.

This is Spotify’s documented externally hosted workflow. Approved hosts can use the Distribution API; Neo v1 does not. Videos uploaded through Spotify for Creators are available on Spotify apps/web. Neo’s video RSS independently serves compatible clients.

MP3 output does not guarantee show/video acceptance: public accessibility, account verification, current platform requirements, codecs, and content review still apply.

- [Spotify: video for externally hosted shows](https://support.spotify.com/ng/creators/article/video-episodes-for-shows-not-hosted-with-spotify/)
- [Spotify: add a show](https://support.spotify.com/sc-en/creators/article/adding-a-new-show-to-your-spotify-for-creators-account/)

Adding video in Spotify does not change Neo’s feed URLs or episode UUIDs. Do not create a duplicate Neo episode merely to add video on Spotify.
