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
gmcp_make_key_level() {
  docker compose exec -T cli wp eval '
    $label = "'"$1"'";
    foreach ( GMCP_Tokens::all() as $k ) { if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); } }
    $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $a = GMCP_Tokens::create( $label, "'"$2"'", 0, [], $admins ? $admins[0]->ID : 0 );
    echo $a["secret"];' 2>/dev/null | tr -d '
'
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
# A real PNG: the tool refuses an attachment that is not an image (the first version of this
# suite imported a .txt and the tool accepted it).
printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' | base64 -d > "/tmp/$MARK.png"
docker compose cp "/tmp/$MARK.png" "cli:/tmp/$MARK.png" >/dev/null 2>&1
ATT=$(docker compose exec -T cli wp media import "/tmp/$MARK.png" --porcelain 2>/dev/null | tail -1 | tr -d '\r\n')
case "$ATT" in ''|*[!0-9]*) echo "  FAIL  seeding: the attachment fixture was not created (the seed, not the tool, is suspect)"; fail=$((fail+1)) ;; esac
echo "  attachment: $ATT"
call c2 "$(tc beaver_create_page '{"title":"BW '"$MARK"'","modules":[{"type":"heading","text":"Title here","tag":"h2"},{"type":"rich-text","text":"<p>Body text</p>"},{"type":"button","text":"Go","link":"https://example.test/x"},{"type":"photo","photo":'"$ATT"'}]}')"
check "the page is created" "$(verdict c2)" "ok"
PID=$(jget c2 ID)
check "CONTROL: the builder is enabled on it" "$(jget c2 builder_enabled)" "true"
check "stored node count is row+group+column+module for each of four modules" "$(jget c2 stored_nodes)" "16"
# The builder's own node contract, not a substring of the JSON and not the rendered page.
# The first version of this check looked for the word "heading" in _fl_builder_data and passed
# on a graph the builder could not render: a module node must be type="module" with the slug in
# settings->type (class-fl-builder.php:3088). Rendering the page was tried and abandoned — BB
# renders through a page template, and the active block theme does not call it, so that
# assertion would have been testing the theme.
check "the stored node is type=module, not the slug used as a type" "$(dbq "SELECT IF(meta_value LIKE '%\"type\";s:6:\"module\"%', 'yes','no') FROM wp_postmeta WHERE post_id=$PID AND meta_key='_fl_builder_data'")" "yes"
check "and its settings name the module slug" "$(dbq "SELECT IF(meta_value LIKE '%\"type\";s:7:\"heading\"%', 'yes','no') FROM wp_postmeta WHERE post_id=$PID AND meta_key='_fl_builder_data'")" "yes"
check "and the heading text is stored" "$(dbq "SELECT IF(meta_value LIKE '%Title here%', 'yes','no') FROM wp_postmeta WHERE post_id=$PID AND meta_key='_fl_builder_data'")" "yes"
check "the page is a draft by default" "$(wpc eval 'echo get_post_status('"$PID"');')" "draft"

echo "-- the tool sanitises, because Beaver does not --"
call c3 "$(tc beaver_create_page '{"title":"BWX '"$MARK"'","modules":[{"type":"heading","text":"Hi <script>alert(1)</script>","tag":"h2"},{"type":"rich-text","text":"<p onclick=\"x()\">ok</p><script>alert(2)</script>"}]}')"
PID2=$(jget c3 ID)
check "CONTROL: that page was created" "$(verdict c3)" "ok"
check "no <script> survives in the stored layout" "$(dbq "SELECT IF(meta_value LIKE '%<script%', 'LEAKED','clean') FROM wp_postmeta WHERE post_id=$PID2 AND meta_key='_fl_builder_data'")" "clean"
check "no onmouseover/onclick handler survives" "$(dbq "SELECT IF(meta_value LIKE '%onclick%', 'LEAKED','clean') FROM wp_postmeta WHERE post_id=$PID2 AND meta_key='_fl_builder_data'")" "clean"

echo "-- raw sinks are refused by name, not dropped --"
BEFORE=$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_type='page'")
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
check "and no page was created by any of those refusals" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_type='page'")" "$BEFORE"

echo "-- the page RENDERS what was stored (a stored-node count alone passed on a page that showed nothing) --"
# Rendering is done by the builder's own render_content_by_id(), not through a theme: the active
# block theme does not call the builder's template, so a front-end fetch would test the theme.
render() {
  docker compose exec -T cli wp eval '
    wp_set_current_user( 1 );
    $GLOBALS["gm_fetched"] = [];
    add_filter( "pre_oembed_result", function ( $r, $url ) { $GLOBALS["gm_fetched"][] = $url; return "<b>EMBEDDED</b>"; }, 10, 2 );
    ob_start(); FLBuilder::render_content_by_id( '"$1"', "div" ); $c = ob_get_clean();
    $c = preg_replace( "#<(style|script)\\b.*?</\\1>#s", "", $c );
    echo wp_json_encode( [ "html" => $c, "fetched" => $GLOBALS["gm_fetched"] ] );' 2>/dev/null > "$OUT/render.$1"
}
has() { python3 -c 'import json,sys,re
d=json.load(open(sys.argv[1]))
print("yes" if re.search(sys.argv[2], d["html"], re.S) else "no")' "$OUT/render.$1" "$2"; }
fetched() { python3 -c 'import json,sys;print(len(json.load(open(sys.argv[1]))["fetched"]))' "$OUT/render.$1"; }
render "$PID"
check "CONTROL: the render produced the builder wrapper" "$(has "$PID" 'fl-builder-content')" "yes"
check "the heading text is rendered inside an h2" "$(has "$PID" '<h2[^>]*>(\s|<[^>]+>)*Title here(\s|<[^>]+>)*</h2>')" "yes"
check "the rich text is rendered" "$(has "$PID" '<p>Body text</p>')" "yes"
check "the button is rendered with its link" "$(has "$PID" 'href="https://example\.test/x"')" "yes"
check "the photo is rendered from the attachment, not Beaver's placeholder" "$(has "$PID" '<img[^>]*src="[^"]*'"$MARK"'[^"]*\.png')" "yes"

echo "-- a line that is only a URL must not make the render fetch it --"
call e1 "$(tc beaver_create_page '{"title":"BWE '"$MARK"'","modules":[{"type":"rich-text","text":"before\n\nhttps://evil.example/line-'"$MARK"'\n\nafter"}]}')"
PIDE=$(jget e1 ID)
check "CONTROL: the page was created" "$(verdict e1)" "ok"
render "$PIDE"
check "no oEmbed fetch was attempted while rendering it" "$(fetched "$PIDE")" "0"
check "the URL is kept, as a link" "$(has "$PIDE" '<a href="https://evil\.example/line-'"$MARK"'">')" "yes"
# The probe has to be able to see a fetch at all, or "0" proves nothing.
PROBE=$(docker compose exec -T cli wp eval '$GLOBALS["gm_n"]=[]; add_filter("pre_oembed_result", function($r,$u){ $GLOBALS["gm_n"][]=$u; return "x"; }, 10, 2); global $wp_embed; $wp_embed->autoembed("a\n\nhttps://evil.example/probe-'"$MARK"'\n\nb"); echo count($GLOBALS["gm_n"]);' 2>/dev/null | tr -d '\r\n')
check "CONTROL: the same probe does see the fetch when the text is not neutralised" "$PROBE" "1"

echo "-- a path link is judged after sanitising, in the form a browser reads it --"
BEFORE=$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_type='page'")
for L in '/\t/evil.example' '/\\evil.example' '\t//evil.example' 'http:evil.example' 'https:evil.example' 'https://user:pw@evil.example/' 'evil.example/x' 'JaVaScRiPt:alert(1)' 'java\nscript:alert(1)'; do
  call l1 "$(tc beaver_create_page '{"title":"BWL '"$MARK"'","modules":[{"type":"button","text":"x","link":"'"$L"'"}]}')"
  check "link refused: $L" "$(verdict l1)" "error"
done
check "and none of them left a page behind" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_type='page'")" "$BEFORE"
call l2 "$(tc beaver_create_page '{"title":"BWL '"$MARK"'","modules":[{"type":"button","text":"a","link":"/about/us?x=1"},{"type":"button","text":"b","link":"HTTPS://Example.test/p","link_target":"_blank"}]}')"
PIDL=$(jget l2 ID)
check "CONTROL: a plain path and an absolute https link are accepted" "$(verdict l2)" "ok"
check "and the stored links are those, not a protocol-relative form" "$(dbq "SELECT IF(meta_value LIKE '%\"/about/us?x=1\"%' AND meta_value LIKE '%//Example.test/p%' AND meta_value NOT LIKE '%s:2:\"//%', 'yes','no') FROM wp_postmeta WHERE post_id=$PIDL AND meta_key='_fl_builder_data'")" "yes"

echo "-- a photo is a pointer to a post, judged the way a featured image is --"
SEED=$(docker compose exec -T cli wp eval '
  $png = base64_decode( "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==" );
  $up = wp_upload_dir();
  $mk = function ( $name, $bytes, $mime, $parent ) use ( $up ) {
    $f = $up["path"] . "/" . $name; file_put_contents( $f, $bytes );
    return wp_insert_attachment( [ "post_mime_type" => $mime, "post_title" => $name, "post_status" => "inherit", "post_parent" => $parent ], $f, $parent );
  };
  $sub = wp_insert_post( [ "post_type" => "flamingo_inbound", "post_title" => "'"$MARK"' submission", "post_status" => "publish" ] );
  echo wp_json_encode( [ "sub" => $sub, "upload" => $mk( "'"$MARK"'-up.png", $png, "image/png", $sub ), "txt" => $mk( "'"$MARK"'-n.txt", "secret", "text/plain", 0 ) ] );' 2>/dev/null | tr -d '\r\n')
SUB=$(echo "$SEED" | python3 -c 'import json,sys;print(json.load(sys.stdin)["sub"])')
UPL=$(echo "$SEED" | python3 -c 'import json,sys;print(json.load(sys.stdin)["upload"])')
TXT=$(echo "$SEED" | python3 -c 'import json,sys;print(json.load(sys.stdin)["txt"])')
echo "  seeded: submission $SUB, its upload $UPL, a text file $TXT"
BEFORE=$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_type='page'")
call p1 "$(tc beaver_create_page '{"title":"BWP '"$MARK"'","modules":[{"type":"photo","photo":'"$UPL"'}]}')"
check "a form submission's upload is refused as a photo" "$(verdict p1)" "error"
call p2 "$(tc wp_update_post_meta '{"ID":'"$PID"',"key":"_thumbnail_id","value":'"$UPL"'}')"
check "CONTROL: the same upload is refused as a featured image by the generic writer (the policy the photo must match)" "$(verdict p2)" "error"
call p3 "$(tc beaver_create_page '{"title":"BWP '"$MARK"'","modules":[{"type":"photo","photo":'"$TXT"'}]}')"
check "a text file is refused as a photo" "$(verdict p3)" "error"
call p4 "$(tc beaver_create_page '{"title":"BWP '"$MARK"'","modules":[{"type":"photo","photo":'"$SUB"'}]}')"
check "a post that is not an attachment is refused as a photo" "$(verdict p4)" "error"
check "and none of those left a page behind" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_type='page'")" "$BEFORE"
check "CONTROL: the ordinary image is accepted (so the refusals above were about the attachments)" "$(verdict c2)" "ok"
check "the stored photo module carries the id and the address derived from it" "$(dbq "SELECT IF(meta_value LIKE '%photo_src%$MARK%' AND meta_value LIKE '%\"photo\";i:$ATT;%', 'yes','no') FROM wp_postmeta WHERE post_id=$PID AND meta_key='_fl_builder_data'")" "yes"

echo "-- a failure after the page exists leaves no page behind, and success is not claimed for half a layout --"
docker compose exec -T cli sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/gmcp-smoke-bbw-fault.php' <<'PHP'
<?php
add_filter( 'fl_builder_before_update_layout_data', function ( $d ) {
  $m = get_option( 'gmcp_smoke_bbw_fault' );
  // Only for a layout that has nodes: deleting the page clears its layout through the same filter.
  if ( $m === 'throw' && $d ) { throw new RuntimeException( 'injected layout failure' ); }
  if ( $m === 'throw-always' ) { throw new RuntimeException( 'injected layout failure, also on removal' ); }
  if ( $m === 'drop' ) { return array_slice( $d, 0, 2, true ); }
  return $d;
} );
PHP
wpc eval 'update_option("gmcp_smoke_bbw_fault","");' >/dev/null
call f0 "$(tc beaver_create_page '{"title":"BWF '"$MARK"'","status":"publish","modules":[{"type":"heading","text":"x"},{"type":"heading","text":"y"}]}')"
check "CONTROL: with the fault off, a published page is created" "$(verdict f0)" "ok"
PIDF=$(jget f0 ID)
check "CONTROL: and is stored as published with its layout" "$(wpc eval 'echo get_post_status('"$PIDF"'), count((array) get_post_meta('"$PIDF"', "_fl_builder_data", true));')" "publish8"
wpc eval 'update_option("gmcp_smoke_bbw_fault","throw");' >/dev/null
BEFORE=$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE 'BWF2 $MARK%'")
call f1 "$(tc beaver_create_page '{"title":"BWF2 '"$MARK"'","status":"publish","modules":[{"type":"heading","text":"x"}]}')"
check "a layout write that throws is answered as a failure" "$(verdict f1)" "error"
check "and no page (published or not) stands in the table afterwards" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE 'BWF2 $MARK%'")" "0"
wpc eval 'update_option("gmcp_smoke_bbw_fault","drop");' >/dev/null
call f2 "$(tc beaver_create_page '{"title":"BWF3 '"$MARK"'","modules":[{"type":"heading","text":"x"},{"type":"heading","text":"y"}]}')"
check "a layout that reaches the table with nodes missing is answered as a failure, not as a page" "$(verdict f2)" "error"
check "and that page is gone too" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE 'BWF3 $MARK%'")" "0"
wpc eval 'delete_option("gmcp_smoke_bbw_fault");' >/dev/null
docker compose exec -T cli rm -f /var/www/html/wp-content/mu-plugins/gmcp-smoke-bbw-fault.php >/dev/null 2>&1

echo "-- limits, shapes, and who may call it --"
BEFORE=$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_type='page'")
MANY=$(python3 -c 'import json;print(json.dumps([{"type":"heading","text":"h"}]*51))')
call m1 "$(tc beaver_create_page '{"title":"BWM '"$MARK"'","modules":'"$MANY"'}')"
check "51 modules are refused" "$(verdict m1)" "error"
BIG=$(python3 -c 'import json;print(json.dumps([{"type":"rich-text","text":"a"*100001}]))')
call m2 "$(tc beaver_create_page '{"title":"BWM '"$MARK"'","modules":'"$BIG"'}')"
check "a field over 100000 bytes is refused" "$(verdict m2)" "error"
call m3 "$(tc beaver_create_page '{"title":"BWM '"$MARK"'","modules":{"a":{"type":"heading","text":"h"}}}')"
check "modules that are not a list are refused" "$(verdict m3)" "error"
check "and none of those left a page behind" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_type='page'")" "$BEFORE"
call m4 "$(tc beaver_create_page '{"title":"BWM '"$MARK"'","modules":"[{\"type\":\"heading\",\"text\":\"from a string\"}]"}')"
check "CONTROL: modules sent as a JSON string (as some clients send arrays) are accepted" "$(verdict m4)" "ok"
PIDM=$(jget m4 ID)
RW=$(gmcp_make_key_level "smoke beaver write rw" readwrite)
TOK_ADMIN="$TOK"; TOK="$RW"
call a1 "$(tc beaver_create_page '{"title":"BWA '"$MARK"'","modules":[{"type":"heading","text":"x"}]}')"
TOK="$TOK_ADMIN"
DR=$(docker compose exec -T cli wp eval '
  foreach ( GMCP_Tokens::all() as $k ) { if ( ( $k["label"] ?? "" ) === "smoke beaver write drafts" ) { GMCP_Tokens::revoke( $k["id"] ); } }
  $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
  $a = GMCP_Tokens::create( "smoke beaver write drafts", "admin", 0, [], $admins[0]->ID, true );
  echo $a["secret"];' 2>/dev/null | tr -d '\r\n')
TOK="$DR"
call a2 "$(tc beaver_create_page '{"title":"BWA '"$MARK"'","modules":[{"type":"heading","text":"x"}]}')"
TOK="$TOK_ADMIN"
check "a drafts-only key (capped below admin) may not call it either, even for a draft" "$(verdict a2)" "error"
check "a readwrite key may not call it" "$(verdict a1)" "error"
check "and it created nothing" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE 'BWA $MARK%'")" "0"
check "the approval queue holds it as a site change, not as a read" "$(wpc eval 'echo GMCP_Approvals::classify(["name"=>"beaver_create_page","accessLevel"=>"admin"]);')" "site"
call j1 "$(tc wp_list_changes '{"limit":20}')"
check "the layout rows it wrote are in the change journal, under this tool" "$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]
print("yes" if "beaver_create_page" in t and "_fl_builder_data" in t else "no")' "" j1)" "yes"

echo "-- a REST field is not a door around the layout rule --"
# Beaver registers fl_builder_css_js on its pages while its debug transient is set, and the field's
# callbacks write `_fl_builder_data_settings` (css and js the public page then loads). The generated
# REST tools passed it straight through. A second field, registered here by a test plugin under a
# name nothing lists, stands for any other plugin's field that reaches the same rows.
REST_WAS=$(wpc eval '$o=get_option("gmcp_options",[]); echo !empty($o["mcp_tools_rest"]) ? 1 : 0;')
wpc eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_rest"]=true;update_option("gmcp_options",$o,false);delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null 2>&1
docker compose exec -T cli mkdir -p /var/www/html/wp-content/mu-plugins 2>/dev/null
docker compose exec -T cli bash -c 'cat > /var/www/html/wp-content/mu-plugins/smoke-bw-rest-door.php' <<'MUPHP'
<?php
add_action( 'rest_api_init', function () {
  register_rest_field( 'page', 'gmcp_door_layout', [
    'get_callback' => function ( $post ) { $d = get_post_meta( $post['id'], '_fl_builder_data_settings', true ); return is_object( $d ) ? (string) ( $d->js ?? '' ) : ''; },
    'update_callback' => function ( $value, $post ) { $d = get_post_meta( $post->ID, '_fl_builder_data_settings', true ); $d = is_object( $d ) ? $d : new stdClass(); $d->js = (string) $value; update_post_meta( $post->ID, '_fl_builder_data_settings', $d ); return true; },
    'schema' => [ 'type' => 'string' ],
  ] );
  register_rest_field( 'page', 'gmcp_door_benign', [
    'get_callback' => function ( $post ) { return (string) get_post_meta( $post['id'], 'gmcp_bw_benign', true ); },
    'update_callback' => function ( $value, $post ) { update_post_meta( $post->ID, 'gmcp_bw_benign', (string) $value ); return true; },
    'schema' => [ 'type' => 'string' ],
  ] );
} );
MUPHP
RWK=$(gmcp_make_key_level "smoke beaver write door rw" readwrite)
ROK=$(gmcp_make_key_level "smoke beaver write door ro" readonly)
call d0 "$(tc beaver_create_page '{"title":"BWD '"$MARK"'","modules":[{"type":"heading","text":"door"}]}')"
PIDD=$(jget d0 ID)
check "CONTROL: the victim page is created" "$(verdict d0)" "ok"
LIST_TOK="$TOK"; TOK="$RWK"
call d1 '{"jsonrpc":"2.0","id":7,"method":"tools/list"}'
TOK="$LIST_TOK"
check "CONTROL: the generated update_pages tool is offered to a readwrite key" "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["tools"];print(len([x for x in t if x["name"]=="update_pages"]))' "" d1)" "1"
check "and its schema does not offer the layout field" "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["tools"];x=[y for y in t if y["name"]=="update_pages"][0];print("fl_builder_css_js" in json.dumps(x))' "" d1)" "False"
DMARK="doormark$MARK"
layout_js() { dbq "SELECT IF(meta_value LIKE '%$DMARK%', 'planted', 'clean') FROM wp_postmeta WHERE post_id=$PIDD AND meta_key='_fl_builder_data_settings'"; }
reset_layout() { wpc eval '$d=new stdClass();$d->css="";$d->js="";update_post_meta('"$PIDD"',"_fl_builder_data_settings",$d);update_post_meta('"$PIDD"',"_fl_builder_draft_settings",$d);' >/dev/null 2>&1; }
reset_layout
check "CONTROL: the probe finds a planted value (so 'clean' below means something)" "$(wpc eval '$d=new stdClass();$d->js="'"$DMARK"'";update_post_meta('"$PIDD"',"_fl_builder_data_settings",$d);' >/dev/null; layout_js)" "planted"
reset_layout
check "CONTROL: and finds it clean again after the reset" "$(layout_js)" "clean"
wpc eval 'set_transient("fl_debug_mode","gmcpdoor",3600);' >/dev/null 2>&1
check "CONTROL: Beaver's field is registered while its debug transient is set" "$(wpc eval 'do_action("rest_api_init"); global $wp_rest_additional_fields; echo isset($wp_rest_additional_fields["page"]["fl_builder_css_js"]) ? "registered" : "absent";')" "registered"
TOK="$RWK"
call d2 "$(tc update_pages '{"id":'"$PIDD"',"fl_builder_css_js":{"css":"body{x:1}","js":"'"$DMARK"'"}}')"
call d3 "$(tc update_pages '{"id":'"$PIDD"',"gmcp_door_layout":"'"$DMARK"'"}')"
call d4 "$(tc update_pages '{"id":'"$PIDD"',"gmcp_door_benign":"benign'"$MARK"'"}')"
TOK="$LIST_TOK"
check "update_pages with fl_builder_css_js is refused" "$(verdict d2)" "error"
check "and the STORED layout settings do not hold the value" "$(layout_js)" "clean"
check "an unlisted field that writes the same row is refused" "$(verdict d3)" "error"
check "and the STORED layout settings still do not hold the value" "$(layout_js)" "clean"
check "CONTROL: a field that writes an ordinary meta key is accepted" "$(verdict d4)" "ok"
check "and its STORED value is there" "$(wpc eval 'echo get_post_meta('"$PIDD"',"gmcp_bw_benign",true);')" "benign$MARK"
wpc eval '$d=new stdClass();$d->css="";$d->js="'"$DMARK"'";update_post_meta('"$PIDD"',"_fl_builder_data_settings",$d);' >/dev/null 2>&1
for who in "$RWK" "$ROK" "$TOK"; do
  TOK="$who"
  call g1 "$(tc get_pages '{"id":'"$PIDD"',"context":"edit"}')"
  call g2 "$(tc list_pages '{"context":"edit","status":"any","per_page":100}')"
  call g3 "$(tc get_pages '{"id":'"$PIDD"',"_fields":"id,title,gmcp_door_layout,fl_builder_css_js"}')"
  TOK="$LIST_TOK"
  check "a reply (get, list, _fields) never carries the layout's script [${who:0:9}]" "$(for f in g1 g2 g3; do grep -c "$DMARK" "$OUT/$f"; done | tr '\n' ' ')" "0 0 0 "
  check "CONTROL: and the same replies are real pages [${who:0:9}]" "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("title" in t or "id" in t)' "" g1)" "True"
done
reset_layout
echo "-- and the switch that opens it is not an option any writer may set --"
wpc eval 'delete_transient("fl_debug_mode");delete_option("fl_debug_mode");delete_option("_transient_fl_debug_mode");delete_option("_transient_timeout_fl_debug_mode");' >/dev/null 2>&1
fl_rows() { dbq "SELECT COUNT(*) FROM wp_options WHERE option_name IN ('_transient_fl_debug_mode','_transient_timeout_fl_debug_mode','fl_debug_mode')"; }
check "CONTROL: no flag row stands before the attempts" "$(fl_rows)" "0"
for name in '_transient_fl_debug_mode' '_transient_timeout_fl_debug_mode' 'fl_debug_mode' '_TRANSIENT_FL_DEBUG_MODE' '_transient_fl_debug_mode '; do
  call o1 "$(tc wp_update_option '{"key":"'"$name"'","value":"gmcpdoor"}')"
  check "wp_update_option '$name' is refused" "$(verdict o1)" "error"
done
check "and no flag row was written, whatever the spelling" "$(fl_rows)" "0"
check "and the Beaver field is still not registered" "$(wpc eval 'do_action("rest_api_init"); global $wp_rest_additional_fields; echo isset($wp_rest_additional_fields["page"]["fl_builder_css_js"]) ? "registered" : "absent";')" "absent"
wpc eval 'set_transient("fl_debug_mode","gmcpdoor",3600);' >/dev/null 2>&1
check "CONTROL: the probe finds the row once the flag is really set" "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name='_transient_fl_debug_mode'")" "1"
call o3 "$(tc wp_get_option '{"key":"_transient_fl_debug_mode"}')"
check "the flag's value (also its debug token) is not readable" "$(grep -c gmcpdoor "$OUT/o3")" "0"
call o4 "$(tc wp_delete_option '{"key":"_transient_fl_debug_mode"}')"
check "and it is not deletable through the option tool either" "$(verdict o4)" "error"
check "CONTROL: the row is still there after that attempt" "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name='_transient_fl_debug_mode'")" "1"
wpc eval 'delete_transient("fl_debug_mode");delete_option("fl_debug_mode");' >/dev/null 2>&1
docker compose exec -T cli rm -f /var/www/html/wp-content/mu-plugins/smoke-bw-rest-door.php >/dev/null 2>&1
[ "$REST_WAS" = "1" ] || wpc eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_rest"]=false;update_option("gmcp_options",$o,false);delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null 2>&1
for p in "$PIDD"; do wpc eval 'wp_delete_post('"${p:-0}"', true);' >/dev/null 2>&1; done

echo "-- cleanup --"
for p in "$PID" "$PID2" "$PIDE" "$PIDL" "$PIDF" "$PIDM" "$SUB" "$UPL" "$TXT" "$ATT"; do wpc eval 'wp_delete_post('"${p:-0}"', true);' >/dev/null 2>&1; done
wpc eval 'foreach ((array) get_posts(["post_type"=>"any","post_status"=>"any","numberposts"=>-1,"fields"=>"ids","s"=>"'"$MARK"'"]) as $i) { wp_delete_post($i,true); }' >/dev/null 2>&1
check "the fixtures are gone" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE '%$MARK%'")" "0"
off
echo; printf 'beaver-write: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
