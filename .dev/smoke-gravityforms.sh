#!/bin/bash
# Smoke test for the Gravity Forms tools.
#
# Its own file because it needs Gravity Forms installed and the Gravity Forms tool group
# switched on, and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin activate gravityforms
#   ./smoke-gravityforms.sh
#
# Every check asserts the stored state rather than the wording of the reply, and every
# "the tool refused" check sits beside a control proving the same call succeeds when it
# should: a refusal is indistinguishable from a completely broken tool otherwise. The
# blocks below that exist to catch one specific defect each say so in a comment.
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
TOK=$(gmcp_make_key "smoke gravityforms" admin)
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
ctext() { py 'import json,sys;d=json.load(sys.stdin);c=d.get("result",{}).get("content",[]);print(c[0]["text"] if c else "")' "$1"; }
tokof() { ctext "$1" | sed -n 's/.*confirm set to "\([a-f0-9]*\)".*/\1/p'; }
dbq()  { docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpc()  { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
group_on()  { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_gravityforms"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
group_off() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_gravityforms"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }

if ! docker compose exec -T cli wp plugin is-active gravityforms >/dev/null 2>&1; then
  echo "Gravity Forms is not active. Activate it first:"
  echo "  docker compose exec -T cli wp plugin activate gravityforms"
  exit 2
fi
group_on

echo "-- seeding through Gravity Forms' own writers --"
# Never hand-built INSERTs: a row seeded straight into wp_gf_entry_meta that omits a column
# Gravity Forms expects reads back as something else and the failures below describe a code
# fault that is really a seeding fault. This form deliberately carries a password, an upload
# and a consent field, so the refusals further down have something real to refuse.
SEED=$(docker compose exec -T cli wp eval-file - <<'PHP'
<?php
foreach ( [ true, false ] as $active ) {
  foreach ( GFAPI::get_forms( $active, false ) as $f ) {
    if ( strpos( (string) $f['title'], 'GMCP GF Smoke' ) === 0 ) { GFAPI::delete_form( $f['id'] ); }
  }
}
$fields = [
  [ 'id' => 1, 'type' => 'text',       'label' => 'Full Name', 'isRequired' => true ],
  [ 'id' => 2, 'type' => 'email',      'label' => 'Email Address' ],
  [ 'id' => 3, 'type' => 'textarea',   'label' => 'Message' ],
  [ 'id' => 4, 'type' => 'checkbox',   'label' => 'Topics', 'choices' => [
      [ 'text' => 'Billing', 'value' => 'Billing' ], [ 'text' => 'Support', 'value' => 'Support' ],
    ], 'inputs' => [ [ 'id' => '4.1', 'label' => 'Billing' ], [ 'id' => '4.2', 'label' => 'Support' ] ] ],
  [ 'id' => 5, 'type' => 'name',       'label' => 'Contact Name', 'inputs' => [
      [ 'id' => '5.3', 'label' => 'First' ], [ 'id' => '5.6', 'label' => 'Last' ] ] ],
  [ 'id' => 6, 'type' => 'password',   'label' => 'Secret' ],
  [ 'id' => 7, 'type' => 'fileupload', 'label' => 'Attachment' ],
  [ 'id' => 8, 'type' => 'consent',    'label' => 'Consent', 'checkboxLabel' => 'I agree' ],
];
$form_id = GFAPI::add_form( [ 'title' => 'GMCP GF Smoke Form', 'description' => 'Seeded by smoke-gravityforms.sh.', 'fields' => $fields ] );
if ( is_wp_error( $form_id ) ) { echo 'SEED_ERROR=' . $form_id->get_error_message(); return; }
$ids = [];
foreach ( [ 'Alpha', 'Beta', 'Gamma' ] as $who ) {
  $eid = GFAPI::add_entry( [ 'form_id' => $form_id, '1' => $who . ' Person', '2' => strtolower( $who ) . '@example.test', '3' => 'Message body for ' . $who, 'status' => 'active' ] );
  if ( is_wp_error( $eid ) ) { echo 'SEED_ERROR=' . $eid->get_error_message(); return; }
  GFAPI::update_entry_field( $eid, '4.1', 'Billing' );
  GFAPI::update_entry_field( $eid, '5.3', $who );
  GFAPI::update_entry_field( $eid, '5.6', 'Tester' );
  $ids[] = $eid;
}
// A stored password value, so the read exclusion has something to exclude and the control
// can prove the value is really in the database.
GFAPI::update_entry_field( $ids[0], '6', 'sup3rsecret' );
GFAPI::add_note( $ids[0], 0, 'Seed', 'Seeded note.' );
echo 'SEED form=' . $form_id . ' a=' . $ids[0] . ' b=' . $ids[1] . ' c=' . $ids[2];
PHP
) 
case "$SEED" in
  *form=*) ;;
  *) echo "Seeding failed: $SEED" >&2; exit 1 ;;
esac
FORM=$( echo "$SEED" | sed -n 's/.*form=\([0-9]*\).*/\1/p' )
A=$( echo "$SEED" | sed -n 's/.* a=\([0-9]*\).*/\1/p' )
B=$( echo "$SEED" | sed -n 's/.* b=\([0-9]*\).*/\1/p' )
C=$( echo "$SEED" | sed -n 's/.* c=\([0-9]*\).*/\1/p' )
echo "  form=$FORM entries=$A,$B,$C"

echo "-- the fixture exists (control for every absence check below) --"
# Without this, every "the tool refused" and "the value is absent" check further down would
# also pass against a suite that simply failed to seed anything.
check "the seeded entry reads back through Gravity Forms" \
  "$(dbq "SELECT meta_value FROM wp_gf_entry_meta WHERE entry_id=$A AND meta_key='1'")" "Alpha Person"
check "the seeded password value is really stored (control for the read-exclusion check)" \
  "$(dbq "SELECT meta_value FROM wp_gf_entry_meta WHERE entry_id=$A AND meta_key='6'")" "sup3rsecret"

echo "-- the tools are offered --"
call gf_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "the Gravity Forms group is listed when switched on" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};need={"gf_list_forms","gf_get_form","gf_list_entries","gf_get_entry","gf_update_entry","gf_update_entry_field","gf_delete_entry","gf_set_form_active"};print(sorted(need-n) or True)' gf_list)" "True"
# The switch a person actually uses is the Tools page, and a group missing from field_kinds()
# cannot be saved there at all: the checkbox writes nothing. That would leave the group
# reachable only from WP-CLI, which is the shape the smoke-admin "147 failures" incident
# took. This asserts the declaration rather than the rendered markup.
check "the Tools page declares the group to its save, not only WP-CLI" \
  "$(docker compose exec -T cli wp eval '$m=new ReflectionMethod("GMCP_Settings","field_kinds");$m->setAccessible(true);$k=$m->invoke(null);echo isset($k["mcp_tools_gravityforms"])?"declared":"missing";' 2>/dev/null | tr -d '\r\n')" "declared"

echo "-- reading forms --"
call gf_forms "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_forms\",\"arguments\":{}}}"
check "gf_list_forms succeeds" "$(verdict gf_forms)" "ok"
check "and lists the seeded form with its entry count" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f=[x for x in d["forms"] if x["id"]=='"$FORM"'];print(f[0]["entry_count"] if f else "MISSING")' gf_forms)" "3"
call gf_form "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_get_form\",\"arguments\":{\"form_id\":$FORM}}}"
check "gf_get_form succeeds" "$(verdict gf_form)" "ok"
check "and returns the checkbox sub-inputs" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);f=[x for x in d["fields"] if x["id"]==4][0];print(sorted(i["id"] for i in f.get("inputs",[])))' gf_form)" "['4.1', '4.2']"
check "and reduces notifications/confirmations to counts, not bodies" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("notifications" in d or "confirmations" in d)' gf_form)" "False"
call gf_brief '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"gf_forms_briefing","arguments":{}}}'
check "gf_forms_briefing succeeds" "$(verdict gf_brief)" "ok"

echo "-- reading entries --"
call gf_entries "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM}}}"
check "gf_list_entries succeeds" "$(verdict gf_entries)" "ok"
check "and returns three entries with a preview" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(len(d["entries"])>=3 and all("preview" in e for e in d["entries"]))' gf_entries)" "True"
call gf_entry "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_get_entry\",\"arguments\":{\"entry_id\":$A}}}"
check "gf_get_entry succeeds" "$(verdict gf_entry)" "ok"
check "and maps a value to its field label" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(next((f["value"] for f in d["fields"] if f["input_id"]=="1"),"MISSING"))' gf_entry)" "Alpha Person"
check "and does not return the password value" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("sup3rsecret" in json.dumps(d))' gf_entry)" "False"
check "and marks the password field as not returned" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(next((f["value"] for f in d["fields"] if f["input_id"]=="6"),"MISSING"))' gf_entry)" "[not returned: password field]"
check "and omits tracking by default" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("tracking" in d)' gf_entry)" "False"
call gf_entry_track "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_get_entry\",\"arguments\":{\"entry_id\":$A,\"include_tracking\":true}}}"
check "and includes tracking when asked (control for the default)" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("ip" in d.get("tracking",{}))' gf_entry_track)" "True"

echo "-- entry filters validate rather than over-report --"
call gf_search "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"search\":\"Gamma\"}}}"
check "a free-text search narrows to one entry" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(len(d["entries"]))' gf_search)" "1"
call gf_filter "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"field_filter\":{\"key\":\"1\",\"operator\":\"contains\",\"value\":\"Beta\"}}}}"
check "a field filter narrows to one entry" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(len(d["entries"]))' gf_filter)" "1"
# Gravity Forms silently treats an operator it does not know as "match everything", so an
# unvalidated operator turns a narrow filter into a full listing. This is the guard against
# that, and the control above proves the narrow filter works when the operator is real.
call gf_badop "{\"jsonrpc\":\"2.0\",\"id\":10,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"field_filter\":{\"key\":\"1\",\"operator\":\"zzz\",\"value\":\"Beta\"}}}}"
check "an unknown operator is refused, not passed through" "$(verdict gf_badop)" "error"
call gf_baddate "{\"jsonrpc\":\"2.0\",\"id\":11,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"date_from\":\"last tuesday\"}}}"
check "a malformed date is refused" "$(verdict gf_baddate)" "error"
call gf_badfield "{\"jsonrpc\":\"2.0\",\"id\":12,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"field_filter\":{\"key\":\"99\",\"value\":\"x\"}}}}"
check "a field filter on an input that does not exist is refused" "$(verdict gf_badfield)" "error"

echo "-- search and filters cannot read a value the group refuses to return --"
# Gravity Forms' default search operator is an exact match, so a word from inside an answer
# found nothing; and its "any field" variant compares against every meta value, including a
# password field's, which with the count in the reply is a boolean oracle. These checks pin
# both down: the substring control proves the search really looks inside answers, and the
# password checks prove it cannot see the one value the group hides.
check "CONTROL: the password value really is stored in the database" \
  "$(dbq "SELECT meta_value FROM wp_gf_entry_meta WHERE entry_id=$A AND meta_key='6'")" "sup3rsecret"
call gf_sub "{\"jsonrpc\":\"2.0\",\"id\":100,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"search\":\"essage body\"}}}"
check "search matches a substring inside an answer" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(len(d["entries"]))' gf_sub)" "3"
call gf_search_nof '{"jsonrpc":"2.0","id":101,"method":"tools/call","params":{"name":"gf_list_entries","arguments":{"search":"Gamma"}}}'
check "search without a form_id is refused" "$(verdict gf_search_nof)" "error"
call gf_search_pw "{\"jsonrpc\":\"2.0\",\"id\":102,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"search\":\"sup3rsecret\"}}}"
check "search cannot reach the password value" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(len(d["entries"]))' gf_search_pw)" "0"
call gf_filter_pw "{\"jsonrpc\":\"2.0\",\"id\":103,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"field_filter\":{\"key\":\"6\",\"operator\":\"contains\",\"value\":\"sup3r\"}}}}"
check "a field filter on a password input is refused" "$(verdict gf_filter_pw)" "error"
call gf_list_badstatus "{\"jsonrpc\":\"2.0\",\"id\":104,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"status\":\"banana\"}}}"
check "an unknown status is refused, not defaulted" "$(verdict gf_list_badstatus)" "error"
call gf_caldate "{\"jsonrpc\":\"2.0\",\"id\":105,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"date_from\":\"2024-02-30\"}}}"
check "a calendar-invalid date is refused" "$(verdict gf_caldate)" "error"
call gf_list_badpage "{\"jsonrpc\":\"2.0\",\"id\":106,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM,\"page\":99999999}}}"
check "an absurd page is refused" "$(verdict gf_list_badpage)" "error"

echo "-- gf_update_entry writes status and flags, verified in the database --"
call gf_spam "{\"jsonrpc\":\"2.0\",\"id\":13,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry\",\"arguments\":{\"entry_id\":$B,\"status\":\"spam\"}}}"
check "marking an entry spam succeeds" "$(verdict gf_spam)" "ok"
check "and the database says spam" "$(dbq "SELECT status FROM wp_gf_entry WHERE id=$B")" "spam"
call gf_star "{\"jsonrpc\":\"2.0\",\"id\":14,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry\",\"arguments\":{\"entry_id\":$B,\"is_starred\":true}}}"
check "starring an entry succeeds" "$(verdict gf_star)" "ok"
check "and the database says starred" "$(dbq "SELECT is_starred FROM wp_gf_entry WHERE id=$B")" "1"
call gf_badstatus "{\"jsonrpc\":\"2.0\",\"id\":15,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry\",\"arguments\":{\"entry_id\":$B,\"status\":\"banana\"}}}"
check "an unknown status is refused" "$(verdict gf_badstatus)" "error"
call gf_restore "{\"jsonrpc\":\"2.0\",\"id\":16,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry\",\"arguments\":{\"entry_id\":$B,\"status\":\"active\",\"is_starred\":false}}}"
check "restoring the entry succeeds" "$(verdict gf_restore)" "ok"
check "and the database is back to active/unstarred" "$(dbq "SELECT CONCAT(status,'/',is_starred) FROM wp_gf_entry WHERE id=$B")" "active/0"

echo "-- gf_update_entry_field is destructive and takes two steps --"
# The value before, so the "nothing changed" control below has something to compare.
BEFORE=$(dbq "SELECT meta_value FROM wp_gf_entry_meta WHERE entry_id=$A AND meta_key='3'")
call gf_field1 "{\"jsonrpc\":\"2.0\",\"id\":17,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry_field\",\"arguments\":{\"entry_id\":$A,\"input_id\":\"3\",\"value\":\"edited by suite\"}}}"
check "the first call is refused with a token (it changes nothing)" "$(verdict gf_field1)" "error"
check "CONTROL: the value is UNCHANGED after the first call" "$(dbq "SELECT meta_value FROM wp_gf_entry_meta WHERE entry_id=$A AND meta_key='3'")" "$BEFORE"
TOKEN=$(tokof gf_field1)
check "CONTROL: a token was actually supplied" "$([ -n "$TOKEN" ] && echo yes || echo no)" "yes"
call gf_field2 "{\"jsonrpc\":\"2.0\",\"id\":18,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry_field\",\"arguments\":{\"entry_id\":$A,\"input_id\":\"3\",\"value\":\"edited by suite\",\"confirm\":\"$TOKEN\"}}}"
check "the second call with the token succeeds" "$(verdict gf_field2)" "ok"
check "and the new value is stored" "$(dbq "SELECT meta_value FROM wp_gf_entry_meta WHERE entry_id=$A AND meta_key='3'")" "edited by suite"
# Verified through a request of its own, not the response of the write: an in-request cache
# could echo the value that was written without it reaching the database.
call gf_field3 "{\"jsonrpc\":\"2.0\",\"id\":19,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_get_entry\",\"arguments\":{\"entry_id\":$A}}}"
check "and a separate request reads the new value back" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(next((f["value"] for f in d["fields"] if f["input_id"]=="3"),"MISSING"))' gf_field3)" "edited by suite"
call gf_fieldnoop "{\"jsonrpc\":\"2.0\",\"id\":20,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry_field\",\"arguments\":{\"entry_id\":$A,\"input_id\":\"3\",\"value\":\"edited by suite\"}}}"
check "writing the value it already holds is a no-op, not a second confirmation" "$(verdict gf_fieldnoop)" "ok"
wpc eval 'GFAPI::update_entry_field('"$A"',"3","Message body for Alpha");' >/dev/null 2>&1
check "the value is restored for the checks that follow" "$(dbq "SELECT meta_value FROM wp_gf_entry_meta WHERE entry_id=$A AND meta_key='3'")" "Message body for Alpha"

echo "-- a token is bound to its target --"
call gf_bind1 "{\"jsonrpc\":\"2.0\",\"id\":21,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry_field\",\"arguments\":{\"entry_id\":$C,\"input_id\":\"3\",\"value\":\"bound value\"}}}"
TOKEN2=$(tokof gf_bind1)
call gf_bind2 "{\"jsonrpc\":\"2.0\",\"id\":22,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry_field\",\"arguments\":{\"entry_id\":$B,\"input_id\":\"3\",\"value\":\"bound value\",\"confirm\":\"$TOKEN2\"}}}"
check "a token minted for one entry cannot be used on another" "$(verdict gf_bind2)" "error"
check "and the other entry is unchanged" "$(dbq "SELECT meta_value FROM wp_gf_entry_meta WHERE entry_id=$B AND meta_key='3'")" "Message body for Beta"

echo "-- the write tool refuses field types it must not touch --"
for probe in "4:x" "6:x" "7:x" "8:x" "99:x"; do
  id="${probe%%:*}"
  call "gf_refuse_$id" "{\"jsonrpc\":\"2.0\",\"id\":30,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry_field\",\"arguments\":{\"entry_id\":$A,\"input_id\":\"$id\",\"value\":\"x\"}}}"
  check "input $id is refused" "$(verdict "gf_refuse_$id")" "error"
done
# The control for the whole block: a plain field on the same entry is accepted (it returns a
# confirmation token, which is an error result, so we assert the MESSAGE, not the verdict).
# The value must differ from the one stored, or the no-op path answers and mints no token.
call gf_allow "{\"jsonrpc\":\"2.0\",\"id\":31,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry_field\",\"arguments\":{\"entry_id\":$A,\"input_id\":\"5.3\",\"value\":\"Alphonse\"}}}"
check "CONTROL: a plain name sub-input is accepted (a token is minted)" \
  "$([ -n "$(tokof gf_allow)" ] && echo yes || echo no)" "yes"

echo "-- deleting an entry is two steps and verifies --"
call gf_del1 "{\"jsonrpc\":\"2.0\",\"id\":40,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_delete_entry\",\"arguments\":{\"entry_id\":$C}}}"
check "the first delete call is refused with a token" "$(verdict gf_del1)" "error"
check "CONTROL: the entry still exists after the first call" "$(dbq "SELECT COUNT(*) FROM wp_gf_entry WHERE id=$C")" "1"
DTOK=$(tokof gf_del1)
call gf_del2 "{\"jsonrpc\":\"2.0\",\"id\":41,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_delete_entry\",\"arguments\":{\"entry_id\":$C,\"confirm\":\"$DTOK\"}}}"
check "the second delete call succeeds" "$(verdict gf_del2)" "ok"
check "and the entry is gone from the database" "$(dbq "SELECT COUNT(*) FROM wp_gf_entry WHERE id=$C")" "0"
check "and its answers are gone too" "$(dbq "SELECT COUNT(*) FROM wp_gf_entry_meta WHERE entry_id=$C")" "0"
call gf_del_missing "{\"jsonrpc\":\"2.0\",\"id\":42,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_delete_entry\",\"arguments\":{\"entry_id\":99999999}}}"
check "deleting an entry that does not exist is refused" "$(verdict gf_del_missing)" "error"

echo "-- notes --"
call gf_note "{\"jsonrpc\":\"2.0\",\"id\":50,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_add_entry_note\",\"arguments\":{\"entry_id\":$A,\"note\":\"Suite note <b>one</b>\"}}}"
check "adding a note succeeds" "$(verdict gf_note)" "ok"
check "and the note is stored, sanitized" "$(dbq "SELECT COUNT(*) FROM wp_gf_entry_notes WHERE entry_id=$A AND value LIKE '%Suite note%'")" "1"
call gf_notes "{\"jsonrpc\":\"2.0\",\"id\":51,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entry_notes\",\"arguments\":{\"entry_id\":$A}}}"
check "listing notes succeeds" "$(verdict gf_notes)" "ok"
check "and includes the note just added" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(any("Suite note" in n["value"] for n in d["notes"]))' gf_notes)" "True"
call gf_no_notes "{\"jsonrpc\":\"2.0\",\"id\":52,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entry_notes\",\"arguments\":{\"entry_id\":$B}}}"
check "an entry with no notes reports that rather than erroring" "$(verdict gf_no_notes)" "ok"

echo "-- activating and deactivating a form --"
call gf_form_off "{\"jsonrpc\":\"2.0\",\"id\":60,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_set_form_active\",\"arguments\":{\"form_id\":$FORM,\"active\":false}}}"
check "switching a form off succeeds" "$(verdict gf_form_off)" "ok"
check "and the form is inactive in the database" "$(dbq "SELECT is_active FROM wp_gf_form WHERE id=$FORM")" "0"
call gf_form_on "{\"jsonrpc\":\"2.0\",\"id\":61,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_set_form_active\",\"arguments\":{\"form_id\":$FORM,\"active\":true}}}"
check "switching it back on succeeds" "$(verdict gf_form_on)" "ok"
check "and the form is active again in the database" "$(dbq "SELECT is_active FROM wp_gf_form WHERE id=$FORM")" "1"

echo "-- a readonly key cannot reach submissions --"
# The control is the read tool on the same key: a refusal here is only meaningful if the
# key works at all.
RO=$(gmcp_make_key "smoke gravityforms readonly" readonly)
call_ro() { curl -sS -X POST "$URL" -H "Authorization: Bearer $RO" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$2" -o "$OUT/$1"; }
call_ro ro_read "{\"jsonrpc\":\"2.0\",\"id\":70,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_forms\",\"arguments\":{}}}"
check "CONTROL: a readonly key can call the read-level form list" "$(verdict ro_read)" "ok"
call_ro ro_write "{\"jsonrpc\":\"2.0\",\"id\":71,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_entries\",\"arguments\":{\"form_id\":$FORM}}}"
check "but cannot call the admin-level entry list" "$(verdict ro_write)" "error"
docker compose exec -T cli wp eval 'foreach ( GMCP_Tokens::all() as $k ) { if ( ( $k["label"] ?? "" ) === "smoke gravityforms readonly" ) { GMCP_Tokens::revoke( $k["id"] ); } }' >/dev/null 2>&1

echo "-- the audit log records the target (entry id), not an empty string --"
check "a gf_ audit row names the entry it was aimed at" \
  "$(dbq "SELECT target FROM wp_gmcp_audit WHERE tool='gf_update_entry_field' AND target='$A' ORDER BY id DESC LIMIT 1")" "$A"

echo "-- the Gravity Forms tools announce their writes --"
# These go through GFAPI, not wp_update_post, so no usual content-change path ran and an
# integration purging a page cache would see the write as silence.
docker exec -u 0 "${COMPOSE_PROJECT_NAME:-wptest}-wp-1" mkdir -p /var/www/html/wp-content/mu-plugins
docker compose exec -T wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/gf-mutate-probe.php <<"PHPEOF"
<?php
add_action( "gmcp_mutate", function ( $tool ) {
  $seen = get_option( "gf_probe_mutate", [] );
  $seen[] = $tool;
  update_option( "gf_probe_mutate", $seen, false );
}, 10, 1 );
PHPEOF'
check "CONTROL: the mutation probe is installed and can observe" \
  "$(docker compose exec -T wp sh -c 'test -f /var/www/html/wp-content/mu-plugins/gf-mutate-probe.php && echo present || echo absent' 2>/dev/null | tr -d '\r\n')" "present"
check "control: and WordPress has loaded it" \
  "$(docker compose exec -T cli wp eval 'echo has_action("gmcp_mutate") ? "hooked" : "not hooked";' 2>/dev/null | tr -d '\r\n')" "hooked"
wpc option delete gf_probe_mutate >/dev/null 2>&1
call mu_update "{\"jsonrpc\":\"2.0\",\"id\":80,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_update_entry\",\"arguments\":{\"entry_id\":$B,\"is_read\":true}}}"
call mu_read '{"jsonrpc":"2.0","id":81,"method":"tools/call","params":{"name":"gf_list_forms","arguments":{}}}'
gf_fired() { wpc eval 'echo in_array("'"$1"'", (array) get_option("gf_probe_mutate",[]), true) ? "fires" : "silent";'; }
check "control: the write itself succeeded" "$(verdict mu_update)" "ok"
check "an entry update announces itself" "$(gf_fired gf_update_entry)" "fires"
check "but a read does not" "$(gf_fired gf_list_forms)" "silent"
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/gf-mutate-probe.php' >/dev/null 2>&1
wpc option delete gf_probe_mutate >/dev/null 2>&1

echo "-- the group switch --"
group_off
call gf_off '{"jsonrpc":"2.0","id":90,"method":"tools/list"}'
check "switched off, no gf_ tool is offered" \
  "$(py 'import json,sys;print(len([t for t in json.load(sys.stdin)["result"]["tools"] if t["name"].startswith("gf_")]))' gf_off)" "0"
call gf_off_call "{\"jsonrpc\":\"2.0\",\"id\":91,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_get_entry\",\"arguments\":{\"entry_id\":$A}}}"
check "and calling one anyway is refused" "$(verdict gf_off_call)" "error"
group_on

echo "-- Gravity Forms deactivated --"
docker compose exec -T cli wp plugin deactivate gravityforms >/dev/null 2>&1
call gf_gone "{\"jsonrpc\":\"2.0\",\"id\":92,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_get_entry\",\"arguments\":{\"entry_id\":$A}}}"
check "with Gravity Forms gone, the tool refuses cleanly" "$(verdict gf_gone)" "error"
check "saying it is not loaded, not that it does not exist" \
  "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("not loaded" in t.lower())' gf_gone)" "True"
docker compose exec -T cli wp plugin activate gravityforms >/dev/null 2>&1
call gf_back "{\"jsonrpc\":\"2.0\",\"id\":93,\"method\":\"tools/call\",\"params\":{\"name\":\"gf_list_forms\",\"arguments\":{}}}"
check "CONTROL: reactivating makes the tools work again" "$(verdict gf_back)" "ok"

echo "-- cleanup --"
docker compose exec -T cli wp eval-file - <<'PHP' >/dev/null 2>&1
<?php
foreach ( [ true, false ] as $active ) {
  foreach ( GFAPI::get_forms( $active, false ) as $f ) {
    if ( strpos( (string) $f['title'], 'GMCP GF Smoke' ) === 0 ) { GFAPI::delete_form( $f['id'] ); }
  }
}
PHP
group_off

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
