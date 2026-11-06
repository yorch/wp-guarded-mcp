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
has() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","")
print("yes" if sys.argv[1].lower() in t.lower() else "no")' "$2" "$1"; }
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

echo "-- a HEAD request (a link previewer) does not spend the token --"
# A mail scanner or a chat unfurler sends HEAD before the person clicks, and WordPress routes it
# to the GET handler. Measured before the fix: the first HEAD consumed the row and the person's
# own click was answered 403.
call "$TOK" mintH "$(tc wp_download_request '{"ID":'"$ATT"'}')"
DLH=$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print(json.loads(t).get("download_url",""))' "" mintH)
TOKH=${DLH##*/}
rows_h() { dbq "SELECT COUNT(*) FROM wp_options WHERE option_name IN ('_transient_gmcp_download_$TOKH','_transient_timeout_gmcp_download_$TOKH')"; }
check "CONTROL: the token's two rows exist before any request" "$(rows_h)" "2"
HCODE=$(curl -sS -I -o "$OUT/head.hdr" -w '%{http_code}' "$DLH" 2>/dev/null)
check "HEAD with a live token answers 200" "$HCODE" "200"
check "and names the type the GET would send" "$(grep -ci '^content-type: ' "$OUT/head.hdr")" "1"
check "and carries the file's length" "$(grep -i '^content-length:' "$OUT/head.hdr" | tr -dc '0-9')" "$(wc -c < "$SRC" | tr -d ' ')"
check "and the token is still unspent: both rows stand" "$(rows_h)" "2"
curl -sS -I -o /dev/null "$DLH" 2>/dev/null
check "a second HEAD changes nothing either" "$(rows_h)" "2"
curl -sS -L -o "$OUT/got_h.bin" "$DLH" 2>/dev/null
check "the GET after the HEADs still serves the file" "$(shasum -a 256 "$OUT/got_h.bin" 2>/dev/null | awk '{print $1}')" "$(shasum -a 256 "$SRC" | awk '{print $1}')"
check "and it spent the token (the rows are gone)" "$(rows_h)" "0"
check "HEAD on a spent token is refused" "$(curl -sS -I -o /dev/null -w '%{http_code}' "$DLH" 2>/dev/null)" "403"
check "HEAD on an unknown token is refused" "$(curl -sS -I -o /dev/null -w '%{http_code}' "${DLH%/*}/deadbeefdeadbeefdeadbeefdeadbeef" 2>/dev/null)" "403"

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

echo "-- an expired token is refused --"
# Minted, then the timeout row is moved into the past: the row itself stays, which is the case
# the direct SELECT would otherwise miss.
call "$TOK" mint3 "$(tc wp_download_request '{"ID":'"$ATT"'}')"
DL3=$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print(json.loads(t).get("download_url",""))' "" mint3)
TOK3=${DL3##*/}
wpc eval 'update_option( "_transient_timeout_gmcp_download_'"$TOK3"'", time() - 60 );' >/dev/null 2>&1
# Read through get_option rather than a hand-built SQL string: the nested quoting in the
# version above produced an empty result, which read as "the control failed" when the fixture
# was fine.
check "CONTROL: the row still exists after its timeout" \
  "$(wpc eval 'echo get_option( "_transient_gmcp_download_'"$TOK3"'", "__ABSENT__" ) !== "__ABSENT__" ? "yes" : "no";')" "yes"
CODE4=$(curl -sS -o /dev/null -w '%{http_code}' "$DL3" 2>/dev/null)
check "an expired token is refused even though its row is still there" "$CODE4" "403"

echo "-- the bytes never reach the audit log --"
# Positive control first: the tool call itself IS in the log, so an empty table is not the
# explanation for a missing payload.
# "At least one", not "exactly one": the log keeps rows across runs, so an exact count makes a
# second run of the suite fail for a reason that has nothing to do with the code.
check "CONTROL: the tool call is in the audit log" \
  "$(dbq "SELECT IF(COUNT(*) > 0, 'yes', 'no') FROM wp_gmcp_audit WHERE tool='wp_download_request'")" "yes"
check "and the file's content is not" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_audit WHERE CONCAT(COALESCE(args,''),COALESCE(detail,''),COALESCE(changes,'')) LIKE '%PAYLOAD-MARKER-$MARK%'")" "0"

echo "-- an upload token is a credential too, and the reply that hands it out is logged --"
# wp_upload_request returns a one-time URL, exactly as wp_download_request does. The URL is in
# the tool reply, and the reply is copied into the audit log, so the token has to be scrubbed
# there. The positive control is the tool row itself: an empty table is not the explanation.
call "$TOK" upreq "$(tc wp_upload_request '{"filename":"export-probe.txt"}')"
check "the upload request answers" "$(verdict upreq)" "ok"
UPLOAD_URL=$(py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
print(json.loads(t).get("upload_url",""))' "" upreq)
UTOK=${UPLOAD_URL##*/}
# The shape, printed without the token. A token that is empty or missing makes the absence
# check below pass for the wrong reason.
case "$UPLOAD_URL" in http*/upload/*) echo "  upload url: ${UPLOAD_URL%%/upload/*}/upload/<token>" ;; *) echo "  FAIL  no upload_url in the reply"; fail=$((fail+1)) ;; esac
check "CONTROL: the reply carries a token-shaped upload token" \
  "$(printf '%s' "$UTOK" | grep -cE '^[A-Za-z0-9]{8,}$')" "1"
check "CONTROL: the upload tool call is in the audit log" \
  "$(dbq "SELECT IF(COUNT(*) > 0, 'yes', 'no') FROM wp_gmcp_audit WHERE tool='wp_upload_request'")" "yes"
check "and the upload token is not" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_audit WHERE CONCAT(COALESCE(args,''),COALESCE(detail,''),COALESCE(changes,'')) LIKE '%$UTOK%'")" "0"

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

echo "-- fixtures for the guards below: a published post with a file, and a form submission with an upload --"
RW=$(gmcp_make_key "smoke download readwrite" readwrite)
case "$RW" in gmcp_*) ;; *) echo "Could not create a readwrite key." >&2; exit 1 ;; esac
mkatt() {  # mkatt PARENT NAME -> attachment id of a real file with that name, attached to PARENT
  local f="/tmp/${MARK}$2.txt"
  printf 'PAYLOAD-%s-%s\n' "$2" "$MARK" > "$f"
  wpc media import "$f" --post_id="$1" --title="Download probe $2 $MARK" --porcelain | tail -1
  rm -f "$f"
}
dl_of() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
try: print(json.loads(t).get("download_url",""))
except Exception: print("")' "" "$1"; }
norm() { py 'import json,sys,re
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","")
print(re.sub(r"\d+","N",t))' "" "$1"; }
PUB=$(wpc post create --post_title="Download pub $MARK" --post_status=publish --porcelain | tail -1)
ATTB=$(mkatt "$PUB" b)
SUB=$(wpc eval 'echo wp_insert_post([ "post_title" => "Download sender '"$MARK"'", "post_type" => "flamingo_inbound", "post_status" => "publish" ]);')
ATTS=$(mkatt "$SUB" s)
for v in "$PUB" "$ATTB" "$SUB" "$ATTS"; do case "$v" in ''|*[!0-9]*) echo "Could not seed fixtures." >&2; exit 1 ;; esac; done
check "CONTROL: the submission is published (so the parent-status rule alone would let its upload through)" \
  "$(dbq "SELECT CONCAT(post_type,'/',post_status) FROM wp_posts WHERE ID=$SUB")" "flamingo_inbound/publish"
check "CONTROL: its upload is attached to it and is a real file under uploads" \
  "$(wpc eval 'echo wp_get_post_parent_id( '"$ATTS"' ) . "/" . ( is_file( get_attached_file( '"$ATTS"' ) ) ? "file" : "nofile" );')" "$SUB/file"

echo "-- the token is a credential: it never reaches the audit log, the journal or a snapshot --"
call "$RO" mint_b "$(tc wp_download_request '{"ID":'"$ATTB"'}')"
check "CONTROL: a read key gets a URL for a file on a published post" "$(verdict mint_b)" "ok"
DLB=$(dl_of mint_b)
TOKB="${DLB##*/}"
check "CONTROL: the token is the last path segment, 32 characters" "${#TOKB}" "32"
check "CONTROL: it is a live credential (its transient row exists)" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name='_transient_gmcp_download_$TOKB'")" "1"
check "CONTROL: this very call is in the audit log" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_audit WHERE tool='wp_download_request' AND target='$ATTB'")" "1"
check "the token is NOT in the audit log (args, target, detail or changes)" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_audit WHERE CONCAT(COALESCE(args,''),COALESCE(target,''),COALESCE(detail,''),COALESCE(changes,'')) LIKE '%$TOKB%'")" "0"
check "the token is NOT in any other option (the change journal included)" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_value LIKE '%$TOKB%' AND option_name NOT LIKE '%gmcp_download_%'")" "0"
check "the token is NOT in the snapshot table" \
  "$(dbq "SELECT COUNT(*) FROM wp_gmcp_meta_snapshots WHERE CAST(meta_value AS CHAR) LIKE '%$TOKB%'")" "0"
check "CONTROL: the audit detail still says what the reply was (route kept, token cut)" \
  "$(dbq "SELECT IF(detail LIKE '%download%[redacted]%', 'yes', 'no') FROM wp_gmcp_audit WHERE tool='wp_download_request' AND target='$ATTB' ORDER BY id DESC LIMIT 1")" "yes"

echo "-- what the endpoint sends with the bytes --"
curl -sS -D "$OUT/hdr" -o "$OUT/body" "$DLB" 2>/dev/null
lower_hdr() { tr 'A-Z' 'a-z' < "$OUT/hdr" | tr -d '\r'; }
check "CONTROL: the first use serves the file" "$(grep -c "PAYLOAD-b-$MARK" "$OUT/body")" "1"
check "nosniff" "$(lower_hdr | grep -c '^x-content-type-options: nosniff')" "1"
check "no-store" "$(lower_hdr | grep '^cache-control:' | grep -c 'no-store')" "1"
check "noindex" "$(lower_hdr | grep -c '^x-robots-tag: noindex')" "1"
check "sent as an attachment, not rendered" "$(lower_hdr | grep '^content-disposition:' | grep -c 'attachment')" "1"

echo "-- expiry is enforced by the endpoint, not only stated by the reply --"
# WordPress leaves a lapsed transient in the options table until something asks for it or the daily
# cleanup runs, and this endpoint reads the row directly. The timeout is moved into the past by hand
# (what ten minutes of waiting does), after proving the same URL serves while it is in the future.
call "$TOK" mint_live "$(tc wp_download_request '{"ID":'"$ATTB"'}')"
DLL=$(dl_of mint_live)
check "CONTROL: an unexpired token is accepted" "$(curl -sS -o /dev/null -w '%{http_code}' "$DLL" 2>/dev/null)" "200"
call "$TOK" mint_old "$(tc wp_download_request '{"ID":'"$ATTB"'}')"
DLO=$(dl_of mint_old)
TOKO="${DLO##*/}"
dbq "UPDATE wp_options SET option_value = UNIX_TIMESTAMP() - 600 WHERE option_name='_transient_timeout_gmcp_download_$TOKO'" >/dev/null
check "CONTROL: the token's timeout is now in the past, and its row is still there" \
  "$(dbq "SELECT IF(t.option_value < UNIX_TIMESTAMP() AND (SELECT COUNT(*) FROM wp_options WHERE option_name='_transient_gmcp_download_$TOKO') = 1, 'yes', 'no') FROM wp_options t WHERE t.option_name='_transient_timeout_gmcp_download_$TOKO'")" "yes"
check "an expired token is refused" "$(curl -sS -o "$OUT/expired" -w '%{http_code}' "$DLO" 2>/dev/null)" "403"
check "and no file bytes were sent" "$(grep -c "PAYLOAD-b-$MARK" "$OUT/expired")" "0"

echo "-- a form submission's upload is not downloadable, and answers like a missing id --"
MISSING=$((ATTS + 900000))
check "CONTROL: the id used as 'missing' does not exist" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE ID=$MISSING")" "0"
ROWS_BEFORE=$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '\\_transient\\_gmcp\\_download\\_%'")
for K in "$RO" "$RW"; do
  call "$K" mint_s "$(tc wp_download_request '{"ID":'"$ATTS"'}')"
  call "$K" mint_m "$(tc wp_download_request '{"ID":'"$MISSING"'}')"
  check "a non-admin key cannot get a URL for it" "$(verdict mint_s)" "error"
  check "and the answer is the one a missing id gets, word for word" "$(norm mint_s)" "$(norm mint_m)"
done
check "CONTROL: the missing-id answer is a real sentence, so the comparison is not of two empty strings" \
  "$(has mint_m "No attachment with ID")" "yes"
call "$TOK" mint_sa "$(tc wp_download_request '{"ID":'"$ATTS"'}')"
check "an admin key is refused too, and told why" "$(verdict mint_sa)" "error"
check "  ...the reason names the form submission" "$(has mint_sa "form submission")" "yes"
check "STORED: none of those calls minted a token" \
  "$(dbq "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '\\_transient\\_gmcp\\_download\\_%'")" "$ROWS_BEFORE"
check "CONTROL: the same read key can still mint for the published post's file" "$(call "$RO" mint_ctl "$(tc wp_download_request '{"ID":'"$ATTB"'}')"; verdict mint_ctl)" "ok"

echo "-- an attachment cannot be repointed at another file --"
# The access rule is about the attachment ROW, so a key that can set _wp_attached_file moves a
# submission's upload under an innocent row and downloads it through that one.
PATH_S=$(wpc eval 'echo get_post_meta( '"$ATTS"', "_wp_attached_file", true );')
PATH_B=$(dbq "SELECT meta_value FROM wp_postmeta WHERE post_id=$ATTB AND meta_key='_wp_attached_file'")
check "CONTROL: the two attachments point at different files" "$([ -n "$PATH_S" ] && [ "$PATH_S" != "$PATH_B" ] && echo yes || echo no)" "yes"
for KEY in '_wp_attached_file' '_WP_ATTACHED_FILE' '_wp_attached_filé' '_wp_attached_file '; do
  call "$RW" repoint "$(tc wp_update_post_meta '{"ID":'"$ATTB"',"key":"'"$KEY"'","value":"'"$PATH_S"'"}')"
  check "repointing through the key '$KEY' is refused" "$(verdict repoint)" "error"
done
check "STORED: the attachment still points at its own file" \
  "$(dbq "SELECT meta_value FROM wp_postmeta WHERE post_id=$ATTB AND meta_key='_wp_attached_file'")" "$PATH_B"
call "$RW" repoint_ctl "$(tc wp_update_post_meta '{"ID":'"$ATTB"',"key":"probe_ordinary_key","value":"ok"}')"
check "CONTROL: an ordinary meta key on the same attachment still writes" "$(verdict repoint_ctl)" "ok"
check "CONTROL: and it is stored" "$(dbq "SELECT meta_value FROM wp_postmeta WHERE post_id=$ATTB AND meta_key='probe_ordinary_key'")" "ok"
call "$RO" bypass "$(tc wp_download_request '{"ID":'"$ATTB"'}')"
BYP=$(dl_of bypass)
curl -sS -o "$OUT/bypass.bin" "$BYP" 2>/dev/null
check "so the download still serves the attachment's OWN bytes" "$(grep -c "PAYLOAD-b-$MARK" "$OUT/bypass.bin")" "1"
check "and not the submission's upload" "$(grep -c "PAYLOAD-s-$MARK" "$OUT/bypass.bin")" "0"

echo "-- cleanup --"
wpc eval 'wp_delete_attachment( '"$ATT"', true ); wp_delete_post( '"$DRAFT"', true ); wp_delete_attachment( '"$ATTB"', true ); wp_delete_attachment( '"$ATTS"', true ); wp_delete_post( '"$PUB"', true ); wp_delete_post( '"$SUB"', true );' >/dev/null 2>&1
rm -f "$SRC"
check "the fixture is gone" "$(dbq "SELECT COUNT(*) FROM wp_posts WHERE post_title LIKE '%$MARK%'")" "0"

echo
printf 'download: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
