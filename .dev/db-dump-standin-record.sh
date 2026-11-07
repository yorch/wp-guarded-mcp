#!/bin/sh
# A stand-in for mariadb-dump that RECORDS what the plugin hands it, then does a real dump.
#
# WHAT IT IS FOR. Two properties of the invocation are invisible from the outside: the exact argv,
# and the contents of the private option file the credentials go in. Both are decided by the plugin
# and neither reaches a reply, so the suite cannot read them from the tool. This stand-in copies
# them where the suite can read them and then hands the SAME argv to the real mariadb-dump, so the
# artifact is a real dump and the completeness check judges real output rather than a stand-in's
# shape.
#
# It records into a fixed path under /tmp that does NOT begin with gmcp-dbdump, because the suite
# counts the group's temporary files by that prefix and a recording directory would be counted as
# a leaked dump.
#
# Where there is no real mariadb-dump (the web container has none) it writes the shape the
# completeness check reads, so the same stand-in can be used from either container.
RECORD=/tmp/gmcp-record
rm -rf "$RECORD"
mkdir -p "$RECORD" 2>/dev/null

# The argv, one argument per line, so an argument holding a space cannot be split by the reader.
: > "$RECORD/argv.txt"
for a in "$@"; do
  printf '%s\n' "$a" >> "$RECORD/argv.txt"
  case "$a" in
    --defaults-file=*|--defaults-extra-file=*)
      # The plugin's own file, as the client would read it. Copied before the plugin's finally
      # removes it.
      cp "${a#*=}" "$RECORD/defaults.cnf" 2>/dev/null
      ;;
  esac
done

if [ -x /usr/bin/mariadb-dump ]; then
  exec /usr/bin/mariadb-dump "$@"
fi
if [ -x /usr/bin/mysqldump ]; then
  exec /usr/bin/mysqldump "$@"
fi

# No real client here: the shape the completeness check reads, and nothing else.
skip=1
for a in "$@"; do
  case "$a" in
    -*) continue ;;
  esac
  if [ "$skip" = 1 ]; then
    skip=0
    continue
  fi
  printf 'CREATE TABLE `%s` (\n  `id` bigint NOT NULL\n);\n' "$a"
  printf 'INSERT INTO `%s` VALUES (1);\n' "$a"
done
echo '-- gmcp db dump record stand-in: the utility wrote this'
echo '-- Dump completed on 2024-01-01 00:00:00'
exit 0
