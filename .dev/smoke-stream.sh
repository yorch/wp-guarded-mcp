#!/bin/bash
# Smoke test for the Stream tools.
#
# Its own file because it needs Stream installed and its tool group switched on, and most runs
# of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin install stream --activate
#   ./smoke-stream.sh
#
# Every check asserts stored state rather than the wording of the reply, and every "X is
# absent" check sits beside a control proving the same probe finds X when present. Blocks that
# exist to catch one specific defect say so.
#
# FIXTURES. The records are produced by real WordPress activity, because Stream's own
# connectors are the writer: a post is created and a user is added through WP-CLI, and Stream
# records both. Nothing is inserted into wp_stream by hand — a hand-built row would sit there
# without the metadata the plugin's own writer adds, and the suite would then agree with a bug
# rather than find it.
#
# THE user_meta BLOCK IS THE POINT OF THIS SUITE. Measured on Stream 4.4.0, a record for a
# created user stores the new account's email address as the FIRST of several values under a
# repeated key called user_meta, with nothing in the name to give it away. A reader that
# allowed it through would return a person's email in plaintext, and this plugin's audit log
# would then hold it for the retention window. The check is written as a pair: the value is in
# the database (control), and it is not in the reply.
#
# WHAT THIS SUITE CANNOT TEST. The multisite branch of table() is not covered: this stack is
# single-site, so base_prefix and prefix are the same string and the choice between them is
# unverifiable from here.
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
TOK=$(gmcp_make_key "smoke stream" admin)
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
dbq() { docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
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
has() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","")
print("yes" if sys.argv[1].lower() in t.lower() else "no")' "$2" "$1"; }
# Every record in a reply must be free of the ip key. Written as a scan of the parsed JSON
# rather than a substring search, so a field merely named "ip" elsewhere cannot satisfy it.
no_ip() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
try: j=json.loads(t)
except Exception: print("UNPARSEABLE"); sys.exit()
rows = j.get("records") or ([j["record"]] if j.get("record") else [])
bad = [r for r in rows if isinstance(r,dict) and ("ip" in r or "address" in r)]
print("clean" if not bad else "LEAK")' "" "$1"; }
group_on()  { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_stream"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
group_off() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_stream"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
toolcount() { curl -sS -X POST "$URL" -H "Authorization: Bearer $TOK" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":700,"method":"tools/list"}' \
  | python3 -c "import json,sys
t=json.load(sys.stdin)['result']['tools']
print(len([x for x in t if x['name'].startswith('stream_')]))"; }

if ! docker compose exec -T cli wp plugin is-active stream >/dev/null 2>&1; then
  echo "Stream is not active. Activate it first:"
  echo "  docker compose exec -T cli wp plugin install stream --activate"
  exit 2
fi

echo "-- the group is a switch, and off means absent rather than hidden --"
group_off
check "group off: no stream_ tools registered" "$(toolcount)" "0"
group_on
check "group on: three stream_ tools registered" "$(toolcount)" "3"

echo "-- fixtures: real activity, recorded by Stream's own connectors --"
MARK="streammark$(date +%s)"
EMAIL="secret-${MARK}@example.test"
POST_ID=$(wpc post create --post_title="Probe post $MARK" --post_status=publish --porcelain | tail -1)
case "$POST_ID" in ''|*[!0-9]*) echo "Could not create a post fixture." >&2; exit 1 ;; esac
USER_ID=$(wpc user create "probe$MARK" "$EMAIL" --role=editor --porcelain | tail -1)
case "$USER_ID" in ''|*[!0-9]*) echo "Could not create a user fixture." >&2; exit 1 ;; esac
echo "  post id: $POST_ID, user id: $USER_ID"
check "control: Stream recorded the post" \
  "$(dbq "SELECT COUNT(*) FROM wp_stream WHERE connector='posts' AND object_id=$POST_ID")" "1"
check "control: Stream recorded the user" \
  "$(dbq "SELECT COUNT(*) FROM wp_stream WHERE connector='users' AND object_id=$USER_ID")" "1"
check "control: the record carries the email in its metadata" \
  "$(dbq "SELECT IF(COUNT(*) > 0, 'yes', 'no') FROM wp_stream_meta WHERE meta_value LIKE '%$EMAIL%'")" "yes"

echo "-- the briefing counts what is in the table --"
call stream_events_briefing '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"stream_events_briefing","arguments":{"days":30}}}'
check "stream_events_briefing answers" "$(verdict stream_events_briefing)" "ok"
check "total matches the table" "$(jnum stream_events_briefing counts.total)" \
  "$(dbq "SELECT COUNT(*) FROM wp_stream WHERE created >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)")"
check "the posts connector is counted" \
  "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
j=json.loads(t)
print("yes" if int(j["counts"]["by_connector"].get("posts",0)) > 0 else "no")' "" stream_events_briefing)" "yes"
check "the log is reported readable" "$(jget stream_events_briefing log_state.readable)" "true"

echo "-- rows are returned at admin level, and never with an address --"
REC_ID=$(dbq "SELECT MAX(ID) FROM wp_stream WHERE object_id=$POST_ID AND connector='posts'")
call stream_list_records '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"stream_list_records","arguments":{"limit":5}}}'
check "stream_list_records answers" "$(verdict stream_list_records)" "ok"
check "it returns records" \
  "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print("yes" if len(json.loads(t).get("records") or []) > 0 else "no")' "" stream_list_records)" "yes"
check "no record carries an address" "$(no_ip stream_list_records)" "clean"
# The control: the address is in the database, so a reader that selected it would have
# returned it. Without this, "clean" and "the probe is broken" look identical.
check "control: the address is stored on that row" \
  "$(dbq "SELECT COUNT(*) FROM wp_stream WHERE ID=$REC_ID AND ip IS NOT NULL AND ip <> ''")" "1"

echo "-- the metadata allowlist, and the email it keeps out --"
USER_REC=$(dbq "SELECT MAX(ID) FROM wp_stream WHERE connector='users' AND object_id=$USER_ID")
call stream_get_record '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"stream_get_record","arguments":{"id":'"$USER_REC"'}}}'
check "stream_get_record answers" "$(verdict stream_get_record)" "ok"
check "the actor's email is NOT returned" "$(has stream_get_record "$EMAIL")" "no"
check "no address in the record" "$(no_ip stream_get_record)" "clean"
check "user_meta is named as withheld" "$(has stream_get_record "user_meta")" "yes"
check "the reply explains why" \
  "$(has stream_get_record "concerns a person")" "yes"
# The control, again from the database rather than from the reply.
check "control: user_meta really holds the email" \
  "$(dbq "SELECT IF(COUNT(*) > 0, 'yes', 'no') FROM wp_stream_meta WHERE record_id=$USER_REC AND meta_key='user_meta' AND meta_value LIKE '%$EMAIL%'")" "yes"

echo "-- retention: the default is a policy, not an absence --"
# Read-modify-restore, because on a configured site wp_stream holds the site's real retention
# and exclude rules and a suite has no business deleting them. The first version asserted the
# row was absent, carried on when it was not, and then ran delete_option unconditionally —
# which would wipe a real Stream configuration, and which a killed run defeats anyway.
ORIG_STREAM=$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name='wp_stream'")
ORIG_TTL=$(wpc eval '$o = get_option( "wp_stream", [] ); echo isset( $o["general_records_ttl"] ) ? (int) $o["general_records_ttl"] : "__ABSENT__";' | tr -d '\r\n')
echo "  wp_stream row on entry: $ORIG_STREAM, ttl: $ORIG_TTL"
wpc eval 'delete_option( "wp_stream" );' >/dev/null 2>&1
check "control: the row is gone, so defaults are in force" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name='wp_stream'")" "0"
call stream_events_briefing '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"stream_events_briefing","arguments":{"days":30}}}'
check "the default retention is reported, not 'unset'" "$(jnum stream_events_briefing log_state.retention.days)" "30"
check "and its source is named as the default" "$(jget stream_events_briefing log_state.retention.source)" "defaults"
check "and no exclude rules are claimed" "$(jnum stream_events_briefing log_state.exclude_rules)" "0"
wpc eval 'update_option( "wp_stream", [ "general_records_ttl" => 5 ] );' >/dev/null 2>&1
call stream_events_briefing '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"stream_events_briefing","arguments":{"days":30}}}'
check "control: a stored retention is reported" "$(jnum stream_events_briefing log_state.retention.days)" "5"
check "and its source is named as stored" "$(jget stream_events_briefing log_state.retention.source)" "stored"

# Exclude rules are stored column by column, keyed by row id. The first version of the tool
# counted the outer array — six columns — so one rule reported six and no rules reported six.
wpc eval 'update_option( "wp_stream", [ "general_records_ttl" => 5, "exclude_rules" => [ "exclude_row" => [ "0" => [ "id" => "0", "connector" => "posts", "context" => "", "action" => "", "author_or_role" => "", "ip_address" => "" ] ] ] ] );' >/dev/null 2>&1
check "control: one exclude rule is stored" \
  "$(wpc eval '$o = get_option( "wp_stream", [] ); echo count( (array) ( $o["exclude_rules"]["exclude_row"] ?? [] ) );' | tr -d '\r\n')" "1"
call stream_events_briefing '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"stream_events_briefing","arguments":{"days":30}}}'
check "one rule is counted as one, not as six columns" "$(jnum stream_events_briefing log_state.exclude_rules)" "1"
check "and the reply says some events are never recorded" \
  "$(has stream_events_briefing "never recorded")" "yes"

# Put back exactly what was there on entry.
if [ "${ORIG_STREAM:-0}" -gt 0 ]; then
  B64TTL=$(printf '%s' "$ORIG_TTL" | base64 | tr -d '\n')
  wpc eval '$t = base64_decode( "'"$B64TTL"'" ); $o = get_option( "wp_stream", [] ); unset( $o["exclude_rules"] ); if ( $t === "__ABSENT__" ) { unset( $o["general_records_ttl"] ); } else { $o["general_records_ttl"] = (int) $t; } update_option( "wp_stream", $o );' >/dev/null 2>&1
else
  wpc eval 'delete_option( "wp_stream" );' >/dev/null 2>&1
fi
check "put back: the row count is what it was on entry" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name='wp_stream'")" "$ORIG_STREAM"

echo "-- the option writer cannot shorten the log's retention --"
# The defect this block exists to catch: before the fix, this exact call was accepted and
# stored general_records_ttl=1. Measured on this stack.
call wpo_stream '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"wp_stream","value":{"general_records_ttl":1}}}}'
check "a write to wp_stream is refused" "$(verdict wpo_stream)" "error"
check "and nothing was stored" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name='wp_stream'")" "0"
call wpo_stream_db '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"wp_stream_db","value":"0"}}}'
check "a write to wp_stream_db is refused too (prefix, not one row)" "$(verdict wpo_stream_db)" "error"
# The control: an ordinary option still writes, so the refusal above is the rule and not a
# broken option writer.
call wpo_ordinary '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"probe_stream_ordinary","value":"ok"}}}'
check "control: an ordinary option still writes" "$(verdict wpo_ordinary)" "ok"
check "control: and it is stored" "$(dbq "SELECT option_value FROM wp_options WHERE option_name='probe_stream_ordinary'")" "ok"
wpc eval 'delete_option( "probe_stream_ordinary" );' >/dev/null 2>&1

echo "-- a read key gets counts, not rows and not minutes --"
RO=$(gmcp_make_key "smoke stream readonly" readonly)
case "$RO" in
  gmcp_*)
    curl -sS -X POST "$URL" -H "Authorization: Bearer $RO" \
      -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
      -d '{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"stream_list_records","arguments":{}}}' -o "$OUT/stream_ro_rows"
    check "a read key cannot list records" "$(verdict stream_ro_rows)" "error"
    curl -sS -X POST "$URL" -H "Authorization: Bearer $RO" \
      -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
      -d '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"stream_events_briefing","arguments":{}}}' -o "$OUT/stream_ro_brief"
    check "a read key can read the counts" "$(verdict stream_ro_brief)" "ok"
    check "and is given a recency bucket, not a timestamp" \
      "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
j=json.loads(t).get("log_state",{})
print("yes" if j.get("newest") is None and j.get("how_recent") else "no")' "" stream_ro_brief)" "yes"
    ;;
  *) echo "  ....  read-level checks skipped: could not mint a read key" ;;
esac

echo "-- cleanup --"
wpc post delete "$POST_ID" --force >/dev/null 2>&1
wpc user delete "$USER_ID" --yes >/dev/null 2>&1
check "the post fixture is gone" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE ID=$POST_ID")" "0"
check "the user fixture is gone" "$(dbq "SELECT COUNT(*) FROM wp_users WHERE ID=$USER_ID")" "0"

group_off
echo
printf 'stream: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
