#!/bin/bash
# Smoke test for the listing tools under another plugin's query filters.
#
# The listings (wp_get_posts, wp_get_media, wp_get_comments and the generated list_* REST tools)
# leave unreadable rows out in SQL, which needs query filters ON, so every filter another plugin
# registered applies to them as well. This suite installs a test-only mu-plugin
# (mu-foreign-filters.php, next to this file) that stands in for such a plugin, in benign and in
# hostile shapes, and asks two questions of each:
#
#   1. Does the guard hold? A form submission's tree (the submission, a file attached to it, a
#      comment on it, a page hanging from it) must not come back from any listing to a read-only
#      key, whatever the other plugin did to the query.
#   2. What happens to the page? Paging over a hidden row must give the pages it would give if the
#      row did not exist, and a count must not be moved by the other plugin's filter.
#
# Needs only this plugin (the submission type is named, not provided: the guard asks the type).
# Every "absent" check is paired with a control: the marker is first shown to be stored, the same
# probe is shown to find an ordinary row, and the hostile filter is shown to have run (its call
# count is written to an option) and, where it widens the query, to have changed the result.
set -u
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
HERE="$(cd "$(dirname "$0")" && pwd)"
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
RO=$(gmcp_make_key "smoke listing filters readonly" readonly)
case "$RO" in gmcp_*) ;; *) echo "Could not create an API key for the suite. Is the plugin active?" >&2; exit 1 ;; esac
OUT=$(mktemp -d)
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1));
  else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
wpe() { docker compose exec -T cli wp eval "$1" 2>/dev/null | tr -d '\r\n'; }
tc() { printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2"; }
callro() { curl -sS -X POST "$URL" -H "Authorization: Bearer $RO" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d "$(tc "$1" "$2")" -o "$OUT/$3"; }
# The whole reply: result text plus any error.
has() { python3 -c 'import json,sys
d=json.load(sys.stdin)
c=d.get("result",{}).get("content",[])
t=(c[0]["text"] if c else "")+json.dumps(d.get("error",""))
print("yes" if sys.argv[1] in t else "no")' "$2" < "$OUT/$1"; }
# The titles/contents of the rows of a list reply, in order, as "a|b|c" (REST titles are objects).
rows() { python3 -c 'import json,sys
d=json.load(sys.stdin)
c=d.get("result",{}).get("content",[])
try: j=json.loads(c[0]["text"])
except Exception: print("UNPARSEABLE"); sys.exit()
if not isinstance(j,list): print("NOTLIST"); sys.exit()
out=[]
for x in j:
    v=x.get("post_title") or x.get("title") or x.get("comment_content") or ""
    if isinstance(v,dict): v=v.get("rendered","")
    out.append(v)
print("|".join(out))' < "$OUT/$1"; }
# Does a list reply hold a row whose id (or ID) is this number?
hasid() { python3 -c 'import json,sys
d=json.load(sys.stdin)
c=d.get("result",{}).get("content",[])
try: j=json.loads(c[0]["text"])
except Exception: print("no"); sys.exit()
print("yes" if isinstance(j,list) and any(isinstance(x,dict) and str(x.get("id",x.get("ID")))==sys.argv[1] for x in j) else "no")' "$2" < "$OUT/$1"; }
setmode() { docker compose exec -T cli wp eval 'update_option("gmcp_test_filter_mode","'"$1"'");update_option("gmcp_test_filter_arg",'"${2:-0}"');update_option("gmcp_test_filter_ids","'"${3:-}"'");update_option("gmcp_test_filter_hits",0,false);' >/dev/null 2>&1; }
hits() { wpe 'echo (int) get_option("gmcp_test_filter_hits",0);'; }

OPTS_WAS=$(wpe 'echo wp_json_encode( array_intersect_key( get_option("gmcp_options",[]), array_flip(["mcp_tools_core","mcp_tools_rest"]) ) );')
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_core"]=true;$o["mcp_tools_rest"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1
docker compose exec -T cli sh -c 'cat > /var/www/html/wp-content/mu-plugins/gmcp-test-foreign-filters.php' < "$HERE/mu-foreign-filters.php"

# ---------------------------------------------------------------------------------------------
echo "== Fixtures =="
# Dates in 2031 so a date-bounded count selects exactly these. Three ordinary files and one
# attached to a submission, between them in date order; three comments, one on the submission.
SEED=$(wpe '
$ids = [];
$mk = function ( $k, $a ) use ( &$ids ) { $ids[$k] = wp_insert_post( $a ); };
$mk( "A", [ "post_title" => "GMCPFF-A", "post_type" => "post", "post_status" => "publish" ] );
$mk( "B", [ "post_title" => "GMCPFF-B", "post_type" => "post", "post_status" => "publish" ] );
$mk( "DRAFT", [ "post_title" => "GMCPFF-DRAFT", "post_type" => "post", "post_status" => "draft" ] );
$mk( "SUB", [ "post_title" => "GMCPFF-SUBMISSION", "post_type" => "nf_sub", "post_status" => "publish" ] );
$mk( "KID", [ "post_title" => "GMCPFF-CHILDPAGE", "post_type" => "page", "post_status" => "publish", "post_parent" => $ids["SUB"] ] );
$m = function ( $k, $title, $parent, $sec ) use ( $mk ) {
  $d = "2031-03-04 12:00:" . sprintf( "%02d", $sec );
  $mk( $k, [ "post_title" => $title, "post_type" => "attachment", "post_status" => "inherit", "post_parent" => $parent,
    "post_mime_type" => "image/png", "guid" => "http://example.test/" . $k . ".png", "post_date" => $d, "post_date_gmt" => $d ] );
};
$m( "M1", "GMCPFF-FILE-1", 0, 3 );
$m( "UP", "GMCPFF-UPLOAD", $ids["SUB"], 2 );
$m( "M2", "GMCPFF-FILE-2", 0, 1 );
$m( "M3", "GMCPFF-FILE-3", 0, 0 );
$c = function ( $k, $post, $text, $sec ) use ( &$ids ) {
  $d = "2031-03-04 12:00:" . sprintf( "%02d", $sec );
  $ids[$k] = wp_insert_comment( [ "comment_post_ID" => $post, "comment_content" => $text, "comment_approved" => 1, "comment_date" => $d, "comment_date_gmt" => $d ] );
};
$c( "C1", $ids["A"], "GMCPFF-COMMENT-1", 3 );
$c( "CS", $ids["SUB"], "GMCPFF-SUBCOMMENT", 2 );
$c( "C2", $ids["A"], "GMCPFF-COMMENT-2", 1 );
echo wp_json_encode( $ids );')
jid() { python3 -c 'import json,sys;print(json.loads(sys.argv[1])[sys.argv[2]])' "$SEED" "$1"; }
A=$(jid A); B=$(jid B); SUB=$(jid SUB); KID=$(jid KID); UP=$(jid UP); M1=$(jid M1); M2=$(jid M2); M3=$(jid M3)
C1=$(jid C1); CS=$(jid CS); C2=$(jid C2)
check "CONTROL: the upload is stored as an attachment of the submission" \
  "$(wpe 'echo get_post_type('"$UP"') . ":" . wp_get_post_parent_id('"$UP"') . ":" . get_post_type('"$SUB"');')" "attachment:$SUB:nf_sub"
check "CONTROL: the comment on the submission is stored on it" "$(wpe 'echo get_comment('"$CS"')->comment_post_ID;')" "$SUB"
check "CONTROL: the page is stored as a child of the submission" "$(wpe 'echo wp_get_post_parent_id('"$KID"');')" "$SUB"
check "CONTROL: no foreign filter mode is set yet" "$(wpe 'echo get_option("gmcp_test_filter_mode","") === "" ? "none" : "set";')" "none"

SECRETS="GMCPFF-SUBMISSION GMCPFF-UPLOAD GMCPFF-SUBCOMMENT GMCPFF-CHILDPAGE"
# Every reader a read-only key has that lists posts, media or comments, asked in the way that
# can surface the submission's tree (a search for the shared marker, any type or status).
readers() {
  printf '%s\n' \
    'wp_get_posts|{"post_type":"any","post_status":"any","search":"GMCPFF","limit":100}' \
    'wp_get_posts|{"post_type":"page","post_status":"any","search":"GMCPFF","limit":100}' \
    'wp_get_media|{"search":"GMCPFF","limit":100}' \
    'wp_get_comments|{"search":"GMCPFF","status":"all","limit":100}' \
    'list_posts|{"search":"GMCPFF","status":"any","per_page":100,"_fields":"id,title"}' \
    'list_pages|{"search":"GMCPFF","status":"any","per_page":100,"_fields":"id,title"}' \
    'list_media|{"search":"GMCPFF","per_page":100,"_fields":"id,title"}' \
    'list_media|{"search":"GMCPFF","per_page":100,"_fields":"title"}' \
    'list_posts|{"search":"GMCPFF","status":"any","per_page":100,"_fields":"title"}' \
    'list_media|{"per_page":100,"_fields":"id"}'
}
# Which of the submission's markers came back from any reader, naming the reader.
leaks() {
  local out="" n=0 line t a m
  while IFS= read -r line; do
    t="${line%%|*}"; a="${line#*|}"; n=$((n+1))
    callro "$t" "$a" "r$n"
    for m in $SECRETS; do
      [ "$(has "r$n" "$m")" = yes ] && out="$out $t:$m"
    done
    # list_media without the search carries ids only: judge it by the stored id of the upload.
    [ "$(hasid "r$n" "$UP")" = yes ] && out="$out $t:id-$UP"
  done < <(readers)
  echo "${out# }"
}

# ---------------------------------------------------------------------------------------------
echo "== Block 1: the guard holds under another plugin's filters =="
setmode ""
check "no filter: no reader returns the submission's tree" "$(leaks)" ""
callro wp_get_posts '{"post_type":"post","post_status":"any","search":"GMCPFF","limit":100}' c1
check "CONTROL: the same probe finds an ordinary post" "$(has c1 GMCPFF-A)" "yes"
callro wp_get_media '{"search":"GMCPFF","limit":100}' c2
check "CONTROL: the same probe finds an ordinary file" "$(has c2 GMCPFF-FILE-1)" "yes"
callro wp_get_comments '{"search":"GMCPFF","status":"all","limit":100}' c3
check "CONTROL: the same probe finds an ordinary comment" "$(has c3 GMCPFF-COMMENT-1)" "yes"
check "CONTROL: a draft is not in the default listing (so the widening below is visible)" \
  "$(callro list_posts '{"search":"GMCPFF","per_page":100,"_fields":"id,title"}' c4; has c4 GMCPFF-DRAFT)" "no"

# mode | argument | ids | whether it widens the query so the draft shows in the default REST listing
for spec in \
  "where_exclude|$A||no" "clauses_join|0||no" "pre_get_posts_type|0||no" \
  "where_or1|0||yes" "where_reset_early|0||yes" "where_reset_max|0||yes" \
  "clauses_reset|0||yes" "clauses_request_reset|0||yes" "clauses_request_or1|0||yes" \
  "request_rewrite|0||yes" "pre_get_posts_suppress|0||no" "pre_query_index|0||no" \
  "results_inject|$UP||no" "the_posts_inject|$UP||no" "pre_query_inject|$UP||no" \
  "comments_exclude|$C2||no" "comments_reset|0||no" "comments_or1|0||no" \
  "comments_results_inject|$CS||no" "comments_pre_query_ids|0|$CS,$C1,$C2|no" \
  "late_the_posts|$UP||no" "late_rest_inject|$UP||no" "late_rest_inject_noid|$UP||no" ; do
  IFS='|' read -r mode arg ids widens <<< "$spec"
  setmode "$mode" "$arg" "$ids"
  check "$mode: no reader returns the submission's tree" "$(leaks)" ""
  check "CONTROL: $mode: the filter ran (it would be a pass for the wrong reason otherwise)" \
    "$([ "$(hits)" -gt 0 ] && echo yes || echo no)" "yes"
  if [ "$widens" = yes ]; then
    setmode "$mode" "$arg" "$ids"
    callro list_posts '{"search":"GMCPFF","per_page":100,"_fields":"id,title"}' w
    check "CONTROL: $mode: the filter widened the query (the draft is in the default REST listing)" "$(has w GMCPFF-DRAFT)" "yes"
  fi
done
setmode ""

# ---------------------------------------------------------------------------------------------
echo "== Block 2: what the SQL guard reports to a pager =="
# held is false when the finished query no longer carries the guard, and the listings then page
# in PHP. Asked directly, because over HTTP only its effect is visible.
held() { wpe '
$held = null;
GMCP_Core::with_readable_posts_sql( function () { return get_posts( [ "post_type" => "attachment", "post_status" => "inherit", "suppress_filters" => false, "no_found_rows" => true, "posts_per_page" => 5 ] ); }, $held );
echo $held ? "held" : "lost";'; }
cheld() { wpe '
$held = null;
GMCP_Core::with_readable_comments_sql( function () { return get_comments( [ "number" => 5, "cache_domain" => "gmcp_held_probe" ] ); }, $held );
echo $held ? "held" : "lost";'; }
setmode ""
check "no filter: the posts guard is held" "$(held)" "held"
check "no filter: the comments guard is held" "$(cheld)" "held"
for spec in "where_exclude|$A|held" "clauses_join|0|held" "where_or1|0|held" "where_reset_early|0|held" "where_reset_max|0|held" \
  "clauses_reset|0|held" "clauses_request_reset|0|held" "clauses_request_or1|0|held" \
  "pre_get_posts_suppress|0|held" "request_rewrite|0|lost" "pre_query_inject|$UP|lost" "pre_query_index|0|lost"; do
  IFS='|' read -r mode arg want <<< "$spec"
  setmode "$mode" "$arg"
  check "$mode: posts guard reported as $want" "$(held)" "$want"
done
setmode "comments_reset" 0
check "comments_reset: comments guard held (the last word is the guard's)" "$(cheld)" "held"
setmode "comments_or1" 0
check "comments_or1: comments guard held" "$(cheld)" "held"
setmode "comments_pre_query_ids" 0 "$CS,$C1"
check "comments_pre_query_ids: comments guard lost (answered without a query)" "$(cheld)" "lost"
setmode ""


echo "== Block 3: the guard's hooks are taken off again =="
HOOKS='foreach ( [ "posts_clauses_request", "posts_request", "posts_request_ids", "posts_pre_query", "the_posts", "pre_get_posts", "comments_clauses", "comments_pre_query" ] as $h ) { $n[$h] = isset( $GLOBALS["wp_filter"][$h] ) ? array_sum( array_map( "count", $GLOBALS["wp_filter"][$h]->callbacks ) ) : 0; } echo wp_json_encode( $n );'
BEFORE=$(wpe "$HOOKS")
check "after a listing run, no hook of the guard is left (callback counts as before the run)" "$(wpe '
GMCP_Core::with_readable_posts_sql( function () { return 1; }, $h, true );
GMCP_Core::with_readable_comments_sql( function () { return 1; } );
'"$HOOKS")" "$BEFORE"
check "after a run that throws, no hook of the guard is left" "$(wpe '
try { GMCP_Core::with_readable_posts_sql( function () { throw new RuntimeException( "x" ); }, $h, true ); } catch ( RuntimeException $e ) {}
try { GMCP_Core::with_readable_comments_sql( function () { throw new RuntimeException( "x" ); } ); } catch ( RuntimeException $e ) {}
'"$HOOKS")" "$BEFORE"
check "CONTROL: inside a run the hooks are present" "$(wpe '
echo GMCP_Core::with_readable_posts_sql( function () { return json_encode( [ (bool) has_filter("posts_clauses_request"), (bool) has_filter("posts_pre_query"), (bool) has_filter("pre_get_posts") ] ); } );')" "[true,true,true]"

# ---------------------------------------------------------------------------------------------
echo "== Block 4: paging over a hidden row =="
# Newest first the files are 1, upload (hidden), 2, 3. One row per page must give 1, 2, 3 and then
# nothing, whether the database counts the offset or, when the guard's SQL was taken out of the
# query, PHP does.
setmode ""
MEDIA='{"post_type":"attachment","post_status":"inherit"'
page_titles() { # page_titles <tool> [extra JSON members, with a leading comma]
  local out="" off
  for off in 0 1 2 3; do
    callro "$1" "{\"search\":\"GMCPFF\",\"limit\":1,\"offset\":$off${2:-}}" pg
    out="$out$(rows pg),"
  done
  echo "$out"
}
check "no filter: wp_get_posts (attachments) pages over the hidden upload" "$(page_titles wp_get_posts ',"post_type":"attachment","post_status":"inherit"')" "GMCPFF-FILE-1,GMCPFF-FILE-2,GMCPFF-FILE-3,,"
check "no filter: wp_get_comments pages over the hidden comment" "$(page_titles wp_get_comments ',"status":"all"')" "GMCPFF-COMMENT-1,GMCPFF-COMMENT-2,,,"
setmode "pre_query_index" 0
check "guard's SQL taken out (another plugin answers from its own index): the same pages, the offset passed over in PHP" \
  "$(page_titles wp_get_posts ',"post_type":"attachment","post_status":"inherit"')" "GMCPFF-FILE-1,GMCPFF-FILE-2,GMCPFF-FILE-3,,"
check "CONTROL: that filter ran" "$([ "$(hits)" -gt 0 ] && echo yes || echo no)" "yes"
setmode "comments_pre_query_ids" 0 "$C1,$CS,$C2"
check "comments answered without a query: wp_get_comments gives the same pages" \
  "$(page_titles wp_get_comments ',"status":"all"')" "GMCPFF-COMMENT-1,GMCPFF-COMMENT-2,,,"
check "CONTROL: that filter ran" "$([ "$(hits)" -gt 0 ] && echo yes || echo no)" "yes"
setmode "where_exclude" "$M2"
check "a benign filter hiding one file: the pages skip it and nothing else (its omission is the other plugin's)" \
  "$(page_titles wp_get_posts ',"post_type":"attachment","post_status":"inherit"')" "GMCPFF-FILE-1,GMCPFF-FILE-3,,,"
callro wp_get_post "{\"ID\":$M2}" one
check "CONTROL: the omitted file is still readable by id (the listing, not the guard, left it out)" "$(has one GMCPFF-FILE-2)" "yes"
setmode ""

echo "== Block 5: a count is not moved by another plugin's filter =="
# wp_count_media with a date bound counts with WP_Query; the files above are the only ones dated
# 2031, so the count is 4 (counts are not read-guarded, see docs/guardrails.md).
check "no filter: the dated count is the four files" "$(callro wp_count_media '{"after":"2030-01-01"}' n; python3 -c 'import json;print(json.load(open("'"$OUT"'/n"))["result"]["content"][0]["text"])')" "4"
for spec in "where_or1|0|moved" "clauses_reset|0|moved" "where_exclude|$M2|3" "clauses_join|0|0"; do
  IFS='|' read -r mode arg bite <<< "$spec"
  setmode "$mode" "$arg"
  callro wp_count_media '{"after":"2030-01-01"}' n
  check "$mode: the dated count is still 4" "$(python3 -c 'import json;print(json.load(open("'"$OUT"'/n"))["result"]["content"][0]["text"])')" "4"
  # The filter does not apply to this count (it runs with the query filters off, as WordPress's own
  # undated count does). Control: the same date bound through an ordinary WP_Query, which the
  # filter does reach, gives another number.
  plain=$(wpe 'echo (new WP_Query( [ "post_type" => "attachment", "post_status" => "inherit", "date_query" => [ [ "after" => "2030-01-01" ] ], "posts_per_page" => 1, "no_found_rows" => false ] ))->found_posts;')
  [ "$bite" = moved ] && { [ "$plain" != 4 ] && plain=moved; }
  check "CONTROL: $mode: an ordinary query with the same date bound is moved by the filter" "$plain" "$bite"
done
setmode ""

# ---------------------------------------------------------------------------------------------
echo "== Block 6: the REST rows are judged by id after the response is built =="
setmode ""
callro list_media '{"search":"GMCPFF","per_page":100,"_fields":"title"}' f1
check "_fields without id: the rows carry no id (it was asked for to judge by, and taken out)" \
  "$(python3 -c 'import json,sys
d=json.load(open(sys.argv[1]));j=json.loads(d["result"]["content"][0]["text"])
print("noid" if j and all("id" not in x for x in j) else "id-present-or-empty")' "$OUT/f1")" "noid"
callro list_media '{"search":"GMCPFF","per_page":100,"_fields":"id,title"}' f2
check "_fields with id: the rows carry it" "$(hasid f2 "$M1")" "yes"
callro list_media '{"search":"GMCPFF","per_page":100,"_fields":"title"}' f3
check "CONTROL: the id-less reply still holds the ordinary files" "$(has f3 GMCPFF-FILE-1)$(has f3 GMCPFF-FILE-3)" "yesyes"
check "CONTROL: and not the upload" "$(has f3 GMCPFF-UPLOAD)" "no"
# Without the judge after the response, a row added at the last filter of the dispatch is returned.
setmode "late_rest_inject_noid" "$UP"
callro list_media '{"search":"GMCPFF","per_page":100,"_fields":"id,title"}' f4
check "a row with no id added behind everything is dropped" "$(has f4 GMCPFF-UPLOAD)" "no"
check "CONTROL: the filter added it" "$([ "$(hits)" -gt 0 ] && echo yes || echo no)" "yes"
setmode ""

# ---------------------------------------------------------------------------------------------
echo "== Cleanup =="
docker compose exec -T cli rm -f /var/www/html/wp-content/mu-plugins/gmcp-test-foreign-filters.php >/dev/null 2>&1
docker compose exec -T cli wp eval '
foreach ( [ "gmcp_test_filter_mode", "gmcp_test_filter_arg", "gmcp_test_filter_ids", "gmcp_test_filter_hits", "gmcp_test_filter_marker" ] as $o ) { delete_option( $o ); }
foreach ( json_decode( '"'"''"$SEED"''"'"', true ) as $k => $id ) {
  if ( in_array( $k, [ "C1", "CS", "C2" ], true ) ) { wp_delete_comment( $id, true ); } else { wp_delete_post( $id, true ); }
}
$o = get_option( "gmcp_options", [] ); $was = json_decode( '"'"''"$OPTS_WAS"''"'"', true ) ?: [];
foreach ( [ "mcp_tools_core", "mcp_tools_rest" ] as $k ) { if ( array_key_exists( $k, $was ) ) { $o[$k] = $was[$k]; } else { unset( $o[$k] ); } }
update_option( "gmcp_options", $o, false );' >/dev/null 2>&1
check "CONTROL: the fixtures are gone" "$(wpe 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE '"'"'GMCPFF%'"'"'" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_content LIKE '"'"'GMCPFF%'"'"'" );')" "0"
check "the mu-plugin is gone" "$(wpe 'echo file_exists( WPMU_PLUGIN_DIR . "/gmcp-test-foreign-filters.php" ) ? "present" : "gone";')" "gone"

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
