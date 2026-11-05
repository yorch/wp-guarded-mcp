#!/bin/bash
# Smoke test for the Rank Math and All in One SEO tools.
#
# STATUS: written blind, then run against the real Rank Math 1.0.281 and All in One SEO 5.0.5
# (no Docker: SQLite and php -S). The storage assumptions it checks held: Rank Math's robots
# array and AIOSEO's robots_* columns are as seeded below. On that SQLite stack the two checks
# under "a missing table is reported as missing" fail for an environmental reason: the suite
# simulates the missing table with RENAME TABLE, which the SQLite drop-in does not perform, so
# the table is never missing (its "the table is back" control passes vacuously). They need a
# MySQL or MariaDB stack.
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
# A readwrite key that is also drafts-only (the sixth argument to GMCP_Tokens::create).
DO=$(docker compose exec -T cli wp eval '
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) === "smoke seo drafts-only" ) { GMCP_Tokens::revoke( $k["id"] ); }
    }
    $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $a = GMCP_Tokens::create( "smoke seo drafts-only", "readwrite", 0, [], $admins ? $admins[0]->ID : 0, true );
    echo $a["secret"];' 2>/dev/null | tr -d '\r\n')
for k in "$TOK" "$RO" "$RW" "$DO"; do
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
has() { python3 -c 'import json,sys
d=json.load(sys.stdin)
c=d.get("result",{}).get("content",[])
t=(c[0]["text"] if c else "")+json.dumps(d.get("error",""))
print("yes" if sys.argv[1] in t else "no")' "$2" < "$OUT/$1"; }
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
  # Rank Math falls back to the post type's setting only when the stored array is EMPTY, and
  # Paper::validate_robots() adds "index" to anything else, so "default" has to clear them all.
  # Seeded from the form the plugin stores (verified against Rank Math 1.0.281's
  # Paper::robots_combine, which keys a list of directive names).
  wpe 'update_post_meta('"$P"',"rank_math_robots",["nofollow"]);' >/dev/null
  call rm_eff "$(tc rankmath_get_post_seo "{\"post_id\":$P}")"
  check "a stored set with no index/noindex is reported as index (Rank Math adds it), not as default" "$(jget rm_eff robots)" "index"
  call rm_def "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"robots\":\"default\"}")"
  check "robots default clears EVERY directive, so the post type's setting applies again" \
    "$(wpe 'echo metadata_exists("post",'"$P"',"rank_math_robots")?"present":"gone";')" "gone"
  check "and the reply says what it removed" \
    "$(py 'import json,sys;print("nofollow" in " ".join(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["notes"]))' rm_def)" "True"
  call rm_def2 "$(tc rankmath_get_post_seo "{\"post_id\":$P}")"
  check "get now reports default" "$(jget rm_def2 robots)" "default"
  wpe 'update_post_meta('"$P"',"rank_math_robots",["index","nofollow"]);' >/dev/null
  call rm_ix "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"robots\":\"noindex\"}")"
  check "CONTROL: noindex keeps the other directives (only default clears them)" \
    "$(wpe '$r=(array)get_post_meta('"$P"',"rank_math_robots",true);sort($r);echo implode(",",$r);')" "nofollow,noindex"

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
  # A drafts-only key may not change a live page, and this tool is one of the writers it must
  # be held at: asked explicitly through GMCP_Core::post_write_guard().
  call rm_title0 "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"title\":\"before drafts-only\"}")"
  DRAFT=$(wpe 'echo wp_insert_post(["post_title"=>"SEO draft","post_status"=>"draft","post_content"=>"x"]);'); POSTS="$POSTS $DRAFT"
  callas "$DO" rm_do1 "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"title\":\"drafts-only wrote this\",\"robots\":\"noindex\"}")"
  check "a drafts-only key is refused on a live post" "$(verdict rm_do1)" "error"
  check "and the refusal is the drafts-only policy" "$(has rm_do1 "drafts-only")" "yes"
  check "and it is not reported as 'the SEO plugin threw an error'" "$(has rm_do1 "threw an error")" "no"
  check "STORED: the live post's title is unchanged" "$(wpe 'echo get_post_meta('"$P"',"rank_math_title",true);')" "before drafts-only"
  callas "$DO" rm_do2 "$(tc rankmath_set_post_seo "{\"post_id\":$DRAFT,\"title\":\"on a draft\"}")"
  check "CONTROL: the same key may write the same fields on a draft" "$(verdict rm_do2)" "ok"
  check "CONTROL: and STORED: the draft holds it" "$(wpe 'echo get_post_meta('"$DRAFT"',"rank_math_title",true);')" "on a draft"

  echo "-- access levels --"
  callas "$RO" rm_ro_get "$(tc rankmath_get_post_seo "{\"post_id\":$P}")"
  check "a readonly key can read" "$(verdict rm_ro_get)" "ok"
  callas "$RO" rm_ro_set "$(tc rankmath_set_post_seo "{\"post_id\":$P,\"title\":\"RO\"}")"
  check "a readonly key cannot write" "$(verdict rm_ro_set)" "error"
  check "and the title is unchanged" "$(wpe 'echo get_post_meta('"$P"',"rank_math_title",true);')" 'before drafts-only'
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
  # AIOSEO writes its own table through its own database layer, which no WordPress hook sees,
  # so the explicit guard is the only thing holding a drafts-only key here.
  call ao_t0 "$(tc aioseo_set_post_seo "{\"post_id\":$P,\"title\":\"before drafts-only\"}")"
  DRAFT=$(wpe 'echo wp_insert_post(["post_title"=>"AIO draft","post_status"=>"draft","post_content"=>"x"]);'); POSTS="$POSTS $DRAFT"
  callas "$DO" ao_do1 "$(tc aioseo_set_post_seo "{\"post_id\":$P,\"title\":\"drafts-only wrote this\",\"robots\":\"noindex\"}")"
  check "a drafts-only key is refused on a live post" "$(verdict ao_do1)" "error"
  check "STORED: the live post's title in AIOSEO's table is unchanged" "$(aio title "$P")" "before drafts-only"
  check "STORED: and its robots decision is unchanged" "$(aio robots_noindex "$P")" "0"
  callas "$DO" ao_do2 "$(tc aioseo_set_post_seo "{\"post_id\":$DRAFT,\"title\":\"on a draft\"}")"
  check "CONTROL: the same key may write a draft" "$(verdict ao_do2)" "ok"
  check "CONTROL: and STORED: the draft's row holds it" "$(aio title "$DRAFT")" "on a draft"

  echo "-- an explicit robots decision does not wake stale flags --"
  # A row that follows the site-wide setting (robots_default 1) with leftover flags that do
  # nothing yet: robots_nofollow and robots_noarchive from an old edit or an import.
  wpe 'global $wpdb;$wpdb->update("'"${PFX}"'aioseo_posts",["robots_default"=>1,"robots_noindex"=>0,"robots_nofollow"=>1,"robots_noarchive"=>1],["post_id"=>'"$P"']);' >/dev/null
  check "CONTROL: the stale flags are stored (the probe sees them)" "$(aio robots_nofollow "$P")$(aio robots_noarchive "$P")" "11"
  call ao_rb "$(tc aioseo_set_post_seo "{\"post_id\":$P,\"robots\":\"index\"}")"
  check "setting robots index succeeds" "$(verdict ao_rb)" "ok"
  check "STORED: robots_default is 0 now" "$(aio robots_default "$P")" "0"
  check "STORED: the stale nofollow flag was reset, not left to start applying" "$(aio robots_nofollow "$P")" "0"
  check "STORED: the stale noarchive flag was reset" "$(aio robots_noarchive "$P")" "0"
  check "and the reply says it did" "$(py 'import json,sys;print("robots_nofollow" in " ".join(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["notes"]))' ao_rb)" "True"
  wpe 'global $wpdb;$wpdb->update("'"${PFX}"'aioseo_posts",["robots_nofollow"=>1],["post_id"=>'"$P"']);' >/dev/null
  call ao_rb2 "$(tc aioseo_set_post_seo "{\"post_id\":$P,\"robots\":\"noindex\"}")"
  check "CONTROL: from a row that already made an explicit decision, a deliberate flag is kept" "$(aio robots_nofollow "$P")" "1"

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
