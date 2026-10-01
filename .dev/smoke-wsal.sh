#!/bin/bash
# Smoke test for the WP Activity Log tools.
#
# Its own file because it needs WP Activity Log installed and its tool group switched on,
# and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin activate wp-security-audit-log
#   ./smoke-wsal.sh
#
# Every check asserts stored state rather than the wording of the reply, and every "the tool
# refused" or "X is absent" check sits beside a control proving the same probe finds X when
# present. Blocks that exist to catch one specific defect say so.
#
# FIXTURES. The events are produced by real WordPress activity, because WP Activity Log's own
# sensors are the writer. Nothing is inserted with a hand-built SQL row: a row written by hand
# would sit in the database without the metadata the plugin's own writer adds, and the suite
# would then agree with a bug rather than find it. The activity goes through the plugin's own
# tools over MCP where a tool exists, so a REST call with a real actor is covered as well as
# the command-line path, which the plugin records with no user at all.
#
# WHAT THIS SUITE CANNOT TEST. The external-storage branch (wsal_adapter-connection set) is
# exercised by writing the option directly for the length of one block and putting it back,
# because there is no external database here to configure; that tests that the tool notices
# the setting, not that it would successfully read a remote log. The multisite branch is not
# covered at all: this stack is single-site, so base_prefix and prefix are the same string
# and the choice between them is unverifiable from here.
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
TOK=$(gmcp_make_key "smoke wsal" admin)
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
dbq() { docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
# A field out of the JSON body of a tool reply.
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
# Does the raw reply text contain a string? (lowercased both sides)
has() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","")
print("yes" if sys.argv[1].lower() in t.lower() else "no")' "$2" "$1"; }
# An integer field out of a reply's JSON body, or empty when it is absent.
jnum() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
try: j=json.loads(t)
except Exception: print(""); sys.exit()
cur=j
for part in sys.argv[1].split("."):
    if part=="": continue
    if isinstance(cur,list): cur=cur[int(part)]
    elif isinstance(cur,dict): cur=cur.get(part)
    else: cur=None
print("" if cur is None else cur)' "$2" "$1"; }
# A yes/no answer for "is this number at least that many", so the assertion reads as a word.
atleast() { # file field floor
  n=$(jnum "$1" "$2")
  if [ -n "$n" ] && [ "$n" -ge "$3" ] 2>/dev/null; then echo yes; else echo no; fi; }
atmost() { # file field ceiling
  n=$(jnum "$1" "$2")
  if [ -n "$n" ] && [ "$n" -le "$3" ] 2>/dev/null; then echo yes; else echo no; fi; }
# A yes/no answer for a scalar SQL query counting at least one row.
sqlhas() { # sql
  n=$(dbq "$1")
  if [ -n "$n" ] && [ "$n" -gt 0 ] 2>/dev/null; then echo yes; else echo no; fi; }
group_on()  { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_wp_activity_log"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
group_off() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_wp_activity_log"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }

if ! docker compose exec -T cli wp plugin is-active wp-security-audit-log >/dev/null 2>&1; then
  echo "WP Activity Log is not active. Activate it first:"
  echo "  docker compose exec -T cli wp plugin activate wp-security-audit-log"
  exit 2
fi
group_on

# ---------------------------------------------------------------------------------------
# Fixtures: real activity, with two markers that must never reach a reply.
# ---------------------------------------------------------------------------------------
MARK="wsalmark$(date +%s)"
SECRET_USER="SECRET-${MARK}"
EMAIL="${MARK}@example.test"

echo "-- seeding with real activity (the plugin's own sensors are the writer) --"
BOUNDARY=$(dbq "SELECT COALESCE(MAX(id),0) FROM wp_wsal_occurrences")
echo "  occurrence id boundary: $BOUNDARY"

# A post, created and then retitled, over the site's own REST API so a real actor is recorded.
call seed_post "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_create_post\",\"arguments\":{\"post_type\":\"post\",\"post_status\":\"publish\",\"post_title\":\"$MARK original\",\"post_content\":\"marker body\"}}}"
check "the post was created through the plugin's own tool" "$(verdict seed_post)" "ok"
# Taken from the database rather than scraped from the reply, so the fixture is anchored to
# the post that really exists.
POST_ID=$(dbq "SELECT ID FROM wp_posts WHERE post_title='$MARK original' AND post_type='post' ORDER BY ID DESC LIMIT 1")
check "CONTROL: the post fixture exists to be edited" "$([ -n "$POST_ID" ] && echo yes || echo no)" "yes"
call seed_edit "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_post\",\"arguments\":{\"ID\":$POST_ID,\"fields\":{\"post_title\":\"$MARK renamed\"}}}}"
check "the post was retitled through the plugin's own tool" "$(verdict seed_edit)" "ok"
call seed_delete "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_delete_post\",\"arguments\":{\"ID\":$POST_ID,\"force\":true}}}"

# A user with a distinctive email, so the serialised user object is really there.
# Stale fixtures from earlier runs are removed first, so the control below counts this run's
# row and not a pile of them.
wpc eval 'foreach ( get_users( [ "search" => "wsalmark*", "search_columns" => [ "user_login" ] ] ) as $u ) { wp_delete_user( $u->ID ); }' >/dev/null 2>&1
wpc eval "\$u = wp_insert_user( [ 'user_login' => '$MARK', 'user_email' => '$EMAIL', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] ); echo is_wp_error(\$u) ? 'ERR' : \$u;" >/dev/null 2>&1

# A failed login: post to wp-login.php with a username that is itself a secret. This is the
# row whose message must be withheld, because the plugin fills it with what was typed.
curl -sS -o /dev/null -X POST "$BASE/wp-login.php" \
  -d "log=$SECRET_USER&pwd=wrong&wp-submit=Log+In&redirect_to=%2F&testcookie=1" 2>/dev/null
sleep 2

AFTER_BOUNDARY=$(dbq "SELECT COALESCE(MAX(id),0) FROM wp_wsal_occurrences")
NEW_ROWS=$((AFTER_BOUNDARY - BOUNDARY))
echo "  new occurrences: $NEW_ROWS (boundary $BOUNDARY -> $AFTER_BOUNDARY)"

# ---------------------------------------------------------------------------------------
echo
echo "-- controls: the fixtures really are in the database (or the absence checks below are vacuous) --"
check "CONTROL: the marker email is stored in the metadata table" \
  "$(sqlhas "SELECT COUNT(*) FROM wp_wsal_metadata WHERE value LIKE '%${EMAIL}%'")" "yes"
check "CONTROL: the failed-login username is stored in the metadata table" \
  "$(dbq "SELECT COUNT(*) FROM wp_wsal_metadata WHERE value LIKE '%${SECRET_USER}%'")" "1"
check "CONTROL: serialised values exist in the metadata table" \
  "$(sqlhas "SELECT COUNT(*) FROM wp_wsal_metadata WHERE value REGEXP '^(a|O):[0-9]+:'")" "yes"
check "CONTROL: the marker post title reached an occurrence" \
  "$(sqlhas "SELECT COUNT(*) FROM wp_wsal_occurrences WHERE id > $BOUNDARY AND alert_id IN (2001,2086)")" "yes"

# ---------------------------------------------------------------------------------------
echo
echo "-- the group is a switch, not a suggestion --"
group_off
call off_brief "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{}}}"
check "with the group off, the tool is refused" "$(verdict off_brief)" "error"
group_on
call on_brief "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{}}}"
check "CONTROL: with the group on, the same call succeeds" "$(verdict on_brief)" "ok"

# ---------------------------------------------------------------------------------------
echo
echo "-- the briefing reports counts, and the log's own state --"
call brief "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{\"days\":30}}}"
check "the briefing succeeds" "$(verdict brief)" "ok"
check "it reports the plugin as loaded" "$(jget brief "log_state.plugin_loaded")" "true"
check "it reports both tables present" "$(jget brief "log_state.tables.present.0")" "wsal_occurrences"
check "it reports the log as readable" "$(jget brief "log_state.readable")" "true"
check "it counts more than zero events (or the rest of this block is vacuous)" \
  "$(atleast brief "counts.total" 1)" "yes"
check "it counts the marker's own activity in the window" \
  "$(atleast brief "counts.total" "$NEW_ROWS")" "yes"
SEVNAMED="no"
names_hit=$(py 'import json,sys
j=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
known=["High","Critical","Medium","Low","Informational","Notification"]
print("yes" if any(k in known for k in j["counts"]["by_severity"]) else "no")' "" brief)
if [ "$names_hit" = "yes" ]; then SEVNAMED="yes"; fi
check "severity is named, not left as a number" "$SEVNAMED" "yes"
check "it reports how many alert types are switched off" \
  "$(atleast brief "log_state.disabled_alerts" 0)" "yes"

echo "-- the counts carry no actor, no address and no user agent --"
check "the briefing reply has no IP-shaped value" \
  "$(py 'import json,sys,re
t=json.load(sys.stdin)["result"]["content"][0]["text"]
print("yes" if re.search(r"\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b",t) else "no")' "" brief)" "no"
check "the briefing reply has no user agent" "$(has brief "Mozilla")" "no"
check "the briefing reply has no username" "$(has brief "$MARK")" "no"

# ---------------------------------------------------------------------------------------
echo
echo "-- a read key may count but not name --"
READTOK=$(gmcp_make_key "smoke wsal read" read)
call rbrief "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{\"days\":7}}}"
rverdict=$(curl -sS -X POST "$URL" -H "Authorization: Bearer $READTOK" -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wsal_events_briefing","arguments":{"days":7}}}' \
  | python3 -c 'import json,sys;d=json.load(sys.stdin);print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")')
check "a read key can call the briefing" "$rverdict" "ok"
rlist=$(curl -sS -X POST "$URL" -H "Authorization: Bearer $READTOK" -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wsal_list_events","arguments":{}}}' \
  | python3 -c 'import json,sys;d=json.load(sys.stdin);print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")')
check "CONTROL: the same key is refused the row tool" "$rlist" "error"

# ---------------------------------------------------------------------------------------
echo
echo "-- rows are admin-level, and carry the allowlisted message --"
call list "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"limit\":100}}}"
check "list_events succeeds at admin" "$(verdict list)" "ok"
check "it returns at least the new fixtures" \
  "$(atleast list "count" 1)" "yes"
check "a row carries an id" "$(atleast list "events.0.id" 1)" "yes"
check "a row carries the alert id" "$(atleast list "events.0.alert_id" 1)" "yes"
check "the marker post title is filled into a message (the filler works)" "$(has list "$MARK")" "yes"
check "no row in the reply carries an absolute filesystem path" "$(has list "/var/www")" "no"
check "no row in the reply carries a serialised value" \
  "$(py 'import json,sys,re
t=json.load(sys.stdin)["result"]["content"][0]["text"]
print("yes" if re.search(r"O:\d+:\\\"|s:\d+:\\\"|a:\d+:\{",t) else "no")' "" list)" "no"
check "no row in the reply carries an email address" "$(has list "$EMAIL")" "no"
check "no row in the reply carries the attempted username" "$(has list "$SECRET_USER")" "no"
check "no row in the reply carries a client IP" \
  "$(py 'import json,sys,re
t=json.load(sys.stdin)["result"]["content"][0]["text"]
print("yes" if re.search(r"\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b",t) else "no")' "" list)" "no"

echo "-- the failed-login row is listed, with its message withheld --"
LOGIN_ID=$(dbq "SELECT id FROM wp_wsal_occurrences WHERE id > $BOUNDARY AND alert_id IN (1002,1003) ORDER BY id DESC LIMIT 1")
if [ -n "$LOGIN_ID" ]; then
  call getlogin "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_get_event\",\"arguments\":{\"id\":$LOGIN_ID}}}"
  check "the failed-login event is returned (not hidden)" "$(verdict getlogin)" "ok"
  check "its message is withheld rather than filled" "$(jget getlogin "event.message")" ""
  check "CONTROL: the row is really the failed-login alert" \
    "$(atleast getlogin "event.alert_id" 1002)" "yes"
  check "the attempted username is not in the reply, message or metadata" "$(has getlogin "$SECRET_USER")" "no"
else
  # A failure, not a skip: this is the only test of the message-withholding guard, and a
  # skip here would let that guard disappear without anything going red.
  echo "  FAIL  no failed-login occurrence was recorded, so the withholding guard went untested"
  fail=$((fail+1))
fi

# ---------------------------------------------------------------------------------------
echo
echo "-- a person or a field the site calls secret is not returned, in any channel --"
# This block exists because of a real defect: a user-profile change stores the field's NAME
# in custom_field_name and its VALUE in new_value, with no name anywhere that looks secret
# on its own. A reader that excluded by name returned the value in plaintext.
#
# The field is `nickname` because that is what WP Activity Log records: measured on 5.6.7,
# a leading-underscore user meta is not logged at all, so seeding one would produce no row
# and this block would pass by testing nothing.
SEC_USER=$(dbq "SELECT ID FROM wp_users ORDER BY ID ASC LIMIT 1")
SEC_VALUE="sv-$MARK"
wpc eval "update_user_meta($SEC_USER, 'first_name', 'LeakFirst$MARK'); update_user_meta($SEC_USER, 'nickname', '$SEC_VALUE');" >/dev/null 2>&1
sleep 2
LEAK_ROW=$(dbq "SELECT occurrence_id FROM wp_wsal_metadata WHERE name='custom_field_name' AND value='nickname' ORDER BY occurrence_id DESC LIMIT 1")
check "CONTROL: the nickname value is stored in the log's metadata table" \
  "$(sqlhas "SELECT COUNT(*) FROM wp_wsal_metadata WHERE name IN ('new_value','new_nickname') AND value='$SEC_VALUE'")" "yes"
check "CONTROL: the field name is stored beside it" \
  "$(sqlhas "SELECT COUNT(*) FROM wp_wsal_metadata WHERE name='custom_field_name' AND value='nickname'")" "yes"
check "CONTROL: the first name is stored in the log" \
  "$(sqlhas "SELECT COUNT(*) FROM wp_wsal_metadata WHERE value='LeakFirst$MARK'")" "yes"
if [ -n "$LEAK_ROW" ]; then
  # Every row this activity produced, because the write path records more than one.
  ALLROWS=$(dbq "SELECT GROUP_CONCAT(DISTINCT id) FROM wp_wsal_occurrences WHERE id >= $LEAK_ROW")
  for r in $(echo "$ALLROWS" | tr ',' ' '); do
    call "leak_$r" "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_get_event\",\"arguments\":{\"id\":$r}}}"
    check "row $r does not return the stored value" "$(has "leak_$r" "$SEC_VALUE")" "no"
    check "row $r does not return the first name" "$(has "leak_$r" "LeakFirst$MARK")" "no"
  done
  check "CONTROL: the event replies were real, not empty" \
    "$(atleast "leak_$LEAK_ROW" "event.id" 1)" "yes"
  check "and the reply says which metadata was withheld" \
    "$(has "leak_$LEAK_ROW" "withheld_metadata")" "yes"
else
  echo "  FAIL  no user-profile occurrence was recorded, so this block tested nothing"
  fail=$((fail+1))
fi
wpc eval "update_user_meta($SEC_USER, 'first_name', ''); update_user_meta($SEC_USER, 'nickname', 'admin');" >/dev/null 2>&1

echo
echo "-- one event in full: metadata is deny-by-default --"
# Chosen deliberately: a row that carries a serialised value the allowlist DOES return, so
# the "marked rather than printed" check has something to find. If this site has no such
# row, the block creates the activity that produces one rather than passing vacuously.
SERIAL_ROW=$(dbq "SELECT occurrence_id FROM wp_wsal_metadata WHERE name='PluginData' ORDER BY occurrence_id DESC LIMIT 1")
if [ -z "$SERIAL_ROW" ]; then
  wpc plugin deactivate hello >/dev/null 2>&1
  wpc plugin activate hello >/dev/null 2>&1
  sleep 2
  SERIAL_ROW=$(dbq "SELECT occurrence_id FROM wp_wsal_metadata WHERE name='PluginData' ORDER BY occurrence_id DESC LIMIT 1")
fi
echo "  (row $SERIAL_ROW carries a serialised value)"
check "CONTROL: a serialised value really is stored for that row" \
  "$(sqlhas "SELECT COUNT(*) FROM wp_wsal_metadata WHERE occurrence_id=$SERIAL_ROW AND name='PluginData' AND value REGEXP '^O:[0-9]+:'")" "yes"
call get "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_get_event\",\"arguments\":{\"id\":$SERIAL_ROW}}}"
check "get_event succeeds at admin" "$(verdict get)" "ok"
check "it returns the event" "$(atleast get "event.id" 1)" "yes"
check "the reply has no client IP" "$(has get "ClientIP")" "no"
check "the reply has no user agent value" "$(has get "Mozilla")" "no"
check "the reply has no session id" "$(has get "SessionID")" "no"
check "the reply has no absolute filesystem path" "$(has get "/var/www")" "no"
check "the reply has no wp-admin URL" "$(has get "wp-admin")" "no"
check "no serialised value is printed" "$(has get "O:8")" "no"
check "a serialised value is marked or resolved, never printed raw" \
  "$(has get "serialised value")" "yes"

echo "-- and the metadata that IS returned is real (or the exclusions above are trivial) --"
check "CONTROL: a permitted metadata name comes back" "$(has get "PluginFile")" "yes"

echo "-- a specific missing id is reported as missing, not as an empty log --"
call missing "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_get_event\",\"arguments\":{\"id\":99999999}}}"
check "an unknown id succeeds with a specific note" "$(verdict missing)" "ok"
check "and says the log was readable" "$(jget missing "log_state.readable")" "true"
check "and says that one id is absent" "$(has missing "is in this site")" "yes"

# ---------------------------------------------------------------------------------------
echo
echo "-- input is refused where a guess would be silently wrong --"
call badsev "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"min_severity\":\"VeryHigh\"}}}"
check "an unknown severity name is refused" "$(verdict badsev)" "error"
call badfam "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"family\":\"everything\"}}}"
check "an unknown family is refused" "$(verdict badfam)" "error"
call baddate "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"since\":\"2026-09-01 00:00:00\"}}}"
check "a date with no timezone is refused rather than guessed" "$(verdict baddate)" "error"
call gooddate "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"since\":\"2026-09-01T00:00:00Z\"}}}"
check "CONTROL: the same date with a zone is accepted" "$(verdict gooddate)" "ok"
call capped "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"limit\":100000}}}"
check "an oversized limit is capped, not obeyed" "$(atmost capped "count" 100)" "yes"
# The cap is asserted against the row count, not by requiring a truncation: on a log with
# fewer rows than the cap, returning everything is correct and a truncation flag would be a
# lie. The control is that the log really does hold more rows than a small page, so a
# limit of 1 must return 1 and say the rest is reachable.
LOGROWS=$(dbq "SELECT COUNT(*) FROM wp_wsal_occurrences")
if [ "$LOGROWS" -gt 1 ]; then
  call tiny "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"limit\":1}}}"
  check "a limit of 1 returns exactly one row" "$(jnum tiny "count")" "1"
  check "and reports a truncation, because more rows exist" "$(jget tiny "truncated")" "true"
  check "and gives next_offset so the rest is reachable" "$(atleast tiny "next_offset" 1)" "yes"
  call page2 "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"limit\":1,\"offset\":1}}}"
  check "CONTROL: the next page returns a different row, not the same one" \
    "$([ "$(jnum tiny "events.0.id")" != "$(jnum page2 "events.0.id")" ] && echo yes || echo no)" "yes"
  # The keyset cursor is the stable way to page on a log that is being written to. Proven by
  # writing an event BETWEEN two calls, which is exactly what breaks offset paging: with a
  # cursor the next page must still be strictly below the cursor.
  CURSOR=$(jnum tiny "next_before_id")
  check "the reply offers a before_id cursor" "$([ -n "$CURSOR" ] && [ "$CURSOR" -gt 0 ] && echo yes || echo no)" "yes"
  wpc eval '$p=wp_insert_post(["post_title"=>"Cursor probe '"$MARK"'","post_status"=>"publish","post_type"=>"post"]); echo $p;' >/dev/null 2>&1
  sleep 1
  call cursored "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"limit\":1,\"before_id\":$CURSOR}}}"
  CURSORED_ID=$(jnum cursored "events.0.id")
  check "paging by cursor still returns a row after a new event arrived" \
    "$([ -n "$CURSORED_ID" ] && [ "$CURSORED_ID" -gt 0 ] && echo yes || echo no)" "yes"
  check "and it is strictly below the cursor, so nothing repeats" \
    "$([ -n "$CURSORED_ID" ] && [ "$CURSORED_ID" -lt "$CURSOR" ] && echo yes || echo no)" "yes"
else
  echo "  FAIL  the log has $LOGROWS row(s), so the paging checks would be vacuous"
  fail=$((fail+1))
fi
call oldsince "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"since\":\"1970-01-01T00:00:00Z\"}}}"
check "a start date before the window cap is refused, not served" "$(verdict oldsince)" "error"
call recentsince "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_list_events\",\"arguments\":{\"since\":\"$(date -u -v-2H '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date -u -d '2 hours ago' '+%Y-%m-%dT%H:%M:%SZ')\"}}}"
check "CONTROL: a start date inside the window is accepted" "$(verdict recentsince)" "ok"
call fwindow "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{\"days\":100000}}}"
check "an oversized window is capped at 90 days" "$(jget fwindow "window.days")" "90"

echo "-- the family filter narrows rather than widening --"
# Same window on both sides, and a strict comparison, so a filter that does nothing cannot
# pass. The control proves events outside the family exist, which is what makes the
# narrowing meaningful.
call fam "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{\"days\":90,\"family\":\"plugins\"}}}"
check "a family filter succeeds" "$(verdict fam)" "ok"
call famall "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{\"days\":90}}}"
FAMN=$(jnum fam "counts.total")
ALLN=$(jnum famall "counts.total")
check "CONTROL: unfiltered really is bigger than the family (else this proves nothing)" \
  "$([ -n "$FAMN" ] && [ -n "$ALLN" ] && [ "$ALLN" -gt "$FAMN" ] && echo yes || echo no)" "yes"
check "the family count is a strict subset" \
  "$([ -n "$FAMN" ] && [ -n "$ALLN" ] && [ "$FAMN" -lt "$ALLN" ] && echo yes || echo no)" "yes"

# ---------------------------------------------------------------------------------------
echo
echo "-- a log that cannot be read says so, instead of reporting zero --"
# The tables are renamed for the length of this block and put back, so the tool is asked the
# question it exists to answer. The names are read first, so the restore is to the exact
# table that was there.
REALOCC=$(dbq "SHOW TABLES LIKE 'wp_wsal_occurrences'")
check "CONTROL: the occurrences table exists before the block" "$REALOCC" "wp_wsal_occurrences"
# A trap, because an interrupted run would leave WP Activity Log's live table renamed and
# the site would stop logging without any error appearing anywhere.
trap 'dbq "RENAME TABLE wp_wsal_occurrences_hidden TO wp_wsal_occurrences" >/dev/null 2>&1' EXIT INT TERM
dbq "RENAME TABLE wp_wsal_occurrences TO wp_wsal_occurrences_hidden" >/dev/null 2>&1
call missingtables "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{\"days\":7}}}"
check "with the table absent, the briefing still answers" "$(verdict missingtables)" "ok"
check "it does NOT report a readable log" "$(jget missingtables "log_state.readable")" "false"
check "it does NOT report counts" "$(has missingtables "by_severity")" "no"
check "it names the missing table" "$(jget missingtables "log_state.tables.missing.0")" "wsal_occurrences"
dbq "RENAME TABLE wp_wsal_occurrences_hidden TO wp_wsal_occurrences" >/dev/null 2>&1
trap - EXIT INT TERM
check "CONTROL: the table is back and counts are real again" \
  "$(sqlhas "SELECT COUNT(*) FROM wp_wsal_occurrences")" "yes"
call restored "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{\"days\":7}}}"
check "and the briefing reports the log as readable again" "$(jget restored "log_state.readable")" "true"

echo "-- external storage is announced, not counted through --"
ADAPTER_BEFORE=$(wpc eval 'echo var_export(get_option("wsal_adapter-connection",false),true);')
wpc eval 'update_option("wsal_adapter-connection","mysql://example");' >/dev/null 2>&1
call external "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{\"days\":7}}}"
check "with external storage configured, the log is not readable locally" "$(jget external "log_state.readable")" "false"
check "it reports the storage as external" "$(jget external "log_state.storage")" "external"
# Restore before asserting anything, so a failure here cannot leave the site misconfigured.
wpc eval 'delete_option("wsal_adapter-connection");' >/dev/null 2>&1
ADAPTER_AFTER=$(wpc eval 'echo var_export(get_option("wsal_adapter-connection",false),true);')
check "the adapter option is restored to its previous value" "$ADAPTER_AFTER" "$ADAPTER_BEFORE"
call reverted "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wsal_events_briefing\",\"arguments\":{\"days\":7}}}"
check "CONTROL: and the briefing reads the local log again" "$(jget reverted "log_state.readable")" "true"

# ---------------------------------------------------------------------------------------
echo
echo "-- the log's own settings cannot be written through this plugin --"
for key in wsal_disabled-alerts wsal_excluded-users wsal_pruning-date wsal_delete-data; do
  BEFORE=$(wpc eval "echo json_encode(get_option('$key',null));")
  call "kill_$key" "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_option\",\"arguments\":{\"key\":\"$key\",\"value\":[1,2]}}}"
  check "$key is refused by the option tool" "$(verdict "kill_$key")" "error"
  AFTER=$(wpc eval "echo json_encode(get_option('$key',null));")
  check "and its stored value is unchanged" "$AFTER" "$BEFORE"
done
# A control with the same prefix shape, so the refusal is about the family and not about
# everything with "wsal" in the name.
call notlog "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_option\",\"arguments\":{\"key\":\"wsalmarker_probe\",\"value\":\"kept\"}}}"
check "CONTROL: an ordinary option with a similar name still writes" "$(verdict notlog)" "ok"
check "and its value is really stored" "$(wpc eval 'echo get_option("wsalmarker_probe","MISSING");')" "kept"
wpc eval 'delete_option("wsalmarker_probe");' >/dev/null 2>&1

# ---------------------------------------------------------------------------------------
echo
echo "-- cleanup --"
wpc eval "\$t=GMCP_Tokens::all(); foreach(\$t as \$k){ if(strpos(\$k['label'],'smoke wsal')===0) GMCP_Tokens::revoke(\$k['id']); }" >/dev/null 2>&1
wpc eval "\$u=get_user_by('login','$MARK'); if(\$u) wp_delete_user(\$u->ID);" >/dev/null 2>&1

echo
echo "  $pass passed, $fail failed"
rm -rf "$OUT"
[ "$fail" -eq 0 ]
