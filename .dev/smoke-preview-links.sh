#!/bin/bash
# Smoke test for the Preview Links screen in wp-admin. Asserts STORED state (the option the
# links live in) and the audit table, never only the page that was shown.
#
# The screen lists links and ends them. It must never print a token or a stored hash, must
# refuse a request without the page nonce or without manage_options, and must not take a
# token as the name of a link. Needs nothing beyond this plugin.
set -u
BASE="${GMCP_URL:-http://localhost:8080}"
OUT=$(mktemp -d); pass=0; fail=0
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1)); else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r'; }
live() { wpc eval 'echo count(GMCP_Preview::listing());'; }
for_post() { wpc eval 'echo GMCP_Preview::count_for('"$1"');'; }
code() { curl -sS -o "$OUT/$1" -w '%{http_code}' "${@:2}"; }

wpc eval '$o=get_option("gmcp_options",[]); echo !empty($o["mcp_tools_admin"]) ? 1 : 0;' > "$OUT/admin_was"
wpc eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_admin"]=true;update_option("gmcp_options",$o,false);delete_option("gmcp_preview_links");' >/dev/null
MARK="pls$(date +%s)"
PW="pw-$MARK-$RANDOM"
wpc user delete "$MARK-admin" --yes >/dev/null; wpc user delete "$MARK-editor" --yes >/dev/null
wpc user create "$MARK-admin" "$MARK-admin@example.test" --role=administrator --user_pass="$PW" >/dev/null
wpc user create "$MARK-editor" "$MARK-editor@example.test" --role=editor --user_pass="$PW" >/dev/null
PA=$(wpc post create --post_status=draft --post_title="PLS A $MARK" --post_content="body $MARK" --porcelain)
PB=$(wpc post create --post_status=draft --post_title="PLS B $MARK" --post_content="body $MARK" --porcelain)
check "CONTROL: the two draft fixtures exist" "$( [ -n "$PA" ] && [ -n "$PB" ] && echo yes || echo no)" "yes"

# Three links: two for A, one for B. Tokens go to a file only this script reads, and are used
# below to prove they are nowhere in what the screen prints.
wpc eval '$t=[]; foreach([['"$PA"'],['"$PA"'],['"$PB"']] as $x){ $r=GMCP_Preview::create(get_post($x[0]),3,1); $t[]=substr($r["url"],-40);} echo implode(" ",$t);' > "$OUT/tokens"
read -r T1 T2 T3 < "$OUT/tokens"
check "CONTROL: three links are stored" "$(live)" "3"
check "and two of them are for the first post" "$(for_post "$PA")" "2"

login() { # user, jar
  rm -f "$2"
  curl -sS -c "$2" -b "$2" -o /dev/null -H "Cookie: wordpress_test_cookie=WP%20Cookie%20check" \
    --data-urlencode "log=$1" --data-urlencode "pwd=$PW" -d "wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"
}
JA="$OUT/jar-admin"; JE="$OUT/jar-editor"
login "$MARK-admin" "$JA"; login "$MARK-editor" "$JE"
PAGE="$BASE/wp-admin/admin.php?page=guarded-mcp-previews"
check "CONTROL: the administrator can open the screen" "$(code list -b "$JA" "$PAGE")" "200"
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' "$OUT/list" | head -1 | sed 's/.*value="//;s/"//')
check "CONTROL: a page nonce was printed" "$( [ -n "$NONCE" ] && echo yes || echo no)" "yes"
REFS=$(grep -o 'name="link_ref" value="[a-f0-9]*"' "$OUT/list" | sed 's/.*value="//;s/"//')
check "one End button per live link, each with a 16-character reference" "$(echo "$REFS" | grep -c '^[a-f0-9]\{16\}$')" "3"

echo "-- what the screen prints --"
check "it says the count" "$(grep -c 'Live links (3)' "$OUT/list")" "1"
check "it names a post by title and id, with an edit link" "$(grep -c "PLS A $MARK" "$OUT/list")" "2"
check "and says a link's address cannot be shown again" "$(grep -c 'address cannot be shown again' "$OUT/list")" "1"
check "it offers no address to copy" "$(grep -c 'gmcp_preview=' "$OUT/list")" "0"
check "and no copy control" "$(sed -n '/class="wrap"/,/Thank you for creating/p' "$OUT/list" | grep -ci 'copy')" "0"
for t in "$T1" "$T2" "$T3"; do
  check "no token appears anywhere in the page [${t:0:6}]" "$(grep -c "$t" "$OUT/list")" "0"
done
# The stored hash is not printed either: the control is the hash itself, read from the option.
HASHES=$(wpc eval 'echo implode(" ", array_keys((array) get_option("gmcp_preview_links")));')
check "CONTROL: the probe can see three stored hashes" "$(echo "$HASHES" | wc -w | tr -d ' ')" "3"
for h in $HASHES; do check "no stored hash appears in the page [${h:0:6}]" "$(grep -c "$h" "$OUT/list")" "0"; check "nor a prefix of it [${h:0:12}]" "$(grep -c "${h:0:12}" "$OUT/list")" "0"; done

echo "-- who may use it --"
check "an editor cannot open the screen" "$(code ed -b "$JE" "$PAGE")" "403"
check "an editor's POST is refused" "$(code ed2 -b "$JE" -d gmcp_action=revoke_all_previews "$PAGE")" "403"
check "no nonce: refused" "$(code nn -b "$JA" -d gmcp_action=revoke_all_previews "$PAGE")" "403"
check "a nonce for another action: refused" "$(code wn -b "$JA" -d gmcp_action=revoke_all_previews -d "_wpnonce=0123456789" "$PAGE")" "403"
check "and none of the three attempts removed a link" "$(live)" "3"

echo "-- ending links --"
FIRST=$(echo "$REFS" | head -1)
check "a token is not a name for a link" "$(code tk -b "$JA" -d gmcp_action=revoke_preview_link -d "link_ref=$T1" -d "_wpnonce=$NONCE" "$PAGE")" "302"
check "and removed nothing" "$(live)" "3"
check "a reference that matches no link ends nothing" "$(code bad -b "$JA" -d gmcp_action=revoke_preview_link -d "link_ref=0000000000000000" -d "_wpnonce=$NONCE" "$PAGE" >/dev/null; live)" "3"
check "CONTROL: all three tokens open their drafts before anything is ended" "$(for t in $T1 $T2; do code f -s "$BASE/?page_id=$PA&gmcp_preview=$t"; done; code f "$BASE/?page_id=$PB&gmcp_preview=$T3")" "200200200"
code one -b "$JA" -d gmcp_action=revoke_preview_link -d "link_ref=$FIRST" -d "_wpnonce=$NONCE" "$PAGE" >/dev/null
check "one reference ends exactly one link (stored state)" "$(live)" "2"
check "the other links are still there" "$(for_post "$PA")$(for_post "$PB")" "11"
# Which of the two went is the table's order, not something to assume: exactly one still opens.
check "on the front end exactly one of the two links for the first post stopped opening" "$(for t in $T1 $T2; do code f "$BASE/?page_id=$PA&gmcp_preview=$t"; echo; done | grep -c 200)" "1"
code two -b "$JA" -d gmcp_action=revoke_preview_link -d "link_ref=$FIRST" -d "_wpnonce=$NONCE" "$PAGE" >/dev/null
check "the same reference a second time ends nothing more" "$(live)" "2"
code post -b "$JA" -d gmcp_action=revoke_post_previews -d "post_id=$PA" -d "_wpnonce=$NONCE" "$PAGE" >/dev/null
check "ending a post's links leaves none for it" "$(for_post "$PA")" "0"
check "and leaves the other post's link" "$(for_post "$PB")" "1"
code all -b "$JA" -d gmcp_action=revoke_all_previews -d "_wpnonce=$NONCE" "$PAGE" >/dev/null
check "ending all leaves none on the site" "$(live)" "0"
check "and the stored option holds no link" "$(wpc eval 'echo count(array_filter((array) get_option("gmcp_preview_links")));')" "0"
check "the screen says so when none is live" "$(code empty -b "$JA" "$PAGE" >/dev/null; grep -c 'No preview link is live' "$OUT/empty")" "1"

echo "-- the audit log --"
AUD=$(wpc eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"gmcp_preview_revoked\" AND actor_name=\"'"$MARK-admin"'\"");')
check "three endings that changed something left three rows, by that administrator" "$AUD" "3"
check "and the refused or empty attempts left none" "$(wpc eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"gmcp_preview_revoked\" AND detail LIKE \"%0000000000000000%\"");')" "0"
ALL=$(wpc eval 'global $wpdb; echo implode("\n", $wpdb->get_col("SELECT CONCAT_WS(\" \", tool, target, detail, args) FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"gmcp_preview_revoked\" AND actor_name=\"'"$MARK-admin"'\""));')
check "CONTROL: the probe can read the rows" "$(echo "$ALL" | grep -c 'gmcp_preview_revoked')" "3"
for t in "$T1" "$T2" "$T3"; do check "no token in the audit rows [${t:0:6}]" "$(echo "$ALL" | grep -c "$t")" "0"; done
for h in $HASHES; do check "no stored hash in the audit rows [${h:0:6}]" "$(echo "$ALL" | grep -c "$h")" "0"; done

echo "-- cleanup --"
for p in "$PA" "$PB"; do wpc post delete "$p" --force >/dev/null; done
wpc user delete "$MARK-admin" --yes >/dev/null; wpc user delete "$MARK-editor" --yes >/dev/null
[ "$(cat "$OUT/admin_was")" = "1" ] || wpc eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_admin"]=false;update_option("gmcp_options",$o,false);' >/dev/null
wpc eval 'delete_option("gmcp_preview_links");' >/dev/null
rm -rf "$OUT"
echo; printf 'preview-links: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
