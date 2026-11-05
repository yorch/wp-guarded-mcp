#!/bin/bash
# Smoke test for the Wordfence tools.
#
# Its own file because it needs Wordfence installed and its tool group switched on, and most
# runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin install wordfence --activate
#   ./smoke-wordfence.sh
#
# Every check asserts stored state rather than the wording of the reply, and every "the tool
# refused" or "X is absent" check sits beside a control proving the same probe finds X when
# present. Blocks that exist to catch one specific defect say so.
#
# FIXTURES. A lockout is created through Wordfence's own writer (wfBlock::createLockout) and
# an issue through its own (wfIssues::shared()->addIssue), because a hand-built INSERT would
# omit whatever else those writers set and the suite would then agree with a bug. The IP is
# passed as a dotted string, which is what createLockout takes: it runs wfUtils::inet_pton()
# itself, and passing an already-packed address produces a row of null bytes — measured, the
# hard way, while writing this suite.
#
# WHAT THIS SUITE CANNOT TEST. Nothing here starts a real scan (it makes outbound requests to
# Wordfence's servers and can run for minutes on a shared host), so the states that depend on
# a completed scan are exercised by writing lastScanCompleted through wfConfig::set and
# putting it back, which tests that the tool reads the key the way Wordfence's own status
# widget reads it — not that a scan produces it. The multisite branch of table() is not
# covered at all: this stack is single-site.
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
TOK=$(gmcp_make_key "smoke wordfence" admin)
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
# Does the STORED state contain a string? The control half of every withholding check.
dbhas() { # dbhas <sql> <needle>
  n=$(docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r' | grep -c -- "$2")
  if [ "${n:-0}" -gt 0 ]; then echo yes; else echo no; fi; }
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
group_on()  { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_wordfence"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
group_off() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_wordfence"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
toolcount() { curl -sS -X POST "$URL" -H "Authorization: Bearer $TOK" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":700,"method":"tools/list"}' \
  | python3 -c "import json,sys
t=json.load(sys.stdin)['result']['tools']
print(len([x for x in t if x['name'].startswith('wf_')]))"; }

if ! docker compose exec -T cli wp plugin is-active wordfence >/dev/null 2>&1; then
  echo "Wordfence is not active. Activate it first:"
  echo "  docker compose exec -T cli wp plugin install wordfence --activate"
  exit 2
fi

echo "-- the group is a switch, and off means absent rather than hidden --"
group_off
check "group off: no wf_ tools registered" "$(toolcount)" "0"
group_on
check "group on: three wf_ tools registered" "$(toolcount)" "3"

echo "-- wf_status reports Wordfence's own state, not a guess --"
call wf_status '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wf_status","arguments":{}}}'
check "wf_status answers" "$(verdict wf_status)" "ok"
check "loaded" "$(jget wf_status wordfence.loaded)" "true"
check "version matches WORDFENCE_VERSION" "$(jget wf_status wordfence.version)" \
  "$(wpc eval 'echo WORDFENCE_VERSION;' | tr -d '\r\n')"
# Stored state, read through Wordfence's own accessor. A value the tool invented would not
# match; a value it read from the wrong row would not either.
check "firewall enabled matches wfConfig" "$(jget wf_status firewall.enabled)" \
  "$(wpc eval 'echo wfConfig::get("firewallEnabled")?"true":"false";' | tr -d '\r\n')"
check "firewall status matches wfConfig" "$(jget wf_status firewall.status)" \
  "$(wpc eval 'echo (string)wfConfig::get("waf_status");' | tr -d '\r\n')"
check "login security matches wfConfig" "$(jget wf_status login_security.enabled)" \
  "$(wpc eval 'echo wfConfig::get("loginSecurityEnabled")?"true":"false";' | tr -d '\r\n')"
check "active ip blocks match the table" "$(jnum wf_status blocking.active_ip_blocks)" \
  "$(dbq 'SELECT COUNT(*) FROM wp_wfblocks7 WHERE type IN (1,2,7,8,9) AND (expiration = 0 OR expiration > UNIX_TIMESTAMP())')"
check "blocks are counted by type" \
  "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
j=json.loads(t).get("blocking",{}).get("blocks_by_type")
print("yes" if isinstance(j,dict) else "no")' "" wf_status)" "yes"

echo "-- the false zero: 'no issues' and 'never scanned' are different answers --"
# Written as read-modify-restore rather than assuming the site starts with no scan recorded.
# The first version of this block asserted "absent to begin with" and was defeated by its own
# earlier run: the suite was killed part way through, after it had set lastScanCompleted and
# before it restored it, and the next run then reported the tool as broken on a site that was
# merely dirty. A precondition about the state of a third-party plugin is a precondition this
# suite cannot own, so the assertions move to states it creates and puts back.
ORIG_SCAN=$(wpc eval 'echo wfConfig::get("lastScanCompleted","__ABSENT__");' | tr -d '\r\n')
echo "  lastScanCompleted on entry: $ORIG_SCAN"
# The API is wfConfig::remove(), not delete(): measured on 9.0.2, delete() does not exist and
# throws, which under `>/dev/null` looked exactly like a fixture that had been cleaned up. The
# first version of this block used it and left lastScanCompleted='ok' behind, so the "no scan
# recorded" assertion failed against a tool that was reading the value correctly. Every
# fixture write below is now asserted rather than assumed.
wpc eval 'wfConfig::remove("lastScanCompleted");' >/dev/null 2>&1
check "control: the row was removed" \
  "$(wpc eval 'echo wfConfig::get("lastScanCompleted","__ABSENT__");' | tr -d '\r\n')" "__ABSENT__"
call wf_status '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wf_status","arguments":{}}}'
check "no completed scan is reported as never" "$(jget wf_status scanning.last_completed)" "never"
check "the reply says a zero means nothing has looked" \
  "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print("yes" if "nothing has looked" in t else "no")' "" wf_status)" "yes"
wpc eval 'wfConfig::set("lastScanCompleted","ok");' >/dev/null 2>&1
call wf_status '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wf_status","arguments":{}}}'
check "control: a recorded completion is reported as ok" "$(jget wf_status scanning.last_completed)" "ok"
# Put back whatever was there on entry, absent or not.
if [ "$ORIG_SCAN" = "__ABSENT__" ]; then
  wpc eval 'wfConfig::remove("lastScanCompleted");' >/dev/null 2>&1
else
  wpc eval 'wfConfig::set("lastScanCompleted","'"$ORIG_SCAN"'");' >/dev/null 2>&1
fi
check "put back: the stored value is what it was on entry" \
  "$(wpc eval 'echo wfConfig::get("lastScanCompleted","__ABSENT__");' | tr -d '\r\n')" "$ORIG_SCAN"

echo "-- issue counts come from the table --"
MARK="wfmark$(date +%s)"
check "issue total matches the table" "$(jnum wf_status scanning.issues.total)" \
  "$(dbq 'SELECT COUNT(*) FROM wp_wfissues')"
# Seeded through Wordfence's own writer, then deleted through its own deleter.
# Seeded through Wordfence's own writer, then deleted through its own deleter.
#
# The 3rd and 4th arguments are the issue's IDENTITY, not decoration: _addIssue() hashes
# them and returns ISSUE_DUPLICATE when a 'new' row already carries the same hash. The first
# version passed 0 for both, so every seeded issue hashed to md5('0'), the second seed was
# deduplicated against the first, and the suite reported the tool as not listing an issue that
# its own fixture had never inserted. Measured by reading _addIssue() after the control failed
# twice. A marker-derived identity also means a seed cannot be swallowed by a real finding on
# a site that already has some.
wpc eval 'wfIssues::shared()->addIssue("wfFileModified",50,"'"$MARK"'p","'"$MARK"'c","'"$MARK"' probe issue","long message that must not be returned",[],false);' >/dev/null 2>&1
check "control: the seeded issue is in the table" \
  "$(dbq "SELECT COUNT(*) FROM wp_wfissues WHERE shortMsg LIKE '%$MARK%'")" "1"
call wf_scan_issues '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"wf_scan_issues","arguments":{"limit":5}}}'
check "wf_scan_issues answers" "$(verdict wf_scan_issues)" "ok"
check "the seeded issue is listed" "$(has wf_scan_issues "$MARK")" "yes"
check "its short message is returned" "$(has wf_scan_issues "probe issue")" "yes"
check "its LONG message is not returned" "$(has wf_scan_issues "must not be returned")" "no"
check "the long message really is stored (control)" \
  "$(dbhas "SELECT longMsg FROM wp_wfissues" "must not be returned")" "yes"
check "data is named as withheld" "$(jget wf_scan_issues withheld.1)" "data"
check "the long message is named as withheld" "$(jget wf_scan_issues withheld.0)" "longmsg"
# The severity filter, which the first version of this suite never exercised: the column is a
# tinyint, so passing the word 'high' straight through matched severity-0 rows or nothing and
# read as "no findings at that level". 75 is wfIssues::SEVERITY_HIGH, measured.
wpc eval 'wfIssues::shared()->addIssue("wfFileModified",75,"'"$MARK"'p2","'"$MARK"'c2","'"$MARK"' high severity probe","x",[],false);' >/dev/null 2>&1
check "control: a severity-75 issue was seeded" \
  "$(dbq "SELECT COUNT(*) FROM wp_wfissues WHERE shortMsg LIKE '%high severity probe%' AND severity=75")" "1"
call wf_scan_issues '{"jsonrpc":"2.0","id":41,"method":"tools/call","params":{"name":"wf_scan_issues","arguments":{"severity":"high"}}}'
check "the severity filter matches by name" "$(has wf_scan_issues "high severity probe")" "yes"
check "and the severity comes back named" "$(jget wf_scan_issues issues.0.severity.name)" "high"
call wf_scan_issues '{"jsonrpc":"2.0","id":42,"method":"tools/call","params":{"name":"wf_scan_issues","arguments":{"severity":"bogus"}}}'
check "an unknown severity is refused rather than matched as 0" "$(verdict wf_scan_issues)" "error"
# The ids come from the table and the deletion goes through the plugin's own deleter. The
# first version looped over getIssues() and matched on shortMsg, which those rows do not carry
# under that name — measured, it returned rows without the key, so the loop deleted nothing
# and the suite reported the tool as leaving a fixture behind that was its own.
# One id per line, read without dbq(): dbq strips the newlines, so two seeded issues came back
# as one number ("1213"), deleteIssue() was called with an id that exists nowhere, and the
# fixture was left behind for the next run to count.
for issue_id in $(docker compose exec -T cli wp db query "SELECT id FROM wp_wfissues WHERE shortMsg LIKE '%$MARK%'" --skip-column-names 2>/dev/null | tr -d '\r'); do
  wpc eval 'wfIssues::shared()->deleteIssue( '"$issue_id"' );' >/dev/null 2>&1
done
check "put back: the seeded issue is gone" \
  "$(dbq "SELECT COUNT(*) FROM wp_wfissues WHERE shortMsg LIKE '%$MARK%'")" "0"

echo "-- lockouts: an address comes back as an address --"
# The defect this block exists to catch: wfBlocks7 stores the IP packed, so a reader that
# returns the column puts null bytes where an address belongs. Measured while writing this.
#
# Nothing here calls removeAllIPBlocks(): that wipes every real IP block on whatever stack runs
# the suite, and the first version did it twice, before and after. The fixture is removed by id
# instead, through Wordfence's own removeBlockIDs().
BEFORE_BLOCKS=$(dbq 'SELECT COUNT(*) FROM wp_wfblocks7')
wpc eval 'wfBlock::createLockout("'"$MARK"' probe lockout","203.0.113.77",3600);' >/dev/null 2>&1
LOCKOUT_ID=$(dbq "SELECT id FROM wp_wfblocks7 WHERE reason LIKE '%$MARK%' LIMIT 1")
case "$LOCKOUT_ID" in ''|*[!0-9]*) echo "Could not seed a lockout; the rest would report the tool as broken." >&2; exit 1 ;; esac
check "control: one block was added" "$(dbq 'SELECT COUNT(*) FROM wp_wfblocks7')" "$((BEFORE_BLOCKS + 1))"
call wf_lockouts '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"wf_lockouts","arguments":{"active_only":true}}}'
check "wf_lockouts answers" "$(verdict wf_lockouts)" "ok"
check "the address is printable, not packed bytes" "$(jget wf_lockouts lockouts.0.ip)" "203.0.113.77"
check "the block type is named" "$(jget wf_lockouts lockouts.0.type_name)" "lockout"
check "the lockout is reported active" "$(jget wf_lockouts lockouts.0.active)" "true"
check "the reason is returned" "$(has wf_lockouts "probe lockout")" "yes"
check "parameters are named as withheld" "$(jget wf_lockouts withheld.0)" "parameters"

# A country block names no single address, and its all-zero IP column comes out of inet_ntop
# as '::'. The tool must not list it as an address, and must count it where it belongs.
wpc eval 'wfBlock::createCountry("'"$MARK"' country block",true,false,["XX"]);' >/dev/null 2>&1
COUNTRY_SEEDED=$(dbq 'SELECT COUNT(*) FROM wp_wfblocks7 WHERE type=3')
if [ "${COUNTRY_SEEDED:-0}" -gt 0 ]; then
  call wf_lockouts '{"jsonrpc":"2.0","id":51,"method":"tools/call","params":{"name":"wf_lockouts","arguments":{}}}'
  check "a country block is not listed as an address" "$(has wf_lockouts "country block")" "no"
  call wf_status '{"jsonrpc":"2.0","id":52,"method":"tools/call","params":{"name":"wf_status","arguments":{}}}'
  check "but it is counted by type" \
    "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
j=json.loads(t).get("blocking",{}).get("blocks_by_type",{})
print("yes" if int(j.get("country",0)) >= 1 else "no")' "" wf_status)" "yes"
  for cid in $(dbq 'SELECT id FROM wp_wfblocks7 WHERE type=3'); do
    wpc eval 'wfBlock::removeBlockIDs( [ '"$cid"' ] );' >/dev/null 2>&1
  done
else
  echo "  ....  country-block check skipped: the fixture did not take"
fi
wpc eval 'wfBlock::removeBlockIDs( [ '"$LOCKOUT_ID"' ] );' >/dev/null 2>&1
check "put back: the block count is what it was" "$(dbq 'SELECT COUNT(*) FROM wp_wfblocks7')" "$BEFORE_BLOCKS"
call wf_lockouts '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"wf_lockouts","arguments":{"active_only":true}}}'
check "an empty list says so rather than looking like a failure" \
  "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print("yes" if "no lockout is currently in force" in t.lower() else "no")' "" wf_lockouts)" "yes"

echo "-- configuration secrets are never returned --"
# The control pair: a secret exists and is readable through Wordfence's own accessor, and it
# does not appear in the reply. Without the control, "not found" and "the probe is broken"
# look the same.
#
# Read-modify-restore, because apiKey is a real credential on a site that has configured
# Wordfence Central: a suite that removed it unconditionally would decommission a working
# install to prove a point about this one.
ORIG_APIKEY=$(wpc eval 'echo wfConfig::get("apiKey","__ABSENT__");' | tr -d '\r\n')
SECRET="CONTROLKEY-$(date +%s)"
wpc eval 'wfConfig::set("apiKey","'"$SECRET"'");' >/dev/null 2>&1
check "control: the secret is stored and readable through wfConfig" \
  "$(wpc eval 'echo wfConfig::get("apiKey");' | tr -d '\r\n')" "$SECRET"
call wf_status '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"wf_status","arguments":{}}}'
check "the api key does not appear in wf_status" "$(has wf_status "$SECRET")" "no"
# Restored by encoding rather than by interpolating the value into PHP source: an API key can
# contain a quote or a backslash, and an eval that breaks there fails silently under the
# redirect, leaving the site with a different key than it started with.
B64=$(printf '%s' "$ORIG_APIKEY" | base64 | tr -d '\n')
wpc eval '$v = base64_decode( "'"$B64"'" ); if ( $v === "__ABSENT__" ) { wfConfig::remove( "apiKey" ); } else { wfConfig::set( "apiKey", $v ); }' >/dev/null 2>&1
check "put back: the stored key is what it was on entry" \
  "$(wpc eval 'echo wfConfig::get("apiKey","__ABSENT__");' | tr -d '\r\n')" "$ORIG_APIKEY"

echo "-- the group is read only --"
# A read-level key must not be able to list addresses. Minted here rather than assumed, and
# the control is that the same call works with the admin key above.
RO=$(gmcp_make_key "smoke wordfence readonly" readonly)
case "$RO" in
  gmcp_*)
    curl -sS -X POST "$URL" -H "Authorization: Bearer $RO" \
      -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
      -d '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"wf_lockouts","arguments":{}}}' -o "$OUT/wf_ro"
    check "a read key cannot list lockouts" "$(verdict wf_ro)" "error"
    curl -sS -X POST "$URL" -H "Authorization: Bearer $RO" \
      -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
      -d '{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"wf_status","arguments":{}}}' -o "$OUT/wf_ro_status"
    check "a read key can still read the status" "$(verdict wf_ro_status)" "ok"
    ;;
  *) echo "  ....  read-level checks skipped: could not mint a read key" ;;
esac

group_off
echo
printf 'wordfence: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
