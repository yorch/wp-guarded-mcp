#!/bin/bash
# Build an installable plugin zip.
#
#   ./build.sh              build from the current commit
#   ./build.sh --dirty      build from the working tree, uncommitted changes included
#
# Writes tmp/guarded-mcp-<version>.zip, which is gitignored.
#
# The package is built from `git archive`, not from the directory, so an untracked
# scratch file, an editor backup or a stray .DS_Store cannot end up inside a plugin
# somebody installs. --dirty falls back to copying the tree and says so.
set -euo pipefail
cd "$(dirname "$0")/.."

SLUG=guarded-mcp
DIRTY=0
[ "${1:-}" = "--dirty" ] && DIRTY=1

# The folder name inside the zip is load-bearing. WordPress derives the plugin's identity
# from it through plugin_basename(), and the guard that stops an agent deactivating or
# deleting the plugin mid-call compares against that, so under any other name the
# self-protection silently stops matching.
VERSION=$(sed -n 's/^Version: *//p' "$SLUG.php" | head -1)
[ -n "$VERSION" ] || { echo "could not read Version from $SLUG.php" >&2; exit 1; }

if [ "$DIRTY" = "0" ] && ! git diff-index --quiet HEAD --; then
  echo "The working tree has uncommitted changes." >&2
  echo "A zip built from an unrecorded state cannot be rebuilt later. Commit first," >&2
  echo "or run ./build.sh --dirty if you know that is what you want." >&2
  exit 1
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"

if [ "$DIRTY" = "1" ]; then
  echo "Building from the WORKING TREE, uncommitted changes included."
  git ls-files -z | tar --null -T - -cf - | ( cd "$STAGE/$SLUG" && tar -xf - )
else
  echo "Building from $(git rev-parse --short HEAD)."
  git archive HEAD | ( cd "$STAGE/$SLUG" && tar -xf - )
fi

# Build-time only. .wordpress-org holds the directory listing assets, which belong in
# the SVN assets/ folder rather than in the plugin. README.md documents the repository
# and the dev stack; readme.txt is the one a user reads. AGENTS.md and its CLAUDE.md
# symlink are instructions for people and agents working ON the plugin, and a symlink
# inside a plugin zip is a portability problem on top of being noise. docs/ is the
# project page, which is a website rather than part of the plugin, and it carries
# screenshots that would otherwise be shipped twice. CREDITS.md carries the GPLv2
# attribution but WordPress.org flags it as an unexpected markdown file, so it stays
# in the repository and the attribution in the plugin header and per-file notices
# ships instead.
( cd "$STAGE/$SLUG" && rm -rf .dev .wordpress-org .github .gitignore README.md AGENTS.md CLAUDE.md CREDITS.md docs )
# languages/.gitkeep is a git placeholder to keep the empty directory; the directory
# itself ships so WordPress.org's translation system can populate it.
rm -f "$STAGE/$SLUG/languages/.gitkeep"

# CREDITS.md no longer ships (WordPress.org flags it as an unexpected markdown file),
# but it still has to exist in the repository and name the upstream author. The checks
# run against the source tree, not the staged package, because the file is stripped
# before this point.
for required in "$SLUG.php" readme.txt LICENSE CREDITS.md uninstall.php; do
  [ -f "$required" ] || { echo "missing from the source tree: $required" >&2; exit 1; }
done
grep -qi "jordy meow" CREDITS.md || { echo "CREDITS.md no longer names the upstream author" >&2; exit 1; }

# GPLv2 2(a) asks the modified FILES to carry the notice, not a companion document, so
# each file derived from upstream states what it came from and that it was changed. A
# refactor that drops one of these is a licensing regression no test would otherwise see.
for derived in includes/server.php includes/oauth.php includes/tools-core.php includes/tools-rest.php; do
  grep -qi "Derived from AI Engine" "$STAGE/$SLUG/$derived" \
    || { echo "$derived has lost its upstream notice" >&2; exit 1; }
done

# Parse every shipped file before anyone installs it. A package that does not compile is
# a white screen on somebody's site.
if command -v php >/dev/null 2>&1; then
  LINT="php -l"
elif docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^wptest-wp-1$'; then
  LINT="docker exec -i wptest-wp-1 php -l /dev/stdin <"
else
  LINT=""
  echo "No PHP available, skipping the syntax check." >&2
fi
if [ -n "$LINT" ]; then
  fails=0
  while IFS= read -r f; do
    if command -v php >/dev/null 2>&1; then
      php -l "$f" >/dev/null || fails=1
    else
      docker exec -i wptest-wp-1 php -l /dev/stdin < "$f" >/dev/null || { echo "  syntax error: $f" >&2; fails=1; }
    fi
  done < <(find "$STAGE/$SLUG" -name '*.php')
  [ "$fails" -eq 0 ] || { echo "package does not compile" >&2; exit 1; }
fi

mkdir -p tmp
OUT="tmp/$SLUG-$VERSION.zip"
[ "$DIRTY" = "1" ] && OUT="tmp/$SLUG-$VERSION-dirty.zip"
rm -f "$OUT"
( cd "$STAGE" && zip -qr "$OLDPWD/$OUT" "$SLUG" -x '*.DS_Store' )

echo
echo "  $OUT"
echo "  $(unzip -l "$OUT" | tail -1 | awk '{print $2}') files, $(du -h "$OUT" | cut -f1)"
echo "  top-level folder: $(unzip -l "$OUT" | awk 'NR==4{print $4}')"
