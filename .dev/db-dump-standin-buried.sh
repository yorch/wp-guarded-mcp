#!/bin/sh
# A dump whose statements are there but not where the completeness check requires them: every table
# named on the command line gets its CREATE TABLE at the START of a line except one, whose statement
# text is written only INSIDE a row value. A substring search finds it in the data and calls the
# dump whole; the anchored check does not, and names exactly that one table.
skip=1
buried='wp_gmcp_audit'
for a in "$@"; do
  case "$a" in
    -*) continue ;;
  esac
  if [ "$skip" = 1 ]; then
    skip=0
    continue
  fi
  if [ "$a" = "$buried" ]; then
    continue
  fi
  printf 'CREATE TABLE `%s` (\n  `id` bigint NOT NULL\n);\n' "$a"
done
printf "INSERT INTO \`wp_options\` VALUES (1,'CREATE TABLE \`%s\` (id int)');\n" "$buried"
echo '-- Dump completed on 2024-01-01 00:00:00'
exit 0
