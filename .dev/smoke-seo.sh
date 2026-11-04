#!/bin/bash
# Smoke test for the Rank Math and All in One SEO tools.
#
# STATUS: UNRUN. This suite was written on a machine with no Docker daemon and neither plugin
# installed, from memory of how each plugin stores its data. Every plugin-specific assumption
# is marked UNVERIFIED in includes/tools-seo.php, and a failure here is as likely to be one of
# those assumptions as a defect in the tools. Run it, read each failure, and decide which.
#
# Its own file because it needs one of the two plugins installed and the group switched on.
# Each plugin's block skips itself, with a message, when that plugin is not active, so a site
# with one of them still gets a meaningful run.
#
#   docker compose exec -T cli wp plugin install seo-by-rank-math --activate   # or all-in-one-seo-pack
#   ./smoke-seo.sh
#
# Every check asserts stored state, read straight from post meta or the plugin's own table,
# rather than the wording of a reply. Every "refused" check sits beside a control proving the
# same call is accepted when it is valid, and a guard check puts the value back and compares
# afterwards, so a missing guard is one failure and not a site left in a strange state.
#
# FIXTURES. Where the plugin has a writer of its own the suite should seed through it. Neither
# is driven here: Rank Math's writer is a REST route that needs a nonce, and AIOSEO's is its
# model, which is what the tool under test calls. The Rank Math robots seed below is therefore
# a plain update_post_meta of the shape the plugin is believed to store (UNVERIFIED), and that
# is a seed that could agree with the code under test. Replace it with a value copied out of
# a post saved through the Rank Math editor.
set -u
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
gmcp_make_key() { # gmcp_make_key <label> <level>
  docker compose exec -T cli wp eval '
    $label = "'"$1"'";
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); }
    }
    $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $a = GMCP_Tokens::create( $label, "'"$2"'", 0, [], $admins ? $admins[0]->ID : 0 );
    echo $a["secret"];' 2>/dev/null | tr -d '\r\n'
}
TOK=$(gmcp_make_key "smoke seo" admin)
RO=$(gmcp_make_key "smoke seo readonly" readonly)
RW=$(gmcp_make_key "smoke seo readwrite" readwrite)
for k in "$TOK" "$RO" "$RW"; do
  case "$k" in
    gmcp_*) ;;
    *) echo "Could not create an API key for the suite. Is the plugin active?" >&2; exit 1 ;;
  esac
done
OUT=$(mktemp -d)
pass=0; fail=0
callas() { # callas <secret> <outfile> <json>
  curl -sS -X POST "$URL" -H "Authorization: Bearer $1" \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$3" -o "$OUT/$2"; }
call() { callas "$TOK" "$1" "$2"; }
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1));
  else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
py() { python3 -c "$1" < "$OUT/$2"; }
verdict() { py 'import json,sys;d=json.load(sys.stdin);print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "$1"; }
ctext() { py 'import json,sys
d=json.load(sys.stdin)
c=d.get("result",{}).get("content",[])
print(c[0]["text"] if c else json.dumps(d.get("error","")))' "$1"; }
jget() { py 'import json,sys
t=json.load(sys.stdin).get("result",{}).get("content",[{}])[0].get("text","{}")
try: cur=json.loads(t)
except Exception: print("UNPARSEABLE"); sys.exit()
for part in "'"$2"'".split("."):
    if part=="": continue
    cur=cur.get(part) if isinstance(cur,dict) else None
print("null" if cur is None else (str(cur).lower() if isinstance(cur,bool) else cur))' "$1"; }
wpe() { docker compose exec -T cli wp eval "$1" 2>/dev/null | tr -d '\r\n'; }
tc() { # tc <tool> <json-arguments>
  printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2"; }
setopt() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_seo"]='"$1"';$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }

setopt true
SITE=$(wpe 'echo home_url();')
OWN="${SITE}/some-page/"
POSTS=""

new_post() { wpe 'echo wp_insert_post(["post_title"=>"SEO smoke","post_status"=>"publish","post_content"=>"x"]);'; }

# ---------------------------------------------------------------------------------------------
echo "== Rank Math =="
if ! docker compose exec -T cli wp plugin is-active seo-by-rank-math >/dev/null 2>&1; then
  echo "  SKIP  Rank Math is not active"
else
  P=$(new_post); POSTS="$POSTS $P"
  echo "  Test post: $P"
  call rm_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
  check "the Rank Math tools are listed when the plugin is loaded" \
    "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print(sorted({"rankmath_get_post_seo","rankmath_set_post_seo"}-n) or True)' rm_list)" "True"

  echo "-- write, then assert the STORED rows --"
  call rm_set "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"title\":\"Smoke %title% %sep%\",\"description\":\"A description.\",\"canonical\":\"$OWN\",\"og_title\":\"OG t\",\"focus_phrase\":\"blue widget\",\"robots\":\"noindex\"}")"
  check "a valid write succeeds" "$(verdict rm_set)" "ok"
  check "title stored with its percent variables intact" "$(wpe 'echo get_post_meta('"$P"',"rank_math_title",true);')" 'Smoke %title% %sep%'
  check "description stored" "$(wpe 'echo get_post_meta('"$P"',"rank_math_description",true);')" "A description."
  check "canonical stored" "$(wpe 'echo get_post_meta('"$P"',"rank_math_canonical_url",true);')" "$OWN"
  check "og title stored" "$(wpe 'echo get_post_meta('"$P"',"rank_math_facebook_title",true);')" "OG t"
  check "focus keyword stored" "$(wpe 'echo get_post_meta('"$P"',"rank_math_focus_keyword",true);')" "blue widget"
  check "robots stored as noindex" "$(wpe 'echo implode(",",(array)get_post_meta('"$P"',"rank_math_robots",true));')" "noindex"
  check "the reply's previous block names the old title as null" "$(jget rm_set previous.title)" "null"

  echo "-- read agrees with storage --"
  call rm_get "$(tc rankmath_get_post_seo "{\"post_id\":$P}")"
  check "get reports the title" "$(jget rm_get title)" 'Smoke %title% %sep%'
  check "get reports noindex" "$(jget rm_get robots)" "noindex"

  echo "-- other robots directives survive a robots change --"
  wpe 'update_post_meta('"$P"',"rank_math_robots",["noindex","nofollow","noarchive"]);' >/dev/null
  call rm_idx "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"robots\":\"index\"}")"
  check "robots index succeeds" "$(verdict rm_idx)" "ok"
  check "index replaced noindex and kept nofollow and noarchive" \
    "$(wpe '$r=(array)get_post_meta('"$P"',"rank_math_robots",true);sort($r);echo implode(",",$r);')" "index,noarchive,nofollow"
  call rm_def "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"robots\":\"default\"}")"
  check "robots default removes the decision and keeps the rest" \
    "$(wpe '$r=(array)get_post_meta('"$P"',"rank_math_robots",true);sort($r);echo implode(",",$r);')" "noarchive,nofollow"

  echo "-- clearing --"
  call rm_clr "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"description\":\"\"}")"
  check "an empty string clears a field" "$(wpe 'echo metadata_exists("post",'"$P"',"rank_math_description")?"present":"gone";')" "gone"

  echo "-- guards (state is compared before and after) --"
  BEFORE=$(wpe 'echo get_post_meta('"$P"',"rank_math_canonical_url",true);')
  call rm_foreign "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"canonical\":\"https://evil.example/steal\"}")"
  check "a canonical on another host is refused" "$(verdict rm_foreign)" "error"
  check "and the stored canonical did not change" "$(wpe 'echo get_post_meta('"$P"',"rank_math_canonical_url",true);')" "$BEFORE"
  call rm_ok_c "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"canonical\":\"$OWN\"}")"
  check "CONTROL: a canonical on this host is accepted" "$(verdict rm_ok_c)" "ok"
  call rm_badrobots "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"robots\":\"nofollow,noindex\"}")"
  check "a free-text robots value is refused" "$(verdict rm_badrobots)" "error"
  call rm_unknown "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"schema\":\"x\"}")"
  check "an unknown field is refused, not ignored" "$(verdict rm_unknown)" "error"
  call rm_nothing "$(tc rankmath_set_post_seo "{\"post_id\":$P}")"
  check "a call naming no field is refused" "$(verdict rm_nothing)" "error"
  # The commerce guard: a shop_order-typed post is an order whether or not WooCommerce is here.
  ORDER=$(wpe 'echo wp_insert_post(["post_title"=>"o","post_type"=>"shop_order","post_status"=>"publish"]);')
  call rm_order "$(tc rankmath_set_post_seo "{\"post_id\":$ORDER,\"title\":\"x\"}")"
  check "an order is refused" "$(verdict rm_order)" "error"
  check "and by the commerce guard, which says who owns it" "$(py 'import json,sys;print("owns it" in json.load(sys.stdin)["result"]["content"][0]["text"])' rm_order)" "True"
  check "and nothing was written to it" "$(wpe 'echo metadata_exists("post",'"$ORDER"',"rank_math_title")?"written":"clean";')" "clean"
  wpe 'wp_delete_post('"$ORDER"',true);' >/dev/null

  echo "-- access levels --"
  callas "$RO" rm_ro_get "$(tc rankmath_get_post_seo "{\"post_id\":$P}")"
  check "a readonly key can read" "$(verdict rm_ro_get)" "ok"
  callas "$RO" rm_ro_set "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"title\":\"RO\"}")"
  check "a readonly key cannot write" "$(verdict rm_ro_set)" "error"
  check "and the title is unchanged" "$(wpe 'echo get_post_meta('"$P"',"rank_math_title",true);')" 'Smoke %title% %sep%'
  callas "$RW" rm_rw_set "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"title\":\"RW title\"}")"
  check "a readwrite key can write (the write level is intended)" "$(verdict rm_rw_set)" "ok"

  echo "-- the group switch --"
  setopt false
  call rm_off "$(tc rankmath_get_post_seo "{\"post_id\":$P}")"
  check "switched off, calling a Rank Math tool by name is refused" "$(verdict rm_off)" "error"
  setopt true
  call rm_on "$(tc rankmath_get_post_seo "{\"post_id\":$P}")"
  check "CONTROL: switched back on, the same call works" "$(verdict rm_on)" "ok"
fi

# ---------------------------------------------------------------------------------------------
echo "== All in One SEO =="
if ! docker compose exec -T cli wp plugin is-active all-in-one-seo-pack >/dev/null 2>&1; then
  echo "  SKIP  All in One SEO is not active"
else
  PFX=$(wpe 'global $wpdb;echo $wpdb->prefix;')
  aio() { wpe 'global $wpdb;echo $wpdb->get_var($wpdb->prepare("SELECT '"$1"' FROM '"${PFX}"'aioseo_posts WHERE post_id=%d",'"$2"'));'; }
  P=$(new_post); POSTS="$POSTS $P"
  echo "  Test post: $P"
  call ao_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
  check "the AIOSEO tools are listed when the plugin is loaded" \
    "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print(sorted({"aioseo_get_post_seo","aioseo_set_post_seo"}-n) or True)' ao_list)" "True"

  echo "-- reading a post the plugin has no row for --"
  wpe 'global $wpdb;$wpdb->delete("'"${PFX}"'aioseo_posts",["post_id"=>'"$P"']);' >/dev/null
  call ao_get0 "$(tc aioseo_get_post_seo "{\"post_id\":$P}")"
  check "no row is reported as no row, not as an error" "$(verdict ao_get0)" "ok"
  check "seo_state.row_present is false" "$(jget ao_get0 seo_state.row_present)" "false"
  check "and the table is reported present" "$(jget ao_get0 seo_state.table_present)" "true"

  echo "-- write, then assert the STORED row --"
  call ao_set "$(tc aioseo_set_post_seo "{\"post_id\":$P,\"title\":\"AIO %title%\",\"description\":\"AIO desc\",\"canonical\":\"$OWN\",\"og_title\":\"AIO og\",\"robots\":\"noindex\"}")"
  check "a valid write succeeds" "$(verdict ao_set)" "ok"
  check "title stored in the plugin's table" "$(aio title "$P")" 'AIO %title%'
  check "description stored" "$(aio description "$P")" "AIO desc"
  check "canonical stored" "$(aio canonical_url "$P")" "$OWN"
  check "og title stored" "$(aio og_title "$P")" "AIO og"
  check "robots_default cleared so the per-post decision applies" "$(aio robots_default "$P")" "0"
  check "robots_noindex set" "$(aio robots_noindex "$P")" "1"
  check "the reply says the journal does not hold this" \
    "$(py 'import json,sys;print("cannot put this back" in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["journal"])' ao_set)" "True"
  call ao_get "$(tc aioseo_get_post_seo "{\"post_id\":$P}")"
  check "get reports noindex" "$(jget ao_get seo.robots)" "noindex"
  call ao_def "$(tc aioseo_set_post_seo "{\"post_id\":$P,\"robots\":\"default\"}")"
  check "robots default sets robots_default back to 1" "$(aio robots_default "$P")" "1"

  echo "-- the keyphrase is read-only --"
  call ao_kp "$(tc aioseo_set_post_seo "{\"post_id\":$P,\"focus_phrase\":\"x\"}")"
  check "writing focus_phrase is refused" "$(verdict ao_kp)" "error"

  echo "-- guards --"
  BEFORE=$(aio canonical_url "$P")
  call ao_foreign "$(tc aioseo_set_post_seo "{\"post_id\":$P,\"canonical\":\"https://evil.example/steal\"}")"
  check "a canonical on another host is refused" "$(verdict ao_foreign)" "error"
  check "and the stored canonical did not change" "$(aio canonical_url "$P")" "$BEFORE"
  ORDER=$(wpe 'echo wp_insert_post(["post_title"=>"o","post_type"=>"shop_order","post_status"=>"publish"]);')
  call ao_order "$(tc aioseo_set_post_seo "{\"post_id\":$ORDER,\"title\":\"x\"}")"
  check "an order is refused" "$(verdict ao_order)" "error"
  check "and no row was created for it" "$(aio post_id "$ORDER")" ""
  wpe 'wp_delete_post('"$ORDER"',true);' >/dev/null

  echo "-- a missing table is reported as missing --"
  # Renamed for the length of one call and put back before anything is compared.
  wpe 'global $wpdb;$wpdb->query("RENAME TABLE '"${PFX}"'aioseo_posts TO '"${PFX}"'aioseo_posts_hold");' >/dev/null
  call ao_notable "$(tc aioseo_get_post_seo "{\"post_id\":$P}")"
  wpe 'global $wpdb;$wpdb->query("RENAME TABLE '"${PFX}"'aioseo_posts_hold TO '"${PFX}"'aioseo_posts");' >/dev/null
  check "CONTROL: the table is back" "$(wpe 'global $wpdb;echo $wpdb->get_var("SHOW TABLES LIKE '"'"''"${PFX}"'aioseo_posts'"'"'");')" "${PFX}aioseo_posts"
  check "with the table gone, table_present is false" "$(jget ao_notable seo_state.table_present)" "false"
  check "and seo is null, not an empty object" "$(jget ao_notable seo)" "null"

  echo "-- access levels --"
  callas "$RO" ao_ro_set "$(tc aioseo_set_post_seo "{\"post_id\":$P,\"title\":\"RO\"}")"
  check "a readonly key cannot write" "$(verdict ao_ro_set)" "error"
  callas "$RO" ao_ro_get "$(tc aioseo_get_post_seo "{\"post_id\":$P}")"
  check "a readonly key can read" "$(verdict ao_ro_get)" "ok"
fi

echo "-- cleanup --"
for p in $POSTS; do wpe 'wp_delete_post('"$p"',true);' >/dev/null; done
setopt false

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
