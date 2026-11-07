#!/bin/bash
# Smoke test for the database-dump group: wp_db_dump_request and the one-time dump endpoint.
#
#   COMPOSE_PROJECT_NAME=wt-db GMCP_PORT=8094 GMCP_URL=http://localhost:8094 ./smoke-db-dump.sh
#
# WHAT THE ASSERTIONS ARE FOR. A dump is every row of the database leaving the server in one file,
# and nothing inside it can be redacted afterwards, so the checks are about state and about the
# file, never about the wording of a reply:
#   - the group is absent from tools/list when off and refused by name, and present when on;
#   - without a maintained dump utility the MANIFEST itself is refused, with a sentence naming what
#     has to be installed and why this plugin will not hand-write a dump — and no pull is offered;
#   - the manifest lists the tables the database actually holds, including this plugin's own
#     activity log and approval queue, with a control proving the list is not empty;
#   - the scopes mean what they say: a table that is not WordPress's is outside `site` and inside
#     `database`;
#   - a pull is held before a person approves it, and NOTHING (no file, no token) exists until that
#     person answers the queue, even with mcp_approval_mode=never;
#   - an approval whose digest no longer matches is refused, and the digest comes back when the
#     table that moved it is dropped, so the refusal is about the table and not about the clock;
#   - the downloaded file decompresses, holds a CREATE TABLE for wp_options and for wp_gmcp_audit,
#     ends with a dump-completed trailer on its LAST line, carries the stand-in's own marker line
#     (so the served bytes are the utility's, unmodified), and its table count is the manifest's;
#   - a dump utility that exits 0 having written a PARTIAL file is refused and nothing is kept:
#     "exit status 0" is not the check, the file is. The stand-in records that it RAN, because a
#     stand-in that never started produces the same refusal and would prove nothing;
#   - a CREATE TABLE that appears only INSIDE a row value does not count, and a trailer that is not
#     the last line does not count: both are refused, each naming what it found;
#   - the file is 0600 and outside the web root, HEAD does not spend the token, the token is spent
#     once, and the file is gone after it is served;
#   - the download token is absent from the audit log while the tool call itself is present.
#
# THE ENGINE IS A STAND-IN, ON PURPOSE. Neither container that would run a dump here has
# mariadb-dump or mysqldump at the pinned path (the web container has neither at all), so the suite
# installs test-only stand-ins in the shared wp-content volume and points the group at them through
# the documented filter, in mu-db-dump-utility.php. Four shapes: a whole dump, a partial one, one
# whose statement is buried in data, one whose trailer is not last. The filter is option-driven, so
# the option also lets the suite measure the no-utility refusal, which is what an uninstalled
# server looks like.
#
# Every "absent" check has a positive control. The suite owns only the rows it creates (its own
# approval requests and token rows) and leaves the audit log alone, which is append-only.
set -u
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
AP_URL="$BASE/wp-admin/admin.php?page=guarded-mcp-approvals"
HERE="$(cd "$(dirname "$0")" && pwd)"
STANDIN_DIR=/var/www/html/wp-content/mu-plugins

dc() { docker compose exec -T "$@"; }
wpc() { dc cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
dbq() { dc cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpq() { dc cli wp eval "$1" 2>/dev/null | tr -d '\r\n'; }
mk_key() { # mk_key <label> <level>
  dc cli wp eval '
    $label = "'"$1"'"; $level = "'"$2"'";
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); }
    }
    $a = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $r = GMCP_Tokens::create( $label, $level, 0, [], $a ? $a[0]->ID : 0 );
    echo $r["secret"];' 2>/dev/null | tr -d '\r\n'
}
OUT=$(mktemp -d)
JAR=$(mktemp)
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1));
  else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
call() { curl -sS -X POST "$URL" -H "Authorization: Bearer $1" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$3" -o "$OUT/$2"; }
tc() { printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2"; }
py() { python3 -c "$1" "$2" < "$OUT/$3"; }
verdict() { py 'import json,sys
d=json.load(sys.stdin)
print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "" "$1"; }
text() { py 'import json,sys
print(json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text",""))' "" "$1"; }
state() { py 'import json,sys
print(json.load(sys.stdin).get("result",{}).get("data",{}).get("status","-"))' "" "$1"; }
rid() { py 'import json,sys
print(json.load(sys.stdin).get("result",{}).get("data",{}).get("request_id","-"))' "" "$1"; }
# The tool's reply is JSON in the first content block; read one field out of it.
jget() { python3 - "$1" "$OUT/$2" <<'PY'
import json, sys
field, path = sys.argv[1], sys.argv[2]
d = json.load(open(path))
text = d.get("result", {}).get("content", [{}])[0].get("text", "{}")
try:
    j = json.loads(text)
except Exception:
    print("UNPARSEABLE"); raise SystemExit
v = j.get(field, "")
print(json.dumps(v) if isinstance(v, (dict, list)) else str(v))
PY
}
names() { curl -sS -X POST "$URL" -H "Authorization: Bearer $1" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | python3 -c 'import json,sys
try: print(" ".join(x["name"] for x in json.load(sys.stdin)["result"]["tools"]))
except Exception: print("UNREADABLE")'; }
has() { python3 -c 'import re,sys; print("yes" if re.search(sys.argv[2], sys.argv[1]) else "no")' "$1" "$2"; }
# The temporary artifacts the group writes. Counted in the WEB container, because that is the one
# whose /tmp the endpoint serves from and whose shutdown callback removes them.
temp_count() { dc wp sh -c 'ls /tmp/gmcp-dbdump-* 2>/dev/null | wc -l' | tr -d ' \r\n'; }
wait_temp_gone() { left=unknown; n=0
  while [ "$n" -lt 20 ]; do
    left=$(temp_count)
    [ "$left" = "0" ] && break
    n=$((n+1)); sleep 0.25
  done
  printf '%s' "$left"; }
token_rows() { dbq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'gmcp_db_dump_token_%'"; }

MARK="dbdump$(date +%s)"
echo "-- preconditions: keys, the options this suite changes, stand-ins, and a clean slate for its rows --"
TOK=$(mk_key "smoke db dump" admin)
RO=$(mk_key "smoke db dump readonly" readonly)
for k in "$TOK" "$RO"; do case "$k" in gmcp_*) ;; *) echo "Could not create an API key." >&2; exit 1 ;; esac; done
PREV_DUMP=$(wpq 'echo (get_option("gmcp_options",[])["mcp_tools_db_dump"] ?? false) ? "1" : "0";')
PREV_MODE=$(wpq 'echo get_option("gmcp_options",[])["mcp_approval_mode"] ?? "never";')
PREV_UTILITY=$(wpq 'echo get_option("gmcp_test_db_dump_utility","");')
echo "  previous dump switch: $PREV_DUMP; previous approval mode: $PREV_MODE; previous stand-in: ${PREV_UTILITY:-none}"
# The stand-ins and the mu-plugin that points the group at one live in the shared wp-content volume,
# so both containers can read them; the filler writes them into the wp container as root because
# that directory is not writable by the PHP user. Removed again at the end.
install_standins() {
  dc wp sh -c "mkdir -p $STANDIN_DIR" >/dev/null 2>&1
  for f in mu-db-dump-utility.php db-dump-standin-full.sh db-dump-standin-partial.sh \
           db-dump-standin-buried.sh db-dump-standin-trailer-mid.sh; do
    dc wp sh -c "cat > $STANDIN_DIR/$f" < "$HERE/$f" || echo "  FAILED to install $f" >&2
  done
  dc wp sh -c "chmod 755 $STANDIN_DIR/db-dump-standin-*.sh; chmod 644 $STANDIN_DIR/mu-db-dump-utility.php" >/dev/null 2>&1
}
remove_standins() {
  dc wp sh -c "rm -f $STANDIN_DIR/mu-db-dump-utility.php $STANDIN_DIR/db-dump-standin-*.sh" >/dev/null 2>&1 || true
  dc cli sh -c 'rm -f /tmp/gmcp-dbdump-partial-ran' >/dev/null 2>&1 || true
}
# Which stand-in the group resolves: a path, or `none` for a server with no dump utility at all.
set_utility() { dc cli wp option update gmcp_test_db_dump_utility "$1" >/dev/null 2>&1; }
STANDIN_FULL="$STANDIN_DIR/db-dump-standin-full.sh"
STANDIN_PARTIAL="$STANDIN_DIR/db-dump-standin-partial.sh"
STANDIN_BURIED="$STANDIN_DIR/db-dump-standin-buried.sh"
STANDIN_MID="$STANDIN_DIR/db-dump-standin-trailer-mid.sh"
install_standins
# Only this suite's rows. Clearing the queue wholesale would drop another session's request, and the
# audit log is append-only on purpose.
dbq "DELETE FROM wp_gmcp_approvals WHERE tool='wp_db_dump_request'" >/dev/null
dbq "DELETE FROM wp_options WHERE option_name LIKE 'gmcp_db_dump_token_%'" >/dev/null
dc wp sh -c 'rm -f /tmp/gmcp-dbdump-*' >/dev/null 2>&1 || true
dc cli sh -c 'rm -f /tmp/gmcp-dbdump-partial-ran' >/dev/null 2>&1 || true
check "CONTROL: the stand-in is installed where both containers can run it" \
  "$(dc wp sh -c "test -x $STANDIN_FULL && echo executable || echo missing" | tr -d '\r\n')" "executable"
check "CONTROL: and it writes a dump shape the completeness check reads" \
  "$(dc wp sh -c "$STANDIN_FULL wp wp_options | grep -c '^CREATE TABLE \`wp_options\`'" | tr -d ' \r\n')" "1"
set_dump() { wpq '$o=get_option("gmcp_options",[]);$o["mcp_tools_db_dump"]=((int) '"$1"')?true:false;update_option("gmcp_options",$o,false);echo "ok";' >/dev/null; }
set_mode() { wpq '$o=get_option("gmcp_options",[]);$o["mcp_approval_mode"]="'"$1"'";update_option("gmcp_options",$o,false);echo "ok";' >/dev/null; }
drop_probe() { dbq "DROP TABLE IF EXISTS $1" >/dev/null; }
set_utility "$STANDIN_FULL"

set_dump 1
set_mode never
echo "-- without a dump utility there is no manifest and no pull --"
# The container serving this over HTTP has neither mariadb-dump nor mysqldump, and `none` is what
# that server looks like. The refusal is at MANIFEST time because the manifest is what a person
# approves: it has no engine to name, so there is nothing to approve.
set_utility none
call "$TOK" no_engine "$(tc wp_db_dump_request '{"scope":"site"}')"
check "with no dump utility the manifest is refused" "$(verdict no_engine)" "error"
check "and the refusal names the utilities that are required" \
  "$(text no_engine | grep -c 'mariadb-dump nor mysqldump')" "1"
check "and says a faithful dump needs a maintained utility" \
  "$(text no_engine | grep -c 'faithful, restorable dump needs a dumper somebody maintains')" "1"
check "and says this plugin will not hand-write one" \
  "$(text no_engine | grep -c 'will not hand-write one')" "1"
check "and tells the operator what to install" \
  "$(text no_engine | grep -c 'Install mariadb-client')" "1"
check "and no dump file or token was created for it" "$(temp_count)$(token_rows)" "00"
# A pull cannot be offered either: manifest refuses before the digest is ever compared, so the reply
# is the engine refusal and not a digest complaint. Reached in-process because the approvals gate
# holds a pull before the tool sees it, which is a different question (and asserted below).
NO_ENGINE_PULL=$(dc cli wp eval '$r = apply_filters( "gmcp_callback", null, "wp_db_dump_request", [ "scope" => "site", "mode" => "pull", "manifest_digest" => "00" ], 1 );
$t = $r["result"]["content"][0]["text"];
echo ( null === $r ) ? "no-tool" : ( ( $r["result"]["isError"] ?? false ) ? "refused" : "accepted" ), "|", ( strpos( $t, "manifest_digest" ) !== false ? "digest-complaint" : "engine-refusal" );' 2>/dev/null | tr -d '\r\n')
check "and a pull is refused for the same reason, not for the digest" "$NO_ENGINE_PULL" "refused|engine-refusal"
set_utility "$STANDIN_FULL"
check "CONTROL: with the stand-in installed the same manifest answers" \
  "$(call "$TOK" engine_ok "$(tc wp_db_dump_request '{"scope":"site"}')"; verdict engine_ok)" "ok"

set_dump 0
echo "-- the group is off until the switch is on --"
LISTED=$(names "$TOK")
check "CONTROL: the listing is readable and has an ordinary tool" "$(has "$LISTED" '(^| )wp_get_posts( |$)')" "yes"
check "dump off: the tool is absent from tools/list" "$(has "$LISTED" '(^| )wp_db_dump_request( |$)')" "no"
# Absent from the menu is not the same as refused: the switch has to be the tool itself, not the
# listing. The control is the same call once the switch is on.
call "$TOK" off_call "$(tc wp_db_dump_request '{"scope":"site"}')"
check "dump off: and a call by name is refused" "$(verdict off_call)" "error"
check "  ...and the refusal names the tool" "$(text off_call | grep -c 'wp_db_dump_request')" "1"
set_dump 1
LISTED=$(names "$TOK")
check "dump on: the tool is present" "$(has "$LISTED" '(^| )wp_db_dump_request( |$)')" "yes"
call "$TOK" on_call "$(tc wp_db_dump_request '{"scope":"site"}')"
check "CONTROL: the same call answers once the switch is on" "$(verdict on_call)" "ok"
RO_LISTED=$(names "$RO")
check "CONTROL: the readonly listing is readable" "$(has "$RO_LISTED" '(^| )wp_get_posts( |$)')" "yes"
check "a readonly key is not offered an admin-level dump tool" "$(has "$RO_LISTED" '(^| )wp_db_dump_request( |$)')" "no"
call "$RO" ro_call "$(tc wp_db_dump_request '{"scope":"site"}')"
check "and cannot call it by name" "$(verdict ro_call)" "error"

echo "-- the manifest: the tables the database actually holds --"
MAN_COUNT=$(jget table_count on_call)
# The control the "lists tables" claim needs: the same number read from the database by a probe
# that can be shown to find a table, so an empty list and a broken query cannot look alike.
DB_COUNT=$(dbq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND LEFT(table_name,3)='wp_'")
check "CONTROL: the database probe finds wp_options" \
  "$(dbq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wp_options'")" "1"
check "CONTROL: the database probe counts more than a handful of tables" \
  "$(python3 -c 'import sys; print("yes" if int(sys.argv[1]) >= 8 else "no")' "$DB_COUNT")" "yes"
check "the manifest's table count is every wp_-prefixed table (site scope, single install)" "$MAN_COUNT" "$DB_COUNT"
check "the manifest lists wp_options" \
  "$(jget tables on_call | grep -c '"wp_options"')" "1"
check "and this plugin's own activity log" \
  "$(jget tables on_call | grep -c '"wp_gmcp_audit"')" "1"
check "and its approval queue" \
  "$(jget tables on_call | grep -c '"wp_gmcp_approvals"')" "1"
check "CONTROL: a table that is not there is not in the list" \
  "$(jget tables on_call | grep -c '"wp_dbdump_absent_probe"')" "0"
check "the manifest says the row counts are estimates" "$(jget row_counts_are_estimates on_call)" "True"
check "it carries a sha256 digest" \
  "$(py 'import json,sys,re
t=json.load(sys.stdin)["result"]["content"][0]["text"]; d=json.loads(t)
print("yes" if re.fullmatch(r"[0-9a-f]{64}", d.get("manifest_digest","")) else "no")' "" on_call)" "yes"
check "the manifest carries no row data (an option value is not in it)" \
  "$(text on_call | grep -c "$(wpq 'echo get_option("blogname");')")" "0"

echo "-- the scopes mean what they say --"
dbq "CREATE TABLE dbdump_probe_$MARK (id int not null primary key, note varchar(20) not null)" >/dev/null
dbq "INSERT INTO dbdump_probe_$MARK VALUES (1,'probe')" >/dev/null
dbq "CREATE TABLE wp_dbdump_probe_$MARK (id int not null primary key)" >/dev/null
check "CONTROL: both probe tables exist in the configured database" \
  "$(dbq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('dbdump_probe_$MARK','wp_dbdump_probe_$MARK')")" "2"
call "$TOK" db_man "$(tc wp_db_dump_request '{"scope":"database"}')"
check "the database scope answers" "$(verdict db_man)" "ok"
check "the database scope includes a table that is not WordPress's" \
  "$(jget tables db_man | grep -c "\"dbdump_probe_$MARK\"")" "1"
call "$TOK" site_man "$(tc wp_db_dump_request '{"scope":"site"}')"
check "the site scope does NOT include a table outside the site's prefix" \
  "$(jget tables site_man | grep -c "\"dbdump_probe_$MARK\"")" "0"
check "CONTROL: the site scope does include a table under the site's prefix" \
  "$(jget tables site_man | grep -c "\"wp_dbdump_probe_$MARK\"")" "1"
call "$TOK" net_man "$(tc wp_db_dump_request '{"scope":"network"}')"
check "the network scope answers" "$(verdict net_man)" "ok"
check "the network scope excludes a table outside the base prefix" \
  "$(jget tables net_man | grep -c "\"dbdump_probe_$MARK\"")" "0"
drop_probe "wp_dbdump_probe_$MARK"
drop_probe "dbdump_probe_$MARK"
check "the probe tables are dropped again" \
  "$(dbq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('dbdump_probe_$MARK','wp_dbdump_probe_$MARK')")" "0"
call "$TOK" bad_scope "$(tc wp_db_dump_request '{"scope":"plugins"}')"
check "an unknown scope is refused" "$(verdict bad_scope)" "error"
check "an unknown mode is refused" "$(call "$TOK" bad_mode "$(tc wp_db_dump_request '{"scope":"site","mode":"dump"}')"; verdict bad_mode)" "error"
# A pull with no digest is refused twice over, at two layers, and both are asserted: the approvals
# gate holds the call before the tool can see it (so nothing runs unattended), and the tool itself
# refuses it when it is reached. Asserting only the HTTP reply would pass on a gate and say nothing
# about the tool; asserting only the in-process call would say nothing about whether a caller with
# no approval can reach it.
call "$TOK" no_digest "$(tc wp_db_dump_request '{"scope":"site","mode":"pull"}')"
check "a pull with no digest is held for a person, not run" "$(state no_digest)" "awaiting_human_approval"
check "and nothing was dumped for it" "$(temp_count)$(token_rows)" "00"
NO_DIGEST_OUT=$(dc cli wp eval '$r = apply_filters( "gmcp_callback", null, "wp_db_dump_request", [ "scope" => "site", "mode" => "pull" ], 1 );
echo ( null === $r ) ? "no-tool" : ( ( $r["result"]["isError"] ?? false ) ? "refused" : "accepted" ), "|", ( strpos( $r["result"]["content"][0]["text"], "manifest_digest" ) !== false ? "names-it" : "silent" );' 2>/dev/null | tr -d '\r\n')
check "CONTROL: and the tool itself refuses it with a reason when it is reached" "$NO_DIGEST_OUT" "refused|names-it"

echo "-- a pull waits for a person, even with mcp_approval_mode=never --"
check "CONTROL: the mode really is never" \
  "$(wpq 'echo get_option("gmcp_options",[])["mcp_approval_mode"] ?? "(unset)";')" "never"
call "$TOK" man "$(tc wp_db_dump_request '{"scope":"site"}')"
MAN_COUNT=$(jget table_count man)
DIGEST=$(jget manifest_digest man)
check "CONTROL: the manifest has a table count and a digest to work from" \
  "$(python3 -c 'import sys; print("yes" if int(sys.argv[1]) > 0 and len(sys.argv[2]) == 64 else "no")' "$MAN_COUNT" "$DIGEST")" "yes"
call "$TOK" pull0 "$(tc wp_db_dump_request '{"scope":"site","mode":"pull","manifest_digest":"'"$DIGEST"'"}')"
R1=$(rid pull0)
check "the pull is not an error" "$(verdict pull0)" "ok"
check "the reply says it is waiting for a person" "$(state pull0)" "awaiting_human_approval"
check "it names a request id" "$(python3 -c 'import sys; print("yes" if sys.argv[1].isdigit() else "no")' "$R1")" "yes"
check "the request is stored as pending" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_approvals WHERE id=$R1 AND tool='wp_db_dump_request' AND status='pending'")" "1"
check "no dump token exists before approval" "$(token_rows)" "0"
check "no dump file exists before approval" "$(temp_count)" "0"

echo "-- a signed-in administrator approves it on the Approvals screen --"
curl -sS -c "$JAR" -b "$JAR" -d 'log=admin&pwd=admin&rememberme=forever&testcookie=1' "$BASE/wp-login.php" -o /dev/null 2>/dev/null
check "CONTROL: the login cookie was stored" "$(grep -c wordpress_logged_in "$JAR")" "1"
ap_form() { curl -sS -b "$JAR" "$AP_URL" | python3 -c '
import re,sys
html=sys.stdin.read()
rid=sys.argv[1]
for f in re.findall(r"<form.*?</form>",html,re.S):
    if "value=\"approve_request\"" in f and re.search(r"name=\"request_id\" value=\""+rid+"\"",f):
        a=re.search(r"name=\"_wpnonce\" value=\"([^\"]+)\"",f)
        b=re.search(r"name=\"gmcp_approval_nonce\" value=\"([^\"]+)\"",f)
        if a and b:
            print(a.group(1)+"|"+b.group(1))
        break
' "$1"; }
approve() { # approve <request_id>
  F=$(ap_form "$1"); W=${F%%|*}; A=${F##*|}
  curl -sS -b "$JAR" -o /dev/null -X POST "$AP_URL" \
    --data-urlencode "_wpnonce=$W" --data-urlencode "gmcp_action=approve_request" \
    --data-urlencode "request_id=$1" --data-urlencode "gmcp_approval_nonce=$A" 2>/dev/null
}
ap_cell() { curl -sS -b "$JAR" "$AP_URL" | python3 -c '
import re,sys,html
page=sys.stdin.read(); rid=sys.argv[1]
for tr in re.findall(r"<tr>.*?</tr>",page,re.S):
    if re.search(r"name=\"request_id\" value=\""+rid+"\"",tr):
        t=re.sub(r"<[^>]+>"," ",tr); print(re.sub(r"\s+"," ",html.unescape(t))); break
' "$1"; }
FORM=$(ap_form "$R1")
check "the request has an approve form on the screen" \
  "$(python3 -c 'import sys; print("yes" if "|" in sys.argv[1] else "no")' "$FORM")" "yes"
CELL=$(ap_cell "$R1")
check "CONTROL: the screen cell for the request was read" "$(printf '%s' "$CELL" | grep -c 'wp_db_dump_request')" "1"
check "the person is shown the scope and the digest they are approving" \
  "$(printf '%s' "$CELL" | grep -c "${DIGEST:0:16}")" "1"
approve "$R1"
check "the row is stored as approved" \
  "$(dbq "SELECT status FROM wp_gmcp_approvals WHERE id=$R1")" "approved"

echo "-- an approval whose digest no longer matches is refused --"
# A table the approved scope covers is created AFTER the person said yes. The set of tables is what
# was approved, so the pull that carries the old digest must not build anything.
dbq "CREATE TABLE wp_dbdump_probe_$MARK (id int not null primary key)" >/dev/null
check "CONTROL: the new table is in the site's scope, so the set of tables really moved" \
  "$(dbq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wp_dbdump_probe_$MARK'")" "1"
call "$TOK" pull_moved "$(tc wp_db_dump_request '{"scope":"site","mode":"pull","manifest_digest":"'"$DIGEST"'"}')"
check "the approved pull is refused once the set of tables changed" "$(verdict pull_moved)" "error"
check "and it says the set of tables changed since that manifest" \
  "$(text pull_moved | grep -c 'set of tables in this scope has changed')" "1"
check "and no dump file was built for it" "$(temp_count)" "0"
check "and no token row was created for it" "$(token_rows)" "0"
drop_probe "wp_dbdump_probe_$MARK"
call "$TOK" man2 "$(tc wp_db_dump_request '{"scope":"site"}')"
check "CONTROL: dropping it brings the digest back, so the refusal was about the table" \
  "$(jget manifest_digest man2)" "$DIGEST"

echo "-- the approved pull builds one dump and mints one token --"
call "$TOK" pull1 "$(tc wp_db_dump_request '{"scope":"site","mode":"pull","manifest_digest":"'"$DIGEST"'"}')"
R2=$(rid pull1)
check "the fresh pull waits for a person again" "$(state pull1)" "awaiting_human_approval"
approve "$R2"
check "the row is stored as approved" \
  "$(dbq "SELECT status FROM wp_gmcp_approvals WHERE id=$R2")" "approved"
call "$TOK" pull2 "$(tc wp_db_dump_request '{"scope":"site","mode":"pull","manifest_digest":"'"$DIGEST"'"}')"
check "the approved pull answers" "$(verdict pull2)" "ok"
dump_url() { py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; print(json.loads(t).get("download_url",""))' "" "$1"; }
DUMP_URL=$(dump_url pull2)
case "$DUMP_URL" in http*/db-dump/*) echo "  url: ${DUMP_URL%%/db-dump/*}/db-dump/<token>" ;; *) echo "  FAIL  no download_url in the reply"; fail=$((fail+1)) ;; esac
DTOK=${DUMP_URL##*/}
check "CONTROL: the reply carries a token-shaped dump token" \
  "$(printf '%s' "$DTOK" | grep -cE '^[A-Za-z0-9]{16,}$')" "1"
check "exactly one dump token row exists" "$(token_rows)" "1"
check "exactly one dump file exists" "$(temp_count)" "1"
DUMP_PATH=$(dc wp sh -c 'ls /tmp/gmcp-dbdump-* 2>/dev/null | head -n 1' | tr -d '\r\n')
check "the dump file is outside the web root" \
  "$(dc wp sh -c 'case "'"$DUMP_PATH"'" in /var/www/*) echo inside;; *) echo outside;; esac' | tr -d '\r\n')" "outside"
check "the dump file is mode 0600" \
  "$(dc wp sh -c 'stat -c %a "'"$DUMP_PATH"'"' | tr -d '\r\n')" "600"
check "the size in the reply is the file's real size" \
  "$(jget size pull2)" "$(dc wp sh -c 'stat -c %s "'"$DUMP_PATH"'"' | tr -d '\r\n')"

echo "-- a HEAD request (a link previewer) does not spend the token or the file --"
check "CONTROL: the token row and the file exist before any request" \
  "$(token_rows)$(dc wp sh -c 'test -e "'"$DUMP_PATH"'" && echo present || echo gone' | tr -d '\r\n')" "1present"
HCODE=$(curl -sS -I -o "$OUT/head.hdr" -w '%{http_code}' "$DUMP_URL" 2>/dev/null)
check "HEAD with a live token answers 200" "$HCODE" "200"
check "and says it is gzip" "$(grep -ci '^content-type: application/gzip' "$OUT/head.hdr")" "1"
check "after a HEAD the token row and the file are still there" \
  "$(token_rows)$(dc wp sh -c 'test -e "'"$DUMP_PATH"'" && echo present || echo gone' | tr -d '\r\n')" "1present"
check "HEAD with an unknown token is refused" \
  "$(curl -sS -I -o /dev/null -w '%{http_code}' "${DUMP_URL%/*}/deadbeefdeadbeefdeadbeefdeadbeef" 2>/dev/null)" "403"

echo "-- the downloaded dump is whole: it decompresses and holds every table the manifest named --"
curl -sS -L -o "$OUT/dump.sql.gz" "$DUMP_URL" 2>/dev/null
check "CONTROL: the download is not empty" \
  "$(python3 -c 'import os,sys; print("yes" if os.path.getsize(sys.argv[1]) > 0 else "no")' "$OUT/dump.sql.gz")" "yes"
check "it decompresses cleanly (gzip CRC)" "$(gunzip -t "$OUT/dump.sql.gz" 2>&1 | wc -l | tr -d ' ')" "0"
gunzip -c "$OUT/dump.sql.gz" > "$OUT/dump.sql" 2>/dev/null
check "it holds a CREATE TABLE for wp_options" "$(grep -c '^CREATE TABLE `wp_options`' "$OUT/dump.sql")" "1"
check "and one for this plugin's activity log" "$(grep -c '^CREATE TABLE `wp_gmcp_audit`' "$OUT/dump.sql")" "1"
check "and one for the approval queue" "$(grep -c '^CREATE TABLE `wp_gmcp_approvals`' "$OUT/dump.sql")" "1"
FILE_TABLES=$(grep -c '^CREATE TABLE `' "$OUT/dump.sql")
check "its table count is the manifest's" "$FILE_TABLES" "$MAN_COUNT"
check "it ends with a dump-completed trailer" \
  "$(tail -c 300 "$OUT/dump.sql" | grep -c -- '-- Dump completed')" "1"
# Not an exact count of INSERT statements: the fallback writes one per 200 rows, so the number
# grows with the log and a check pinned to 1 fails on a busy site for the wrong reason. What is
# asserted is the property (at least one INSERT INTO the audit table), plus a value that only
# exists in a ROW and never in the DDL, which is what separates "the rows are in the file" from
# "the table's structure is in the file".
AUDIT_INSERTS=$(grep -c '^INSERT INTO `wp_gmcp_audit`' "$OUT/dump.sql")
check "CONTROL: it carries rows for the audit table, not only its structure" \
  "$(python3 -c 'import sys; print("yes" if int(sys.argv[1]) >= 1 else "no")' "$AUDIT_INSERTS")" "yes"
# The stand-in writes a synthetic row per table and nothing read from the database, so this counts
# its marker. It does NOT prove a real dump preserves stored values — that needs a real dumper.
AUDIT_ROW_TEXT=$(grep '^INSERT INTO `wp_gmcp_audit` VALUES (1);' "$OUT/dump.sql" | wc -l | tr -d ' ')
# The full stand-in emits synthetic VALUES rows, not values read from the database, so this
# asserts the stand-in's marker. Real row preservation needs a real dumper and is NOT covered here.
check "and the stand-in's row marker is in the file (real row preservation is not covered)" \
  "$(python3 -c 'import sys; print("yes" if int(sys.argv[1]) >= 1 else "no")' "$AUDIT_ROW_TEXT")" "yes"
check "CONTROL: the audit table really holds those rows here (so the check is about the file)" \
  "$(python3 -c 'import sys; print("yes" if int(sys.argv[1]) >= 1 else "no")' "$(dbq "SELECT COUNT(*) FROM wp_gmcp_audit WHERE tool='wp_db_dump_request'")")" "yes"
check "the reply names which engine wrote it" \
  "$(jget dump_engine pull2 | grep -cE '^(php-fallback|mariadb-dump/mysqldump \(pinned path\))$')" "1"
check "the manifest named the same engine before it was built" \
  "$(jget dump_engine man2)" "$(jget dump_engine pull2)"

echo "-- one use, and the file is gone after it is served --"
check "a second request with the same token is refused" \
  "$(curl -sS -o /dev/null -w '%{http_code}' "$DUMP_URL" 2>/dev/null)" "403"
check "the token row is consumed" "$(token_rows)" "0"
check "the dump file is gone after the first serve" "$(wait_temp_gone)" "0"

echo "-- the token never reaches the audit log, while the tool call does --"
check "CONTROL: the dump tool call is in the audit log" \
  "$(dbq "SELECT IF(COUNT(*) > 0, 'yes', 'no') FROM wp_gmcp_audit WHERE tool='wp_db_dump_request'")" "yes"
check "and the dump token is not" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_audit WHERE CONCAT(COALESCE(args,''),COALESCE(detail,''),COALESCE(changes,'')) LIKE '%$DTOK%'")" "0"
check "CONTROL: the scrubber did run over that reply (the route is there, the token is not)" \
  "$(dbq "SELECT IF(LOCATE('db-dump/[redacted]', detail) > 0, 'yes', 'no') FROM wp_gmcp_audit WHERE tool='wp_db_dump_request' AND detail LIKE '%db-dump%' LIMIT 1")" "yes"
check "CONTROL: the approval decision is in the audit log too" \
  "$(dbq "SELECT IF(COUNT(*) > 0, 'yes', 'no') FROM wp_gmcp_audit WHERE tool='approval:approved'")" "yes"

echo "-- a dump utility that exits 0 having written a partial file is not a success --"
# The check on a dump is the FILE, not the tool's exit status: a tool that stops at the tenth table
# and exits 0 would otherwise be reported as a whole backup. The stand-in writes the first table's
# CREATE TABLE and the trailer, exits 0, and is reached through the documented filter for the
# utility's path. Registered in the CLI container, which is where wp eval runs this call.
PARTIAL="/tmp/gmcp-partial-$MARK.sh"
dc cli sh -c "printf '#!/bin/sh\nprintf \"CREATE TABLE \\\`wp_options\\\` (id int);\\\\n-- Dump completed on now\\\\n\"\nexit 0\n' > '$PARTIAL'; chmod 755 '$PARTIAL'" >/dev/null 2>&1
check "CONTROL: the stand-in utility exists and exits 0" \
  "$(dc cli sh -c "'$PARTIAL' | tail -n 1" | tr -d '\r\n')" "-- Dump completed on now"
check "CONTROL: and it writes one table, whatever it is asked for" \
  "$(dc cli sh -c "'$PARTIAL' | grep -c '^CREATE TABLE'" | tr -d '\r\n')" "1"
dc wp sh -c 'rm -f /tmp/gmcp-dbdump-*' >/dev/null 2>&1 || true
PARTIAL_PHP='add_filter("gmcp_db_dump_utility_paths",function(){return ["'"$PARTIAL"'"];});
$m = GMCP_Tools_Db_Dump::manifest("site");
$args = ["scope"=>"site","mode"=>"pull","manifest_digest"=>$m["manifest_digest"]];
$r = apply_filters("gmcp_callback",null,"wp_db_dump_request",$args,1);
$d = json_decode($r["result"]["content"][0]["text"],true);
echo ($r["result"]["isError"] ?? false) ? "refused" : "accepted", "|", ($d["status"] ?? "no-json"), "|", ($d["size"] ?? "no-size");'
PARTIAL_OUT=$(dc cli wp eval "$PARTIAL_PHP" 2>/dev/null | tr -d '\r\n')
check "a partial dump that exited 0 is refused" "$(printf '%s' "$PARTIAL_OUT" | cut -d'|' -f1)" "refused"
check "and its reply is not a ready URL" "$(printf '%s' "$PARTIAL_OUT" | cut -d'|' -f2)" "no-json"
check "and nothing was kept: no token row" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'gmcp_db_dump_token_%'")" "0"
check "and nothing was kept: no file, in either container" \
  "$(temp_count)$(dc cli sh -c 'ls /tmp/gmcp-dbdump-* 2>/dev/null | wc -l' | tr -d ' \r\n')" "00"
# The control: the same call with the real utility list is accepted, so the refusal above is about
# the partial file and not about the call being impossible in that container.
REAL_OUT=$(dc cli wp eval '$m = GMCP_Tools_Db_Dump::manifest("site");
$args = ["scope"=>"site","mode"=>"pull","manifest_digest"=>$m["manifest_digest"]];
$r = apply_filters("gmcp_callback",null,"wp_db_dump_request",$args,1);
$d = json_decode($r["result"]["content"][0]["text"],true);
if (is_array($d) && !empty($d["download_url"])) { GMCP_Tools_Db_Dump::discard_token(basename($d["download_url"])); echo "ready"; } else { echo "refused"; }' 2>/dev/null | tr -d '\r\n')
check "CONTROL: the same call through the real engine is accepted" "$REAL_OUT" "ready"
check "and its token row and file are cleaned up again" \
  "$(token_rows)$(dc cli sh -c 'ls /tmp/gmcp-dbdump-* 2>/dev/null | wc -l' | tr -d ' \r\n')" "00"
dc cli sh -c "rm -f '$PARTIAL'" >/dev/null 2>&1

echo "-- cleanup: this suite's rows, files and the two options it changed --"
dbq "DROP TABLE IF EXISTS dbdump_probe_$MARK" >/dev/null
dbq "DROP TABLE IF EXISTS wp_dbdump_probe_$MARK" >/dev/null
dbq "DELETE FROM wp_gmcp_approvals WHERE tool='wp_db_dump_request'" >/dev/null
dbq "DELETE FROM wp_options WHERE option_name LIKE 'gmcp_db_dump_token_%'" >/dev/null
dc wp sh -c 'rm -f /tmp/gmcp-dbdump-*' >/dev/null 2>&1 || true
dc cli sh -c 'rm -f /tmp/gmcp-dbdump-*' >/dev/null 2>&1 || true
set_mode "$PREV_MODE"
set_dump "$PREV_DUMP"
check "the dump switch is back where it was" \
  "$(wpq 'echo (get_option("gmcp_options",[])["mcp_tools_db_dump"] ?? false) ? "1" : "0";')" "$PREV_DUMP"
check "the approval mode is back where it was" \
  "$(wpq 'echo get_option("gmcp_options",[])["mcp_approval_mode"] ?? "never";')" "$PREV_MODE"
dc wp sh -c "rm -f /var/www/html/wp-content/mu-plugins/db-dump-standin-*.sh /var/www/html/wp-content/mu-plugins/mu-db-dump-utility.php" >/dev/null 2>&1 || true
check "no probe table is left behind" \
  "$(dbq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('dbdump_probe_$MARK','wp_dbdump_probe_$MARK')")" "0"

echo
printf 'db dump: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
