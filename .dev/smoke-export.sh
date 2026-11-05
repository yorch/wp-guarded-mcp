#!/bin/bash
# Smoke test for the site-file export group: wp_export_request and the one-time archive endpoint.
#
#   ./smoke-export.sh
#
# WHAT THE ASSERTIONS ARE FOR. A manifest is a read of the filesystem and an archive is the
# whole of a directory leaving the server, so the checks below are about state rather than
# about replies:
#   - the group is absent from tools/list when off, and present when on;
#   - the manifest refuses over its byte cap instead of returning a truncated count;
#   - a symbolic link inside the root is excluded and named, not followed;
#   - a pull is held before a person approves it, and NOTHING (no archive, no token) exists
#     until that person answers the queue, even with mcp_approval_mode=never;
#   - the approved archive contains the fixture through WordPress's own media writer, and
#     does not contain the symlink target;
#   - the archive is a 0600 file outside the web root, is served once, and is gone after it
#     is served;
#   - the manifest digest covers file CONTENTS, so an equal-length rewrite inside the same
#     second still changes it and an approval taken before that rewrite is refused at pull
#     time rather than archiving bytes nobody approved;
#   - an archive whose download the client abandons is removed then, not left for a sweep;
#   - the export token is absent from the audit log while the tool call itself is present.
#
# Every "absent" check has a positive control. Fixtures go through WordPress's own writers
# (`wp media import`, `wp_upload_bits`' directory) rather than hand-built rows, and cleanup
# restores the two options this suite changes.
set -u
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
AP_URL="$BASE/wp-admin/admin.php?page=guarded-mcp-approvals"

dc() { docker compose exec -T "$@"; }
dcp() { docker compose cp "$@"; }
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
jget() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
try: j=json.loads(t)
except Exception: print("UNPARSEABLE"); sys.exit()
v=j.get(sys.argv[1],"")
print(json.dumps(v) if isinstance(v,(dict,list)) else str(v))' "$2" "$1"; }
text() { py 'import json,sys
d=json.load(sys.stdin).get("result",{})
print(d.get("content",[{}])[0].get("text",""))' "" "$1"; }
state() { py 'import json,sys
print(json.load(sys.stdin).get("result",{}).get("data",{}).get("status","-"))' "" "$1"; }
rid() { py 'import json,sys
print(json.load(sys.stdin).get("result",{}).get("data",{}).get("request_id","-"))' "" "$1"; }
# Wait for the temporary directory to be empty. An unlink runs on shutdown, which is after the
# request has already gone wrong, so the check waits for it rather than racing it.
wait_temp_gone() { left=unknown; n=0
  while [ "$n" -lt 20 ]; do
    left=$(dc wp sh -c 'ls /tmp/gmcp-export-* 2>/dev/null | wc -l' | tr -d ' \r\n')
    [ "$left" = "0" ] && break
    n=$((n+1)); sleep 0.25
  done
  printf '%s' "$left"; }

echo "-- preconditions: keys, options, and a clean slate for this suite's rows --"
TOK=$(mk_key "smoke export" admin)
RO=$(mk_key "smoke export readonly" readonly)
for k in "$TOK" "$RO"; do case "$k" in gmcp_*) ;; *) echo "Could not create an API key." >&2; exit 1 ;; esac; done
PREV_EXPORT=$(wpq 'echo (get_option("gmcp_options",[])["mcp_tools_export"] ?? false) ? "1" : "0";')
PREV_MODE=$(wpq 'echo get_option("gmcp_options",[])["mcp_approval_mode"] ?? "never";')
echo "  previous export switch: $PREV_EXPORT; previous approval mode: $PREV_MODE"
# This suite owns rows only for its own tool. Do not clear the queue wholesale: it may hold
# another session's request, and the audit log is deliberately append-only.
wpq 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}gmcp_approvals WHERE tool=\"wp_export_request\"");' >/dev/null
wpq 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"gmcp_export_token_%\"");' >/dev/null
dc wp sh -c 'rm -f /tmp/gmcp-export-*' >/dev/null 2>&1 || true
set_export() { wpq '$o=get_option("gmcp_options",[]);$o["mcp_tools_export"]=((int) '"$1"')?true:false;update_option("gmcp_options",$o,false);echo "ok";' >/dev/null; }
set_mode() { wpq '$o=get_option("gmcp_options",[]);$o["mcp_approval_mode"]="'"$1"'";update_option("gmcp_options",$o,false);echo "ok";' >/dev/null; }
names() { curl -sS -X POST "$URL" -H "Authorization: Bearer $1" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | python3 -c 'import json,sys
try: print(" ".join(x["name"] for x in json.load(sys.stdin)["result"]["tools"]))
except Exception: print("UNREADABLE")'; }
has() { python3 -c 'import re,sys; print("yes" if re.search(sys.argv[2], sys.argv[1]) else "no")' "$1" "$2"; }

echo "-- the group is off until the switch is on --"
set_export 0
ON_NAMES=$(names "$TOK")
check "CONTROL: the listing is readable and has an ordinary tool" "$(has "$ON_NAMES" '(^| )wp_get_posts( |$)')" "yes"
check "export off: the tool is absent from tools/list" "$(has "$ON_NAMES" '(^| )wp_export_request( |$)')" "no"
# Absent from the menu is not the same as refused: the switch has to be the tool itself,
# not the listing. The control is the same call once the switch is on, so a typo in the
# arguments cannot make the refusal look like the guard.
call "$TOK" off_call "$(tc wp_export_request '{"root":"uploads"}')"
check "export off: and a call by name is refused" "$(verdict off_call)" "error"
check "  ...and the refusal names the tool" "$(text off_call | grep -c 'wp_export_request')" "1"
set_export 1
ON_NAMES=$(names "$TOK")
check "export on: the tool is present" "$(has "$ON_NAMES" '(^| )wp_export_request( |$)')" "yes"
call "$TOK" on_call "$(tc wp_export_request '{"root":"uploads"}')"
check "CONTROL: the same call answers once the switch is on" "$(verdict on_call)" "ok"
RO_NAMES=$(names "$RO")
check "CONTROL: the readonly listing is readable" "$(has "$RO_NAMES" '(^| )wp_get_posts( |$)')" "yes"
check "a readonly key is not offered an admin-level export tool" "$(has "$RO_NAMES" '(^| )wp_export_request( |$)')" "no"
call "$RO" ro_pull "$(tc wp_export_request '{"root":"uploads","mode":"manifest"}')"
check "and cannot call it by name" "$(verdict ro_pull)" "error"

echo "-- fixtures: one through the media writer, one symlink, one hard-link pair --"
MARK="exportmark$(date +%s)"
SRC="/tmp/$MARK.txt"
printf 'MARKER-%s\n' "$MARK" > "$SRC"
dcp "$SRC" "cli:/tmp/$MARK.txt" >/dev/null 2>&1
ATT=$(dc cli wp media import "/tmp/$MARK.txt" --title="Export probe $MARK" --porcelain 2>/dev/null | tr -d '\r\n')
case "$ATT" in ''|*[!0-9]*) echo "Could not create an attachment fixture." >&2; exit 1 ;; esac
ATT_PATH=$(wpq 'echo get_attached_file('"$ATT"');')
UPLOADS=$(wpq 'echo wp_get_upload_dir()["basedir"];')
ATT_REL=$(wpq '$u=wp_get_upload_dir();echo ltrim(str_replace($u["basedir"],"",get_attached_file('"$ATT"')),"/");')
check "CONTROL: the fixture has a local file under uploads" \
  "$(python3 -c 'import sys; print("yes" if sys.argv[1].startswith(sys.argv[2].rstrip("/")+"/") else "no")' "$ATT_PATH" "$UPLOADS")" "yes"
check "CONTROL: the fixture carries the marker" \
  "$(dc cli grep -c "MARKER-$MARK" "$ATT_PATH" 2>/dev/null | tr -d '\r\n')" "1"
# The link's target goes into the WEB container, not the CLI one. They share the site's
# files but not /tmp, and the archive is built by the web container: a target that exists
# only in the CLI container makes the link dangling there, so "the target's bytes are not
# in the archive" would pass for a tool that followed links, because there was nothing to
# follow. Measured with the symlink guard removed: the assertion stayed green for exactly
# that reason until this fixture moved.
OUTSIDE="/tmp/$MARK-outside.txt"
printf 'OUTSIDE-MARKER-%s\n' "$MARK" > "$OUTSIDE"
dcp "$OUTSIDE" "wp:/tmp/$MARK-outside.txt" >/dev/null 2>&1
dc cli sh -c "ln -s /tmp/$MARK-outside.txt '$UPLOADS/export-symlink-$MARK.txt'; printf 'HARDLINK-$MARK\n' > '$UPLOADS/export-hardlink-source-$MARK.txt'; ln '$UPLOADS/export-hardlink-source-$MARK.txt' '$UPLOADS/export-hardlink-copy-$MARK.txt'" >/dev/null 2>&1
check "CONTROL: the symlink exists and points outside uploads" \
  "$(dc cli sh -c "test -L '$UPLOADS/export-symlink-$MARK.txt' && echo yes || echo no" | tr -d '\r\n')" "yes"
# A link with nothing behind it is not a test of containment. This proves the target is
# readable from the container that would follow the link, which is what makes the archive
# checks below able to fail.
check "CONTROL: the symlink's target is readable from the web container" \
  "$(dc wp sh -c "cat /tmp/$MARK-outside.txt" 2>/dev/null | tr -d '\r\n')" "OUTSIDE-MARKER-$MARK"
check "CONTROL: the hard-link source has two links" \
  "$(dc cli sh -c "stat -c %h '$UPLOADS/export-hardlink-source-$MARK.txt'" | tr -d '\r\n')" "2"
# A fixture large enough that a rate-limited download cannot finish inside its timeout. Without
# one, "the archive was removed when the client left" would pass on a download that quietly
# completed, which is the same thing that happens when the check is broken.
BIG="$UPLOADS/export-big-$MARK.bin"
dc cli sh -c "head -c 8388608 /dev/urandom > '$BIG'" >/dev/null 2>&1
check "CONTROL: the large fixture for the cut-off download exists" \
  "$(dc cli sh -c "stat -c %s '$BIG'" 2>/dev/null | tr -d '\r\n')" "8388608"

echo "-- manifest: counts, a digest, and exclusions that are named --"
call "$TOK" man "$(tc wp_export_request '{"root":"uploads"}')"
check "the manifest answers" "$(verdict man)" "ok"
check "it counts at least the fixture" "$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; d=json.loads(t); print("yes" if d.get("file_count",0)>=1 else "no")' "" man)" "yes"
check "it has a sha256 digest" "$(py 'import json,sys,re
t=json.load(sys.stdin)["result"]["content"][0]["text"]; d=json.loads(t)
print("yes" if re.fullmatch(r"[0-9a-f]{64}", d.get("manifest_digest","")) else "no")' "" man)" "yes"
check "it names the symlink as excluded and says why" \
  "$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; d=json.loads(t)
print("yes" if any(e.get("path","").endswith("export-symlink-'"$MARK"'.txt") and e.get("reason")=="symbolic_link" for e in d.get("excluded",[])) else "no")' "" man)" "yes"
check "it names the hard link as excluded and says why" \
  "$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; d=json.loads(t)
print("yes" if any(e.get("path","").endswith("export-hardlink-copy-'"$MARK"'.txt") and e.get("reason")=="hard_link" for e in d.get("excluded",[])) else "no")' "" man)" "yes"
check "it does NOT return file contents (the marker is absent)" \
  "$(text man | grep -c "MARKER-$MARK")" "0"
# The relative label is the only path the reply carries for an excluded entry. An absolute
# server path in a manifest is a filesystem disclosure the caller did not need.
check "the exclusions are relative, not absolute paths" \
  "$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; d=json.loads(t)
print("yes" if all(not e.get("path","").startswith("/") for e in d.get("excluded",[])) else "no")' "" man)" "yes"
MAN_DIGEST=$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; print(json.loads(t).get("manifest_digest",""))' "" man)

echo "-- paths: absolute, traversal and stream wrappers are refused; a real relative path works --"
for bad in "/etc" "../wp-config.php" "php://filter" "$UPLOADS"; do
  call "$TOK" bad "$(tc wp_export_request '{"root":"uploads","path":"'"$bad"'"}')"
  check "path '$bad' is refused" "$(verdict bad)" "error"
done
call "$TOK" good "$(tc wp_export_request '{"root":"uploads","path":"'"$(dirname "$ATT_REL")"'"}')"
check "CONTROL: a relative directory under uploads is readable" "$(verdict good)" "ok"
call "$TOK" sym "$(tc wp_export_request '{"root":"uploads","path":"export-symlink-'"$MARK"'.txt"}')"
check "a path that is a symlink is refused rather than followed" "$(verdict sym)" "error"

echo "-- over the byte cap: refused, not truncated --"
CAP_FILE="$UPLOADS/export-overcap-$MARK.bin"
dc cli sh -c "truncate -s 513M '$CAP_FILE'" >/dev/null 2>&1
check "CONTROL: the over-cap file is larger than 512 MiB" \
  "$(dc cli sh -c "stat -c %s '$CAP_FILE'" | tr -d '\r\n')" "537919488"
call "$TOK" cap "$(tc wp_export_request '{"root":"uploads"}')"
check "the manifest refuses the tree" "$(verdict cap)" "error"
# The refusal wording, not just the word "over": a manifest that returned a count anyway
# carries "Over the caps this call refuses rather than returning a truncated count" in its
# own note, so a looser pattern passed on the exact behaviour it is here to catch.
check "and says it is over the cap" "$(text cap | grep -c 'over the 512 MiB cap')" "1"
check "and returns no counts at all" "$(text cap | grep -c 'file_count')" "0"
check "and no archive was built" \
  "$(dc wp sh -c 'ls /tmp/gmcp-export-* 2>/dev/null | wc -l' | tr -d ' \r\n')" "0"
dc cli sh -c "rm -f '$CAP_FILE'" >/dev/null 2>&1
call "$TOK" cap_ok "$(tc wp_export_request '{"root":"uploads"}')"
check "CONTROL: after deleting it, the same call answers" "$(verdict cap_ok)" "ok"

# The other half of the cap, and the half nothing covered: the count. A walk that stopped
# at 20,000 and reported a clean-looking number would be a lie about what the archive
# would hold, and it is exactly what a manifest that truncates instead of refusing looks
# like from outside. 20,005 empty files in one directory, so the walk meets the cap on the
# count and not on the bytes.
echo "-- over the entry cap: refused, not truncated either --"
MANY="$UPLOADS/export-many-$MARK"
dc cli sh -c "mkdir -p '$MANY' && cd '$MANY' && seq 1 20005 | xargs touch" >/dev/null 2>&1
check "CONTROL: the fixture directory holds more than 20000 entries" \
  "$(dc cli sh -c "ls '$MANY' | wc -l" | tr -d ' \r\n')" "20005"
call "$TOK" ecap "$(tc wp_export_request '{"root":"uploads","path":"export-many-'"$MARK"'"}')"
check "the manifest refuses the entry count" "$(verdict ecap)" "error"
check "and says it is over the entry cap" "$(text ecap | grep -c 'over the 20000-entry cap')" "1"
check "and returns no counts at all" "$(text ecap | grep -c 'file_count')" "0"
dc cli sh -c "rm -rf '$MANY'" >/dev/null 2>&1
call "$TOK" cap_ok2 "$(tc wp_export_request '{"root":"uploads"}')"
check "CONTROL: after removing them, the same root answers" "$(verdict cap_ok2)" "ok"
MAN_DIGEST=$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; print(json.loads(t).get("manifest_digest",""))' "" cap_ok2)

echo "-- a pull waits for a person, even with mcp_approval_mode=never --"
set_mode never
check "CONTROL: the mode really is never" \
  "$(wpq 'echo get_option("gmcp_options",[])["mcp_approval_mode"] ?? "(unset)";')" "never"
call "$TOK" pull0 "$(tc wp_export_request '{"root":"uploads","mode":"pull","manifest_digest":"'"$MAN_DIGEST"'"}')"
R1=$(rid pull0)
check "the pull is not an error" "$(verdict pull0)" "ok"
check "the reply says it is waiting for a person" "$(state pull0)" "awaiting_human_approval"
check "it names a request id" "$(python3 -c 'import sys; print("yes" if sys.argv[1].isdigit() else "no")' "$R1")" "yes"
check "the request is stored as pending" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_approvals WHERE id=$R1 AND tool='wp_export_request' AND status='pending'")" "1"
check "no export token exists before approval" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'gmcp_export_token_%'")" "0"
check "no archive exists before approval" \
  "$(dc wp sh -c 'ls /tmp/gmcp-export-* 2>/dev/null | wc -l' | tr -d ' \r\n')" "0"

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
FORM=$(ap_form "$R1")
check "the request has an approve form on the screen" "$(python3 -c 'import sys; print("yes" if "|" in sys.argv[1] else "no")' "$FORM")" "yes"
WN=${FORM%%|*}; AN=${FORM##*|}
curl -sS -b "$JAR" -o /dev/null -X POST "$AP_URL" \
  --data-urlencode "_wpnonce=$WN" --data-urlencode "gmcp_action=approve_request" \
  --data-urlencode "request_id=$R1" --data-urlencode "gmcp_approval_nonce=$AN" 2>/dev/null
check "the row is stored as approved" \
  "$(dbq "SELECT status FROM wp_gmcp_approvals WHERE id=$R1")" "approved"

echo "-- the approved pull builds the archive and mints one token --"
call "$TOK" pull1 "$(tc wp_export_request '{"root":"uploads","mode":"pull","manifest_digest":"'"$MAN_DIGEST"'"}')"
check "the approved pull answers" "$(verdict pull1)" "ok"
EXPORT_URL=$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; print(json.loads(t).get("export_url",""))' "" pull1)
case "$EXPORT_URL" in http*/export/*) echo "  url: ${EXPORT_URL%%/export/*}/export/<token>" ;; *) echo "  FAIL  no export_url in the reply"; fail=$((fail+1)) ;; esac
ETOK=${EXPORT_URL##*/}
check "CONTROL: the reply carries a token-shaped export token" \
  "$(printf '%s' "$ETOK" | grep -cE '^[A-Za-z0-9]{16,}$')" "1"
check "exactly one export token row exists" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'gmcp_export_token_%'")" "1"
ARCHIVE_PATH=$(dc wp sh -c 'ls /tmp/gmcp-export-* 2>/dev/null | head -n 1' | tr -d '\r\n')
check "CONTROL: an archive exists in the web container's temp directory" \
  "$([ -n "$ARCHIVE_PATH" ] && echo yes || echo no)" "yes"
check "the archive is outside the web root" \
  "$(dc wp sh -c 'case "'"$ARCHIVE_PATH"'" in /var/www/*) echo inside;; *) echo outside;; esac' | tr -d '\r\n')" "outside"
check "the archive is mode 0600" \
  "$(dc wp sh -c 'stat -c %a "'"$ARCHIVE_PATH"'"' | tr -d '\r\n')" "600"

echo "-- the archive contains the fixture and not the excluded entries --"
curl -sS -L -o "$OUT/export.zip" "$EXPORT_URL" 2>/dev/null
check "CONTROL: the download is a zip file" "$(python3 -c 'import zipfile,sys; print("yes" if zipfile.is_zipfile(sys.argv[1]) else "no")' "$OUT/export.zip")" "yes"
check "the fixture file is in the archive" \
  "$(python3 -c 'import zipfile,sys; print("yes" if sys.argv[2] in zipfile.ZipFile(sys.argv[1]).namelist() else "no")' "$OUT/export.zip" "$ATT_REL")" "yes"
check "and its bytes are the fixture's bytes" \
  "$(python3 -c 'import zipfile,sys; print(zipfile.ZipFile(sys.argv[1]).read(sys.argv[2]).decode().strip())' "$OUT/export.zip" "$ATT_REL")" "MARKER-$MARK"
check "the symlink name is not an entry" \
  "$(python3 -c 'import zipfile,sys; print("no" if any(n.endswith(sys.argv[2]) for n in zipfile.ZipFile(sys.argv[1]).namelist()) else "yes")' "$OUT/export.zip" "export-symlink-$MARK.txt")" "yes"
check "the symlink TARGET's bytes are not in the archive" \
  "$(python3 -c 'import zipfile,sys
z=zipfile.ZipFile(sys.argv[1]); data=b"".join(z.read(n) for n in z.namelist() if not n.endswith("/"))
print("no" if sys.argv[2].encode() in data else "yes")' "$OUT/export.zip" "OUTSIDE-MARKER-$MARK")" "yes"
check "the hard link name is not an entry" \
  "$(python3 -c 'import zipfile,sys; print("no" if any(n.endswith(sys.argv[2]) for n in zipfile.ZipFile(sys.argv[1]).namelist()) else "yes")' "$OUT/export.zip" "export-hardlink-copy-$MARK.txt")" "yes"

echo "-- one use, and the archive is gone after it is served --"
CODE=$(curl -sS -o /dev/null -w '%{http_code}' "$EXPORT_URL" 2>/dev/null)
check "a second request with the same token is refused" "$CODE" "403"
check "the temporary archive is gone after the first serve" \
  "$(dc wp sh -c 'test -e "'"$ARCHIVE_PATH"'" && echo present || echo gone' | tr -d '\r\n')" "gone"
check "the token row is consumed" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'gmcp_export_token_%'")" "0"

echo "-- the digest covers contents, so a same-length rewrite in the same second moves it --"
# The fixture is rewritten with the same number of bytes and its mtime is put back afterwards, so
# name, size and mtime are all unchanged. A digest built from those three would not move, and an
# approval taken before the rewrite would still match while the archive carried the new bytes.
ATT_SIZE_BEFORE=$(dc cli sh -c "stat -c %s '$ATT_PATH'" | tr -d ' \r\n')
ATT_MTIME_BEFORE=$(dc cli sh -c "stat -c %Y '$ATT_PATH'" | tr -d ' \r\n')
dc cli sh -c "printf 'XARKER-$MARK\n' > '$ATT_PATH'; touch -d @$ATT_MTIME_BEFORE '$ATT_PATH'" >/dev/null 2>&1
check "CONTROL: the rewrite kept the file's size" \
  "$(dc cli sh -c "stat -c %s '$ATT_PATH'" | tr -d ' \r\n')" "$ATT_SIZE_BEFORE"
check "CONTROL: and its mtime" \
  "$(dc cli sh -c "stat -c %Y '$ATT_PATH'" | tr -d ' \r\n')" "$ATT_MTIME_BEFORE"
check "CONTROL: and the bytes really are different" \
  "$(dc cli sh -c "grep -c 'XARKER-$MARK' '$ATT_PATH'" | tr -d '\r\n')" "1"
call "$TOK" man2 "$(tc wp_export_request '{"root":"uploads"}')"
REWRITTEN_DIGEST=$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; print(json.loads(t).get("manifest_digest",""))' "" man2)
check "an equal-length rewrite inside the same second changes the manifest digest" \
  "$(python3 -c 'import sys; print("changed" if sys.argv[1] and sys.argv[1] != sys.argv[2] else "same")' "$REWRITTEN_DIGEST" "$MAN_DIGEST")" "changed"
dc cli sh -c "printf 'MARKER-$MARK\n' > '$ATT_PATH'; touch -d @$ATT_MTIME_BEFORE '$ATT_PATH'" >/dev/null 2>&1
call "$TOK" man3 "$(tc wp_export_request '{"root":"uploads"}')"
RESTORED_DIGEST=$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; print(json.loads(t).get("manifest_digest",""))' "" man3)
check "CONTROL: restoring the original bytes restores the digest" \
  "$(python3 -c 'import sys; print("same" if sys.argv[1] and sys.argv[1] == sys.argv[2] else "different")' "$RESTORED_DIGEST" "$MAN_DIGEST")" "same"

echo "-- an approval whose file changed afterwards is refused at pull time --"
call "$TOK" pull2 "$(tc wp_export_request '{"root":"uploads","mode":"pull","manifest_digest":"'"$MAN_DIGEST"'"}')"
R2=$(rid pull2)
check "the pull waits for a person first" "$(state pull2)" "awaiting_human_approval"
approve "$R2"
check "the row is stored as approved" \
  "$(dbq "SELECT status FROM wp_gmcp_approvals WHERE id=$R2")" "approved"
# Approval is given for the tree as it was, and only then does a file change under it.
dc cli sh -c "printf 'ZARKER-$MARK\n' > '$ATT_PATH'; touch -d @$ATT_MTIME_BEFORE '$ATT_PATH'" >/dev/null 2>&1
call "$TOK" pull2_changed "$(tc wp_export_request '{"root":"uploads","mode":"pull","manifest_digest":"'"$MAN_DIGEST"'"}')"
check "the approved pull is refused once a file changed under it" "$(verdict pull2_changed)" "error"
check "and it says the tree changed since that manifest" \
  "$(text pull2_changed | grep -c 'has changed since that manifest')" "1"
check "and no archive was built for it" \
  "$(dc wp sh -c 'ls /tmp/gmcp-export-* 2>/dev/null | wc -l' | tr -d ' \r\n')" "0"
check "and no token row was created for it" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'gmcp_export_token_%'")" "0"
dc cli sh -c "printf 'MARKER-$MARK\n' > '$ATT_PATH'; touch -d @$ATT_MTIME_BEFORE '$ATT_PATH'" >/dev/null 2>&1

# The token is spent when a request begins, so a client that disappears mid-stream used
# to leave the archive in the temporary directory until a later sweep found it. The download is
# rate-limited so it cannot finish inside its timeout, and the partial file shows it was really
# cut off rather than completed or refused.
echo "-- a download that is cut off does not leave the archive behind --"
call "$TOK" pull3 "$(tc wp_export_request '{"root":"uploads","mode":"pull","manifest_digest":"'"$MAN_DIGEST"'"}')"
R3=$(rid pull3)
check "the pull waits for a person first" "$(state pull3)" "awaiting_human_approval"
approve "$R3"
check "the row is stored as approved" \
  "$(dbq "SELECT status FROM wp_gmcp_approvals WHERE id=$R3")" "approved"
call "$TOK" pull3_ok "$(tc wp_export_request '{"root":"uploads","mode":"pull","manifest_digest":"'"$MAN_DIGEST"'"}')"
check "the approved pull answers" "$(verdict pull3_ok)" "ok"
CUT_URL=$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]; print(json.loads(t).get("export_url",""))' "" pull3_ok)
CUT_ARCHIVE=$(dc wp sh -c 'ls /tmp/gmcp-export-* 2>/dev/null | head -n 1' | tr -d '\r\n')
CUT_BYTES=$(dc wp sh -c "stat -c %s '$CUT_ARCHIVE'" | tr -d ' \r\n')
check "CONTROL: the archive is in place and far larger than one second of the download" \
  "$(python3 -c 'import sys; print("yes" if int(sys.argv[1]) > 400000 else "no")' "$CUT_BYTES")" "yes"
curl -sS --limit-rate 100k --max-time 2 -o "$OUT/cut.zip" "$CUT_URL" 2>/dev/null
PART=$(wc -c < "$OUT/cut.zip" | tr -d ' ')
check "CONTROL: the request started and was cut off mid-stream" \
  "$(python3 -c 'import sys; print("yes" if 0 < int(sys.argv[1]) < int(sys.argv[2]) else "no")' "$PART" "$CUT_BYTES")" "yes"
check "the token was spent, as it is on every request" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'gmcp_export_token_%'")" "0"
check "the archive is removed even though the client went away" "$(wait_temp_gone)" "0"

echo "-- the token never reaches the audit log, while the tool call does --"
check "CONTROL: the export tool call is in the audit log" \
  "$(dbq "SELECT IF(COUNT(*) > 0, 'yes', 'no') FROM wp_gmcp_audit WHERE tool='wp_export_request'")" "yes"
check "and the export token is not" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_audit WHERE CONCAT(COALESCE(args,''),COALESCE(detail,''),COALESCE(changes,'')) LIKE '%$ETOK%'")" "0"
check "CONTROL: the approval decision is in the audit log too" \
  "$(dbq "SELECT IF(COUNT(*) > 0, 'yes', 'no') FROM wp_gmcp_audit WHERE tool='approval:approved'")" "yes"

echo "-- cleanup: fixtures, rows, and the two options this suite changed --"
dc cli wp eval 'wp_delete_attachment('"$ATT"', true);' >/dev/null 2>&1
dc cli sh -c "rm -f '$UPLOADS/export-symlink-$MARK.txt' '$UPLOADS/export-hardlink-source-$MARK.txt' '$UPLOADS/export-hardlink-copy-$MARK.txt' '$UPLOADS/export-big-$MARK.bin'" >/dev/null 2>&1
dc wp sh -c "rm -f /tmp/$MARK-outside.txt" >/dev/null 2>&1
rm -f "$SRC" "$OUTSIDE"
wpq 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}gmcp_approvals WHERE tool=\"wp_export_request\"");' >/dev/null
wpq 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"gmcp_export_token_%\"");' >/dev/null
dc wp sh -c 'rm -f /tmp/gmcp-export-*' >/dev/null 2>&1 || true
set_mode "$PREV_MODE"
set_export "$PREV_EXPORT"
check "the export switch is back where it was" \
  "$(wpq 'echo (get_option("gmcp_options",[])["mcp_tools_export"] ?? false) ? "1" : "0";')" "$PREV_EXPORT"
check "the approval mode is back where it was" \
  "$(wpq 'echo get_option("gmcp_options",[])["mcp_approval_mode"] ?? "never";')" "$PREV_MODE"
check "the fixture is gone" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE '%Export probe $MARK%'")" "0"

echo
printf 'export: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
