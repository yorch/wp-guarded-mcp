#!/bin/sh
# Every table's CREATE TABLE at the start of a line, then the completion trailer, then MORE output.
# The trailer is inside the last few hundred bytes but it is not the last line, which is the shape a
# partly flushed stream has: a check that only looks for the string nearby accepts this dump.
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
done
echo '-- Dump completed on 2024-01-01 00:00:00'
echo 'SET FOREIGN_KEY_CHECKS=1;'
exit 0
