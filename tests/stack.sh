#!/usr/bin/env bash
# Disposable Linux CI smoke test of the real Nginx + PHP-FPM + worker stack.
set -euo pipefail
cd "$(dirname "$0")/.."
export NEO_SETUP_TOKEN=disposable-container-test-token-only
export NEO_HTTP_PORT=0
export NEO_SECURE_COOKIES=1
project="neo-smoke-${RANDOM}-$$"
dc=(docker compose -p "$project" -f compose.yaml -f compose.smoke.yaml)
cleanup() {
  "${dc[@]}" logs --no-color > test-results/stack.log 2>&1 || true
  # Only this randomly named test project's resources are removed.
  "${dc[@]}" down -v --remove-orphans
}
trap cleanup EXIT
mkdir -p test-results/tls
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
  -keyout test-results/tls/key.pem -out test-results/tls/cert.pem \
  -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost' 2>/dev/null
sed 's/listen 80;/listen 443 ssl;\n    ssl_certificate \/etc\/nginx\/test-cert.pem;\n    ssl_certificate_key \/etc\/nginx\/test-key.pem;/' docker/nginx.conf > test-results/tls/nginx.conf
"${dc[@]}" up -d --build
port=$("${dc[@]}" port web 443 | awk -F: '{print $NF}')
url="https://localhost:$port"
fetch=(curl --silent --show-error --cacert test-results/tls/cert.pem)
for attempt in $(seq 1 30); do
  if "${fetch[@]}" -o /dev/null "$url/setup"; then break; fi
  sleep 1
done
"${dc[@]}" exec -T app ffmpeg -y -v error -f lavfi -i color=c=green:s=160x120:d=1 -f lavfi -i sine=frequency=440:duration=1 -c:v libx264 -c:a aac -shortest /tmp/fixture.mp4
# Seed uses the same domain services and worker code as normal publishing.
"${dc[@]}" stop worker
"${dc[@]}" exec -T app php tests/preview.php /tmp/fixture.mp4
"${dc[@]}" start worker
"${fetch[@]}" --fail "$url/feeds/audio.xml" > test-results/stack-audio.xml
"${fetch[@]}" --fail "$url/feeds/video.xml" > test-results/stack-video.xml
readarray -t paths < <(python3 - <<'PY'
import xml.etree.ElementTree as ET
from urllib.parse import urlsplit
for kind, mime in [('audio','audio/mpeg'),('video','video/mp4')]:
    items=ET.parse('test-results/stack-'+kind+'.xml').findall('./channel/item')
    assert len(items)==3
    assert all(len(item.findall('enclosure'))==1 for item in items)
    assert all(item.find('enclosure').get('type')==mime for item in items)
    print(urlsplit(items[0].find('enclosure').get('url')).path)
PY
)
test "${#paths[@]}" -eq 2
for path in "${paths[@]}"; do
  status=$("${fetch[@]}" -H 'Range: bytes=0-9' -D test-results/stack-range.headers -o test-results/stack-range.bin -w '%{http_code}' "$url$path")
  test "$status" = 206
  test "$(wc -c < test-results/stack-range.bin)" -eq 10
  grep -qi '^Content-Range: bytes 0-9/' test-results/stack-range.headers
  "${fetch[@]}" --fail --head "$url$path" > test-results/stack-head.headers
  grep -qi '^Content-Length:' test-results/stack-head.headers
done
test "$("${fetch[@]}" -o /dev/null -w '%{http_code}' "$url/_protected/anything.mp3")" = 404
"${dc[@]}" restart app worker
"${fetch[@]}" --retry 5 --retry-all-errors --fail "$url/feeds/audio.xml" > test-results/stack-restarted.xml
cmp test-results/stack-audio.xml test-results/stack-restarted.xml
"${dc[@]}" stop worker
"${dc[@]}" exec -T app php bin/console.php backup /app/var/smoke-backup
"${dc[@]}" exec -T -e NEO_DATA=/app/var/smoke-backup app php -r '$s=require "bootstrap.php"; if(count($s->all("SELECT id FROM episodes"))!==3) exit(1); echo (new Neo\Feed($s))->render("audio");' > test-results/stack-restored.xml
cmp test-results/stack-audio.xml test-results/stack-restored.xml
echo 'Container smoke test passed: HTTPS, paired feeds, Nginx ranges, persistence, backup restoration.'
