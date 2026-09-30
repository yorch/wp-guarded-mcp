#!/bin/bash
# Smoke test for the LearnDash tools.
#
# Its own file because it needs LearnDash installed and the LearnDash tool group switched
# on, and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin activate sfwd-lms
#   ./smoke-learndash.sh
#
# Every check asserts stored state rather than the wording of the reply, and every "the tool
# refused" or "X is absent" check sits beside a control proving the same probe finds it when
# present. The blocks that exist to catch one specific defect each say so in a comment.
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
TOK=$(gmcp_make_key "smoke learndash" admin)
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
ctext() { py 'import json,sys
d=json.load(sys.stdin)
c=d.get("result",{}).get("content",[])
print(c[0]["text"] if c else "")' "" "$1"; }
tokof() { ctext "$1" | sed -n 's/.*confirm set to "\([a-f0-9]*\)".*/\1/p'; }
dbq()  { docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpc()  { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
group_on()  { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_learndash"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
group_off() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_learndash"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }

if ! docker compose exec -T cli wp plugin is-active sfwd-lms >/dev/null 2>&1; then
  echo "LearnDash is not active. Activate it first:"
  echo "  docker compose exec -T cli wp plugin activate sfwd-lms"
  exit 2
fi
group_on

echo "-- seeding through LearnDash's own writers --"
# Never hand-built INSERTs: a course structure or enrolment written straight into meta that
# omits part of LearnDash's shape reads back as something else, and the failures below would
# describe a code fault that is really a seeding fault.
SEEDFILE=$(mktemp)
cat > "$SEEDFILE" <<'PHP'
<?php
// The user-activity tables are created only on an is_admin() request, which a CLI/REST run
// never makes, so create them here through LearnDash's own DDL or the enrolment below logs
// database errors.
if ( class_exists( 'Learndash_Admin_Data_Upgrades_User_Activity_DB_Table' ) ) {
  $rc = new ReflectionClass( 'Learndash_Admin_Data_Upgrades_User_Activity_DB_Table' );
  $rc->newInstanceWithoutConstructor()->upgrade_db_tables( '' );
}

foreach ( [ 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question', 'groups' ] as $type ) {
  foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $pid ) {
    if ( strpos( (string) get_the_title( $pid ), 'GMCP LD Smoke' ) === 0 ) { wp_delete_post( $pid, true ); }
  }
}
foreach ( [ 'gmcp_ld_student', 'gmcp_ld_other', 'gmcp_ld_groupie' ] as $login ) {
  $u = get_user_by( 'login', $login );
  if ( $u ) { wp_delete_user( $u->ID ); }
}

$course = wp_insert_post( [ 'post_type' => 'sfwd-courses', 'post_status' => 'publish', 'post_title' => 'GMCP LD Smoke Course' ] );
$l1 = wp_insert_post( [ 'post_type' => 'sfwd-lessons', 'post_status' => 'publish', 'post_title' => 'GMCP LD Smoke Lesson One' ] );
$l2 = wp_insert_post( [ 'post_type' => 'sfwd-lessons', 'post_status' => 'publish', 'post_title' => 'GMCP LD Smoke Lesson Two' ] );
$t1 = wp_insert_post( [ 'post_type' => 'sfwd-topic', 'post_status' => 'publish', 'post_title' => 'GMCP LD Smoke Topic One' ] );
$quiz = wp_insert_post( [ 'post_type' => 'sfwd-quiz', 'post_status' => 'publish', 'post_title' => 'GMCP LD Smoke Quiz' ] );
$q1 = wp_insert_post( [ 'post_type' => 'sfwd-question', 'post_status' => 'publish', 'post_title' => 'GMCP LD Smoke Question One', 'post_content' => 'What is two plus two?' ] );
$q2 = wp_insert_post( [ 'post_type' => 'sfwd-question', 'post_status' => 'publish', 'post_title' => 'GMCP LD Smoke Question Two', 'post_content' => 'What colour is the sky?' ] );
// The meta key LearnDash reads is question_points; 'points' is the form field's name, not
// the stored key. Seeding the wrong one made the suite agree with the tool's own wrong key.
update_post_meta( $q1, 'question_type', 'single' );
update_post_meta( $q1, 'question_points', 5 );

LDLMS_Factory_Post::course_steps( $course )->set_steps( [
  'sfwd-lessons' => [
    $l1 => [ 'sfwd-topic' => [ $t1 => [ 'sfwd-quiz' => [] ] ], 'sfwd-quiz' => [] ],
    $l2 => [ 'sfwd-topic' => [], 'sfwd-quiz' => [ $quiz => [] ] ],
  ],
  'sfwd-quiz' => [],
] );
LDLMS_Factory_Post::quiz_questions( $quiz )->set_questions( [ $q1, $q2 ] );

$student = wp_insert_user( [ 'user_login' => 'gmcp_ld_student', 'user_pass' => wp_generate_password(), 'user_email' => 'student@example.test', 'role' => 'subscriber' ] );
$other   = wp_insert_user( [ 'user_login' => 'gmcp_ld_other',   'user_pass' => wp_generate_password(), 'user_email' => 'other@example.test',   'role' => 'subscriber' ] );
$groupie = wp_insert_user( [ 'user_login' => 'gmcp_ld_groupie', 'user_pass' => wp_generate_password(), 'user_email' => 'groupie@example.test', 'role' => 'subscriber' ] );
ld_update_course_access( $student, $course );

$group = wp_insert_post( [ 'post_type' => 'groups', 'post_status' => 'publish', 'post_title' => 'GMCP LD Smoke Group' ] );
learndash_set_group_enrolled_courses( $group, [ $course ] );
learndash_set_groups_users( $group, [ $groupie ] );
// The group member ALSO holds a direct enrolment. Without it there is nothing for
// ld_unenroll_user's group guard to protect: the earlier "no direct enrolment to remove"
// check answers first, and a suite that only ever exercises that check passes while the
// group guard is broken. This is a person in a group who was enrolled individually too,
// which is the case the guard exists for.
ld_update_course_access( $groupie, $course );

echo 'SEED course=' . $course . ' l1=' . $l1 . ' l2=' . $l2 . ' topic=' . $t1 . ' quiz=' . $quiz . ' q1=' . $q1 . ' q2=' . $q2
  . ' student=' . $student . ' other=' . $other . ' groupie=' . $groupie . ' group=' . $group;
PHP
docker compose cp "$SEEDFILE" cli:/var/www/html/gmcp-ld-seed.php >/dev/null 2>&1
# The CLI container runs as a non-root UID, so the copied file is not readable by the wp
# process that runs it. chown it through the same container the file lands in.
docker compose exec -T -u 0 cli chmod 644 /var/www/html/gmcp-ld-seed.php >/dev/null 2>&1
SEED=$(docker compose exec -T cli wp eval-file /var/www/html/gmcp-ld-seed.php 2>&1 | tr -d '\r')
docker compose exec -T -u 0 cli rm -f /var/www/html/gmcp-ld-seed.php >/dev/null 2>&1
rm -f "$SEEDFILE"
case "$SEED" in
  *course=*) ;;
  *) echo "Seeding failed: $SEED" >&2; exit 1 ;;
esac
COURSE=$( echo "$SEED" | sed -n 's/.*course=\([0-9]*\).*/\1/p' )
LESSON=$( echo "$SEED" | sed -n 's/.* l1=\([0-9]*\).*/\1/p' )
QUIZ=$( echo "$SEED" | sed -n 's/.* quiz=\([0-9]*\).*/\1/p' )
STUDENT=$( echo "$SEED" | sed -n 's/.* student=\([0-9]*\).*/\1/p' )
OTHER=$( echo "$SEED" | sed -n 's/.* other=\([0-9]*\).*/\1/p' )
GROUPIE=$( echo "$SEED" | sed -n 's/.* groupie=\([0-9]*\).*/\1/p' )
GROUP=$( echo "$SEED" | sed -n 's/.* group=\([0-9]*\)$/\1/p' )
echo "  course=$COURSE lesson=$LESSON quiz=$QUIZ student=$STUDENT other=$OTHER groupie=$GROUPIE group=$GROUP"

echo "-- the fixture exists, and the probe discriminates (control) --"
# Without the second line, every "the user is not enrolled" check further down would also
# pass against a suite that seeded nothing at all.
check "CONTROL: the enrolled student has a direct enrolment in the database" \
  "$(dbq "SELECT CASE WHEN meta_value <> '' THEN 'set' ELSE 'empty' END FROM $(wpc db prefix)usermeta WHERE user_id=$STUDENT AND meta_key='course_${COURSE}_access_from' LIMIT 1")" "set"
check "CONTROL: the other user has NO direct enrolment" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)usermeta WHERE user_id=$OTHER AND meta_key='course_${COURSE}_access_from'")" "0"

echo "-- the tools are offered --"
call ld_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "the LearnDash group is listed when switched on" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
n={t["name"] for t in d["result"]["tools"]};need={"ld_list_courses","ld_get_course","ld_courses_briefing","ld_list_course_users","ld_list_user_courses","ld_get_user_progress","ld_get_quiz_questions","ld_enroll_user","ld_unenroll_user"};print(sorted(need-n) or True)' "" ld_list)" "True"
check "the Tools page declares the group to its save, not only WP-CLI" \
  "$(docker compose exec -T cli wp eval '$m=new ReflectionMethod("GMCP_Settings","field_kinds");$m->setAccessible(true);$k=$m->invoke(null);echo isset($k["mcp_tools_learndash"])?"declared":"missing";' 2>/dev/null | tr -d '\r\n')" "declared"

echo "-- reading course structure --"
call ld_courses '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"ld_list_courses","arguments":{}}}'
check "ld_list_courses succeeds" "$(verdict ld_courses)" "ok"
check "and counts the seeded lessons, topics and quizzes" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);c=[x for x in d["courses"] if x["id"]==int(sys.argv[1])][0];print(str(c["lessons"])+"/"+str(c["topics"])+"/"+str(c["quizzes"]))' "$COURSE" ld_courses)" "2/1/1"
call ld_course "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_get_course\",\"arguments\":{\"course_id\":$COURSE}}}"
check "ld_get_course succeeds" "$(verdict ld_course)" "ok"
check "and nests the topic under its lesson" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);s=d["structure"]["lessons"];print(s[0]["topics"][0]["title"])' "" ld_course)" "GMCP LD Smoke Topic One"
check "and nests the quiz under lesson two" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);s=d["structure"]["lessons"];print(s[1]["quizzes"][0]["id"])' "" ld_course)" "$QUIZ"
check "and never returns the course_access_list setting (user IDs)" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("course_access_list" in json.dumps(d))' "" ld_course)" "False"
call ld_brief '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"ld_courses_briefing","arguments":{}}}'
check "ld_courses_briefing succeeds" "$(verdict ld_brief)" "ok"

echo "-- reading quiz questions --"
call ld_quiz "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_get_quiz_questions\",\"arguments\":{\"quiz_id\":$QUIZ}}}"
check "ld_get_quiz_questions succeeds" "$(verdict ld_quiz)" "ok"
check "and returns both questions with their points" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(str(len(d["questions"]))+"-"+str([q["points"] for q in d["questions"] if q["points"]==5][0]))' "" ld_quiz)" "2-5"
check "CONTROL: a question text is returned (so the next check is not vacuous)" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("two plus two" in json.dumps(d).lower())' "" ld_quiz)" "True"
check "and no answer key, hint or feedback is returned" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);s=json.dumps(d).lower();print(any(k in s for k in ("answer_data","correct_msg","incorrect_msg","tip_msg","\"answer\"")))' "" ld_quiz)" "False"

echo "-- reading who holds access, and how --"
call ld_users "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_list_course_users\",\"arguments\":{\"course_id\":$COURSE}}}"
check "ld_list_course_users succeeds" "$(verdict ld_users)" "ok"
check "and lists the directly-enrolled student as via direct" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(next((u["via"] for u in d["users"] if u["user_id"]==int(sys.argv[1])),"MISSING"))' "$STUDENT" ld_users)" "['direct']"
check "and lists a member who has both reasons as direct+group" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(next((u["via"] for u in d["users"] if u["user_id"]==int(sys.argv[1])),"MISSING"))' "$GROUPIE" ld_users)" "['direct', 'group']"
call ld_ucourses "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_list_user_courses\",\"arguments\":{\"user_id\":$GROUPIE}}}"
check "ld_list_user_courses succeeds" "$(verdict ld_ucourses)" "ok"
check "and reports both reasons for a member who is enrolled as well" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);c=[x for x in d["courses"] if x["course_id"]==int(sys.argv[1])][0];print("group" in c["reasons"] and "direct" in c["reasons"])' "$COURSE" ld_ucourses)" "True"

echo "-- reading progress --"
call ld_prog "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_get_user_progress\",\"arguments\":{\"user_id\":$STUDENT,\"course_id\":$COURSE}}}"
check "ld_get_user_progress succeeds" "$(verdict ld_prog)" "ok"
check "and totals the lesson/topic steps" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d["progress"]["total"])' "" ld_prog)" "3"
check "and reports the student as directly enrolled" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d["access"]["direct"])' "" ld_prog)" "True"
call ld_prog_g "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_get_user_progress\",\"arguments\":{\"user_id\":$GROUPIE,\"course_id\":$COURSE}}}"
check "and reports the group member as both enrolled and grouped" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(str(d["access"]["direct"])+"/"+str(d["access"]["group"])+"/"+str(d["access"]["reachable"]))' "" ld_prog_g)" "True/True/True"

echo "-- enrolling is verified against stored state, not the return value --"
# ld_update_course_access() returns false both for "already enrolled" and for bad input, so a
# tool that maps the boolean to success/failure reports the opposite of the truth in one of
# the two cases. The check is the enrolment meta.
check "CONTROL: the user to enrol starts with no direct enrolment" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)usermeta WHERE user_id=$OTHER AND meta_key='course_${COURSE}_access_from'")" "0"
call ld_enrol "{\"jsonrpc\":\"2.0\",\"id\":10,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_enroll_user\",\"arguments\":{\"user_id\":$OTHER,\"course_id\":$COURSE}}}"
check "ld_enroll_user succeeds" "$(verdict ld_enrol)" "ok"
check "and the enrolment meta now exists" \
  "$(dbq "SELECT CASE WHEN meta_value <> '' THEN 'set' ELSE 'empty' END FROM $(wpc db prefix)usermeta WHERE user_id=$OTHER AND meta_key='course_${COURSE}_access_from' LIMIT 1")" "set"
call ld_enrol2 "{\"jsonrpc\":\"2.0\",\"id\":11,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_enroll_user\",\"arguments\":{\"user_id\":$OTHER,\"course_id\":$COURSE}}}"
check "enrolling an already-enrolled user reports that, rather than failing" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
t=d["result"]["content"][0]["text"]
print("already" in t.lower())' "" ld_enrol2)" "True"
check "CONTROL: and did not change the enrolment meta" \
  "$(dbq "SELECT CASE WHEN meta_value <> '' THEN 'set' ELSE 'empty' END FROM $(wpc db prefix)usermeta WHERE user_id=$OTHER AND meta_key='course_${COURSE}_access_from' LIMIT 1")" "set"
call ld_bad_course "{\"jsonrpc\":\"2.0\",\"id\":12,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_enroll_user\",\"arguments\":{\"user_id\":$OTHER,\"course_id\":$LESSON}}}"
check "enrolling into a lesson id (not a course) is refused" "$(verdict ld_bad_course)" "error"
call ld_bad_user "{\"jsonrpc\":\"2.0\",\"id\":13,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_enroll_user\",\"arguments\":{\"user_id\":99999999,\"course_id\":$COURSE}}}"
check "enrolling a user who does not exist is refused" "$(verdict ld_bad_user)" "error"

echo "-- removing an enrolment is two steps --"
call ld_un1 "{\"jsonrpc\":\"2.0\",\"id\":14,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_unenroll_user\",\"arguments\":{\"user_id\":$OTHER,\"course_id\":$COURSE}}}"
check "the first call is refused with a token (it changes nothing)" "$(verdict ld_un1)" "error"
check "CONTROL: the enrolment is UNCHANGED after the first call" \
  "$(dbq "SELECT CASE WHEN meta_value <> '' THEN 'set' ELSE 'empty' END FROM $(wpc db prefix)usermeta WHERE user_id=$OTHER AND meta_key='course_${COURSE}_access_from' LIMIT 1")" "set"
TOKEN=$(tokof ld_un1)
check "CONTROL: a token was actually supplied" "$([ -n "$TOKEN" ] && echo yes || echo no)" "yes"
call ld_un2 "{\"jsonrpc\":\"2.0\",\"id\":15,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_unenroll_user\",\"arguments\":{\"user_id\":$OTHER,\"course_id\":$COURSE,\"confirm\":\"$TOKEN\"}}}"
check "the second call with the token succeeds" "$(verdict ld_un2)" "ok"
check "and the enrolment meta is gone" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)usermeta WHERE user_id=$OTHER AND meta_key='course_${COURSE}_access_from'")" "0"
check "CONTROL: the user is still a real account after the removal" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)users WHERE ID=$OTHER")" "1"

echo "-- removing refuses when there is nothing direct to remove --"
call ld_un_other "{\"jsonrpc\":\"2.0\",\"id\":16,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_unenroll_user\",\"arguments\":{\"user_id\":$OTHER,\"course_id\":$COURSE}}}"
check "removing an enrolment that does not exist is refused" "$(verdict ld_un_other)" "error"

echo "-- a group member is not unenrolled by removing a direct row --"
# This user holds BOTH a direct enrolment and a group membership, which is the only state in
# which the group guard is reachable: with no direct enrolment the "nothing to remove" check
# answers first and this block would pass without the guard existing at all.
check "CONTROL: the group member holds a direct enrolment too" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)usermeta WHERE user_id=$GROUPIE AND meta_key='course_${COURSE}_access_from' AND meta_value <> ''")" "1"
call ld_un_group "{\"jsonrpc\":\"2.0\",\"id\":17,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_unenroll_user\",\"arguments\":{\"user_id\":$GROUPIE,\"course_id\":$COURSE}}}"
check "removing a group member's access is refused" "$(verdict ld_un_group)" "error"
check "and the refusal names the LearnDash Group as the source" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
t=d["result"]["content"][0]["text"].lower()
print("comes from a learndash group" in t)' "" ld_un_group)" "True"
check "CONTROL: the direct enrolment is still there (the guard protected it)" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)usermeta WHERE user_id=$GROUPIE AND meta_key='course_${COURSE}_access_from' AND meta_value <> ''")" "1"
check "CONTROL: the group still grants the course" \
  "$(wpc eval 'echo learndash_user_group_enrolled_to_course('"$GROUPIE"','"$COURSE"')?"yes":"no";')" "yes"

echo "-- the tools announce their writes --"
docker exec -u 0 "${COMPOSE_PROJECT_NAME:-wptest}-wp-1" mkdir -p /var/www/html/wp-content/mu-plugins
docker compose exec -T wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/ld-mutate-probe.php <<"PHPEOF"
<?php
add_action( "gmcp_mutate", function ( $tool ) {
  $seen = get_option( "ld_probe_mutate", [] );
  $seen[] = $tool;
  update_option( "ld_probe_mutate", $seen, false );
}, 10, 1 );
PHPEOF'
check "CONTROL: the mutation probe is installed and can observe" \
  "$(docker compose exec -T wp sh -c 'test -f /var/www/html/wp-content/mu-plugins/ld-mutate-probe.php && echo present || echo absent' 2>/dev/null | tr -d '\r\n')" "present"
check "control: and WordPress has loaded it" \
  "$(docker compose exec -T cli wp eval 'echo has_action("gmcp_mutate") ? "hooked" : "not hooked";' 2>/dev/null | tr -d '\r\n')" "hooked"
wpc option delete ld_probe_mutate >/dev/null 2>&1
call mu_enrol "{\"jsonrpc\":\"2.0\",\"id\":18,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_enroll_user\",\"arguments\":{\"user_id\":$OTHER,\"course_id\":$COURSE}}}"
call mu_read '{"jsonrpc":"2.0","id":19,"method":"tools/call","params":{"name":"ld_list_courses","arguments":{}}}'
ld_fired() { wpc eval 'echo in_array("'"$1"'", (array) get_option("ld_probe_mutate",[]), true) ? "fires" : "silent";'; }
check "control: the enrolment itself succeeded" "$(verdict mu_enrol)" "ok"
check "an enrolment announces itself" "$(ld_fired ld_enroll_user)" "fires"
check "but a read does not" "$(ld_fired ld_list_courses)" "silent"
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/ld-mutate-probe.php' >/dev/null 2>&1
wpc option delete ld_probe_mutate >/dev/null 2>&1

echo "-- a readonly key cannot reach student data --"
RO=$(gmcp_make_key "smoke learndash readonly" readonly)
call_ro() { curl -sS -X POST "$URL" -H "Authorization: Bearer $RO" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$2" -o "$OUT/$1"; }
call_ro ro_read "{\"jsonrpc\":\"2.0\",\"id\":20,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_list_courses\",\"arguments\":{}}}"
check "CONTROL: a readonly key can call the read-level course list" "$(verdict ro_read)" "ok"
call_ro ro_write "{\"jsonrpc\":\"2.0\",\"id\":21,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_list_course_users\",\"arguments\":{\"course_id\":$COURSE}}}"
check "but cannot call the admin-level student list" "$(verdict ro_write)" "error"
docker compose exec -T cli wp eval 'foreach ( GMCP_Tokens::all() as $k ) { if ( ( $k["label"] ?? "" ) === "smoke learndash readonly" ) { GMCP_Tokens::revoke( $k["id"] ); } }' >/dev/null 2>&1

echo "-- the audit log records the target (user id), not an empty string --"
check "an ld_ audit row names the user it was aimed at" \
  "$(dbq "SELECT target FROM $(wpc db prefix)gmcp_audit WHERE tool='ld_enroll_user' AND target='$OTHER' ORDER BY id DESC LIMIT 1")" "$OTHER"

echo "-- a read tool does not mutate anything --"
# ld_course_access_expired() is not a predicate: on a lapsed enrolment it deletes the
# enrolment row and, on a course configured to, the user's progress. An earlier version of
# this group called it from access_state(), which every read tool uses, so asking "how far
# did they get" could delete the answer. This block builds an EXPIRED enrolment, because on
# a live one the function returns early and the defect would not show.
wpc eval '
  $e = get_user_meta( '"$STUDENT"', "course_'"$COURSE"'_access_from", true );
  update_option( "gmcp_ld_saved_access_from", $e, false );
  update_user_meta( '"$STUDENT"', "course_'"$COURSE"'_access_from", time() - 400 * DAY_IN_SECONDS );
  learndash_update_setting( '"$COURSE"', "expire_access", 1 );
  learndash_update_setting( '"$COURSE"', "expire_access_days", 30 );
' >/dev/null 2>&1
check "CONTROL: the enrolment is present (and expired) before the read" \
  "$(dbq "SELECT CASE WHEN meta_value <> '' THEN 'set' ELSE 'empty' END FROM $(wpc db prefix)usermeta WHERE user_id=$STUDENT AND meta_key='course_${COURSE}_access_from' LIMIT 1")" "set"
EXPIRED_META=$(dbq "SELECT meta_id,meta_value FROM $(wpc db prefix)usermeta WHERE user_id=$STUDENT AND meta_key='course_${COURSE}_access_from' LIMIT 1")
call ld_read_progress "{\"jsonrpc\":\"2.0\",\"id\":26,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_get_user_progress\",\"arguments\":{\"user_id\":$STUDENT,\"course_id\":$COURSE}}}"
check "a progress read succeeds on an expired enrolment" "$(verdict ld_read_progress)" "ok"
check "and the enrolment row is byte-identical afterwards" \
  "$(dbq "SELECT meta_id,meta_value FROM $(wpc db prefix)usermeta WHERE user_id=$STUDENT AND meta_key='course_${COURSE}_access_from' LIMIT 1")" "$EXPIRED_META"
check "and no expiry marker was written by the read" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)usermeta WHERE user_id=$STUDENT AND meta_key='learndash_course_expired_${COURSE}'")" "0"
call ld_read_users "{\"jsonrpc\":\"2.0\",\"id\":27,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_list_course_users\",\"arguments\":{\"course_id\":$COURSE}}}"
check "a course-users read succeeds" "$(verdict ld_read_users)" "ok"
check "and the enrolment row survives that read too" \
  "$(dbq "SELECT meta_id,meta_value FROM $(wpc db prefix)usermeta WHERE user_id=$STUDENT AND meta_key='course_${COURSE}_access_from' LIMIT 1")" "$EXPIRED_META"
# Put the enrolment and the course settings back for the checks that follow.
wpc eval '
  $e = get_option( "gmcp_ld_saved_access_from" );
  update_user_meta( '"$STUDENT"', "course_'"$COURSE"'_access_from", $e );
  delete_option( "gmcp_ld_saved_access_from" );
  learndash_update_setting( '"$COURSE"', "expire_access", 0 );
  learndash_update_setting( '"$COURSE"', "expire_access_days", 0 );
' >/dev/null 2>&1
check "the enrolment is restored for the checks that follow" \
  "$(dbq "SELECT CASE WHEN meta_value <> '' THEN 'set' ELSE 'empty' END FROM $(wpc db prefix)usermeta WHERE user_id=$STUDENT AND meta_key='course_${COURSE}_access_from' LIMIT 1")" "set"

echo "-- writes refuse an unpublished course --"
DRAFT=$(wpc eval '$id=wp_insert_post(["post_type"=>"sfwd-courses","post_status"=>"draft","post_title"=>"GMCP LD Smoke Draft"]);echo $id;')
call ld_draft_enrol "{\"jsonrpc\":\"2.0\",\"id\":28,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_enroll_user\",\"arguments\":{\"user_id\":$OTHER,\"course_id\":$DRAFT}}}"
check "enrolling into a draft course is refused" "$(verdict ld_draft_enrol)" "error"
check "CONTROL: and no enrolment was written for the draft" \
  "$(dbq "SELECT COUNT(*) FROM $(wpc db prefix)usermeta WHERE user_id=$OTHER AND meta_key='course_${DRAFT}_access_from'")" "0"
call ld_draft_unenrol "{\"jsonrpc\":\"2.0\",\"id\":29,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_unenroll_user\",\"arguments\":{\"user_id\":$OTHER,\"course_id\":$DRAFT}}}"
check "unenrolling from a draft course is refused" "$(verdict ld_draft_unenrol)" "error"
call ld_draft_read "{\"jsonrpc\":\"2.0\",\"id\":30,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_get_course\",\"arguments\":{\"course_id\":$DRAFT}}}"
check "CONTROL: but a draft course can still be READ" "$(verdict ld_draft_read)" "ok"

echo "-- the group switch --"
group_off
call ld_off '{"jsonrpc":"2.0","id":22,"method":"tools/list"}'
check "switched off, no ld_ tool is offered" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
print(len([t for t in d["result"]["tools"] if t["name"].startswith("ld_")]))' "" ld_off)" "0"
call ld_off_call "{\"jsonrpc\":\"2.0\",\"id\":23,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_list_courses\",\"arguments\":{}}}"
check "and calling one anyway is refused" "$(verdict ld_off_call)" "error"
group_on

echo "-- LearnDash deactivated --"
docker compose exec -T cli wp plugin deactivate sfwd-lms >/dev/null 2>&1
call ld_gone "{\"jsonrpc\":\"2.0\",\"id\":24,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_list_courses\",\"arguments\":{}}}"
check "with LearnDash gone, the tool refuses cleanly" "$(verdict ld_gone)" "error"
check "saying it is not loaded, not that it does not exist" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
t=d["result"]["content"][0]["text"]
print("not loaded" in t.lower())' "" ld_gone)" "True"
docker compose exec -T cli wp plugin activate sfwd-lms >/dev/null 2>&1
call ld_back "{\"jsonrpc\":\"2.0\",\"id\":25,\"method\":\"tools/call\",\"params\":{\"name\":\"ld_list_courses\",\"arguments\":{}}}"
check "CONTROL: reactivating makes the tools work again" "$(verdict ld_back)" "ok"

echo "-- cleanup --"
docker compose exec -T cli wp eval-file - <<'PHP' >/dev/null 2>&1
<?php
foreach ( [ 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question', 'groups' ] as $type ) {
  foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $pid ) {
    if ( strpos( (string) get_the_title( $pid ), 'GMCP LD Smoke' ) === 0 ) { wp_delete_post( $pid, true ); }
  }
}
foreach ( [ 'gmcp_ld_student', 'gmcp_ld_other', 'gmcp_ld_groupie' ] as $login ) {
  $u = get_user_by( 'login', $login );
  if ( $u ) { wp_delete_user( $u->ID ); }
}
PHP
group_off

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
