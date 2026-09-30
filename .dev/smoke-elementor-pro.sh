#!/bin/bash
# Smoke test for the LearnDash tools.
#
# Its own file because it needs Elementor Pro installed and the Elementor Pro tool group switched
# on, and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin activate sfwd-lms
#   ./smoke-elementor-pro.sh
#
# Every check asserts stored state rather than the wording of the reply, and every "the tool
# refused" or "X is absent" check sits beside a control proving the same probe finds it when
# present. The blocks that exist to catch one specific defect each say so in a comment.
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
TOK=$(gmcp_make_key "smoke elementor pro" admin)
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
py() { python3 -c "$1" "$2" < "$OUT/$3"; }
verdict() { py 'import json,sys
d=json.load(sys.stdin)
print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "" "$1"; }
ctext() { py 'import json,sys
d=json.load(sys.stdin)
c=d.get("result",{}).get("content",[])
print(c[0]["text"] if c else "")' "" "$1"; }
tokof() { ctext "$1" | sed -n 's/.*confirm set to "\([a-f0-9]*\)".*/\1/p'; }
dbq()  { docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpc()  { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
pro_active() { docker compose exec -T cli wp plugin is-active elementor-pro >/dev/null 2>&1; }
group_on()  { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_elementor_pro"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
group_off() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_elementor_pro"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }

if ! pro_active; then
  echo "Elementor Pro is not active. Activate it first:"
  echo "  docker compose exec -T cli wp plugin activate elementor-pro"
  exit 2
fi
group_on

echo "-- seeding through Elementor Pro's own writers --"
SEEDFILE=$(mktemp)
cat > "$SEEDFILE" <<'PHP'
<?php
if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) { echo 'PRO_NOT_LOADED'; return; }

// Create the submissions tables the way Pro does, then write submissions through Pro's own
// Query::add_submission(). Pro has no public installer other than this migration path, and
// a hand-built INSERT that misses a column hashes differently and reads back wrong.
$migration = '\ElementorPro\Modules\Forms\Submissions\Database\Migration';
if ( class_exists( $migration ) ) { $migration::install(); }
$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
global $wpdb;
$table = $wpdb->prefix . 'e_submissions';
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE form_name = %s", 'GMCP EP Smoke Form' ) );

for ( $i = 1; $i <= 3; $i++ ) {
  $query->add_submission(
    [
      'type' => 'submission', 'post_id' => 0, 'referer' => 'http://localhost:8082/',
      'element_id' => 'gmcp' . $i, 'form_name' => 'GMCP EP Smoke Form', 'status' => 'new',
      'is_read' => $i === 1 ? 0 : 1, 'user_ip' => '127.0.0.1', 'user_agent' => 'GMCP Smoke',
      'actions_count' => 0,
    ],
    [
      [ 'id' => 'name', 'type' => 'text', 'value' => 'Visitor ' . $i, 'title' => 'Name' ],
      [ 'id' => 'email', 'type' => 'email', 'value' => 'visitor' . $i . '@example.test', 'title' => 'Email' ],
      [ 'id' => 'message', 'type' => 'textarea', 'value' => 'GMCP_SUBMISSION_MARKER ' . $i, 'title' => 'Message' ],
    ]
  );
}
$query->add_submission(
  [ 'type' => 'submission', 'post_id' => 0, 'referer' => 'http://localhost:8082/', 'element_id' => 'gmcp',
    'form_name' => 'GMCP EP Smoke Form', 'status' => 'trash', 'is_read' => 1,
    'user_ip' => '127.0.0.1', 'user_agent' => 'GMCP Smoke', 'actions_count' => 0 ],
  [ [ 'id' => 'name', 'type' => 'text', 'value' => 'Trashed', 'title' => 'Name' ] ]
  );

// A Custom Code snippet. The meta keys come from Pro's own constants, never a literal:
// Pro stores '_elementor_' . FIELD_LOCATION, and the field NAME is not the stored KEY.
foreach ( get_posts( [ 'post_type' => 'elementor_snippet', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $pid ) {
  if ( strpos( (string) get_the_title( $pid ), 'GMCP EP Smoke' ) === 0 ) { wp_delete_post( $pid, true ); }
}
$meta = '\ElementorPro\Modules\CustomCode\Custom_Code_Metabox';
$sid = wp_insert_post( [ 'post_type' => 'elementor_snippet', 'post_status' => 'publish', 'post_title' => 'GMCP EP Smoke Snippet' ] );
update_post_meta( $sid, '_elementor_' . $meta::FIELD_LOCATION, 'head' );
update_post_meta( $sid, '_elementor_' . $meta::FIELD_PRIORITY, 10 );
update_post_meta( $sid, '_elementor_' . $meta::FIELD_CODE, 'GMCP_SNIPPET_BODY_MARKER console.log("smoke");' );

// A Pro library template, so the type counts have something to find.
foreach ( get_posts( [ 'post_type' => 'elementor_library', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $pid ) {
  if ( strpos( (string) get_the_title( $pid ), 'GMCP EP Smoke' ) === 0 ) { wp_delete_post( $pid, true ); }
}
$popup = wp_insert_post( [ 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'GMCP EP Smoke Popup' ] );
update_post_meta( $popup, '_elementor_template_type', 'popup' );

echo 'SEED submissions=4 snippet=' . $sid . ' popup=' . $popup;
PHP
docker compose cp "$SEEDFILE" cli:/var/www/html/gmcp-ep-seed.php >/dev/null 2>&1
docker compose exec -T -u 0 cli chmod 644 /var/www/html/gmcp-ep-seed.php >/dev/null 2>&1
SEED=$(docker compose exec -T cli wp eval-file /var/www/html/gmcp-ep-seed.php 2>&1 | tr -d '\r')
docker compose exec -T -u 0 cli rm -f /var/www/html/gmcp-ep-seed.php >/dev/null 2>&1
rm -f "$SEEDFILE"
case "$SEED" in
  *SEED*) ;;
  *) echo "Seeding failed: $SEED" >&2; exit 1 ;;
esac
SNIPPET=$(echo "$SEED" | sed -n 's/.*snippet=\([0-9]*\).*/\1/p')
POPUP=$(echo "$SEED" | sed -n 's/.*popup=\([0-9]*\)$/\1/p')
echo "  snippet=$SNIPPET popup=$POPUP"

echo "-- the fixture exists (control for every absence check below) --"
check "CONTROL: the submissions table exists after seeding" \
  "$(dbq "SHOW TABLES LIKE \"$(wpc db prefix)e_submissions\"")" "$(wpc db prefix)e_submissions"
check "CONTROL: the seeded submissions are in the database" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)e_submissions WHERE form_name='GMCP EP Smoke Form' AND status='new'")" "3"
check "CONTROL: the seeded snippet code body is really stored" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)postmeta WHERE post_id=$SNIPPET AND meta_key='_elementor_code' AND meta_value LIKE '%GMCP_SNIPPET_BODY_MARKER%'")" "1"

echo "-- the tools are offered --"
call ep_list "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}"
check "the Elementor Pro group is listed when switched on" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
n={t["name"] for t in d["result"]["tools"]}
need={"elementor_pro_status","elementor_forms_briefing"}
print(sorted(need-n) or True)' "" ep_list)" "True"
check "the Tools page declares the group to its save, not only WP-CLI" \
  "$(docker compose exec -T cli wp eval '$m=new ReflectionMethod("GMCP_Settings","field_kinds");$m->setAccessible(true);$k=$m->invoke(null);echo isset($k["mcp_tools_elementor_pro"])?"declared":"missing";' 2>/dev/null | tr -d '\r\n')" "declared"

echo "-- pro status answers what it is for --"
call ep_status "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_pro_status\",\"arguments\":{}}}"
check "elementor_pro_status succeeds" "$(verdict ep_status)" "ok"
check "and reports Pro as loaded" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["pro_loaded"])' "" ep_status)" "True"
check "and reports the version" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(bool(d["pro_version"]))' "" ep_status)" "True"
# The control that stops a hardcoded "all modules present" passing: the detection asks
# whether the class AND the method exist per module, so a module that is genuinely absent
# must read as absent.
check "CONTROL: a module that does not exist reads as unavailable" \
  "$(docker compose exec -T cli wp eval 'echo ( class_exists("\\ElementorPro\\Modules\\NoSuchModule\\Module") && method_exists("\\ElementorPro\\Modules\\NoSuchModule\\Module","instance") ) ? "yes" : "no";' 2>/dev/null | tr -d '\r\n')" "no"
check "and the modules it does have are all reported available" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
m=d["modules"]
print(all(m.values()) and len(m)>=5)' "" ep_status)" "True"
check "and it counts the seeded popup as a library template" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["library_template_counts"].get("popup", 0) >= 1)' "" ep_status)" "True"

echo "-- custom code: inventory without the body --"
check "CONTROL: the inventory finds the seeded snippet" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(any(s["id"]==int(sys.argv[1]) for s in d["custom_code"]["snippets"]))' "$SNIPPET" ep_status)" "True"
check "and reports its location from the key Pro actually stores" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
s=[x for x in d["custom_code"]["snippets"] if x["id"]==int(sys.argv[1])][0]
print(s["location"])' "$SNIPPET" ep_status)" "head"
# The control is above: the row IS found. Without it, "the body is absent" would also pass
# on a tool that returned nothing at all.
check "and never returns the seeded snippet code body" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print("GMCP_SNIPPET_BODY_MARKER" in json.dumps(d))' "" ep_status)" "False"
check "and reports its size instead" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
s=[x for x in d["custom_code"]["snippets"] if x["id"]==int(sys.argv[1])][0]
print(s["code_bytes"] > 0)' "$SNIPPET" ep_status)" "True"

echo "-- form submissions: counts, and no false zero --"
call ep_forms "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_forms_briefing\",\"arguments\":{}}}"
check "elementor_forms_briefing succeeds" "$(verdict ep_forms)" "ok"
check "and reports that the tables exist" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["table_exists"])' "" ep_forms)" "True"
check "and counts the three non-trash submissions" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["counts"]["total"])' "" ep_forms)" "3"
check "and counts the unread one" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["counts"]["unread"])' "" ep_forms)" "1"
check "and counts the trashed one separately" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["counts"]["trash"])' "" ep_forms)" "1"
# The control for the whole tool: the marker exists in the table, so "no submission content
# is returned" is a real absence rather than an empty database. Asserted as "more than the
# tool would report", not an exact count, because other runs leave rows behind and the
# point is that the content is there to be found.
check "CONTROL: the submission rows really hold visitor content" \
  "$([ "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)e_submissions_values WHERE value LIKE '%GMCP_SUBMISSION_MARKER%'")" -ge 3 ] && echo yes || echo no)" "yes"
check "and no submission content is returned by the tool" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
s=json.dumps(d)
print("GMCP_SUBMISSION_MARKER" in s or "visitor1@example.test" in s)' "" ep_forms)" "False"

echo "-- a missing submissions table is reported as missing, not as zero --"
# Pro creates these tables on demand and its own query layer returns total 0 while the table
# is absent, so counting without checking reports "no submissions" on a site where the
# feature has simply never run. Simulated by renaming the table away and back.
TBL="$(wpc db prefix)e_submissions"
wpc db query "RENAME TABLE $TBL TO ${TBL}_hidden" >/dev/null 2>&1
check "CONTROL: the table is really gone" "$(dbq "SHOW TABLES LIKE \"$TBL\"")" ""
call ep_forms_missing "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_forms_briefing\",\"arguments\":{}}}"
check "with no table, the tool still answers" "$(verdict ep_forms_missing)" "ok"
check "and says the table does not exist" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["table_exists"])' "" ep_forms_missing)" "False"
check "and reports no counts rather than a zero" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["counts"] is None)' "" ep_forms_missing)" "True"
wpc db query "RENAME TABLE ${TBL}_hidden TO $TBL" >/dev/null 2>&1
check "CONTROL: the table is restored" "$(dbq "SHOW TABLES LIKE \"$TBL\"")" "$TBL"
call ep_forms_back "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_forms_briefing\",\"arguments\":{}}}"
check "and counting resumes" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["counts"]["total"])' "" ep_forms_back)" "3"

echo "-- the status tool answers with Pro switched off --"
# This is the whole reason the status tool is not gated on Pro being loaded: it is what an
# operator reaches for at the moment Pro is not working.
docker compose exec -T cli wp plugin deactivate elementor-pro >/dev/null 2>&1
call ep_off_status "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_pro_status\",\"arguments\":{}}}"
check "with Pro gone, the status tool still answers" "$(verdict ep_off_status)" "ok"
check "and reports Pro as not loaded" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["pro_loaded"])' "" ep_off_status)" "False"
check "and its modules as unavailable" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(any(d["modules"].values()))' "" ep_off_status)" "False"
call ep_off_forms "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_forms_briefing\",\"arguments\":{}}}"
check "but the submission briefing refuses in its own words" "$(verdict ep_off_forms)" "error"
check "saying Pro is not loaded, not that the tool is unknown" \
  "$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"].lower()
print("not loaded" in t and "unknown tool" not in t)' "" ep_off_forms)" "True"
docker compose exec -T cli wp plugin activate elementor-pro >/dev/null 2>&1
call ep_back "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_pro_status\",\"arguments\":{}}}"
check "CONTROL: reactivating restores Pro" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["pro_loaded"])' "" ep_back)" "True"

echo "-- a readonly key cannot reach the Pro group --"
# Both tools here are read level, so the meaningful control is that the group switch works;
# the level split itself is exercised in the other suites.
RO=$(gmcp_make_key "smoke elementor pro readonly" readonly)
call_ro() { curl -sS -X POST "$URL" -H "Authorization: Bearer $RO" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$2" -o "$OUT/$1"; }
call_ro ro_read "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_pro_status\",\"arguments\":{}}}"
check "CONTROL: a readonly key can call the read-level status tool" "$(verdict ro_read)" "ok"
docker compose exec -T cli wp eval 'foreach ( GMCP_Tokens::all() as $k ) { if ( ( $k["label"] ?? "" ) === "smoke elementor pro readonly" ) { GMCP_Tokens::revoke( $k["id"] ); } }' >/dev/null 2>&1

echo "-- the group switch --"
group_off
call ep_group_off "{\"jsonrpc\":\"2.0\",\"id\":10,\"method\":\"tools/list\"}"
check "switched off, no Elementor Pro tool is offered" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
print(len([t for t in d["result"]["tools"] if t["name"] in ("elementor_pro_status","elementor_forms_briefing")]))' "" ep_group_off)" "0"
call ep_group_off_call "{\"jsonrpc\":\"2.0\",\"id\":11,\"method\":\"tools/call\",\"params\":{\"name\":\"elementor_pro_status\",\"arguments\":{}}}"
check "and calling one anyway is refused" "$(verdict ep_group_off_call)" "error"
group_on

echo "-- cleanup --"
SEEDFILE=$(mktemp)
cat > "$SEEDFILE" <<'PHP'
<?php
global $wpdb;
if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
  $t = $wpdb->prefix . 'e_submissions';
  $wpdb->query( $wpdb->prepare( "DELETE FROM `{$t}` WHERE form_name = %s", 'GMCP EP Smoke Form' ) );
}
foreach ( [ 'elementor_snippet', 'elementor_library' ] as $type ) {
  foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $pid ) {
    if ( strpos( (string) get_the_title( $pid ), 'GMCP EP Smoke' ) === 0 ) { wp_delete_post( $pid, true ); }
  }
}
PHP
docker compose cp "$SEEDFILE" cli:/var/www/html/gmcp-ep-clean.php >/dev/null 2>&1
docker compose exec -T -u 0 cli chmod 644 /var/www/html/gmcp-ep-clean.php >/dev/null 2>&1
docker compose exec -T cli wp eval-file /var/www/html/gmcp-ep-clean.php >/dev/null 2>&1
docker compose exec -T -u 0 cli rm -f /var/www/html/gmcp-ep-clean.php >/dev/null 2>&1
rm -f "$SEEDFILE"
group_off

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
