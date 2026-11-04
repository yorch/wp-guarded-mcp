#!/bin/bash
# Smoke test for the Polylang tools (read only).
#
# STATUS WHEN WRITTEN: NOT RUN. Authored without a Docker daemon and without Polylang
# installed. Seeding goes through Polylang's own writers (pll_save_post_translations,
# pll_set_post_language), which are assumed to exist; correct them on first run if not.
#
#   docker compose exec -T cli wp plugin install polylang --activate
#   ./smoke-polylang.sh
#
# The check that matters most is that the three empty answers stay apart: Polylang with no
# languages (not_configured), a post with no language (language_not_assigned) and a post with a
# language and no siblings (no_translations).
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
TOK=$(gmcp_make_key "smoke polylang")
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
tool() { call "$1" "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}"; }
state() { py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["state"])' "$1"; }

if ! docker compose exec -T cli wp plugin is-active polylang >/dev/null 2>&1; then
  echo "Polylang is not active. Install it first:"
  echo "  docker compose exec -T cli wp plugin install polylang --activate"
  exit 2
fi
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_polylang"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

# Languages are created through Polylang's own model, only if the site has none.
HAD=$(docker compose exec -T cli wp eval 'echo count( pll_languages_list() );' 2>/dev/null | tr -d '\r\n')
echo "  languages configured before the suite: $HAD"

echo "-- not configured is not 'no translations' --"
if [ "$HAD" = "0" ]; then
  tool p_l0 pll_list_languages '{}'
  check "no languages defined reports not_configured" "$(state p_l0)" "not_configured"
  P0=$(wpc post create --post_title="pll smoke none" --post_status=publish --porcelain)
  tool p_t0 pll_get_post_translations "{\"post_id\":$P0}"
  check "a post on a site with no languages reports not_configured" "$(state p_t0)" "not_configured"
  docker compose exec -T cli wp eval '
    $m = PLL()->model;
    $m->add_language( [ "name" => "English", "slug" => "en", "locale" => "en_US", "rtl" => 0, "term_group" => 0 ] );
    $m->add_language( [ "name" => "Deutsch", "slug" => "de", "locale" => "de_DE", "rtl" => 0, "term_group" => 1 ] );' >/dev/null 2>&1
else
  echo "  SKIP  the not_configured checks need a site with no languages"
fi

echo "-- configured --"
tool p_l pll_list_languages '{}'
check "list succeeds" "$(verdict p_l)" "ok"
check "state is configured" "$(state p_l)" "configured"
check "CONTROL: STORED languages (via Polylang) agree with the reply" \
  "$(py 'import json,sys;print(len(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["languages"]))' p_l)" \
  "$(docker compose exec -T cli wp eval 'echo count( pll_languages_list() );' 2>/dev/null | tr -d '\r\n')"

A=$(wpc post create --post_title="pll smoke en" --post_status=publish --porcelain)
B=$(wpc post create --post_title="pll smoke de" --post_status=draft --porcelain)
C=$(wpc post create --post_title="pll smoke nolang" --post_status=publish --porcelain)
docker compose exec -T cli wp eval 'pll_set_post_language( '"$A"', "en" ); pll_set_post_language( '"$B"', "de" );' >/dev/null 2>&1

echo "-- the three empty answers stay apart --"
tool p_a pll_get_post_translations "{\"post_id\":$A}"
check "a post with a language and no group partner is no_translations" "$(state p_a)" "no_translations"
check "language is reported" "$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["language"])' p_a)" "en"
tool p_c pll_get_post_translations "{\"post_id\":$C}"
check "a post with no language is language_not_assigned, not no_translations" "$(state p_c)" "language_not_assigned"

echo "-- a linked pair --"
docker compose exec -T cli wp eval 'pll_save_post_translations( [ "en" => '"$A"', "de" => '"$B"' ] );' >/dev/null 2>&1
STORED=$(docker compose exec -T cli wp eval 'echo json_encode( pll_get_post_translations( '"$A"' ) );' 2>/dev/null | tr -d '\r\n')
check "CONTROL: Polylang itself holds the link" "$(echo "$STORED" | python3 -c 'import json,sys;d=json.load(sys.stdin);print(d.get("de")=='"$B"')')" "True"
tool p_a2 pll_get_post_translations "{\"post_id\":$A}"
check "the pair is reported as translated" "$(state p_a2)" "translated"
check "the sibling is the German draft, with its status" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["translations"];print([(x["language"],x["post_id"],x["post_status"]) for x in d]=='"[(\"de\",$B,\"draft\")]"')' p_a2)" "True"
check "the reply carries no title or content" \
  "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("pll smoke" in t)' p_a2)" "False"
tool p_x pll_get_post_translations '{"post_id":999999999}'
check "an unknown post is an error" "$(verdict p_x)" "error"

echo "-- there is no writer --"
call p_all '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "only the two read tools exist (control: both are seen)" \
  "$(py 'import json,sys;n=sorted(t["name"] for t in json.load(sys.stdin)["result"]["tools"] if t["name"].startswith("pll_"));print(n)' p_all)" "['pll_get_post_translations', 'pll_list_languages']"

echo "-- a group that is off refuses by name --"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_polylang"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1
tool p_off pll_list_languages '{}'
check "refused with the group off" "$(verdict p_off)" "error"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_polylang"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1

for id in $A $B $C ${P0:-}; do [ -n "$id" ] && wpc post delete "$id" --force >/dev/null 2>&1; done
echo
echo "$pass passed, $fail failed  (UNRUN: written without a Docker daemon or Polylang installed)"
[ "$fail" -eq 0 ]
