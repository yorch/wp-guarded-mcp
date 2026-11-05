#!/bin/bash
# Smoke test for wp_download_request and the one-time download endpoint.
#
#   ./smoke-download.sh
#
# The two assertions worth having are the ones that would pass on a broken implementation:
#   - the file the endpoint serves is byte-identical to what was uploaded (a 200 with the wrong
#     bytes looks like success), and
#   - the file's CONTENT never reaches the audit log. That is the whole reason for the token
#     rather than base64 in a tool reply, so it is checked with a positive control: a marker in
#     the file body must be absent from the audit table while the tool call itself is present in
#     it. Without the control, "absent" and "the audit log was empty" look the same.
#
# Fixtures go through WordPress's own media API (wp media import), not a hand-built row: an
# attachment without the metadata media_handle_sideload writes has no `_wp_attached_file`, and a
# suite that seeded the same missing key would agree with a tool that failed to read it.
set -u
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
gmcp_make_key() {
  docker compose exec -T cli wp eval '
    $label = "'"$1"'";
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); }
    }
    $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $a = GMCP_Tokens::create( $label, "'"$2"'", 0, [], $admins ? $admins[0]->ID : 0 );
    echo $a["secret"];' 2>/dev/null | tr -d '\r\n'
}
TOK=$(gmcp_make_key "smoke download" admin)
RO=$(gmcp_make_key "smoke download readonly" readonly)
for k in "$TOK" "$RO"; do case "$k" in gmcp_*) ;; *) echo "Could not create an API key." >&2; exit 1 ;; esac; done
OUT=$(mktemp -d)
pass=0; fail=0
call() { curl -sS -X POST "$URL" -H "Authorization: Bearer $1" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$3" -o "$OUT/$2"; }
tc() { printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2"; }
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
print(str(j.get(sys.argv[1],"")).lower())' "$2" "$1"; }
dbq() { docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }

echo "-- fixture: a real attachment, through WordPress's media API --"
MARK="dlmark$(date +%s)"
SRC="/tmp/$MARK.txt"
printf 'PAYLOAD-MARKER-%s\n' "$MARK" > "$SRC"
# Copy first, then import once: the first version imported before the file was in the
# container, so the import failed and its empty id was silently replaced by a second attempt.
docker compose cp "$SRC" "cli:/tmp/$MARK.txt" >/dev/null 2>&1
ATT=$(docker compose exec -T cli wp media import "/tmp/$MARK.txt" --title="Download probe $MARK" --porcelain 2>/dev/null | tr -d '\r\n')
case "$ATT" in ''|*[!0-9]*) echo "Could not create an attachment fixture (is the file in the container?)." >&2; exit 1 ;; esac
echo "  attachment: $ATT"
check "CONTROL: the attachment has a local file" \
  "$(wpc eval 'echo get_attached_file( '"$ATT"' ) ? "yes" : "no";')" "yes"
ATT_PATH=$(wpc eval 'echo get_attached_file( '"$ATT"' );')
check "CONTROL: the payload is in that file" \
  "$(docker compose exec -T cli grep -c "PAYLOAD-MARKER-$MARK" "$ATT_PATH" 2>/dev/null | tr -d '\r\n')" "1"

echo "-- a read key can mint a URL for a published attachment --"
call "$TOK" mint "$(tc wp_download_request '{"ID":'"$ATT"'}')"
check "the tool answers" "$(verdict mint)" "ok"
DL=$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print(json.loads(t).get("download_url",""))' "" mint)
case "$DL" in http*) echo "  url: ${DL%%/download/*}/download/<token>" ;; *) echo "  FAIL  no download_url in the reply"; fail=$((fail+1)) ;; esac

echo "-- the endpoint serves exactly the file --"
LOCAL_SUM=$(shasum -a 256 "$SRC" | awk '{print $1}')
curl -sS -L -o "$OUT/got.bin" "$DL" 2>/dev/null
GOT_SUM=$(shasum -a 256 "$OUT/got.bin" 2>/dev/null | awk '{print $1}')
check "the served bytes are identical to the uploaded file" "$GOT_SUM" "$LOCAL_SUM"
check "the served body is not an error page" \
  "$(grep -c 'PAYLOAD-MARKER' "$OUT/got.bin" 2>/dev/null || echo 0)" "1"

echo "-- the token is single use, and a bad token is refused --"
CODE=$(curl -sS -o /dev/null -w '%{http_code}' "$DL" 2>/dev/null)
check "a second request with the same token is refused" "$CODE" "403"
CODE2=$(curl -sS -o /dev/null -w '%{http_code}' "${DL%/*}/deadbeefdeadbeefdeadbeefdeadbeef" 2>/dev/null)
check "an unknown token is refused" "$CODE2" "403"

echo "-- the bytes never reach the audit log --"
# Positive control first: the tool call itself IS in the log, so an empty table is not the
# explanation for a missing payload.
# "At least one", not "exactly one": the log keeps rows across runs, so an exact count makes a
# second run of the suite fail for a reason that has nothing to do with the code.
check "CONTROL: the tool call is in the audit log" \
  "$(dbq "SELECT IF(COUNT(*) > 0, 'yes', 'no') FROM wp_gmcp_audit WHERE tool='wp_download_request'")" "yes"
check "and the file's content is not" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_audit WHERE CONCAT(COALESCE(args,''),COALESCE(detail,''),COALESCE(changes,'')) LIKE '%PAYLOAD-MARKER-$MARK%'")" "0"

echo "-- a file attached to an unpublished post is admin-only --"
DRAFT=$(wpc post create --post_title="Download draft $MARK" --post_status=draft --porcelain | tail -1)
wpc eval 'wp_update_post([ "ID" => '"$ATT"', "post_parent" => '"$DRAFT"' ]);' >/dev/null 2>&1
check "CONTROL: the attachment is now attached to a draft" \
  "$(wpc eval 'echo get_post_status( wp_get_post_parent_id( '"$ATT"' ) );')" "draft"
call "$RO" mint_ro "$(tc wp_download_request '{"ID":'"$ATT"'}')"
check "a read key is refused" "$(verdict mint_ro)" "error"
check "and the refusal says why" \
  "$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","")
print("yes" if "not published" in t else "no")' "" mint_ro)" "yes"
call "$TOK" mint_adm "$(tc wp_download_request '{"ID":'"$ATT"'}')"
check "an admin key is not" "$(verdict mint_adm)" "ok"

echo "-- a file outside uploads is refused at serve time --"
# The token is minted while the file is inside uploads, then the locator is repointed. The
# endpoint must re-check containment rather than trust what was true at mint time.
call "$TOK" mint2 "$(tc wp_download_request '{"ID":'"$ATT"'}')"
DL2=$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print(json.loads(t).get("download_url",""))' "" mint2)
wpc eval 'update_post_meta( '"$ATT"', "_wp_attached_file", "../../wp-config.php" );' >/dev/null 2>&1
CODE3=$(curl -sS -o /dev/null -w '%{http_code}' "$DL2" 2>/dev/null)
check "an attachment repointed outside uploads is refused" "$CODE3" "403"

echo "-- cleanup --"
wpc eval 'wp_delete_attachment( '"$ATT"', true ); wp_delete_post( '"$DRAFT"', true );' >/dev/null 2>&1
rm -f "$SRC"
check "the fixture is gone" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE '%$MARK%'")" "0"

echo
printf 'download: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
