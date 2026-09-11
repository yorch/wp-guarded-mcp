#!/bin/bash
# Regenerate .wordpress-org/screenshot-*.png against a running stack.
#
#   COMPOSE_PROJECT_NAME=wpshot GMCP_PORT=8095 ./screenshots.sh
#
# Seed the site first. The screenshots are only as good as what is on the screen, and an
# empty audit log makes the most interesting screen look like a feature nobody uses.
set -euo pipefail
PROJECT="${COMPOSE_PROJECT_NAME:-wptest}"
NETWORK="${PROJECT}_default"
HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="$(cd "$HERE/.." && pwd)/.wordpress-org"

# The address the screenshots will show. A capture of localhost:8095 tells a reader
# nothing and dates the image to whichever port happened to be free.
SITE="${SHOT_SITE:-https://example.com}"
HOSTNAME_ONLY="${SITE#*://}"

wp_cli() { docker compose -p "$PROJECT" exec -T cli wp "$@"; }

WAS_SITEURL="$(wp_cli option get siteurl | tr -d '\r\n')"
WAS_HOME="$(wp_cli option get home | tr -d '\r\n')"

# Always put the site back, including on a failure part-way through. Without this a
# crashed run leaves the stack pointing at an address nothing serves, and the next
# person to open it gets a site that will not load and no idea why.
restore() {
  wp_cli option update siteurl "$WAS_SITEURL" >/dev/null 2>&1 || true
  wp_cli option update home "$WAS_HOME" >/dev/null 2>&1 || true
}
trap restore EXIT

wp_cli option update siteurl "$SITE" >/dev/null
wp_cli option update home "$SITE" >/dev/null

# WordPress redirects to its own siteurl after login, so the browser has to be able to
# resolve that name. Pointing it at the wp container's address inside the compose network
# is what lets the screenshots show a real-looking address instead of a port number.
WP_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NETWORK\").IPAddress}}" "${PROJECT}-wp-1")"
[ -n "$WP_IP" ] || { echo "could not find the wp container's address on $NETWORK" >&2; exit 1; }

# Mounted into the image's own app directory, and run from there, so `require` resolves
# against the puppeteer the image already ships. Mounted anywhere else, node looks for
# node_modules beside the script, finds none, and exits with MODULE_NOT_FOUND.
docker run --rm \
  --network "$NETWORK" \
  --add-host "$HOSTNAME_ONLY:$WP_IP" \
  -v "$OUT:/out" \
  -v "$HERE/screenshots.js:/usr/src/app/screenshots.js:ro" \
  -w /usr/src/app \
  -e SHOT_BASE="$SITE" \
  --entrypoint node \
  zenika/alpine-chrome:with-puppeteer \
  screenshots.js
