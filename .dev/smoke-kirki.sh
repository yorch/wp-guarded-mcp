#!/bin/bash
# Smoke test for the Kirki tools.
#
# Its own file because it needs Kirki installed and the Kirki tool group switched on,
# and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin install kirki --activate
#   ./smoke-kirki.sh
#
# Every check corresponds to something that was wrong or could quietly go wrong, and each
# asserts the stored state rather than the wording of the reply. The guard checks follow
# the pattern from AGENTS.md: read the value, make the hostile call, read it back, put it
# back, and only then compare.
set -u
#   GMCP_URL=http://localhost:8081 ./smoke-kirki.sh
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

if ! docker compose exec -T cli wp plugin is-active kirki >/dev/null 2>&1; then
  echo "Kirki is not active. Install it first:"
  echo "  docker compose exec -T cli wp plugin install kirki --activate"
  exit 2
fi
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_kirki"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

# Register a Kirki field for the suite to work with. Kirki fields are registered in PHP
# on every request (typically in a theme's functions.php), so registering them through
# wp eval only affects that one CLI process. An mu-plugin is the right shape: it runs on
# every HTTP request the way a theme's functions.php would, so the fields exist when the
# MCP endpoint's tools look for them.
docker compose exec -T cli mkdir -p /var/www/html/wp-content/mu-plugins 2>/dev/null
docker compose exec -T cli bash -c 'cat > /var/www/html/wp-content/mu-plugins/smoke-kirki-fields.php' < "$(dirname "$0")/smoke-kirki-fields.php" 2>/dev/null

echo "-- the tools are offered --"
call k_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "the Kirki group is listed when switched on" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};need={"kirki_list_fields","kirki_get_field_value","kirki_set_field_value","kirki_regenerate_css","kirki_export_config"};print(sorted(need-n) or True)' k_list)" "True"

echo "-- field discovery --"
call k_fields '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"kirki_list_fields","arguments":{}}}'
check "listing fields succeeds" "$(verdict k_fields)" "ok"
check "and the theme_mod field is registered" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print("smoke_color_mod" in f)' k_fields)" "True"
check "and the option field is registered" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print("smoke_text_opt" in f)' k_fields)" "True"
check "and the theme_mod field reports option_type theme_mod" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print(f["smoke_color_mod"]["option_type"])' k_fields)" "theme_mod"
check "and the option field reports option_type option" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print(f["smoke_text_opt"]["option_type"])' k_fields)" "option"
check "and the option field reports its option_name" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print(f["smoke_text_opt"]["option_name"])' k_fields)" "smoke_options"
check "and the standalone option field is registered" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print("smoke_standalone_opt" in f)' k_fields)" "True"
check "and the standalone option field reports option_type option" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print(f["smoke_standalone_opt"]["option_type"])' k_fields)" "option"
check "and the standalone option field has no option_name" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print(f["smoke_standalone_opt"]["option_name"])' k_fields)" ""

echo "-- reading values with defaults --"
call k_get '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"kirki_get_field_value","arguments":{"field_id":"smoke_color_mod"}}}'
check "an unset field reads back its default" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d["value"])' k_get)" "#ff0000"

echo "-- writing a theme_mod field --"
call k_set '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"kirki_set_field_value","arguments":{"field_id":"smoke_color_mod","value":"#00ff00"}}}'
check "setting a theme_mod field succeeds" "$(verdict k_set)" "ok"
# Assert the stored state through Kirki's own API, not the reply text. A tool reporting
# "set" and a value landing where Kirki reads it are different claims.
check "and Kirki::get_option reads back the new value" \
  "$(wpc eval 'echo Kirki::get_option("smoke_config","smoke_color_mod");')" "#00ff00"
check "and the raw theme_mod agrees" \
  "$(wpc eval 'echo get_theme_mod("smoke_color_mod");')" "#00ff00"

echo "-- writing an option field (grouped) --"
call k_setopt '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"kirki_set_field_value","arguments":{"field_id":"smoke_text_opt","value":"hello-world"}}}'
check "setting a grouped option field succeeds" "$(verdict k_setopt)" "ok"
check "and Kirki::get_option reads back the new value" \
  "$(wpc eval 'echo Kirki::get_option("smoke_opt_config","smoke_text_opt");')" "hello-world"
# The value lands inside the smoke_options array, not as a standalone option.
check "and the grouped option holds the new value" \
  "$(wpc eval 'echo get_option("smoke_options")["smoke_text_opt"];')" "hello-world"
# A grouped option write must merge, not replace. Seed a sibling key and write the
# field; the sibling must survive.
docker compose exec -T cli wp eval '$o=get_option("smoke_options",[]); $o["sibling_key"]="keep-me"; update_option("smoke_options",$o);' >/dev/null 2>&1
call k_setopt2 '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"kirki_set_field_value","arguments":{"field_id":"smoke_text_opt","value":"second-write"}}}'
check "a grouped write does not wipe a sibling key" \
  "$(wpc eval 'echo get_option("smoke_options")["sibling_key"];')" "keep-me"
check "and the field itself was updated" \
  "$(wpc eval 'echo get_option("smoke_options")["smoke_text_opt"];')" "second-write"

echo "-- writing a standalone option field --"
call k_setstd '{"jsonrpc":"2.0","id":60,"method":"tools/call","params":{"name":"kirki_set_field_value","arguments":{"field_id":"smoke_standalone_opt","value":"standalone-value"}}}'
check "setting a standalone option field succeeds" "$(verdict k_setstd)" "ok"
check "and Kirki::get_option reads back the new value" \
  "$(wpc eval 'echo Kirki::get_option("smoke_standalone_config","smoke_standalone_opt");')" "standalone-value"
check "and the standalone option holds the new value" \
  "$(wpc eval 'echo get_option("smoke_standalone_opt");')" "standalone-value"

echo "-- the theme_mod partial update --"
# The whole point of wp_set_theme_mod vs wp_update_option on theme_mods_*: a single-key
# write must not wipe nav_menu_locations or any other mod. Seed a second mod, write the
# field, and confirm the second mod survives.
docker compose exec -T cli wp eval 'set_theme_mod("smoke_sibling_mod","sibling-value");' >/dev/null 2>&1
call k_setmod '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"wp_set_theme_mod","arguments":{"key":"smoke_color_mod","value":"#0000ff"}}}'
check "wp_set_theme_mod succeeds" "$(verdict k_setmod)" "ok"
check "and the mod holds the new value" \
  "$(wpc eval 'echo get_theme_mod("smoke_color_mod");')" "#0000ff"
check "and the sibling mod survived" \
  "$(wpc eval 'echo get_theme_mod("smoke_sibling_mod");')" "sibling-value"

echo "-- credential redaction --"
# A field named smoke_api_key holds a secret. The read tool must redact it, not hand the
# value to the model in plain text.
call k_secret '{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"kirki_get_field_value","arguments":{"field_id":"smoke_api_key"}}}'
check "a credential-shaped field is redacted on read" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d["value"])' k_secret)" "[redacted]"
check "and the secret value is not in the reply" \
  "$(py 'import json,sys;d=json.load(sys.stdin)["result"]["content"][0]["text"];print("sk-live-secret" not in d)' k_secret)" "True"
# And the export redacts it too.
call k_export '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"kirki_export_config","arguments":{}}}'
check "the export succeeds" "$(verdict k_export)" "ok"
check "and the export redacts the credential field" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print(f["smoke_api_key"]["value"])' k_export)" "[redacted]"
check "and the secret is not in the export" \
  "$(py 'import json,sys;d=json.load(sys.stdin)["result"]["content"][0]["text"];print("sk-live-secret" not in d)' k_export)" "True"
# The default is redacted too, not just the value. A field named api_key can have a
# secret as its default, and the list and export must both blank it.
check "and the export redacts the credential field default" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print(f["smoke_api_key"]["default"])' k_export)" "[redacted]"
check "and the list redacts the credential field default" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f={x["settings"]:x for x in d["fields"]};print(f["smoke_api_key"]["default"])' k_fields)" "[redacted]"

echo "-- the option guard applies to Kirki writes --"
# A Kirki field with option_type=option and no option_name writes to a standalone option
# named by its settings key. The mu-plugin registers a field named "siteurl" for this
# test. The same guard wp_update_option consults must refuse it here. The guard test
# follows the AGENTS.md pattern: read the value, make the hostile call, read it back.
SITEURL_WAS=$(wpc option get siteurl)
call k_guard '{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"kirki_set_field_value","arguments":{"field_id":"siteurl","value":"http://evil.test"}}}'
check "a Kirki field writing siteurl is refused" "$(verdict k_guard)" "error"
SITEURL_NOW=$(wpc option get siteurl)
# The guard test must not depend on the guard: read the option back rather than trust
# the refusal above.
check "and the siteurl was not changed" "$SITEURL_NOW" "$SITEURL_WAS"

echo "-- CSS cache clearing --"
# Seed the font caches so the tool has something to clear.
docker compose exec -T cli wp eval 'update_option("kirki_downloaded_font_files",["https://fonts.googleapis.com/css?family=Roboto"=>"local-roboto.woff2"]); set_transient("kirki_remote_url_contents",["cached"=>"css"],WEEK_IN_SECONDS);' >/dev/null 2>&1
call k_css '{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"kirki_regenerate_css","arguments":{}}}'
check "regenerate_css succeeds" "$(verdict k_css)" "ok"
check "and the font option was cleared" \
  "$(wpc eval 'echo get_option("kirki_downloaded_font_files",null)===null?"absent":"present";')" "absent"
check "and the font transient was cleared" \
  "$(wpc eval 'echo get_transient("kirki_remote_url_contents")===false?"absent":"present";')" "absent"
check "and the reply says the page cache is separate" \
  "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("page cache" in t.lower() or "wp_flush_cache" in t)' k_css)" "True"

echo "-- undo --"
# A theme_mod write is journalled, so wp_undo_change can put it back. Write a value,
# find the journal entry, undo it, and confirm the old value is back.
call k_undo_write '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"kirki_set_field_value","arguments":{"field_id":"smoke_color_mod","value":"#aabbcc"}}}'
check "the write for undo succeeds" "$(verdict k_undo_write)" "ok"
check "and the new value is stored" \
  "$(wpc eval 'echo get_theme_mod("smoke_color_mod");')" "#aabbcc"
call k_list_changes '{"jsonrpc":"2.0","id":13,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{"limit":5}}}'
# wp_list_changes returns a JSON array. Find the most recent kirki_set_field_value
# entry — the journal records the option row that changed (e.g. "theme_mods_* changed"),
# not the individual field id, so match on the tool name instead.
ENTRY=$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);e=[x for x in d if x.get("tool")=="kirki_set_field_value"];print(e[0]["id"] if e else "")' k_list_changes)
if [ -n "$ENTRY" ]; then
  call k_undo "{\"jsonrpc\":\"2.0\",\"id\":14,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$ENTRY\"}}}"
  check "undoing the write succeeds" "$(verdict k_undo)" "ok"
  check "and the value is back to what it was" \
    "$(wpc eval 'echo get_theme_mod("smoke_color_mod");')" "#0000ff"
else
  check "undo: found a journal entry for the field" "" "entry"
fi

echo "-- the group switch --"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_kirki"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1
call k_off '{"jsonrpc":"2.0","id":15,"method":"tools/list"}'
check "switched off, the Kirki tools are not offered" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print("kirki_list_fields" in n)' k_off)" "False"
call k_offcall '{"jsonrpc":"2.0","id":16,"method":"tools/call","params":{"name":"kirki_list_fields","arguments":{}}}'
check "and calling one anyway is refused" "$(verdict k_offcall)" "error"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_kirki"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

echo "-- Kirki deactivated --"
# With Kirki deactivated, the per-call check must refuse with a sentence, not a fatal.
docker compose exec -T cli wp plugin deactivate kirki >/dev/null 2>&1
call k_gone '{"jsonrpc":"2.0","id":17,"method":"tools/call","params":{"name":"kirki_list_fields","arguments":{}}}'
check "with Kirki gone, the tool refuses cleanly" "$(verdict k_gone)" "error"
check "saying it is not loaded, not that it does not exist" \
  "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("gated" if "not loaded" in t else t[:40])' k_gone)" "gated"
docker compose exec -T cli wp plugin activate kirki >/dev/null 2>&1

echo "-- cleanup --"
# Put back everything the suite changed.
docker compose exec -T cli wp eval '
remove_theme_mod("smoke_color_mod");
remove_theme_mod("smoke_sibling_mod");
delete_option("smoke_options");
delete_option("smoke_standalone_opt");
delete_option("kirki_downloaded_font_files");
delete_transient("kirki_remote_url_contents");
' >/dev/null 2>&1
# Remove the mu-plugin so it does not affect other suites.
docker compose exec -T cli rm -f /var/www/html/wp-content/mu-plugins/smoke-kirki-fields.php 2>/dev/null

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
