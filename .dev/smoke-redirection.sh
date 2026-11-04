#!/bin/bash
# Smoke test for the Redirection tools.
#
# STATUS WHEN WRITTEN: NOT RUN. It was authored without a Docker daemon and without
# Redirection installed, so every Redirection API assumption in it (Red_Item::get_by_id,
# create, get_filtered, to_json keys, delete) is unverified. First run it where Redirection is
# installed and expect to correct the seeding calls before trusting a pass.
#
# Its own file because it needs Redirection installed and its tool group switched on.
#
#   docker compose exec -T cli wp plugin install redirection --activate
#   ./smoke-redirection.sh
#
# Every check asserts the stored state, read back through Redirection's own classes, rather
# than the wording of the reply. Every "was not created" check has a control proving the probe
# can see a row that IS there.
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
    $a = GMCP_Tokens::create( $label, "'"$2"'", 0, [], $admins ? $admins[0]->ID : 0 );
    echo $a["secret"];' 2>/dev/null | tr -d '\r\n'
}
TOK=$(gmcp_make_key "smoke redirection" admin)
case "$TOK" in
  gmcp_*) ;;
  *) echo "Could not create an API key for the suite. Is the plugin active?" >&2; exit 1 ;;
esac
TOK_RW=$(gmcp_make_key "smoke redirection rw" readwrite)
OUT=$(mktemp -d)
pass=0; fail=0
call() { curl -sS -X POST "$URL" -H "Authorization: Bearer ${3:-$TOK}" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$2" -o "$OUT/$1"; }
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1));
  else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
py() { python3 -c "$1" < "$OUT/$2"; }
verdict() { py 'import json,sys;d=json.load(sys.stdin);print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "$1"; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
tool() { # tool <outfile> <name> <json arguments> [token]
  call "$1" "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}" "${4:-$TOK}"; }

if ! docker compose exec -T cli wp plugin is-active redirection >/dev/null 2>&1; then
  echo "Redirection is not active. Install it first:"
  echo "  docker compose exec -T cli wp plugin install redirection --activate"
  exit 2
fi
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_redirection"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

# Fixtures go through Redirection's own API, and are removed through it, so a second run starts
# from the same place. Everything the suite makes carries the title prefix gmcp-smoke.
cleanup() {
  docker compose exec -T cli wp eval '
    $page = Red_Item::get_filtered( [ "page" => 0, "per_page" => 200, "orderby" => "id", "direction" => "desc" ] );
    foreach ( $page["items"] as $i ) {
      $j = is_object( $i ) ? $i->to_json() : $i;
      if ( strpos( (string) ( $j["title"] ?? "" ), "gmcp-smoke" ) === 0 ) { Red_Item::get_by_id( (int) $j["id"] )->delete(); }
    }' >/dev/null 2>&1
}
# How many redirects exist whose source is $1, read through the plugin's own query layer.
by_source() {
  docker compose exec -T cli wp eval '
    $n = 0;
    $page = Red_Item::get_filtered( [ "page" => 0, "per_page" => 200, "orderby" => "id", "direction" => "desc" ] );
    foreach ( $page["items"] as $i ) { $j = is_object( $i ) ? $i->to_json() : $i; if ( ( $j["url"] ?? "" ) === "'"$1"'" ) { $n++; } }
    echo $n;' 2>/dev/null | tr -d '\r\n'
}
# A field of one stored redirect: stored <id> <url|enabled|target>
stored() {
  docker compose exec -T cli wp eval '
    $i = Red_Item::get_by_id( '"$1"' ); if ( !$i ) { echo "none"; return; }
    $j = $i->to_json();
    $f = "'"$2"'";
    if ( $f === "target" ) { $a = $j["action_data"] ?? null; echo is_array( $a ) ? ( $a["url"] ?? "" ) : $a; }
    elseif ( $f === "enabled" ) { echo $i->is_enabled() ? "yes" : "no"; }
    else { echo $j[ $f ] ?? ""; }' 2>/dev/null | tr -d '\r\n'
}
seed() { # seed <source> <target> <title>  -> prints id; written by Redirection itself
  docker compose exec -T cli wp eval '
    $i = Red_Item::create( [ "url" => "'"$1"'", "match_type" => "url", "action_type" => "url", "action_code" => 301,
      "action_data" => [ "url" => "'"$2"'" ], "regex" => false, "group_id" => 1, "title" => "'"$3"'", "position" => 0 ] );
    echo is_wp_error( $i ) ? "ERR" : $i->get_id();' 2>/dev/null | tr -d '\r\n'
}
cleanup

echo "-- the tools are offered, and the state block is honest --"
call r_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "the Redirection group is listed when switched on" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};need={"redirection_list","redirection_get","redirection_create","redirection_update","redirection_set_enabled"};print(sorted(need-n) or True)' r_list)" "True"
check "there is no delete tool (control: the probe sees a tool that is there)" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print(("redirection_delete" in n, "redirection_create" in n))' r_list)" "(False, True)"
tool r_l '{}'
check "list succeeds" "$(verdict r_l)" "ok"
check "log_state says the plugin is loaded and readable" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["log_state"];print((d["plugin_loaded"],d["readable"],isinstance(d["redirects"],int)))' r_l)" "(True, True, True)"
tool r_l2 '{"per_page":1000}'
check "list size is capped at 50 whatever is asked" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d["per_page"]<=50 and len(d["redirects"])<=50)' r_l2)" "True"

echo "-- an acceptable redirect is created, and stored as asked --"
tool r_c '{"source":"/gmcp-smoke-old","target":"/gmcp-smoke-new","title":"gmcp-smoke ok"}'
check "creating a same-site redirect succeeds" "$(verdict r_c)" "ok"
RID=$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["redirect"]["id"])' r_c)
check "STORED: source" "$(stored "$RID" url)" "/gmcp-smoke-old"
check "STORED: target" "$(stored "$RID" target)" "/gmcp-smoke-new"
check "STORED: enabled" "$(stored "$RID" enabled)" "yes"
check "CONTROL: the by_source probe finds a row that exists" "$(by_source /gmcp-smoke-old)" "1"

echo "-- refusals: each must leave nothing stored --"
refuse() { # refuse <label> <source> <json args>
  tool r_x "redirection_create" "$3"
  check "refused: $1" "$(verdict r_x)" "error"
  check "and nothing stored for $1" "$(by_source "$2")" "0"
}
refuse "foreign https host" /gmcp-smoke-a '{"source":"/gmcp-smoke-a","target":"https://evil.example/phish","title":"gmcp-smoke a"}'
refuse "protocol-relative target" /gmcp-smoke-b '{"source":"/gmcp-smoke-b","target":"//evil.example","title":"gmcp-smoke b"}'
refuse "backslash target" /gmcp-smoke-c '{"source":"/gmcp-smoke-c","target":"/\\evil.example","title":"gmcp-smoke c"}'
refuse "userinfo host trick" /gmcp-smoke-d '{"source":"/gmcp-smoke-d","target":"https://localhost@evil.example/","title":"gmcp-smoke d"}'
refuse "javascript target" /gmcp-smoke-e '{"source":"/gmcp-smoke-e","target":"javascript:alert(1)","title":"gmcp-smoke e"}'
refuse "source on wp-login" /wp-login.php '{"source":"/wp-login.php","target":"/gmcp-smoke-new","title":"gmcp-smoke f"}'
refuse "source under wp-admin" /wp-admin/ '{"source":"/wp-admin/","target":"/gmcp-smoke-new","title":"gmcp-smoke g"}'
refuse "source on the REST API" /wp-json/mcp/v1/http '{"source":"/wp-json/mcp/v1/http","target":"/gmcp-smoke-new","title":"gmcp-smoke h"}'
refuse "encoded wp-login" /%77p-login.php '{"source":"/%77p-login.php","target":"/gmcp-smoke-new","title":"gmcp-smoke i"}'
refuse "regex source by default" '^/gmcp-smoke-r/(.*)' '{"source":"^/gmcp-smoke-r/(.*)","target":"/gmcp-smoke-new/$1","regex":true,"title":"gmcp-smoke j"}'
refuse "status code outside the list" /gmcp-smoke-k '{"source":"/gmcp-smoke-k","target":"/gmcp-smoke-new","code":418,"title":"gmcp-smoke k"}'
check "CONTROL: the earlier valid redirect is still the only row (probe still sees it)" "$(by_source /gmcp-smoke-old)" "1"

echo "-- update is held to the policy and read back --"
tool r_u "redirection_update" "{\"id\":$RID,\"target\":\"https://evil.example/\"}"
check "updating the target to a foreign host is refused" "$(verdict r_u)" "error"
check "STORED: target unchanged after the refused update" "$(stored "$RID" target)" "/gmcp-smoke-new"
tool r_u2 "redirection_update" "{\"id\":$RID,\"target\":\"/gmcp-smoke-newer\"}"
check "CONTROL: an acceptable update succeeds" "$(verdict r_u2)" "ok"
check "STORED: target changed" "$(stored "$RID" target)" "/gmcp-smoke-newer"

echo "-- enabling re-checks what is stored --"
# A redirect written by something else, to a foreign host, switched off. The tool must not
# switch it on. Seeded through Redirection's own writer, so the row is what the admin screen makes.
BAD=$(seed /gmcp-smoke-bad https://evil.example/ "gmcp-smoke bad")
docker compose exec -T cli wp eval 'Red_Item::get_by_id( '"$BAD"' )->disable();' >/dev/null 2>&1
check "CONTROL: the seeded off-policy redirect exists and is disabled" "$(stored "$BAD" enabled)" "no"
tool r_e "redirection_set_enabled" "{\"id\":$BAD,\"enabled\":true}"
check "enabling an off-policy redirect is refused" "$(verdict r_e)" "error"
check "STORED: it is still disabled" "$(stored "$BAD" enabled)" "no"
tool r_g "redirection_get" "{\"id\":$BAD}"
check "redirection_get reports the violation" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["redirect"];print(len(d["policy_violations"])>0)' r_g)" "True"
tool r_d "redirection_set_enabled" "{\"id\":$RID,\"enabled\":false}"
check "disabling is allowed" "$(verdict r_d)" "ok"
check "STORED: disabled" "$(stored "$RID" enabled)" "no"
tool r_d2 "redirection_set_enabled" "{\"id\":$RID,\"enabled\":true}"
check "CONTROL: enabling an acceptable redirect works" "$(verdict r_d2)" "ok"
check "STORED: enabled again" "$(stored "$RID" enabled)" "yes"

echo "-- access levels --"
tool r_rw '{}' "$TOK_RW"
check "a readwrite key can list" "$(verdict r_rw)" "ok"
tool r_rw2 "redirection_create" '{"source":"/gmcp-smoke-rw","target":"/gmcp-smoke-new","title":"gmcp-smoke rw"}' "$TOK_RW"
check "a readwrite key cannot create (admin tool)" "$(verdict r_rw2)" "error"
check "and nothing stored" "$(by_source /gmcp-smoke-rw)" "0"

echo "-- a group that is off refuses its tools by name --"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_redirection"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1
tool r_off "redirection_create" '{"source":"/gmcp-smoke-off","target":"/gmcp-smoke-new","title":"gmcp-smoke off"}'
check "create is refused with the group off" "$(verdict r_off)" "error"
check "and nothing stored" "$(by_source /gmcp-smoke-off)" "0"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_redirection"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

cleanup
echo
echo "$pass passed, $fail failed  (UNRUN: written without a Docker daemon or Redirection installed)"
[ "$fail" -eq 0 ]
