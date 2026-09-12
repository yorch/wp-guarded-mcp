#!/bin/bash
# Smoke test for the site-administration tools and the guards around them.
#
# Separate from smoke.sh because these need the admin tools switched on and some of
# them reach wordpress.org. Every check here corresponds to a defect that was found
# and fixed, so a failure means a regression, not a flaky network.
#
# Responses go to files, never through shell variables: a JSON body full of \/ and \n
# escapes does not survive echo.
set -u
# The site under test. Override to run against a second stack, which a parallel worktree
# needs: this suite is destructive, and two runs sharing a database produce failures that
# look like real regressions in both.
#
#   GMCP_URL=http://localhost:8081 ./smoke-admin.sh
BASE="${GMCP_URL:-http://localhost:8080}"
URL="$BASE/wp-json/mcp/v1/http"
# The suite mints itself a key. There is no shared token any more, and a key is shown
# once, so there is nothing to read back out of the database and hardcode here.
#
# Recreated under the same label each run rather than reused, and the shape is asserted:
# without the guard, a failure to create one leaves TOK empty, every request 401s, and a
# suite that reports a hundred failures is describing one missing credential.
gmcp_make_key() { # gmcp_make_key <label>
  docker compose exec -T cli wp eval '
    $label = "'"$1"'";
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); }
    }
    $admins = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $a = GMCP_Tokens::create( $label, "admin", 0, [], $admins ? $admins[0]->ID : 0 );
    echo $a["secret"];' 2>/dev/null | tr -d '\r\n'
}
# Every key except the suite's own. Blocks that test key behaviour used to wipe the whole
# option, which was harmless while the suite authenticated with a shared token kept
# elsewhere. The suite's credential is now a key too, so a blanket wipe revokes it
# mid-run, every later request 401s, and the checks report the features as broken rather
# than the credential as gone.
gmcp_clear_other_keys() { # gmcp_clear_other_keys <keep-label>
  docker compose exec -T cli wp eval '
    $keep = "'"$1"'";
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) !== $keep ) { GMCP_Tokens::revoke( $k["id"] ); }
    }' >/dev/null 2>&1
}

TOK=$(gmcp_make_key "smoke suite")
case "$TOK" in
  gmcp_*) ;;
  *) echo "Could not create an API key for the suite. Is the plugin active?" >&2; exit 1 ;;
esac
# The group this suite exists to test, switched on the way smoke-woo.sh and
# smoke-elementor.sh switch on theirs. mcp_tools_admin defaults to OFF, deliberately: these
# tools install code and change how a site renders, so they are opt-in. Nothing in the
# documented setup turned it on, so a first run against a fresh stack found none of the
# tools it names registered and reported 147 failures describing every guard in the plugin
# as broken. One missing setting, read as a catastrophe, which is the same shape as the
# missing credential the key helper above guards against.
#
# Asserted rather than assumed, because a write that silently did nothing would put the
# suite straight back into that state with no clue why.
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_admin"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1
ADMIN_ON=$(docker compose exec -T cli wp eval 'global $gmcp_core; echo $gmcp_core->get_option("mcp_tools_admin") ? "on" : "off";' 2>/dev/null | tr -d '\r\n')
if [ "$ADMIN_ON" != "on" ]; then
  echo "Could not switch the administration tools on, so every check below would fail for that reason alone." >&2
  exit 1
fi

OUT=$(mktemp -d)
pass=0; fail=0

call() { # call <file> <json>
  curl -sS -X POST "$URL" \
    -H "Authorization: Bearer $TOK" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' \
    -d "$2" -o "$OUT/$1"
}

# The API also answers on ?rest_route=, which is the only way in while .htaccess is
# missing and pretty permalinks are broken. The recovery tests below depend on it.
call_plain() { # call <file> <json>, without pretty permalinks
  curl -sS -X POST "$BASE/index.php?rest_route=/mcp/v1/http" \
    -H "Authorization: Bearer $TOK" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' \
    -d "$2" -o "$OUT/$1"
}

check() { # check <label> <actual> <expected>
  if [ "$2" = "$3" ]; then
    printf '  PASS  %s\n' "$1"; pass=$((pass+1))
  else
    printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1))
  fi
}

py() { python3 -c "$1" < "$OUT/$2"; }
# "error" when the call was refused, "ok" when it went through.
# A refusal arrives as a tool-level isError result rather than a JSON-RPC error,
# because clients treat protocol errors as a broken server and may never show the
# text to the model. The two-step confirmation depends on that text being read.
verdict() { py 'import json,sys;d=json.load(sys.stdin);print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "$1"; }
# The refusal text, wherever it ended up.
refusal() { py 'import json,sys;d=json.load(sys.stdin);print(d["error"]["message"] if "error" in d else d["result"]["content"][0]["text"])' "$1"; }

# Refuse to run without what this suite drives, rather than reporting its absence as a
# regression. Three fresh stacks in a row produced a wall of failures that were a missing
# prerequisite, and the backup ones land in the single place where a false negative is
# expensive: this plugin treats "cannot tell" as a first-class answer precisely so nobody
# reads a missing provider as a working one.
#
# futuretheme is the subtler one. The check above it already proves a MISSING theme is
# refused; this one proves a theme needing a newer PHP is refused, and without the theme
# present both assert the same thing and the second proves nothing. It passes on the
# missing-theme branch, which is the wrong branch.
#
# Asked directly, never through a helper that ends in a pipe: a pipeline exits with its
# last command's status, so a guard built on one can never fire.
missing=""
for p in updraftplus backuply; do
  docker compose exec -T cli wp plugin is-installed "$p" >/dev/null 2>&1 || missing="$missing $p"
done
if [ -n "$missing" ]; then
  echo "Missing backup plugins this suite drives:$missing"
  echo "  docker compose exec -T cli wp plugin install$missing"
  exit 2
fi
if ! docker compose exec -T wp test -f /var/www/html/wp-content/themes/futuretheme/style.css >/dev/null 2>&1; then
  echo "The futuretheme fixture is missing, so the 'needs a newer PHP' check would pass"
  echo "on the missing-theme branch instead. Create it:"
  echo "  docker compose exec -T cli bash -c 'mkdir -p /var/www/html/wp-content/themes/futuretheme &&"
  echo "    printf \"/*\\nTheme Name: Future Theme\\nRequires PHP: 99.0\\nVersion: 1.0\\n*/\\n\" \\"
  echo "      > /var/www/html/wp-content/themes/futuretheme/style.css &&"
  echo "    printf \"<?php\\n\" > /var/www/html/wp-content/themes/futuretheme/index.php'"
  exit 2
fi

# Reset anything a previous run left behind. A smoke suite you cannot run twice is
# not much of a smoke suite, and every failure on the second run was this rather than
# a real regression: a menu that already existed, widgets that had accumulated, a
# widget the last run had already deleted.
reset_state() {
  docker compose exec -T cli wp theme activate twentytwentyfive >/dev/null 2>&1
  docker compose exec -T cli wp eval '
    foreach ( wp_get_nav_menus() as $m ) { wp_delete_nav_menu( $m->term_id ); }
    $s = wp_get_sidebars_widgets();
    foreach ( array_keys( $s ) as $k ) { if ( $k !== "array_version" ) { $s[ $k ] = []; } }
    wp_set_sidebars_widgets( $s );
    update_option( "widget_block", [ "_multiwidget" => 1 ] );
    update_option( "widget_text", [ "_multiwidget" => 1 ] );
    foreach ( [ "classic-editor" ] as $slug ) {
      if ( is_plugin_active( $slug . "/" . $slug . ".php" ) ) { deactivate_plugins( [ $slug . "/" . $slug . ".php" ] ); }
    }
  ' >/dev/null 2>&1
  docker compose exec -T cli wp plugin delete classic-editor >/dev/null 2>&1
  docker compose exec -T cli wp option update blogdescription "reset" >/dev/null 2>&1
  docker compose exec -T cli wp option update default_role subscriber >/dev/null 2>&1
  docker compose exec -T cli wp option update users_can_register 0 >/dev/null 2>&1
  docker compose exec -T cli wp transient delete gmcp_admin_email_cooldown >/dev/null 2>&1
  docker compose exec -T cli wp option delete adminhash >/dev/null 2>&1
  docker compose exec -T cli wp option delete gmcp_journal >/dev/null 2>&1
  docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1
  gmcp_clear_other_keys "smoke suite"
  # The scheduled-events block schedules smoke_test_event and never cleared a previous
  # run's, so a second run found two occurrences and three checks counting them failed.
  # Every one of those failures was about arithmetic on leftovers, not about cron.
  docker compose exec -T cli wp cron event delete smoke_test_event >/dev/null 2>&1
  # A role that is dangerous WITHOUT holding edit_posts: the case the first guard missed.
  docker compose exec -T cli wp eval 'remove_role("api_admin"); add_role("api_admin","API Admin",["read"=>true,"manage_options"=>true]);' >/dev/null 2>&1
  # The backup sections drive both adapters in sequence and expect to begin with
  # UpdraftPlus driving and Backuply out of the way. Left however the last run ended them,
  # the first section finds both active and reports Backuply, which reads as the older
  # provider losing a contest it is supposed to win. That is a state fault presenting as a
  # product fault, and it is what the rest of this function exists to prevent.
  docker compose exec -T cli wp plugin activate updraftplus >/dev/null 2>&1
  docker compose exec -T cli wp plugin deactivate backuply >/dev/null 2>&1
}
reset_state

echo "-- admin pages (one submenu page per section, not tabs) --"
# Placed here on purpose: after reset_state has steadied the fixtures, before any
# destructive section, and reset_state touches no users, passwords, credentials or
# tool-group flags, so neither this block nor the admin-switch-on gate above
# depends on anything below. It mutates nothing itself.
# Every admin link in the plugin goes through GMCP_Settings::page_url(), so the mapping
# from section name to page slug is the contract the menu, the redirects and the entry
# links all share. Assert the page query param it produces, which is host-independent.
# Pure PHP, no HTTP: what needs a login is covered by hand, and the mapping is what a
# refactor would silently break (a renamed slug without an updated PAGES entry).
page_of() { # page_of <section>
  docker compose exec -T cli wp eval '
    parse_str( parse_url( GMCP_Settings::page_url( "'"$1"'" ), PHP_URL_QUERY ), $q );
    echo $q["page"];' 2>/dev/null | tr -d '\r\n'
}
# The empty section is the control: the parent slug never moved, so if the probe cannot
# find even that, the failures below describe the probe rather than the code.
check "connection page keeps the parent slug" "$(page_of '')" "guarded-mcp-settings"
check "access section has its own page" "$(page_of access)" "guarded-mcp-access"
check "tools section has its own page" "$(page_of tools)" "guarded-mcp-tools"
check "logging section has its own page" "$(page_of logging)" "guarded-mcp-logging"
check "logs section has its own page" "$(page_of logs)" "guarded-mcp-logs"
# And each page has its own render callback. The tabbed code rendered all four through
# one render(), so this fails there and passes here.
check "each page has its own render callback" \
  "$(docker compose exec -T cli wp eval 'echo (int) ( method_exists( "GMCP_Settings", "render_connect_page" ) && method_exists( "GMCP_Settings", "render_access_page" ) && method_exists( "GMCP_Settings", "render_tools_page" ) && method_exists( "GMCP_Settings", "render_logging_page" ) && method_exists( "GMCP_Settings", "render_logs_page" ) );' 2>/dev/null | tr -d '\r\n')" "1"

echo "-- admin pages answer over HTTP --"
# The mapping block proves the URLs; this proves the pages render behind them, with
# the right heading and the sibling cross-links both ways. It needs a logged-in
# administrator, which nothing else here needs: admin/admin is the documented
# dev-stack default (see .dev/README.md and screenshots.js), so a stack with other
# credentials skips the block instead of failing it. The login cookie gates the
# block, which is also what separates a broken probe from a real failure: with a
# cookie but wrong headings, these fail rather than skip.
JAR=$(mktemp)
curl -sS -c "$JAR" -b "$JAR" -d 'log=admin&pwd=admin&rememberme=forever&testcookie=1' "$BASE/wp-login.php" -o /dev/null 2>/dev/null
if grep -q wordpress_logged_in "$JAR"; then
  page_h1() { # page_h1 <slug>
    curl -sS -b "$JAR" "$BASE/wp-admin/admin.php?page=$1" 2>/dev/null | grep -o '<h1>[^<]*</h1>' | head -n 1
  }
  check "connection page renders" "$(page_h1 guarded-mcp-settings)" "<h1>Connection</h1>"
  check "access page renders" "$(page_h1 guarded-mcp-access)" "<h1>Access</h1>"
  check "tools page renders" "$(page_h1 guarded-mcp-tools)" "<h1>Tools</h1>"
  check "logging page renders" "$(page_h1 guarded-mcp-logging)" "<h1>Logging</h1>"
  check "audit log renders" "$(page_h1 guarded-mcp-logs)" "<h1>Audit Log</h1>"
  check "logging page links to the audit log" \
    "$(curl -sS -b "$JAR" "$BASE/wp-admin/admin.php?page=guarded-mcp-logging" 2>/dev/null | grep -q 'page=guarded-mcp-logs' && echo yes || echo no)" "yes"
  check "audit log links to logging" \
    "$(curl -sS -b "$JAR" "$BASE/wp-admin/admin.php?page=guarded-mcp-logs" 2>/dev/null | grep -q 'page=guarded-mcp-logging' && echo yes || echo no)" "yes"
else
  echo "  SKIP  admin login failed; the HTTP block needs the dev-stack admin credential"
fi
rm -f "$JAR"

echo "-- credential protection (privilege escalation) --"
call opt_read '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"gmcp_options"}}}'
check "own options are unreadable" "$(verdict opt_read)" "error"
call opt_raw '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"gmcp_options","raw":true}}}'
check "raw read cannot bypass it" "$(verdict opt_raw)" "error"
call opt_write '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"gmcp_options","value":{"mcp_bearer_token":"pwned","mcp_role":"admin"}}}}'
check "own options are unwritable" "$(verdict opt_write)" "error"
call opt_cred '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"some_plugin_api_key"}}}'
check "credential-shaped keys refused" "$(verdict opt_cred)" "error"
call opt_ok '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"blogname"}}}'
check "ordinary options still readable" "$(verdict opt_ok)" "ok"

echo "-- the identifier the plugin tools need --"
call plist '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"wp_list_plugins","arguments":{}}}'
check "wp_list_plugins returns the plugin file" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(all("plugin" in x and "active" in x for x in d))' plist)" "True"

echo "-- theme activation guards --"
call t_missing '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"wp_activate_theme","arguments":{"stylesheet":"no-such-theme"}}}'
check "missing theme refused" "$(verdict t_missing)" "error"
# The one that matters: WP_Theme::errors() says nothing about "Requires PHP", so
# without validate_theme_requirements() this switch succeeds and the next request
# fatals, taking the front end, wp-admin and this API down with no way back.
call t_future '{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"wp_activate_theme","arguments":{"stylesheet":"futuretheme"}}}'
check "theme needing a newer PHP refused" "$(verdict t_future)" "error"
# wp theme list --field=name yields the stylesheet directory, not the display name.
check "active theme unchanged" \
  "$(docker compose exec -T cli wp theme list --status=active --field=name 2>/dev/null | tr -d '\r\n')" "twentytwentyfive"
call t_active '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"wp_delete_theme","arguments":{"stylesheet":"twentytwentyfive"}}}'
check "deleting the active theme refused" "$(verdict t_active)" "error"

echo "-- self-protection --"
call self_off '{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"wp_deactivate_plugin","arguments":{"plugin":"guarded-mcp"}}}'
check "cannot deactivate itself" "$(verdict self_off)" "error"
call self_del '{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"wp_delete_plugin","arguments":{"plugin":"guarded-mcp"}}}'
check "cannot delete itself" "$(verdict self_del)" "error"

echo "-- install source restriction --"
call url_inst '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"wp_install_plugin","arguments":{"url":"https://example.invalid/x.zip"}}}'
check "arbitrary ZIP URL refused" "$(verdict url_inst)" "error"

echo "-- two-step confirmation (needs the network: installs from wordpress.org) --"
call inst '{"jsonrpc":"2.0","id":16,"method":"tools/call","params":{"name":"wp_install_plugin","arguments":{"slug":"classic-editor","activate":true}}}'
check "install from wordpress.org" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d.get("activated"))' inst)" "True"
call deact '{"jsonrpc":"2.0","id":17,"method":"tools/call","params":{"name":"wp_deactivate_plugin","arguments":{"plugin":"classic-editor"}}}'
check "deactivate" "$(verdict deact)" "ok"

call mint '{"jsonrpc":"2.0","id":18,"method":"tools/call","params":{"name":"wp_delete_plugin","arguments":{"plugin":"classic-editor"}}}'
check "first delete call changes nothing" "$(verdict mint)" "error"
check "plugin still installed after step one" \
  "$(docker compose exec -T cli wp plugin list --format=csv 2>/dev/null | grep -c '^classic-editor,')" "1"
# Echoing the target's own name must NOT work: that is the design this replaced.
call guess '{"jsonrpc":"2.0","id":19,"method":"tools/call","params":{"name":"wp_delete_plugin","arguments":{"plugin":"classic-editor","confirm":"classic-editor/classic-editor.php"}}}'
check "guessing the confirmation refused" "$(verdict guess)" "error"
# Refusals must be readable tool results, not transport errors the client may swallow.
check "the refusal is a tool result, not a protocol error" \
  "$(py 'import json,sys;print("error" not in json.load(sys.stdin))' guess)" "True"

TOKEN=$(python3 "$(dirname "$0")/extract_token.py" < "$OUT/guess")
call real "{\"jsonrpc\":\"2.0\",\"id\":20,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_delete_plugin\",\"arguments\":{\"plugin\":\"classic-editor\",\"confirm\":\"$TOKEN\"}}}"
check "minted token completes the delete" "$(verdict real)" "ok"
check "plugin gone" \
  "$(docker compose exec -T cli wp plugin list --format=csv 2>/dev/null | grep -c '^classic-editor,')" "0"
call replay "{\"jsonrpc\":\"2.0\",\"id\":21,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_delete_plugin\",\"arguments\":{\"plugin\":\"akismet\",\"confirm\":\"$TOKEN\"}}}"
check "token cannot be replayed on another target" "$(verdict replay)" "error"
check "akismet untouched" \
  "$(docker compose exec -T cli wp plugin list --format=csv 2>/dev/null | grep -c '^akismet,')" "1"

echo "-- settings allowlist --"
call s_read '{"jsonrpc":"2.0","id":30,"method":"tools/call","params":{"name":"wp_get_settings","arguments":{"group":"general"}}}'
check "settings readable" "$(verdict s_read)" "ok"
call s_write '{"jsonrpc":"2.0","id":31,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"blogdescription":"smoke","posts_per_page":25}}}}'
check "allowed settings written" \
  "$(docker compose exec -T cli wp option get blogdescription 2>/dev/null | tr -d '\r\n')" "smoke"
# siteurl and home would make the site and this endpoint unreachable with no undo.
call s_url '{"jsonrpc":"2.0","id":32,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"siteurl":"http://evil.invalid"}}}}'
check "siteurl unchanged" \
  "$(docker compose exec -T cli wp option get siteurl 2>/dev/null | tr -d '\r\n')" "$BASE"
# Writing admin_email directly would silently repoint password recovery.
call s_mail '{"jsonrpc":"2.0","id":33,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"admin_email":"attacker@evil.invalid"}}}}'
check "admin_email unchanged" \
  "$(docker compose exec -T cli wp option get admin_email 2>/dev/null | tr -d '\r\n')" "a@b.test"
# The supported route runs WordPress's own confirmation flow, which needs the wp-admin
# handler called explicitly because REST never loads the hook that fires it. It is also
# a mailer aimed at an arbitrary address, so it takes a token and then a cooldown.
call s_new '{"jsonrpc":"2.0","id":34,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"new_admin_email":"pending@example.test"}}}}'
check "nothing is mailed before confirming" \
  "$(docker compose exec -T cli wp option get adminhash --format=json 2>/dev/null | grep -c 'pending@example.test' || true)" "0"
MAILTOK=$(python3 "$(dirname "$0")/extract_token.py" not_changed.new_admin_email < "$OUT/s_new")
call s_new2 "{\"jsonrpc\":\"2.0\",\"id\":39,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_settings\",\"arguments\":{\"settings\":{\"new_admin_email\":\"pending@example.test\"},\"confirm\":\"$MAILTOK\"}}}"
check "confirming makes the change pending" \
  "$(docker compose exec -T cli wp option get adminhash --format=json 2>/dev/null | grep -c 'pending@example.test')" "1"
check "admin_email still not changed" \
  "$(docker compose exec -T cli wp option get admin_email 2>/dev/null | tr -d '\r\n')" "a@b.test"
# A second address immediately after must be refused, or this is a spray tool.
call s_new3 '{"jsonrpc":"2.0","id":40,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"new_admin_email":"other@example.test"},"confirm":"x"}}}'
check "a second address is rate limited" \
  "$(py 'import json,sys;t=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("last 15 minutes" in t["not_changed"]["new_admin_email"] or "confirm set to" in t["not_changed"]["new_admin_email"])' s_new3)" "True"
# Open registration plus a privileged default role is the escalation pair.
call s_role '{"jsonrpc":"2.0","id":35,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"default_role":"administrator"}}}}'
check "escalating default_role refused" \
  "$(docker compose exec -T cli wp option get default_role 2>/dev/null | tr -d '\r\n')" "subscriber"
# The guard originally tested edit_posts, which is the wrong capability. A role can hold
# manage_options without it, and such a role as the registration default turns the public
# form into an administrator factory. Reproduced before the fix.
call s_role2 '{"jsonrpc":"2.0","id":36,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"users_can_register":1,"default_role":"api_admin"}}}}'
check "privileged role without edit_posts refused" \
  "$(docker compose exec -T cli wp option get default_role 2>/dev/null | tr -d '\r\n')" "subscriber"
call s_role3 '{"jsonrpc":"2.0","id":37,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"default_role":"subscriber"}}}}'
check "an ordinary default role is still allowed" "$(verdict s_role3)" "ok"

# The same policy, asked through the other door. wp_update_settings enforced all of this
# and wp_update_option enforced none of it, so every refusal above was reachable by
# naming the same option through the generic writer: siteurl and home, which the settings
# tool refuses because a wrong value leaves no way back; admin_email, which has a
# confirmation flow precisely so one call cannot repoint password recovery; and
# default_role, checked by construction in one tool and not at all in the other.
#
# Each check asserts the STORED value, not the response. A refusal message is easy to
# emit and easy to emit while still writing.
echo "-- the generic option writer obeys the same policy --"
# Read, call, read back, PUT IT BACK, and only then compare. The obvious order gets this
# wrong in a way that matters: these calls succeed when the policy is missing, so an
# assertion made before the repair leaves siteurl pointing at evil.test. The site then
# 301s, and the twelve unrelated checks after this one fail describing a catastrophe
# rather than the one missing guard. A test for a guard must not depend on the guard.
probe_refused() { # probe_refused <label> <file> <id> <option> <hostile value>
  local was; was=$(docker compose exec -T cli wp option get "$4" 2>/dev/null | tr -d '\r\n')
  call "$2" "{\"jsonrpc\":\"2.0\",\"id\":$3,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_option\",\"arguments\":{\"key\":\"$4\",\"value\":\"$5\"}}}"
  local now; now=$(docker compose exec -T cli wp option get "$4" 2>/dev/null | tr -d '\r\n')
  docker compose exec -T cli wp option update "$4" "$was" >/dev/null 2>&1
  check "$1" "$now" "$was"
}

probe_refused "wp_update_option cannot rewrite siteurl" o_siteurl 41 siteurl http://evil.test
probe_refused "nor home" o_home 42 home http://evil.test
probe_refused "nor repoint the administration email past its confirmation" o_email 43 admin_email evil@evil.test
call o_role '{"jsonrpc":"2.0","id":44,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"default_role","value":"administrator"}}}'
ROLE_NOW=$(docker compose exec -T cli wp option get default_role 2>/dev/null | tr -d '\r\n')
docker compose exec -T cli wp option update default_role subscriber >/dev/null 2>&1
check "nor set a privileged registration default" "$ROLE_NOW" "subscriber"
check "and it says why rather than failing silently" \
  "$(py 'import json,sys;print("to anyone who registers" in json.load(sys.stdin)["error"]["message"])' o_role)" "True"

# Control. Without it, a policy that refused every write would pass all five checks above.
call o_ok '{"jsonrpc":"2.0","id":45,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"blogdescription","value":"policy control tagline"}}}'
check "control: an ordinary option write still goes through" \
  "$(docker compose exec -T cli wp option get blogdescription 2>/dev/null | tr -d '\r\n')" "policy control tagline"

# And the third door. Undo replays a write, so it has to ask the same question: a site
# whose default_role was already privileged would otherwise have that value restorable
# by reverting the change that closed it.
docker compose exec -T cli wp option update default_role editor >/dev/null 2>&1
call o_close '{"jsonrpc":"2.0","id":46,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"default_role":"subscriber"}}}}'
check "closing a privileged default role is allowed" \
  "$(docker compose exec -T cli wp option get default_role 2>/dev/null | tr -d '\r\n')" "subscriber"
call o_jlist '{"jsonrpc":"2.0","id":47,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{}}}'
O_JID=$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(next((x['id'] for x in e if 'default_role' in x['what']), ''))" o_jlist)
check "control: the closing change was journalled" "$([ -n "$O_JID" ] && echo yes || echo no)" "yes"
call o_undo "{\"jsonrpc\":\"2.0\",\"id\":48,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$O_JID\"}}}"
UNDO_NOW=$(docker compose exec -T cli wp option get default_role 2>/dev/null | tr -d '\r\n')
docker compose exec -T cli wp option update default_role subscriber >/dev/null 2>&1
check "undo cannot restore the privileged default role" "$UNDO_NOW" "subscriber"

# The admin-email flow mails an arbitrary address from this domain, with body text
# drawn from blogname, which this same tool can rewrite. It needs a token and a cooldown.


echo "-- permalinks --"
call p_read '{"jsonrpc":"2.0","id":36,"method":"tools/call","params":{"name":"wp_get_permalink_structure","arguments":{}}}'
check "permalink structure readable" "$(verdict p_read)" "ok"
# A date-only structure gives two posts published the same day the same URL.
call p_bad '{"jsonrpc":"2.0","id":37,"method":"tools/call","params":{"name":"wp_set_permalink_structure","arguments":{"structure":"/%year%/%monthnum%/"}}}'
check "non-unique structure refused" "$(verdict p_bad)" "error"
call p_ok '{"jsonrpc":"2.0","id":38,"method":"tools/call","params":{"name":"wp_set_permalink_structure","arguments":{"structure":"/%postname%/"}}}'
check "valid structure accepted" "$(verdict p_ok)" "ok"
check "site still serving after the flush" \
  "$(curl -sS -o /dev/null -w '%{http_code}' $BASE/)" "200"

echo "-- site health --"
call h '{"jsonrpc":"2.0","id":39,"method":"tools/call","params":{"name":"wp_get_site_health","arguments":{}}}'
check "site health runs its direct tests" \
  "$(py 'import json,sys;t=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(sum(t["summary"].values())>10)' h)" "True"
check "site health reports the environment" \
  "$(py 'import json,sys;t=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(all(k in t["environment"] for k in ("wordpress","php","active_theme")))' h)" "True"

echo "-- menus --"
call m_new '{"jsonrpc":"2.0","id":50,"method":"tools/call","params":{"name":"wp_create_menu","arguments":{"name":"Smoke Menu"}}}'
check "menu created" "$(verdict m_new)" "ok"
call m_dupe '{"jsonrpc":"2.0","id":51,"method":"tools/call","params":{"name":"wp_create_menu","arguments":{"name":"Smoke Menu"}}}'
check "duplicate menu name refused" "$(verdict m_dupe)" "error"
call m_item '{"jsonrpc":"2.0","id":52,"method":"tools/call","params":{"name":"wp_add_menu_item","arguments":{"menu":"Smoke Menu","title":"Home","type":"custom","url":"$BASE/"}}}'
check "custom item added" "$(verdict m_item)" "ok"
call m_bad '{"jsonrpc":"2.0","id":53,"method":"tools/call","params":{"name":"wp_add_menu_item","arguments":{"menu":"Smoke Menu","type":"post_type","object_id":999999}}}'
check "item for a missing post refused" "$(verdict m_bad)" "error"
# A menu item stores its own copy of the title, and core's front-end filter only drops
# missing or trashed targets. A draft target therefore puts its headline in the public
# navigation behind a link visitors cannot open.
DRAFT_ID=$(docker compose exec -T cli wp post create --post_title="Smoke Draft Title" --post_status=draft --porcelain 2>/dev/null | tr -d '\r\n')
call m_draft "{\"jsonrpc\":\"2.0\",\"id\":58,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_add_menu_item\",\"arguments\":{\"menu\":\"Smoke Menu\",\"type\":\"post_type\",\"object_id\":$DRAFT_ID}}}"
check "draft target refused for a menu item" "$(verdict m_draft)" "error"
docker compose exec -T cli wp post delete "$DRAFT_ID" --force >/dev/null 2>&1
# menu-item-status defaults to draft, which renders nothing and looks like a no-op.
call m_list '{"jsonrpc":"2.0","id":54,"method":"tools/call","params":{"name":"wp_get_menu_items","arguments":{"menu":"Smoke Menu"}}}'
check "item is published, not draft" \
  "$(py 'import json,sys;t=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(len(t["items"])==1)' m_list)" "True"
call m_loc '{"jsonrpc":"2.0","id":55,"method":"tools/call","params":{"name":"wp_assign_menu_location","arguments":{"location":"nope","menu":"Smoke Menu"}}}'
check "unknown menu location refused" "$(verdict m_loc)" "error"
call m_del '{"jsonrpc":"2.0","id":56,"method":"tools/call","params":{"name":"wp_delete_menu","arguments":{"menu":"Smoke Menu"}}}'
check "deleting a menu needs confirming" "$(verdict m_del)" "error"

echo "-- widgets on a block theme --"
check "block theme is active" \
  "$(docker compose exec -T cli wp theme list --status=active --field=name 2>/dev/null | tr -d '\r\n')" "twentytwentyfive"
# A block theme still has sidebars registered: _wp_block_theme_register_classic_sidebars()
# rebuilds the previous classic theme's areas so widgets survive a switch. They accept
# writes and display nothing, so listing must keep working (to clean them up) while
# reporting that nothing renders them.
call w_none '{"jsonrpc":"2.0","id":57,"method":"tools/call","params":{"name":"wp_list_sidebars","arguments":{}}}'
check "listing says widgets do not render here" \
  "$(py 'import json,sys;t=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(t["widgets_render"] is False and "block theme" in t.get("note",""))' w_none)" "True"
# Writing sidebars_widgets on a block theme stores data nothing ever reads.
call w_ref '{"jsonrpc":"2.0","id":58,"method":"tools/call","params":{"name":"wp_add_widget","arguments":{"sidebar":"sidebar-1","content":"<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->"}}}'
check "adding a widget refused, not silently stored" "$(verdict w_ref)" "error"

echo "-- widgets on a classic theme --"
call w_theme '{"jsonrpc":"2.0","id":59,"method":"tools/call","params":{"name":"wp_install_theme","arguments":{"slug":"twentytwentyone","activate":true}}}'
check "classic theme installed and active" "$(verdict w_theme)" "ok"
call w_block '{"jsonrpc":"2.0","id":60,"method":"tools/call","params":{"name":"wp_add_widget","arguments":{"sidebar":"sidebar-1","content":"<!-- wp:paragraph --><p>smoke-block-widget</p><!-- /wp:paragraph -->"}}}'
check "block widget added" "$(verdict w_block)" "ok"
call w_classic '{"jsonrpc":"2.0","id":61,"method":"tools/call","params":{"name":"wp_add_widget","arguments":{"sidebar":"sidebar-1","id_base":"text","settings":{"title":"Smoke","text":"smoke-classic-widget"}}}}'
check "classic widget added" "$(verdict w_classic)" "ok"
# A widget lives in an option and a widget area in another one, so the audit log would
# otherwise record adding a widget as two anonymous setting writes. It names them.
check "a widget write is recorded as a widget, not as a setting" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;$c=(string)$wpdb->get_var("SELECT changes FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"wp_add_widget\" ORDER BY id DESC LIMIT 1");echo strpos($c,"\"what\":\"widget ")!==false && strpos($c,"\"what\":\"widget area ")!==false ? "labelled" : "NOT: ".mb_substr($c,0,60);' 2>/dev/null | tr -d '\r\n')" "labelled"
call w_bad '{"jsonrpc":"2.0","id":62,"method":"tools/call","params":{"name":"wp_add_widget","arguments":{"sidebar":"not-a-sidebar","content":"x"}}}'
check "unknown widget area refused" "$(verdict w_bad)" "error"
# Losing _multiwidget makes core read the row as pre-2.8 format and mangle it.
check "_multiwidget preserved" \
  "$(docker compose exec -T cli wp eval 'echo (int) (get_option("widget_block")["_multiwidget"] ?? 0);' 2>/dev/null | tr -d '\r\n')" "1"
check "widgets actually render on the page" \
  "$(curl -sS $BASE/ | grep -c 'smoke-block-widget')" "1"
# Stored XSS. Both paths matter and they fail differently.
# Block widgets: WP_Widget_Block::widget() echoes the content through
# widget_block_content, whose core filters do not escape.
# Classic widgets: WP_Widget_Text::update() only sanitizes when the caller lacks
# unfiltered_html, and every caller here is an administrator, so that branch was never
# taken. Routing through update() is not enough on its own; the capability has to be
# dropped for the write.
call w_xss1 '{"jsonrpc":"2.0","id":65,"method":"tools/call","params":{"name":"wp_add_widget","arguments":{"sidebar":"sidebar-1","content":"<!-- wp:html --><script>alert(\"xssA\")</script><p>legitA</p><!-- /wp:html -->"}}}'
call w_xss2 '{"jsonrpc":"2.0","id":66,"method":"tools/call","params":{"name":"wp_add_widget","arguments":{"sidebar":"sidebar-1","id_base":"text","settings":{"title":"T","text":"<script>alert(\"xssB\")</script>legitB"}}}}'
curl -sS $BASE/ -o "$OUT/page.html"
check "block widget script is not executable" \
  "$(python3 "$(dirname "$0")/check_xss.py" xssA < "$OUT/page.html")" "safe"
check "classic widget script is not executable" \
  "$(python3 "$(dirname "$0")/check_xss.py" xssB < "$OUT/page.html")" "safe"
check "legitimate widget markup survives" \
  "$(grep -c 'legitA' "$OUT/page.html")" "1"
call w_unreg '{"jsonrpc":"2.0","id":67,"method":"tools/call","params":{"name":"wp_add_widget","arguments":{"sidebar":"sidebar-1","id_base":"not_a_widget","settings":{}}}}'
check "unregistered widget type refused" "$(verdict w_unreg)" "error"

call w_del '{"jsonrpc":"2.0","id":63,"method":"tools/call","params":{"name":"wp_delete_widget","arguments":{"widget_id":"text-1"}}}'
check "widget removed" "$(verdict w_del)" "ok"
check "its settings removed too" \
  "$(docker compose exec -T cli wp eval 'echo isset(get_option("widget_text")[1]) ? "still-there" : "gone";' 2>/dev/null | tr -d '\r\n')" "gone"

# Put the site back so a re-run starts from the same place.
call w_restore '{"jsonrpc":"2.0","id":64,"method":"tools/call","params":{"name":"wp_activate_theme","arguments":{"stylesheet":"twentytwentyfive"}}}'
check "restored the block theme" "$(verdict w_restore)" "ok"


echo "-- post content sanitising --"
# The guard here used to be current_user_can('unfiltered_html'), which is always true
# for any caller that reaches these tools, so the sanitiser was unreachable and a script
# tag written through wp_create_post executed on the public page. These tools sit at the
# "write" level, so even a deliberately limited token could do it.
call xa '{"jsonrpc":"2.0","id":90,"method":"tools/call","params":{"name":"wp_create_post","arguments":{"post_title":"Probe A","post_content":"<p>legit-a</p><script>alert(\"xss-a\")</script>","post_status":"publish","post_name":"probe-a"}}}'
curl -sS "$BASE/probe-a/" -o "$OUT/pa.html"
check "script in post content is not executable" \
  "$(python3 "$(dirname "$0")/check_xss.py" xss-a < "$OUT/pa.html")" "safe"
check "legitimate body survives" "$(grep -c 'legit-a' "$OUT/pa.html")" "1"

# Content with no recognised HTML took the markdown branch, and Parsedown runs without
# safe mode, so a bare script tag bypassed sanitising entirely.
call xb '{"jsonrpc":"2.0","id":91,"method":"tools/call","params":{"name":"wp_create_post","arguments":{"post_title":"Probe B","post_content":"<script>alert(\"xss-b\")</script>","post_status":"publish","post_name":"probe-b"}}}'
curl -sS "$BASE/probe-b/" -o "$OUT/pb.html"
check "the markdown path is sanitised too" \
  "$(python3 "$(dirname "$0")/check_xss.py" xss-b < "$OUT/pb.html")" "safe"

# wp_kses_post cannot be pointed at block markup wholesale: it does not recognise a
# delimiter comment containing HTML entities and escapes the opener, destroying the
# block. Attributes must round-trip as JSON instead.
call xc '{"jsonrpc":"2.0","id":92,"method":"tools/call","params":{"name":"wp_create_post","arguments":{"post_title":"Probe C","post_content":"<!-- wp:paragraph --><p>legit-c</p><script>alert(\"xss-c\")</script><!-- /wp:paragraph -->","post_status":"publish","post_name":"probe-c"}}}'
curl -sS "$BASE/probe-c/" -o "$OUT/pc.html"
check "script inside a block is stripped" \
  "$(python3 "$(dirname "$0")/check_xss.py" xss-c < "$OUT/pc.html")" "safe"
call xd '{"jsonrpc":"2.0","id":93,"method":"tools/call","params":{"name":"wp_create_post","arguments":{"post_title":"Probe D","post_content":"<!-- wp:faq {\"q\":\"&lt;p&gt;hi&lt;/p&gt;\"} --><div>legit-d</div><!-- /wp:faq -->","post_status":"publish","post_name":"probe-d"}}}'
check "block attributes survive sanitising" \
  "$(docker compose exec -T cli wp eval '$p=get_page_by_path("probe-d",OBJECT,"post"); $b=parse_blocks($p->post_content)[0]; echo ($b["blockName"]==="core/faq" && ($b["attrs"]["q"]??"")==="&lt;p&gt;hi&lt;/p&gt;") ? "ok" : "lost";' 2>/dev/null | tr -d '\r\n')" "ok"
docker compose exec -T cli wp eval 'foreach(["probe-a","probe-b","probe-c","probe-d"] as $s){ $p=get_page_by_path($s,OBJECT,"post"); if($p) wp_delete_post($p->ID,true); }' >/dev/null 2>&1

echo "-- what gets recorded, and against what --"
docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1
call act1 '{"jsonrpc":"2.0","id":70,"method":"tools/call","params":{"name":"wp_create_post","arguments":{"post_title":"Activity Probe","post_status":"draft"}}}'
# A refusal must be recorded too: those are the entries worth having.
call act2 '{"jsonrpc":"2.0","id":71,"method":"tools/call","params":{"name":"wp_deactivate_plugin","arguments":{"plugin":"guarded-mcp"}}}'
audit_q() { docker compose exec -T cli wp eval "global \$wpdb; echo (string) \$wpdb->get_var(\"$1\");" 2>/dev/null | tr -d '\r\n'; }
check "successful call recorded" \
  "$(audit_q 'SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"wp_create_post\" AND outcome=\"ok\"')" "1"
check "refused call recorded as refused" \
  "$(audit_q 'SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"wp_deactivate_plugin\" AND outcome=\"refused\"')" "1"
check "the target is captured, not just the tool" \
  "$(audit_q 'SELECT target FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"wp_deactivate_plugin\" LIMIT 1')" "guarded-mcp"
# Arguments ARE stored now, which is the point of the change, so assert it rather than
# leaving the old check to pass because the option it queried no longer exists.
check "arguments are stored, redacted" \
  "$(audit_q 'SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"wp_create_post\" AND args LIKE \"%Activity Probe%\"')" "1"
# A table, so a burst of calls cannot lose an entry to a read-modify-write, which the
# option row it replaced could.
check "the record is a table, not an option row" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;$t=$wpdb->prefix."gmcp_audit";echo $wpdb->get_var("SHOW TABLES LIKE \"$t\"")===$t?"table":"NO";' 2>/dev/null | tr -d '\r\n')" "table"
check "and the old option is gone" \
  "$(docker compose exec -T cli wp eval 'echo get_option("gmcp_activity",null)===null?"gone":"STILL THERE";' 2>/dev/null | tr -d '\r\n')" "gone"
docker compose exec -T cli wp plugin deactivate guarded-mcp >/dev/null 2>&1
docker compose exec -T cli wp plugin activate guarded-mcp >/dev/null 2>&1
check "and stays gone across a reactivation" \
  "$(docker compose exec -T cli wp eval 'echo get_option("gmcp_activity",null)===null?"gone":"RESURRECTED";' 2>/dev/null | tr -d '\r\n')" "gone"

echo "-- site briefing --"
# One call has to answer "what am I looking at", or an agent spends five round trips
# on orientation at the start of every conversation and pays for all of them in context.
call brief '{"jsonrpc":"2.0","id":90,"method":"tools/call","params":{"name":"wp_site_briefing","arguments":{}}}'
check "the briefing answers" "$(verdict brief)" "ok"
brief() { py "import json,sys;d=json.load(sys.stdin);b=json.loads(d['result']['content'][0]['text']);print($1)" brief; }
check "it reports the real WordPress version" \
  "$(brief "b['versions']['wordpress']")" \
  "$(docker compose exec -T cli wp core version 2>/dev/null | tr -d '\r\n')"
check "it names the active theme" "$(brief "b['theme']['stylesheet']")" "twentytwentyfive"
check "it lists this plugin as active" "$(brief "'yes' if any(p.startswith('Guarded MCP') for p in b['plugins']['active']) else 'no'")" "yes"
check "it counts published posts" \
  "$(brief "b['content']['post_types'][0]['published']")" \
  "$(docker compose exec -T cli wp post list --post_type=post --post_status=publish --format=count 2>/dev/null | tr -d '\r\n')"
# The rule, not a fixed list: a taxonomy is reported only if it attaches to a post type
# that was also reported. Naming category and post_tag instead would fail the moment a
# plugin adds a legitimate one of its own, which WooCommerce does.
check "every taxonomy listed attaches to a listed post type" \
  "$(brief "'yes' if all(set(t['applies_to']) & set(p['type'] for p in b['content']['post_types']) for t in b['content']['taxonomies']) else 'no'")" "yes"
check "and the machinery ones are still gone" \
  "$(brief "'yes' if not set(['link_category','wp_pattern_category']) & set(t['taxonomy'] for t in b['content']['taxonomies']) else 'no'")" "yes"
# Reporting zero pending updates when nothing ever checked would tell someone their
# site is current while it rots. The absence of a check has to be visible.
check "update figures say whether a check happened" "$(brief "b['updates'].get('checked')")" "True"
# The briefing walks options and transients. None of that may carry the token out.
check "the briefing does not leak the token" \
  "$(grep -c "$TOK" "$OUT/brief" || true)" "0"

echo "-- change journal --"
# The activity log answers "what did my agent do". This answers the question you ask in
# a hurry: put it back. Every check here is about it being trustworthy enough to use
# under pressure.
docker compose exec -T cli wp option update blogdescription "before the agent" >/dev/null 2>&1
call j_opt '{"jsonrpc":"2.0","id":100,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"blogdescription","value":"after the agent"}}}'
check "the option write went through" \
  "$(docker compose exec -T cli wp option get blogdescription 2>/dev/null | tr -d '\r\n')" "after the agent"
call j_list '{"jsonrpc":"2.0","id":101,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{}}}'
J_ID=$(py "import json,sys;d=json.load(sys.stdin);print(json.loads(d['result']['content'][0]['text'])[0]['id'])" j_list)
check "the change is on record" "$(test -n "$J_ID" && echo yes || echo no)" "yes"
call j_undo "{\"jsonrpc\":\"2.0\",\"id\":102,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$J_ID\"}}}"
# Assert the stored value, not the reported success. The first version of this reported
# a successful restore while leaving the post body exactly as the agent had left it.
check "reverting really restores the value" \
  "$(docker compose exec -T cli wp option get blogdescription 2>/dev/null | tr -d '\r\n')" "before the agent"
call j_replay "{\"jsonrpc\":\"2.0\",\"id\":103,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$J_ID\"}}}"
check "a revert cannot be applied twice" "$(verdict j_replay)" "error"
call j_unknown '{"jsonrpc":"2.0","id":104,"method":"tools/call","params":{"name":"wp_undo_change","arguments":{"id":"nosuchchange"}}}'
check "an unknown change id is refused" "$(verdict j_unknown)" "error"

# Posts: title, body and status all have to come back. Leaning on post revisions looks
# like the shortcut and is wrong, because by the time post_updated fires the newest
# revision holds the NEW body. That version restored the title and silently left the
# body rewritten.
call j_new '{"jsonrpc":"2.0","id":105,"method":"tools/call","params":{"name":"wp_create_post","arguments":{"post_title":"Journal subject","post_content":"<p>Original body.</p>","post_status":"publish"}}}'
J_POST=$(py "import json,sys,re;d=json.load(sys.stdin);print(re.search(r'\d+',d['result']['content'][0]['text']).group())" j_new)
call j_edit "{\"jsonrpc\":\"2.0\",\"id\":106,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_post\",\"arguments\":{\"ID\":$J_POST,\"post_title\":\"Rewritten\",\"post_content\":\"<p>Replaced.</p>\",\"post_status\":\"draft\"}}}"
call j_list2 '{"jsonrpc":"2.0","id":107,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{"limit":1}}}'
J_ID2=$(py "import json,sys;d=json.load(sys.stdin);print(json.loads(d['result']['content'][0]['text'])[0]['id'])" j_list2)
call j_undo2 "{\"jsonrpc\":\"2.0\",\"id\":108,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$J_ID2\"}}}"
check "reverting restores the post title" \
  "$(docker compose exec -T cli wp post get "$J_POST" --field=post_title 2>/dev/null | tr -d '\r\n')" "Journal subject"
check "reverting restores the post body" \
  "$(docker compose exec -T cli wp post get "$J_POST" --field=post_content 2>/dev/null | tr -d '\r\n')" "<p>Original body.</p>"
check "reverting restores the post status" \
  "$(docker compose exec -T cli wp post get "$J_POST" --field=post_status 2>/dev/null | tr -d '\r\n')" "publish"
docker compose exec -T cli wp post delete "$J_POST" --force >/dev/null 2>&1

# Only the agent's writes. A person saving a settings page is not the agent's to undo,
# and listing it would make the history untrustworthy at exactly the wrong moment.
docker compose exec -T cli wp option update blogname "Edited by a person" >/dev/null 2>&1
call j_list3 '{"jsonrpc":"2.0","id":109,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{}}}'
check "a person's own edit is not recorded" "$(grep -c blogname "$OUT/j_list3" || true)" "0"
docker compose exec -T cli wp option update blogname "MCP Test" >/dev/null 2>&1

# The journal writes previous values into an option row. Anything credential-shaped that
# reaches it is a second copy of a secret, sitting somewhere nothing expects one.
check "credential-shaped keys are never journalled" \
  "$(docker compose exec -T cli wp eval '$r=new ReflectionClass("GMCP_Journal");$m=$r->getMethod("skip_option");$m->setAccessible(true);$j=$r->newInstanceWithoutConstructor();$bad=0;foreach(["my_api_key","some_secret","gmcp_options","_transient_x","rewrite_rules","active_plugins"] as $k){if(!$m->invoke($j,$k))$bad++;}echo $bad;' 2>/dev/null | tr -d '\r\n')" "0"
check "the journal row does not contain the token" \
  "$(docker compose exec -T cli wp eval 'echo strpos(maybe_serialize(get_option("gmcp_journal",[])),"'"$TOK"'")===false?0:1;' 2>/dev/null | tr -d '\r\n')" "0"

echo "-- named keys: reach and lifetime --"
# One shared secret with one access level is fine until there are two of anything.
# These check that a key's stated limits are real, not decoration.
KEYS=$(docker compose exec -T cli wp eval '
  $a = GMCP_Tokens::create("Scoped reader","readonly",0,["wp_get_posts"]);
  $b = GMCP_Tokens::create("Already expired","admin",0,[]);
  $rows = GMCP_Tokens::all(); $rows[$b["id"]]["expires"] = time() - 60; update_option("gmcp_tokens",$rows,false);
  $c = GMCP_Tokens::create("Admin but scoped","admin",0,["wp_get_posts"]);
  echo $a["secret"], " ", $b["secret"], " ", $c["secret"];
' 2>/dev/null | tr -d '\r\n')
K_SCOPED=$(echo "$KEYS" | cut -d' ' -f1)
K_EXPIRED=$(echo "$KEYS" | cut -d' ' -f2)
K_ADMIN_SCOPED=$(echo "$KEYS" | cut -d' ' -f3)

kcall() { # kcall <file> <key> <json>
  curl -sS -X POST "$URL" -H "Authorization: Bearer $2" \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -d "$3" -o "$OUT/$1"
}

kcall k_list "$K_SCOPED" '{"jsonrpc":"2.0","id":120,"method":"tools/list"}'
# Hiding the tools is not the boundary, but a model offered a menu of things that will
# be refused wastes a turn on each of them.
check "a scoped key is offered only its own tools" \
  "$(py "import json,sys;d=json.load(sys.stdin);print(','.join(sorted(t['name'] for t in d['result']['tools'])))" k_list)" \
  "mcp_ping,wp_get_posts"
kcall k_allowed "$K_SCOPED" '{"jsonrpc":"2.0","id":121,"method":"tools/call","params":{"name":"wp_get_posts","arguments":{"limit":1}}}'
check "a scoped key can call the tool it is scoped to" "$(verdict k_allowed)" "ok"
# The real boundary. An admin-level key is past the access-level filter entirely, so if
# the scope check sat behind that filter it would not run at all for this caller.
kcall k_denied "$K_ADMIN_SCOPED" '{"jsonrpc":"2.0","id":122,"method":"tools/call","params":{"name":"wp_delete_theme","arguments":{"stylesheet":"twentytwentyfour"}}}'
check "an admin-level key is still held to its tool list" "$(verdict k_denied)" "error"
check "the theme was not deleted" \
  "$(docker compose exec -T cli wp theme is-installed twentytwentyfour >/dev/null 2>&1 && echo yes || echo no)" "yes"
# mcp_ping is what every tool description tells a model to call when something fails.
kcall k_ping "$K_SCOPED" '{"jsonrpc":"2.0","id":123,"method":"tools/call","params":{"name":"mcp_ping","arguments":{}}}'
check "ping works whatever the key is scoped to" "$(verdict k_ping)" "ok"
check "an expired key is refused at the door" \
  "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$URL" -H "Authorization: Bearer $K_EXPIRED" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" "401"
check "a made-up key is refused" \
  "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$URL" -H 'Authorization: Bearer gmcp_00000000_deadbeef' -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" "401"
# A key readable back out of the database is a key that leaks with the database.
check "keys are stored hashed, never in the clear" \
  "$(docker compose exec -T cli wp eval 'echo strpos(maybe_serialize(get_option("gmcp_tokens",[])),"'"$K_SCOPED"'")===false?0:1;' 2>/dev/null | tr -d '\r\n')" "0"
# Revokes everything except the suite's own key and counts what is left, rather than
# revoking the lot and expecting zero. The old form was correct while the suite
# authenticated with a shared token kept somewhere else; now its credential is a key, and
# "revoke everything, expect none" revoked the suite mid-run. What followed was 167
# failures describing features as broken, when one credential had been deleted.
check "revoking a key locks it out immediately" \
  "$(docker compose exec -T cli wp eval '
      foreach ( GMCP_Tokens::all() as $k ) {
        if ( ( $k["label"] ?? "" ) !== "smoke suite" ) { GMCP_Tokens::revoke( $k["id"] ); }
      }
      $left = array_filter( GMCP_Tokens::all(), fn( $k ) => ( $k["label"] ?? "" ) !== "smoke suite" );
      echo count( $left );' 2>/dev/null | tr -d '\r\n')" "0"
check "the revoked key no longer authenticates" \
  "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$URL" -H "Authorization: Bearer $K_SCOPED" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" "401"

echo "-- resources are no softer than the tools --"
# A resource is a second way to reach the same data. If it does not go through the same
# gate it is a second door into the same room with a different lock on it.
K_RES=$(docker compose exec -T cli wp eval '$a=GMCP_Tokens::create("Comments only","readonly",0,["wp_get_comments"]);echo $a["secret"];' 2>/dev/null | tr -d '\r\n')
kcall r_list "$K_RES" '{"jsonrpc":"2.0","id":130,"method":"resources/list"}'
check "a scoped key is offered only the resources it could already read" \
  "$(py "import json,sys;d=json.load(sys.stdin);print(','.join(r['uri'] for r in d['result']['resources']))" r_list)" \
  "gmcp://comments/pending"
kcall r_post "$K_RES" '{"jsonrpc":"2.0","id":131,"method":"resources/read","params":{"uri":"gmcp://post/1"}}'
check "and cannot read a post through one" "$(py 'import json,sys;print("error" in json.load(sys.stdin))' r_post)" "True"
kcall r_brief "$K_RES" '{"jsonrpc":"2.0","id":132,"method":"resources/read","params":{"uri":"gmcp://site/briefing"}}'
check "nor the site briefing" "$(py 'import json,sys;print("error" in json.load(sys.stdin))' r_brief)" "True"
kcall r_tpl "$K_RES" '{"jsonrpc":"2.0","id":133,"method":"resources/templates/list"}'
check "nor is it offered the post template" \
  "$(py 'import json,sys;print(len(json.load(sys.stdin)["result"]["resourceTemplates"]))' r_tpl)" "0"
gmcp_clear_other_keys "smoke suite"

echo "-- undo is not a way round the access levels --"
# wp_undo_change sits at the write level; wp_update_option sits at admin. Undo replays the
# same write backwards, so without a check of its own it is an unscoped write primitive:
# a key refused wp_update_option outright reopened public registration by reverting the
# change that closed it. Every admin-level tightening became a handle usable at write
# level, and a tool scope list did not help, because "undo" is one name standing for
# every write on the journal.
docker compose exec -T cli wp option update users_can_register 1 >/dev/null 2>&1
docker compose exec -T cli wp option delete gmcp_journal >/dev/null 2>&1
K_UNDO=$(docker compose exec -T cli wp eval '$a=GMCP_Tokens::create("Deploy","readwrite",0,["wp_get_posts","wp_list_changes","wp_undo_change"]);echo $a["secret"];' 2>/dev/null | tr -d '\r\n')
call u_tighten '{"jsonrpc":"2.0","id":140,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"users_can_register","value":"0"}}}'
check "an admin connection can tighten registration" \
  "$(docker compose exec -T cli wp option get users_can_register 2>/dev/null | tr -d '\r\n')" "0"
kcall u_direct "$K_UNDO" '{"jsonrpc":"2.0","id":141,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"users_can_register","value":"1"}}}'
check "a write-level key cannot set that option directly" "$(verdict u_direct)" "error"
kcall u_list "$K_UNDO" '{"jsonrpc":"2.0","id":142,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{"limit":1}}}'
U_ID=$(py "import json,sys;d=json.load(sys.stdin);print(json.loads(d['result']['content'][0]['text'])[0]['id'])" u_list)
kcall u_revert "$K_UNDO" "{\"jsonrpc\":\"2.0\",\"id\":143,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$U_ID\"}}}"
check "nor reach it through undo" "$(verdict u_revert)" "error"
# Assert the stored option, not the refusal text. The refusal is what the tool says; this
# is what actually happened.
check "registration really is still closed" \
  "$(docker compose exec -T cli wp option get users_can_register 2>/dev/null | tr -d '\r\n')" "0"
check "the refusal names the tool that made the change" "$(refusal u_revert | grep -c wp_update_option)" "1"
# The gate must not break legitimate undo, which is the whole point of the feature.
call u_admin "{\"jsonrpc\":\"2.0\",\"id\":144,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$U_ID\"}}}"
check "an admin connection can still undo its own change" \
  "$(docker compose exec -T cli wp option get users_can_register 2>/dev/null | tr -d '\r\n')" "1"
gmcp_clear_other_keys "smoke suite"

echo "-- a credential in an innocuous option is not journalled --"
# option_guard matches on the option NAME. Most secrets do not live in the name:
# woocommerce_stripe_settings, wp_mail_smtp and jetpack_options are all innocuous names
# holding an array with a secret_key inside. Rotating one through the agent left the old
# live key sitting in a second row.
docker compose exec -T cli wp option delete gmcp_journal >/dev/null 2>&1
call c_set1 '{"jsonrpc":"2.0","id":145,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"acme_gateway_settings","value":{"mode":"live","secret_key":"sk_live_SUPERSECRET123"}}}}'
call c_set2 '{"jsonrpc":"2.0","id":146,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"acme_gateway_settings","value":{"mode":"test","secret_key":"sk_test_rotated"}}}}'
check "the rotated-out credential is not in the journal" \
  "$(docker compose exec -T cli wp eval 'echo strpos(maybe_serialize(get_option("gmcp_journal",[])),"sk_live_SUPERSECRET123")===false?0:1;' 2>/dev/null | tr -d '\r\n')" "0"
call c_list '{"jsonrpc":"2.0","id":147,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{"limit":1}}}'
# The secret is gone from the record, which is the assertion above and has not changed.
# What changed is everything around it: the value used to be dropped whole, so an option
# that merely CONTAINS a credential-shaped field lost its undo entirely. Now the shape is
# kept with those leaves blanked, and the entry says the restore will be partial rather
# than letting someone believe it is complete.
check "the change is still reversible, minus the credential" \
  "$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])[0].get("reversible"))' c_list)" "True"
check "and the entry says the restore will be partial" \
  "$(py 'import json,sys;print("looked like credentials were never recorded" in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])[0].get("partial_restore",""))' c_list)" "True"
C_JID=$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])[0]["id"])' c_list)
call c_undo "{\"jsonrpc\":\"2.0\",\"id\":150,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$C_JID\"}}}"
check "undo puts back the field that was recorded" \
  "$(docker compose exec -T cli wp eval 'echo get_option("acme_gateway_settings")["mode"];' 2>/dev/null | tr -d '\r\n')" "live"
# The one thing undo must never do. Writing the marker over a live credential is not a
# partial restore, it is destroying the secret the blanking existed to protect, which is
# worse than having had no undo at all.
check "and leaves the live credential alone rather than writing the marker over it" \
  "$(docker compose exec -T cli wp eval 'echo get_option("acme_gateway_settings")["secret_key"];' 2>/dev/null | tr -d '\r\n')" "sk_test_rotated"
# A value this cannot snapshot safely keeps the old all-or-nothing answer. An object is
# one: restoring it from an array copy would put back a different type than was there.
docker compose exec -T cli wp eval 'update_option("acme_object_settings", (object) [ "mode" => "live", "secret_key" => "sk_live_OBJECT" ]);' >/dev/null 2>&1
call c_obj '{"jsonrpc":"2.0","id":151,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"acme_object_settings","value":{"mode":"test"}}}}'
call c_objlist '{"jsonrpc":"2.0","id":152,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{"limit":1}}}'
check "a value that cannot be snapshotted is still refused outright" \
  "$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])[0].get("not_reversible_because",""))' c_objlist)" \
  "The previous value looked like it held a credential, so it was never stored."
check "and that secret is not in the journal either" \
  "$(docker compose exec -T cli wp eval 'echo strpos(maybe_serialize(get_option("gmcp_journal",[])),"sk_live_OBJECT")===false?0:1;' 2>/dev/null | tr -d '\r\n')" "0"
docker compose exec -T cli wp option delete acme_object_settings >/dev/null 2>&1

echo "-- redaction reaches every shape the detector reaches --"
# The blanking has to be at least as thorough as holds_credential(), or it is a hole
# rather than a guard. That function unpacks JSON and serialized strings before judging
# them, so a secret under an innocuous field name arrives as a plain string that a
# key-name-only redaction would copy out untouched and write to the journal.
#
# Each case plants the same token and greps the serialized result for it. The control
# column is the same grep against the value BEFORE redaction: without it, a case that
# failed to build its fixture and a case that was redacted correctly read identically.
redaction_probe() { docker compose exec -T cli wp eval '
  $S = "PLANTEDSECRET0001";
  $cases = [
    "named"      => [ "api_key" => $S, "colour" => "blue" ],
    "nested"     => [ "cfg" => [ "inner" => [ "secret" => $S ] ] ],
    "json"       => [ "cfg" => json_encode( [ "api_key" => $S ] ) ],
    "serialized" => [ "cfg" => serialize( [ "password" => $S ] ) ],
  ];
  $leaked = 0; $unplanted = 0;
  foreach ( $cases as $v ) {
    if ( strpos( serialize( $v ), $S ) === false ) { $unplanted++; continue; }
    [ $ok, $clean ] = GMCP_Core::redact_reversible( $v );
    if ( $ok && strpos( serialize( $clean ), $S ) !== false ) { $leaked++; }
  }
  echo $leaked . ":" . $unplanted;' 2>/dev/null | tr -d '\r\n'; }
check "no planted secret survives redaction, in any shape" "$(redaction_probe)" "0:0"
# "0:0" is two assertions in one string, and the second half is the control: a case whose
# fixture never contained the token is counted separately, so a probe that planted nothing
# reports 0:4 rather than passing as though it had proved something.
check "an innocent value is kept whole rather than blanked" \
  "$(docker compose exec -T cli wp eval '
    [ $ok, $clean ] = GMCP_Core::redact_reversible( [ "width" => 1000, "path" => "M450 75" ] );
    echo $ok && $clean === [ "width" => 1000, "path" => "M450 75" ] ? "whole" : "altered";' 2>/dev/null | tr -d '\r\n')" "whole"
check "and the Elementor case keeps everything but the key field" \
  "$(docker compose exec -T cli wp eval '
    [ $ok, $clean ] = GMCP_Core::redact_reversible( [ "content" => [ "path" => "M450", "key" => "eicon-star" ] ] );
    echo $ok && $clean["content"]["path"] === "M450" && $clean["content"]["key"] === GMCP_Core::REDACTION_MARKER
      ? "path kept, key blanked" : "wrong";' 2>/dev/null | tr -d '\r\n')" "path kept, key blanked"
# The journal row holds previous values of other options, so it must not be readable.
call c_read '{"jsonrpc":"2.0","id":148,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"gmcp_journal"}}}'
check "the journal row cannot be read through the option tools" "$(verdict c_read)" "error"
call c_keys '{"jsonrpc":"2.0","id":149,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"gmcp_tokens"}}}'
check "nor can the key table" "$(verdict c_keys)" "error"
docker compose exec -T cli wp option delete acme_gateway_settings >/dev/null 2>&1

# The recorded tool is what was IN FLIGHT, not always what made the write. The journal
# listens at the WordPress level, so an option written by another plugin hooked on
# save_post during a wp_update_post call is recorded against wp_update_post. Gating on
# that name alone let a write-level caller revert an admin-level option: a key refused
# wp_update_option outright set default_role to editor, which with open registration is a
# way in. So the entry's kind decides a second gate, named for the operation the revert
# actually performs.
# Idempotent on purpose. A probe that flipped the value would be measuring its own
# flipping rather than the revert, and the check would pass or fail for the wrong reason.
docker compose exec -T wp sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/hookprobe.php <<"PHPEOF"
<?php
add_action( "save_post", function ( $id ) {
  update_option( "default_role", "subscriber" );
}, 10, 1 );
PHPEOF' >/dev/null 2>&1
M_POST=$(docker compose exec -T cli wp post create --post_title='Attribution probe' --post_status=publish --porcelain 2>/dev/null | tr -d '\r\n')
# Set AFTER creating the post. Creating it fires save_post too, and if the value already
# matched, the tool call below would change nothing and the journal would record nothing.
# editor is what the revert would restore, so a successful revert is the escalation.
docker compose exec -T cli wp option update default_role editor >/dev/null 2>&1
docker compose exec -T cli wp option delete gmcp_journal >/dev/null 2>&1
call m_touch "{\"jsonrpc\":\"2.0\",\"id\":150,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_post\",\"arguments\":{\"ID\":$M_POST,\"post_title\":\"Touched\"}}}"
call m_all '{"jsonrpc":"2.0","id":151,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{}}}'
check "an option written during a post call is attributed to the post tool" \
  "$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(any(x['tool']=='wp_update_post' and 'default_role' in x['what'] for x in e))" m_all)" "True"
M_OPT=$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(next(x['id'] for x in e if 'default_role' in x['what']))" m_all)
K_MIX=$(docker compose exec -T cli wp eval '$a=GMCP_Tokens::create("Writer","readwrite",0,[]);echo $a["secret"];' 2>/dev/null | tr -d '\r\n')
kcall m_direct "$K_MIX" '{"jsonrpc":"2.0","id":152,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"default_role","value":"editor"}}}'
check "a write-level key cannot set default_role directly" "$(verdict m_direct)" "error"
kcall m_undo "$K_MIX" "{\"jsonrpc\":\"2.0\",\"id\":153,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$M_OPT\"}}}"
check "nor revert it just because a post tool was in flight" "$(verdict m_undo)" "error"
# The id has to be real, or the refusal above proves nothing: an empty id is refused with
# equal enthusiasm by the lookup, and this block sat green through exactly that.
check "and the id it was refused for actually exists" \
  "$( [ -n "$M_OPT" ] && echo present || echo EMPTY )" "present"
check "refused by the gate, not by a missing entry" \
  "$(refusal m_undo | grep -qi 'no recorded change' && echo "WRONG REASON" || echo gate)" "gate"
check "default_role was not restored to the more privileged value" \
  "$(docker compose exec -T cli wp option get default_role 2>/dev/null | tr -d '\r\n')" "subscriber"
# Telling a caller something is reversible and then refusing is its own bug.
kcall m_list "$K_MIX" '{"jsonrpc":"2.0","id":154,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{}}}'
# all([]) is True, so the check below would pass over a filter that had stopped matching
# anything. The count comes first: a selection of zero entries proves nothing about
# reversibility, and "no such entry" is not "not reversible".
check "the listing has the entry this is about" \
  "$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(len([x for x in e if 'default_role' in x['what']]) > 0)" m_list)" "True"
check "the listing already says it is not reversible for this caller" \
  "$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);m=[x for x in e if 'default_role' in x['what']];print(bool(m) and all(not x['reversible'] for x in m))" m_list)" "True"
# The gate must not cost a caller the reverts it is entitled to.
kcall m_post "$K_MIX" "{\"jsonrpc\":\"2.0\",\"id\":155,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(next(x['id'] for x in e if x['what'].startswith('Post')))" m_all)\"}}}"
check "a write-level key can still revert a post change" "$(verdict m_post)" "ok"
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/hookprobe.php' >/dev/null 2>&1
docker compose exec -T cli wp post delete "$M_POST" --force >/dev/null 2>&1
gmcp_clear_other_keys "smoke suite"
docker compose exec -T cli wp option update default_role subscriber >/dev/null 2>&1

# touch() is a read-modify-write of the row holding every key. Writing back a copy
# fetched before the throttle check resurrected a key revoked in between.
check "a revoked key is not resurrected by a later touch" \
  "$(docker compose exec -T cli wp eval '$a=GMCP_Tokens::create("Doomed","readonly",0,[]);$r=GMCP_Tokens::all();$r[$a["id"]]["last_used"]=0;update_option("gmcp_tokens",$r,false);$stale=GMCP_Tokens::all();GMCP_Tokens::revoke($a["id"]);GMCP_Tokens::touch($a["id"]);echo isset(GMCP_Tokens::all()[$a["id"]])?1:0;' 2>/dev/null | tr -d '\r\n')" "0"
gmcp_clear_other_keys "smoke suite"

# The guard matches field names inside a value. Options hold settings as arrays, as
# stdClass and as JSON strings, and checking only arrays left two of those three
# unguarded. The short forms matter most: the guard matches by substring, so "password"
# does not match a field called "pass", and wp_mail_smtp stores its password under
# exactly that. The example named in the comment was the one slipping past the check.
docker compose exec -T cli wp option delete gmcp_journal >/dev/null 2>&1
docker compose exec -T cli wp eval-file /var/www/html/wp-content/plugins/guarded-mcp/.dev/credential-shapes.php >/dev/null 2>&1
call s_obj '{"jsonrpc":"2.0","id":160,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"probe_obj","value":{"secret_key":"rotated"}}}}'
call s_json '{"jsonrpc":"2.0","id":161,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"probe_json","value":"{}"}}}'
call s_smtp '{"jsonrpc":"2.0","id":162,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"probe_smtp","value":{"smtp":{"pass":"rotated"}}}}}'
call s_deep '{"jsonrpc":"2.0","id":163,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"probe_deep","value":{"x":1}}}}'
call s_plain '{"jsonrpc":"2.0","id":164,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"probe_plain","value":{"mode":"test"}}}}'
leaked() { docker compose exec -T cli wp eval 'echo strpos(maybe_serialize(get_option("gmcp_journal",[])),"'"$1"'")===false?0:1;' 2>/dev/null | tr -d '\r\n'; }
check "a credential in a stdClass is not journalled" "$(leaked OBJ_LEAK_1)" "0"
check "a credential in a JSON string is not journalled" "$(leaked JSON_LEAK_2)" "0"
check "a password under the short field name pass is not journalled" "$(leaked SMTP_LEAK_3)" "0"
# Running past the depth limit has to redact. Returning false there is a limit that
# fails open, and a credential nested deeply enough was stored.
check "a credential deeper than the walk limit is not journalled" "$(leaked DEEP_LEAK_4)" "0"
# The opposite failure: a check broad enough to redact everything would pass all four
# above and quietly make undo useless.
call s_after '{"jsonrpc":"2.0","id":165,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{}}}'
check "an ordinary settings array is still journalled and revertible" \
  "$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(next(x['reversible'] for x in e if 'probe_plain' in x['what']))" s_after)" "True"
docker compose exec -T cli wp option delete probe_obj probe_json probe_smtp probe_deep probe_plain >/dev/null 2>&1

echo "-- a hidden prompt cannot be fetched by name --"
K_PR=$(docker compose exec -T cli wp eval '$a=GMCP_Tokens::create("Posts only","readonly",0,["wp_get_posts"]);echo $a["secret"];' 2>/dev/null | tr -d '\r\n')
kcall pr_list "$K_PR" '{"jsonrpc":"2.0","id":170,"method":"prompts/list"}'
check "the scoped key is offered only prompts it can drive" \
  "$(py "import json,sys;print(','.join(sorted(x['name'] for x in json.load(sys.stdin)['result']['prompts'])))" pr_list)" \
  "content_audit,stale_drafts"
kcall pr_hidden "$K_PR" '{"jsonrpc":"2.0","id":171,"method":"prompts/get","params":{"name":"site_health_brief"}}'
check "and cannot fetch a hidden one by name" "$(py 'import json,sys;print("error" in json.load(sys.stdin))' pr_hidden)" "True"
# Distinct from "unknown", so a client holding a stale listing can tell a withdrawn
# prompt from one that never existed, and can say which tools it needs.
check "the refusal names the tool it cannot reach" \
  "$(py 'import json,sys;print("wp_get_site_health" in json.load(sys.stdin)["error"]["message"])' pr_hidden)" "True"
kcall pr_ok "$K_PR" '{"jsonrpc":"2.0","id":172,"method":"prompts/get","params":{"name":"stale_drafts"}}'
check "an offered prompt still renders for it" "$(py 'import json,sys;print("result" in json.load(sys.stdin))' pr_ok)" "True"
gmcp_clear_other_keys "smoke suite"

echo "-- this plugin's own rows are not readable through its own tools --"
# The guard used to name rows one at a time and was wrong twice: the change journal was
# readable until it was named, and the one-time plaintext of a newly minted key sits in
# _transient_gmcp_new_key, which no exact entry matched. Reading it needs an admin-level
# caller, so it is not a level escalation, but an admin key deliberately narrowed to
# wp_get_option, the shape of a reporting key someone would think safe, harvested any key
# minted in the next sixty seconds. It also undercut the whole reason keys are hashed.
docker compose exec -T cli wp eval 'set_transient("gmcp_new_key_1","gmcp_deadbeef_SECRETPLAINTEXTKEY",60);' >/dev/null 2>&1
for row in _transient_gmcp_new_key_1 gmcp_journal gmcp_tokens gmcp_activity gmcp_options; do
  call g_row "{\"jsonrpc\":\"2.0\",\"id\":180,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_get_option\",\"arguments\":{\"key\":\"$row\"}}}"
  check "$row is refused" "$(verdict g_row)" "error"
done
# raw:true reads straight from the database, bypassing the object cache and option_*
# filters, so it has to be refused by the same gate rather than sneaking round it.
call g_raw '{"jsonrpc":"2.0","id":181,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"_transient_gmcp_new_key_1","raw":true}}}'
check "and the raw read is refused too" "$(verdict g_raw)" "error"
# Substring, not prefix, and that is load-bearing rather than incidental: the row this
# finding was about is named _transient_gmcp_new_key_<user>, so a rule anchored to the
# start of the option name would miss the one it most needs to catch.
check "the match is not anchored to the start of the name" \
  "$(docker compose exec -T cli wp eval 'echo GMCP_Core::option_guard("_transient_gmcp_new_key_1")===true?"allowed":"refused";' 2>/dev/null | tr -d '\r\n')" "refused"
# The opposite failure: a prefix rule broad enough to refuse everything would pass all of
# the above and make the option tools useless.
call g_ok '{"jsonrpc":"2.0","id":182,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"blogname"}}}'
check "an ordinary option still reads" "$(verdict g_ok)" "ok"
docker compose exec -T cli wp eval 'delete_transient("gmcp_new_key_1");' >/dev/null 2>&1

echo "-- what two-step confirmation does and does not cover --"
# The readmes led with "deleting takes two calls" for a long time. It is true of plugins,
# themes, menus and the administration email, and false of content: wp_delete_post with
# force destroys a post in one call. Pinning both halves, because the sentence read as
# universal and nothing in the suite contradicted it.
call cf_plugin '{"jsonrpc":"2.0","id":200,"method":"tools/call","params":{"name":"wp_delete_plugin","arguments":{"plugin":"akismet/akismet.php"}}}'
check "deleting a plugin is refused without a token" "$(verdict cf_plugin)" "error"
check "and the refusal offers one" "$(refusal cf_plugin | grep -c 'confirm')" "1"
CF_POST=$(docker compose exec -T cli wp post create --post_title='One call delete' --post_status=publish --porcelain 2>/dev/null | tr -d '\r\n')
call cf_post "{\"jsonrpc\":\"2.0\",\"id\":201,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_delete_post\",\"arguments\":{\"ID\":$CF_POST,\"force\":true}}}"
check "deleting a post permanently takes one call, as documented" "$(verdict cf_post)" "ok"
check "and the post really is gone" \
  "$(docker compose exec -T cli wp post list --post__in="$CF_POST" --format=count 2>/dev/null | tr -d '\r\n')" "0"

echo "-- the audit log --"
# The old version lived in an option: a read-modify-write that could lose a concurrent
# entry, capped at a hundred rows. This is a table, so an INSERT cannot lose anything and
# the record survives long enough to answer a question about last month.
docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1
call au_refuse '{"jsonrpc":"2.0","id":210,"method":"tools/call","params":{"name":"wp_delete_theme","arguments":{"stylesheet":"twentytwentyfive"}}}'
call au_ok '{"jsonrpc":"2.0","id":211,"method":"tools/call","params":{"name":"wp_create_post","arguments":{"post_title":"Audited","post_status":"draft"}}}'
check "a refusal is recorded, not just successes" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit WHERE outcome=\"refused\"");' 2>/dev/null | tr -d '\r\n')" "1"
# The refusal message is the interesting content in the whole table: it is the sentence
# that says a guard fired.
check "and the refusal text is kept" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;echo (int)(bool)$wpdb->get_var("SELECT detail FROM {$wpdb->prefix}gmcp_audit WHERE outcome=\"refused\" LIMIT 1");' 2>/dev/null | tr -d '\r\n')" "1"

# The decision the whole design turns on. wp_create_user takes a password and
# wp_update_option takes whatever a settings array holds, so recording arguments verbatim
# would put plaintext credentials in a table meant to be kept for months.
call au_pw '{"jsonrpc":"2.0","id":212,"method":"tools/call","params":{"name":"wp_create_user","arguments":{"user_login":"audituser","user_email":"au@example.test","user_pass":"PLAINTEXTMUSTNOTAPPEAR","role":"subscriber"}}}'
call au_secret '{"jsonrpc":"2.0","id":213,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"acme_gw","value":{"mode":"live","secret_key":"sk_live_MUSTNOTAPPEAR"}}}}'
leaked_audit() { docker compose exec -T cli wp eval 'global $wpdb;$a=implode("",$wpdb->get_col("SELECT CONCAT(COALESCE(args,\"\"),COALESCE(detail,\"\")) FROM {$wpdb->prefix}gmcp_audit"));echo strpos($a,"'"$1"'")===false?0:1;' 2>/dev/null | tr -d '\r\n'; }
check "a password argument is never written down" "$(leaked_audit PLAINTEXTMUSTNOTAPPEAR)" "0"
check "nor a key nested in a settings array" "$(leaked_audit sk_live_MUSTNOTAPPEAR)" "0"
# The control. Without it, a scan that silently returns nothing passes both checks above
# for the wrong reason, which happened while writing them.
check "but harmless arguments are, so the scan works" "$(leaked_audit au@example.test)" "1"
# The credential patterns suit field names inside a value, where "key" is a good signal.
# At the top level it is the option's NAME, and redacting it leaves an entry saying an
# option changed without saying which.
# A call that names a thing and supplies its content: if the NAME is credential-shaped
# then the content is a credential, whatever the argument holding it is called.
# wp_update_option passes the name as "key" and the secret as "value", and "value" matches
# no pattern, so a password written to smtp_pwd was kept in the clear for ninety days. The
# write is allowed because option_guard's list is deliberately narrower than the field-name
# list, which is right, and is exactly why the recording side must look at the name.
call c_named '{"jsonrpc":"2.0","id":166,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"smtp_pwd","value":"PWDMUSTNOTAPPEAR"}}}'
call c_plainopt '{"jsonrpc":"2.0","id":167,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"blogdescription","value":"an ordinary setting"}}}'
check "a value under a credential-shaped option name is not recorded" "$(leaked PWDMUSTNOTAPPEAR)" "0"
# Two controls. The option name must survive, or the entry says nothing useful; and an
# ordinary value must survive, or the check above would pass by redacting everything.
check "but the option name still is" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;echo (int)(bool)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}gmcp_audit WHERE args LIKE \"%smtp_pwd%\"");' 2>/dev/null | tr -d '\r\n')" "1"
check "and an ordinary value is not over-redacted" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;echo (int)(bool)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}gmcp_audit WHERE args LIKE \"%an ordinary setting%\"");' 2>/dev/null | tr -d '\r\n')" "1"
docker compose exec -T cli wp option delete smtp_pwd >/dev/null 2>&1

check "the option name stays readable" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;echo (int)(bool)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}gmcp_audit WHERE args LIKE \"%acme_gw%\"");' 2>/dev/null | tr -d '\r\n')" "1"

# An edited row and a deleted row have to look different from a real one, or this is a
# history rather than an audit.
# The check used to read the OLDEST 5,000 rows of a table allowed to hold 50,000, and
# then say "intact across 5,000 entries", which is true and reads as coverage. Tampering
# with anything recent was never examined, so a green line said least about the period
# somebody would most want to check. It now walks the newest rows by default, in chunks,
# and reports how much of the table it looked at.
check "a recent row is inside the default window" \
  "$(docker compose exec -T cli wp eval '
     GMCP_Audit::clear();
     global $wpdb; $t = $wpdb->prefix . "gmcp_audit";
     $m = new ReflectionMethod( "GMCP_Audit", "hash" ); $m->setAccessible( true );
     $prev = "";
     for ( $i = 0; $i < 1200; $i++ ) {
       $row = [ "ts" => gmdate("Y-m-d H:i:s"), "actor" => 0, "actor_name" => "", "client" => "",
                "auth_method" => "", "tool" => "wp_get_posts", "target" => (string) $i,
                "outcome" => "ok", "ms" => 1, "args" => "{}", "detail" => "" ];
       $row["prev_hash"] = $prev; $row["hash"] = $m->invoke( null, $row, $prev );
       $prev = $row["hash"]; $wpdb->insert( $t, $row );
     }
     $recent = (int) $wpdb->get_var( "SELECT id FROM $t ORDER BY id DESC LIMIT 1 OFFSET 5" );
     $wpdb->query( $wpdb->prepare( "UPDATE $t SET target = %s WHERE id = %d", "tampered", $recent ) );
     $v = GMCP_Audit::verify();
     echo $v["ok"] ? "MISSED" : "detected";' 2>/dev/null | tr -d '\r\n')" "detected"
# And the honesty half: a partial check must say it was partial, or a tenth of a log reads
# as a whole one.
check "a partial check says so" \
  "$(docker compose exec -T cli wp eval '$v=GMCP_Audit::verify();echo $v["complete"]?"claims complete":"says partial";' 2>/dev/null | tr -d '\r\n')" "says partial"
docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1
for i in 1 2 3; do
  call vw_one "{\"jsonrpc\":\"2.0\",\"id\":24$i,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_get_posts\",\"arguments\":{\"limit\":1}}}"
done
check "and the full walk covers everything" \
  "$(docker compose exec -T cli wp eval '$v=GMCP_Audit::verify("all");echo $v["ok"] && $v["complete"] ? "complete" : "INCOMPLETE";' 2>/dev/null | tr -d '\r\n')" "complete"
# Rows written by record() rather than by hand, so the check cannot pass or fail because
# the seed disagreed with what the plugin actually writes. It did, once: actor is
# NOT NULL DEFAULT 0, so an omitted actor hashes as "" and reads back as "0".
check "and those rows were written by the plugin, not seeded" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"wp_get_posts\"");' 2>/dev/null | tr -d '\r\n')" "3"

check "the chain is intact before tampering" \
  "$(docker compose exec -T cli wp eval '$v=GMCP_Audit::verify();echo $v["ok"]?"ok":"broken";' 2>/dev/null | tr -d '\r\n')" "ok"
check "editing a row is detected" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;$t=$wpdb->prefix."gmcp_audit";$id=(int)$wpdb->get_var("SELECT id FROM $t ORDER BY id ASC LIMIT 1 OFFSET 1");$wpdb->query($wpdb->prepare("UPDATE $t SET target=%s WHERE id=%d","tampered",$id));$v=GMCP_Audit::verify();echo $v["ok"]?"missed":"detected";' 2>/dev/null | tr -d '\r\n')" "detected"
check "deleting a row is detected too" \
  "$(docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1; for i in 1 2 3 4; do curl -sS -o /dev/null -X POST "$URL" -H "Authorization: Bearer $TOK" -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d "{\"jsonrpc\":\"2.0\",\"id\":$i,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_get_posts\",\"arguments\":{\"limit\":1}}}"; done; docker compose exec -T cli wp eval 'global $wpdb;$t=$wpdb->prefix."gmcp_audit";$id=(int)$wpdb->get_var("SELECT id FROM $t ORDER BY id ASC LIMIT 1 OFFSET 1");$wpdb->query("DELETE FROM $t WHERE id=$id");$v=GMCP_Audit::verify();echo $v["ok"]?"missed":"detected";' 2>/dev/null | tr -d '\r\n')" "detected"
# Rows carried over from the option-based version were never in a chain. Reporting them
# as a break would tell every upgraded site on day one that its log had been tampered with.
check "imported rows are reported, not called tampering" \
  "$(docker compose exec -T cli wp eval 'GMCP_Audit::clear();global $wpdb;$wpdb->insert($wpdb->prefix."gmcp_audit",["ts"=>gmdate("Y-m-d H:i:s"),"tool"=>"old","outcome"=>"ok","hash"=>"","prev_hash"=>""]);$v=GMCP_Audit::verify();echo $v["ok"]&&$v["imported"]===1?"handled":"WRONG";' 2>/dev/null | tr -d '\r\n')" "handled"

# Age, row count and byte count, because any one alone fails: age lets a runaway agent
# fill a disk in a day, a row cap lets one enormous entry do it, a byte cap throws away
# last week because of last year.
check "pruning drops everything past the retention window" \
  "$(docker compose exec -T cli wp eval 'GMCP_Audit::clear();global $wpdb;$t=$wpdb->prefix."gmcp_audit";for($i=0;$i<40;$i++){$wpdb->insert($t,["ts"=>gmdate("Y-m-d H:i:s",time()-($i*5*DAY_IN_SECONDS)),"tool"=>"wp_get_posts","outcome"=>"ok","args"=>"{}","detail"=>"","hash"=>"","prev_hash"=>""]);}GMCP_Audit::prune();$cut=gmdate("Y-m-d H:i:s",time()-(GMCP_Audit::retention_days()*DAY_IN_SECONDS));echo (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE ts < \"$cut\"");' 2>/dev/null | tr -d '\r\n')" "0"
check "and keeps everything inside it" \
  "$(docker compose exec -T cli wp eval 'echo GMCP_Audit::count() > 0 ? "kept" : "OVERPRUNED";' 2>/dev/null | tr -d '\r\n')" "kept"
check "a prune is scheduled" \
  "$(docker compose exec -T cli wp eval 'echo wp_next_scheduled("gmcp_audit_prune") ? "yes" : "no";' 2>/dev/null | tr -d '\r\n')" "yes"

# An agent that can prune its own audit trail is not being audited.
call au_tools '{"jsonrpc":"2.0","id":214,"method":"tools/list"}'
check "the only audit tool is the read one" \
  "$(py "import json,sys;t=[x['name'] for x in json.load(sys.stdin)['result']['tools']];print(','.join(sorted(n for n in t if 'audit' in n)))" au_tools)" \
  "wp_get_audit_log"
call au_read '{"jsonrpc":"2.0","id":215,"method":"tools/call","params":{"name":"wp_get_audit_log","arguments":{"limit":5}}}'
check "and it reads" "$(verdict au_read)" "ok"
# A caller reading the record should be told straight away if the record was altered.
check "the reply carries the tamper verdict" \
  "$(py "import json,sys;d=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print('yes' if d.get('tamper_check') else 'no')" au_read)" "yes"
docker compose exec -T cli wp user delete audituser --yes >/dev/null 2>&1
docker compose exec -T cli wp option delete acme_gw >/dev/null 2>&1

echo "-- what the journal does and does not see --"
# The journal listens on post_updated and updated_option. WordPress does not fire
# post_updated on an insert, so a post the agent created is not in the journal, and
# neither is one it deleted. Users, comments and terms are not listened for at all. The
# readmes claimed "changes can be put back" without saying which changes, which read as a
# promise about deletions. Pinning the real boundary so the documentation cannot drift
# back, and so the day somebody widens the listeners this fails loudly and gets updated.
docker compose exec -T cli wp option delete gmcp_journal >/dev/null 2>&1
call jb_new '{"jsonrpc":"2.0","id":220,"method":"tools/call","params":{"name":"wp_create_post","arguments":{"post_title":"Journal boundary probe","post_status":"publish"}}}'
JB_ID=$(py "import json,sys,re;print(re.search(r'\d+',json.load(sys.stdin)['result']['content'][0]['text']).group())" jb_new)
call jb_edit "{\"jsonrpc\":\"2.0\",\"id\":221,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_post\",\"arguments\":{\"ID\":$JB_ID,\"post_title\":\"Renamed\"}}}"
call jb_list '{"jsonrpc":"2.0","id":222,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{}}}'
# The control. Without it, "creation absent" would pass on an empty journal for any reason.
check "an edit is journalled" \
  "$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(sum(1 for x in e if x['tool']=='wp_update_post'))" jb_list)" "1"
check "a creation is not, which the readmes now say" \
  "$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(sum(1 for x in e if x['tool']=='wp_create_post'))" jb_list)" "0"
call jb_del "{\"jsonrpc\":\"2.0\",\"id\":223,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_delete_post\",\"arguments\":{\"ID\":$JB_ID,\"force\":true}}}"
call jb_list2 '{"jsonrpc":"2.0","id":224,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{}}}'
check "nor is a deletion" \
  "$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(sum(1 for x in e if x['tool']=='wp_delete_post'))" jb_list2)" "0"
# The audit log is the complete record, which is the reason the readmes can point at it.
check "but the audit log has all three" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;echo (int)$wpdb->get_var("SELECT COUNT(DISTINCT tool) FROM {$wpdb->prefix}gmcp_audit WHERE tool IN (\"wp_create_post\",\"wp_update_post\",\"wp_delete_post\")");' 2>/dev/null | tr -d '\r\n')" "3"

echo "-- a backup plugin's own rows are not a way round the backup tools --"
# wp_list_backups withholds archive filenames because the name is what makes the archive
# fetchable. That was worth nothing while wp_get_option would hand over the same plugin's
# option rows: the filenames and job nonce out of updraft_backup_history, and on a site
# with offsite storage configured the FTP password, the S3 access and secret keys, and the
# archive encryption passphrase, which is the one thing making a stored archive safe at
# rest. None of those names contains "password", "secret" or "key", so the credential
# heuristic matched none of them.
#
# Seeded with the shapes these plugins really store, because a site with no remote storage
# configured has no such rows and the check would pass on a site that could not fail.
docker compose exec -T cli wp eval '
  update_option( "backuply_remote_backup_locs", [ 1 => [ "name" => "Offsite", "protocol" => "ftp", "ftp_user" => "u", "ftp_pass" => "SMOKE-FTP-PASSWORD" ] ] );
  update_option( "updraft_s3", [ "settings" => [ "x" => [ "accesskey" => "SMOKE-ACCESS-KEY", "secretkey" => "SMOKE-SECRET-KEY" ] ] ] );
  update_option( "updraft_encryptionphrase", "SMOKE-ENCRYPTION-PHRASE" );
' >/dev/null 2>&1
# The control: every planted secret really is in the database, so an absence below is the
# guard withholding it rather than there being nothing to withhold.
check "control: the planted backup secrets really are stored" \
  "$(docker compose exec -T cli wp eval '
      $n = 0;
      $locs = (array) get_option( "backuply_remote_backup_locs", [] );
      if ( isset( $locs[1]["ftp_pass"] ) && $locs[1]["ftp_pass"] === "SMOKE-FTP-PASSWORD" ) { $n++; }
      $s3 = (array) get_option( "updraft_s3", [] );
      if ( ( $s3["settings"]["x"]["secretkey"] ?? "" ) === "SMOKE-SECRET-KEY" ) { $n++; }
      if ( get_option( "updraft_encryptionphrase" ) === "SMOKE-ENCRYPTION-PHRASE" ) { $n++; }
      echo $n;' 2>/dev/null | tr -d '\r\n')" "3"

bs_read() { call "$1" "{\"jsonrpc\":\"2.0\",\"id\":270,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_get_option\",\"arguments\":{\"key\":\"$2\"}}}"; }
bs_read bs_hist updraft_backup_history
check "the archive filenames and nonce are refused" "$(verdict bs_hist)" "error"
bs_read bs_s3 updraft_s3
check "the storage access and secret keys are refused" "$(verdict bs_s3)" "error"
bs_read bs_phrase updraft_encryptionphrase
check "the archive encryption passphrase is refused" "$(verdict bs_phrase)" "error"
bs_read bs_locs backuply_remote_backup_locs
check "the offsite FTP password is refused" "$(verdict bs_locs)" "error"
bs_read bs_keys backuply_config_keys
check "and the key authenticating Backuply's own endpoints is refused" "$(verdict bs_keys)" "error"
# A refusal an agent cannot act on gets worked around by guessing at another row.
check "the refusal names the tools that do answer the question" \
  "$(refusal bs_hist | grep -c 'wp_list_backups')" "1"
# raw:true is a separate code path and was how the credential guard was first bypassed.
call bs_raw '{"jsonrpc":"2.0","id":271,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"updraft_s3","raw":true}}}'
check "and a raw read cannot go round it" "$(verdict bs_raw)" "error"

# Writing matters on its own: an agent that can rewrite the history can erase a site's
# record of its own backups.
# The check below asserts the refusal left the history alone, so there has to be a history
# to leave alone. It is normally built earlier in this run by a backup that completes, and
# on a site where that never happened the check reduces to "an empty thing is still empty",
# which passes whatever the guard does. That is why it failed on the first run against
# three separate fresh stacks and passed on every second one: flakiness in appearance, a
# missing precondition in fact.
#
# Planted BEFORE the hostile write, never after. Repairing afterwards would put back
# precisely the damage the check exists to detect, so a broken guard would read as a pass.
docker compose exec -T cli wp eval '
  if ( ! (array) get_option( "updraft_backup_history", [] ) ) {
    update_option( "updraft_backup_history", [ time() => [ "nonce" => "smoketest" ] ] );
  }' >/dev/null 2>&1
check "there is a history to protect in the first place" \
  "$(docker compose exec -T cli wp eval 'echo count( (array) get_option( "updraft_backup_history", [] ) ) > 0 ? 1 : 0;' 2>/dev/null | tr -d '\r\n')" "1"
call bs_write '{"jsonrpc":"2.0","id":272,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"updraft_backup_history","value":{}}}}'
check "rewriting the backup history is refused" "$(verdict bs_write)" "error"
check "and the history is still there" \
  "$(docker compose exec -T cli wp eval 'echo count( (array) get_option( "updraft_backup_history", [] ) ) > 0 ? 1 : 0;' 2>/dev/null | tr -d '\r\n')" "1"

# The audit log records a tool's response, so before this the secrets were not merely
# returned, they were written to the database and readable again through wp_get_audit_log.
check "no planted secret reaches any reply" \
  "$(cat "$OUT/bs_hist" "$OUT/bs_s3" "$OUT/bs_phrase" "$OUT/bs_locs" "$OUT/bs_keys" "$OUT/bs_raw" | grep -c 'SMOKE-FTP-PASSWORD\|SMOKE-SECRET-KEY\|SMOKE-ACCESS-KEY\|SMOKE-ENCRYPTION-PHRASE')" "0"
check "nor the audit log that stores the replies" \
  "$(docker compose exec -T cli wp eval 'global $wpdb; $t = $wpdb->prefix . "gmcp_audit";
      $all = implode( "", (array) $wpdb->get_col( "SELECT CONCAT(COALESCE(args,\"\"),COALESCE(detail,\"\"),COALESCE(changes,\"\")) FROM $t" ) );
      $hit = 0; foreach ( [ "SMOKE-FTP-PASSWORD", "SMOKE-SECRET-KEY", "SMOKE-ACCESS-KEY", "SMOKE-ENCRYPTION-PHRASE" ] as $n ) { if ( strpos( $all, $n ) !== false ) { $hit++; } }
      echo $hit;' 2>/dev/null | tr -d '\r\n')" "0"
# A scan over an empty column reports every secret safe, so prove the column has content.
check "control: the audit log did record these calls" \
  "$(docker compose exec -T cli wp eval 'global $wpdb; $t = $wpdb->prefix . "gmcp_audit";
      echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE tool IN (\"wp_get_option\",\"wp_update_option\") AND target LIKE \"updraft%\"" ) > 0 ? 1 : 0;' 2>/dev/null | tr -d '\r\n')" "1"

# Ordinary options are untouched. A guard that blocked everything would pass every check
# above and be useless.
call bs_ok '{"jsonrpc":"2.0","id":273,"method":"tools/call","params":{"name":"wp_get_option","arguments":{"key":"blogname"}}}'
check "control: an unrelated option is still readable" "$(verdict bs_ok)" "ok"
docker compose exec -T cli wp eval '
  delete_option( "backuply_remote_backup_locs" );
  delete_option( "updraft_s3" );
  delete_option( "updraft_encryptionphrase" );
' >/dev/null 2>&1

echo "-- backups: reporting, never pretending --"
# The dangerous failure here is a false yes. Every other guard in this plugin fails
# closed; a backup tool that claims a backup exists when it does not fails OPEN, because
# it makes an agent more willing to do the irreversible thing. So the checks below are
# about what it says when it does not know, as much as when it does.
call bk_status '{"jsonrpc":"2.0","id":230,"method":"tools/call","params":{"name":"wp_backup_status","arguments":{}}}'
check "status answers" "$(verdict bk_status)" "ok"
bk() { py "import json,sys;d=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print($1)" bk_status; }
# UpdraftPlus is installed on this stack, so the drivable path is the one under test.
check "it names the provider it found" "$(bk "d['provider']")" "UpdraftPlus"
check "and reports it can both start and read it" "$(bk "str(d['can_start']) + ',' + str(d['can_tell'])")" "True,True"

# Starting must never report completion. On a site with three posts UpdraftPlus finishes
# inside the request, which is exactly the trap: testing here and concluding the operation
# is synchronous would put a false promise in front of every real site.
call bk_start '{"jsonrpc":"2.0","id":231,"method":"tools/call","params":{"name":"wp_start_backup","arguments":{}}}'
check "starting succeeds" "$(verdict bk_start)" "ok"
check "and says plainly that it is not finished" "$(refusal bk_start | grep -c 'not finished yet')" "1"
check "and never claims a backup was taken" "$(refusal bk_start | grep -ci 'backup complete\|backup taken\|backed up successfully')" "0"

# The two-step confirmation reports the situation rather than gating on it.
call bk_confirm '{"jsonrpc":"2.0","id":232,"method":"tools/call","params":{"name":"wp_delete_plugin","arguments":{"plugin":"akismet/akismet.php"}}}'
check "a destructive confirmation carries the backup state" "$(refusal bk_confirm | grep -c 'UpdraftPlus backup')" "1"
# Reporting, not gating: the operation must still be reachable.
check "and still offers a token rather than refusing outright" "$(refusal bk_confirm | grep -c 'confirm set to')" "1"

echo "-- backups: Backuply, where the button's route is not a route --"
# The second drivable provider, and the one that shows these adapters are written against
# what a REST request actually has. Backuply's Create Backup button posts to a handler in
# a file the plugin includes only under wp_doing_ajax(), and that handler calls the site
# back over HTTP carrying the administrator's browser cookies. From here the function does
# not exist and there are no cookies, so the adapter goes through the cron hook Backuply
# runs its own unattended backups on instead.
#
# UpdraftPlus wins detection while both are active, so it comes off for this section. A
# check that quietly exercised UpdraftPlus a second time would pass without ever reaching
# the code it names.
docker compose exec -T cli wp plugin deactivate updraftplus >/dev/null 2>&1
docker compose exec -T cli wp plugin activate backuply >/dev/null 2>&1
# Back to a site that has never completed a backup, which the third assertion below needs,
# and which is also what clears the archive the previous run of this suite left on disk.
docker compose exec -T cli wp eval '
  delete_option( "backuply_last_backup" );
  delete_option( "backuply_status" );
  foreach ( glob( BACKUPLY_BACKUP_DIR . "backups_info-*/*.php" ) as $f ) { if ( basename( $f ) !== "index.php" ) { @unlink( $f ); } }
  foreach ( glob( BACKUPLY_BACKUP_DIR . "backups-*/*.tar.gz" ) as $f ) { @unlink( $f ); }
  @unlink( BACKUPLY_BACKUP_DIR . "backuply_backup_log.php" );
' >/dev/null 2>&1

call bky_status '{"jsonrpc":"2.0","id":240,"method":"tools/call","params":{"name":"wp_backup_status","arguments":{}}}'
bky() { py "import json,sys;d=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print($1)" "$2"; }
check "Backuply is detected once UpdraftPlus is out of the way" "$(bky "d['provider']" bky_status)" "Backuply"
check "and is reported as both startable and readable" "$(bky "str(d['can_start']) + ',' + str(d['can_tell'])" bky_status)" "True,True"
check "with nothing yet to fall back on" "$(bky "str(d['last_completed'])" bky_status)" "None"

call bky_start '{"jsonrpc":"2.0","id":241,"method":"tools/call","params":{"name":"wp_start_backup","arguments":{}}}'
check "starting succeeds" "$(verdict bky_start)" "ok"
# The reply is the tool being polite about what it asked for. This is the evidence that it
# asked: a job queued on the hook Backuply itself runs backups from.
check "and really queues Backuply's own backup hook" \
  "$(docker compose exec -T cli wp cron event list --fields=hook 2>/dev/null | grep -c backuply_backup_cron)" "1"
call bky_queued '{"jsonrpc":"2.0","id":242,"method":"tools/call","params":{"name":"wp_backup_status","arguments":{}}}'
check "and a queued job reads as in flight, never as a backup that exists" \
  "$(bky "str(d['running']) + ',' + str(d['last_completed'])" bky_queued)" "True,None"
# Backuply keeps one job record, so a second start would overwrite the first and leave
# neither finishable. Refusing is half of it; saying which refusal this is is the half an
# agent can act on, because this one means wait and the other means stop.
call bky_again '{"jsonrpc":"2.0","id":243,"method":"tools/call","params":{"name":"wp_start_backup","arguments":{}}}'
check "a second start while one is in flight is refused" "$(verdict bky_again)" "error"
check "and the refusal says which refusal it is" "$(refusal bky_again | grep -c 'already has a backup in flight')" "1"

# Run the queued job from here rather than waiting on the site's own cron. This stack
# cannot reach itself over HTTP: WordPress believes it is on the published port while
# Apache listens on 80 inside the container, so the loopback spawn_cron fires goes
# nowhere. That is a property of the stack and not of the tool, which is why the check
# above asserts the job was queued and the ones below assert what running it produces.
docker compose exec -T cli wp cron event run backuply_backup_cron >/dev/null 2>&1
call bky_done '{"jsonrpc":"2.0","id":244,"method":"tools/call","params":{"name":"wp_backup_status","arguments":{}}}'
check "running the job leaves a real archive on disk" \
  "$(docker compose exec -T cli wp eval 'echo count( glob( BACKUPLY_BACKUP_DIR . "backups-*/*.tar.gz" ) );' 2>/dev/null | tr -d '\r\n')" "1"
check "and the status counts it" "$(bky "d['count']" bky_done)" "1"
check "and stops calling a finished job running" "$(bky "str(d['running'])" bky_done)" "False"
check "and reads it as having succeeded" "$(bky "str(d['last_succeeded'])" bky_done)" "True"

# A failed Backuply run writes nothing to the database: backuply_last_backup only moves on
# success, so a failure an hour ago and no attempt at all leave the same trace. Without
# reading the log this would report the older backup with no hint that the last try broke.
docker compose exec -T cli wp eval 'file_put_contents( BACKUPLY_BACKUP_DIR . "backuply_backup_log.php", "<?php exit();?>\nCould not write archive|error|100\nBackup failed|error|100\n" );' >/dev/null 2>&1
call bky_fail '{"jsonrpc":"2.0","id":245,"method":"tools/call","params":{"name":"wp_backup_status","arguments":{}}}'
check "a failed last run is read out of the log rather than assumed fine" \
  "$(bky "str(d['last_succeeded']) + ',' + str(d['last_errors'])" bky_fail)" "False,2"
check "and the summary says not to rely on it" \
  "$(bky "'yes' if 'do not rely on it' in d['summary'] else 'no'" bky_fail)" "yes"

# The confirmation line names whichever provider was found, not a hardcoded one.
call bky_confirm '{"jsonrpc":"2.0","id":246,"method":"tools/call","params":{"name":"wp_delete_plugin","arguments":{"plugin":"akismet/akismet.php"}}}'
check "a destructive confirmation carries Backuply's state too" "$(refusal bky_confirm | grep -c 'Backuply backup')" "1"

# Adding a provider must not move an existing site onto it. UpdraftPlus is listed first
# and stays first.
docker compose exec -T cli wp plugin activate updraftplus >/dev/null 2>&1
call bky_order '{"jsonrpc":"2.0","id":247,"method":"tools/call","params":{"name":"wp_backup_status","arguments":{}}}'
check "with both active the older provider still wins" "$(bky "d['provider']" bky_order)" "UpdraftPlus"

echo "-- listing backups without handing out the keys to them --"
# The listing exists so an agent can see whether a recent backup is worth relying on. What
# it must never hand over is anything a caller could turn into a URL. On UpdraftPlus the
# archive name carries a 12-hex-character job nonce, and that nonce is the whole
# protection: the .htaccess it sits behind says "deny from all", which nginx never reads.
# Backuply inverts it, with a filename derivable from the timestamp inside a directory
# whose 6-character wp_generate_password suffix is the secret, shared by every backup on
# the site. Neither secret is derivable from a completion time, which is why the time is
# what identifies a backup here.
#
# Both providers get a backup made here rather than inherited from the sections above.
# Reaching this point with one already present is likely but not guaranteed, and a
# listing test whose subject arrived by luck is a listing test that reports luck.
docker compose exec -T cli wp eval 'do_action( "updraft_backupnow_backup_all", [ "nocloud" => 0 ] );' >/dev/null 2>&1
for _ in 1 2 3 4 5 6 7 8 9 10; do
  [ "$(docker compose exec -T cli wp eval 'echo count( (array) UpdraftPlus_Backup_History::get_history() );' 2>/dev/null | tr -d '\r\n')" != "0" ] && break
  sleep 3
done
# A second Backuply backup, so "newest first" has something to order. Database only, which
# also proves contains reflects the job rather than being hardcoded.
docker compose exec -T cli wp eval '
  backuply_create_log_file();
  update_option( "backuply_backup_stopped", false );
  update_option( "backuply_status", [ "backup_dir" => "", "backup_db" => "1", "backup_location" => "" ] );
  do_action( "backuply_backup_cron" );
' >/dev/null 2>&1

call lb_up '{"jsonrpc":"2.0","id":250,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{}}}'
lb() { py "import json,sys;d=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print($1)" "$2"; }
check "UpdraftPlus backups are listed" "$(lb "str(d['can_list']) + ',' + str(d['total'] >= 1)" lb_up)" "True,True"
check "each dated entry is identified by when it finished" \
  "$(lb "str(all(isinstance(b['completed'], int) and b['completed'] > 0 for b in d['backups'] if b['completed'] is not None))" lb_up)" "True"
# Every entry says where it went, and every entry either lists contents or says why it has
# none. The earlier form of this demanded contents unconditionally, which failed the moment
# UpdraftPlus retention emptied a set the suite itself had pushed out of the window.
check "and each says where it went, or why it holds nothing" \
  "$(lb "str(all(b['destination'] and (b['contains'] or b.get('note')) for b in d['backups']))" lb_up)" "True"

# UpdraftPlus backs up mu-plugins as a first-class component, and the component list is
# open to add-ons through updraftplus_backupable_file_entities. A hardcoded five-entry map
# dropped mu-plugins from both the contents and the size on every real backup.
check "control: the stored history really does carry mu-plugins" \
  "$(docker compose exec -T cli wp eval '$h = (array) UpdraftPlus_Backup_History::get_history(); $n = 0; foreach ( $h as $s ) { $s = (array) $s; if ( !empty( $s["mu-plugins"] ) ) { $n++; } } echo $n > 0 ? 1 : 0;' 2>/dev/null | tr -d '\r\n')" "1"
check "and a set holding mu-plugins says so" \
  "$(lb "str(any('mu-plugins' in b['contains'] for b in d['backups']))" lb_up)" "True"
# A set whose archives retention has removed is still in the history. Counting it as a
# backup is the false yes this whole file is arranged against, so the reply separates the
# ones that could actually put the site back.
check "and the reply counts how many hold a database" \
  "$(lb "str(d['with_database'] <= d['shown'])" lb_up)" "True"
check "and says so in the summary when some do not" \
  "$(lb "'yes' if (d['with_database'] == d['shown']) or ('cannot put the site back' in d['summary']) else 'no'" lb_up)" "yes"

# A scan for something that must be absent is worth nothing without a control proving the
# thing exists and is findable. Both halves are asserted, in that order.
UP_NONCE=$(docker compose exec -T cli wp eval '$h = (array) UpdraftPlus_Backup_History::get_history(); $e = $h ? (array) reset( $h ) : []; echo (string) ( $e["nonce"] ?? "" );' 2>/dev/null | tr -d '\r\n')
check "control: UpdraftPlus really does have a nonce to leak" "$(printf '%s' "$UP_NONCE" | grep -cE '^[0-9a-f]{8,}$')" "1"
check "and it is nowhere in the listing" "$(grep -c "$UP_NONCE" "$OUT/lb_up")" "0"
# Filename shapes only. "updraft" is not in this pattern: the provider's own name is in
# every reply by design, and a pattern that matched it would fail on the name rather than
# on a leak, which is a check that cannot tell the two apart.
check "nor is any archive filename" "$(grep -ciE 'backup_[0-9]{4}-|\.zip|\.gz|\.tar' "$OUT/lb_up")" "0"
check "control: the stored history does contain that filename" \
  "$(docker compose exec -T cli wp eval '$h = (array) UpdraftPlus_Backup_History::get_history(); $e = $h ? (array) reset( $h ) : []; echo empty( $e["db"] ) && empty( $e["mu-plugins"] ) ? 0 : 1;' 2>/dev/null | tr -d '\r\n')" "1"

# Backuply, whose archives differ in what is secret and must be withheld all the same.
docker compose exec -T cli wp plugin deactivate updraftplus >/dev/null 2>&1
call lb_bky '{"jsonrpc":"2.0","id":251,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{}}}'
check "Backuply backups are listed too" "$(lb "str(d['provider']) + ',' + str(d['can_list'])" lb_bky)" "Backuply,True"
BKY_DIR=$(docker compose exec -T cli wp eval 'echo basename( (string) backuply_glob( "backups" ) );' 2>/dev/null | tr -d '\r\n')
BKY_NAME=$(docker compose exec -T cli wp eval '$i = backuply_get_backups_info(); echo $i ? (string) $i[0]->name : "";' 2>/dev/null | tr -d '\r\n')
check "control: Backuply's archive directory has a random suffix" \
  "$(printf '%s' "$BKY_DIR" | grep -cE '^backups-.+$')" "1"
check "and that directory is nowhere in the listing" "$(grep -c "$BKY_DIR" "$OUT/lb_bky")" "0"
# Backuply's own filename shape, which the earlier pattern missed entirely: it looks for
# "tar.gz" and the directory, and Backuply's basename contains neither, so an adapter
# handing back the name passed this section untouched.
check "control: Backuply really does record a wp__<date> archive name" \
  "$(printf '%s' "$BKY_NAME" | grep -cE '^wp__[0-9]{4}-[0-9]{2}-[0-9]{2}_')" "1"
check "and that name is nowhere in the listing" "$(grep -c "$BKY_NAME" "$OUT/lb_bky")" "0"
check "nor is any wp__<date> or tar.gz shape at all" "$(grep -ciE 'wp__[0-9]{4}-|tar\.gz' "$OUT/lb_bky")" "0"

# Newest first, and exact about how many exist. truncated used to be derived from how many
# came back, which cannot tell "that is all of them" from "the adapter stopped there".
call lb_two '{"jsonrpc":"2.0","id":252,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{"limit":100}}}'
check "there are at least two Backuply backups to order" "$(lb "str(d['total'] >= 2)" lb_two)" "True"
# A sort assertion over a list whose values are all equal passes on any order, because
# Python's sort is stable. The control asserts the timestamps actually differ, so the
# comparison below is doing work.
check "control: the timestamps to order by are actually distinct" \
  "$(lb "str(len({b['completed'] for b in d['backups'] if b['completed']}) >= 2)" lb_two)" "True"
check "and they come back newest first" \
  "$(lb "str([b['completed'] for b in d['backups'] if b['completed']] == sorted([b['completed'] for b in d['backups'] if b['completed']], reverse=True))" lb_two)" "True"
TOTAL=$(py "import json,sys;print(json.loads(json.load(sys.stdin)['result']['content'][0]['text'])['total'])" lb_two)
call lb_one '{"jsonrpc":"2.0","id":253,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{"limit":1}}}'
check "a capped listing says it was capped and how many exist" "$(lb "str(d['shown']) + ',' + str(d['truncated']) + ',' + str(d['total'])" lb_one)" "1,True,$TOTAL"
# The case the old contract got wrong in the reassuring direction: asking for exactly as
# many as exist must not claim there may be older ones.
call lb_exact "{\"jsonrpc\":\"2.0\",\"id\":258,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_list_backups\",\"arguments\":{\"limit\":$TOTAL}}}"
check "asking for exactly as many as exist is not called truncated" "$(lb "str(d['truncated'])" lb_exact)" "False"
check "and the summary does not offer to look further back" "$(lb "'yes' if 'raise limit' not in d['summary'] else 'no'" lb_exact)" "yes"
call lb_big '{"jsonrpc":"2.0","id":254,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{"limit":9999}}}'
check "an absurd limit is clamped and the real one reported" "$(lb "d['limit']" lb_big)" "100"
call lb_zero '{"jsonrpc":"2.0","id":255,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{"limit":-5}}}'
check "and so is a negative one" "$(lb "d['limit']" lb_zero)" "1"

echo "-- a listing is reduced to shape, not asked to behave --"
# gmcp_backup_providers is a public filter, so the no-filenames rule cannot be a sentence
# in a summary: an adapter registered by any plugin would have been passed through
# untouched and then had that sentence appended to it. The audit log stores the reply, so
# a leak there is written to the database and readable afterwards. This plants an adapter
# that returns everything the rule forbids and asserts none of it survives.
# The cli container runs as uid 33 and wp-content/mu-plugins can be root-owned, depending
# on what created it. The write then fails silently and the probe never loads, which the
# control below catches as "Backuply answered" rather than as a missing file. Claim the
# directory first so the section works on any stack.
docker compose exec -T --user root wp mkdir -p /var/www/html/wp-content/mu-plugins >/dev/null 2>&1
docker compose exec -T --user root wp chown 33:33 /var/www/html/wp-content/mu-plugins >/dev/null 2>&1
docker compose exec -T cli bash -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/gmcp-probe-provider.php <<"PROBE"
<?php
add_filter( "gmcp_backup_providers", function ( $p ) {
  return [ "probe" => [
    "name" => "Probe",
    "installed" => function () { return true; },
    "start" => null,
    "state" => null,
    "list" => function () {
      return [
        "wp-content/updraft/backup_2026-01-01-0000_Site_deadbeefcafe-db.gz",
        [ "completed" => time(), "contains" => [ "database" ], "size_bytes" => 10,
          "destination" => "wp-content/backuply/backups-SECRET/wp__2026-01-01_00-00-00.tar.gz",
          "path" => "/var/www/html/wp-content/updraft/backup_deadbeefcafe-db.gz",
          "filename" => "backup_2026-01-01-0000_Site_deadbeefcafe-db.gz",
          "note" => "fetch it from /wp-content/updraft/backup_deadbeefcafe-db.gz" ],
      ];
    },
  ] ];
}, 99 );
PROBE
echo ok' >/dev/null 2>&1
call lb_probe '{"jsonrpc":"2.0","id":256,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{}}}'
check "control: the planted adapter really is the one answering" "$(lb "d['provider']" lb_probe)" "Probe"
check "its path-shaped destination is withheld" \
  "$(lb "str(all('withheld' in b['destination'] for b in d['backups']))" lb_probe)" "True"
check "so is its path-shaped note" \
  "$(lb "str(all('withheld' in (b.get('note') or 'withheld') for b in d['backups']))" lb_probe)" "True"
check "keys nobody designed are dropped rather than rendered" \
  "$(lb "str(any('path' in b or 'filename' in b for b in d['backups']))" lb_probe)" "False"
check "and an entry that is not a record at all is dropped and counted" \
  "$(lb "str(d.get('unreadable'))" lb_probe)" "1"
# The whole point, asserted against the raw bytes rather than against parsed fields.
check "no planted secret survives anywhere in the reply" \
  "$(grep -ciE 'deadbeefcafe|backups-SECRET|wp-content/|\.gz|tar\.gz' "$OUT/lb_probe")" "0"
# And the audit log, which stores the reply, therefore has none of it either.
check "and none of it reaches the audit log" \
  "$(docker compose exec -T cli wp eval 'global $wpdb; $a = implode( "", (array) $wpdb->get_col( "SELECT COALESCE(detail,\"\") FROM {$wpdb->prefix}gmcp_audit" ) ); echo strpos( $a, "deadbeefcafe" ) === false ? 0 : 1;' 2>/dev/null | tr -d '\r\n')" "0"
docker compose exec -T cli rm -f /var/www/html/wp-content/mu-plugins/gmcp-probe-provider.php >/dev/null 2>&1

# An adapter registered without a name must not answer with the value that means "no
# backup plugin recognised". Those are opposite situations and they read identically.
docker compose exec -T cli bash -c 'cat > /var/www/html/wp-content/mu-plugins/gmcp-noname-provider.php <<"NONAME"
<?php
add_filter( "gmcp_backup_providers", function ( $p ) {
  return [ "nameless" => [
    "installed" => function () { return true; },
    "start" => null, "state" => null, "list" => null,
  ] ];
}, 99 );
NONAME
echo ok' >/dev/null 2>&1
call lb_noname '{"jsonrpc":"2.0","id":262,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{}}}'
check "an unnamed adapter is not reported as no adapter at all" \
  "$(lb "str(d['provider'] is not None)" lb_noname)" "True"
check "and it falls back to the slug it was registered under" "$(lb "d['provider']" lb_noname)" "nameless"
check "and its summary does not start on a bare space" \
  "$(lb "str(d['summary'][:1] != ' ')" lb_noname)" "True"
docker compose exec -T cli rm -f /var/www/html/wp-content/mu-plugins/gmcp-noname-provider.php >/dev/null 2>&1

echo "-- a listing survives records it cannot read --"
# Backuply pushes json_decode()'s return without checking it, so an info file truncated
# mid-write whose archive survived puts a null in the list. That used to reach an object
# type hint and take out the listing for every other backup on the site, with the plugin's
# absolute path in the error message. An undated record used to render as 1970 and an age
# of half a million hours beside a note saying no time was recorded.
# Counted before planting, because how many backups earlier sections left behind is not
# something this one should assume. An absolute number here failed the moment the section
# above it reset Backuply.
call lb_pre '{"jsonrpc":"2.0","id":261,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{"limit":100}}}'
LB_PRE=$(lb "d['total']" lb_pre)
docker compose exec -T cli bash -c 'D=$(ls -d /var/www/html/wp-content/backuply/backups_info-*); A=$(ls -d /var/www/html/wp-content/backuply/backups-*);
printf "<?php exit();?>\n{\n  \"name\": \"wp__2026-09-10_00-00-00\",\n  \"backup_di" > "$D/wp__2026-09-10_00-00-00.php"
printf "partial" > "$A/wp__2026-09-10_00-00-00.tar.gz"
printf "<?php exit();?>\n{\"name\":\"wp__2026-09-05_00-00-00\",\"backup_dir\":\"1\",\"backup_db\":\"1\",\"ext\":\"tar.gz\",\"size\":12345}" > "$D/wp__2026-09-05_00-00-00.php"
printf "partial" > "$A/wp__2026-09-05_00-00-00.tar.gz"' >/dev/null 2>&1
call lb_bad '{"jsonrpc":"2.0","id":257,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{"limit":100}}}'
check "one unreadable record does not take out the listing" "$(verdict lb_bad)" "ok"
check "control: both planted records really are in the listing" "$(lb "d['total']" lb_bad)" "$((LB_PRE + 2))"
check "an undated entry reports no time rather than 1970" \
  "$(lb "str(all(b['completed_gmt'] is None and b['age_hours'] is None for b in d['backups'] if b['completed'] is None))" lb_bad)" "True"
check "and there really is such an entry, so that means something" \
  "$(lb "str(any(b['completed'] is None for b in d['backups']))" lb_bad)" "True"
check "each undated entry says why it has no time" \
  "$(lb "str(all(b.get('note') for b in d['backups'] if b['completed'] is None))" lb_bad)" "True"
check "and no filesystem path appears in the reply" "$(grep -c 'wp-content/plugins' "$OUT/lb_bad")" "0"
docker compose exec -T cli bash -c 'rm -f /var/www/html/wp-content/backuply/backups_info-*/wp__2026-09-10_00-00-00.php /var/www/html/wp-content/backuply/backups-*/wp__2026-09-10_00-00-00.tar.gz /var/www/html/wp-content/backuply/backups_info-*/wp__2026-09-05_00-00-00.php /var/www/html/wp-content/backuply/backups-*/wp__2026-09-05_00-00-00.tar.gz' >/dev/null 2>&1


# With nothing it can enumerate, an empty list would be a lie. There must be no list.
docker compose exec -T cli wp plugin deactivate backuply >/dev/null 2>&1
call lb_none '{"jsonrpc":"2.0","id":260,"method":"tools/call","params":{"name":"wp_list_backups","arguments":{}}}'
check "with no provider it says it cannot list" "$(lb "str(d['can_list'])" lb_none)" "False"
check "and returns no backups key at all, rather than an empty one" \
  "$(lb "str('backups' in d)" lb_none)" "False"
check "and does not claim there are none" \
  "$(lb "'yes' if 'not the same as there being no backups' in d['summary'] else 'no'" lb_none)" "yes"
docker compose exec -T cli wp plugin activate updraftplus backuply >/dev/null 2>&1

echo "-- backups: what it says when it cannot tell --"
# A site running something this plugin cannot read still has backups. Saying "no backups"
# there would be a lie in the direction that gets somebody hurt. Both drivable providers
# come off: leaving one on would test the wrong branch and still pass.
docker compose exec -T cli wp plugin deactivate updraftplus backuply >/dev/null 2>&1
call bk_none '{"jsonrpc":"2.0","id":233,"method":"tools/call","params":{"name":"wp_backup_status","arguments":{}}}'
check "with no provider it admits it cannot tell" \
  "$(py "import json,sys;d=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(str(d['can_tell']))" bk_none)" "False"
check "and does not claim there are no backups" \
  "$(py "import json,sys;d=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print('yes' if 'not the same as there being none' in d['summary'] else 'no')" bk_none)" "yes"
call bk_nostart '{"jsonrpc":"2.0","id":234,"method":"tools/call","params":{"name":"wp_start_backup","arguments":{}}}'
check "and starting refuses rather than reporting success" "$(verdict bk_nostart)" "error"
docker compose exec -T cli wp plugin activate updraftplus backuply >/dev/null 2>&1

call bk_tools '{"jsonrpc":"2.0","id":236,"method":"tools/call","params":{"name":"tools/list"}}'
call bk_list '{"jsonrpc":"2.0","id":237,"method":"tools/list"}'
# Restore is absent on purpose: it discards everything since the backup, which is a larger
# irreversible act than anything else here, and no confirmation token makes that safe.
check "there is no restore tool at any level" \
  "$(py "import json,sys;t=[x['name'] for x in json.load(sys.stdin)['result']['tools']];print(len([n for n in t if 'restore' in n]))" bk_list)" "0"
echo "-- the audit log says what changed, not only what was called --"
# An entry recording that wp_update_post ran on post 12 does not say the post went from
# private to publish, which is usually what somebody is looking for. Every check here
# reads the stored row rather than the reply, and asserts the real state first: a
# summary describing a change that did not happen is worse than no summary at all.
docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1
changes_of() { audit_q "SELECT changes FROM {\$wpdb->prefix}gmcp_audit WHERE tool=\\\"$1\\\" ORDER BY id DESC LIMIT 1"; }
# A scan for something that must be absent needs a control that finds something present,
# or an empty column reports every secret safe.
leaked_changes() { docker compose exec -T cli wp eval 'global $wpdb;$a=implode("",$wpdb->get_col("SELECT COALESCE(changes,\"\") FROM {$wpdb->prefix}gmcp_audit"));echo strpos($a,"'"$1"'")===false?0:1;' 2>/dev/null | tr -d '\r\n'; }
have_changes_column() { docker compose exec -T cli wp eval 'global $wpdb;echo $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}gmcp_audit LIKE \"changes\"") ? "yes" : "no";' 2>/dev/null | tr -d '\r\n'; }

C_POST=$(docker compose exec -T cli wp post create --post_title='Diff subject' --post_status=private --post_content='<p>Body that stays put.</p>' --porcelain 2>/dev/null | tr -d '\r\n')
check "the seed landed, and landed private" \
  "$(docker compose exec -T cli wp post get "$C_POST" --field=post_status 2>/dev/null | tr -d '\r\n')" "private"
call c_pub "{\"jsonrpc\":\"2.0\",\"id\":220,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_post\",\"arguments\":{\"ID\":$C_POST,\"post_status\":\"publish\",\"post_title\":\"Diff subject renamed\"}}}"
check "the write really happened" \
  "$(docker compose exec -T cli wp post get "$C_POST" --field=post_status 2>/dev/null | tr -d '\r\n')" "publish"
C_CH=$(changes_of wp_update_post)
check "the status change is recorded with both sides" \
  "$(echo "$C_CH" | grep -c '"post_status":{"from":"private","to":"publish"}')" "1"
check "so is the title change" \
  "$(echo "$C_CH" | grep -c '"post_title":{"from":"Diff subject","to":"Diff subject renamed"}')" "1"
# The two checks above are the control for this one: the same scan over the same string
# finds the fields that did change, so a zero here is absence rather than a blind scan.
check "the body, which did not change, is not mentioned" "$(echo "$C_CH" | grep -c 'post_content')" "0"

# Field-level detail is only affordable because long values are described, not copied.
C_BIG=$(python3 -c "print('y'*5000)")
call c_grow "{\"jsonrpc\":\"2.0\",\"id\":221,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_post\",\"arguments\":{\"ID\":$C_POST,\"post_content\":\"$C_BIG\"}}}"
check "the long body really was written" \
  "$(docker compose exec -T cli wp eval "echo strlen( get_post( $C_POST )->post_content );" 2>/dev/null | tr -d '\r\n')" "5000"
C_CH2=$(changes_of wp_update_post)
check "a long value is recorded as its size, not copied" "$(echo "$C_CH2" | grep -c 'of text\]')" "1"
check "and those 5000 characters are not in the row" "$(echo "$C_CH2" | grep -c 'yyyyyyyyyy')" "0"
docker compose exec -T cli wp post delete "$C_POST" --force >/dev/null 2>&1

# wp_update_user takes a password. That it changed is worth recording; the value never is.
call c_user '{"jsonrpc":"2.0","id":222,"method":"tools/call","params":{"name":"wp_create_user","arguments":{"user_login":"diffuser","user_email":"diff@example.test","user_pass":"CREATEPASS_MUSTNOTAPPEAR","role":"subscriber"}}}'
C_UID=$(docker compose exec -T cli wp user get diffuser --field=ID 2>/dev/null | tr -d '\r\n')
check "the user was really created" "$(test -n "$C_UID" && echo yes || echo no)" "yes"
call c_pass "{\"jsonrpc\":\"2.0\",\"id\":223,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_user\",\"arguments\":{\"ID\":$C_UID,\"fields\":{\"user_pass\":\"NEWPASS_MUSTNOTAPPEAR\",\"display_name\":\"Diff Person\"}}}}"
check "the display name really changed" \
  "$(docker compose exec -T cli wp user get "$C_UID" --field=display_name 2>/dev/null | tr -d '\r\n')" "Diff Person"
C_CH3=$(changes_of wp_update_user)
check "the password change is recorded as having happened" \
  "$(echo "$C_CH3" | grep -c '"user_pass":{"from":"\[redacted\]","to":"\[redacted\]"}')" "1"
check "and the harmless field beside it is recorded in full" "$(echo "$C_CH3" | grep -c 'Diff Person')" "1"
check "no password reaches the changes column" "$(leaked_changes NEWPASS_MUSTNOTAPPEAR)" "0"
check "nor the one the account was created with" "$(leaked_changes CREATEPASS_MUSTNOTAPPEAR)" "0"
check "but an ordinary changed value does, so the scan works" "$(leaked_changes 'Diff Person')" "1"
# A role change is the user write that matters most, and it arrives on its own hook.
call c_role "{\"jsonrpc\":\"2.0\",\"id\":224,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_user\",\"arguments\":{\"ID\":$C_UID,\"fields\":{\"role\":\"editor\"}}}}"
check "the promotion really happened" \
  "$(docker compose exec -T cli wp user get "$C_UID" --field=roles 2>/dev/null | tr -d '\r\n')" "editor"
check "and is recorded from and to" \
  "$(changes_of wp_update_user | grep -c '"roles":{"from":"subscriber","to":"editor"}')" "1"
docker compose exec -T cli wp user delete "$C_UID" --yes >/dev/null 2>&1

# A credential inside an option value is refused by the names inside it, both sides.
docker compose exec -T cli wp option update diff_gw --format=json '{"mode":"live","secret_key":"OPTSEED_MUSTNOTAPPEAR"}' >/dev/null 2>&1
call c_gw '{"jsonrpc":"2.0","id":225,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"diff_gw","value":{"mode":"test","secret_key":"OPTNEW_MUSTNOTAPPEAR"}}}}'
check "the option write really happened" \
  "$(docker compose exec -T cli wp eval 'echo get_option("diff_gw")["mode"];' 2>/dev/null | tr -d '\r\n')" "test"
check "neither side of a credential-shaped value is recorded" \
  "$(changes_of wp_update_option | grep -c '"value":{"from":"\[redacted\]","to":"\[redacted\]"}')" "1"
check "the previous secret is not in the column" "$(leaked_changes OPTSEED_MUSTNOTAPPEAR)" "0"
check "nor the new one" "$(leaked_changes OPTNEW_MUSTNOTAPPEAR)" "0"
docker compose exec -T cli wp option delete diff_gw >/dev/null 2>&1

# Where, and not in storage terms. A menu is a term and a menu item is a post, and an
# entry that says so makes the reader do the translating.
call c_menu '{"jsonrpc":"2.0","id":226,"method":"tools/call","params":{"name":"wp_create_menu","arguments":{"name":"Diff menu"}}}'
check "the menu really exists" \
  "$(docker compose exec -T cli wp eval 'echo wp_get_nav_menu_object("Diff menu") ? "yes" : "no";' 2>/dev/null | tr -d '\r\n')" "yes"
check "a menu is recorded as a menu, not as a nav_menu term" \
  "$(changes_of wp_create_menu | grep -c '"what":"menu ')" "1"
call c_item '{"jsonrpc":"2.0","id":227,"method":"tools/call","params":{"name":"wp_add_menu_item","arguments":{"menu":"Diff menu","title":"Diff item","type":"custom","url":"https://example.test"}}}'
check "the item really exists" \
  "$(docker compose exec -T cli wp eval 'echo count( wp_get_nav_menu_items( "Diff menu" ) );' 2>/dev/null | tr -d '\r\n')" "1"
check "a menu item is recorded as a menu item, not as a post" \
  "$(changes_of wp_add_menu_item | grep -c '"what":"menu item ')" "1"

# An attachment is a post that fires neither post_updated nor wp_insert_post, so a
# listener on those alone recorded nothing for any media write at all.
call c_media '{"jsonrpc":"2.0","id":231,"method":"tools/call","params":{"name":"wp_upload_media","arguments":{"filename":"diff.gif","base64":"R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7","title":"Diff image"}}}'
C_ATT=$(docker compose exec -T cli wp eval 'echo (int) ( get_posts( [ "post_type" => "attachment", "numberposts" => 1, "fields" => "ids" ] )[0] ?? 0 );' 2>/dev/null | tr -d '\r\n')
check "the upload really landed" \
  "$(docker compose exec -T cli wp eval "echo get_post( $C_ATT )->post_title;" 2>/dev/null | tr -d '\r\n')" "Diff image"
check "a media write is recorded at all" "$(changes_of wp_upload_media | grep -c '"what":"media ')" "1"
call c_rename "{\"jsonrpc\":\"2.0\",\"id\":232,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_media\",\"arguments\":{\"ID\":$C_ATT,\"title\":\"Diff image renamed\"}}}"
check "the rename really happened" \
  "$(docker compose exec -T cli wp eval "echo get_post( $C_ATT )->post_title;" 2>/dev/null | tr -d '\r\n')" "Diff image renamed"
check "and the rename is recorded from and to" \
  "$(changes_of wp_update_media | grep -c '"post_title":{"from":"Diff image","to":"Diff image renamed"}')" "1"
docker compose exec -T cli wp post delete "$C_ATT" --force >/dev/null 2>&1

# The reader side: the tool has to hand the detail on, and has to be honest about what
# the account name means. A bearer caller borrows one administrator, so it is not a who.
call c_read '{"jsonrpc":"2.0","id":228,"method":"tools/call","params":{"name":"wp_get_audit_log","arguments":{"limit":25}}}'
check "the audit tool returns the recorded changes" \
  "$(py "import json,sys;d=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(any(e.get('changed') for e in d['entries']))" c_read)" "True"
check "and names the caller and the account separately" \
  "$(py "import json,sys;d=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);e=d['entries'][0];print('called_by' in e and 'acted_as' in e and 'actor' not in e)" c_read)" "True"
check "and says what the account name does not mean" \
  "$(py "import json,sys;d=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print('does not identify a person' in d.get('about_identity',''))" c_read)" "True"
# A shared token names no client, and an empty column is less use than the method.
check "a shared-token call is attributed to something" \
  "$(audit_q 'SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit WHERE client = \"\"')" "0"

# The chain has to cover the new column while still covering rows written before it
# existed. Both cases, explicitly, because the argument is easier to get right on paper
# than in code.
check "rows with and without recorded changes chain together" \
  "$(docker compose exec -T cli wp eval '$v=GMCP_Audit::verify();echo $v["ok"]?"intact":"BROKEN at ".$v["broken_at"];' 2>/dev/null | tr -d '\r\n')" "intact"
check "and both kinds are really present, so that verdict means something" \
  "$(audit_q 'SELECT CONCAT(SUM(changes IS NULL) > 0, \"-\", SUM(changes IS NOT NULL) > 0) FROM {$wpdb->prefix}gmcp_audit')" "1-1"
# Blanking the column is the way out a per-row hash could leave open: the recomputation
# has to notice that what was hashed is no longer there.
C_ROW=$(audit_q 'SELECT id FROM {$wpdb->prefix}gmcp_audit WHERE changes IS NOT NULL ORDER BY id LIMIT 1')
check "there is a recorded change to tamper with" "$(test -n "$C_ROW" && echo yes || echo no)" "yes"
check "blanking a recorded change is detected" \
  "$(docker compose exec -T cli wp eval "global \$wpdb;\$t=\$wpdb->prefix.\"gmcp_audit\";\$wpdb->query(\"UPDATE \$t SET changes=NULL WHERE id=$C_ROW\");\$v=GMCP_Audit::verify();echo \$v['ok']?'MISSED':'detected at '.\$v['broken_at'];" 2>/dev/null | tr -d '\r\n')" \
  "detected at $C_ROW"

# A hash that commits only to the contents of a row and not to its shape can be spliced:
# move the recorded changes onto the end of the detail column behind a separator, blank
# the column, and a recomputation over one field fewer rebuilds the same string. The chain
# would call the row intact while the changes it covered had been erased. Caught now, and
# the control below is what makes "caught" mean something: it computes the same splice
# under the older construction and shows it accepted there.
docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1
call c_splice1 '{"jsonrpc":"2.0","id":233,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"blogdescription","value":"spliceable"}}}'
call c_splice2 '{"jsonrpc":"2.0","id":234,"method":"tools/call","params":{"name":"wp_get_posts","arguments":{"limit":1}}}'
C_ROW=$(audit_q 'SELECT id FROM {$wpdb->prefix}gmcp_audit WHERE changes IS NOT NULL ORDER BY id LIMIT 1')
check "there is a row carrying changes to splice" "$(test -n "$C_ROW" && echo yes || echo no)" "yes"
check "control: the splice is accepted by a hash over contents alone" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;$t=$wpdb->prefix."gmcp_audit";$r=$wpdb->get_row("SELECT * FROM $t WHERE changes IS NOT NULL ORDER BY id LIMIT 1",ARRAY_A);$p=(string)$r["prev_hash"];$e=["ts","actor","actor_name","client","auth_method","tool","target","outcome","ms","args","detail"];$j=function($x,$f) use ($p){$o=[];foreach($f as $k){$o[]=(string)($x[$k] ?? "");}return hash("sha256",$p."\x1f".implode("\x1f",$o));};$s=$r;$s["detail"]=(string)$r["detail"]."\x1f".(string)$r["changes"];$s["changes"]=null;echo $j($s,$e)===$j($r,array_merge($e,["changes"]))?"accepted":"rejected";' 2>/dev/null | tr -d '\r\n')" "accepted"
check "but the chain as shipped refuses it" \
  "$(docker compose exec -T cli wp eval "global \$wpdb;\$t=\$wpdb->prefix.\"gmcp_audit\";\$r=\$wpdb->get_row(\"SELECT id,detail,changes FROM \$t WHERE id=$C_ROW\",ARRAY_A);\$wpdb->update(\$t,['detail'=>(string)\$r['detail'].\"\x1f\".(string)\$r['changes'],'changes'=>null],['id'=>$C_ROW]);\$v=GMCP_Audit::verify();echo \$v['ok']?'MISSED':'detected at '.\$v['broken_at'];" 2>/dev/null | tr -d '\r\n')" \
  "detected at $C_ROW"
# The refusal has to be about the splice rather than about the blanking on its own, so
# assert the row really is holding the separator and the changes it swallowed.
check "and the splice really was written" \
  "$(audit_q "SELECT changes IS NULL AND INSTR(detail, CHAR(31)) > 0 FROM {\$wpdb->prefix}gmcp_audit WHERE id=$C_ROW")" "1"

# Rows written before the canonical encoding have to keep verifying under the construction
# that signed them, or every upgraded site is told on day one that its log was tampered
# with. Built here rather than assumed: rows hashed the old way, the boundary set to the
# last of them, then a real call on top.
docker compose exec -T cli wp eval 'GMCP_Audit::clear();
  global $wpdb; $t=$wpdb->prefix."gmcp_audit"; $prev="";
  foreach ( [ "wp_get_posts", "wp_update_option", "wp_get_users" ] as $i => $tool ) {
    $row = [ "ts"=>gmdate("Y-m-d H:i:s"), "actor"=>1, "actor_name"=>"admin", "client"=>"bearer",
      "auth_method"=>"bearer", "tool"=>$tool, "target"=>"t".$i, "outcome"=>"ok", "ms"=>1,
      "args"=>"{}", "detail"=>"", "changes"=>null ];
    $parts = [];
    foreach ( ["ts","actor","actor_name","client","auth_method","tool","target","outcome","ms","args","detail"] as $f ) { $parts[] = (string) $row[$f]; }
    $row["prev_hash"] = $prev;
    $row["hash"] = $prev = hash("sha256", $prev."\x1f".implode("\x1f",$parts));
    $wpdb->insert($t,$row);
  }
  update_option("gmcp_audit_hash_boundary",(int)$wpdb->get_var("SELECT MAX(id) FROM $t"),false);' >/dev/null 2>&1
check "the older rows were really written the old way" \
  "$(audit_q 'SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit')" "3"
check "and they verify" \
  "$(docker compose exec -T cli wp eval '$v=GMCP_Audit::verify();echo $v["ok"]?"intact ".$v["checked"]:"BROKEN at ".$v["broken_at"];' 2>/dev/null | tr -d '\r\n')" "intact 3"
call c_after '{"jsonrpc":"2.0","id":235,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"blogdescription","value":"after the boundary"}}}'
check "a new row lands above the boundary" \
  "$(audit_q 'SELECT COUNT(*) > 3 FROM {$wpdb->prefix}gmcp_audit')" "1"
check "and old and new constructions verify in one chain" \
  "$(docker compose exec -T cli wp eval '$v=GMCP_Audit::verify();echo $v["ok"]?"intact":"BROKEN at ".$v["broken_at"];' 2>/dev/null | tr -d '\r\n')" "intact"
# The same chain under a full walk, since the windowed default and the whole-table scope
# start in different places and only the second one begins below the boundary mark.
check "and under a full walk, which reports its own coverage" \
  "$(docker compose exec -T cli wp eval '$v=GMCP_Audit::verify("all");echo $v["ok"] && $v["complete"] ? "intact and complete" : "BROKEN";' 2>/dev/null | tr -d '\r\n')" "intact and complete"
check "and the walk really crossed the mark, so that verdict covers both constructions" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;$b=(int)get_option("gmcp_audit_hash_boundary");$t=$wpdb->prefix."gmcp_audit";echo ((int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE id <= $b") > 0 && (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE id > $b") > 0) ? "both sides" : "ONE SIDE ONLY";' 2>/dev/null | tr -d '\r\n')" "both sides"
# Clearing resets the boundary, or the ids TRUNCATE hands back would be checked the old
# way and every new row would read as tampered with.
docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1
check "clearing resets the boundary" \
  "$(docker compose exec -T cli wp eval 'echo (int) get_option("gmcp_audit_hash_boundary");' 2>/dev/null | tr -d '\r\n')" "0"
call c_fresh '{"jsonrpc":"2.0","id":236,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"blogdescription","value":"after the clear"}}}'
check "and rows written after a clear still verify" \
  "$(docker compose exec -T cli wp eval '$v=GMCP_Audit::verify();echo $v["ok"]?"intact":"BROKEN at ".$v["broken_at"];' 2>/dev/null | tr -d '\r\n')" "intact"

echo "-- reading the log: filtered counts, one entry, one entry's own verdict --"
# The screen now pages through the log rather than showing a fixed fifty, which makes
# these four things load-bearing in a way they were not before. All of it is asserted
# through wp eval rather than by scraping the rendered page: markup changes, and these
# are the parts that would go wrong silently if they changed.

# count() took a filter array and ignored it, returning the whole table however the list
# had been narrowed. Nothing noticed while the screen quoted the total separately. A
# pager divides one by the other, so a wrong count means pages of rows that do not exist.
docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1
call rl_ok '{"jsonrpc":"2.0","id":300,"method":"tools/call","params":{"name":"wp_get_posts","arguments":{"limit":1}}}'
call rl_ok2 '{"jsonrpc":"2.0","id":301,"method":"tools/call","params":{"name":"wp_get_posts","arguments":{"limit":1}}}'
call rl_no '{"jsonrpc":"2.0","id":302,"method":"tools/call","params":{"name":"wp_update_option","arguments":{"key":"gmcp_options","value":"x"}}}'
check "the log holds what the calls just made" \
  "$(docker compose exec -T cli wp eval 'echo GMCP_Audit::count();' 2>/dev/null | tr -d '\r\n')" "3"
check "and counting refusals counts only those" \
  "$(docker compose exec -T cli wp eval 'echo GMCP_Audit::count(["outcome"=>"refused"]);' 2>/dev/null | tr -d '\r\n')" "1"
check "control: counting successes is not the same number" \
  "$(docker compose exec -T cli wp eval 'echo GMCP_Audit::count(["outcome"=>"ok"]);' 2>/dev/null | tr -d '\r\n')" "2"
check "a filtered count agrees with the rows it filtered" \
  "$(docker compose exec -T cli wp eval 'echo GMCP_Audit::count(["tool"=>"wp_get_posts"]) === count(GMCP_Audit::query(["tool"=>"wp_get_posts"])) ? "agree" : "DISAGREE";' 2>/dev/null | tr -d '\r\n')" "agree"

# The chain verdict names an id, so an id has to lead somewhere.
check "one entry can be fetched by its id" \
  "$(docker compose exec -T cli wp eval '$r = GMCP_Audit::get(1); echo $r ? $r["tool"] : "MISSING";' 2>/dev/null | tr -d '\r\n')" "wp_get_posts"
check "and an id that is not there says so rather than erroring" \
  "$(docker compose exec -T cli wp eval 'var_export(GMCP_Audit::get(999999) === null);' 2>/dev/null | tr -d '\r\n')" "true"

# Ordering comes from a query string, and a column name cannot be a bound parameter.
check "an injected sort column falls back instead of reaching SQL" \
  "$(docker compose exec -T cli wp eval '$r = GMCP_Audit::query(["orderby"=>"id; DROP TABLE wp_posts","limit"=>1]); echo $r ? "survived" : "BROKEN";' 2>/dev/null | tr -d '\r\n')" "survived"
check "control: a sort column that is allowed does change the order" \
  "$(docker compose exec -T cli wp eval '
     $a = GMCP_Audit::query(["order"=>"asc","limit"=>1])[0]["id"];
     $d = GMCP_Audit::query(["order"=>"desc","limit"=>1])[0]["id"];
     echo $a === $d ? "SAME" : "differ";' 2>/dev/null | tr -d '\r\n')" "differ"

# One row's own verdict, which is the question a reader has once the chain names an id.
check "a good entry verifies on its own" \
  "$(docker compose exec -T cli wp eval '$v = GMCP_Audit::verify_row(2); echo $v["ok"] && $v["checked"] ? "ok" : "NOT OK";' 2>/dev/null | tr -d '\r\n')" "ok"
docker compose exec -T cli wp eval 'global $wpdb; $t = GMCP_Audit::table(); $wpdb->query("UPDATE {$t} SET target = \"tampered\" WHERE id = 2");' >/dev/null 2>&1
check "an edited entry fails on its own" \
  "$(docker compose exec -T cli wp eval '$v = GMCP_Audit::verify_row(2); echo $v["ok"] ? "STILL OK" : "caught";' 2>/dev/null | tr -d '\r\n')" "caught"
check "and the entry the chain blames is the one that was edited" \
  "$(docker compose exec -T cli wp eval '$v = GMCP_Audit::verify("all"); echo (int) $v["broken_at"];' 2>/dev/null | tr -d '\r\n')" "2"
check "control: its untouched neighbour still verifies" \
  "$(docker compose exec -T cli wp eval '$v = GMCP_Audit::verify_row(1); echo $v["ok"] ? "ok" : "NOT OK";' 2>/dev/null | tr -d '\r\n')" "ok"
docker compose exec -T cli wp eval 'GMCP_Audit::clear();' >/dev/null 2>&1

echo "-- the audit table repairs itself on upgrade --"
# WordPress does not run the activation hook when a plugin is updated in place, so the
# old table meets the new code. While it is broken nothing may be lost quietly, and it
# has to repair itself on the next load.
#
# The marker is set from the constant rather than to a number. It has to read as current,
# so install() skips and the table stays broken for the checks below. Written as a literal
# it stopped meaning "current" the day the schema changed: the plugin saw a stale marker,
# repaired the table before the first check ran, and three checks then measured a repair
# that had already happened.
GMCP_DB_VERSION=$(docker compose exec -T cli wp eval 'echo GMCP_Audit::DB_VERSION;' 2>/dev/null | tr -d '\r\n')
docker compose exec -T cli wp eval 'GMCP_Audit::clear();global $wpdb;$wpdb->query("ALTER TABLE {$wpdb->prefix}gmcp_audit DROP COLUMN changes");update_option("gmcp_audit_db_version",GMCP_Audit::DB_VERSION);' >/dev/null 2>&1
check "the column really is gone" "$(have_changes_column)" "no"
C_ROWS=$(audit_q 'SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit')
call c_stale '{"jsonrpc":"2.0","id":229,"method":"tools/call","params":{"name":"wp_get_posts","arguments":{"limit":1}}}'
check "the call still succeeds for the caller" "$(verdict c_stale)" "ok"
check "but the entry is not written against the wrong schema" \
  "$(audit_q 'SELECT COUNT(*) FROM {$wpdb->prefix}gmcp_audit')" "$C_ROWS"
check "and the failure is announced rather than swallowed" \
  "$(docker compose logs wp --since 120s 2>&1 | grep -q 'audit entry NOT recorded' && echo said || echo silent)" "said"
check "control: the log scan does not match just anything" \
  "$(docker compose logs wp --since 120s 2>&1 | grep -q 'audit entry NOT recorded for a tool that does not exist' && echo said || echo silent)" "silent"
# Now the repair: a version marker older than the code is what an upgrade really leaves.
docker compose exec -T cli wp option update gmcp_audit_db_version 1 >/dev/null 2>&1
call c_fixed '{"jsonrpc":"2.0","id":230,"method":"tools/call","params":{"name":"wp_get_posts","arguments":{"limit":1}}}'
check "the table repairs itself on the next load" "$(have_changes_column)" "yes"
check "the version marker is brought up to date" \
  "$(docker compose exec -T cli wp option get gmcp_audit_db_version 2>/dev/null | tr -d '\r\n')" "$GMCP_DB_VERSION"
check "and entries are recorded again" \
  "$(audit_q "SELECT COUNT(*) > $C_ROWS FROM {\$wpdb->prefix}gmcp_audit")" "1"

echo "-- rewrite rules and header handling (destructive: rebuilds .htaccess) --"
# The hard flush is what writes .htaccess, and it only runs if save_mod_rewrite_rules()
# exists. That lives in wp-admin/includes/misc.php and calls get_home_path() from
# file.php, neither of which a REST request loads. Without both requires the flush
# degraded silently to soft, .htaccess was never written, and every inner URL 404'd
# while the tool reported success. The home page still resolved through DirectoryIndex,
# so a front-page check did not catch it.
docker compose exec -T wp rm -f /var/www/html/.htaccess
check "inner URLs break without .htaccess" \
  "$(curl -sS -o /dev/null -w '%{http_code}' $BASE/hello-world/)" "404"
call_plain p_flush '{"jsonrpc":"2.0","id":80,"method":"tools/call","params":{"name":"wp_set_permalink_structure","arguments":{"structure":"/%postname%/"}}}'
check "the API is still reachable on ?rest_route=" "$(verdict p_flush)" "ok"
check "hard flush writes .htaccess" \
  "$(docker compose exec -T wp sh -c 'test -f /var/www/html/.htaccess && echo yes || echo no' | tr -d '\r\n')" "yes"
check "inner URLs resolve again" \
  "$(curl -sS -o /dev/null -w '%{http_code}' $BASE/hello-world/)" "200"

# Apache receives the Authorization header but does not place it in $_SERVER unless a
# rewrite rule copies it there, and WordPress only reads $_SERVER. A site whose
# .htaccess was hand-written or reset loses bearer auth entirely and sees a 401 that
# looks like a bad token. The server falls back to apache_request_headers().
docker compose exec -T wp sh -c "sed -i '/HTTP_AUTHORIZATION/d' /var/www/html/.htaccess"
check "bearer auth survives a stripped header rule" \
  "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$URL" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" "200"
check "a wrong token is still rejected" \
  "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$URL" -H 'Authorization: Bearer wrong' -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" "401"
call p_restore '{"jsonrpc":"2.0","id":81,"method":"tools/call","params":{"name":"wp_set_permalink_structure","arguments":{"structure":"/%postname%/"}}}'
check "regenerating restores the header rule" \
  "$(docker compose exec -T wp sh -c 'grep -c HTTP_AUTHORIZATION /var/www/html/.htaccess' | tr -d '\r\n')" "1"

echo "-- what the audit log does with a very large reply --"
# detail() used to strip tags across the whole response text and only then keep 1000
# characters. A chunked read of a multi-megabyte value arrives as tens of megabytes of
# text, and on a mid-sized host that fatals inside wp_strip_all_tags, taking the request
# down as a 500 with an empty body: the caller is told nothing at all. The cut comes first
# now. Ordinary text is asserted too, because a fix that quietly shortened every detail
# line, or stopped stripping markup, would be its own defect. Nothing asserts a duration:
# the speed is the point, but a timing assertion on a shared machine is a flaky test.
audit_detail() { # audit_detail <php expression yielding the text>
  docker compose exec -T cli wp eval "
    \$m = new ReflectionMethod( 'GMCP_Audit', 'detail' );
    \$m->setAccessible( true );
    \$call = [ 'result' => [ 'result' => [ 'content' => [ [ 'text' => $1 ] ] ] ] ];
    echo mb_strlen( \$m->invoke( new GMCP_Audit(), \$call, false ) );" 2>/dev/null | tr -d '\r\n'
}
check "a short reply is recorded whole" "$(audit_detail "'Post created ID 16'")" "18"
check "a long one is still cut to a thousand" "$(audit_detail "str_repeat('a',50000)")" "1000"
check "and a multi-megabyte one survives" "$(audit_detail "str_repeat('z',8000000)")" "1000"
check "markup is still stripped out" \
  "$(docker compose exec -T cli wp eval "
     \$m = new ReflectionMethod( 'GMCP_Audit', 'detail' );
     \$m->setAccessible( true );
     \$call = [ 'result' => [ 'result' => [ 'content' => [ [ 'text' => '<b>bold</b> and <i>italic</i>' ] ] ] ] ];
     echo \$m->invoke( new GMCP_Audit(), \$call, false );" 2>/dev/null | tr -d '\r\n')" "bold and italic"

echo "-- deleting an option --"
docker compose exec -T cli wp option add gmcp_smoke_removable "bye" >/dev/null 2>&1
call do_ok '{"jsonrpc":"2.0","id":85,"method":"tools/call","params":{"name":"wp_delete_option","arguments":{"key":"gmcp_smoke_removable"}}}'
# The plugin's own rows are unreadable and unwritable, and deleting is a write. A tool that
# could drop them would be a way to remove the guard rather than pass it.
check "a gmcp_ row is refused like any other access to one" "$(verdict do_ok)" "error"
docker compose exec -T cli wp option add smoke_removable "bye" >/dev/null 2>&1
call do_ok2 '{"jsonrpc":"2.0","id":86,"method":"tools/call","params":{"name":"wp_delete_option","arguments":{"key":"smoke_removable"}}}'
check "an ordinary option is deleted" "$(verdict do_ok2)" "ok"
check "and the row is really gone" \
  "$(docker compose exec -T cli wp option get smoke_removable >/dev/null 2>&1 && echo present || echo absent | tr -d '\r\n')" "absent"
# Nothing was deleted, so saying so is the honest answer. Reporting a deletion that did not
# happen would have a caller believe a stale value is gone when it never existed.
call do_again '{"jsonrpc":"2.0","id":87,"method":"tools/call","params":{"name":"wp_delete_option","arguments":{"key":"smoke_removable"}}}'
check "deleting one that is not there says so rather than claiming a deletion" \
  "$(refusal do_again | grep -c 'does not exist; nothing was deleted')" "1"
# Undo cannot put a deleted option back, so the reply carries the value: it is the only
# copy anyone gets. That makes the reply a read of the row, which is why the credential
# test that keeps values out of the journal has to apply here too.
docker compose exec -T cli wp eval 'update_option("smoke_value_back","the old value",false);' >/dev/null 2>&1
call do_val '{"jsonrpc":"2.0","id":92,"method":"tools/call","params":{"name":"wp_delete_option","arguments":{"key":"smoke_value_back"}}}'
check "the deleted value comes back with the answer" \
  "$(refusal do_val | grep -c 'the old value')" "1"
check "and the reply says undo cannot restore it" \
  "$(refusal do_val | grep -c 'wp_undo_change cannot put it back')" "1"
docker compose exec -T cli wp eval 'update_option("smoke_endpoint_config",["endpoint"=>"https://x.test","api_key"=>"sk-live-123"],false);' >/dev/null 2>&1
call do_sec '{"jsonrpc":"2.0","id":93,"method":"tools/call","params":{"name":"wp_delete_option","arguments":{"key":"smoke_endpoint_config"}}}'
# The option name is innocuous, so option_guard lets the delete through. The value is not
# innocuous, and repeating it would make deleting a way to read a credential out.
check "but a credential-shaped value is withheld" \
  "$(refusal do_sec | grep -c 'sk-live-123')" "0"
check "and the reply says why it was withheld" \
  "$(refusal do_sec | grep -c 'credential-shaped')" "1"

# siteurl is the row that makes the site and this endpoint resolvable. There is no way back
# in through the API that deleted it, so it cannot be deleted through the API at all.
call do_site '{"jsonrpc":"2.0","id":88,"method":"tools/call","params":{"name":"wp_delete_option","arguments":{"key":"siteurl"}}}'
check "siteurl cannot be deleted" "$(verdict do_site)" "error"
check "and it is still there" \
  "$(docker compose exec -T cli wp option get siteurl >/dev/null 2>&1 && echo present || echo absent | tr -d '\r\n')" "present"
call do_cron '{"jsonrpc":"2.0","id":89,"method":"tools/call","params":{"name":"wp_delete_option","arguments":{"key":"cron"}}}'
# Deleting the cron row drops every scheduled event on the site and nothing errors.
check "and neither can the whole cron schedule" "$(verdict do_cron)" "error"

echo "-- flushing caches --"
docker compose exec -T cli wp eval 'set_transient("smoke_fresh","keep",3600); set_transient("smoke_stale","go",1); update_option("_transient_timeout_smoke_stale", time()-60);' >/dev/null 2>&1
call fc '{"jsonrpc":"2.0","id":90,"method":"tools/call","params":{"name":"wp_flush_cache","arguments":{"scope":"transients"}}}'
check "wp_flush_cache clears expired transients" "$(verdict fc)" "ok"
check "the expired one is gone" \
  "$(docker compose exec -T cli wp eval 'echo get_transient("smoke_stale")===false?"gone":"kept";' | tr -d '\r\n')" "gone"
# Throwing away unexpired transients discards work rather than stale data, so the expired
# sweep must leave them alone.
check "and a live one is left alone" \
  "$(docker compose exec -T cli wp eval 'echo get_transient("smoke_fresh")==="keep"?"kept":"gone";' | tr -d '\r\n')" "kept"
call fc2 '{"jsonrpc":"2.0","id":91,"method":"tools/call","params":{"name":"wp_flush_cache","arguments":{"scope":"all"}}}'
check "a full flush succeeds" "$(verdict fc2)" "ok"
# The reason the field report had to be told twice to purge by hand. A CDN or a Varnish in
# front of WordPress is not reachable from here, and the reply has to say so rather than
# leave a caller believing the page is fresh.
check "and it names the CDN it cannot reach" \
  "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["content"][0]["text"];print("NOT purged" in t and "CDN" in t)' fc2)" "True"

echo "-- the audit log screen --"
# The Refusals view is the one an operator opens first, and outcome was the one filtered
# column with no index, on a table allowed to reach 50,000 rows.
check "outcome is indexed" \
  "$(docker compose exec -T cli wp db query 'SHOW INDEX FROM wp_gmcp_audit WHERE Key_name="outcome"' --skip-column-names 2>/dev/null | grep -c outcome)" "1"

# Search used to run four unindexed LIKE over TEXT including args, which has a 50MB budget
# across the table. It now reads target and detail unless the caller asks for more. The
# shallow miss is the control: without it, a query matching everything would look like a
# working deep search.
audit_find() { # audit_find <term> <deep 0|1>
  docker compose exec -T cli wp eval "
    \$f = [ 'search' => '$1', 'deep' => $2 ];
    echo (int) GMCP_Audit::count( \$f );" 2>/dev/null | tr -d '\r\n'
}
docker compose exec -T cli wp eval '
  global $wpdb; $t = $wpdb->prefix . "gmcp_audit";
  $wpdb->query( "DELETE FROM $t WHERE tool = \"smoke_scope\"" );' >/dev/null 2>&1
# Seeded through the recorder rather than a hand-built INSERT, so the row has every column
# the reader has and hashes like a real one.
docker compose exec -T cli wp eval '
  $a = new GMCP_Audit();
  $a->record( [ "tool" => "smoke_scope", "args" => [ "needle" => "smokedeepneedle" ],
    "status" => "ok", "user_id" => 1, "auth_method" => "bearer" ] );' >/dev/null 2>&1
check "a term living only in the arguments is not found by default" "$(audit_find smokedeepneedle 0)" "0"
check "and is found when deep search is asked for" "$(audit_find smokedeepneedle 1)" "1"
# The pager divides count() by the page size, so a count that ignores a filter the list
# honours offers pages of a list that does not exist. That happened here once already.
check "count and query agree under deep search" \
  "$(docker compose exec -T cli wp eval '
     $f = [ "search" => "smokedeepneedle", "deep" => 1 ];
     echo ( (int) GMCP_Audit::count( $f ) === count( GMCP_Audit::query( $f + [ "limit" => 50 ] ) ) ) ? "agree" : "differ";' 2>/dev/null | tr -d '\r\n')" "agree"

# An export that claims to be the filtered view and quietly holds a different set is worse
# than no export, because the row count looks plausible either way.
check "export selects exactly what the filters select" \
  "$(docker compose exec -T cli wp eval '
     $f = [ "outcome" => "refused" ];
     echo ( count( GMCP_Audit::export_rows( $f ) ) === (int) GMCP_Audit::count( $f ) ) ? "same" : "differ";' 2>/dev/null | tr -d '\r\n')" "same"

# A spreadsheet runs a cell that opens with =, +, - or @, and this log carries text an
# anonymous person wrote. Tab and carriage return count too: Excel skips them and reads
# what follows. A plain number must survive, or a legitimate -1 becomes text.
csv_cell() {
  docker compose exec -T cli wp eval "
    \$m = new ReflectionMethod( 'GMCP_Settings', 'csv_cell' );
    \$m->setAccessible( true );
    echo \$m->invoke( null, $1 );" 2>/dev/null | tr -d '\r\n'
}
check "a formula cell is made inert" "$(csv_cell "'=1+1'")" "'=1+1"
check "and so is one behind a tab" "$(csv_cell "\"\\t=1+1\"")" "'	=1+1"
check "a negative number is left alone" "$(csv_cell "'-1'")" "-1"
check "and ordinary text is untouched" "$(csv_cell "'wp_create_post'")" "wp_create_post"
docker compose exec -T cli wp eval '
  global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}gmcp_audit WHERE tool = \"smoke_scope\"" );' >/dev/null 2>&1

echo "-- editing a menu item --"
# wp_update_nav_menu_item() blanks every field you do not name, and treats position 0 as
# "append", while the first item of any menu is genuinely stored at 0. So a naive rename
# clears the URL and moves the top item to the bottom, with nothing erroring. Both are
# asserted on the STORED row: wp_get_nav_menu_items() renumbers menu_order on the objects
# it returns, so a comparison built on it compares two renumbered views and sees nothing.
MENU_ID=$(docker compose exec -T cli wp eval '
  $m = wp_create_nav_menu( "Smoke Menu " . wp_rand( 1000, 9999 ) );
  $a = wp_update_nav_menu_item( $m, 0, [ "menu-item-title" => "First", "menu-item-url" => "https://example.test/one",
    "menu-item-type" => "custom", "menu-item-status" => "publish" ] );
  $b = wp_update_nav_menu_item( $m, 0, [ "menu-item-title" => "Second", "menu-item-url" => "https://example.test/two",
    "menu-item-type" => "custom", "menu-item-status" => "publish" ] );
  echo $m . "|" . $a . "|" . $b;' 2>/dev/null | tr -d '\r\n')
ITEM_A=$(echo "$MENU_ID" | cut -d'|' -f2)
ORDER_BEFORE=$(docker compose exec -T cli wp post get "$ITEM_A" --field=menu_order 2>/dev/null | tr -d '\r\n')
call mi_rename "{\"jsonrpc\":\"2.0\",\"id\":240,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_menu_item\",\"arguments\":{\"item_id\":$ITEM_A,\"title\":\"First Renamed\"}}}"
check "a menu item can be renamed" "$(verdict mi_rename)" "ok"
check "and the rename leaves its URL alone" \
  "$(docker compose exec -T cli wp eval "echo get_post_meta($ITEM_A,'_menu_item_url',true);" 2>/dev/null | tr -d '\r\n')" "https://example.test/one"
# The one that catches the position-0 append. Renaming the FIRST item is the case that
# breaks, because its stored menu_order is 0 and core reads that as "not specified".
check "and does not move it to the end of the menu" \
  "$(docker compose exec -T cli wp post get "$ITEM_A" --field=menu_order 2>/dev/null | tr -d '\r\n')" "$ORDER_BEFORE"
# A repair after the write would leave the audit log describing a change that did not
# happen, because menu_order is one of the post fields the change layer watches.
check "and the log does not record a move that never happened" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;$c=(string)$wpdb->get_var("SELECT changes FROM {$wpdb->prefix}gmcp_audit WHERE tool=\"wp_update_menu_item\" ORDER BY id DESC LIMIT 1");echo strpos($c,"menu_order")===false?"clean":"RECORDED A MOVE";' 2>/dev/null | tr -d '\r\n')" "clean"
call mi_self "{\"jsonrpc\":\"2.0\",\"id\":241,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_menu_item\",\"arguments\":{\"item_id\":$ITEM_A,\"parent_id\":$ITEM_A}}}"
check "an item cannot be its own parent" "$(verdict mi_self)" "error"

echo "-- menu slugs and the audit --"
# WordPress appends a numbered suffix when a derived slug is taken, and the slug is what an
# Elementor Nav Menu widget stores. Creating "main-menu-2" silently leaves that widget
# pointing at the other menu, rendering an empty nav with no error anywhere.
docker compose exec -T cli wp eval 'if ( ! get_term_by( "slug", "smoke-taken", "nav_menu" ) ) { wp_insert_term( "Smoke Taken", "nav_menu", [ "slug" => "smoke-taken" ] ); }' >/dev/null 2>&1
call ms_dup '{"jsonrpc":"2.0","id":242,"method":"tools/call","params":{"name":"wp_create_menu","arguments":{"name":"Another","slug":"smoke-taken"}}}'
check "a taken menu slug is refused rather than suffixed" "$(verdict ms_dup)" "error"
check "and no second menu was created under a suffix" \
  "$(docker compose exec -T cli wp eval 'echo get_term_by("slug","smoke-taken-2","nav_menu") ? "SUFFIXED" : "none";' 2>/dev/null | tr -d '\r\n')" "none"
call mh_report '{"jsonrpc":"2.0","id":243,"method":"tools/call","params":{"name":"wp_menu_health","arguments":{}}}'
check "the menu health report runs" "$(verdict mh_report)" "ok"
# It reads Elementor data, and Elementor may not be installed. That must be a sentence,
# never an error: the report is worth most on a site where something is already wrong.
check "and it reports rather than failing when Elementor is absent" \
  "$(py 'import json,sys;print("ok" if not json.load(sys.stdin)["result"].get("isError") else "err")' mh_report)" "ok"

echo "-- purging one URL --"
call pu_foreign '{"jsonrpc":"2.0","id":244,"method":"tools/call","params":{"name":"wp_purge_url","arguments":{"urls":["https://evil.example/x"]}}}'
check "a URL on another site is refused" "$(verdict pu_foreign)" "error"
# A host that merely STARTS with this site's host is the case a prefix comparison passes.
call pu_prefix '{"jsonrpc":"2.0","id":245,"method":"tools/call","params":{"name":"wp_purge_url","arguments":{"urls":["http://localhost:8101.evil.example/x"]}}}'
check "and so is a host that merely starts with ours" "$(verdict pu_prefix)" "error"
# Every per-URL purge below turns the URL into a filesystem path and globs it.
call pu_trav '{"jsonrpc":"2.0","id":246,"method":"tools/call","params":{"name":"wp_purge_url","arguments":{"urls":["/../../etc/passwd"]}}}'
check "and a path stepping outside the site is refused" "$(verdict pu_trav)" "error"
call pu_ok '{"jsonrpc":"2.0","id":247,"method":"tools/call","params":{"name":"wp_purge_url","arguments":{"urls":["/hello-world/"]}}}'
check "a URL on this site is accepted" "$(verdict pu_ok)" "ok"
# With no page cache installed the honest answer is that nothing was purged. Reporting
# success here is how a caller comes to believe a stale page is fresh.
check "and says plainly that nothing was purged" \
  "$(refusal pu_ok | grep -ci 'nothing was purged')" "1"

echo "-- the documented tool counts are the real ones --"
# The access-level table in README.md is nine numbers that nothing checked, and every one
# of them had drifted behind the code by the time anybody looked: 93/56/31 documented
# against 99/59/34 live. The suites assert behaviour exhaustively and asserted nothing
# about the documentation, so this is the cheapest defect class in the project to close.
#
# Counts exclude Elementor tools. The table's columns are cumulative over the three groups
# it names, and Elementor is a fourth group that comes and goes with a plugin, so folding
# it in would make the numbers depend on what happens to be installed.
tc_key() { # tc_key <level>
  docker compose exec -T cli wp eval '
    $level = "'"$1"'";
    $label = "count " . $level;
    foreach ( GMCP_Tokens::all() as $k ) {
      if ( ( $k["label"] ?? "" ) === $label ) { GMCP_Tokens::revoke( $k["id"] ); }
    }
    $a = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
    $r = GMCP_Tokens::create( $label, $level, 0, [], $a ? $a[0]->ID : 0 );
    echo $r["secret"];' 2>/dev/null | tr -d '\r\n'
}
tc_groups() { # tc_groups <admin 0|1> <woo 0|1>
  docker compose exec -T cli wp eval '
    $o = get_option( "gmcp_options", [] );
    $o["mcp_tools_core"] = true;
    $o["mcp_tools_admin"] = '"$1"' ? true : false;
    $o["mcp_tools_woo"]   = '"$2"' ? true : false;
    update_option( "gmcp_options", $o, false );' >/dev/null 2>&1
}
tc_count() { # tc_count <secret>
  curl -sS -X POST "$URL" -H "Authorization: Bearer $1" \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -d '{"jsonrpc":"2.0","id":700,"method":"tools/list"}' \
  | python3 -c "
import json,sys
try:
    t = json.load(sys.stdin)['result']['tools']
except Exception:
    print('unreadable'); raise SystemExit
print(len([x for x in t if not x['name'].startswith('elementor_') and not x['name'].startswith('kirki_') and not x['name'].startswith('yoast_') and not x['name'].startswith('acf_')]))"
}
# The expected numbers come from the table itself, so the check fails whichever side moved.
tc_doc() { # tc_doc <level> <column index, 1-3>
  python3 - "$(dirname "$0")/../README.md" "$1" "$2" <<'PY'
import re, sys
path, level, col = sys.argv[1], sys.argv[2], int(sys.argv[3])
for line in open(path):
    if line.startswith(f'| `{level}` |'):
        cells = [c.strip() for c in line.strip().strip('|').split('|')]
        print(cells[col])
        break
else:
    print('no such row')
PY
}
TC_ADMIN=$(tc_key admin); TC_RW=$(tc_key readwrite); TC_RO=$(tc_key readonly)
# Without this, a failure to mint the keys leaves the counts at 0 and nine checks report
# the documentation as wrong when nothing was ever counted.
TC_OK=0
for k in "$TC_ADMIN" "$TC_RW" "$TC_RO"; do
  [ "${k#gmcp_}" != "$k" ] && TC_OK=$((TC_OK+1))
done
check "control: the three counting keys were created" "$TC_OK" "3"
check "control: the table can still be read" "$(tc_doc admin 1)" "$(tc_doc admin 1)"
case "$(tc_doc admin 1)" in
  ''|'no such row') echo "  FAIL  the access-level table could not be parsed"; fail=$((fail+1));;
esac

tc_groups 0 0
check "content only, admin level"     "$(tc_count "$TC_ADMIN")" "$(tc_doc admin 1)"
check "content only, readwrite"       "$(tc_count "$TC_RW")"    "$(tc_doc readwrite 1)"
check "content only, readonly"        "$(tc_count "$TC_RO")"    "$(tc_doc readonly 1)"

tc_groups 1 0
check "plus administration, admin"    "$(tc_count "$TC_ADMIN")" "$(tc_doc admin 2)"
check "plus administration, readwrite" "$(tc_count "$TC_RW")"   "$(tc_doc readwrite 2)"
check "plus administration, readonly" "$(tc_count "$TC_RO")"    "$(tc_doc readonly 2)"

if docker compose exec -T cli wp plugin is-active woocommerce >/dev/null 2>&1; then
  tc_groups 1 1
  check "plus WooCommerce, admin"       "$(tc_count "$TC_ADMIN")" "$(tc_doc admin 3)"
  check "plus WooCommerce, readwrite"   "$(tc_count "$TC_RW")"    "$(tc_doc readwrite 3)"
  check "plus WooCommerce, readonly"    "$(tc_count "$TC_RO")"    "$(tc_doc readonly 3)"
else
  # Said out loud. A column quietly not checked is a column that drifts exactly as far as
  # the ones nobody was checking before this block existed.
  echo "  ....  WooCommerce column not checked: the plugin is not active on this site"
fi
tc_groups 1 0
gmcp_clear_other_keys "smoke suite"

echo "-- scheduled events --"
# Site Health flags a cron event that keeps failing and there was no way to look at it,
# run it or stop it. The guards matter more than the happy path here: the run tool fires
# hooks, and a tool that fires any hook the caller names is a tool for running arbitrary
# WordPress code on an instruction that may have arrived inside a comment.
docker compose exec -T cli wp cron event schedule smoke_test_event now hourly >/dev/null 2>&1
call cr_list '{"jsonrpc":"2.0","id":90,"method":"tools/call","params":{"name":"wp_list_cron_events","arguments":{}}}'
check "cron events are listed" \
  "$(py 'import json,sys;d=json.load(sys.stdin);j=json.loads(d["result"]["content"][0]["text"]);print("smoke_test_event" in [e["hook"] for e in j["events"]])' cr_list)" "True"
# Every event is overdue by design on a site where cron does not spawn, so the listing
# has to say which it is rather than leave a reader to diagnose a fault that is a setting.
check "and say whether cron runs at all" \
  "$(py 'import json,sys;d=json.load(sys.stdin);j=json.loads(d["result"]["content"][0]["text"]);print("cron_disabled" in j)' cr_list)" "True"

call cr_unscheduled '{"jsonrpc":"2.0","id":91,"method":"tools/call","params":{"name":"wp_run_cron_event","arguments":{"hook":"init"}}}'
check "a hook the site never scheduled cannot be fired" "$(verdict cr_unscheduled)" "error"
call cr_own '{"jsonrpc":"2.0","id":92,"method":"tools/call","params":{"name":"wp_run_cron_event","arguments":{"hook":"gmcp_audit_prune"}}}'
check "and neither can this plugin's own housekeeping" "$(verdict cr_own)" "error"

call cr_run '{"jsonrpc":"2.0","id":93,"method":"tools/call","params":{"name":"wp_run_cron_event","arguments":{"hook":"smoke_test_event"}}}'
check "a scheduled event runs" "$(verdict cr_run)" "ok"
# The occurrence comes off the schedule before the callbacks run, so a callback that
# fatals cannot leave the event still due for the next spawn to run a second time. A
# recurring event must still be scheduled afterwards, at its next occurrence.
check "and a recurring one is rescheduled, not left due twice" \
  "$(docker compose exec -T cli wp cron event list --fields=hook --format=csv 2>/dev/null | grep -c '^smoke_test_event$' | tr -d '\r\n')" "1"

call cr_drop1 '{"jsonrpc":"2.0","id":94,"method":"tools/call","params":{"name":"wp_unschedule_cron_event","arguments":{"hook":"smoke_test_event"}}}'
check "unscheduling asks for confirmation first" "$(verdict cr_drop1)" "error"
# The first call has to change nothing. A confirmation that already did the thing is
# worse than none, because it reads as a safeguard.
check "and the first call leaves the event alone" \
  "$(docker compose exec -T cli wp cron event list --fields=hook --format=csv 2>/dev/null | grep -c '^smoke_test_event$' | tr -d '\r\n')" "1"
CRONTOK=$(python3 "$(dirname "$0")/extract_token.py" < "$OUT/cr_drop1")
call cr_drop2 "{\"jsonrpc\":\"2.0\",\"id\":95,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_unschedule_cron_event\",\"arguments\":{\"hook\":\"smoke_test_event\",\"confirm\":\"$CRONTOK\"}}}"
check "the token completes it" "$(verdict cr_drop2)" "ok"
check "and the event is gone" \
  "$(docker compose exec -T cli wp cron event list --fields=hook --format=csv 2>/dev/null | grep -c '^smoke_test_event$' | tr -d '\r\n')" "0"

echo "-- the journal watches post meta --"
# On a page-builder site the meta IS the work, so an undo log covering everything except
# _elementor_data missed the changes that mattered most on the sites this gets used to
# build. Previous values live in a table of their own: the journal's option row caps a
# single value at 64KB and an Elementor document runs past 100KB, so sharing the budget
# would have recorded every one of them as too large to keep.
docker compose exec -T cli wp option delete gmcp_journal >/dev/null 2>&1
JM_ID=$(docker compose exec -T cli wp eval 'echo wp_insert_post(["post_title"=>"Journal meta probe","post_type"=>"page","post_status"=>"publish"]);' 2>/dev/null | tr -d '\r\n')
# Seeded with wp_slash, the way the plugin's own writer does it. Without that
# update_post_meta unslashes the fixture and the test measures a corrupt seed.
docker compose exec -T cli wp eval "
  update_post_meta($JM_ID, 'jm_plain', 'ORIGINAL');
  update_post_meta($JM_ID, 'jm_slash', wp_slash([ 'sl' => 'C:' . chr(92) . 'path' ]));
  update_post_meta($JM_ID, 'api_key', 'JM-LIVE-SECRET');
  update_post_meta($JM_ID, 'jm_big', str_repeat('B', 1200000));
  update_post_meta($JM_ID, '_edit_lock', '123:1');" >/dev/null 2>&1

jm_call() { # jm_call <file> <id> <arguments json>
  call "$1" "{\"jsonrpc\":\"2.0\",\"id\":$2,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_update_post_meta\",\"arguments\":$3}}"
}
jm_call jm_w1 300 "{\"ID\":$JM_ID,\"key\":\"jm_plain\",\"value\":\"CHANGED\"}"
jm_call jm_w2 301 "{\"ID\":$JM_ID,\"key\":\"jm_new\",\"value\":\"FRESH\"}"
jm_call jm_w3 302 "{\"ID\":$JM_ID,\"key\":\"api_key\",\"value\":\"ROTATED\"}"
jm_call jm_w4 303 "{\"ID\":$JM_ID,\"key\":\"_edit_lock\",\"value\":\"456:2\"}"
# Through the API, not wp eval: the journal records writes a tool call made, so a fixture
# written straight to the database is not journalled and the undo below would have nothing
# to find. The first version of this block did that and reported the restore as mangled
# when in truth it had never run.
jm_call jm_wslash 313 "{\"ID\":$JM_ID,\"key\":\"jm_slash\",\"value\":{\"sl\":\"OVERWRITTEN\"}}"
call jm_list '{"jsonrpc":"2.0","id":304,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{"limit":40}}}'
jm_field() { py "import json,sys,re
rows = json.loads(json.load(sys.stdin)['result']['content'][0]['text'])
hit = [r for r in rows if 'custom field \"$1\"' in r['what']]
print(hit[0][$2] if hit else 'MISSING')" jm_list; }

check "an edited field is journalled" "$(jm_field jm_plain "'reversible'")" "True"
check "and adding a field is journalled as added" \
  "$(py "import json,sys;rows=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(any('\"jm_new\" added' in r['what'] for r in rows))" jm_list)" "True"
# The control for the two absence checks below: the probe finds a key that IS journalled,
# so a key it cannot find is one that was skipped rather than one it cannot see.
check "CONTROL: the probe can find a journalled field" "$(jm_field jm_plain "'what'")" \
  "Page \"Journal meta probe\" custom field \"jm_plain\" changed"
# Keys that say who is editing rather than what the post holds. Journalling these buries
# the signal: every post save writes one.
check "a noise key is not journalled at all" "$(jm_field _edit_lock "'what'")" "MISSING"
check "a credential-shaped key is recorded but its value is not kept" \
  "$(jm_field api_key "'not_reversible_because'")" \
  "The previous value looked like it held a credential, so it was never stored."
# The bound that makes a table safe to keep at all. Written through the API for the same
# reason as above, with the body built in Python because a megabyte does not belong on a
# command line.
python3 - "$OUT" "$JM_ID" <<'PYBIG'
import json, sys
out, pid = sys.argv[1], int(sys.argv[2])
open(out + '/jm_big.json', 'w').write(json.dumps(
    {"jsonrpc": "2.0", "id": 305, "method": "tools/call",
     "params": {"name": "wp_update_post_meta",
                "arguments": {"ID": pid, "key": "jm_big", "value": "C" * 1200000}}}))
PYBIG
curl -sS -X POST "$URL" -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' -d @"$OUT/jm_big.json" -o "$OUT/jm_big"
call jm_list2 '{"jsonrpc":"2.0","id":306,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{"limit":40}}}'
check "an oversized value is recorded without a copy" \
  "$(py "import json,sys;rows=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);hit=[r for r in rows if 'jm_big' in r['what']];print('past the limit' in hit[0].get('not_reversible_because','') if hit else 'MISSING')" jm_list2)" "True"
check "and neither the secret nor the oversized value reached the snapshot table" \
  "$(docker compose exec -T cli wp eval 'global $wpdb; $t = GMCP_Journal::snapshot_table();
      $all = implode( "", (array) $wpdb->get_col( "SELECT CONCAT(meta_key, COALESCE(meta_value,\"\")) FROM $t" ) );
      $hit = 0;
      if ( strpos( $all, "JM-LIVE-SECRET" ) !== false ) { $hit++; }
      if ( strpos( $all, str_repeat( "B", 1000 ) ) !== false ) { $hit++; }
      echo $hit;' 2>/dev/null | tr -d '\r\n')" "0"
# A scan over an empty table reports everything safe, so prove the table has content.
check "CONTROL: the snapshot table did record the other fields" \
  "$(docker compose exec -T cli wp eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . GMCP_Journal::snapshot_table() ) > 0 ? 1 : 0;' 2>/dev/null | tr -d '\r\n')" "1"

JM_UNDO=$(py "import json,sys;rows=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(next(r['id'] for r in rows if 'jm_plain' in r['what']))" jm_list)
call jm_rev "{\"jsonrpc\":\"2.0\",\"id\":306,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$JM_UNDO\"}}}"
check "undo puts a field back" \
  "$(docker compose exec -T cli wp eval "echo get_post_meta($JM_ID,'jm_plain',true);" 2>/dev/null | tr -d '\r\n')" "ORIGINAL"
JM_ADDED=$(py "import json,sys;rows=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(next(r['id'] for r in rows if 'jm_new' in r['what']))" jm_list)
call jm_rev2 "{\"jsonrpc\":\"2.0\",\"id\":307,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$JM_ADDED\"}}}"
# Absent is its own state. Restoring an empty string would leave a row the post never had.
check "and undoing an added field removes the row rather than emptying it" \
  "$(docker compose exec -T cli wp eval "echo metadata_exists('post',$JM_ID,'jm_new') ? 'present' : 'gone';" 2>/dev/null | tr -d '\r\n')" "gone"
JM_SLASH=$(py "import json,sys;rows=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(next(r['id'] for r in rows if 'jm_slash' in r['what']))" jm_list)
call jm_rev3 "{\"jsonrpc\":\"2.0\",\"id\":308,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$JM_SLASH\"}}}"
# update_post_meta unslashes what it is handed, so a restore without wp_slash puts back a
# corrupted copy of a value that was recorded correctly.
check "and a restored value keeps its backslashes" \
  "$(docker compose exec -T cli wp eval "
      \$v = get_post_meta($JM_ID, 'jm_slash', true);
      echo is_array( \$v ) && \$v['sl'] === 'C:' . chr(92) . 'path' ? 'intact' : 'mangled';" 2>/dev/null | tr -d '\r\n')" "intact"

echo "-- one call, one unit of undo --"
# A single call routinely changes several things and they read as unrelated events without
# a shared id.
docker compose exec -T cli wp option delete gmcp_journal >/dev/null 2>&1
docker compose exec -T cli wp eval "
  update_post_meta($JM_ID,'g_a','A1'); update_post_meta($JM_ID,'g_b','B1'); update_post_meta($JM_ID,'g_c','C1');" >/dev/null 2>&1
jm_call jm_grp 309 "{\"ID\":$JM_ID,\"meta\":{\"g_a\":\"A2\",\"g_b\":\"B2\",\"g_c\":\"C2\"}}"
call jm_glist '{"jsonrpc":"2.0","id":310,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{"limit":40}}}'
check "three writes in one call share one call id" \
  "$(py "import json,sys;rows=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);g=[r for r in rows if r['what'].count('custom field') and r['what'].split('custom field ')[1][1] == 'g'];print(len({r['call'] for r in g}))" jm_glist)" "1"
JM_CALL=$(py "import json,sys;rows=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(rows[0]['call'])" jm_glist)
call jm_grev "{\"jsonrpc\":\"2.0\",\"id\":311,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"call\":\"$JM_CALL\"}}}"
check "and undoing the call puts all three back" \
  "$(docker compose exec -T cli wp eval "echo get_post_meta($JM_ID,'g_a',true) . get_post_meta($JM_ID,'g_b',true) . get_post_meta($JM_ID,'g_c',true);" 2>/dev/null | tr -d '\r\n')" "A1B1C1"
# The two mean different amounts of undo, and guessing which was meant is the wrong way to
# be helpful about a write.
call jm_both '{"jsonrpc":"2.0","id":312,"method":"tools/call","params":{"name":"wp_undo_change","arguments":{"id":"x","call":"y"}}}'
check "passing both id and call is refused" \
  "$(py "import json,sys;print('not both' in json.load(sys.stdin)['result']['content'][0]['text'])" jm_both)" "True"
docker compose exec -T cli wp post delete "$JM_ID" --force >/dev/null 2>&1

echo "-- uninstall leaves nothing behind --"
# Last, because it empties the plugin out from under everything above. Run through the
# real uninstall.php rather than `wp plugin uninstall`, which deletes the plugin directory
# and here that is a bind mount of the working tree.
#
# Asserted as "no gmcp_ row and no gmcp_ table survives" rather than as a list of names,
# so a table added later without an uninstall line fails this instead of being noticed by
# somebody reading the file. That is how wp_gmcp_meta_snapshots got left behind: it holds
# what a page design said before an agent changed it, and an uninstall left the lot.
docker compose exec -T cli wp eval '
  $a = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
  GMCP_Tokens::create( "uninstall probe", "admin", 0, [], $a ? $a[0]->ID : 0 );
  GMCP_Journal::install();
  GMCP_Audit::install();
  update_option( "gmcp_audit_last_full_verify", [ "at" => time() ], false );' >/dev/null 2>&1
# The control. Without it, a site that wrote nothing would pass the two checks below by
# having nothing to leave behind, which is the shape that has produced false passes here
# before.
UN_OPTS=$(docker compose exec -T cli wp db query "SELECT COUNT(*) FROM ${TABLE_PREFIX:-wp_}options WHERE option_name LIKE 'gmcp\\_%' AND option_name NOT LIKE 'gmcp\\_smoke\\_%'" --skip-column-names 2>/dev/null | tr -d '\r\n')
UN_TABS=$(docker compose exec -T cli wp db query "SHOW TABLES LIKE '${TABLE_PREFIX:-wp_}gmcp%'" --skip-column-names 2>/dev/null | tr -d '\r' | grep -c .)
check "CONTROL: the plugin has rows and tables to leave behind" \
  "$( [ "${UN_OPTS:-0}" -gt 3 ] && [ "${UN_TABS:-0}" -ge 4 ] && echo present || echo "NOTHING TO TEST ($UN_OPTS opts, $UN_TABS tables)" )" "present"
# Captured BEFORE the uninstall, which is the whole point: uninstall.php deletes this row
# along with everything else, so reading it afterwards saves an empty array and restores
# nothing. The first version of this did exactly that and the restore check below caught it.
UN_OPTS_SAVE=$(docker compose exec -T cli wp eval 'echo base64_encode( serialize( get_option( "gmcp_options", [] ) ) );' 2>/dev/null | tr -d '\r\n')
docker compose exec -T cli wp eval '
  define( "WP_UNINSTALL_PLUGIN", "guarded-mcp/guarded-mcp.php" );
  require WP_PLUGIN_DIR . "/guarded-mcp/uninstall.php";' >/dev/null 2>&1
# gmcp_smoke_ is this suite's own fixture prefix, not the plugin's, and uninstall.php is
# right not to touch it. Excluded by prefix rather than by naming the one that exists
# today, and only fixtures are excluded: a row the PLUGIN writes still fails this, which
# is the direction that matters. An allow-list of plugin rows would have been written to
# match today's code and would have missed the snapshot table exactly as uninstall.php did.
check "no option row survives the uninstall" \
  "$(docker compose exec -T cli wp db query "SELECT GROUP_CONCAT(option_name) FROM ${TABLE_PREFIX:-wp_}options WHERE option_name LIKE 'gmcp\\_%' AND option_name NOT LIKE 'gmcp\\_smoke\\_%'" --skip-column-names 2>/dev/null | tr -d '\r\n')" "NULL"
check "and no table survives it" \
  "$(docker compose exec -T cli wp db query "SHOW TABLES LIKE '${TABLE_PREFIX:-wp_}gmcp%'" --skip-column-names 2>/dev/null | tr -d '\r' | grep -c .)" "0"
# Put the site back, or the next run of any suite starts against a gutted install and
# reports a hundred features as broken.
#
# Reactivating is not enough. The settings row goes with everything else, so the plugin
# comes back at its defaults with the admin tool group OFF, and smoke.sh then finds four
# prompts where it expects six: two of them need admin tools, so they are filtered out of
# prompts/list for a caller who cannot call them. That reads as two broken prompts and is
# a wiped setting. The row is captured before the uninstall and written back after.
docker compose exec -T cli wp plugin deactivate guarded-mcp >/dev/null 2>&1
docker compose exec -T cli wp plugin activate guarded-mcp >/dev/null 2>&1
if [ -n "$UN_OPTS_SAVE" ]; then
  docker compose exec -T cli wp eval '
    $o = unserialize( base64_decode( "'"$UN_OPTS_SAVE"'" ) );
    if ( is_array( $o ) && $o ) { update_option( "gmcp_options", $o, false ); }' >/dev/null 2>&1
fi
# Said out loud rather than assumed, because a restore that silently did nothing would
# leave the next suite to report the consequence instead of this one.
check "the settings survive the uninstall check that wiped them" \
  "$(docker compose exec -T cli wp eval 'echo !empty( get_option( "gmcp_options", [] )["mcp_tools_admin"] ) ? "restored" : "LOST";' 2>/dev/null | tr -d '\r\n')" "restored"

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
