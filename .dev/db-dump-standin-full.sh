#!/bin/sh
# A stand-in for mariadb-dump, used by smoke-db-dump.sh where the container has neither client tool.
#
# WHAT IT IS FOR. The suite has to prove the plugin's side of a dump: that the artifact is the
# utility's own bytes unmodified, that a partial or wrongly-shaped file is refused, and that nothing
# is kept when it is. A real mysqldump would add a dependency on the very tool this test stands in
# for, and a stand-in that wrote nothing would make every refusal below pass for the wrong reason.
# So it writes the shape the completeness check reads — one CREATE TABLE line per table named on its
# command line, one row line each, a marker line, and the completion trailer as the LAST line — and
# nothing else. It is not a database dump and does not claim to be one.
#
# argv is what the plugin builds: options, then the database name, then the table names.
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
echo '-- gmcp db dump stand-in: the utility wrote this'
echo '-- Dump completed on 2024-01-01 00:00:00'
exit 0
