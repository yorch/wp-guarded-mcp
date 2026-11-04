#!/bin/bash
# Release checks, run by the package and release workflows and runnable locally.
#
#   check-release.sh version [TAG]   the version strings agree (and match TAG, v-prefix optional)
#   check-release.sh zip PATH        the built zip holds the plugin's files and nothing outside that list
#
# Every "X is absent" assertion is preceded by a control: the same probe is shown to find
# a present X, because an absence check and a broken probe print the same thing.
set -euo pipefail
cd "$(dirname "$0")/.."

SLUG=guarded-mcp
die() { echo "FAIL: $*" >&2; exit 1; }

# The package is an allowlist, not a blocklist: anything outside it fails, so a new dev file,
# a differently-cased README, a dotfile or a nested folder cannot slip through by not being
# named. A genuinely new top-level file or folder in the plugin has to be added here, which
# fails closed with a message saying so.
TOP_FILES=" $SLUG.php readme.txt LICENSE uninstall.php "
TOP_DIRS=" includes languages vendor "
# Files the plugin cannot load without (includes/init.php requires vendor/Parsedown.php).
REQUIRED="$SLUG.php readme.txt LICENSE uninstall.php includes/init.php vendor/Parsedown.php"
VERSION_RE='^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'

# Reads zip entry names on stdin, prints one line per entry that does not belong.
shape_problems() {
  local n rest trimmed first part parts
  while IFS= read -r n; do
    case "$n" in "$SLUG/"*) ;; *) echo "outside $SLUG/: $n"; continue ;; esac
    rest=${n#"$SLUG/"}
    [ -z "$rest" ] && continue   # the top-level folder entry itself
    case "$n" in *\\*) echo "backslash in path: $n"; continue ;; esac
    trimmed=${rest%/}
    IFS=/ read -ra parts <<<"$trimmed"
    first=${parts[0]}
    for part in "${parts[@]}"; do
      case "$part" in
        ""|.|..) echo "empty, . or .. path component: $n"; continue 2 ;;
        .*)      echo "dotfile: $n"; continue 2 ;;
      esac
    done
    if [[ "$TOP_FILES" == *" $first "* ]]; then
      [ "${#parts[@]}" -eq 1 ] && [ "$rest" = "$trimmed" ] || echo "file used as a folder: $n"
    elif [[ "$TOP_DIRS" != *" $first "* ]]; then
      echo "not part of the plugin: $n"
    fi
  done
}

# Prints the entries of a verbose `unzip -Z` listing that are symlinks (mode starts with l).
symlink_hits() { grep -E '^l[rwxsStT-]{9}[[:space:]]' || true; }

cmd_version() {
  local tag="${1:-}" main stable const headers
  # WordPress reads the header with a case-insensitive regex over the first 8 KB that also
  # accepts a leading comment marker, so a second "Version:" line (a docblock example)
  # could make it report a different number than build.sh does. Require exactly one.
  headers=$(head -c 8192 "$SLUG.php" | grep -ciE '^([[:space:]]*<\?php)?[[:space:]/*#@]*Version:' || true)
  [ "$headers" -eq 1 ] || die "expected exactly one Version: header in the first 8 KB of $SLUG.php, found $headers"
  # Same extraction as build.sh: trim the ends only, then require a plain version. Deleting
  # interior whitespace would make '1.0 .0' pass as '1.0.0' while build.sh names the zip
  # after the unmodified string.
  main=$(sed -n 's/^Version:[[:space:]]*//p' "$SLUG.php" | head -1 | sed 's/[[:space:]]*$//')
  stable=$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -1 | sed 's/[[:space:]]*$//')
  [ -n "$main" ] || die "no 'Version:' header found in $SLUG.php"
  [ -n "$stable" ] || die "no 'Stable tag:' found in readme.txt"
  [[ "$main" =~ $VERSION_RE ]] || die "Version '$main' in $SLUG.php is not MAJOR.MINOR.PATCH[-suffix]"
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
  local zip="${1:-}" list verbose hits name sample
  [ $# -eq 1 ] || die "usage: check-release.sh zip PATH (exactly one path; got $# arguments, did a glob match several zips?)"
  [ -f "$zip" ] || die "no such file: $zip"

  list=$(unzip -Z1 "$zip") || die "cannot list $zip"
  verbose=$(unzip -Z "$zip") || die "cannot list $zip"

  # Controls: each probe must flag a known-bad sample, and pass a known-good one, before
  # its silence on the real zip counts for anything.
  for sample in "$SLUG/../../evil.php" "$SLUG/./x.php" "$SLUG//x.php" "$SLUG/.dev/x.sh" \
                "$SLUG/Readme.md" "$SLUG/docs/a.md" "$SLUG/$SLUG/$SLUG.php" "$SLUG/includes/.DS_Store" \
                "$SLUG/tmp/old.zip" "$SLUG/.gitignore" "/$SLUG/x.php" "evil/x.php" "$SLUG/readme.txt/x"; do
    [ -n "$(printf '%s\n' "$sample" | shape_problems)" ] || die "internal: shape probe did not flag: $sample"
  done
  [ -z "$(printf '%s\n' "$SLUG/" "$SLUG/$SLUG.php" "$SLUG/readme.txt" "$SLUG/includes/" "$SLUG/includes/a.php" \
          "$SLUG/vendor/Parsedown.php" "$SLUG/languages/" | shape_problems)" ] \
    || die "internal: shape probe flags entries that belong"
  printf '%s\n' 'lrwxrwxrwx  3.0 unx 9 b- stor 24-Jan-01 00:00 guarded-mcp/CLAUDE.md' | symlink_hits | grep -q . \
    || die "internal: symlink probe cannot find a symlink"

  # Control for the listing itself: it must contain the plugin file before any absence counts.
  printf '%s\n' "$list" | grep -qx "$SLUG/$SLUG.php" \
    || die "$SLUG/$SLUG.php not in the zip (wrong top-level folder?)"

  hits=$(printf '%s\n' "$list" | shape_problems)
  [ -z "$hits" ] || die "the zip holds entries that do not belong in the plugin:"$'\n'"$hits"

  for name in $REQUIRED; do
    printf '%s\n' "$list" | grep -qxF "$SLUG/$name" || die "missing from the zip: $SLUG/$name"
  done

  hits=$(printf '%s\n' "$verbose" | symlink_hits)
  [ -z "$hits" ] || die "symlinks inside the zip:"$'\n'"$hits"

  echo "zip ok: $zip ($(printf '%s\n' "$list" | wc -l) entries, top-level $SLUG/)"
}

case "${1:-}" in
  version) shift; [ $# -le 1 ] || die "usage: check-release.sh version [TAG]"; cmd_version "$@" ;;
  zip)     shift; cmd_zip "$@" ;;
  *) echo "usage: check-release.sh version [TAG] | zip PATH" >&2; exit 2 ;;
esac
