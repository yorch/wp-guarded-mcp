#!/bin/bash
# Smoke test for beaver_create_page. Asserts STORED layout meta, never the reply.
#
# The assertions that matter are the sanitising ones: Beaver's write path applies no
# sanitisation, so the tool is the only control, and a reply saying "created" proves nothing
# about what landed in `_fl_builder_data`.
set -u
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
gmcp_make_key() {
  docker compose exec -T cli wp eval '
    $label = "'"$1"'";
    foreach ( GMCP_Tokens::all() as $k ) { if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); } }
    $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $a = GMCP_Tokens::create( $label, "admin", 0, [], $admins ? $admins[0]->ID : 0 );
    echo $a["secret"];' 2>/dev/null | tr -d '\r\n'
}
TOK=$(gmcp_make_key "smoke beaver write")
case "$TOK" in gmcp_*) ;; *) echo "Could not create a key." >&2; exit 1 ;; esac
OUT=$(mktemp -d); pass=0; fail=0
call() { curl -sS -X POST "$URL" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$2" -o "$OUT/$1"; }
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1)); else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
py() { python3 -c "$1" "$2" < "$OUT/$3"; }
verdict() { py 'import json,sys;d=json.load(sys.stdin);print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "" "$1"; }
jget() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
try: j=json.loads(t)
except Exception: print("UNPARSEABLE"); sys.exit()
print(str(j.get(sys.argv[1],"")).lower())' "$2" "$1"; }
tc() { printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2"; }
dbq() { docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
on()  { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_beaver_write"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
off() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_beaver_write"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
count() { curl -sS -X POST "$URL" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d '{"jsonrpc":"2.0","id":7,"method":"tools/list"}' | python3 -c "import json,sys;t=json.load(sys.stdin)['result']['tools'];print(len([x for x in t if x['name']=='beaver_create_page']))"; }

if ! docker compose exec -T cli wp plugin is-active beaver-builder-lite-version >/dev/null 2>&1; then
  echo "Beaver Builder is not active."; exit 2
fi
MARK="bwmark$(date +%s)"

echo "-- the switch --"
off; check "off: the tool is not registered" "$(count)" "0"
on;  check "on: the tool is registered" "$(count)" "1"

echo "-- a page is built and the STORED layout is what is checked --"
call c1 "$(tc beaver_create_page '{"title":"BW '"$MARK"'","modules":[{"type":"heading","text":"Title here","tag":"h2"},{"type":"rich-text","text":"<p>Body text</p>"},{"type":"button","text":"Go","link":"https://example.test/x"},{"type":"photo","photo":0}]}')"
check "a photo without a valid attachment is refused (the fixture needs a real one)" "$(verdict c1)" "error"
# The fixture, in the order that works: write the file on the host, copy it in, THEN import.
printf 'fixture' > "/tmp/$MARK.txt"
docker compose cp "/tmp/$MARK.txt" "cli:/tmp/$MARK.txt" >/dev/null 2>&1
ATT=$(docker compose exec -T cli wp media import "/tmp/$MARK.txt" --porcelain 2>/dev/null | tail -1 | tr -d '\r\n')
case "$ATT" in ''|*[!0-9]*) echo "  FAIL  seeding: the attachment fixture was not created (the seed, not the tool, is suspect)"; fail=$((fail+1)) ;; esac
echo "  attachment: $ATT"
call c2 "$(tc beaver_create_page '{"title":"BW '"$MARK"'","modules":[{"type":"heading","text":"Title here","tag":"h2"},{"type":"rich-text","text":"<p>Body text</p>"},{"type":"button","text":"Go","link":"https://example.test/x"},{"type":"photo","photo":'"$ATT"'}]}')"
check "the page is created" "$(verdict c2)" "ok"
PID=$(jget c2 ID)
check "CONTROL: the builder is enabled on it" "$(jget c2 builder_enabled)" "true"
check "stored node count is rows+columns+modules" "$(jget c2 stored_nodes)" "12"
check "CONTROL: the stored layout really holds a heading node" "$(dbq "SELECT IF(meta_value LIKE '%\"heading\"%', 'yes','no') FROM wp_postmeta WHERE post_id=$PID AND meta_key='_fl_builder_data'")" "yes"
check "the page is a draft by default" "$(wpc eval 'echo get_post_status('"$PID"');')" "draft"

echo "-- the tool sanitises, because Beaver does not --"
call c3 "$(tc beaver_create_page '{"title":"BWX '"$MARK"'","modules":[{"type":"heading","text":"Hi <script>alert(1)</script>","tag":"h2"},{"type":"rich-text","text":"<p onclick=\"x()\">ok</p><script>alert(2)</script>"}]}')"
PID2=$(jget c3 ID)
check "CONTROL: that page was created" "$(verdict c3)" "ok"
check "no <script> survives in the stored layout" "$(dbq "SELECT IF(meta_value LIKE '%<script%', 'LEAKED','clean') FROM wp_postmeta WHERE post_id=$PID2 AND meta_key='_fl_builder_data'")" "clean"
check "no onmouseover/onclick handler survives" "$(dbq "SELECT IF(meta_value LIKE '%onclick%', 'LEAKED','clean') FROM wp_postmeta WHERE post_id=$PID2 AND meta_key='_fl_builder_data'")" "clean"

echo "-- raw sinks are refused by name, not dropped --"
call r1 "$(tc beaver_create_page '{"title":"r '"$MARK"'","modules":[{"type":"heading","text":"x","bb_js_code":"alert(1)"}]}')"
check "bb_js_code is refused" "$(verdict r1)" "error"
call r2 "$(tc beaver_create_page '{"title":"r '"$MARK"'","modules":[{"type":"button","text":"x","link":"//evil.example.com/x"}]}')"
check "a protocol-relative link is refused" "$(verdict r2)" "error"
call r3 "$(tc beaver_create_page '{"title":"r '"$MARK"'","modules":[{"type":"button","text":"x","link":"javascript:alert(1)"}]}')"
check "a javascript: link is refused" "$(verdict r3)" "error"
call r4 "$(tc beaver_create_page '{"title":"r '"$MARK"'","modules":[{"type":"text","text":"x"}]}')"
check "a module type that does not exist is refused" "$(verdict r4)" "error"
call r5 "$(tc beaver_create_page '{"title":"r '"$MARK"'","modules":[{"type":"heading","text":"x","tag":"h2 onclick=alert(1)"}]}')"
check "a tag that is not an element name is refused" "$(verdict r5)" "error"

echo "-- cleanup --"
for p in "$PID" "$PID2" "$ATT"; do wpc eval 'wp_delete_post('"${p:-0}"', true);' >/dev/null 2>&1; done
wpc eval 'foreach ((array) get_posts(["post_type"=>"any","post_status"=>"any","numberposts"=>-1,"fields"=>"ids","s"=>"'"$MARK"'"]) as $i) { wp_delete_post($i,true); }' >/dev/null 2>&1
check "the fixtures are gone" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE '%$MARK%'")" "0"
off
echo; printf 'beaver-write: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
