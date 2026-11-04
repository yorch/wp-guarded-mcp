#!/bin/bash
# Smoke test for the form-submission tools: Contact Form 7 (with Flamingo), Fluent Forms and
# Ninja Forms, plus the generic-reader guard they depend on.
#
# STATUS: UNRUN. Written on a machine with no Docker daemon and none of the three plugins
# installed, from memory of how each stores a submission. Every storage assumption is marked
# UNVERIFIED in includes/tools-forms.php. The seeding code below calls each plugin's own
# writer where one is believed to exist, and those calls are the likeliest lines to be wrong:
# a seeding failure here is reported as a seeding failure, not as a failure of the tool. Run
# it, read each failure, and decide which it is.
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
for k in "$TOK" "$RO"; do
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
  check "and the refusal is the personal-data guard's, not some other error" "$(has g0 "form submission")" "yes"
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
echo "== Contact Form 7 + Flamingo =="
if ! docker compose exec -T cli wp plugin is-active contact-form-7 >/dev/null 2>&1 || ! docker compose exec -T cli wp plugin is-active flamingo >/dev/null 2>&1; then
  echo "  SKIP  Contact Form 7 and Flamingo are not both active"
else
  call cf_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
  check "the cf7 tools are offered" \
    "$(py 'import json,sys;n={t["name"] for t in json.load(sys.stdin)["result"]["tools"]};print(sorted({"cf7_messages_briefing","cf7_list_messages","cf7_get_message"}-n) or True)' cf_list)" "True"
  # A form with a typed field and one whose name looks credential-shaped, saved through CF7.
  FORM_SLUG=$(wpe '$f=WPCF7_ContactForm::get_template();$f->set_title("Smoke form");$f->set_properties(["form"=>"[text* your-name] [email* your-email] [text api-key] [textarea your-message]"]);$f->save();echo $f->name();')
  echo "  Form slug: $FORM_SLUG"
  # Seeded through Flamingo's own writer (signature UNVERIFIED).
  MID=$(wpe '$m=Flamingo_Inbound_Message::add(["channel"=>"'"$FORM_SLUG"'","subject"=>"Smoke subject","from"=>"Ann <ann@example.test>","from_name"=>"Ann","from_email"=>"ann@example.test","fields"=>["your-name"=>"Ann Smoke","your-email"=>"ann@example.test","api-key"=>"CF7KEYLEAK","your-message"=>"hello there","not-in-form"=>"CF7ORPHAN"],"meta"=>["remote_ip"=>"203.0.113.77","user_agent"=>"UA-SMOKE-MARK"],"akismet"=>null,"spam"=>false]);echo is_object($m)?$m->id():0;')
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

echo "-- the group switch --"
setopt false
call sw_off "$(tc cf7_messages_briefing '{}')"
check "switched off, a cf7 tool called by name is refused" "$(verdict sw_off)" "error"
call sw_off2 "$(tc fluentform_briefing '{}')"
check "switched off, a fluentform tool is refused" "$(verdict sw_off2)" "error"
call sw_off3 "$(tc ninjaform_briefing '{}')"
check "switched off, a ninjaform tool is refused" "$(verdict sw_off3)" "error"
callas "$RO" sw_gen "$(tc wp_get_post_meta "{\"ID\":$NF}")"
check "and the generic readers still refuse submission posts with the group off" "$(verdict sw_gen)" "error"

echo "-- cleanup --"
for p in $CLEAN; do wpe 'wp_delete_post('"$p"',true);' >/dev/null; done

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
