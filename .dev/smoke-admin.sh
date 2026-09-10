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
URL_TOKEN="$BASE/wp-json/mcp/v1/testtoken1234567890"
TOK='testtoken1234567890'
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

call_url_token() { # same, but authenticating through the token-in-path route
  curl -sS -X POST "$URL_TOKEN" \
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
  docker compose exec -T cli wp option delete gmcp_tokens >/dev/null 2>&1
  # A role that is dangerous WITHOUT holding edit_posts: the case the first guard missed.
  docker compose exec -T cli wp eval 'remove_role("api_admin"); add_role("api_admin","API Admin",["read"=>true,"manage_options"=>true]);' >/dev/null 2>&1
}
reset_state

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

echo "-- URL-token endpoint ceiling --"
call_url_token ut_read '{"jsonrpc":"2.0","id":13,"method":"tools/call","params":{"name":"wp_get_posts","arguments":{"posts_per_page":1}}}'
check "URL token may still read" "$(verdict ut_read)" "ok"
call_url_token ut_inst '{"jsonrpc":"2.0","id":14,"method":"tools/call","params":{"name":"wp_install_plugin","arguments":{"slug":"hello-dolly"}}}'
check "URL token may not install" "$(verdict ut_inst)" "error"
call_url_token ut_theme '{"jsonrpc":"2.0","id":15,"method":"tools/call","params":{"name":"wp_activate_theme","arguments":{"stylesheet":"twentytwentyfour"}}}'
check "URL token may not switch theme" "$(verdict ut_theme)" "error"

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

echo "-- URL-token ceiling covers reconfiguration too --"
call_url_token ut_set '{"jsonrpc":"2.0","id":40,"method":"tools/call","params":{"name":"wp_update_settings","arguments":{"settings":{"blogdescription":"via url token"}}}}'
check "URL token may not change settings" "$(verdict ut_set)" "error"
call_url_token ut_perm '{"jsonrpc":"2.0","id":41,"method":"tools/call","params":{"name":"wp_set_permalink_structure","arguments":{"structure":""}}}'
check "URL token may not change permalinks" "$(verdict ut_perm)" "error"

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
check "revoking a key locks it out immediately" \
  "$(docker compose exec -T cli wp eval '$r=GMCP_Tokens::all();foreach($r as $k=>$v){GMCP_Tokens::revoke($k);}echo count(GMCP_Tokens::all());' 2>/dev/null | tr -d '\r\n')" "0"
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
docker compose exec -T cli wp option delete gmcp_tokens >/dev/null 2>&1

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
docker compose exec -T cli wp option delete gmcp_tokens >/dev/null 2>&1

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
# Silently skipping it would leave someone believing the change is reversible.
check "and the entry says why it cannot be reverted" \
  "$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])[0].get("not_reversible_because",""))' c_list)" \
  "The previous value looked like it held a credential, so it was never stored."
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
check "default_role was not restored to the more privileged value" \
  "$(docker compose exec -T cli wp option get default_role 2>/dev/null | tr -d '\r\n')" "subscriber"
# Telling a caller something is reversible and then refusing is its own bug.
kcall m_list "$K_MIX" '{"jsonrpc":"2.0","id":154,"method":"tools/call","params":{"name":"wp_list_changes","arguments":{}}}'
check "the listing already says it is not reversible for this caller" \
  "$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(all(not x['reversible'] for x in e if 'default_role' in x['what']))" m_list)" "True"
# The gate must not cost a caller the reverts it is entitled to.
kcall m_post "$K_MIX" "{\"jsonrpc\":\"2.0\",\"id\":155,\"method\":\"tools/call\",\"params\":{\"name\":\"wp_undo_change\",\"arguments\":{\"id\":\"$(py "import json,sys;e=json.loads(json.load(sys.stdin)['result']['content'][0]['text']);print(next(x['id'] for x in e if x['what'].startswith('Post')))" m_all)\"}}}"
check "a write-level key can still revert a post change" "$(verdict m_post)" "ok"
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/hookprobe.php' >/dev/null 2>&1
docker compose exec -T cli wp post delete "$M_POST" --force >/dev/null 2>&1
docker compose exec -T cli wp option delete gmcp_tokens >/dev/null 2>&1
docker compose exec -T cli wp option update default_role subscriber >/dev/null 2>&1

# touch() is a read-modify-write of the row holding every key. Writing back a copy
# fetched before the throttle check resurrected a key revoked in between.
check "a revoked key is not resurrected by a later touch" \
  "$(docker compose exec -T cli wp eval '$a=GMCP_Tokens::create("Doomed","readonly",0,[]);$r=GMCP_Tokens::all();$r[$a["id"]]["last_used"]=0;update_option("gmcp_tokens",$r,false);$stale=GMCP_Tokens::all();GMCP_Tokens::revoke($a["id"]);GMCP_Tokens::touch($a["id"]);echo isset(GMCP_Tokens::all()[$a["id"]])?1:0;' 2>/dev/null | tr -d '\r\n')" "0"
docker compose exec -T cli wp option delete gmcp_tokens >/dev/null 2>&1

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
docker compose exec -T cli wp option delete gmcp_tokens >/dev/null 2>&1

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

echo "-- the URL-token route cannot change anything an admin cares about --"
# That endpoint puts the secret in the request path, where every proxy log, access log and
# browser history keeps it. The blocklist used to name eleven tools covering plugins,
# themes, settings and permalinks, and had never included menus or widgets. Both are admin
# level, and a widget is arbitrary markup on every page, which is a wider blast radius
# than most of what the list did cover. It is a rule now: every admin-level tool.
docker compose exec -T cli wp eval 'foreach(wp_get_nav_menus() as $m){wp_delete_nav_menu($m->term_id);}' >/dev/null 2>&1
for tool_call in \
  'wp_create_menu:{"name":"URL token menu"}' \
  'wp_add_widget:{"sidebar":"sidebar-1","id_base":"text","settings":{"title":"x","text":"y"}}' \
  'wp_delete_plugin:{"plugin":"akismet/akismet.php"}' \
  'wp_update_option:{"key":"blogname","value":"pwned"}' \
  'wp_get_users:{}' \
  'wp_get_site_health:{}' \
  'wp_upload_request:{"filename":"x.png"}' ; do
  tool="${tool_call%%:*}"; args="${tool_call#*:}"
  call_url_token ut_one "{\"jsonrpc\":\"2.0\",\"id\":190,\"method\":\"tools/call\",\"params\":{\"name\":\"$tool\",\"arguments\":$args}}"
  check "$tool is refused over the URL-token route" "$(verdict ut_one)" "error"
done
# Assert the effect, not the refusal text: a refusal that still wrote would look identical.
check "and no menu was created" \
  "$(docker compose exec -T cli wp menu list --format=count 2>/dev/null | tr -d '\r\n')" "0"
check "and the site name is untouched" \
  "$(docker compose exec -T cli wp option get blogname 2>/dev/null | tr -d '\r\n')" "MCP Test"
# The route exists for hosts that strip the Authorization header, so reads must still work
# or it is not a fallback at all.
call_url_token ut_read '{"jsonrpc":"2.0","id":191,"method":"tools/call","params":{"name":"wp_get_posts","arguments":{"limit":1}}}'
check "read-level tools still work there" "$(verdict ut_read)" "ok"
call_url_token ut_ping '{"jsonrpc":"2.0","id":192,"method":"tools/call","params":{"name":"mcp_ping","arguments":{}}}'
check "and so does the health check" "$(verdict ut_ping)" "ok"
call_url_token ut_brief '{"jsonrpc":"2.0","id":193,"method":"tools/call","params":{"name":"wp_site_briefing","arguments":{}}}'
check "and orientation, which changes nothing" "$(verdict ut_brief)" "ok"
call_url_token ut_brief2 '{"jsonrpc":"2.0","id":194,"method":"tools/call","params":{"name":"wp_site_briefing","arguments":{}}}'
call_url_token ut_write '{"jsonrpc":"2.0","id":195,"method":"tools/call","params":{"name":"wp_create_post","arguments":{"post_title":"Written over the URL token route","post_status":"publish","post_content":"body"}}}'
check "the route is not read-only, and the docs say so" "$(verdict ut_write)" "ok"
check "a post written there really lands" \
  "$(docker compose exec -T cli wp post list --post_status=publish --title='Written over the URL token route' --format=count 2>/dev/null | tr -d '\r\n')" "1"
docker compose exec -T cli wp eval '$p=get_page_by_title("Written over the URL token route","OBJECT","post"); if($p){wp_delete_post($p->ID,true);}' >/dev/null 2>&1
# wp_upload_request is write level and writes nothing: it mints a URL on a route whose
# permission callback returns true unconditionally, so the caller walks away holding an
# unauthenticated upload endpoint. A level rule cannot see that, hence the exception list.
#
# Its own call and its own file. This read $OUT/ut_one, which the loop above overwrites on
# every iteration, so it was asserting against whatever happened to run last and passed
# for that reason rather than this one. Reordering the loop would have broken it silently.
call_url_token ut_upload '{"jsonrpc":"2.0","id":196,"method":"tools/call","params":{"name":"wp_upload_request","arguments":{"filename":"x.png"}}}'
check "no upload URL was handed out" "$(grep -c upload_url "$OUT/ut_upload" || true)" "0"
# The settings tool returns the administration email, which is a person's address rather
# than a fact about the site, and changing it already costs a confirmation token.
call_url_token ut_settings '{"jsonrpc":"2.0","id":197,"method":"tools/call","params":{"name":"wp_get_settings","arguments":{}}}'
check "the settings tool is refused there" "$(verdict ut_settings)" "error"
check "and the administration email did not come out" \
  "$(grep -c 'a@b.test' "$OUT/ut_settings" || true)" "0"
# Deliberately still reachable: all of this is in wp_site_briefing, which is read level and
# allowed there, so blocking them one at a time would be a line drawn where nothing changes.
for readable in wp_list_menus wp_list_sidebars wp_list_themes wp_get_permalink_structure; do
  call_url_token ut_read_one "{\"jsonrpc\":\"2.0\",\"id\":198,\"method\":\"tools/call\",\"params\":{\"name\":\"$readable\",\"arguments\":{}}}"
  check "$readable still reads there, as documented" "$(verdict ut_read_one)" "ok"
done

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
check "the option name stays readable" \
  "$(docker compose exec -T cli wp eval 'global $wpdb;echo (int)(bool)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}gmcp_audit WHERE args LIKE \"%acme_gw%\"");' 2>/dev/null | tr -d '\r\n')" "1"

# An edited row and a deleted row have to look different from a real one, or this is a
# history rather than an audit.
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

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
