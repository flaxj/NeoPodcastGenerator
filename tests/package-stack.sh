#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
server="${1:?apache or nginx}"
prefix="${2:-}"
version="${3:-0.0.0-test}"
[[ "$server" == apache || "$server" == nginx ]]
[[ "$prefix" == '' || "$prefix" == /podcast ]]
run="$(pwd)/test-results/package-${server}-${RANDOM}-$$"
mkdir -p "$run/www" "$run/fixtures"
php bin/package.php "$version"
archive="$(pwd)/dist/neo-podcast-generator-${version#v}.zip"
php tests/package-prepare.php "$archive" "$run/www" "$prefix"
ffmpeg -v error -f lavfi -i sine=frequency=440:duration=2 -c:a libmp3lame -b:a 128k "$run/fixtures/audio.mp3"
ffmpeg -v error -f lavfi -i sine=frequency=440:duration=2 -c:a libmp3lame -q:a 2 "$run/fixtures/vbr.mp3"
ffmpeg -v error -f lavfi -i color=c=green:s=1400x1400 -frames:v 1 "$run/fixtures/cover.png"
php -d disable_functions=exec,shell_exec,system,passthru,proc_open,popen tests/shared.php "$run/www$prefix" "$run/fixtures"
# Writable only inside this disposable test document root.
chmod -R a+rwX "$run/www"
name="neo-package-${RANDOM}-$$"
cleanup() {
    docker logs "$name-web" > "$run/web.log" 2>&1 || true
    docker logs "$name-php" > "$run/php.log" 2>&1 || true
    docker rm -f "$name-web" "$name-php" >/dev/null 2>&1 || true
    docker network rm "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$name" >/dev/null
if [[ "$server" == apache ]]; then
    docker build -f tests/shared.Dockerfile --target apache -t neo-package-apache tests
    docker run -d --name "$name-web" --network "$name" -p 127.0.0.1::80 -v "$run/www:/var/www/html" neo-package-apache >/dev/null
else
    docker build -f tests/shared.Dockerfile --target fpm -t neo-package-fpm tests
    docker run -d --name "$name-php" --network "$name" --network-alias php -v "$run/www:/var/www/html" neo-package-fpm >/dev/null
    if [[ "$prefix" == '' ]]; then snippet=release/nginx-root.conf; else snippet=release/nginx-subfolder.conf; fi
    {
        echo 'server { listen 80; root /var/www/html;'
        sed -e '/^root /d' -e 's@unix:/run/php/php8.4-fpm.sock@php:9000@g' "$snippet"
        echo '}'
    } > "$run/nginx.conf"
    docker run -d --name "$name-web" --network "$name" -p 127.0.0.1::80 -v "$run/www:/var/www/html:ro" -v "$run/nginx.conf:/etc/nginx/conf.d/default.conf:ro" nginx:stable-alpine >/dev/null
fi
port=$(docker port "$name-web" 80/tcp | head -1 | awk -F: '{print $NF}')
origin="http://127.0.0.1:$port"
for attempt in $(seq 1 30); do
    if curl --silent --fail "$origin$prefix/setup" >/dev/null; then break; fi
    sleep 1
done
php tests/package-http.php "$origin" "$prefix" "$run/fixtures"
php tests/package-prepare.php "$archive" "$run/www" "$prefix"
php tests/package-http.php "$origin" "$prefix" "$run/fixtures" --upgrade
