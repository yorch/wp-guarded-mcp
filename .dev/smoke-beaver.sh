#!/bin/bash
# Smoke test for the Beaver Builder tools.
#
#   docker compose exec -T cli wp plugin install beaver-builder-lite-version --activate
#   ./smoke-beaver.sh
#
# The assertions that matter here are about what is NOT returned. A node's settings holds the
# content, and the html module holds arbitrary markup including script, so the suite seeds a
# layout containing a marker and proves (a) the marker is really in the stored meta and (b)
# it never appears in a reply. Without the control, "absent from the reply" and "the probe is
# broken" look identical.
#
# FIXTURES go through Beaver Builder's own writer, which took three measurements to get
# right and is worth recording: FLBuilderModel::update_layout_data( $data, $status, $post_id )
# takes the status SECOND, so passing the post id there writes nothing; enable() takes no
# arguments and uses the model's current post, so set_post_id() must come first; and the
# nodes must be objects, because clean_layout_data() silently drops arrays. Each of those
# produced a plausible-looking empty layout rather than an error.
set -u
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
gmcp_make_key() {
  docker compose exec -T cli wp eval '
    $label = "'"$1"'";
    $level = "'"$2"'";
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); }
    }
    $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $a = GMCP_Tokens::create( $label, $level, 0, [], $admins ? $admins[0]->ID : 0 );
    echo $a["secret"];' 2>/dev/null | tr -d '\r\n'
}
TOK=$(gmcp_make_key "smoke beaver" admin)
case "$TOK" in gmcp_*) ;; *) echo "Could not create an API key. Is the plugin active?" >&2; exit 1 ;; esac
OUT=$(mktemp -d)
pass=0; fail=0
call() { curl -sS -X POST "$URL" -H "Authorization: Bearer $TOK" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$2" -o "$OUT/$1"; }
callas() { curl -sS -X POST "$URL" -H "Authorization: Bearer $1" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$3" -o "$OUT/$2"; }
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1));
  else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
py() { python3 -c "$1" "$2" < "$OUT/$3"; }
verdict() { py 'import json,sys
d=json.load(sys.stdin)
print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "" "$1"; }
jget() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
try: j=json.loads(t)
except Exception: print("UNPARSEABLE"); sys.exit()
cur=j
for part in sys.argv[1].split("."):
    if part=="": continue
    if isinstance(cur,list): cur=cur[int(part)]
    elif isinstance(cur,dict): cur=cur.get(part)
    else: cur=None
print("" if cur is None else str(cur).lower())' "$2" "$1"; }
has() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","")
print("yes" if sys.argv[1].lower() in t.lower() else "no")' "$2" "$1"; }
dbq() { docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
tc() { printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2"; }
group_on()  { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_beaver"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
group_off() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_beaver"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
toolcount() { curl -sS -X POST "$URL" -H "Authorization: Bearer $TOK" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":700,"method":"tools/list"}' \
  | python3 -c "import json,sys
t=json.load(sys.stdin)['result']['tools']
print(len([x for x in t if x['name'].startswith('beaver_')]))"; }

if ! docker compose exec -T cli wp plugin is-active beaver-builder-lite-version >/dev/null 2>&1; then
  echo "Beaver Builder is not active. Activate it first:"
  echo "  docker compose exec -T cli wp plugin install beaver-builder-lite-version --activate"
  exit 2
fi

echo "-- the group is a switch --"
group_off
check "group off: no beaver_ tools registered" "$(toolcount)" "0"
group_on
check "group on: four beaver_ tools registered" "$(toolcount)" "4"

echo "-- beaver_status reports the builder's own state --"
call st "$(tc beaver_status '{}')"
check "status answers" "$(verdict st)" "ok"
check "loaded" "$(jget st builder.loaded)" "true"
check "version matches FL_BUILDER_VERSION" "$(jget st builder.version)" "$(wpc eval 'echo FL_BUILDER_VERSION;' | tr -d '\r\n')"
check "edition is the free one (FL_BUILDER_LITE is defined)" "$(jget st builder.edition)" "lite"
check "modules are listed by name" \
  "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print("yes" if len(json.loads(t).get("modules",{}).get("enabled") or []) > 0 else "no")' "" st)" "yes"

echo "-- fixtures through the builder's own writer --"
MARK="beavermark$(date +%s)"
SEED_OUT=$(docker compose exec -T cli wp eval '
wp_set_current_user( 1 );
$pid = wp_insert_post([ "post_title" => "Beaver probe '"$MARK"'", "post_type" => "page", "post_status" => "publish" ]);
$n = function( $id, $type, $parent, $pos, $settings ) { return (object) [ "node" => (string) $id, "type" => $type, "parent" => $parent, "position" => $pos, "settings" => (object) $settings ]; };
$data = [
  "1" => $n( 1, "row", null, 0, [ "bg_color" => "#ffffff" ] ),
  "2" => $n( 2, "column", "1", 0, [] ),
  "3" => $n( 3, "html", "2", 0, [ "html" => "<script>LEAKMARK-'"$MARK"'-JS</script>" ] ),
  "4" => $n( 4, "heading", "2", 1, [ "text" => "Hello" ] ),
];
FLBuilderModel::set_post_id( $pid );
FLBuilderModel::update_layout_data( $data, "published", $pid );
FLBuilderModel::update_layout_settings( [ "css" => "LEAKCSS-'"$MARK"'-VALUE", "title" => "probe" ], "published", $pid );
FLBuilderModel::enable();
echo $pid;' 2>&1)
PID=$(printf '%s' "$SEED_OUT" | tail -1)
case "$PID" in
  ''|*[!0-9]*)
    echo "Could not seed a builder page; the rest would report the tool as broken. Seed output:" >&2
    printf '%s\n' "$SEED_OUT" | tail -6 >&2
    exit 1 ;;
esac
echo "  page id: $PID"
check "CONTROL: the builder is enabled on the seeded page" \
  "$(wpc eval 'echo FLBuilderModel::is_builder_enabled( '"$PID"' ) ? "yes" : "no";')" "yes"
check "CONTROL: the layout really holds four nodes" \
  "$(wpc eval 'echo count( FLBuilderModel::get_layout_data( "published", '"$PID"' ) );')" "4"
# The marker is in the stored meta, so its absence from a reply means something.
check "CONTROL: the html module's script is in the stored layout" \
  "$(dbq "SELECT IF(meta_value LIKE '%LEAKMARK-$MARK-JS%', 'yes', 'no') FROM wp_postmeta WHERE post_id=$PID AND meta_key='_fl_builder_data'")" "yes"

echo "-- beaver_layout reports the shape, never the settings --"
call lay "$(tc beaver_layout '{"ID":'"$PID"'}')"
check "layout answers" "$(verdict lay)" "ok"
check "the builder is reported enabled" "$(jget lay builder_enabled)" "true"
check "four published nodes" "$(jget lay published.nodes)" "4"
check "row/column/html/heading are named, not counted as 'modules'" "$(jget lay published.by_type.html)" "1"
check "the tree depth is reported" "$(jget lay published.depth)" "3"
check "the html module's script is NOT returned" "$(has lay "LEAKMARK-$MARK-JS")" "no"
# The layout-level settings meta, not a node's: node settings are never returned at all.
check "layout settings are reported by name" \
  "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print("yes" if "css" in (json.loads(t).get("layout_settings",{}).get("keys") or []) else "no")' "" lay)" "yes"
check "custom CSS is reported as present" "$(jget lay layout_settings.carries_custom_code.css)" "true"
# The control for the other half: the CSS value is stored, and is not in the reply.
check "CONTROL: the layout's custom CSS is stored" \
  "$(dbq "SELECT IF(meta_value LIKE '%LEAKCSS-$MARK-VALUE%', 'yes', 'no') FROM wp_postmeta WHERE post_id=$PID AND meta_key='_fl_builder_data_settings'")" "yes"
check "the layout's custom CSS value is NOT returned" "$(has lay "LEAKCSS-$MARK-VALUE")" "no"
check "custom code is reported as present/absent, not returned" \
  "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print("yes" if "carries_custom_code" in json.loads(t).get("layout_settings",{}) else "no")' "" lay)" "yes"

echo "-- the generic meta tools cannot reach the layout --"
# The group withholds node settings; that withholding is decoration if wp_get_post_meta returns
# the same row. Both directions are refused, and an ordinary key still works as the control.
call gm_read "$(tc wp_get_post_meta '{"ID":'"$PID"',"key":"_fl_builder_data"}')"
check "reading the layout meta through the generic reader is refused" "$(verdict gm_read)" "error"
check "and the refusal names the builder" "$(has gm_read "page builder layout")" "yes"
call gm_write "$(tc wp_update_post_meta '{"ID":'"$PID"',"key":"_fl_builder_data_settings","value":{"js":"alert(1)"}}')"
check "writing the layout meta through the generic writer is refused" "$(verdict gm_write)" "error"
call gm_ok "$(tc wp_update_post_meta '{"ID":'"$PID"',"key":"probe_ordinary_key","value":"ok"}')"
check "CONTROL: an ordinary meta key still writes" "$(verdict gm_ok)" "ok"
check "CONTROL: and it is stored" \
  "$(dbq "SELECT meta_value FROM wp_postmeta WHERE post_id=$PID AND meta_key='probe_ordinary_key'")" "ok"

echo "-- the draft copy is the other half of the trap --"
# A draft that changes only a SETTING: the node count is identical, which is exactly the case a
# count-based comparison misses.
wpc eval '
wp_set_current_user( 1 );
$n = function( $id, $type, $parent, $pos, $text ) { return (object) [ "node" => (string) $id, "type" => $type, "parent" => $parent, "position" => $pos, "settings" => (object) [ "text" => $text ] ]; };
$draft = [
  "1" => $n( 1, "row", null, 0, "a" ),
  "2" => $n( 2, "column", "1", 0, "b" ),
  "3" => $n( 3, "html", "2", 0, "c" ),
  "4" => $n( 4, "heading", "2", 1, "CHANGED-IN-DRAFT" ),
];
FLBuilderModel::set_post_id( '"$PID"' );
FLBuilderModel::update_layout_data( $draft, "draft", '"$PID"' );' >/dev/null 2>&1
check "CONTROL: the draft copy holds the same four nodes" \
  "$(wpc eval 'echo count( FLBuilderModel::get_layout_data( "draft", '"$PID"' ) );')" "4"
call lay2 "$(tc beaver_layout '{"ID":'"$PID"'}')"
check "the draft is reported present" "$(jget lay2 draft_present)" "true"
check "and divergence is detected even though the node count did not change" "$(jget lay2 draft_differs)" "true"
check "the reply says visitors see the published copy" "$(has lay2 "unpublished builder changes")" "yes"
check "the changed text is NOT returned" "$(has lay2 "CHANGED-IN-DRAFT")" "no"

echo "-- a page with the builder off is a different answer --"
PLAIN=$(wpc post create --post_title="Beaver plain $MARK" --post_status=publish --porcelain | tail -1)
call lay3 "$(tc beaver_layout '{"ID":'"$PLAIN"'}')"
check "a plain page reports the builder disabled" "$(jget lay3 builder_enabled)" "false"
check "and says the front end renders ordinary content" "$(has lay3 "not enabled on this post")" "yes"

echo "-- clearing the generated assets --"
call clr "$(tc beaver_clear_cache '{"ID":'"$PID"'}')"
check "clear answers" "$(verdict clr)" "ok"
check "and reports the scope it cleared" "$(jget clr scope)" "post"
call clr_bad "$(tc beaver_clear_cache '{"ID":999999}')"
check "an unknown post is refused rather than reported as cleared" "$(jget clr_bad cleared)" "false"
RO=$(gmcp_make_key "smoke beaver readonly" readonly)
case "$RO" in
  gmcp_*)
    callas "$RO" clr_ro "$(tc beaver_clear_cache '{}')"
    check "a read key cannot clear the cache" "$(verdict clr_ro)" "error"
    callas "$RO" lay_ro "$(tc beaver_layout '{"ID":'"$PID"'}')"
    check "a read key can still read the layout shape" "$(verdict lay_ro)" "ok"
    ;;
  *) echo "  ....  read-level checks skipped: could not mint a read key" ;;
esac

echo "-- the layout is read through the same guards every other reader asks --"
# 1. A reader that lists EVERY meta key never asked about the layout key by name, and returned it.
#    The control is the stored layout (the marker is in the row) and an ordinary key in the same reply.
call gm_all "$(tc wp_get_post_meta '{"ID":'"$PID"'}')"
check "CONTROL: the generic reader of every key answers, and lists an ordinary key" "$(has gm_all "probe_ordinary_key")" "yes"
check "listing every meta key does NOT return the layout's script" "$(has gm_all "LEAKMARK-$MARK-JS")" "no"
check "and does not return the layout settings' css" "$(has gm_all "LEAKCSS-$MARK-VALUE")" "no"
check "the layout key is listed as withheld, not silently dropped" "$(has gm_all "withheld: page builder layout")" "yes"
call gm_snap "$(tc wp_get_post_snapshot '{"ID":'"$PID"'}')"
check "CONTROL: the snapshot answers with the post's ordinary meta" "$(has gm_snap "probe_ordinary_key")" "yes"
check "the post snapshot does NOT return the layout's script" "$(has gm_snap "LEAKMARK-$MARK-JS")" "no"
# 2. The key as the database compares it. Refused whatever the collation, so SQLite (which compares
#    exactly) and MariaDB (which does not) give the same answer; the stored value is read back.
BEFORE_LAYOUT=$(dbq "SELECT MD5(meta_value) FROM wp_postmeta WHERE post_id=$PID AND meta_key='_fl_builder_data'")
call gm_acc_r "$(tc wp_get_post_meta '{"ID":'"$PID"',"key":"_fl_builder_dáta"}')"
check "reading the layout under an accented spelling of its key is refused" "$(verdict gm_acc_r)" "error"
check "and the refusal names the builder, so it is the layout guard that answered" "$(has gm_acc_r "page builder layout")" "yes"
call gm_acc_w "$(tc wp_update_post_meta '{"ID":'"$PID"',"key":"_fl_builder_dáta","value":"x"}')"
check "writing the layout under an accented spelling of its key is refused" "$(verdict gm_acc_w)" "error"
call gm_sp_w "$(tc wp_update_post_meta '{"ID":'"$PID"',"key":"_fl_builder_data ","value":"x"}')"
check "and under a trailing space" "$(verdict gm_sp_w)" "error"
check "STORED: the layout row is byte-for-byte what it was" \
  "$(dbq "SELECT MD5(meta_value) FROM wp_postmeta WHERE post_id=$PID AND meta_key='_fl_builder_data'")" "$BEFORE_LAYOUT"
# 3. A form submission is personal data. beaver_layout reports a post's title, type and status, so
#    it asks the post read policy, and below admin the answer is the one a missing id gets.
SUBID=$(wpc eval 'echo wp_insert_post([ "post_title" => "Sender '"$MARK"'", "post_type" => "flamingo_inbound", "post_status" => "publish" ]);')
case "$SUBID" in ''|*[!0-9]*) echo "  FAIL  could not seed a submission"; fail=$((fail+1)); SUBID=0 ;; esac
RW=$(gmcp_make_key "smoke beaver readwrite" readwrite)
MISSING=$((SUBID + 900000))
check "CONTROL: the submission exists and is published" \
  "$(dbq "SELECT CONCAT(post_type,'/',post_status) FROM wp_posts WHERE ID=$SUBID")" "flamingo_inbound/publish"
check "CONTROL: the id used as 'missing' really does not exist" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE ID=$MISSING")" "0"
for K in "$RO" "$RW"; do
  callas "$K" lay_sub "$(tc beaver_layout '{"ID":'"$SUBID"'}')"
  callas "$K" lay_miss "$(tc beaver_layout '{"ID":'"$MISSING"'}')"
  check "a non-admin key does not get the submission's title from beaver_layout" "$(has lay_sub "Sender $MARK")" "no"
  check "and the answer is the one a missing id gets, word for word" \
    "$(py 'import json,sys,re
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","")
print(re.sub(r"\d+","N",t))' "" lay_sub)" \
    "$(py 'import json,sys,re
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","")
print(re.sub(r"\d+","N",t))' "" lay_miss)"
done
check "CONTROL: the missing-id answer is the 'no post' note, so the comparison is not of two empty strings" "$(has lay_miss "No post with id")" "yes"
check "CONTROL: the same key does read an ordinary page's title" \
  "$(callas "$RO" lay_ok "$(tc beaver_layout '{"ID":'"$PID"'}')"; has lay_ok "Beaver probe $MARK")" "yes"
call lay_sub_admin "$(tc beaver_layout '{"ID":'"$SUBID"'}')"
check "an admin key is told why, and still does not get the title" "$(has lay_sub_admin "form submission")" "yes"
check "  ...the admin refusal carries no layout" "$(has lay_sub_admin "Sender $MARK")" "no"
wpc eval 'wp_delete_post( '"$SUBID"', true );' >/dev/null 2>&1

echo "-- templates --"
call tpl "$(tc beaver_templates '{}')"
check "templates answers" "$(verdict tpl)" "ok"
if docker compose exec -T cli wp eval 'echo post_type_exists("fl-builder-template") ? "yes" : "no";' 2>/dev/null | grep -q yes; then
  TID=$(wpc eval '
  wp_set_current_user( 1 );
  $tid = wp_insert_post([ "post_title" => "Beaver tpl '"$MARK"'", "post_type" => "fl-builder-template", "post_status" => "publish" ]);
  $n = (object) [ "node" => "1", "type" => "row", "parent" => null, "position" => 0, "settings" => (object) [] ];
  FLBuilderModel::set_post_id( $tid );
  FLBuilderModel::update_layout_data( [ "1" => $n ], "published", $tid );
  echo $tid;' | tail -1)
  call tpl2 "$(tc beaver_templates '{}')"
  check "a saved template is listed" "$(has tpl2 "Beaver tpl $MARK")" "yes"
  wpc eval 'wp_delete_post( '"$TID"', true );' >/dev/null 2>&1
else
  echo "  ....  template listing not exercised: fl-builder-template is not registered here"
fi

echo "-- cleanup --"
wpc eval 'wp_delete_post( '"$PID"', true ); wp_delete_post( '"$PLAIN"', true );' >/dev/null 2>&1
check "the fixtures are gone" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE '%$MARK%'")" "0"

group_off
echo
printf 'beaver: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
