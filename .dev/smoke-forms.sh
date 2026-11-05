#!/bin/bash
# Smoke test for the form-submission tools: Contact Form 7 (with Flamingo), Fluent Forms and
# Ninja Forms, plus the generic-reader guard they depend on.
#
# STATUS: written blind, then run against the real Contact Form 7 6.2.4.1 + Flamingo 2.6.4,
# Fluent Forms 6.2.17 and Ninja Forms 3.16.0 on a SQLite stack (no Docker). Their storage matched
# what the tools read. Two checks fail there for an environmental reason: the Fluent block
# simulates a missing table with RENAME TABLE, which the SQLite drop-in does not perform, so
# "readable is false" and "counts are null" need MySQL or MariaDB. Seeds go through each
# plugin's own writer where one exists (Flamingo_Inbound_Message::add, wpcf7_flamingo_add_channel).
#
# BLOCK 0 needs no form plugin at all. It builds posts of the submission post types by hand
# and proves the generic readers refuse them, because that guard is in the always-on content
# tools and has to hold whether or not the forms group is on.
#
#   docker compose exec -T cli wp plugin install contact-form-7 flamingo --activate
#   ./smoke-forms.sh
#
# Every check asserts stored state or the full text of a reply, and every "X is absent" check
# is paired with a control proving the same probe finds X where it is present: a value is
# first shown to be in the database, and in an allowed field, before its absence from a reply
# means anything.
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
TOK=$(gmcp_make_key "smoke forms" admin)
RO=$(gmcp_make_key "smoke forms readonly" readonly)
RW=$(gmcp_make_key "smoke forms readwrite" readwrite)
for k in "$TOK" "$RO" "$RW"; do
  case "$k" in
    gmcp_*) ;;
    *) echo "Could not create an API key for the suite. Is the plugin active?" >&2; exit 1 ;;
  esac
done
OUT=$(mktemp -d)
pass=0; fail=0
callas() { curl -sS -X POST "$URL" -H "Authorization: Bearer $1" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$3" -o "$OUT/$2"; }
call() { callas "$TOK" "$1" "$2"; }
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1));
  else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
py() { python3 -c "$1" < "$OUT/$2"; }
verdict() { py 'import json,sys;d=json.load(sys.stdin);print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "$1"; }
# Does the whole reply (result text or error) contain a string?
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
    if isinstance(cur,list):
        try: cur=cur[int(part)]
        except Exception: cur=None
    elif isinstance(cur,dict): cur=cur.get(part)
    else: cur=None
print("null" if cur is None else (str(cur).lower() if isinstance(cur,bool) else cur))' "$1"; }
wpe() { docker compose exec -T cli wp eval "$1" 2>/dev/null | tr -d '\r\n'; }
tc() { printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2"; }
setopt() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_forms"]='"$1"';$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
setopt true
# wp_create_preview_link lives in the administration group, which defaults to off. Block 0 calls
# it as the control for the preview guard, so the group is switched on for the length of this run
# and put back at the end. Without this the suite only passed on a stack where an earlier suite
# had left the group on, and on a fresh one reported "requires 'admin' access" as two failures
# that described the guard rather than the missing switch.
ADMIN_WAS=$(wpe 'echo empty(get_option("gmcp_options",[])["mcp_tools_admin"])?"false":"true";')
setadmin() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_admin"]='"$1"';update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
setadmin true
check "CONTROL: the administration group (preview links) is on for this run" \
  "$(wpe 'global $gmcp_core;echo $gmcp_core->get_option("mcp_tools_admin")?"on":"off";')" "on"
CLEAN=""

# ---------------------------------------------------------------------------------------------
echo "== Block 0: the generic readers refuse submission post types =="
# Nothing here needs a form plugin: a post of the type is enough, because the guard asks the
# post's type and not whether any plugin is loaded.
MARK="LEAKMARK-$RANDOM"
mkpost() { wpe 'echo wp_insert_post(["post_title"=>"t","post_type"=>"'"$1"'","post_status"=>"publish","post_content"=>"'"$MARK"'-body"]);'; }
NF=$(mkpost nf_sub); FL=$(mkpost flamingo_inbound); NORMAL=$(mkpost post)
CLEAN="$CLEAN $NF $FL $NORMAL"
wpe 'update_post_meta('"$NF"',"_field_1","'"$MARK"'-meta");update_post_meta('"$FL"',"_meta",["remote_ip"=>"'"$MARK"'-ip"]);update_post_meta('"$NORMAL"',"_field_1","'"$MARK"'-meta");' >/dev/null
check "CONTROL: the marker really is stored on the submission-type posts" \
  "$(wpe 'echo get_post_meta('"$NF"',"_field_1",true);')" "$MARK-meta"

# Below admin a submission is answered exactly as a post that does not exist; an admin key is
# told why, because it reads submissions through the form tools anyway.
for tool_json in \
  "wp_get_post_meta|{\"ID\":$NF,\"key\":\"_field_1\"}" \
  "wp_get_post_meta|{\"ID\":$NF}" \
  "wp_get_post_meta|{\"ID\":$FL}" \
  "wp_get_post|{\"ID\":$NF}" \
  "wp_get_post_snapshot|{\"ID\":$FL}" \
  "wp_read_post_meta_chunk|{\"ID\":$NF,\"key\":\"_field_1\"}"; do
  t="${tool_json%%|*}"; a="${tool_json#*|}"
  callas "$RO" g0 "$(tc "$t" "$a")"
  check "a read-only key is refused $t on a submission post ($a)" "$(verdict g0)" "error"
  check "and the reply does not contain the stored value" "$(has g0 "$MARK")" "no"
  pid=$(printf '%s' "$a" | grep -o '"ID":[0-9]*' | cut -d: -f2)
  check "and the answer is the one a post that does not exist gets" "$(has g0 "No post with ID $pid.")" "yes"
  callas "$TOK" g0a "$(tc "$t" "$a")"
  check "CONTROL: an admin key is refused too, and told why (the personal-data guard is what answered)" "$(has g0a "form submission")" "yes"
done
callas "$RO" g0 "$(tc wp_get_post_snapshot "{\"ID\":$NORMAL}")"
check "CONTROL: wp_get_post_snapshot on an ordinary post succeeds, so its refusal above was the guard" "$(verdict g0)" "ok"
callas "$RO" g0 "$(tc wp_read_post_meta_chunk "{\"ID\":$NORMAL,\"key\":\"_field_1\"}")"
check "CONTROL: wp_read_post_meta_chunk on an ordinary post succeeds" "$(verdict g0)" "ok"
callas "$RO" g0 "$(tc wp_get_posts '{"post_type":"nf_sub","post_status":"any"}')"
check "wp_get_posts naming a submission post type is refused" "$(verdict g0)" "error"
check "and it is the personal-data guard that refused" "$(has g0 "form submission")" "yes"
callas "$RO" g0 "$(tc wp_get_posts '{"post_type":"post","post_status":"any"}')"
check "CONTROL: wp_get_posts for an ordinary type succeeds" "$(verdict g0)" "ok"
callas "$RO" g0 "$(tc wp_get_post_meta "{\"ID\":$NORMAL,\"key\":\"_field_1\"}")"
check "CONTROL: the same call on an ordinary post succeeds" "$(verdict g0)" "ok"
check "and the probe finds the marker when it is there" "$(has g0 "$MARK")" "yes"
callas "$RO" g0 "$(tc wp_get_post "{\"ID\":$NORMAL}")"
check "CONTROL: wp_get_post on an ordinary post succeeds" "$(verdict g0)" "ok"

# ---------------------------------------------------------------------------------------------
echo "== Block 0b: a write tool is not a way round the read guard =="
# A readwrite key cannot read a submission. These are the routes that would let it read one
# anyway: copy the meta onto its own draft, retype the post into an ordinary type, duplicate it,
# or read it through a URI. Each hostile call is followed by reading the STORED state, and the
# state is put back before comparing. Controls run the same call on an ordinary post.
docker compose exec -T cli sh -c 'cat > /var/www/html/wp-content/mu-plugins/gmcp-smoke-forms-type.php' <<'PHP'
<?php
// A form-submission type that "any" will return, which is how a per-row check is reached.
add_action( 'init', function () {
  register_post_type( 'flamingo_inbound', [ 'public' => true, 'show_in_rest' => false, 'label' => 'smoke submission' ] );
}, 99 );
PHP
DRAFT=$(wpe 'echo wp_insert_post(["post_title"=>"smoke draft","post_type"=>"post","post_status"=>"draft"]);')
SUBID=$(wpe 'echo wp_insert_post(["post_title"=>"smoke submission","post_type"=>"flamingo_inbound","post_status"=>"publish","post_content"=>"'"$MARK"'-body"]);')
SUBID2=$(wpe 'echo wp_insert_post(["post_title"=>"smoke submission 2","post_type"=>"flamingo_inbound","post_status"=>"draft","post_content"=>"'"$MARK"'-body2"]);')
CLEAN="$CLEAN $DRAFT $SUBID $SUBID2"
wpe 'update_post_meta('"$SUBID"',"_meta",["remote_ip"=>"'"$MARK"'-ip"]);update_post_meta('"$SUBID"',"_field_message","'"$MARK"'-field");' >/dev/null
check "CONTROL: the submission carries the marker in its meta, and the draft starts with none" \
  "$(wpe 'echo get_post_meta('"$SUBID"',"_field_message",true), "|", count(get_post_meta('"$DRAFT"'));')" "$MARK-field|0"

callas "$RW" w1 "$(tc wp_copy_post_meta "{\"from_id\":$SUBID,\"to_id\":$DRAFT}")"
check "wp_copy_post_meta FROM a submission is refused" "$(verdict w1)" "error"
check "STORED: the draft holds none of the submission's keys" \
  "$(wpe 'echo count(array_intersect(array_keys(get_post_meta('"$DRAFT"')), ["_meta","_field_message"]));')" "0"
check "and the answer is the one a missing source gets" "$(has w1 "No source post with ID $SUBID.")" "yes"
callas "$RW" w1b "$(tc wp_copy_post_meta "{\"from_id\":$SUBID,\"to_id\":$DRAFT,\"keys\":[\"_meta\"]}")"
check "naming the key does not help" "$(verdict w1b)" "error"
check "STORED: still no _meta on the draft" "$(wpe 'echo metadata_exists("post",'"$DRAFT"',"_meta")?"yes":"no";')" "no"
callas "$RW" w1c "$(tc wp_copy_post_meta "{\"from_id\":$NORMAL,\"to_id\":$DRAFT}")"
check "CONTROL: copying from an ordinary post onto the same draft succeeds" "$(verdict w1c)" "ok"
check "CONTROL: and STORED: the draft now holds the ordinary post's key (the probe can see a copy)" \
  "$(wpe 'echo get_post_meta('"$DRAFT"',"_field_1",true);')" "$MARK-meta"

callas "$RW" w2 "$(tc wp_update_post "{\"ID\":$SUBID,\"fields\":{\"post_type\":\"post\"}}")"
check "retyping a submission to an ordinary type is refused" "$(verdict w2)" "error"
check "STORED: its type is unchanged" "$(wpe 'echo get_post_type('"$SUBID"');')" "flamingo_inbound"
callas "$RW" w2b "$(tc wp_update_post "{\"ID\":$SUBID,\"post_type\":\"page\"}")"
check "the same with post_type at the top level" "$(verdict w2b)" "error"
check "STORED: its type is still unchanged" "$(wpe 'echo get_post_type('"$SUBID"');')" "flamingo_inbound"
callas "$RW" w2c "$(tc wp_get_post "{\"ID\":$SUBID}")"
check "and wp_get_post still refuses it" "$(has w2c "$MARK")" "no"
callas "$RW" w3 "$(tc wp_update_post "{\"ID\":$NORMAL,\"fields\":{\"post_type\":\"flamingo_inbound\"}}")"
check "retyping an ordinary post INTO a submission type is refused" "$(verdict w3)" "error"
check "STORED: it is still a post" "$(wpe 'echo get_post_type('"$NORMAL"');')" "post"
callas "$RW" w3b "$(tc wp_update_post "{\"ID\":$NORMAL,\"fields\":{\"post_type\":\"page\"}}")"
check "CONTROL: retyping an ordinary post to page succeeds" "$(verdict w3b)" "ok"
check "CONTROL: and STORED: it is a page" "$(wpe 'echo get_post_type('"$NORMAL"');')" "page"
wpe 'wp_update_post(["ID"=>'"$NORMAL"',"post_type"=>"post"]);' >/dev/null
check "restored: the ordinary post is a post again" "$(wpe 'echo get_post_type('"$NORMAL"');')" "post"

BEFORE=$(wpe 'echo count(get_posts(["post_type"=>"flamingo_inbound","post_status"=>"any","numberposts"=>-1]));')
callas "$RW" w4 "$(tc wp_create_post '{"post_title":"forged","post_type":"flamingo_inbound"}')"
check "creating a submission-type post is refused" "$(verdict w4)" "error"
callas "$RW" w4b "$(tc wp_duplicate_post "{\"ID\":$SUBID}")"
check "duplicating a submission is refused" "$(verdict w4b)" "error"
check "STORED: no submission-type post was added by either" \
  "$(wpe 'echo count(get_posts(["post_type"=>"flamingo_inbound","post_status"=>"any","numberposts"=>-1]));')" "$BEFORE"
callas "$RW" w4c "$(tc wp_duplicate_post "{\"ID\":$NORMAL}")"
check "CONTROL: duplicating an ordinary post succeeds" "$(verdict w4c)" "ok"
DUPID=$(wpe 'echo (int) get_posts(["post_type"=>"post","post_status"=>"draft","numberposts"=>1,"orderby"=>"ID","order"=>"DESC","fields"=>"ids"])[0];')
CLEAN="$CLEAN $DUPID"

callas "$RW" w5 "$(tc wp_update_post "{\"ID\":$SUBID,\"post_title\":\"x\",\"preview\":true}")"
check "previewing an update to a submission is refused (a preview prints the start of the body)" "$(verdict w5)" "error"
callas "$RW" w5b "$(tc wp_alter_post "{\"ID\":$SUBID,\"field\":\"post_content\",\"search\":\"$MARK\",\"replace\":\"x\",\"preview\":true}")"
check "previewing a search-and-replace on a submission is refused (a match count is an oracle)" "$(verdict w5b)" "error"
callas "$RW" w5c "$(tc wp_alter_post "{\"ID\":$SUBID,\"field\":\"post_content\",\"search\":\"$MARK\",\"replace\":\"x\"}")"
check "and so is the real one" "$(verdict w5c)" "error"
check "STORED: the body is unchanged" "$(wpe 'echo get_post('"$SUBID"')->post_content;')" "$MARK-body"
callas "$RW" w5d "$(tc wp_alter_post "{\"ID\":$NORMAL,\"field\":\"post_content\",\"search\":\"$MARK\",\"replace\":\"$MARK\",\"preview\":true}")"
check "CONTROL: the same preview on an ordinary post succeeds" "$(verdict w5d)" "ok"

callas "$RO" w6 "$(tc wp_get_posts '{"post_type":"any","post_status":"any","limit":50}')"
check "CONTROL: wp_get_posts type any succeeds and lists the ordinary post (the probe sees a row)" \
  "$(has w6 "\"ID\": $NORMAL")" "yes"
check "wp_get_posts type any does not list a submission row" "$(has w6 "\"ID\": $SUBID")" "no"
callas "$RO" w7 '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"gmcp://post/'"$SUBID2"'"}}'
# resources/read answers under result.contents, which has() does not look at, so these two
# search the raw reply. The control below proves the probe sees a body that is there.
rawhas() { grep -qF -- "$2" "$OUT/$1" && echo yes || echo no; }
check "reading a submission through a gmcp://post resource returns nothing" "$(rawhas w7 "$MARK-body2")" "no"
check "CONTROL: that submission's body is stored, so its absence above is the guard's" "$(wpe 'echo get_post('"$SUBID2"')->post_content;')" "$MARK-body2"
callas "$RO" w7b '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"gmcp://post/'"$NORMAL"'"}}'
check "CONTROL: the same resource for an ordinary post returns its body" "$(rawhas w7b "$MARK-body")" "yes"
callas "$RW" w8 "$(tc wp_create_preview_link "{\"post_id\":$DRAFT}")"
check "CONTROL: a preview link for an ordinary draft is minted" "$(verdict w8)" "ok"
callas "$RW" w8b "$(tc wp_create_preview_link "{\"post_id\":$SUBID2}")"
check "a preview link to an unpublished submission is not minted" "$(verdict w8b)" "error"
check "and the answer is the one a post that does not exist gets" "$(has w8b "No post with ID $SUBID2.")" "yes"
docker compose exec -T cli rm -f /var/www/html/wp-content/mu-plugins/gmcp-smoke-forms-type.php >/dev/null 2>&1

# ---------------------------------------------------------------------------------------------
echo "== Block 0c: what hangs off a submission, and the other readers of a post =="
# The guards above ask a post's own type. A revision of a submission, a file attached to one, a
# comment on one, its terms, the change journal that names it, the briefing that quotes the newest
# post and the REST-generated tools all reach the same data through something that is not of that
# type. Every hostile call is made with a read-only key against a SEEDED submission (a
# flamingo_inbound registered by a mu-plugin, so no form plugin is needed), and every check that
# something is absent is paired with a control that the same call finds the marker on an ordinary
# post. A shop order stands in for the commerce half, below admin.
M2="H2MARK-$RANDOM"
docker compose exec -T cli sh -c 'cat > /var/www/html/wp-content/mu-plugins/gmcp-smoke-forms-rel.php' <<'PHP'
<?php
// A submission type with a taxonomy, revisions and comments; a vocabulary shared with ordinary
// posts; a stand-in order type when no shop plugin registers one.
add_action( 'init', function () {
  if ( !post_type_exists( 'flamingo_inbound' ) ) {
    register_post_type( 'flamingo_inbound', [ 'public' => true, 'show_in_rest' => false, 'label' => 'smoke submission',
      'supports' => [ 'title', 'editor', 'excerpt', 'comments', 'revisions', 'custom-fields' ] ] );
  }
  // With the real form plugin active the type already exists, non-public and without revisions or
  // comments. The seed below needs a submission with a revision and a comment that "any" can find.
  foreach ( [ 'revisions', 'comments', 'title', 'editor', 'excerpt', 'custom-fields' ] as $feature ) {
    add_post_type_support( 'flamingo_inbound', $feature );
  }
  if ( isset( $GLOBALS['wp_post_types']['flamingo_inbound'] ) ) {
    $GLOBALS['wp_post_types']['flamingo_inbound']->exclude_from_search = false;
  }
  register_taxonomy( 'gmcp_smoke_channel', [ 'flamingo_inbound' ], [ 'public' => true, 'hierarchical' => true, 'label' => 'smoke channel' ] );
  register_taxonomy( 'gmcp_smoke_shared', [ 'flamingo_inbound', 'post' ], [ 'public' => true, 'hierarchical' => true, 'label' => 'smoke shared' ] );
  if ( !post_type_exists( 'shop_order' ) ) {
    register_post_type( 'shop_order', [ 'public' => true, 'show_in_rest' => false, 'label' => 'smoke order', 'supports' => [ 'title', 'editor', 'comments', 'custom-fields' ] ] );
  }
}, 99 );
// Lets the suite make a page type look like a form-submission type, to prove that a REST route
// belonging to a guarded type is not turned into tools.
add_filter( 'gmcp_personal_data_post_types', function ( $types ) {
  if ( get_option( 'gmcp_smoke_guard_pages' ) ) {
    $types[] = 'page';
  }
  return $types;
} );
function gmcp_smoke_seed( $m ) {
  $out = [];
  foreach ( [ 'sub' => 'flamingo_inbound', 'ctl' => 'post', 'ord' => 'shop_order' ] as $k => $type ) {
    $tag = $m . '-' . $k;
    $id = wp_insert_post( [ 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $tag . '-title', 'post_content' => $tag . '-content [elementor-template id="7771"]', 'post_excerpt' => $tag . '-excerpt', 'comment_status' => 'open' ] );
    update_post_meta( $id, '_field_message', $tag . '-meta' );
    $cid = wp_insert_comment( [ 'comment_post_ID' => $id, 'comment_content' => $tag . '-comment', 'comment_author' => 'a', 'comment_approved' => 1 ] );
    $tax = $type === 'flamingo_inbound' ? 'gmcp_smoke_channel' : 'category';
    $term = wp_insert_term( $tag . '-term', $tax );
    wp_set_object_terms( $id, [ (int) $term['term_id'] ], $tax );
    wp_update_post( [ 'ID' => $id, 'post_content' => $tag . '-content-v2 [elementor-template id="7771"]' ] );
    $revs = array_keys( wp_get_post_revisions( $id ) );
    $att = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $id, 'post_title' => $tag . '-att-title', 'post_mime_type' => 'text/plain', 'guid' => home_url( '/' . $tag . '-att.txt' ) ] );
    $out[ $k ] = [ 'id' => $id, 'comment' => (int) $cid, 'rev' => $revs ? (int) $revs[0] : 0, 'att' => (int) $att, 'term' => (int) $term['term_id'], 'tax' => $tax ];
  }
  return $out;
}
// Stands in for a form plugin's own bookkeeping from a hook a tool call can fire: Flamingo saves a
// contact (a post of a guarded type, with meta) every time a user profile is saved.
add_action( 'profile_update', function ( $user_id ) {
  if ( !get_option( 'gmcp_smoke_sideeffect' ) ) {
    return;
  }
  $id = (int) get_option( 'gmcp_smoke_contact' );
  if ( !$id || !get_post( $id ) ) {
    $id = wp_insert_post( [ 'post_type' => 'flamingo_contact', 'post_status' => 'publish', 'post_title' => 'contact-created' ] );
    update_option( 'gmcp_smoke_contact', $id, false );
  }
  wp_update_post( [ 'ID' => $id, 'post_title' => 'contact-updated-' . $user_id ] );
  update_post_meta( $id, '_gmcp_smoke_side', 'meta-' . $user_id );
} );
// A tool that exists only for this suite. It writes through WordPress directly, and with
// net=off with the net the plugin installs under its tools taken out first, which is how the
// change records are shown to hold when a writer asks none of the guards.
add_filter( 'gmcp_tools', function ( $tools ) {
  $tools[] = [ 'name' => 'gmcp_smoke_rawwrite', 'description' => 'Test only.', 'category' => 'smoke', 'accessLevel' => 'write',
    'inputSchema' => [ 'type' => 'object', 'properties' => [ 'op' => [ 'type' => 'string' ], 'id' => [ 'type' => 'integer' ],
      'parent' => [ 'type' => 'integer' ], 'key' => [ 'type' => 'string' ], 'value' => [ 'type' => 'string' ], 'net' => [ 'type' => 'string' ] ], 'required' => [ 'op' ] ] ];
  return $tools;
} );
add_filter( 'gmcp_callback', function ( $existing, $tool, $args ) {
  if ( $tool !== 'gmcp_smoke_rawwrite' ) {
    return $existing;
  }
  if ( ( $args['net'] ?? '' ) === 'off' ) {
    foreach ( [ 'wp_insert_post_data', 'wp_insert_attachment_data' ] as $h ) {
      remove_filter( $h, [ 'GMCP_Core', 'pd_net_insert' ], PHP_INT_MAX - 1 );
    }
    foreach ( [ 'add', 'update', 'delete' ] as $op ) {
      remove_filter( $op . '_post_metadata', [ 'GMCP_Core', 'pd_net_meta' ], PHP_INT_MIN + 1 );
    }
    foreach ( [ 'pre_delete_post', 'pre_delete_attachment', 'pre_trash_post', 'pre_untrash_post' ] as $h ) {
      remove_filter( $h, [ 'GMCP_Core', 'pd_net_remove' ], PHP_INT_MIN + 1 );
    }
    remove_filter( 'wp_update_comment_data', [ 'GMCP_Core', 'pd_net_comment_update' ], PHP_INT_MAX - 1 );
  }
  $id = (int) ( $args['id'] ?? 0 );
  switch ( $args['op'] ?? '' ) {
    case 'parent':
      $r = wp_update_post( [ 'ID' => $id, 'post_parent' => (int) ( $args['parent'] ?? 0 ) ], true );
      break;
    case 'title':
      $r = wp_update_post( [ 'ID' => $id, 'post_title' => (string) ( $args['value'] ?? '' ) ], true );
      break;
    case 'meta':
      $r = update_post_meta( $id, (string) ( $args['key'] ?? '' ), (string) ( $args['value'] ?? '' ) );
      break;
    case 'create':
      $r = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => (string) ( $args['value'] ?? 'raw' ), 'post_parent' => (int) ( $args['parent'] ?? 0 ) ], true );
      break;
    case 'comment_post':
      $r = wp_update_comment( [ 'comment_ID' => $id, 'comment_post_ID' => (int) ( $args['parent'] ?? 0 ) ] );
      break;
    default:
      $r = 'unknown op';
  }
  return is_wp_error( $r ) ? 'error: ' . $r->get_error_message() : 'done ' . wp_json_encode( $r );
}, 10, 3 );
PHP
SEED=$(wpe 'echo wp_json_encode(gmcp_smoke_seed("'"$M2"'"));')
sid() { python3 -c 'import json,sys;print(json.loads(sys.argv[1])[sys.argv[2]][sys.argv[3]])' "$SEED" "$1" "$2"; }
SUB=$(sid sub id); SUBREV=$(sid sub rev); SUBATT=$(sid sub att); SUBCOM=$(sid sub comment)
CTL=$(sid ctl id); CTLREV=$(sid ctl rev); CTLATT=$(sid ctl att)
ORD=$(sid ord id); ORDATT=$(sid ord att)
CLEAN="$CLEAN $SUB $SUBATT $CTL $CTLATT $ORD $ORDATT"
deny() { # deny <label> <key> <tool> <args> <marker>: refused, and the marker is not in the reply
  callas "$2" d0 "$(tc "$3" "$4")"
  check "$1: refused" "$(verdict d0)" "error"
  check "$1: the reply carries no marker" "$(has d0 "$5")" "no"
}
quiet() { # quiet <label> <key> <tool> <args> <marker>: answers, and the marker is not in the reply
  callas "$2" d0 "$(tc "$3" "$4")"
  check "$1: answers" "$(verdict d0)" "ok"
  check "$1: the reply carries no marker" "$(has d0 "$5")" "no"
}
allow() { # allow <label> <key> <tool> <args> <marker>: answers and the marker IS in the reply
  callas "$2" d0 "$(tc "$3" "$4")"
  check "CONTROL $1: answers" "$(verdict d0)" "ok"
  check "CONTROL $1: the probe finds the marker" "$(has d0 "$5")" "yes"
}
check "CONTROL: the seed stored a marker on the submission, its revision, its upload and its comment" \
  "$(wpe 'echo get_post_meta('"$SUB"',"_field_message",true),"|",get_post('"$SUBREV"')->post_parent==='"$SUB"'?"rev":"norev","|",get_post('"$SUBATT"')->post_parent==='"$SUB"'?"att":"noatt","|",get_comment('"$SUBCOM"')->comment_post_ID=='"$SUB"'?"com":"nocom";')" "$M2-sub-meta|rev|att|com"
check "CONTROL: the order type has the same rows" \
  "$(wpe 'echo get_post_type('"$ORD"'),"|",get_post('"$ORDATT"')->post_parent==='"$ORD"'?"att":"noatt";')" "shop_order|att"

echo "-- a revision, an upload or a comment of a submission is the submission --"
deny "wp_get_post on a revision of a submission" "$RO" wp_get_post "{\"ID\":$SUBREV}" "$M2-sub"
deny "wp_get_post on a file attached to a submission" "$RO" wp_get_post "{\"ID\":$SUBATT}" "$M2-sub"
deny "wp_get_post_snapshot on a file attached to a submission" "$RO" wp_get_post_snapshot "{\"ID\":$SUBATT}" "$M2-sub"
deny "wp_get_post_meta on a revision of a submission" "$RO" wp_get_post_meta "{\"ID\":$SUBREV}" "$M2-sub"
allow "wp_get_post on a revision of an ordinary post" "$RO" wp_get_post "{\"ID\":$CTLREV}" "$M2-ctl"
allow "wp_get_post on a file attached to an ordinary post" "$RO" wp_get_post "{\"ID\":$CTLATT}" "$M2-ctl"
quiet "wp_get_posts listing revisions" "$RO" wp_get_posts '{"post_type":"revision","post_status":"inherit","limit":200}' "$M2-sub"
allow "wp_get_posts listing revisions" "$RO" wp_get_posts '{"post_type":"revision","post_status":"inherit","limit":200}' "$M2-ctl"
quiet "wp_get_posts listing attachments" "$RO" wp_get_posts '{"post_type":"attachment","post_status":"inherit","limit":200}' "$M2-sub"
allow "wp_get_posts listing attachments" "$RO" wp_get_posts '{"post_type":"attachment","post_status":"inherit","limit":200}' "$M2-ctl"
quiet "wp_get_media" "$RO" wp_get_media '{"search":"'"$M2"'","limit":200}' "$M2-sub"
allow "wp_get_media" "$RO" wp_get_media '{"search":"'"$M2"'","limit":200}' "$M2-ctl"
quiet "wp_get_comments with no post named" "$RO" wp_get_comments '{"search":"'"$M2"'","limit":500}' "$M2-sub"
allow "wp_get_comments with no post named" "$RO" wp_get_comments '{"search":"'"$M2"'","limit":500}' "$M2-ctl"
quiet "wp_get_comments naming the submission" "$RO" wp_get_comments "{\"post_id\":$SUB}" "$M2-sub"
allow "wp_get_comments naming the ordinary post" "$RO" wp_get_comments "{\"post_id\":$CTL}" "$M2-ctl"
quiet "wp_get_comments by an ADMIN key naming the submission (the guard applies at every level)" "$TOK" wp_get_comments "{\"post_id\":$SUB}" "$M2-sub"
callas "$RW" d1 "$(tc wp_duplicate_post "{\"ID\":$SUBATT}")"
check "wp_duplicate_post of a file attached to a submission is refused" "$(verdict d1)" "error"
callas "$RW" d1 "$(tc wp_duplicate_post "{\"ID\":$SUBREV}")"
check "wp_duplicate_post of a revision of a submission is refused" "$(verdict d1)" "error"
check "STORED: neither made a copy carrying the marker" \
  "$(wpe 'echo count(get_posts(["post_type"=>"any","post_status"=>"any","numberposts"=>-1,"s"=>"'"$M2"'-sub","post__not_in"=>['"$SUB"','"$SUBATT"']]));')" "0"
callas "$RW" d1 "$(tc wp_duplicate_post "{\"ID\":$CTL}")"
check "CONTROL: wp_duplicate_post of an ordinary post succeeds" "$(verdict d1)" "ok"
CLEAN="$CLEAN $(wpe 'echo (int) get_posts(["post_type"=>"post","post_status"=>"draft","numberposts"=>1,"orderby"=>"ID","order"=>"DESC","fields"=>"ids"])[0];')"

echo "-- below admin, the same for what hangs off an order --"
deny "wp_get_post on a file attached to an order" "$RO" wp_get_post "{\"ID\":$ORDATT}" "$M2-ord"
quiet "wp_get_media" "$RO" wp_get_media '{"search":"'"$M2"'","limit":200}' "$M2-ord"
quiet "wp_get_comments naming the order" "$RO" wp_get_comments "{\"post_id\":$ORD}" "$M2-ord"
allow "wp_get_post on a file attached to an order, by an ADMIN key (orders are admin-level data, not unreadable)" "$TOK" wp_get_post "{\"ID\":$ORDATT}" "$M2-ord"

echo "-- terms: the terms of a submission, and a vocabulary that exists for submissions --"
SUBTAX=$(sid sub tax)
deny "wp_get_post_terms on a submission" "$RO" wp_get_post_terms "{\"ID\":$SUB,\"taxonomy\":\"$SUBTAX\"}" "$M2-sub"
allow "wp_get_post_terms on an ordinary post" "$RO" wp_get_post_terms "{\"ID\":$CTL,\"taxonomy\":\"category\"}" "$M2-ctl"
deny "wp_get_terms of a taxonomy attached to submissions only" "$RO" wp_get_terms "{\"taxonomy\":\"$SUBTAX\",\"search\":\"$M2\"}" "$M2-sub"
deny "the same by an ADMIN key" "$TOK" wp_get_terms "{\"taxonomy\":\"$SUBTAX\",\"search\":\"$M2\"}" "$M2-sub"
allow "wp_get_terms of an ordinary taxonomy" "$RO" wp_get_terms '{"taxonomy":"category","search":"'"$M2"'"}' "$M2-ctl"
callas "$RO" d2 "$(tc wp_get_terms '{"taxonomy":"gmcp_smoke_shared"}')"
check "CONTROL: a taxonomy shared with an ordinary post type is still listed (the guard is not a blanket)" "$(verdict d2)" "ok"
BEFORE_T=$(wpe 'echo count(wp_get_object_terms('"$SUB"',"'"$SUBTAX"'",["fields"=>"ids"]));')
deny "wp_add_post_terms on a submission (its reply lists the terms the post now holds)" "$RW" wp_add_post_terms "{\"ID\":$SUB,\"taxonomy\":\"$SUBTAX\",\"terms\":[$(sid sub term)]}" "$M2-sub"
check "STORED: the submission's terms are unchanged" "$(wpe 'echo count(wp_get_object_terms('"$SUB"',"'"$SUBTAX"'",["fields"=>"ids"]));')" "$BEFORE_T"
callas "$RW" d2 "$(tc wp_create_term "{\"term_name\":\"$M2-extra\",\"taxonomy\":\"category\"}")"
EXTRA=$(wpe 'echo (int) term_exists("'"$M2"'-extra","category")["term_id"];')
callas "$RW" d2 "$(tc wp_add_post_terms "{\"ID\":$CTL,\"taxonomy\":\"category\",\"terms\":[$EXTRA]}")"
check "CONTROL: wp_add_post_terms on an ordinary post succeeds" "$(verdict d2)" "ok"
check "CONTROL: and STORED: the term is on the ordinary post" "$(wpe 'echo in_array('"$EXTRA"',wp_get_object_terms('"$CTL"',"category",["fields"=>"ids"]))?"yes":"no";')" "yes"
wpe 'wp_delete_term('"$EXTRA"',"category");' >/dev/null

echo "-- counts are an existence oracle and are kept --"
callas "$RO" d3 "$(tc wp_count_posts '{"post_type":"flamingo_inbound"}')"
check "wp_count_posts for a submission type answers (a number, never a row)" "$(verdict d3)" "ok"
check "and it carries no marker" "$(has d3 "$M2")" "no"

echo "-- the briefing quotes the newest post --"
wpe 'wp_update_post(["ID"=>'"$SUB"',"post_title"=>"'"$M2"'-sub-title"]);global $wpdb;$wpdb->update($wpdb->posts,["post_modified"=>"2099-01-01 00:00:00","post_modified_gmt"=>"2099-01-01 00:00:00"],["ID"=>'"$SUB"']);clean_post_cache('"$SUB"');' >/dev/null
check "CONTROL: the submission is the most recently modified post" \
  "$(wpe 'echo get_posts(["post_type"=>"any","post_status"=>["publish","draft","pending","private"],"numberposts"=>1,"orderby"=>"modified","order"=>"DESC","fields"=>"ids","suppress_filters"=>false])[0]=='"$SUB"'?"yes":"no";')" "yes"
callas "$RO" d4 "$(tc wp_site_briefing '{}')"
if [ "$(verdict d4)" = "ok" ]; then
  check "wp_site_briefing does not quote the submission's title as the latest change" "$(has d4 "$M2-sub")" "no"
  wpe 'global $wpdb;$wpdb->update($wpdb->posts,["post_modified"=>"2099-06-01 00:00:00","post_modified_gmt"=>"2099-06-01 00:00:00"],["ID"=>'"$CTL"']);clean_post_cache('"$CTL"');' >/dev/null
  callas "$RO" d4 "$(tc wp_site_briefing '{}')"
  check "CONTROL: once an ordinary post is newer the briefing quotes it" "$(has d4 "$M2-ctl")" "yes"
else
  echo "  SKIP  wp_site_briefing is not offered (the admin tool group is off); its guard was not exercised"
fi
wpe 'global $wpdb;$wpdb->update($wpdb->posts,["post_modified"=>current_time("mysql"),"post_modified_gmt"=>current_time("mysql",true)],["ID"=>'"$SUB"']);$wpdb->update($wpdb->posts,["post_modified"=>current_time("mysql"),"post_modified_gmt"=>current_time("mysql",true)],["ID"=>'"$CTL"']);clean_post_cache('"$SUB"');clean_post_cache('"$CTL"');' >/dev/null

echo "-- the change journal and the audit log do not name or quote a submission --"
JOURNAL_WAS=$(wpe 'echo get_option("gmcp_options",[])["mcp_change_journal"]??"unset";')
wpe '$o=get_option("gmcp_options",[]);$o["mcp_change_journal"]=true;update_option("gmcp_options",$o,false);GMCP_Journal::clear();' >/dev/null
# A write to a submission, or to anything in its tree, is refused at every level, so a record about
# one can only come from a writer that asks no guard. The suite registers a test-only tool that
# writes through WordPress directly, with the net under the tools taken out (net=off): the
# defence in depth, which is that the records are withheld whatever the writer.
callas "$RW" j1 "$(tc wp_update_post_meta "{\"ID\":$SUB,\"key\":\"_field_message\",\"value\":\"agent-wrote-this\"}")"
check "the write to the submission's meta is refused" "$(verdict j1)" "error"
check "STORED: the submission's meta is unchanged" "$(wpe 'echo get_post_meta('"$SUB"',"_field_message",true);')" "$M2-sub-meta"
callas "$RW" j2 "$(tc wp_update_post "{\"ID\":$SUB,\"fields\":{\"post_title\":\"$M2-retitled\"}}")"
check "an edit of its title is refused" "$(verdict j2)" "error"
check "STORED: the title is unchanged" "$(wpe 'echo get_post('"$SUB"')->post_title;')" "$M2-sub-title"
callas "$RW" j2b "$(tc gmcp_smoke_rawwrite "{\"op\":\"meta\",\"id\":$SUB,\"key\":\"_field_message\",\"value\":\"agent-wrote-this\",\"net\":\"off\"}")"
check "CONTROL: with the net out, a raw writer does change the submission's meta (so the records below describe a real write)" "$(wpe 'echo get_post_meta('"$SUB"',"_field_message",true);')" "agent-wrote-this"
callas "$RW" j2c "$(tc gmcp_smoke_rawwrite "{\"op\":\"title\",\"id\":$SUB,\"value\":\"$M2-retitled\",\"net\":\"off\"}")"
check "CONTROL: and its title" "$(wpe 'echo get_post('"$SUB"')->post_title;')" "$M2-retitled"
callas "$RW" j3 "$(tc wp_update_post_meta "{\"ID\":$CTL,\"key\":\"_field_message\",\"value\":\"agent-wrote-this\"}")"
check "CONTROL: the same write to an ordinary post succeeds" "$(verdict j3)" "ok"
callas "$RO" j4 "$(tc wp_list_changes '{"limit":40}')"
check "CONTROL: wp_list_changes answers" "$(verdict j4)" "ok"
check "CONTROL: it names the ordinary post's change (the probe sees an entry)" "$(has j4 "$M2-ctl-title")" "yes"
check "wp_list_changes does not name the submission by its title" "$(has j4 "$M2-sub")" "no"
check "nor by its new title" "$(has j4 "$M2-retitled")" "no"
check "STORED: the journal row holds nothing of the submission" \
  "$(wpe 'echo strpos(serialize(get_option(GMCP_Journal::OPTION,[])),"'"$M2"'-sub")===false&&strpos(serialize(get_option(GMCP_Journal::OPTION,[])),"'"$M2"'-retitled")===false?"clean":"quoted";')" "clean"
check "STORED: and no snapshot of a submission's meta was kept" \
  "$(wpe 'global $wpdb;echo (int) $wpdb->get_var("SELECT COUNT(*) FROM ".GMCP_Journal::snapshot_table()." WHERE post_id='"$SUB"'");')" "0"
check "CONTROL: a snapshot WAS kept for the ordinary post's meta (the probe sees a row)" \
  "$(wpe 'global $wpdb;echo (int) $wpdb->get_var("SELECT COUNT(*) FROM ".GMCP_Journal::snapshot_table()." WHERE post_id='"$CTL"'")>0?"kept":"none";')" "kept"
callas "$RW" j4w "$(tc wp_list_changes '{"limit":40}')"
JID=$(python3 -c 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"]
print("".join(e["id"] for e in json.loads(t) if "'"$M2"'-ctl-title" in e.get("what","") and e.get("reversible"))[:10])' < "$OUT/j4w")
callas "$RW" j5 "$(tc wp_undo_change "{\"id\":\"$JID\"}")"
check "CONTROL: the ordinary post's entry can be undone" "$(verdict j5)" "ok"
# An entry written before records about submissions were withheld names the post by its title.
wpe 'update_option(GMCP_Journal::OPTION,[["id"=>"legacy-sub","t"=>time(),"kind"=>"post","ID"=>'"$SUB"',"what"=>"Smoke submission \"'"$M2"'-sub-title\" changed: post_title","fields"=>["post_title"],"previous"=>["post_title"=>"x"],"tool"=>"wp_update_post","call"=>"c1"],["id"=>"legacy-ctl","t"=>time(),"kind"=>"post","ID"=>'"$CTL"',"what"=>"Post \"'"$M2"'-ctl-title\" changed: post_title","fields"=>["post_title"],"previous"=>["post_title"=>"x"],"tool"=>"wp_update_post","call"=>"c2"]],false);' >/dev/null
callas "$RO" j6 "$(tc wp_list_changes '{"limit":40}')"
check "CONTROL: an old entry about an ordinary post is listed with its title" "$(has j6 "$M2-ctl-title")" "yes"
check "an old entry about a submission is listed without its title" "$(has j6 "$M2-sub")" "no"
check "and says why" "$(has j6 "form submission")" "yes"
callas "$RW" j7 "$(tc wp_undo_change '{"id":"legacy-sub"}')"
check "an old entry about a submission is not put back" "$(verdict j7)" "error"
wpe 'GMCP_Journal::clear();' >/dev/null
if [ "$JOURNAL_WAS" = unset ]; then
  wpe '$o=get_option("gmcp_options",[]);unset($o["mcp_change_journal"]);update_option("gmcp_options",$o,false);' >/dev/null
else
  wpe '$o=get_option("gmcp_options",[]);$o["mcp_change_journal"]='"$JOURNAL_WAS"';update_option("gmcp_options",$o,false);' >/dev/null
fi
callas "$TOK" j8 "$(tc wp_get_audit_log '{"limit":200}')"
if [ "$(verdict j8)" = "ok" ]; then
  check "CONTROL: the audit log names the ordinary post's change by its title (the probe sees a label)" "$(has j8 "$M2-ctl-title")" "yes"
  check "the audit log does not quote the submission's previous title" "$(has j8 "$M2-sub-title")" "no"
else
  echo "  SKIP  wp_get_audit_log is not offered; the audit half of the change records was not exercised"
fi
wpe 'wp_update_post(["ID"=>'"$SUB"',"post_title"=>"'"$M2"'-sub-title"]);update_post_meta('"$SUB"',"_field_message","'"$M2"'-sub-meta");' >/dev/null

echo "-- a scan that returns the titles of posts --"
check "CONTROL: a post that quotes a template shortcode is reported by the scan" \
  "$(wpe 'add_filter("gmcp_caller_access_level",function(){return "readonly";});$r=GMCP_Tools_Elementor::shortcode_references(7771,50);echo strpos(json_encode($r),"'"$M2"'-ctl")!==false?"found":"missing";')" "found"
check "the scan reports neither a submission nor an order that quotes it, below admin" \
  "$(wpe 'add_filter("gmcp_caller_access_level",function(){return "readonly";});$r=GMCP_Tools_Elementor::shortcode_references(7771,50);echo strpos(json_encode($r),"'"$M2"'-sub")===false&&strpos(json_encode($r),"'"$M2"'-ord")===false?"clean":"quoted";')" "clean"
check "an admin key's scan still reports an order but not a submission" \
  "$(wpe '$r=GMCP_Tools_Elementor::shortcode_references(7771,50);$j=json_encode($r);echo strpos($j,"'"$M2"'-ord")!==false?"order":"noorder","|",strpos($j,"'"$M2"'-sub")===false?"clean":"quoted";')" "order|clean"

echo "-- resources --"
callas "$RO" r1 '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"gmcp://post/'"$SUBATT"'"}}'
check "a gmcp://post resource for a file attached to a submission returns nothing" "$(rawhas r1 "$M2-sub")" "no"
callas "$RO" r1 '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"gmcp://post/'"$CTLATT"'"}}'
check "CONTROL: the same for a file attached to an ordinary post returns it" "$(rawhas r1 "$M2-ctl")" "yes"
callas "$RO" r2 '{"jsonrpc":"2.0","id":1,"method":"resources/list"}'
check "resources/list names no submission" "$(rawhas r2 "$M2-sub")" "no"
check "CONTROL: and does list the ordinary post (the probe sees a row)" "$(rawhas r2 "$M2-ctl")" "yes"

echo "-- the REST-generated tools --"
REST_WAS=$(wpe 'echo get_option("gmcp_options",[])["mcp_tools_rest"]??"unset";')
setrest() { wpe '$o=get_option("gmcp_options",[]);$o["mcp_tools_rest"]='"$1"';update_option("gmcp_options",$o,false);delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null; }
setrest true
callas "$RO" t0 '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "CONTROL: the REST tools are offered with the group on" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print(sorted({"list_media","get_media","list_posts","list_pages"}-n) or True)' t0)" "True"
callas "$RO" t1 "$(tc list_media '{"search":"'"$M2"'","per_page":100}')"
check "list_media: answers" "$(verdict t1)" "ok"
check "CONTROL: list_media finds the ordinary post's file" "$(has t1 "$M2-ctl-att")" "yes"
check "list_media does not list a file attached to a submission" "$(has t1 "$M2-sub-att")" "no"
check "nor one attached to an order, below admin" "$(has t1 "$M2-ord-att")" "no"
callas "$RO" t2 "$(tc list_media '{"_fields":"id","per_page":100}')"
check "with _fields naming only the id, the id of a submission's file is not listed" "$(has t2 "\"id\": $SUBATT")" "no"
check "CONTROL: and the ordinary post's file id is" "$(has t2 "\"id\": $CTLATT")" "yes"
callas "$RO" t3 "$(tc get_media "{\"id\":$SUBATT}")"
check "get_media on a file attached to a submission is refused" "$(verdict t3)" "error"
check "and it carries nothing of it" "$(has t3 "$M2-sub")" "no"
callas "$RO" t3 "$(tc get_media "{\"id\":$CTLATT}")"
check "CONTROL: get_media on a file attached to an ordinary post succeeds" "$(verdict t3)" "ok"
callas "$RW" t4 "$(tc update_media "{\"id\":$SUBATT,\"title\":\"agent-retitled\"}")"
check "update_media on a file attached to a submission is refused" "$(verdict t4)" "error"
check "STORED: its title is unchanged" "$(wpe 'echo get_post('"$SUBATT"')->post_title;')" "$M2-sub-att-title"
callas "$RW" t5 "$(tc update_media "{\"id\":$CTLATT,\"title\":\"agent-retitled\"}")"
check "CONTROL: update_media on a file attached to an ordinary post succeeds" "$(verdict t5)" "ok"
check "CONTROL: and STORED: its title changed" "$(wpe 'echo get_post('"$CTLATT"')->post_title;')" "agent-retitled"
callas "$RO" t6 "$(tc get_posts "{\"id\":$SUB}")"
check "get_posts on a submission's id is refused" "$(verdict t6)" "error"
check "and returns nothing of it" "$(has t6 "$M2-sub")" "no"
# A route that a guarded type has taken over is not turned into tools at all.
wpe 'update_option("gmcp_smoke_guard_pages",1);delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null
callas "$RO" t7 '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "when the page type is a guarded type, no tool is generated for the pages route" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print(sorted({"list_pages","get_pages"}&n) or "none")' t7)" "none"
check "CONTROL: and the posts route still is" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print(sorted({"list_posts","get_posts"}-n) or "present")' t7)" "present"
wpe 'delete_option("gmcp_smoke_guard_pages");delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null
callas "$RO" t8 '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "CONTROL: with the option removed the pages tools are back" \
  "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print(sorted({"list_pages","get_pages"}-n) or "present")' t8)" "present"
if [ "$REST_WAS" = unset ]; then
  wpe '$o=get_option("gmcp_options",[]);unset($o["mcp_tools_rest"]);update_option("gmcp_options",$o,false);delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null
else
  setrest "$REST_WAS"
fi
# ---------------------------------------------------------------------------------------------
echo "-- the tree cannot be rearranged: nothing is moved out of a submission, or into one --"
# The read guards judge a post by what it hangs from, so every generic writer that could change
# post_parent or comment_post_ID was a way round all of them. Each attack is made by a READWRITE
# key and the read by a READ-ONLY one; every outcome is asserted on the STORED row, and each attack
# has a control that the same kind of write to an ordinary post succeeds.
CTLCOM=$(sid ctl comment)
# A real image: set_post_thumbnail() refuses an attachment that is not one, and the control for the
# featured-image guard has to be a thumbnail that WordPress itself would accept.
OU=$(wpe '$b=wp_upload_bits("'"$M2"'-ou.png",null,base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="));echo wp_insert_attachment(["post_mime_type"=>"image/png","post_title"=>"'"$M2"'-ou-title","post_status"=>"inherit"],$b["file"]);')
# An earlier block retitled the ordinary post's upload; the controls below look for its marker.
wpe 'wp_update_post(["ID"=>'"$CTLATT"',"post_title"=>"'"$M2"'-ctl-att-title"]);' >/dev/null
PG=$(wpe 'echo wp_insert_post(["post_type"=>"page","post_status"=>"publish","post_title"=>"'"$M2"'-pg-title","post_content"=>"'"$M2"'-pg-body"]);')
PG2=$(wpe 'echo wp_insert_post(["post_type"=>"page","post_status"=>"publish","post_title"=>"'"$M2"'-pg2-title"]);')
CLEAN="$CLEAN $OU $PG $PG2"
parent_of() { wpe 'echo (int) get_post('"$1"')->post_parent;'; }
check "CONTROL: the seed is what the attacks assume: the revision, the upload and the comment hang from the submission" \
  "$(parent_of $SUBREV)|$(parent_of $SUBATT)|$(wpe 'echo (int) get_comment('"$SUBCOM"')->comment_post_ID;')" "$SUB|$SUB|$SUB"

# 1a. a revision of a submission is given another parent
callas "$RW" m1 "$(tc wp_update_post "{\"ID\":$SUBREV,\"fields\":{\"post_parent\":0}}")"
check "re-parenting a revision of a submission to 0 is refused" "$(verdict m1)" "error"
check "STORED: the revision still hangs from the submission" "$(parent_of $SUBREV)" "$SUB"
deny "wp_get_post on that revision, by a read-only key" "$RO" wp_get_post "{\"ID\":$SUBREV}" "$M2-sub"
callas "$RW" m1b "$(tc wp_update_post "{\"ID\":$SUBREV,\"fields\":{\"post_title\":\"x\"}}")"
check "any other write to that revision is refused too" "$(verdict m1b)" "error"
check "STORED: its title is unchanged" "$(wpe 'echo get_post('"$SUBREV"')->post_title;')" "$M2-sub-title"
callas "$RW" m1c "$(tc wp_update_post "{\"ID\":$CTLREV,\"fields\":{\"post_parent\":0}}")"
check "CONTROL: the same re-parenting of a revision of an ORDINARY post succeeds" "$(verdict m1c)" "ok"
check "CONTROL: and STORED: its parent is now 0 (the probe sees a move)" "$(parent_of $CTLREV)" "0"
wpe 'global $wpdb;$wpdb->update($wpdb->posts,["post_parent"=>'"$CTL"'],["ID"=>'"$CTLREV"']);clean_post_cache('"$CTLREV"');' >/dev/null

# 1b. a visitor's upload is given an ordinary parent
callas "$RW" m2 "$(tc wp_update_post "{\"ID\":$SUBATT,\"post_parent\":$CTL}")"
check "re-parenting a submission's upload under an ordinary post is refused" "$(verdict m2)" "error"
check "STORED: the upload still hangs from the submission" "$(parent_of $SUBATT)" "$SUB"
callas "$RW" m2b "$(tc wp_update_post "{\"ID\":$SUBATT,\"fields\":{\"post_parent\":$CTL}}")"
check "the same through fields" "$(verdict m2b)" "error"
check "STORED: still unchanged" "$(parent_of $SUBATT)" "$SUB"
quiet "wp_get_media after the attempts" "$RO" wp_get_media '{"search":"'"$M2"'","limit":200}' "$M2-sub"
deny "wp_get_post_meta on the upload after the attempts" "$RO" wp_get_post_meta "{\"ID\":$SUBATT}" "$M2-sub"
callas "$RW" m2c "$(tc wp_update_post "{\"ID\":$OU,\"post_parent\":$CTL}")"
check "CONTROL: re-parenting an ordinary upload under an ordinary post succeeds" "$(verdict m2c)" "ok"
check "CONTROL: and STORED: it moved" "$(parent_of $OU)" "$CTL"
callas "$RW" m2d "$(tc wp_update_post "{\"ID\":$OU,\"post_parent\":0}")"

# 1c. a comment on a submission is moved to an ordinary post
callas "$RW" m3 "$(tc wp_update_comment "{\"comment_ID\":$SUBCOM,\"fields\":{\"comment_post_ID\":$CTL}}")"
check "moving a comment on a submission to an ordinary post is refused" "$(verdict m3)" "error"
check "STORED: it is still on the submission" "$(wpe 'echo (int) get_comment('"$SUBCOM"')->comment_post_ID;')" "$SUB"
quiet "wp_get_comments after the attempt" "$RO" wp_get_comments '{"search":"'"$M2"'","limit":500}' "$M2-sub"
callas "$RW" m3b "$(tc wp_update_comment "{\"comment_ID\":$SUBCOM,\"fields\":{\"comment_content\":\"edited\"}}")"
check "editing a comment on a submission is refused" "$(verdict m3b)" "error"
# wp_delete_comment, wp_delete_post, wp_delete_media and wp_delete_post_meta are admin-level tools:
# a call by the read-write key would be refused for its level, which proves nothing about the guard.
callas "$TOK" m3c "$(tc wp_delete_comment "{\"comment_ID\":$SUBCOM,\"force\":true}")"
check "an admin key's deleting a comment on a submission is refused" "$(verdict m3c)" "error"
check "and the refusal is the guard's, not the tool's level" "$(has m3c "form submission")" "yes"
DELCOM=$(wpe 'echo wp_insert_comment(["comment_post_ID"=>'"$CTL"',"comment_content"=>"'"$M2"'-delcom","comment_author"=>"a","comment_approved"=>1]);')
callas "$TOK" m3c2 "$(tc wp_delete_comment "{\"comment_ID\":$DELCOM,\"force\":true}")"
check "CONTROL: an admin key's deleting a comment on an ordinary post succeeds" "$(verdict m3c2)" "ok"
check "CONTROL: and STORED: it is gone" "$(wpe 'echo get_comment('"$DELCOM"')?"there":"gone";')" "gone"
check "STORED: the comment is still there, unedited" "$(wpe 'echo get_comment('"$SUBCOM"')->comment_content;')" "$M2-sub-comment"
callas "$RW" m3d "$(tc wp_create_comment "{\"post_id\":$SUB,\"comment_content\":\"agent-comment\"}")"
check "creating a comment on a submission is refused" "$(verdict m3d)" "error"
check "STORED: no such comment exists" "$(wpe 'echo count(get_comments(["search"=>"agent-comment"]));')" "0"
callas "$RW" m3d2 "$(tc wp_create_comment "{\"post_id\":$CTL,\"comment_content\":\"agent-comment\"}")"
check "CONTROL: creating a comment on an ordinary post succeeds" "$(verdict m3d2)" "ok"
check "CONTROL: and STORED: it exists (the probe sees a created comment)" "$(wpe 'echo count(get_comments(["search"=>"agent-comment"]));')" "1"
wpe 'foreach(get_comments(["search"=>"agent-comment"]) as $c){wp_delete_comment($c->comment_ID,true);}' >/dev/null
callas "$RW" m3e "$(tc wp_update_comment "{\"comment_ID\":$CTLCOM,\"fields\":{\"comment_content\":\"$M2-ctl-edited\"}}")"
check "CONTROL: editing a comment on an ordinary post succeeds" "$(verdict m3e)" "ok"
check "CONTROL: and STORED: it changed" "$(wpe 'echo get_comment('"$CTLCOM"')->comment_content;')" "$M2-ctl-edited"
callas "$RW" m3f "$(tc wp_update_comment "{\"comment_ID\":$CTLCOM,\"fields\":{\"comment_post_ID\":$PG}}")"
check "CONTROL: moving a comment between ordinary posts succeeds" "$(verdict m3f)" "ok"
check "CONTROL: and STORED: it moved" "$(wpe 'echo (int) get_comment('"$CTLCOM"')->comment_post_ID;')" "$PG"
wpe 'wp_update_comment(["comment_ID"=>'"$CTLCOM"',"comment_post_ID"=>'"$CTL"',"comment_content"=>"'"$M2"'-ctl-comment"]);' >/dev/null

# 1d. hiding: an ordinary page is parented under a submission
callas "$RW" m4 "$(tc wp_update_post "{\"ID\":$PG,\"fields\":{\"post_parent\":$SUB}}")"
check "parenting an ordinary page under a submission is refused" "$(verdict m4)" "error"
check "STORED: the page has no parent" "$(parent_of $PG)" "0"
allow "wp_get_post on the page, which a key could otherwise hide from every reader" "$RO" wp_get_post "{\"ID\":$PG}" "$M2-pg"
callas "$RW" m4b "$(tc wp_update_post "{\"ID\":$PG,\"fields\":{\"post_parent\":$SUBATT}}")"
check "and under a submission's upload" "$(verdict m4b)" "error"
check "STORED: still no parent" "$(parent_of $PG)" "0"
callas "$RW" m4c "$(tc wp_update_post "{\"ID\":$PG,\"fields\":{\"post_parent\":$PG2}}")"
check "CONTROL: parenting it under another ordinary page succeeds" "$(verdict m4c)" "ok"
check "CONTROL: and STORED: it has that parent" "$(parent_of $PG)" "$PG2"
allow "wp_get_post on the child page, still readable" "$RO" wp_get_post "{\"ID\":$PG}" "$M2-pg"
callas "$RW" m4d "$(tc wp_update_post "{\"ID\":$PG,\"fields\":{\"post_parent\":0}}")"
check "CONTROL: and back to none" "$(parent_of $PG)" "0"
# an order's tree is as closed to a move
callas "$RW" m4e "$(tc wp_update_post "{\"ID\":$PG,\"fields\":{\"post_parent\":$ORD}}")"
check "parenting an ordinary page under an order is refused as well" "$(verdict m4e)" "error"
check "STORED: still no parent" "$(parent_of $PG)" "0"

# 1e. the net under the tools, for a writer that asks no guard (the test tool writes through WordPress)
callas "$RW" n1 "$(tc gmcp_smoke_rawwrite "{\"op\":\"parent\",\"id\":$SUBREV,\"parent\":0}")"
check "the net refuses a raw re-parenting of a revision of a submission" "$(verdict n1)" "error"
check "STORED: it still hangs from the submission" "$(parent_of $SUBREV)" "$SUB"
callas "$RW" n2 "$(tc gmcp_smoke_rawwrite "{\"op\":\"parent\",\"id\":$PG,\"parent\":$SUB}")"
check "the net refuses a raw parenting of an ordinary page under a submission" "$(verdict n2)" "error"
check "STORED: the page has no parent" "$(parent_of $PG)" "0"
callas "$RW" n3 "$(tc gmcp_smoke_rawwrite "{\"op\":\"comment_post\",\"id\":$SUBCOM,\"parent\":$CTL}")"
check "the net refuses a raw move of a comment out of a submission" "$(verdict n3)" "error"
check "STORED: the comment is still on the submission" "$(wpe 'echo (int) get_comment('"$SUBCOM"')->comment_post_ID;')" "$SUB"
callas "$RW" n3b "$(tc gmcp_smoke_rawwrite "{\"op\":\"create\",\"parent\":$SUB,\"value\":\"$M2-raw-created\"}")"
check "the net refuses a raw creation of a post under a submission" "$(verdict n3b)" "error"
check "STORED: nothing was created" "$(wpe 'echo count(get_posts(["post_type"=>"page","post_status"=>"any","numberposts"=>-1,"s"=>"'"$M2"'-raw-created"]));')" "0"
callas "$RW" n3c "$(tc gmcp_smoke_rawwrite "{\"op\":\"create\",\"parent\":$CTL,\"value\":\"$M2-raw-created\"}")"
check "CONTROL: the same creation under an ordinary post is not refused" "$(verdict n3c)" "ok"
check "CONTROL: and STORED: the page exists (the probe sees a creation)" "$(wpe 'echo count(get_posts(["post_type"=>"page","post_status"=>"any","numberposts"=>-1,"s"=>"'"$M2"'-raw-created"]));')" "1"
wpe 'foreach(get_posts(["post_type"=>"page","post_status"=>"any","numberposts"=>-1,"s"=>"'"$M2"'-raw-created"]) as $p){wp_delete_post($p->ID,true);}' >/dev/null
callas "$RW" n4 "$(tc gmcp_smoke_rawwrite "{\"op\":\"meta\",\"id\":$SUBATT,\"key\":\"_wp_attachment_image_alt\",\"value\":\"raw\"}")"
check "the net refuses a raw meta write on a submission's upload" "$(verdict n4)" "error"
check "STORED: the upload's meta is unchanged" "$(wpe 'echo get_post_meta('"$SUBATT"',"_wp_attachment_image_alt",true);')" ""
# (update_post_meta() on a REVISION id writes to the revision's parent, so a meta write "on" a
# revision is a write to the submission's own row, which the net leaves to the generic tools.)
callas "$RW" n5 "$(tc gmcp_smoke_rawwrite "{\"op\":\"parent\",\"id\":$PG2,\"parent\":$PG}")"
check "CONTROL: the same raw re-parenting between ordinary pages is not refused" "$(verdict n5)" "ok"
check "CONTROL: and STORED: it moved" "$(parent_of $PG2)" "$PG"
wpe 'global $wpdb;$wpdb->update($wpdb->posts,["post_parent"=>0],["ID"=>'"$PG2"']);clean_post_cache('"$PG2"');' >/dev/null

echo "-- a form plugin's own bookkeeping, from a hook a tool call fires, is not refused --"
# Flamingo saves a contact (a post of a guarded type, with meta) every time a user profile is saved,
# so wp_update_user and wp_create_user fire a write to such a row. The net under the tools refuses
# what hangs FROM a submission and leaves the submission's own row to the generic tools, which refuse
# it themselves; a net that refused it too broke those two tools on a site with Flamingo, which was
# measured on the first full run. The mu-plugin stands in for the plugin, so this does not depend on it.
SEU=$(wpe 'echo wp_insert_user(["user_login"=>"'"$M2"'-seu","user_email"=>"seu-'"$RANDOM"'@example.test","user_pass"=>wp_generate_password(),"role"=>"subscriber"]);')
wpe 'update_option("gmcp_smoke_sideeffect",1);delete_option("gmcp_smoke_contact");' >/dev/null
callas "$TOK" se1 "$(tc wp_update_user "{\"ID\":$SEU,\"fields\":{\"display_name\":\"$M2-seu-name\"}}")"
check "wp_update_user succeeds although a plugin hook creates and writes a submission-type post during the call" "$(verdict se1)" "ok"
check "STORED: the user's display name changed" "$(wpe 'echo get_userdata('"$SEU"')->display_name;')" "$M2-seu-name"
check "STORED: and the hook's own writes to its contact row went through (title and meta)" \
  "$(wpe '$c=(int) get_option("gmcp_smoke_contact");echo get_post_type($c),"|",get_post($c)->post_title,"|",get_post_meta($c,"_gmcp_smoke_side",true);')" "flamingo_contact|contact-updated-$SEU|meta-$SEU"
callas "$TOK" se2 "$(tc wp_update_user "{\"ID\":$SEU,\"fields\":{\"display_name\":\"$M2-seu-name-2\"}}")"
check "and a second call, which UPDATES the row the first created, succeeds as well" "$(verdict se2)" "ok"
wpe 'wp_delete_post((int) get_option("gmcp_smoke_contact"),true);delete_option("gmcp_smoke_contact");delete_option("gmcp_smoke_sideeffect");wp_delete_user('"$SEU"');' >/dev/null

echo "-- the change records quote neither state of a post that moved out of a submission --"
# The writer here asks no guard and the net is taken out, so the move happens. The records are
# built after the write, and read afterwards the upload is an ordinary row: the record has to be
# judged on the state BEFORE as well, or it quotes the title and names the submission as the
# parent it came from.
JOURNAL_WAS2=$(wpe 'echo get_option("gmcp_options",[])["mcp_change_journal"]??"unset";')
wpe '$o=get_option("gmcp_options",[]);$o["mcp_change_journal"]=true;update_option("gmcp_options",$o,false);GMCP_Journal::clear();' >/dev/null
callas "$RW" r1 "$(tc gmcp_smoke_rawwrite "{\"op\":\"parent\",\"id\":$SUBATT,\"parent\":$CTL,\"net\":\"off\"}")"
check "CONTROL: with the net out, the raw writer does move the upload (so the records below are about a real move)" "$(parent_of $SUBATT)" "$CTL"
wpe 'global $wpdb;$wpdb->update($wpdb->posts,["post_parent"=>'"$SUB"'],["ID"=>'"$SUBATT"']);clean_post_cache('"$SUBATT"');' >/dev/null
callas "$RW" r2 "$(tc gmcp_smoke_rawwrite "{\"op\":\"parent\",\"id\":$CTLATT,\"parent\":0,\"net\":\"off\"}")"
callas "$RO" r3 "$(tc wp_list_changes '{"limit":40}')"
check "CONTROL: wp_list_changes names the ordinary upload's change (the probe sees an entry)" "$(has r3 "$M2-ctl-att-title")" "yes"
check "wp_list_changes does not name the submission's upload" "$(has r3 "$M2-sub-att")" "no"
check "STORED: the journal option holds nothing of the upload's title" \
  "$(wpe 'echo strpos(serialize(get_option(GMCP_Journal::OPTION,[])),"'"$M2"'-sub-att")===false?"clean":"quoted";')" "clean"
check "CONTROL: it does hold the ordinary upload's title (the probe sees a row)" \
  "$(wpe 'echo strpos(serialize(get_option(GMCP_Journal::OPTION,[])),"'"$M2"'-ctl-att")!==false?"kept":"missing";')" "kept"
check "STORED: no audit row quotes it, in the changes or the detail column" \
  "$(wpe 'global $wpdb;$t=GMCP_Audit::table();echo (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE (changes LIKE \"%'"$M2"'-sub-att%\" OR detail LIKE \"%'"$M2"'-sub-att%\")");')" "0"
check "CONTROL: an audit row does quote the ordinary upload (the probe sees a row)" \
  "$(wpe 'global $wpdb;$t=GMCP_Audit::table();echo (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE changes LIKE \"%'"$M2"'-ctl-att%\"")>0?"quoted":"none";')" "quoted"
check "STORED: and no journal snapshot was kept for it" \
  "$(wpe 'global $wpdb;echo (int) $wpdb->get_var("SELECT COUNT(*) FROM ".GMCP_Journal::snapshot_table()." WHERE post_id='"$SUBATT"'");')" "0"
wpe 'wp_update_post(["ID"=>'"$CTLATT"',"post_parent"=>'"$CTL"']);GMCP_Journal::clear();' >/dev/null
if [ "$JOURNAL_WAS2" = unset ]; then
  wpe '$o=get_option("gmcp_options",[]);unset($o["mcp_change_journal"]);update_option("gmcp_options",$o,false);' >/dev/null
else
  wpe '$o=get_option("gmcp_options",[]);$o["mcp_change_journal"]='"$JOURNAL_WAS2"';update_option("gmcp_options",$o,false);' >/dev/null
fi

echo "-- a featured image is a read: nothing makes a visitor's upload a public page's thumbnail --"
callas "$RW" f1 "$(tc wp_set_featured_image "{\"post_id\":$PG,\"media_id\":$SUBATT}")"
check "setting a submission's upload as a page's featured image is refused" "$(verdict f1)" "error"
check "STORED: the page has no thumbnail" "$(wpe 'echo (int) get_post_meta('"$PG"',"_thumbnail_id",true);')" "0"
callas "$RW" f1b "$(tc wp_update_post_meta "{\"ID\":$PG,\"meta\":{\"_thumbnail_id\":\"$SUBATT\"}}")"
check "writing _thumbnail_id through the meta tool is refused too" "$(verdict f1b)" "error"
check "STORED: still no thumbnail" "$(wpe 'echo (int) get_post_meta('"$PG"',"_thumbnail_id",true);')" "0"
callas "$RW" f1c "$(tc gmcp_smoke_rawwrite "{\"op\":\"meta\",\"id\":$PG,\"key\":\"_thumbnail_id\",\"value\":\"$SUBATT\"}")"
check "and through a raw writer (the net)" "$(verdict f1c)" "error"
check "STORED: still no thumbnail" "$(wpe 'echo (int) get_post_meta('"$PG"',"_thumbnail_id",true);')" "0"
callas "$RW" f1d "$(tc wp_update_post "{\"ID\":$PG,\"meta_input\":{\"_thumbnail_id\":\"$SUBATT\"}}")"
check "writing _thumbnail_id through meta_input is refused too" "$(verdict f1d)" "error"
check "STORED: still no thumbnail" "$(wpe 'echo (int) get_post_meta('"$PG"',"_thumbnail_id",true);')" "0"
BEFORE_PC=$(wpe 'echo count(get_posts(["post_type"=>"post","post_status"=>"any","numberposts"=>-1,"s"=>"'"$M2"'-pointer"]));')
callas "$RW" f1e "$(tc wp_create_post "{\"post_title\":\"$M2-pointer\",\"meta_input\":{\"_thumbnail_id\":\"$SUBATT\"}}")"
check "creating a post with the submission's upload as its thumbnail is refused" "$(verdict f1e)" "error"
check "STORED: and no post was created by the attempt" "$(wpe 'echo count(get_posts(["post_type"=>"post","post_status"=>"any","numberposts"=>-1,"s"=>"'"$M2"'-pointer"]));')" "$BEFORE_PC"
callas "$RW" f2 "$(tc wp_set_featured_image "{\"post_id\":$PG,\"media_id\":$OU}")"
check "CONTROL: setting an ordinary upload as the featured image succeeds" "$(verdict f2)" "ok"
check "CONTROL: and STORED: it is the thumbnail" "$(wpe 'echo (int) get_post_meta('"$PG"',"_thumbnail_id",true);')" "$OU"
allow "wp_get_post_snapshot of the page shows its ordinary thumbnail" "$RO" wp_get_post_snapshot "{\"ID\":$PG}" "$M2-ou"
# A thumbnail already in place before the guard existed, set outside any tool call.
wpe 'update_post_meta('"$PG"',"_thumbnail_id",'"$SUBATT"');' >/dev/null
check "CONTROL: the page now carries the submission's upload as its thumbnail (set outside a tool)" "$(wpe 'echo (int) get_post_meta('"$PG"',"_thumbnail_id",true);')" "$SUBATT"
callas "$RO" f3 "$(tc wp_get_post_snapshot "{\"ID\":$PG}")"
check "wp_get_post_snapshot of the page answers" "$(verdict f3)" "ok"
check "and does not return the submission's upload as its thumbnail" "$(has f3 "$M2-sub")" "no"
check "CONTROL: it still returns the page itself" "$(has f3 "$M2-pg")" "yes"
wpe 'delete_post_meta('"$PG"',"_thumbnail_id");' >/dev/null

echo "-- a menu item is a read of what it points at --"
MENU=$(wpe 'echo wp_create_nav_menu("'"$M2"'-menu");')
MI_OK=$(wpe 'echo wp_update_nav_menu_item('"$MENU"',0,["menu-item-type"=>"post_type","menu-item-object"=>"post","menu-item-object-id"=>'"$CTL"',"menu-item-status"=>"publish"]);')
MI_AIM=$(wpe 'echo wp_update_nav_menu_item('"$MENU"',0,["menu-item-type"=>"post_type","menu-item-object"=>"post","menu-item-object-id"=>'"$CTL"',"menu-item-status"=>"publish"]);')
MI_OLD=$(wpe 'echo wp_update_nav_menu_item('"$MENU"',0,["menu-item-type"=>"post_type","menu-item-object"=>"flamingo_inbound","menu-item-object-id"=>'"$SUB"',"menu-item-status"=>"publish"]);')
CLEAN="$CLEAN $MI_OK $MI_AIM $MI_OLD"
check "CONTROL: the menu holds an item that points at the submission (made outside any tool)" "$(wpe 'echo (int) get_post_meta('"$MI_OLD"',"_menu_item_object_id",true);')" "$SUB"
callas "$RO" mi0 "$(tc wp_get_menu_items "{\"menu\":\"$MENU\"}")"
check "wp_get_menu_items answers" "$(verdict mi0)" "ok"
check "CONTROL: it lists the item that points at an ordinary post, with that post's title" "$(has mi0 "$M2-ctl-title")" "yes"
check "it does not list the item that points at a submission" "$(has mi0 "$M2-sub-title")" "no"
check "nor give its id" "$(has mi0 "\"item_id\": $MI_OLD")" "no"
callas "$RW" mi1 "$(tc wp_update_post_meta "{\"ID\":$MI_AIM,\"meta\":{\"_menu_item_object_id\":\"$SUB\",\"_menu_item_object\":\"flamingo_inbound\"}}")"
check "re-pointing a menu item at a submission through its meta is refused" "$(verdict mi1)" "error"
check "STORED: the item still points at the ordinary post" "$(wpe 'echo (int) get_post_meta('"$MI_AIM"',"_menu_item_object_id",true),"|",get_post_meta('"$MI_AIM"',"_menu_item_object",true);')" "$CTL|post"
callas "$RW" mi1b "$(tc wp_update_post_meta "{\"ID\":$MI_AIM,\"meta\":{\"_menu_item_object_id\":\"$SUBATT\"}}")"
check "and at a submission's upload" "$(verdict mi1b)" "error"
callas "$RW" mi1c "$(tc gmcp_smoke_rawwrite "{\"op\":\"meta\",\"id\":$MI_AIM,\"key\":\"_menu_item_object_id\",\"value\":\"$SUB\"}")"
check "and through a raw writer (the net)" "$(verdict mi1c)" "error"
check "STORED: still the ordinary post" "$(wpe 'echo (int) get_post_meta('"$MI_AIM"',"_menu_item_object_id",true);')" "$CTL"
# The same attack in two steps: make it a taxonomy item, point it at the number, switch it back.
callas "$RW" mi2a "$(tc wp_update_post_meta "{\"ID\":$MI_AIM,\"meta\":{\"_menu_item_type\":\"taxonomy\"}}")"
callas "$RW" mi2b "$(tc wp_update_post_meta "{\"ID\":$MI_AIM,\"meta\":{\"_menu_item_object_id\":\"$SUB\"}}")"
callas "$RW" mi2c "$(tc wp_update_post_meta "{\"ID\":$MI_AIM,\"meta\":{\"_menu_item_type\":\"post_type\"}}")"
check "switching the item back to a post item while it holds the submission's id is refused" "$(verdict mi2c)" "error"
check "STORED: the item is not a post item pointing at the submission" \
  "$(wpe 'echo get_post_meta('"$MI_AIM"',"_menu_item_type",true)==="post_type"&&(int) get_post_meta('"$MI_AIM"',"_menu_item_object_id",true)==='"$SUB"'?"pointing":"safe";')" "safe"
wpe 'update_post_meta('"$MI_AIM"',"_menu_item_type","post_type");update_post_meta('"$MI_AIM"',"_menu_item_object_id",'"$CTL"');' >/dev/null
callas "$RW" mi3 "$(tc wp_update_post_meta "{\"ID\":$MI_AIM,\"meta\":{\"_menu_item_object_id\":\"$PG\"}}")"
check "CONTROL: re-pointing the item at another ordinary page succeeds" "$(verdict mi3)" "ok"
check "CONTROL: and STORED: it points there" "$(wpe 'echo (int) get_post_meta('"$MI_AIM"',"_menu_item_object_id",true);')" "$PG"
callas "$TOK" mi4 "$(tc wp_add_menu_item "{\"menu\":\"$MENU\",\"type\":\"post_type\",\"object_id\":$SUB}")"
callas "$TOK" mi4b "$(tc wp_add_menu_item "{\"menu\":\"$MENU\",\"type\":\"post_type\",\"object_id\":987654321}")"
check "wp_add_menu_item for a submission is answered as for a post that does not exist" "$(py 'import json,sys;d=json.load(sys.stdin);print(d["result"]["content"][0]["text"])' mi4)" "$(py 'import json,sys;d=json.load(sys.stdin);print(d["result"]["content"][0]["text"])' mi4b)"
check "and is refused" "$(verdict mi4)" "error"
callas "$TOK" mi4c "$(tc wp_update_menu_item "{\"item_id\":$MI_OLD,\"title\":\"x\"}")"
check "wp_update_menu_item on the item that points at a submission is refused (its reply would print the submission's title)" "$(verdict mi4c)" "error"
callas "$TOK" mi4d "$(tc wp_menu_health '{}')"
check "wp_menu_health does not print the submission's title" "$(has mi4d "$M2-sub-title")" "no"
wpe 'wp_delete_nav_menu('"$MENU"');' >/dev/null

echo "-- a guessed value is not confirmed, and nothing is deleted out from under a submission --"
callas "$TOK" x1 "$(tc wp_delete_post_meta "{\"ID\":$SUB,\"key\":\"_field_message\",\"value\":\"a-wrong-guess\"}")"
callas "$TOK" x2 "$(tc wp_delete_post_meta "{\"ID\":$SUB,\"key\":\"_field_message\",\"value\":\"$M2-sub-meta\"}")"
check "wp_delete_post_meta with a wrong value on a submission is refused" "$(verdict x1)" "error"
check "and the refusal is the guard's, not the tool's level" "$(has x1 "form submission")" "yes"
check "and with the right value too, so the two replies cannot tell a guess from a hit" "$(verdict x2)" "error"
check "and the replies are the same" \
  "$(py 'import json,sys;print(json.load(sys.stdin)["result"]["content"][0]["text"])' x1)" "$(py 'import json,sys;print(json.load(sys.stdin)["result"]["content"][0]["text"])' x2)"
check "STORED: the submission's meta is still there" "$(wpe 'echo get_post_meta('"$SUB"',"_field_message",true);')" "$M2-sub-meta"
wpe 'update_post_meta('"$CTL"',"_field_message","'"$M2"'-ctl-meta");' >/dev/null
callas "$TOK" x3 "$(tc wp_delete_post_meta "{\"ID\":$CTL,\"key\":\"_field_message\",\"value\":\"$M2-ctl-meta\"}")"
check "CONTROL: the same call with the right value on an ordinary post deletes it" "$(verdict x3)" "ok"
check "CONTROL: and STORED: the meta is gone" "$(wpe 'echo metadata_exists("post",'"$CTL"',"_field_message")?"there":"gone";')" "gone"
wpe 'update_post_meta('"$CTL"',"_field_message","'"$M2"'-ctl-meta");' >/dev/null
callas "$TOK" x4 "$(tc wp_delete_post "{\"ID\":$SUB,\"force\":true}")"
check "an admin key's wp_delete_post of a submission is refused" "$(verdict x4)" "error"
check "STORED: the submission, its revision and its upload are all still there, and the upload still hangs from it" \
  "$(wpe 'echo get_post('"$SUB"')?"sub":"nosub","|",get_post('"$SUBREV"')?"rev":"norev","|",(int) get_post('"$SUBATT"')->post_parent==='"$SUB"'?"att":"orphan";')" "sub|rev|att"
callas "$TOK" x5 "$(tc wp_delete_media "{\"ID\":$SUBATT,\"force\":true}")"
check "deleting a submission's upload through wp_delete_media is refused" "$(verdict x5)" "error"
check "STORED: the upload is still there" "$(wpe 'echo get_post('"$SUBATT"')?"there":"gone";')" "there"
callas "$TOK" x6 "$(tc gmcp_smoke_rawwrite "{\"op\":\"title\",\"id\":$SUBATT,\"value\":\"x\"}")"
check "the net refuses a raw edit of the upload" "$(verdict x6)" "error"
DEL=$(wpe 'echo wp_insert_post(["post_type"=>"post","post_status"=>"publish","post_title"=>"'"$M2"'-del"]);')
callas "$TOK" x7 "$(tc wp_delete_post "{\"ID\":$DEL,\"force\":true}")"
check "CONTROL: an admin key's wp_delete_post of an ordinary post succeeds" "$(verdict x7)" "ok"
check "CONTROL: and STORED: it is gone" "$(wpe 'echo get_post('"$DEL"')?"there":"gone";')" "gone"

echo "-- below admin a guarded post is answered exactly as one that does not exist --"
MISSING=987654321
norm() { python3 -c 'import json,sys
d=json.load(sys.stdin)
c=d.get("result",{}).get("content",[])
t=(c[0]["text"] if c else "")+json.dumps(d.get("error",""))
e=d.get("result",{}).get("isError",False)
print(("E:" if e else "O:")+t.replace(sys.argv[1],"N"))' "$2" < "$OUT/$1"; }
same() { # same <label> <key> <tool> <args containing @ID@> <guarded id>
  callas "$2" u1 "$(tc "$3" "${4//@ID@/$5}")"; callas "$2" u2 "$(tc "$3" "${4//@ID@/$MISSING}")"
  check "$1: a guarded id and a missing id get the same answer" "$(norm u1 "$5")" "$(norm u2 "$MISSING")"
  check "$1: and it is a refusal" "$(verdict u1)" "error"
}
for g in "$SUB" "$SUBREV" "$SUBATT"; do
  same "wp_get_post ($g)" "$RO" wp_get_post '{"ID":@ID@}' "$g"
  same "wp_get_post_snapshot ($g)" "$RO" wp_get_post_snapshot '{"ID":@ID@}' "$g"
  same "wp_get_post_meta ($g)" "$RO" wp_get_post_meta '{"ID":@ID@}' "$g"
  same "wp_read_post_meta_chunk ($g)" "$RO" wp_read_post_meta_chunk '{"ID":@ID@,"key":"_field_message"}' "$g"
  same "wp_get_post_terms ($g)" "$RO" wp_get_post_terms '{"ID":@ID@,"taxonomy":"category"}' "$g"
  same "wp_alter_post preview ($g)" "$RW" wp_alter_post '{"ID":@ID@,"field":"post_content","search":"a","replace":"b","preview":true}' "$g"
  same "wp_update_post preview ($g)" "$RW" wp_update_post '{"ID":@ID@,"fields":{"post_title":"x"},"preview":true}' "$g"
  same "wp_create_preview_link ($g)" "$RW" wp_create_preview_link '{"post_id":@ID@}' "$g"
  same "wp_duplicate_post ($g)" "$RW" wp_duplicate_post '{"ID":@ID@}' "$g"
  same "wp_copy_post_meta source ($g)" "$RW" wp_copy_post_meta "{\"from_id\":@ID@,\"to_id\":$PG2}" "$g"
  same "wp_add_post_terms ($g)" "$RW" wp_add_post_terms '{"ID":@ID@,"taxonomy":"category","terms":[1]}' "$g"
done
callas "$TOK" u0 "$(tc wp_delete_post "{\"ID\":$SUB,\"preview\":true}")"
check "an admin key's preview of deleting a submission is refused, with the reason" "$(has u0 "form submission")" "yes"
setrest true
same "get_media (REST)" "$RO" get_media '{"id":@ID@}' "$SUBATT"
setrest false
callas "$RO" u3 "$(tc wp_get_post "{\"ID\":$CTL}")"
callas "$RO" u4 "$(tc wp_get_post "{\"ID\":$MISSING}")"
check "CONTROL: an ordinary post is answered differently from a missing one (the comparison can tell)" \
  "$([ "$(norm u3 "$CTL")" != "$(norm u4 "$MISSING")" ] && echo differs || echo same)" "differs"
callas "$TOK" u5 "$(tc wp_get_post "{\"ID\":$SUB}")"
check "an ADMIN key is still told why" "$(has u5 "form submission")" "yes"
callas "$RO" u6 "$(tc wp_get_posts '{"post_type":"flamingo_inbound"}')"
check "a listing that names the submission type is refused with the reason (it names a type, not a post)" "$(has u6 "form submission")" "yes"
if [ "$REST_WAS" = unset ]; then
  wpe '$o=get_option("gmcp_options",[]);unset($o["mcp_tools_rest"]);update_option("gmcp_options",$o,false);delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null
else
  setrest "$REST_WAS"
fi
wpe 'foreach(["gmcp_smoke_channel","gmcp_smoke_shared","category"] as $tx){foreach(get_terms(["taxonomy"=>$tx,"hide_empty"=>false,"search"=>"'"$M2"'"]) as $t){wp_delete_term($t->term_id,$tx);}}foreach(get_comments(["search"=>"'"$M2"'","status"=>"all"]) as $c){wp_delete_comment($c->comment_ID,true);}' >/dev/null
docker compose exec -T cli rm -f /var/www/html/wp-content/mu-plugins/gmcp-smoke-forms-rel.php >/dev/null 2>&1

# ---------------------------------------------------------------------------------------------
echo "== Contact Form 7 + Flamingo =="
if ! docker compose exec -T cli wp plugin is-active contact-form-7 >/dev/null 2>&1 || ! docker compose exec -T cli wp plugin is-active flamingo >/dev/null 2>&1; then
  echo "  SKIP  Contact Form 7 and Flamingo are not both active"
else
  call cf_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
  check "the cf7 tools are offered" \
    "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print(sorted({"cf7_messages_briefing","cf7_list_messages","cf7_get_message"}-n) or True)' cf_list)" "True"
  # A form with a typed field and one whose name looks credential-shaped, saved through CF7.
  # A form is saved through CF7, and given a Flamingo channel the way CF7 itself does it on the
  # first stored message (modules/flamingo.php, wpcf7_flamingo_submit): the channel term is
  # made by wpcf7_flamingo_add_channel() and its id is kept in the form post's _flamingo meta.
  # That link, not the slug, is what ties a message to its form. Verified against Contact
  # Form 7 6.2.4.1 and Flamingo 2.6.4. cf7_seed <title> <form-template> <additional-settings>
  # prints "form_id:slug".
  cf7_seed() {
    local php='$f=WPCF7_ContactForm::get_template();$f->set_title("__T__");$f->set_properties(["form"=>"__F__","additional_settings"=>"__S__"]);$f->save();$id=$f->id();$slug=get_post($id)->post_name;$ch=wpcf7_flamingo_add_channel($slug,"__T__");update_post_meta($id,"_flamingo",["channel"=>$ch]);echo $id.":".$slug;'
    php="${php//__T__/$1}"; php="${php//__F__/$2}"; php="${php//__S__/$3}"
    wpe "$php"
  }
  FORM_SEED=$(cf7_seed "Smoke form" "[text* your-name] [email* your-email] [text api-key] [textarea your-message] [text your-subject]" "")
  FORM_ID="${FORM_SEED%%:*}"; FORM_SLUG="${FORM_SEED#*:}"
  echo "  Form $FORM_ID, slug: $FORM_SLUG"
  CLEAN="$CLEAN $FORM_ID"
  # Seeded through Flamingo's own writer (signature verified against Flamingo 2.6.4).
  MID=$(wpe '$m=Flamingo_Inbound_Message::add(["channel"=>"'"$FORM_SLUG"'","subject"=>"Smoke subject","from"=>"Ann <ann@example.test>","from_name"=>"Ann","from_email"=>"ann@example.test","fields"=>["your-name"=>"Ann Smoke","your-email"=>"ann@example.test","api-key"=>"CF7KEYLEAK","your-message"=>"hello there","your-subject"=>"Smoke subject","not-in-form"=>"CF7ORPHAN"],"meta"=>["remote_ip"=>"203.0.113.77","user_agent"=>"UA-SMOKE-MARK"],"akismet"=>null,"spam"=>false]);echo is_object($m)?$m->id():0;')
  echo "  Message: $MID"
  CLEAN="$CLEAN $MID"
  if [ "${MID:-0}" = "0" ]; then
    echo "  FAIL  seeding: Flamingo_Inbound_Message::add did not return a message (the seed call, not the tool, is suspect)"; fail=$((fail+1))
  else
    check "CONTROL: the IP address really is stored on the message" \
      "$(wpe '$m=get_post_meta('"$MID"',"_meta",true);echo $m["remote_ip"]??"none";')" "203.0.113.77"
    check "CONTROL: the credential-named answer really is stored" \
      "$(wpe 'echo get_post_meta('"$MID"',"_field_api-key",true);')" "CF7KEYLEAK"

    echo "-- admin reads, with stored-state controls --"
    call cf_get "$(tc cf7_get_message "{\"message_id\":$MID}")"
    check "get succeeds at admin" "$(verdict cf_get)" "ok"
    check "an ordinary answer is returned (the probe can find a value)" "$(has cf_get "Ann Smoke")" "yes"
    check "the IP address is not returned" "$(has cf_get "203.0.113.77")" "no"
    check "the user agent is not returned" "$(has cf_get "UA-SMOKE-MARK")" "no"
    check "a credential-named answer is not returned" "$(has cf_get "CF7KEYLEAK")" "no"
    check "an answer the form does not define is not returned" "$(has cf_get "CF7ORPHAN")" "no"
    check "and the withheld names are listed so the absence is visible" "$(has cf_get "api-key")" "yes"
    call cf_list_a "$(tc cf7_list_messages '{}')"
    check "list succeeds at admin" "$(verdict cf_list_a)" "ok"
    check "list does not return the IP either" "$(has cf_list_a "203.0.113.77")" "no"

    echo "-- read level sees counts only --"
    callas "$RO" cf_b "$(tc cf7_messages_briefing '{}')"
    check "the briefing works at read" "$(verdict cf_b)" "ok"
    check "the briefing carries a log_state block" "$(jget cf_b log_state.readable)" "true"
    check "the briefing does not contain a message body" "$(has cf_b "Ann Smoke")" "no"
    callas "$RO" cf_ro_get "$(tc cf7_get_message "{\"message_id\":$MID}")"
    check "a read-only key is refused cf7_get_message" "$(verdict cf_ro_get)" "error"
    callas "$RO" cf_ro_list "$(tc cf7_list_messages '{}')"
    check "a read-only key is refused cf7_list_messages" "$(verdict cf_ro_list)" "error"

    echo "-- there is no write or search surface --"
    call cf_tools '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
    check "no cf7 tool name suggests a write" \
      "$(py 'import json,sys,re;n=[t["name"] for t in json.load(sys.stdin)["result"]["tools"] if t["name"].startswith("cf7_")];print([x for x in n if re.search("update|delete|trash|spam|add_|set_|search",x)] or True)' cf_tools)" "True"
    call cf_s "$(tc cf7_list_messages '{"search":"Ann"}')"
    check "an extra search argument does not turn into a search (the unfiltered count is returned)" \
      "$(jget cf_s total)" "$(jget cf_list_a total)"

    echo "-- the subject is only returned when it is the visitor's own your-subject --"
    check "CONTROL: a default form returns its subject (the probe sees one that is allowed)" "$(jget cf_get message.subject)" "Smoke subject"
    # A form whose flamingo_subject template carries the IP address and user agent, so the
    # stored _subject (what Flamingo builds with wpcf7_mail_replace_tags) contains both.
    SEED2=$(cf7_seed "Smoke form tpl" "[text your-name] [text your-subject]" 'flamingo_subject: \"Enquiry from [_remote_ip] [_user_agent]\"')
    F2ID="${SEED2%%:*}"; F2SLUG="${SEED2#*:}"; CLEAN="$CLEAN $F2ID"
    MID2=$(wpe '$m=Flamingo_Inbound_Message::add(["channel"=>"'"$F2SLUG"'","subject"=>"Enquiry from 203.0.113.99 UA-TPL-MARK","from"=>"Bo <bo@example.test>","from_name"=>"Bo","from_email"=>"bo@example.test","fields"=>["your-name"=>"Bo Smoke","your-subject"=>"typed subject"],"meta"=>["remote_ip"=>"203.0.113.99","user_agent"=>"UA-TPL-MARK"],"akismet"=>null,"spam"=>false]);echo is_object($m)?$m->id():0;')
    CLEAN="$CLEAN $MID2"
    check "CONTROL: the template really is stored on the form, and the IP really is in the stored subject" \
      "$(wpe 'echo get_post_meta('"$MID2"',"_subject",true);')" "Enquiry from 203.0.113.99 UA-TPL-MARK"
    check "CONTROL: the form reports that template" "$(wpe 'echo wpcf7_strip_quote(WPCF7_ContactForm::get_instance('"$F2ID"')->pref("flamingo_subject"));')" "Enquiry from [_remote_ip] [_user_agent]"
    call cf_get2 "$(tc cf7_get_message "{\"message_id\":$MID2}")"
    check "get succeeds" "$(verdict cf_get2)" "ok"
    check "the subject is not returned when the template is not the default" "$(jget cf_get2 message.subject)" "null"
    check "the IP address from the subject is not in the reply" "$(has cf_get2 "203.0.113.99")" "no"
    check "the user agent from the subject is not in the reply" "$(has cf_get2 "UA-TPL-MARK")" "no"
    check "and the reply says the subject was withheld" "$(has cf_get2 "Withheld")" "yes"
    check "CONTROL: the ordinary answer on that form is still returned" "$(has cf_get2 "Bo Smoke")" "yes"

    echo "-- a form is found through its channel link, not its slug --"
    # F1 owns the message through its _flamingo link and types doc as a FILE (never returned).
    # F1 is then renamed, and F3 takes F1's old slug and types the same name as plain text.
    SEED1=$(cf7_seed "Smoke linked form" "[file doc] [text who]" "")
    F1ID="${SEED1%%:*}"; F1SLUG="${SEED1#*:}"; CLEAN="$CLEAN $F1ID"
    MID3=$(wpe '$m=Flamingo_Inbound_Message::add(["channel"=>"'"$F1SLUG"'","subject"=>"s","from"=>"c <c@example.test>","from_name"=>"c","from_email"=>"c@example.test","fields"=>["doc"=>"DOCVALUE-FROM-FILE-FIELD","who"=>"Cy Smoke"],"meta"=>["remote_ip"=>"203.0.113.5","user_agent"=>"u"],"akismet"=>null,"spam"=>false]);echo is_object($m)?$m->id():0;')
    CLEAN="$CLEAN $MID3"
    wpe 'wp_update_post(["ID"=>'"$F1ID"',"post_name"=>"smoke-renamed-'"$RANDOM"'"]);' >/dev/null
    SEED3=$(cf7_seed "Smoke impostor form" "[text doc] [text who]" "")
    F3ID="${SEED3%%:*}"; CLEAN="$CLEAN $F3ID"
    wpe 'delete_post_meta('"$F3ID"',"_flamingo");wp_update_post(["ID"=>'"$F3ID"',"post_name"=>"'"$F1SLUG"'"]);' >/dev/null
    check "CONTROL: the impostor form now holds the message channel's slug, and the real one does not" \
      "$(wpe 'echo get_post('"$F3ID"')->post_name==="'"$F1SLUG"'" && get_post('"$F1ID"')->post_name!=="'"$F1SLUG"'" ? "swapped":"not swapped";')" "swapped"
    call cf_get3 "$(tc cf7_get_message "{\"message_id\":$MID3}")"
    check "get succeeds" "$(verdict cf_get3)" "ok"
    check "the answer the LINKED form types as a file is withheld, not typed by the slug-matched form" "$(has cf_get3 "DOCVALUE-FROM-FILE-FIELD")" "no"
    check "CONTROL: a plain-text answer on the linked form is returned" "$(has cf_get3 "Cy Smoke")" "yes"

    echo "-- a field name with capitals is answered under its lower-cased stored key --"
    # Flamingo stores sanitize_key( '_field_' . name ), so Your-Name is _field_your-name. The
    # answer must be returned, and a credential-shaped capitalised name must still be withheld.
    SEED4=$(cf7_seed "Smoke caps form" "[text Your-Name] [text API-Key]" "")
    F4ID="${SEED4%%:*}"; F4SLUG="${SEED4#*:}"; CLEAN="$CLEAN $F4ID"
    MID4=$(wpe '$m=Flamingo_Inbound_Message::add(["channel"=>"'"$F4SLUG"'","subject"=>"s","from"=>"d <d@example.test>","from_name"=>"d","from_email"=>"d@example.test","fields"=>["Your-Name"=>"Dee Capital","API-Key"=>"CAPSKEYLEAK"],"meta"=>["remote_ip"=>"203.0.113.6","user_agent"=>"u"],"akismet"=>null,"spam"=>false]);echo is_object($m)?$m->id():0;')
    CLEAN="$CLEAN $MID4"
    check "CONTROL: Flamingo stored the answer under the lower-cased key" "$(wpe 'echo get_post_meta('"$MID4"',"_field_your-name",true);')" "Dee Capital"
    call cf_get4 "$(tc cf7_get_message "{\"message_id\":$MID4}")"
    check "the capitalised field's answer is returned" "$(has cf_get4 "Dee Capital")" "yes"
    check "the capitalised credential-shaped answer is still withheld" "$(has cf_get4 "CAPSKEYLEAK")" "no"
  fi

  echo "-- log_state: Flamingo gone --"
  docker compose exec -T cli wp plugin deactivate flamingo >/dev/null 2>&1
  call cf_nofl "$(tc cf7_messages_briefing '{}')"
  docker compose exec -T cli wp plugin activate flamingo >/dev/null 2>&1
  check "CONTROL: Flamingo is back" "$(docker compose exec -T cli wp plugin is-active flamingo >/dev/null 2>&1 && echo yes || echo no)" "yes"
  check "without Flamingo the briefing is not an error" "$(verdict cf_nofl)" "ok"
  check "and says nothing is being stored" "$(jget cf_nofl log_state.storage)" "none"
  check "and reports no counts, rather than a zero" "$(jget cf_nofl counts)" "null"
fi

# ---------------------------------------------------------------------------------------------
echo "== Fluent Forms =="
if ! docker compose exec -T cli wp plugin is-active fluentform >/dev/null 2>&1; then
  echo "  SKIP  Fluent Forms is not active"
else
  PFX=$(wpe 'global $wpdb;echo $wpdb->prefix;')
  # Seeded through Fluent's own query builder (UNVERIFIED columns). A hand-seeded row can omit
  # a column the plugin's own writer fills; if a read fails here, check the seed first.
  FFID=$(wpe '$id=wpFluent()->table("fluentform_forms")->insertGetId(["title"=>"Smoke FF","status"=>"published","type"=>"form","has_payment"=>0,"appearance_settings"=>"{}","form_fields"=>json_encode(["fields"=>[["element"=>"input_text","attributes"=>["name"=>"fullname"],"settings"=>["label"=>"Full name"]],["element"=>"input_password","attributes"=>["name"=>"pw"],"settings"=>["label"=>"Pw"]],["element"=>"input_file","attributes"=>["name"=>"cv"],"settings"=>["label"=>"CV"]],["element"=>"input_text","attributes"=>["name"=>"api_token"],"settings"=>["label"=>"Token"]]]]),"created_at"=>current_time("mysql"),"updated_at"=>current_time("mysql")]);echo $id;')
  FSID=$(wpe '$id=wpFluent()->table("fluentform_submissions")->insertGetId(["form_id"=>'"$FFID"',"serial_number"=>1,"response"=>json_encode(["fullname"=>"Fiona Smoke","pw"=>"FFHUNTER2","cv"=>"/wp-content/uploads/FFCV.pdf","api_token"=>"FFTOKENLEAK","stray"=>"FFSTRAY"]),"source_url"=>"https://example.test/FFSRC","user_id"=>0,"status"=>"unread","is_favourite"=>0,"browser"=>"FFBROWSER","device"=>"d","ip"=>"198.51.100.55","created_at"=>current_time("mysql"),"updated_at"=>current_time("mysql")]);echo $id;')
  echo "  Form $FFID, submission $FSID"
  if [ -z "$FFID" ] || [ -z "$FSID" ]; then
    echo "  FAIL  seeding: could not create the form or submission (the seed, not the tool, is suspect)"; fail=$((fail+1))
  else
    check "CONTROL: the IP is stored on the submission row" "$(wpe 'global $wpdb;echo $wpdb->get_var("SELECT ip FROM '"${PFX}"'fluentform_submissions WHERE id='"$FSID"'");')" "198.51.100.55"
    check "CONTROL: the password answer is stored in the response" "$(wpe 'global $wpdb;echo strpos($wpdb->get_var("SELECT response FROM '"${PFX}"'fluentform_submissions WHERE id='"$FSID"'"),"FFHUNTER2")!==false?"stored":"missing";')" "stored"
    call ff_get "$(tc fluentform_get_entry "{\"entry_id\":$FSID}")"
    check "get succeeds at admin" "$(verdict ff_get)" "ok"
    check "an ordinary answer is returned (the probe finds a value)" "$(has ff_get "Fiona Smoke")" "yes"
    for needle in FFHUNTER2 FFCV FFTOKENLEAK FFSTRAY 198.51.100.55 FFBROWSER FFSRC; do
      check "'$needle' is not returned" "$(has ff_get "$needle")" "no"
    done
    call ff_list "$(tc fluentform_list_entries "{\"form_id\":$FFID}")"
    check "list succeeds and previews the ordinary answer" "$(has ff_list "Fiona Smoke")" "yes"
    check "list does not return the password answer" "$(has ff_list "FFHUNTER2")" "no"
    callas "$RO" ff_ro "$(tc fluentform_get_entry "{\"entry_id\":$FSID}")"
    check "a read-only key is refused fluentform_get_entry" "$(verdict ff_ro)" "error"
    callas "$RO" ff_bro "$(tc fluentform_briefing '{}')"
    check "the briefing works at read and is counts only" "$(verdict ff_bro)" "ok"
    check "and carries a readable log_state" "$(jget ff_bro log_state.readable)" "true"
    check "and returns no answer" "$(has ff_bro "Fiona Smoke")" "no"
    # delete_entry_on_submission exists in Fluent Forms (formSettings in fluentform_form_meta).
    # A form set to it keeps nothing, so log_state names it instead of letting a zero stand.
    check "log_state lists no form that deletes entries when none is set" "$(jget ff_bro log_state.forms_that_delete_entries_on_submission)" "[]"
    wpe 'wpFluent()->table("fluentform_form_meta")->insert(["form_id"=>'"$FFID"',"meta_key"=>"formSettings","value"=>json_encode(["delete_entry_on_submission"=>"yes"])]);' >/dev/null
    check "CONTROL: the setting is stored where Fluent keeps it" "$(wpe 'global $wpdb;echo $wpdb->get_var("SELECT value FROM '"${PFX}"'fluentform_form_meta WHERE form_id='"$FFID"' AND meta_key=\"formSettings\"");')" '{"delete_entry_on_submission":"yes"}'
    callas "$RO" ff_bro2 "$(tc fluentform_briefing '{}')"
    check "log_state now names the form that deletes entries on submission" "$(jget ff_bro2 log_state.forms_that_delete_entries_on_submission)" "[$FFID]"
    wpe 'wpFluent()->table("fluentform_form_meta")->where("form_id",'"$FFID"')->where("meta_key","formSettings")->delete();' >/dev/null
    # A form whose definition is broken fails closed.
    wpe 'wpFluent()->table("fluentform_forms")->where("id",'"$FFID"')->update(["form_fields"=>"{broken"]);' >/dev/null
    call ff_badform "$(tc fluentform_get_entry "{\"entry_id\":$FSID}")"
    wpe 'wpFluent()->table("fluentform_forms")->where("id",'"$FFID"')->update(["form_fields"=>json_encode(["fields"=>[["element"=>"input_text","attributes"=>["name"=>"fullname"],"settings"=>["label"=>"Full name"]]]])]);' >/dev/null
    check "with an unreadable form definition, no answer is returned" "$(has ff_badform "Fiona Smoke")" "no"
    check "and the reply says why" "$(has ff_badform "could not be read")" "yes"
    call ff_ok2 "$(tc fluentform_get_entry "{\"entry_id\":$FSID}")"
    check "CONTROL: with the definition repaired the answer returns" "$(has ff_ok2 "Fiona Smoke")" "yes"

    echo "-- a missing table is reported as missing --"
    wpe 'global $wpdb;$wpdb->query("RENAME TABLE '"${PFX}"'fluentform_submissions TO '"${PFX}"'fluentform_submissions_hold");' >/dev/null
    call ff_notable "$(tc fluentform_briefing '{}')"
    wpe 'global $wpdb;$wpdb->query("RENAME TABLE '"${PFX}"'fluentform_submissions_hold TO '"${PFX}"'fluentform_submissions");' >/dev/null
    check "CONTROL: the table is back" "$(wpe 'global $wpdb;echo $wpdb->get_var("SELECT COUNT(*) FROM '"${PFX}"'fluentform_submissions WHERE id='"$FSID"'");')" "1"
    check "with the table gone the briefing is not an error" "$(verdict ff_notable)" "ok"
    check "readable is false" "$(jget ff_notable log_state.readable)" "false"
    check "and counts are null, not zero" "$(jget ff_notable counts)" "null"
    call ff_back "$(tc fluentform_briefing '{}')"
    check "CONTROL: with the table back, readable is true" "$(jget ff_back log_state.readable)" "true"
    wpe 'wpFluent()->table("fluentform_submissions")->where("id",'"$FSID"')->delete();wpFluent()->table("fluentform_forms")->where("id",'"$FFID"')->delete();' >/dev/null
  fi
fi

# ---------------------------------------------------------------------------------------------
echo "== Ninja Forms =="
if ! docker compose exec -T cli wp plugin is-active ninja-forms >/dev/null 2>&1; then
  echo "  SKIP  Ninja Forms is not active"
else
  # Seeded through Ninja Forms' own model API (UNVERIFIED method names).
  NFORM=$(wpe '$f=Ninja_Forms()->form()->get();$f->update_settings(["title"=>"Smoke NF"])->save();echo $f->get_id();')
  mkfield() { wpe '$x=Ninja_Forms()->form('"$NFORM"')->field()->get();$x->update_settings(["type"=>"'"$1"'","label"=>"'"$2"'","key"=>"'"$3"'"])->save();echo $x->get_id();'; }
  F_NAME=$(mkfield textbox "Name" smoke_name)
  F_PW=$(mkfield password "Password" smoke_pw)
  F_TOK=$(mkfield textbox "Token" smoke_api_token)
  echo "  Form $NFORM, fields $F_NAME $F_PW $F_TOK"
  NSUB=$(wpe '$s=Ninja_Forms()->form('"$NFORM"')->sub()->get();$s->update_field_value('"$F_NAME"',"Nina Smoke")->update_field_value('"$F_PW"',"NFHUNTER2")->update_field_value('"$F_TOK"',"NFTOKENLEAK")->save();echo $s->get_id();')
  echo "  Submission $NSUB"
  if [ -z "$NSUB" ] || [ "$NSUB" = "0" ]; then
    echo "  FAIL  seeding: the submission was not created (the seed, not the tool, is suspect)"; fail=$((fail+1))
  else
    CLEAN="$CLEAN $NSUB"
    check "CONTROL: the password answer is stored on the submission" "$(wpe 'echo get_post_meta('"$NSUB"',"_field_'"$F_PW"'",true);')" "NFHUNTER2"
    call nf_get "$(tc ninjaform_get_entry "{\"entry_id\":$NSUB}")"
    check "get succeeds at admin" "$(verdict nf_get)" "ok"
    check "an ordinary answer is returned (the probe finds a value)" "$(has nf_get "Nina Smoke")" "yes"
    check "the password answer is not returned" "$(has nf_get "NFHUNTER2")" "no"
    check "the credential-keyed answer is not returned" "$(has nf_get "NFTOKENLEAK")" "no"
    callas "$RO" nf_ro "$(tc ninjaform_get_entry "{\"entry_id\":$NSUB}")"
    check "a read-only key is refused ninjaform_get_entry" "$(verdict nf_ro)" "error"
    callas "$RO" nf_b "$(tc ninjaform_briefing '{}')"
    check "the briefing works at read, with log_state" "$(jget nf_b log_state.readable)" "true"
    check "and returns no answer" "$(has nf_b "Nina Smoke")" "no"
    # The generic readers must refuse the very rows these tools read.
    callas "$RO" nf_gen "$(tc wp_get_post_meta "{\"ID\":$NSUB}")"
    check "the generic meta reader refuses a real Ninja Forms submission" "$(verdict nf_gen)" "error"
    check "and does not return the password answer" "$(has nf_gen "NFHUNTER2")" "no"
  fi
  wpe 'Ninja_Forms()->form('"$NFORM"')->delete();' >/dev/null
fi

# ---- WPForms ---------------------------------------------------------------------------
# WPForms is in this group because it is a form plugin, and the shape of its support differs
# from the other three: on Lite it stores no submission rows at all. Measured on 2.0.3.1:
# wpforms()->obj('entry') is null, there is no src/Entries, and none of wpforms_entries,
# wpforms_entry_fields or wpforms_entry_meta exist. So there is no entry tool for it, and the
# briefing's job is to say which storage state this site is in rather than report a bare zero.
if docker compose exec -T cli wp plugin is-active wpforms-lite >/dev/null 2>&1; then
  echo "-- WPForms: schema and counts, with the source named --"
  WPF_MARK="wpformsmark$(date +%s)"
  WPF_SECRET="secret-${WPF_MARK}@example.test"
  WFORM=$(wpe '
    wp_set_current_user( 1 );
    $fields = [
      [ "id" => "0", "type" => "name", "label" => "Name", "format" => "simple", "required" => "1" ],
      [ "id" => "1", "type" => "email", "label" => "Email", "required" => "1" ],
      [ "id" => "2", "type" => "select", "label" => "Topic", "choices" => [ [ "label" => "Sales", "value" => "sales" ], [ "label" => "Support", "value" => "support" ] ] ],
      [ "id" => "3", "type" => "textarea", "label" => "Message", "required" => "1" ],
    ];
    $content = [
      "fields" => $fields,
      "settings" => [
        "notifications" => [ "1" => [ "email" => "'"$WPF_SECRET"'", "subject" => "probe" ] ],
        "confirmations" => [ "1" => [ "message" => "thanks", "redirect" => "https://example.test/x" ] ],
      ],
    ];
    $id = wpforms()->obj( "form" )->add( "Probe form '"$WPF_MARK"'", [ "post_content" => wp_json_encode( $content ) ] );
    echo $id;')
  if [ -z "$WFORM" ] || [ "$WFORM" = "0" ]; then
    echo "  FAIL  seeding: the form was not created (the seed, not the tool, is suspect)"; fail=$((fail+1))
  else
    echo "  Form $WFORM"
    # The control: the recipient really is stored, so its absence from the reply means something.
    check "CONTROL: the notification recipient is stored in the form" \
      "$(wpe 'echo strpos( (string) get_post_field( "post_content", '"$WFORM"' ), "secret-wpformsmark" ) !== false ? "yes" : "no";')" "yes"
    check "CONTROL: the plugin's own decode sees four fields" \
      "$(wpe 'echo count( (array) ( wpforms_decode( get_post_field( "post_content", '"$WFORM"' ) )["fields"] ?? [] ) );')" "4"

    call wpf_b "$(tc wpforms_briefing '{}')"
    check "the briefing answers" "$(verdict wpf_b)" "ok"
    check "it reports the storage state" "$(jget wpf_b log_state.plugin_loaded)" "true"
    check "the count source is named, not implied" "$(jget wpf_b log_state.count_source)" "counter"
    check "and the reply says nothing is storing submissions" "$(has wpf_b "no WPForms entry storage")" "yes"

    call wpf_f "$(tc wpforms_get_form '{"id":'"$WFORM"'}')"
    check "the form schema answers" "$(verdict wpf_f)" "ok"
    check "four fields are returned" "$(jget wpf_f form.field_count)" "4"
    check "field types are the stored ones" "$(jget wpf_f form.fields.2.type)" "select"
    # Structured, not a substring of the reply: a substring search passes on a field LABEL
    # that happens to contain the word, and fails if the reply is worded differently while the
    # data is right. The choice list is the claim.
    check "choice labels are returned" "$(jget wpf_f form.fields.2.choices.1)" "Support"
    check "the notification is counted" "$(jget wpf_f form.notifications_count)" "1"
    check "the recipient address is NOT returned" "$(has wpf_f "$WPF_SECRET")" "no"
    check "the confirmation redirect is NOT returned" "$(has wpf_f "example.test/x")" "no"

    # The counter, through the plugin's own read path: present means the plugin counted one.
    wpe 'update_post_meta( '"$WFORM"', "wpforms_entries_count", 7 );' >/dev/null
    check "CONTROL: the counter is stored" \
      "$(wpe 'echo (int) get_post_meta( '"$WFORM"', "wpforms_entries_count", true );')" "7"
    call wpf_b2 "$(tc wpforms_briefing '{"form_id":'"$WFORM"'}')"
    check "the tool reports the stored counter" "$(jget wpf_b2 counts.total)" "7"
    check "and says the counter is present" "$(jget wpf_b2 counts.by_form.0.counter_present)" "true"
    wpe 'delete_post_meta( '"$WFORM"', "wpforms_entries_count" );' >/dev/null
    call wpf_b3 "$(tc wpforms_briefing '{"form_id":'"$WFORM"'}')"
    check "an absent counter is reported as absent, not as a bare zero" "$(jget wpf_b3 counts.by_form.0.counter_present)" "false"

    # The form is a post, and the generic post tools must not reach it: the whole document,
    # recipient addresses included, is one post content field.
    call wpf_w "$(tc wp_update_post '{"ID":'"$WFORM"',"post_title":"changed by a generic tool"}')"
    check "a generic post write to a form is refused" "$(verdict wpf_w)" "error"
    check "and the refusal names WPForms" "$(has wpf_w "WPForms")" "yes"
    call wpf_c "$(tc wp_create_post '{"post_type":"wpforms","post_title":"made by a generic tool"}')"
    check "creating a form through the generic tools is refused too" "$(verdict wpf_c)" "error"
    callas "$RW" wpf_rw "$(tc wp_get_post '{"ID":'"$WFORM"'}')"
    check "a readwrite key cannot read the form's content" "$(verdict wpf_rw)" "error"
    call wpf_adm "$(tc wp_get_post '{"ID":'"$WFORM"'}')"
    check "CONTROL: an admin key still reads it, so the refusal above is the rule" "$(verdict wpf_adm)" "ok"

    wpe 'wp_set_current_user( 1 ); wpforms()->obj( "form" )->delete( '"$WFORM"' );' >/dev/null
    check "put back: the form is gone" "$(wpe 'echo get_post( '"$WFORM"' ) ? "present" : "gone";')" "gone"
  fi
else
  echo "  ....  WPForms block skipped: wpforms-lite is not active"
fi

echo "-- the group switch --"
setopt false
call sw_off "$(tc cf7_messages_briefing '{}')"
check "switched off, a cf7 tool called by name is refused" "$(verdict sw_off)" "error"
call sw_off2 "$(tc fluentform_briefing '{}')"
check "switched off, a fluentform tool is refused" "$(verdict sw_off2)" "error"
call sw_off3 "$(tc ninjaform_briefing '{}')"
check "switched off, a ninjaform tool is refused" "$(verdict sw_off3)" "error"
call sw_off4 "$(tc wpforms_briefing '{}')"
check "switched off, a wpforms tool is refused" "$(verdict sw_off4)" "error"
callas "$RO" sw_gen "$(tc wp_get_post_meta "{\"ID\":$NF}")"
check "and the generic readers still refuse submission posts with the group off" "$(verdict sw_gen)" "error"

echo "-- cleanup --"
setadmin "$ADMIN_WAS"
for p in $CLEAN; do wpe 'wp_delete_post('"$p"',true);' >/dev/null; done

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
