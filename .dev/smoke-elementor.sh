#!/bin/bash
# Smoke test for the Elementor tools.
#
# Its own file because it needs Elementor installed and the Elementor tool group switched
# on, and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin install elementor --activate
#   ./smoke-elementor.sh
#
# The theme builder itself is Elementor Pro or PRO Elements and is not installed here, so
# the conditions checks below prove that the right rows are written and that the tools say
# plainly that nothing reads them yet. That is the honest half to test: writing the rows is
# what this plugin does, and claiming they took effect would be the failure.
#
# Every check corresponds to something that was wrong or could quietly go wrong, and each
# asserts the stored state rather than the wording of the reply.
set -u
#   GMCP_URL=http://localhost:8081 ./smoke-elementor.sh
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
# The suite mints itself a key. There is no shared token any more, and a key is shown
# once, so there is nothing to read back out of the database and hardcode here.
#
# Recreated under the same label each run rather than reused, and the shape is asserted:
# without the guard, a failure to create one leaves TOK empty, every request 401s, and a
# suite that reports a hundred failures is describing one missing credential.
gmcp_make_key() { # gmcp_make_key <label>
  docker compose exec -T cli wp eval '
    $label = "'"$1"'";
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); }
    }
    $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $a = GMCP_Tokens::create( $label, "admin", 0, [], $admins ? $admins[0]->ID : 0 );
    echo $a["secret"];' 2>/dev/null | tr -d '\r\n'
}
# Every key except the suite's own. Blocks that test key behaviour used to wipe the whole
# option, which was harmless while the suite authenticated with a shared token kept
# elsewhere. The suite's credential is now a key too, so a blanket wipe revokes it
# mid-run, every later request 401s, and the checks report the features as broken rather
# than the credential as gone.
gmcp_clear_other_keys() { # gmcp_clear_other_keys <keep-label>
  docker compose exec -T cli wp eval '
    $keep = "'"$1"'";
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) !== $keep ) { GMCP_Tokens::revoke( $k["id"] ); }
    }' >/dev/null 2>&1
}

TOK=$(gmcp_make_key "smoke suite")
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

if ! docker compose exec -T cli wp plugin is-active elementor >/dev/null 2>&1; then
  echo "Elementor is not active. Install it first:"
  echo "  docker compose exec -T cli wp plugin install elementor --activate"
  exit 2
fi
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_elementor"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

# A header template with conditions on it and an empty array in the cache: the exact state
# an imported kit lands in when something wrote the meta and not the cache. Elementor reads
# a stored empty array as computed, so it never rebuilds and the header stays invisible.
HDR=$(docker compose exec -T cli wp eval '
$id = wp_insert_post(["post_title"=>"Smoke Header","post_type"=>"elementor_library","post_status"=>"publish"]);
update_post_meta($id,"_elementor_template_type","header");
update_post_meta($id,"_elementor_conditions",["include/general"]);
update_option("elementor_pro_theme_builder_conditions",[],false);
echo $id;' 2>/dev/null | tr -d '\r\n')

echo "-- the tools are offered --"
call e_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "the Elementor group is listed when switched on" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};need={"elementor_list_templates","elementor_get_conditions","elementor_set_conditions","elementor_regenerate_css","elementor_apply_template"};print(sorted(need-n) or True)' e_list)" "True"

echo "-- the stale conditions cache --"
call e_diag "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_get_conditions\",\"arguments\":{\"ID\":$HDR}}}"
# The whole point of the group. A generic meta read says the template is configured and
# stops there; this has to name the cache as the reason nothing is applied.
check "an empty cache is named as an empty cache" \
  "$(py 'import json,sys;j=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(j["cache"]["state"])' e_diag)" "empty array"
check "and the disagreement is reported" \
  "$(py 'import json,sys;j=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("disagree" in j["summary"])' e_diag)" "True"
# Nothing reads conditions without the theme builder. Reporting success here would send a
# reader looking at their theme for a fault that is a missing plugin.
check "and the absent theme builder is not glossed over" \
  "$(py 'import json,sys;j=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(j["theme_builder_present"])' e_diag)" "False"

call e_set "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_set_conditions\",\"arguments\":{\"ID\":$HDR,\"conditions\":[\"include/singular/page\"]}}}"
check "setting conditions succeeds" "$(verdict e_set)" "ok"
check "the meta holds the new conditions" \
  "$(wpc post meta get $HDR _elementor_conditions --format=json)" '["include\/singular\/page"]'
# An empty array blocks Elementor's own rebuild, so leaving one behind would leave the
# template just as invisible as before. Absent is the state that heals itself.
check "and the empty array is gone rather than rewritten" \
  "$(wpc eval 'echo get_option("elementor_pro_theme_builder_conditions",null)===null?"absent":"present";')" "absent"

echo "-- refusals --"
POST=$(wpc eval 'echo wp_insert_post(["post_title"=>"Smoke Page","post_type"=>"page","post_status"=>"publish"]);')
call e_notlib "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_set_conditions\",\"arguments\":{\"ID\":$POST,\"conditions\":[\"include/general\"]}}}"
check "conditions on something that is not a library template" "$(verdict e_notlib)" "error"
call e_badcond "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_set_conditions\",\"arguments\":{\"ID\":$HDR,\"conditions\":[\"everywhere\"]}}}"
check "a condition Elementor does not understand" "$(verdict e_badcond)" "error"
check "and the refusal left the stored conditions alone" \
  "$(wpc post meta get $HDR _elementor_conditions --format=json)" '["include\/singular\/page"]'

echo "-- putting a template on a page --"
# _elementor_data is a JSON string holding escapes, and update_metadata() unslashes what it
# is handed, so a value that went out through a tool argument and came back would lose them.
# Copying inside PHP is the only way it survives; this asserts that it did, byte for byte.
SRC=$(docker compose exec -T cli wp eval '
$id = wp_insert_post(["post_title"=>"Smoke Section","post_type"=>"elementor_library","post_status"=>"publish"]);
update_post_meta($id,"_elementor_template_type","section");
# wp_slash, because update_post_meta() unslashes what it is handed. Seeding without it
# stores a document with every escape eaten, which is the same corruption a value would
# suffer going out through a tool argument and back. Elementor slashes for this reason too.
update_post_meta($id,"_elementor_data",wp_slash("[{\"id\":\"a1\",\"settings\":{\"title\":\"He said \\\"go\\\"\",\"link\":\"https:\\/\\/example.com\\/a\\/b\"}}]"));
echo $id;' 2>/dev/null | tr -d '\r\n')
call e_copy "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_apply_template\",\"arguments\":{\"page_id\":$POST,\"template_id\":$SRC,\"mode\":\"copy\"}}}"
check "copy mode succeeds" "$(verdict e_copy)" "ok"
check "and the design arrives byte for byte" \
  "$(wpc eval "echo get_post_meta($POST,'_elementor_data',true)===get_post_meta($SRC,'_elementor_data',true)?'same':'differs';")" "same"
check "and it is still valid JSON after the round trip" \
  "$(wpc eval "echo json_decode(get_post_meta($POST,'_elementor_data',true))===null?'broken':'parses';")" "parses"

# A second apply would silently throw away a design somebody built. It has to be asked for.
call e_again "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_apply_template\",\"arguments\":{\"page_id\":$POST,\"template_id\":$SRC,\"mode\":\"copy\"}}}"
check "applying over an existing design needs overwrite" "$(verdict e_again)" "error"

echo "-- the group switch --"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_elementor"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1
call e_off '{"jsonrpc":"2.0","id":8,"method":"tools/list"}'
check "switched off, the tools are not offered" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print("elementor_set_conditions" in n)' e_off)" "False"
call e_offcall "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_set_conditions\",\"arguments\":{\"ID\":$HDR,\"conditions\":[\"include/general\"]}}}"
check "and calling one anyway is refused" "$(verdict e_offcall)" "error"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_elementor"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
