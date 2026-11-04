#!/bin/bash
# Release checks, run by the package and release workflows and runnable locally.
#
#   check-release.sh version [TAG]   the version strings agree (and match TAG, v-prefix optional)
#   check-release.sh zip PATH        the built zip has the shape WordPress needs and nothing else
#
# Every "X is absent" assertion is preceded by a control: the same probe is shown to find
# a present X, because an absence check and a broken probe print the same thing.
set -euo pipefail
cd "$(dirname "$0")/.."

SLUG=guarded-mcp
die() { echo "FAIL: $*" >&2; exit 1; }

# Entries that must never ship. Anchored to the top-level folder; a directory matches
# itself and everything beneath it.
FORBIDDEN='.dev .github .wordpress-org docs CLAUDE.md AGENTS.md README.md phpcs.xml.dist'
REQUIRED="$SLUG.php readme.txt LICENSE uninstall.php"

# Prints the forbidden entries found in a listing on stdin.
forbidden_hits() {
  local re="" name esc
  for name in $FORBIDDEN; do
    esc=${name//./\\.}
    re="${re:+$re|}$esc"
  done
  grep -E "^$SLUG/($re)(/|\$)" || true
}

# Prints the entries of a verbose `unzip -Z` listing that are symlinks (mode starts with l).
symlink_hits() { grep -E '^l[rwxsStT-]{9}[[:space:]]' || true; }

cmd_version() {
  local tag="${1:-}" main stable const
  main=$(sed -n 's/^Version:[[:space:]]*//p' "$SLUG.php" | head -1 | tr -d '[:space:]')
  stable=$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -1 | tr -d '[:space:]')
  [ -n "$main" ] || die "no 'Version:' header found in $SLUG.php"
  [ -n "$stable" ] || die "no 'Stable tag:' found in readme.txt"
  [ "$main" = "$stable" ] || die "$SLUG.php says Version $main but readme.txt says Stable tag $stable"
  const=$(sed -n "s/^[[:space:]]*define([[:space:]]*'GMCP_VERSION'[[:space:]]*,[[:space:]]*'\([^']*\)'.*/\1/p" "$SLUG.php" | head -1)
  if [ -n "$const" ]; then
    [ "$const" = "$main" ] || die "$SLUG.php header says Version $main but GMCP_VERSION is $const"
  elif grep -q "GMCP_VERSION" "$SLUG.php"; then
    # Defined in some form this script cannot parse. Say so rather than skip silently.
    die "GMCP_VERSION appears in $SLUG.php but its value could not be parsed"
  fi
  if [ -n "$tag" ]; then
    tag=${tag#v}
    [ "$tag" = "$main" ] || die "tag is $tag but the plugin version is $main"
  fi
  echo "version ok: $main${const:+ (header, Stable tag, GMCP_VERSION)}${tag:+, matches tag}"
}

cmd_zip() {
  local zip="${1:-}"
  [ $# -eq 1 ] || die "usage: check-release.sh zip PATH (exactly one path; a glob matched $# )"
  [ -f "$zip" ] || die "no such file: $zip"
  local list verbose bad hits name

  list=$(unzip -Z1 "$zip") || die "cannot list $zip"
  verbose=$(unzip -Z "$zip") || die "cannot list $zip"

  # Control for the probes: they must find what they are looking for in a known sample.
  printf '%s\n' "$SLUG/.dev/x.sh" "$SLUG/docs/a.md" "$SLUG/README.md" "$SLUG/readme.txt" \
    | forbidden_hits | grep -q "^$SLUG/.dev/x.sh$" || die "internal: forbidden-entry probe cannot find .dev"
  [ "$(printf '%s\n' "$SLUG/.dev/x.sh" "$SLUG/docs/a.md" "$SLUG/README.md" "$SLUG/readme.txt" | forbidden_hits | wc -l)" -eq 3 ] \
    || die "internal: forbidden-entry probe miscounts (README.md vs readme.txt)"
  printf '%s\n' 'lrwxrwxrwx  3.0 unx 9 b- stor 24-Jan-01 00:00 guarded-mcp/CLAUDE.md' | symlink_hits | grep -q . \
    || die "internal: symlink probe cannot find a symlink"

  # Control for the listing itself: it must contain the plugin file before any absence counts.
  printf '%s\n' "$list" | grep -qx "$SLUG/$SLUG.php" \
    || die "$SLUG/$SLUG.php not in the zip (wrong top-level folder?)"

  # Single top-level folder, exactly the slug.
  bad=$(printf '%s\n' "$list" | grep -v "^$SLUG/" || true)
  [ -z "$bad" ] || die "entries outside the $SLUG/ folder:"$'\n'"$bad"
  [ "$(printf '%s\n' "$list" | cut -d/ -f1 | sort -u)" = "$SLUG" ] \
    || die "top-level folder is not exactly $SLUG"

  for name in $REQUIRED; do
    printf '%s\n' "$list" | grep -qx "$SLUG/$name" || die "missing from the zip: $SLUG/$name"
  done

  hits=$(printf '%s\n' "$list" | forbidden_hits)
  [ -z "$hits" ] || die "development files shipped in the zip:"$'\n'"$hits"

  hits=$(printf '%s\n' "$verbose" | symlink_hits)
  [ -z "$hits" ] || die "symlinks inside the zip:"$'\n'"$hits"

  echo "zip ok: $zip ($(printf '%s\n' "$list" | wc -l) entries, top-level $SLUG/)"
}

case "${1:-}" in
  version) shift; [ $# -le 1 ] || die "usage: check-release.sh version [TAG]"; cmd_version "$@" ;;
  zip)     shift; cmd_zip "$@" ;;
  *) echo "usage: check-release.sh version [TAG] | zip PATH" >&2; exit 2 ;;
esac
