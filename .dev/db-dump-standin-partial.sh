#!/bin/sh
# A dump utility that stops after the first table, EXITS 0, and records that it RAN.
#
# The marker file is the point of this file. A stand-in that never started produces exactly the same
# refusal as one whose output was judged partial, so without the marker the suite's assertion would
# pass on a broken harness and prove nothing about the check. The suite removes the marker before
# the call and asserts it exists afterwards.
: > /tmp/gmcp-dbdump-partial-ran
printf 'CREATE TABLE `wp_options` (\n  `id` bigint NOT NULL\n);\n'
echo '-- Dump completed on 2024-01-01 00:00:00'
exit 0
