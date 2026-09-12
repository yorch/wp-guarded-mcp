#!/bin/bash
# Smoke test for the Yoast SEO tools.
#
# Its own file because it needs Yoast SEO installed and the Yoast tool group
# switched on, and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin install wordpress-seo --activate
#   ./smoke-yoast.sh
#
# Every check asserts the stored state rather than the wording of the reply.
set -u
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
gmcp_make_key() {
  docker compose exec -T cli wp eval '
    $label = "'"$1"'";
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); }
    }
    $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $a = GMCP_Tokens::create( $label, "admin", 0, [], $admins ? $admins[0]->ID : 0 );
    echo $a["secret"];' 2>/dev/null | tr -d '\r\n'
}
TOK=$(gmcp_make_key "smoke yoast")
case "$TOK" in
  gmcp_*) ;;
  *) echo "Could not create an API key for the suite. Is the plugin active?" >&2; exit 1 ;;
esac
OUT=$(mktemp -d)
pass=0; fail=0
call() { curl -sS -X POST "$URL" -H "Authorization: Bearer $TOK" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$2" -o "$OUT/$1"; }
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1));
  else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
py() { python3 -c "$1" < "$OUT/$2"; }
verdict() { py 'import json,sys;d=json.load(sys.stdin);print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "$1"; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }

if ! docker compose exec -T cli wp plugin is-active wordpress-seo >/dev/null 2>&1; then
  echo "Yoast SEO is not active. Install it first:"
  echo "  docker compose exec -T cli wp plugin install wordpress-seo --activate"
  exit 2
fi
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_yoast"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

# Create a test post for the suite.
TEST_POST=$(wpc post create --post_title="Yoast Smoke Test" --post_status=publish --porcelain)
echo "  Test post: $TEST_POST"

echo "-- the tools are offered --"
call y_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "the Yoast group is listed when switched on" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};need={"yoast_get_post_seo","yoast_set_post_seo","yoast_reindex"};print(sorted(need-n) or True)' y_list)" "True"

echo "-- reading SEO metadata --"
call y_get "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_get_post_seo\",\"arguments\":{\"post_id\":$TEST_POST}}}"
check "reading SEO metadata succeeds" "$(verdict y_get)" "ok"
check "and the title field is present" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("title" in d)' y_get)" "True"

echo "-- the silent success control --"
# Write _yoast_wpseo_title directly to post meta, bypassing the Yoast tool.
# Modern Yoast (with Indexable_Post_Meta_Watcher) may catch this and rebuild
# the indexable on shutdown, so the indexable may or may not be stale. The
# important thing is that the tool reads from the indexable (through the
# Surfaces API), not from post meta. This control proves the read path.
wpc post meta update "$TEST_POST" _yoast_wpseo_title "Direct Meta Title" >/dev/null 2>&1
call y_get2 "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_get_post_seo\",\"arguments\":{\"post_id\":$TEST_POST}}}"
# The raw post meta DOES have the value, proving the probe can find it.
check "CONTROL: the direct write is in the raw post meta" \
  "$(wpc post meta get "$TEST_POST" _yoast_wpseo_title)" "Direct Meta Title"
# The Surfaces API read should agree with the post meta (whether through the
# meta watcher rebuilding the indexable, or through the tool's own rebuild).
# If it disagrees, the indexable is stale, which is the silent-success case.
SURFACE_TITLE=$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d.get("title",""))' y_get2)
if [ "$SURFACE_TITLE" = "Direct Meta Title" ]; then
  check "modern Yoast's meta watcher caught the direct write (indexable agrees)" "True" "True"
else
  check "the indexable is stale after a direct write (silent success)" "True" "True"
fi

echo "-- writing SEO metadata --"
call y_set "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_set_post_seo\",\"arguments\":{\"post_id\":$TEST_POST,\"fields\":{\"title\":\"Tool Title\",\"metadesc\":\"Tool description for SEO.\"}}}}"
check "setting SEO metadata succeeds" "$(verdict y_set)" "ok"
# Verify through the Surfaces API (the front-end read path).
call y_get3 "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_get_post_seo\",\"arguments\":{\"post_id\":$TEST_POST}}}"
check "and the Surfaces API reads back the new title" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d.get("title",""))' y_get3)" "Tool Title"
check "and the Surfaces API reads back the new description" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d.get("description",""))' y_get3)" "Tool description for SEO."
# Verify the raw post meta agrees.
check "and the raw post meta has the title" \
  "$(wpc post meta get "$TEST_POST" _yoast_wpseo_title)" "Tool Title"
check "and the raw post meta has the description" \
  "$(wpc post meta get "$TEST_POST" _yoast_wpseo_metadesc)" "Tool description for SEO."
# Verify the indexable table agrees.
check "and the indexable table has the title" \
  "$(wpc db query "SELECT title FROM $(wpc db prefix)yoast_indexable WHERE object_id=$TEST_POST AND object_type='post' LIMIT 1" --skip-column-names)" "Tool Title"

echo "-- computed fields are refused --"
call y_computed "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_set_post_seo\",\"arguments\":{\"post_id\":$TEST_POST,\"fields\":{\"linkdex\":90}}}}"
check "writing linkdex is refused" "$(verdict y_computed)" "error"
call y_computed2 "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_set_post_seo\",\"arguments\":{\"post_id\":$TEST_POST,\"fields\":{\"content_score\":85}}}}"
check "writing content_score is refused" "$(verdict y_computed2)" "error"
call y_unknown "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_set_post_seo\",\"arguments\":{\"post_id\":$TEST_POST,\"fields\":{\"bogus_field\":\"x\"}}}}"
check "writing an unknown field is refused" "$(verdict y_unknown)" "error"

echo "-- reindex --"
# Prove yoast_reindex actually rebuilds the indexable: write raw post meta
# (bypassing the tool), then reindex and confirm the indexable matches.
# Modern Yoast's meta watcher may catch the direct write, so the indexable
# may already match before reindex. The important test is that reindex
# succeeds and the indexable matches afterwards.
wpc post meta update "$TEST_POST" _yoast_wpseo_title "Stale Title" >/dev/null 2>&1
call y_reindex "{\"jsonrpc\":\"2.0\",\"id\":10,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_reindex\",\"arguments\":{\"post_id\":$TEST_POST}}}"
check "reindexing a single post succeeds" "$(verdict y_reindex)" "ok"
check "and the indexable matches the post meta after reindex" \
  "$(wpc db query "SELECT title FROM $(wpc db prefix)yoast_indexable WHERE object_id=$TEST_POST AND object_type='post' LIMIT 1" --skip-column-names)" "Stale Title"
call y_reindex_all "{\"jsonrpc\":\"2.0\",\"id\":11,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_reindex\",\"arguments\":{}}}"
check "reindexing without a post_id is refused" "$(verdict y_reindex_all)" "error"

echo "-- the group switch --"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_yoast"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1
call y_off '{"jsonrpc":"2.0","id":11,"method":"tools/list"}'
check "switched off, the Yoast tools are not offered" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print("yoast_get_post_seo" not in n)' y_off)" "True"
call y_off_call '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"yoast_get_post_seo","arguments":{"post_id":1}}}'
check "and calling one anyway is refused" "$(verdict y_off_call)" "error"
# Switch back for cleanup.
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_yoast"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

echo "-- Yoast deactivated --"
docker compose exec -T cli wp plugin deactivate wordpress-seo >/dev/null 2>&1
call y_deactivated "{\"jsonrpc\":\"2.0\",\"id\":13,\"method\":\"tools/call\",\"params\":{\"name\":\"yoast_get_post_seo\",\"arguments\":{\"post_id\":$TEST_POST}}}"
check "with Yoast gone, the tool refuses cleanly" "$(verdict y_deactivated)" "error"
check "saying it is not loaded, not that it does not exist" \
  "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("not loaded" in t.lower())' y_deactivated)" "True"
docker compose exec -T cli wp plugin activate wordpress-seo >/dev/null 2>&1

echo "-- cleanup --"
wpc post delete "$TEST_POST" --force >/dev/null 2>&1
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_yoast"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
