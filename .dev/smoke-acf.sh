#!/bin/bash
# Smoke test for the ACF tools.
#
# Its own file because it needs ACF installed and the ACF tool group
# switched on, and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin install advanced-custom-fields --activate
#   ./smoke-acf.sh
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
TOK=$(gmcp_make_key "smoke acf")
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

if ! docker compose exec -T cli wp plugin is-active advanced-custom-fields >/dev/null 2>&1; then
  echo "ACF is not active. Install it first:"
  echo "  docker compose exec -T cli wp plugin install advanced-custom-fields --activate"
  exit 2
fi
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_acf"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

# Register a field group via mu-plugin, since ACF fields registered in PHP
# only exist for the request that registers them.
docker compose exec -T cli mkdir -p /var/www/html/wp-content/mu-plugins 2>/dev/null
docker compose exec -T cli bash -c 'cat > /var/www/html/wp-content/mu-plugins/smoke-acf-fields.php' <<'MUPHP'
<?php
// Register a field group for the ACF smoke test. This runs on every request
// the way a theme's functions.php would, so the fields exist when the MCP
// endpoint's tools look for them.
add_action('acf/init', function() {
    acf_add_local_field_group([
        'key' => 'group_smoke_acf',
        'title' => 'Smoke ACF Fields',
        'fields' => [
            [
                'key' => 'field_smoke_text',
                'name' => 'smoke_text',
                'label' => 'Smoke Text',
                'type' => 'text',
                'required' => false,
            ],
            [
                'key' => 'field_smoke_secret',
                'name' => 'smoke_api_key',
                'label' => 'Smoke API Key',
                'type' => 'text',
                'required' => false,
            ],
        ],
        'location' => [
            [
                [
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'post',
                ],
            ],
        ],
    ]);
});
MUPHP

# Create a test post.
TEST_POST=$(wpc post create --post_title="ACF Smoke Test" --post_status=publish --porcelain)
echo "  Test post: $TEST_POST"

echo "-- the tools are offered --"
call a_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "the ACF group is listed when switched on" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};need={"acf_list_fields","acf_get_field_value","acf_set_field_value","acf_get_field_objects"};print(sorted(need-n) or True)' a_list)" "True"

echo "-- field discovery --"
call a_fields '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"acf_list_fields","arguments":{}}}'
check "listing fields succeeds" "$(verdict a_fields)" "ok"
check "and the text field is registered" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["name"] for g in d["groups"] for x in g["fields"]};print("smoke_text" in f)' a_fields)" "True"
check "and the field has a key" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["key"] for g in d["groups"] for x in g["fields"]};print("field_smoke_text" in f)' a_fields)" "True"

echo "-- writing a field value --"
call a_set "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"acf_set_field_value\",\"arguments\":{\"field\":\"smoke_text\",\"value\":\"hello-from-tool\",\"post_id\":$TEST_POST}}}"
check "setting a field value succeeds" "$(verdict a_set)" "ok"
# Verify through get_field (the theme's read path).
check "and get_field reads back the new value" \
  "$(docker compose exec -T cli wp eval "echo get_field('smoke_text',$TEST_POST);" 2>/dev/null | tr -d '\r\n')" "hello-from-tool"
# Verify the field key reference was written.
check "and the field key reference was written" \
  "$(docker compose exec -T cli wp eval "echo get_post_meta($TEST_POST,'_smoke_text',true);" 2>/dev/null | tr -d '\r\n')" "field_smoke_text"

echo "-- the silent success control --"
# Write smoke_text directly via update_post_meta, WITHOUT the field key
# reference. First delete the reference that the previous tool write created,
# so we can prove the direct write does not restore it. This shows the
# reference is what the tool writes, not what update_post_meta writes.
docker compose exec -T cli wp eval "delete_post_meta($TEST_POST,'_smoke_text');update_post_meta($TEST_POST,'smoke_text','direct-write');" >/dev/null 2>&1
check "CONTROL: a direct write stores the value" \
  "$(docker compose exec -T cli wp eval "echo get_post_meta($TEST_POST,'smoke_text',true);" 2>/dev/null | tr -d '\r\n')" "direct-write"
check "but the field key reference is missing after a direct write" \
  "$(docker compose exec -T cli wp eval "echo empty(get_post_meta($TEST_POST,'_smoke_text',true))?'missing':'present';" 2>/dev/null | tr -d '\r\n')" "missing"
# Now write through the tool and confirm the reference is restored.
call a_set2 "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"acf_set_field_value\",\"arguments\":{\"field\":\"smoke_text\",\"value\":\"tool-write\",\"post_id\":$TEST_POST}}}"
check "and writing through the tool restores the reference" \
  "$(docker compose exec -T cli wp eval "echo get_post_meta($TEST_POST,'_smoke_text',true);" 2>/dev/null | tr -d '\r\n')" "field_smoke_text"

echo "-- writing an unregistered field is refused --"
call a_unreg "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"acf_set_field_value\",\"arguments\":{\"field\":\"nonexistent_field\",\"value\":\"x\",\"post_id\":$TEST_POST}}}"
check "writing an unregistered field is refused" "$(verdict a_unreg)" "error"
check "saying it is not registered" \
  "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("not registered" in t.lower())' a_unreg)" "True"

echo "-- credential redaction --"
# Seed a real secret through update_post_meta (bypassing the tool's credential
# check), then read it through the tool. The tool must redact it on read
# regardless of how the value got there.
docker compose exec -T cli wp eval "update_post_meta($TEST_POST,'smoke_api_key','sk-live-secret-12345');update_post_meta($TEST_POST,'_smoke_api_key','field_smoke_secret');" >/dev/null 2>&1
# Read by field name — should be redacted.
call a_get_secret "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"acf_get_field_value\",\"arguments\":{\"field\":\"smoke_api_key\",\"post_id\":$TEST_POST}}}"
check "a credential-shaped field is redacted on read (by name)" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d["value"])' a_get_secret)" "[redacted]"
# Read by field key — should also be redacted. This is the bypass the
# adversarial review found: the redaction decision must be on the resolved
# field name, not the selector.
call a_get_secret_key "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"acf_get_field_value\",\"arguments\":{\"field\":\"field_smoke_secret\",\"post_id\":$TEST_POST}}}"
check "a credential-shaped field is redacted on read (by key)" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d["value"])' a_get_secret_key)" "[redacted]"
# Writing a credential-shaped field should be refused.
call a_set_secret "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"acf_set_field_value\",\"arguments\":{\"field\":\"smoke_api_key\",\"value\":\"sk-live-secret-12345\",\"post_id\":$TEST_POST}}}"
check "writing a credential-shaped field is refused" "$(verdict a_set_secret)" "error"

echo "-- post_id validation --"
# An arbitrary string post_id should be refused.
call a_bad_pid "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"acf_set_field_value\",\"arguments\":{\"field\":\"smoke_text\",\"value\":\"x\",\"post_id\":\"siteurl\"}}}"
check "an arbitrary string post_id is refused" "$(verdict a_bad_pid)" "error"
check "saying it is not a recognised location" \
  "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("not a recognised" in t.lower())' a_bad_pid)" "True"

echo "-- field objects --"
call a_objects "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"acf_get_field_objects\",\"arguments\":{\"post_id\":$TEST_POST}}}"
check "listing field objects succeeds" "$(verdict a_objects)" "ok"
check "and the text field is present" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("smoke_text" in d["fields"])' a_objects)" "True"

echo "-- the group switch --"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_acf"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1
call a_off '{"jsonrpc":"2.0","id":10,"method":"tools/list"}'
check "switched off, the ACF tools are not offered" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print("acf_list_fields" not in n)' a_off)" "True"
call a_off_call '{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"acf_list_fields","arguments":{}}}'
check "and calling one anyway is refused" "$(verdict a_off_call)" "error"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_acf"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

echo "-- ACF deactivated --"
docker compose exec -T cli wp plugin deactivate advanced-custom-fields >/dev/null 2>&1
call a_deactivated '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"acf_list_fields","arguments":{}}}'
check "with ACF gone, the tool refuses cleanly" "$(verdict a_deactivated)" "error"
check "saying it is not loaded, not that it does not exist" \
  "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("not loaded" in t.lower())' a_deactivated)" "True"
docker compose exec -T cli wp plugin activate advanced-custom-fields >/dev/null 2>&1

echo "-- cleanup --"
wpc post delete "$TEST_POST" --force >/dev/null 2>&1
docker compose exec -T cli rm -f /var/www/html/wp-content/mu-plugins/smoke-acf-fields.php 2>/dev/null
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_acf"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
