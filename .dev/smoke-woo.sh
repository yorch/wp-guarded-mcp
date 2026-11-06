#!/bin/bash
# Smoke test for the WooCommerce tools.
#
# Its own file because it needs WooCommerce installed and the woo tool group switched
# on, and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin install woocommerce --activate
#   ./smoke-woo.sh
#
# Every check corresponds to something that was wrong or could quietly go wrong, and
# each asserts the stored state rather than the wording of the reply.
set -u
# The site under test. Override to run against a second stack, which a parallel worktree
# needs: this suite is destructive, and two runs sharing a database produce failures that
# look like real regressions in both.
#
#   GMCP_URL=http://localhost:8081 ./smoke-woo.sh
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
OUT=$(mktemp -d)
pass=0; fail=0

call() { curl -sS -X POST "$URL" -H "Authorization: Bearer $TOK" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$2" -o "$OUT/$1"; }
check() { if [ "$2" = "$3" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1));
  else printf '  FAIL  %s (got %s, want %s)\n' "$1" "$2" "$3"; fail=$((fail+1)); fi; }
py() { python3 -c "$1" < "$OUT/$2"; }
verdict() { py 'import json,sys;d=json.load(sys.stdin);print("error" if ("error" in d or d.get("result",{}).get("isError")) else "ok")' "$1"; }
# A refusal may arrive as a JSON-RPC error or as an isError result, and which one it is
# depends on where in the stack it was decided. Read the text from wherever it landed.
body() { py 'import json,sys;d=json.load(sys.stdin);print(d["error"]["message"] if "error" in d else d["result"]["content"][0]["text"])' "$1"; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
kcall() { curl -sS -X POST "$URL" -H "Authorization: Bearer $2" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$3" -o "$OUT/$1"; }

# Asked directly rather than through wpc: that helper ends in a pipe to tr, and a
# pipeline exits with the status of its last command, so the test always read as yes and
# the guard never fired. The suite then ran against a site with no shop and reported
# thirty failures that were an absent plugin rather than a regression.
if ! docker compose exec -T cli wp plugin is-active woocommerce >/dev/null 2>&1; then
  echo "WooCommerce is not active. Install it first:"
  echo "  docker compose exec -T cli wp plugin install woocommerce --activate"
  exit 2
fi
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_woo"]=true;update_option("gmcp_options",$o);' >/dev/null 2>&1

# Leave nothing behind from a previous run. Orders are deleted through WooCommerce's own
# API rather than with wp_delete_post: with HPOS on — which is the default on a fresh store —
# an order is a row in wc_orders with no wp_posts row at all, so deleting by post id removes
# nothing and every run leaves its fixtures behind. The counts in this file are exact, so
# leftovers from run N make run N+1 report a tool defect that is not one.
docker compose exec -T cli wp eval '
  foreach ( wc_get_orders( [ "limit" => -1, "return" => "ids", "status" => array_keys( wc_get_order_statuses() ) ] ) as $id ) {
    $o = wc_get_order( $id );
    if ( $o ) { $o->delete( true ); }
  }
  foreach ( get_posts( [ "post_type" => [ "product", "shop_order" ], "post_status" => "any", "numberposts" => -1 ] ) as $p ) { wp_delete_post( $p->ID, true ); }
' >/dev/null 2>&1

echo "-- the tools only exist when the shop does --"
call wlist '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "woo tools are registered" \
  "$(py 'import json,sys;print(len([t for t in json.load(sys.stdin)["result"]["tools"] if t["name"].startswith("wc_")]))' wlist)" "12"

echo "-- products --"
# Created as a draft unless asked otherwise: a product live with no price is buyable.
call p_draft '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wc_create_product","arguments":{"name":"Draft Widget"}}}'
# By title, not by slug: WordPress does not assign a post_name to a draft, so looking
# one up by slug finds nothing and the check passes or fails for the wrong reason.
check "a product is created as a draft by default" \
  "$(wpc post list --post_type=product --post_status=any --title='Draft Widget' --field=post_status)" "draft"
# Inventing taxonomy terms from a model's guess at a name is how a shop ends up with
# Shoes, shoes and "Shoes ".
call p_cat '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wc_create_product","arguments":{"name":"Test Widget","regular_price":"19.99","sku":"TW-1","manage_stock":true,"stock_quantity":5,"status":"publish","categories":["No Such Category"]}}}'
check "an unknown category is reported, not created" \
  "$(body p_cat | grep -c 'were not created')" "1"
check "and really was not created" \
  "$(wpc term list product_cat --slug=no-such-category --format=count)" "0"
P_ID=$(wpc post list --post_type=product --name=test-widget --field=ID)

call p_stock "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_set_stock\",\"arguments\":{\"id\":$P_ID,\"delta\":-5}}}"
check "a stock delta applies" "$(wpc post meta get "$P_ID" _stock | awk '{printf "%d", $1}')" "0"
# A quantity of zero with a status still saying instock leaves the product purchasable.
check "stock status follows the quantity down" "$(wpc post meta get "$P_ID" _stock_status)" "outofstock"

call p_prev "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$P_ID,\"regular_price\":\"24.99\",\"preview\":true}}}"
check "a product preview changes nothing" "$(wpc post meta get "$P_ID" _regular_price)" "19.99"
call p_upd "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$P_ID,\"regular_price\":\"24.99\"}}}"
check "and the real update does" "$(wpc post meta get "$P_ID" _regular_price)" "24.99"

# status is written straight to post_status by WC_Product::set_status(), which validates
# nothing. 'trash' that way skipped wp_trash_post(), and a made-up status hid the product
# from every admin list. Read the stored status back after each hostile call.
call p_st_ctl "{\"jsonrpc\":\"2.0\",\"id\":50,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$P_ID,\"status\":\"draft\"}}}"
check "CONTROL: a valid status change goes through" "$(wpc post get "$P_ID" --field=post_status)" "draft"
call p_st_back "{\"jsonrpc\":\"2.0\",\"id\":51,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$P_ID,\"status\":\"publish\"}}}"
check "CONTROL: and back to publish" "$(wpc post get "$P_ID" --field=post_status)" "publish"
call p_st_trash "{\"jsonrpc\":\"2.0\",\"id\":52,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$P_ID,\"status\":\"trash\"}}}"
T_STORED=$(wpc post get "$P_ID" --field=post_status)
[ "$T_STORED" = "publish" ] || docker compose exec -T cli wp post update "$P_ID" --post_status=publish >/dev/null 2>&1
check "trashing through the update tool is refused" "$(verdict p_st_trash)" "error"
check "and the stored status is untouched" "$T_STORED" "publish"
check "and the refusal points at the delete tool" "$(body p_st_trash | grep -c 'wp_delete_post')" "1"
call p_st_junk "{\"jsonrpc\":\"2.0\",\"id\":53,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$P_ID,\"status\":\"foo\"}}}"
J_STORED=$(wpc post get "$P_ID" --field=post_status)
[ "$J_STORED" = "publish" ] || docker compose exec -T cli wp post update "$P_ID" --post_status=publish >/dev/null 2>&1
check "an invented status is refused" "$(verdict p_st_junk)" "error"
check "and the stored status is untouched by it" "$J_STORED" "publish"
call p_st_prev "{\"jsonrpc\":\"2.0\",\"id\":54,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$P_ID,\"status\":\"trash\",\"preview\":true}}}"
check "a preview of a refused status is refused too" "$(verdict p_st_prev)" "error"

# The description said search matches SKUs; it is a WP_Query text search and does not.
call p_sku "{\"jsonrpc\":\"2.0\",\"id\":55,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_list_products\",\"arguments\":{\"sku\":\"TW-1\"}}}"
check "CONTROL: the sku argument finds the product that has that SKU" \
  "$(py 'import json,sys;print([p["id"] for p in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])])' p_sku)" "[$P_ID]"
call p_sku_none "{\"jsonrpc\":\"2.0\",\"id\":56,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_list_products\",\"arguments\":{\"sku\":\"NO-SUCH-SKU\"}}}"
check "and a SKU nothing has finds nothing" \
  "$(py 'import json,sys;print(len(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])))' p_sku_none)" "0"

# A delta is applied in the database in one statement. Simulate a sale landing between the
# tool reading the stock and writing it: the first read of the quantity decrements the
# stored figure behind the tool's back, as a concurrent checkout would.
docker exec -u 0 "${COMPOSE_PROJECT_NAME:-wptest}-wp-1" mkdir -p /var/www/html/wp-content/mu-plugins
docker compose exec -T wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/stock-race.php <<"PHPEOF"
<?php
add_filter( "woocommerce_product_get_stock_quantity", function ( $q ) {
  $id = (int) get_option( "probe_race", 0 );
  if ( $id ) {
    delete_option( "probe_race" );
    global $wpdb;
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value - 1 WHERE post_id = %d AND meta_key = %s", $id, "_stock" ) );
  }
  return $q;
} );
PHPEOF' >/dev/null 2>&1
docker compose exec -T cli wp post meta update "$P_ID" _stock 10 >/dev/null 2>&1
docker compose exec -T cli wp option update probe_race "$P_ID" >/dev/null 2>&1
call p_race "{\"jsonrpc\":\"2.0\",\"id\":57,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_set_stock\",\"arguments\":{\"id\":$P_ID,\"delta\":3}}}"
R_LEFT=$(wpc option get probe_race)
R_STORED=$(wpc post meta get "$P_ID" _stock)
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/stock-race.php' >/dev/null 2>&1
docker compose exec -T cli wp option delete probe_race >/dev/null 2>&1
check "CONTROL: the simulated concurrent sale fired" "$R_LEFT" ""
# 10, minus the concurrent sale, plus 3. A read-modify-write stores 13 and loses the sale.
check "a stock delta does not overwrite a sale that landed in between" "$(printf '%s' "$R_STORED" | awk '{printf "%d", $1}')" "12"
# Put it back, through the absolute path, which also goes through the atomic writer.
call p_zero "{\"jsonrpc\":\"2.0\",\"id\":58,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_set_stock\",\"arguments\":{\"id\":$P_ID,\"quantity\":0}}}"
check "an absolute quantity applies, and the status follows it down" \
  "$(wpc post meta get "$P_ID" _stock | awk '{printf "%d", $1}'):$(wpc post meta get "$P_ID" _stock_status)" "0:outofstock"

echo "-- orders --"
O_ID=$(docker compose exec -T cli wp eval "
  \$o = wc_create_order();
  \$o->add_product( wc_get_product($P_ID), 2 );
  \$o->set_address( [ 'first_name'=>'Ada','last_name'=>'Lovelace','email'=>'ada@example.com' ], 'billing' );
  \$o->calculate_totals(); \$o->set_status('pending'); \$o->save(); echo \$o->get_id();" 2>/dev/null | tr -d '\r\n')

# The bug this suite exists for. ltrim( \$key, 'wc-' ) strips a CHARACTER SET, not a
# prefix, so 'wc-completed' became 'ompleted'. Both the input and the valid list were
# mangled identically, so validation passed, WooCommerce was handed a status that does
# not exist, and it silently reset the order to pending while the tool reported success.
call o_done "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$O_ID,\"status\":\"completed\"}}}"
check "a status starting with c really is applied" \
  "$(docker compose exec -T cli wp eval "echo wc_get_order($O_ID)->get_status();" 2>/dev/null | tr -d '\r\n')" "completed"
check "the reply names the status it actually set" "$(body o_done | grep -c 'to completed')" "1"
# Silence here would be worse than the wrong answer: somebody has been written to. Assert
# the address rather than the word "email", which the old wording carried either way.
check "and names the customer it wrote to" "$(body o_done | grep -c 'ada@example.com')" "1"
call o_same "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$O_ID,\"status\":\"completed\"}}}"
check "setting the status it already has sends nothing" "$(body o_same | grep -c 'no email was sent')" "1"
call o_bad "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$O_ID,\"status\":\"teleported\"}}}"
check "an unknown status is refused" "$(verdict o_bad)" "error"
check "and the refusal lists the real ones, unmangled" "$(body o_bad | grep -c 'completed, cancelled')" "1"

call o_filter '{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"wc_list_orders","arguments":{"status":"completed"}}}'
check "filtering by a c-status finds the order" "$(py 'import json,sys;print(len(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])))' o_filter)" "1"
call o_none '{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"wc_list_orders","arguments":{"status":"cancelled"}}}'
# A broken filter returns everything, which reads as a working tool with a busy shop.
check "filtering by a status nothing has finds nothing" "$(py 'import json,sys;print(len(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])))' o_none)" "0"

call o_get "{\"jsonrpc\":\"2.0\",\"id\":12,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_get_order\",\"arguments\":{\"id\":$O_ID}}}"
check "one order reads back with its line items" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print(d["items"][0]["quantity"])' o_get)" "2"
call o_note "{\"jsonrpc\":\"2.0\",\"id\":13,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_add_order_note\",\"arguments\":{\"id\":$O_ID,\"note\":\"internal only\"}}}"
check "a note is private unless asked otherwise" "$(body o_note | grep -c 'customer was not told')" "1"

echo "-- a refund is not an order --"
# wc_get_order() resolves a refund id too, and a refund has no get_address(), so the tool
# died with an undefined-method Error that only the server's catch-all turned into a reply.
RF_ID=$(docker compose exec -T cli wp eval "
  \$ref = wc_create_refund( [ 'order_id' => $O_ID, 'amount' => '1.00', 'reason' => 'suite', 'refund_payment' => false ] );
  echo is_wp_error( \$ref ) ? 0 : \$ref->get_id();" 2>/dev/null | tr -d '\r\n')
check "CONTROL: the refund fixture exists" "$(docker compose exec -T cli wp eval "echo wc_get_order($RF_ID) instanceof WC_Order_Refund ? 'refund' : 'other';" 2>/dev/null | tr -d '\r\n')" "refund"
call rf_get "{\"jsonrpc\":\"2.0\",\"id\":60,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_get_order\",\"arguments\":{\"id\":$RF_ID}}}"
check "reading a refund id as an order is refused" "$(verdict rf_get)" "error"
check "and says what it is, instead of failing on a missing method" "$(body rf_get | grep -c 'not an order')" "1"
call rf_ctl "{\"jsonrpc\":\"2.0\",\"id\":61,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_get_order\",\"arguments\":{\"id\":$O_ID}}}"
check "CONTROL: a real order still reads" "$(verdict rf_ctl)" "ok"
docker compose exec -T cli wp eval "\$r = wc_get_order($RF_ID); if ( \$r ) { \$r->delete( true ); }" >/dev/null 2>&1

echo "-- reporting --"
call s_sum '{"jsonrpc":"2.0","id":14,"method":"tools/call","params":{"name":"wc_sales_summary","arguments":{"days":30}}}'
check "the sales summary counts the paid order" \
  "$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["orders"])' s_sum)" "1"
call s_brief '{"jsonrpc":"2.0","id":15,"method":"tools/call","params":{"name":"wc_store_briefing","arguments":{}}}'
check "the briefing reports order counts by real status names" \
  "$(py 'import json,sys;print("completed" in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["orders"])' s_brief)" "True"
check "and flags the out-of-stock product" \
  "$(py 'import json,sys;print("Test Widget" in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["products"]["out_of_stock"])' s_brief)" "True"
check "the briefing counts the paid order in the last 30 days" \
  "$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["last_30_days"]["orders"])' s_brief)" "1"
check "and reports the order counts it got from WooCommerce's own counter" \
  "$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["orders"].get("completed"))' s_brief)" "1"
check "revenue is labelled gross, with a currency" \
  "$(py 'import json,sys;d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]);print("gross" in d["revenue_basis"] and bool(d["revenue_by_currency"]))' s_sum)" "True"
check "CONTROL: nothing was truncated at the default cap" \
  "$(py 'import json,sys;print("truncated" in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]))' s_sum)" "False"
# Stop the walk after one order and the reply must say so, with the true total.
docker exec -u 0 "${COMPOSE_PROJECT_NAME:-wptest}-wp-1" mkdir -p /var/www/html/wp-content/mu-plugins
docker compose exec -T wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/report-cap.php <<"PHPEOF"
<?php
add_filter( "gmcp_woo_report_order_cap", function () { return 1; } );
PHPEOF' >/dev/null 2>&1
CAP_ORD=$(docker compose exec -T cli wp eval '
  $o = wc_create_order(); $o->set_status( "processing" ); $o->set_total( "10.00" ); $o->save(); echo $o->get_id();' 2>/dev/null | tr -d '\r\n')
call s_cap '{"jsonrpc":"2.0","id":58,"method":"tools/call","params":{"name":"wc_sales_summary","arguments":{"days":30}}}'
call s_cap_b '{"jsonrpc":"2.0","id":59,"method":"tools/call","params":{"name":"wc_store_briefing","arguments":{}}}'
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/report-cap.php' >/dev/null 2>&1
docker compose exec -T cli wp eval "\$o = wc_get_order($CAP_ORD); if ( \$o ) { \$o->delete( true ); }" >/dev/null 2>&1
check "a capped summary still reports the full order count" \
  "$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["orders"])' s_cap)" "2"
check "and says it stopped early" "$(py 'import json,sys;print("truncated" in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"]))' s_cap)" "True"
check "the briefing says so too" "$(py 'import json,sys;print("truncated" in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["last_30_days"])' s_cap_b)" "True"

echo "-- who actually gets emailed --"
# Predicting this from a list of statuses was wrong in both directions, and measuring it
# on a real shop corrected both the code and the review that flagged it. on-hold and
# failed do mail the customer; cancelled goes only to the shop; and whether refunded
# mails depends on the transition, not the target status. Any list would also go stale
# as shops add statuses and plugins add mail to transitions that had none.
M_ORD=$(docker compose exec -T cli wp eval '
  $o = wc_create_order();
  $p = wc_get_products( [ "limit" => 1 ] );
  if ( $p ) { $o->add_product( $p[0], 1 ); }
  $o->set_address( [ "first_name" => "Ada", "email" => "customer@example.test" ], "billing" );
  $o->calculate_totals(); $o->set_status( "pending" ); $o->save(); echo $o->get_id();' 2>/dev/null | tr -d '\r\n')
call e_hold "{\"jsonrpc\":\"2.0\",\"id\":30,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$M_ORD,\"status\":\"on-hold\"}}}"
# The status the old hardcoded list left out entirely, and the one a shop uses for bank
# transfers, so it is not an exotic path.
check "moving to on-hold reports the customer was emailed" \
  "$(body e_hold | grep -c 'notification for this change to customer@example.test')" "1"
call e_cancel "{\"jsonrpc\":\"2.0\",\"id\":31,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$M_ORD,\"status\":\"cancelled\"}}}"
# The old list claimed this one mailed the customer. It mails the shop.
check "cancelling reports that the customer was not emailed" \
  "$(body e_cancel | grep -c 'none of them to the customer')" "1"
check "and does not claim the customer was reached" \
  "$(body e_cancel | grep -c 'customer@example.test')" "0"

# The wp_mail filter fires before pre_wp_mail, so an SMTP or mail-disabling plugin
# intercepting delivery does not hide the fact that WooCommerce decided to write.
docker compose exec -T wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/mailkill.php <<"PHPEOF"
<?php
add_filter( "pre_wp_mail", function () { return true; }, 1 );
PHPEOF' >/dev/null 2>&1
call e_killed "{\"jsonrpc\":\"2.0\",\"id\":38,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$M_ORD,\"status\":\"failed\"}}}"
check "a mail-intercepting plugin does not hide the notification" \
  "$(body e_killed | grep -c 'customer@example.test')" "1"
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/mailkill.php' >/dev/null 2>&1

# A shop that defers its transactional email sends it after this request, once the capture
# is gone. "No email was sent" was then a statement the customer's inbox contradicted.
D_ORD=$(docker compose exec -T cli wp eval '
  $o = wc_create_order();
  $o->set_address( [ "first_name" => "Ada", "email" => "defer@example.test" ], "billing" );
  $o->set_total( "5.00" ); $o->set_status( "pending" ); $o->save(); echo $o->get_id();' 2>/dev/null | tr -d '\r\n')
docker compose exec -T wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/defer-mail.php <<"PHPEOF"
<?php
add_filter( "woocommerce_defer_transactional_emails", "__return_true" );
PHPEOF' >/dev/null 2>&1
check "CONTROL: the shop really defers its emails now" \
  "$(docker compose exec -T cli wp eval 'echo has_action("woocommerce_order_status_completed", ["WC_Emails","queue_transactional_email"]) !== false ? "deferred" : "immediate";' 2>/dev/null | tr -d '\r\n')" "deferred"
call e_defer "{\"jsonrpc\":\"2.0\",\"id\":39,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$D_ORD,\"status\":\"on-hold\"}}}"
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/defer-mail.php' >/dev/null 2>&1
check "CONTROL: the change itself happened" "$(docker compose exec -T cli wp eval "echo wc_get_order($D_ORD)->get_status();" 2>/dev/null | tr -d '\r\n')" "on-hold"
check "a deferring shop is not told that no email was sent" "$(body e_defer | grep -c 'No email was sent')" "0"
check "and the reply says the recipients cannot be reported" "$(body e_defer | grep -c 'cannot be reported')" "1"
docker compose exec -T cli wp eval "\$o = wc_get_order($D_ORD); if ( \$o ) { \$o->delete( true ); }" >/dev/null 2>&1

# The flag was documented as emailing the note and was never read: set_status records
# whatever it is given privately, so a note meant for the customer stayed internal.
call e_priv "{\"jsonrpc\":\"2.0\",\"id\":32,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$M_ORD,\"status\":\"processing\",\"note\":\"internal\"}}}"
check "a note without the flag stays private" \
  "$(docker compose exec -T cli wp eval "echo count(wc_get_order_notes(['order_id'=>$M_ORD,'type'=>'customer']));" 2>/dev/null | tr -d '\r\n')" "0"
call e_cust "{\"jsonrpc\":\"2.0\",\"id\":33,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$M_ORD,\"status\":\"completed\",\"note\":\"On its way\",\"customer_note\":true}}}"
check "and customer_note really reaches the customer" \
  "$(docker compose exec -T cli wp eval "echo count(wc_get_order_notes(['order_id'=>$M_ORD,'type'=>'customer']));" 2>/dev/null | tr -d '\r\n')" "1"
docker compose exec -T cli wp post delete "$M_ORD" --force >/dev/null 2>&1

echo "-- personal data is not cheaper than a username --"
# wp_get_users is admin and returns no email at all. Orders and customers carry names,
# email addresses and home addresses, so leaving them at read meant the lowest-privilege
# key on the system read customers' addresses while being refused a list of usernames.
K_RO=$(docker compose exec -T cli wp eval '$a=GMCP_Tokens::create("Read only","readonly",0,[]);echo $a["secret"];' 2>/dev/null | tr -d '\r\n')
kcall pii_list "$K_RO" '{"jsonrpc":"2.0","id":34,"method":"tools/call","params":{"name":"wc_list_orders","arguments":{}}}'
check "a readonly key cannot list orders" "$(verdict pii_list)" "error"
kcall pii_cust "$K_RO" '{"jsonrpc":"2.0","id":35,"method":"tools/call","params":{"name":"wc_list_customers","arguments":{}}}'
check "nor customers" "$(verdict pii_cust)" "error"
# The shop still has to be usable at read level for everything that carries no PII.
kcall pii_prod "$K_RO" '{"jsonrpc":"2.0","id":36,"method":"tools/call","params":{"name":"wc_list_products","arguments":{}}}'
check "but can still read products" "$(verdict pii_prod)" "ok"
kcall pii_sales "$K_RO" '{"jsonrpc":"2.0","id":37,"method":"tools/call","params":{"name":"wc_sales_summary","arguments":{}}}'
check "and sales figures, which name nobody" "$(verdict pii_sales)" "ok"
kcall pii_brief "$K_RO" '{"jsonrpc":"2.0","id":38,"method":"tools/call","params":{"name":"wc_store_briefing","arguments":{}}}'
for f in pii_prod pii_sales pii_brief; do
  check "nothing customer-shaped comes back from $f" \
    "$(grep -cE '@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|billing|shipping|first_name|last_name' "$OUT/$f" || true)" "0"
done
# Only the key this block made. Deleting the whole option was harmless while the suite
# authenticated with a shared token that lived somewhere else; now the suite's own
# credential is a key, and wiping them all revoked it mid-run. Every request after this
# point came back 401, and the checks reported the features as broken rather than the
# credential as gone.
docker compose exec -T cli wp eval '
  foreach ( GMCP_Tokens::all() as $k ) {
    if ( ( $k["label"] ?? "" ) === "Read only" ) { GMCP_Tokens::revoke( $k["id"] ); }
  }' >/dev/null 2>&1

echo "-- the shop tools announce their writes --"
# These go through the WooCommerce CRUD classes, so nothing here touches wp_update_post
# and none of the usual content-change paths ran. An integration purging a full-page cache
# saw a price change as silence, and most WooCommerce sites run such a cache, so the
# visible symptom was a shopper still being shown the old price.
# The directory first, as root. It does not exist on a fresh WordPress, `cat >` into a
# missing directory fails, the redirect below hides the error, and the probe is simply
# never installed. The checks then report the hook as silent, which is a true statement
# about a fixture that was never written rather than about the hook.
docker exec -u 0 "${COMPOSE_PROJECT_NAME:-wptest}-wp-1" mkdir -p /var/www/html/wp-content/mu-plugins
docker compose exec -T wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/mutate-probe.php <<"PHPEOF"
<?php
add_action( "gmcp_mutate", function ( $tool ) {
  $seen = get_option( "probe_mutate", [] );
  $seen[] = $tool;
  update_option( "probe_mutate", $seen, false );
}, 10, 1 );
PHPEOF'
# The control for the two assertions below, and it has to come first. "But a read does not"
# is not one: it expects silence, which is exactly what a probe that was never installed
# reports, so it passed throughout the period the probe was broken.
check "CONTROL: the mutation probe is installed and can observe" \
  "$(docker compose exec -T wp sh -c 'test -f /var/www/html/wp-content/mu-plugins/mutate-probe.php && echo present || echo absent' 2>/dev/null | tr -d '\r\n')" "present"
docker compose exec -T cli wp option delete probe_mutate >/dev/null 2>&1
# The probe is a fixture, so it gets the same treatment as any other: asserted, not
# assumed. Without this, a fixture that was never written reports the hook as silent,
# and the failure describes the wrong thing entirely.
check "control: the mutate probe is installed" \
  "$(docker compose exec -T cli sh -c 'test -e /var/www/html/wp-content/mu-plugins/mutate-probe.php && echo yes || echo no' 2>/dev/null | tr -d '\r\n')" "yes"
check "control: and WordPress has loaded it" \
  "$(docker compose exec -T cli wp eval 'echo has_action("gmcp_mutate") ? "hooked" : "not hooked";' 2>/dev/null | tr -d '\r\n')" "hooked"
M_PROD=$(docker compose exec -T cli wp post list --post_type=product --format=ids 2>/dev/null | tr -d '\r\n' | awk '{print $1}')
call mu_price "{\"jsonrpc\":\"2.0\",\"id\":40,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$M_PROD,\"regular_price\":\"44.00\"}}}"
call mu_stock "{\"jsonrpc\":\"2.0\",\"id\":41,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_set_stock\",\"arguments\":{\"id\":$M_PROD,\"quantity\":11}}}"
call mu_read '{"jsonrpc":"2.0","id":42,"method":"tools/call","params":{"name":"wc_list_products","arguments":{}}}'
fired() { docker compose exec -T cli wp eval 'echo in_array("'"$1"'", (array) get_option("probe_mutate",[]), true) ? "fires" : "silent";' 2>/dev/null | tr -d '\r\n'; }
# The call has to have done something before its silence means anything. Without this, a
# product id that no longer exists makes the write a no-op, nothing mutates, and "silent"
# is a true answer to a question nobody meant to ask.
echo "    [probe] keys now: $(docker compose exec -T cli wp eval 'foreach (GMCP_Tokens::all() as $k) printf("%s/%s/owner=%d ", $k["label"], $k["level"], (int) $k["owner"]); echo "|";' 2>&1 | tr -d '\r\n')"
echo "    [probe] mu_price body: $(head -c 120 "$OUT/mu_price")"
check "control: the price change itself succeeded" "$(verdict mu_price)" "ok"
check "control: and the stock change did too" "$(verdict mu_stock)" "ok"
check "a price change announces itself" "$(fired wc_update_product)" "fires"
check "so does a stock change" "$(fired wc_set_stock)" "fires"
# The control, and the reason the list is named rather than derived from the access level:
# wc_get_order is admin level and changes nothing, so a level test would fire on reads and
# teach an integration to ignore the hook entirely.
check "but a read does not" "$(fired wc_list_products)" "silent"
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/mutate-probe.php' >/dev/null 2>&1
docker compose exec -T cli wp option delete probe_mutate >/dev/null 2>&1

echo "-- a subscription is not an order, and the order tools refuse it --"
# wc_get_order() resolves a subscription id, because WC_Subscription extends WC_Order, so
# every tool that starts with wc_get_order() treated one as an order. set_status() then
# silently rewrites a status that is not valid for a subscription to 'pending', so
# wc_update_order_status said "moved from active to completed" while the stored status
# became pending: a reported status that was never set. It also skipped the date bookkeeping
# and the payment schedule WC_Subscription::update_status() performs, while still firing the
# transition hooks a gateway cancels on. Reachable at write level, on sites that never turn
# the subscriptions group on.
#
# Skipped, with the reason, when WooCommerce Subscriptions is absent: the fixture needs its
# writer, and a suite that invented one would be testing its own invention.
if docker compose exec -T cli wp plugin is-active woocommerce-subscriptions >/dev/null 2>&1; then
  SUBFIX=$(mktemp)
  cat > "$SUBFIX" <<'SUBPHP'
<?php
if ( ! class_exists( 'WC_Subscriptions' ) ) { echo 0; return; }
foreach ( get_posts( [ 'post_type' => 'shop_subscription', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $pid ) {
  $o = wc_get_order( $pid );
  if ( $o && strpos( (string) $o->get_billing_email(), 'wcs-guard-' ) === 0 ) { $o->delete( true ); }
}
$parent = wc_create_order();
$parent->set_billing_email( 'wcs-guard-parent@example.test' );
$parent->save();
// wcs_create_subscription() refuses without a customer_id, so the fixture supplies one.
$uid = (int) get_user_by( 'login', 'gmcp_wcs_guard' )->ID;
if ( ! $uid ) {
  $uid = wp_insert_user( [ 'user_login' => 'gmcp_wcs_guard', 'user_pass' => wp_generate_password(), 'user_email' => 'wcs-guard-cust@example.test', 'role' => 'customer' ] );
  if ( is_wp_error( $uid ) ) { echo 0; return; }
}
$parent->set_customer_id( $uid );
$parent->save();
$sub = wcs_create_subscription( [
  'order_id' => $parent->get_id(), 'customer_id' => $uid,
  'billing_period' => 'month', 'billing_interval' => 1,
  'start_date' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'status' => 'active',
] );
if ( is_wp_error( $sub ) ) { echo 0; return; }
$sub->set_billing_email( 'wcs-guard-' . $sub->get_id() . '@example.test' );
$sub->update_dates( [ 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ] );
$sub->set_requires_manual_renewal( true );
$sub->save();
echo $sub->get_id();
SUBPHP
  docker compose cp "$SUBFIX" cli:/var/www/html/gmcp-wcs-fix.php >/dev/null 2>&1
  docker compose exec -T -u 0 cli chmod 644 /var/www/html/gmcp-wcs-fix.php >/dev/null 2>&1
  SUB_ID=$(docker compose exec -T cli wp eval-file /var/www/html/gmcp-wcs-fix.php 2>/dev/null | tr -d '\r\n')
  docker compose exec -T -u 0 cli rm -f /var/www/html/gmcp-wcs-fix.php >/dev/null 2>&1
  rm -f "$SUBFIX"
  check "CONTROL: the subscription fixture exists and is active" \
    "$(docker compose exec -T cli wp eval "echo wc_get_order($SUB_ID)->get_status();" 2>/dev/null | tr -d '\r\n')" "active"
  check "CONTROL: and wc_get_order() really resolves it as a subscription" \
    "$(docker compose exec -T cli wp eval "echo wc_get_order($SUB_ID)->get_type();" 2>/dev/null | tr -d '\r\n')" "shop_subscription"
  call sub_status "{\"jsonrpc\":\"2.0\",\"id\":70,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$SUB_ID,\"status\":\"completed\"}}}"
  check "moving a subscription through the order tool is refused" "$(verdict sub_status)" "error"
  check "and the refusal says what it is, rather than that it is missing" \
    "$(body sub_status | grep -c 'shop_subscription')" "1"
  # The assertion that matters: the stored status is untouched. Before the guard this read
  # 'pending' while the reply claimed 'completed'.
  check "and the stored status is unchanged" \
    "$(docker compose exec -T cli wp eval "echo wc_get_order($SUB_ID)->get_status();" 2>/dev/null | tr -d '\r\n')" "active"
  # Notes ARE allowed on a subscription: a note changes no state, so the type guard, which
  # exists for writes that have an effect this plugin cannot see, does not apply. This is the
  # control opposite the status check above.
  call sub_note "{\"jsonrpc\":\"2.0\",\"id\":71,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_add_order_note\",\"arguments\":{\"id\":$SUB_ID,\"note\":\"annotation on a subscription\"}}}"
  check "annotating a subscription is allowed, since a note changes no state" "$(verdict sub_note)" "ok"
  NOTE_HITS=$(wpc db query "SELECT COUNT(*) FROM $(wpc db prefix)comments WHERE comment_post_ID=$SUB_ID AND comment_content LIKE '%annotation on a subscription%'" --skip-column-names)
  check "and the note really landed" "$NOTE_HITS" "1"
  # And the status is still untouched by it.
  check "and the note did not disturb the status" \
    "$(docker compose exec -T cli wp eval "echo wc_get_order($SUB_ID)->get_status();" 2>/dev/null | tr -d '\r\n')" "active"
  # The control for the guard: a real order still works, so the refusal is about the type
  # and not about the tool having stopped working.
  call sub_control "{\"jsonrpc\":\"2.0\",\"id\":72,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_order_status\",\"arguments\":{\"id\":$O_ID,\"status\":\"processing\"}}}"
  check "CONTROL: a real order still moves" "$(verdict sub_control)" "ok"
  check "CONTROL: and it really moved" \
    "$(docker compose exec -T cli wp eval "echo wc_get_order($O_ID)->get_status();" 2>/dev/null | tr -d '\r\n')" "processing"
  docker compose exec -T cli wp eval "
    foreach ( get_posts( [ 'post_type' => 'shop_subscription', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as \$pid ) {
      \$o = wc_get_order( \$pid );
      if ( \$o && strpos( (string) \$o->get_billing_email(), 'wcs-guard-' ) === 0 ) { \$o->delete( true ); }
    }" >/dev/null 2>&1
else
  echo "  SKIP  subscription guard checks: WooCommerce Subscriptions is not active"
fi

echo "-- an order is an order under either storage, and so are its notes --"
# An order is a row in wp_posts only when the legacy storage is on. With high-performance
# order storage it is a row in wc_orders and wp_posts holds a placeholder (or, on a shop that
# enabled the storage before WooCommerce reserved ids, nothing, or an ordinary post that took
# the id). Every generic guard used to be asked of the post row's TYPE, so an order whose post
# row was missing or ordinary was judged by nothing, and its notes, which are comments on that
# id, were writable by a write-level key through the comment tools in EVERY storage mode, the
# placeholder included. Each hostile call below is judged by the stored state, taken before and
# after: a refusal in the reply proves nothing, a write that landed is what matters.
#   A  an ordinary order (a legacy shop_order post, or an HPOS order with its placeholder)
#   B  HPOS only: an order whose id is ALSO an ordinary page (what an id collision looks like)
#   C  HPOS only: an order with no wp_posts row at all
#   D  HPOS only: an order whose id is also an attachment
ON_CODE=$(cat <<'ONPHP'
global $wpdb;
$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE 'ORDNOTE %'" ) as $p ) { wp_delete_post( (int) $p, true ); }
$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_content LIKE 'ORDNOTE-%'" );
$mk = function ( $tag ) {
  $o = wc_create_order();
  $o->set_billing_email( 'ordnote-' . $tag . '@example.test' );
  $o->set_status( 'processing' ); $o->save();
  $o->add_order_note( 'ORDNOTE-private-' . $tag );
  // A comment of a type nothing filters, so a reader has something to find that does not
  // depend on WooCommerce hiding order_note from every comment query.
  wp_insert_comment( [ 'comment_post_ID' => $o->get_id(), 'comment_content' => 'ORDNOTE-visible-' . $tag, 'comment_approved' => 1, 'comment_type' => 'gmcp_probe_note' ] );
  return $o->get_id();
};
$out = [ 'hpos' => $hpos ? 1 : 0, 'A' => $mk( 'a' ) ];
if ( $hpos ) {
  $b = $mk( 'b' ); $c = $mk( 'c' ); $d = $mk( 'd' );
  $wpdb->update( $wpdb->posts, [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'ORDNOTE colliding page', 'post_content' => 'ORDNOTE page body' ], [ 'ID' => $b ] );
  clean_post_cache( $b );
  $wpdb->delete( $wpdb->posts, [ 'ID' => $c ] );
  clean_post_cache( $c );
  // D: the same, with an attachment at the id, which the media tools (not the post tools) reach.
  $wpdb->update( $wpdb->posts, [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png', 'post_title' => 'ORDNOTE colliding media' ], [ 'ID' => $d ] );
  clean_post_cache( $d );
  $out['B'] = $b; $out['C'] = $c; $out['D'] = $d;
}
$out['PAGE'] = wp_insert_post( [ 'post_title' => 'ORDNOTE control page', 'post_content' => 'ORDNOTE control body', 'post_status' => 'publish', 'post_type' => 'page' ] );
$k = function ( $label, $level ) {
  foreach ( GMCP_Tokens::all() as $t ) { if ( ( $t['label'] ?? '' ) === $label ) { GMCP_Tokens::revoke( $t['id'] ); } }
  $a = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );
  $r = GMCP_Tokens::create( $label, $level, 0, [], $a ? $a[0]->ID : 0 );
  return $r['secret'];
};
$out['RW'] = $k( 'ordnote rw', 'readwrite' );
$out['RO'] = $k( 'ordnote ro', 'readonly' );
echo "ONJSON" . wp_json_encode( $out );
ONPHP
)
# Handed over as the argument of wp eval, not as a file: copying a file into the container
# needs a command the other suites do not, and the argument works on every stack they run on.
ON_JSON=$(docker compose exec -T cli wp eval "$ON_CODE" 2>/dev/null | tr -d '\r' | grep -o 'ONJSON.*' | sed 's/^ONJSON//')
onj() { printf '%s' "$ON_JSON" | python3 -c "import json,sys;d=json.load(sys.stdin);print(d.get('$1',''))"; }
ON_HPOS=$(onj hpos); ON_A=$(onj A); ON_B=$(onj B); ON_C=$(onj C); ON_D=$(onj D); ON_PAGE=$(onj PAGE); ON_RW=$(onj RW); ON_RO=$(onj RO)
check "CONTROL: the fixture exists, and says which storage it ran on" "$([ -n "$ON_A" ] && [ -n "$ON_PAGE" ] && case "$ON_RW$ON_RO" in *gmcp_*gmcp_*) echo yes;; esac)" "yes"
echo "    storage under test: $([ "$ON_HPOS" = 1 ] && echo 'high-performance order storage' || echo 'legacy posts storage')"
# What is stored for an id: its post row, its comments (type, status and text), its post meta,
# and whether WooCommerce still has an order there.
onsnap() { # onsnap <id>
  docker compose exec -T cli wp eval 'global $wpdb; $id = '"$1"';
    $row = $wpdb->get_var( "SELECT CONCAT(post_type,\"|\",post_status,\"|\",post_title) FROM {$wpdb->posts} WHERE ID=$id" );
    $cm = $wpdb->get_col( "SELECT CONCAT(comment_ID,\":\",comment_type,\":\",comment_approved,\":\",comment_content) FROM {$wpdb->comments} WHERE comment_post_ID=$id ORDER BY comment_ID" );
    $pm = $wpdb->get_col( "SELECT CONCAT(meta_key,\"=\",meta_value) FROM {$wpdb->postmeta} WHERE post_id=$id ORDER BY meta_id" );
    echo ( $row ?: "no-post" ), " ;comments=", count( $cm ), ":", md5( implode( "|", $cm ) ), " ;meta=", count( $pm ), ":", md5( implode( "|", $pm ) ), " ;order=", wc_get_order( $id ) ? 1 : 0;' 2>/dev/null | tr -d '\r\n'
}
onnote() { # onnote <order id> <tag>: the id of the order note
  docker compose exec -T cli wp eval 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_type=\"order_note\" AND comment_content=%s", '"$1"', "ORDNOTE-private-'"$2"'" ) );' 2>/dev/null | tr -d '\r\n'
}
ontool() { # ontool <file> <key> <tool> <arguments json>
  kcall "$1" "$2" "{\"jsonrpc\":\"2.0\",\"id\":430,\"method\":\"tools/call\",\"params\":{\"name\":\"$3\",\"arguments\":$4}}"
}
# The positive controls first, so the absences below mean something. A key that can write
# changes an ordinary page and comments on it; a key that can only read sees a comment on one;
# an admin key sees the colliding page in a listing.
ontool on_ctl_w "$ON_RW" wp_update_post "{\"ID\":$ON_PAGE,\"fields\":{\"post_title\":\"ORDNOTE control page edited\"}}"
check "CONTROL: a write-level key edits an ordinary page" \
  "$(verdict on_ctl_w)/$(docker compose exec -T cli wp eval "echo get_post($ON_PAGE)->post_title;" 2>/dev/null | tr -d '\r\n')" "ok/ORDNOTE control page edited"
ontool on_ctl_c "$ON_RW" wp_create_comment "{\"post_id\":$ON_PAGE,\"comment_content\":\"ORDNOTE-ordinary-comment\"}"
check "CONTROL: and comments on it, which is stored" \
  "$(verdict on_ctl_c)/$(docker compose exec -T cli wp eval 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_content=%s", '"$ON_PAGE"', "ORDNOTE-ordinary-comment" ) );' 2>/dev/null | tr -d '\r\n')" "ok/1"
ontool on_ctl_r "$ON_RO" wp_get_comments "{\"post_id\":$ON_PAGE}"
check "CONTROL: a read-only key reads a comment on an ordinary page" "$(grep -c 'ORDNOTE-ordinary-comment' "$OUT/on_ctl_r")" "1"

ON_IDS="$ON_A"
[ "$ON_HPOS" = 1 ] && ON_IDS="$ON_A $ON_B $ON_C $ON_D"
if [ "$ON_HPOS" = 1 ]; then
  ontool on_ctl_list_ro "$ON_RO" wp_get_posts '{"post_type":"page","search":"ORDNOTE","post_status":"any"}'
  ontool on_ctl_list_ad "$TOK" wp_get_posts '{"post_type":"page","search":"ORDNOTE","post_status":"any"}'
  check "CONTROL: an admin key lists the colliding page" "$(grep -c 'ORDNOTE colliding page' "$OUT/on_ctl_list_ad")" "1"
  check "and a read-only key lists the ordinary one" "$(grep -c 'ORDNOTE control page' "$OUT/on_ctl_list_ro")" "1"
  check "but not the page that shares its id with an order" "$(grep -c 'ORDNOTE colliding page' "$OUT/on_ctl_list_ro")" "0"
fi

for T in $ON_IDS; do
  case "$T" in
    "$ON_A") L=A ;; "$ON_B") L=B ;; "$ON_C") L=C ;; "$ON_D") L=D ;;
  esac
  N=$(onnote "$T" "$(echo $L | tr 'A-Z' 'a-z')")
  check "CONTROL ($L): the order note to attack exists" "$([ "$N" -gt 0 ] 2>/dev/null && echo yes)" "yes"
  BEFORE=$(onsnap "$T")
  check "CONTROL ($L): the snapshot sees the order" "$(echo "$BEFORE" | grep -c ';order=1')" "1"
  # Reads. A read-only key gets neither the post nor its meta nor its comments, and an admin
  # key's comment listing still finds the visible comment (the control for the absence).
  ontool on_r_post "$ON_RO" wp_get_post "{\"ID\":$T}"
  ontool on_r_meta "$ON_RO" wp_get_post_meta "{\"ID\":$T}"
  ontool on_r_com "$ON_RO" wp_get_comments "{\"post_id\":$T,\"status\":\"all\"}"
  ontool on_r_com_t "$ON_RO" wp_get_comments "{\"post_id\":$T,\"type\":\"gmcp_probe_note\",\"status\":\"all\"}"
  ontool on_r_com_s "$ON_RO" wp_get_comments '{"search":"ORDNOTE-","status":"all","limit":100}'
  ontool on_a_com "$TOK" wp_get_comments "{\"post_id\":$T,\"type\":\"gmcp_probe_note\",\"status\":\"all\"}"
  check "CONTROL ($L): an admin key finds the visible comment on the order" "$(grep -c "ORDNOTE-visible" "$OUT/on_a_com")" "1"
  check "($L) a read-only key gets no post and no meta for the order's id" \
    "$(verdict on_r_post)/$(verdict on_r_meta)" "error/error"
  check "($L) nor any comment on it, by id, by type or by search" \
    "$(cat "$OUT/on_r_com" "$OUT/on_r_com_t" "$OUT/on_r_com_s" | grep -c "ORDNOTE-visible-$(echo $L | tr 'A-Z' 'a-z')\|ORDNOTE-private-$(echo $L | tr 'A-Z' 'a-z')")" "0"
  # Writes, as a write-level key.
  ontool on_w1 "$ON_RW" wp_update_post "{\"ID\":$T,\"fields\":{\"post_title\":\"ORDNOTE hacked\"}}"
  ontool on_w2 "$ON_RW" wp_update_post_meta "{\"ID\":$T,\"key\":\"ordnote_probe\",\"value\":\"x\"}"
  ontool on_w3 "$ON_RW" wp_update_comment "{\"comment_ID\":$N,\"fields\":{\"comment_content\":\"ORDNOTE-rewritten\"}}"
  ontool on_w4 "$ON_RW" wp_update_comment "{\"comment_ID\":$N,\"fields\":{\"comment_type\":\"comment\"}}"
  ontool on_w5 "$ON_RW" wp_create_comment "{\"post_id\":$T,\"comment_content\":\"ORDNOTE-injected\"}"
  ontool on_w6 "$ON_RW" wp_update_comment "{\"comment_ID\":$N,\"fields\":{\"comment_approved\":\"0\"}}"
  ontool on_w7 "$ON_RW" wp_alter_post "{\"ID\":$T,\"field\":\"post_title\",\"search\":\"ORDNOTE\",\"replace\":\"x\"}"
  ontool on_w8 "$ON_RW" wp_copy_post_meta "{\"from_id\":$ON_PAGE,\"to_id\":$T}"
  ontool on_w9 "$ON_RW" wp_update_media "{\"ID\":$T,\"title\":\"ORDNOTE retitled\"}"
  # ...and as an admin key, the one that has the delete tools.
  ontool on_d1 "$TOK" wp_delete_post "{\"ID\":$T}"
  ontool on_d2 "$TOK" wp_delete_post "{\"ID\":$T,\"force\":true}"
  ontool on_d3 "$TOK" wp_delete_comment "{\"comment_ID\":$N,\"force\":true}"
  ontool on_d4 "$TOK" wp_delete_comment "{\"comment_ID\":$N}"
  ontool on_d5 "$TOK" wp_delete_post_meta "{\"ID\":$T,\"key\":\"ordnote_probe\"}"
  ontool on_d6 "$TOK" wp_update_post_meta "{\"ID\":$T,\"key\":\"ordnote_probe\",\"value\":\"y\"}"
  ontool on_d7 "$TOK" wp_update_comment "{\"comment_ID\":$N,\"fields\":{\"comment_content\":\"ORDNOTE-admin-rewritten\"}}"
  ontool on_d8 "$TOK" wp_delete_media "{\"ID\":$T}"
  ontool on_d9 "$TOK" wp_delete_media "{\"ID\":$T,\"force\":true}"
  ontool on_d10 "$TOK" wp_delete_media "{\"ID\":$T,\"preview\":true}"
  AFTER=$(onsnap "$T")
  # The assertion that matters: nothing stored moved, whatever the replies said.
  check "($L) after every write and delete tried at write and admin level, the stored order, post row, comments and meta are as they were" "$AFTER" "$BEFORE"
  check "($L) and each of those calls was refused" \
    "$(for f in on_w1 on_w2 on_w3 on_w4 on_w5 on_w6 on_w7 on_w8 on_w9 on_d1 on_d2 on_d3 on_d4 on_d5 on_d6 on_d7 on_d8 on_d9 on_d10; do verdict $f; done | sort | uniq -c | tr -s ' ' | tr -d '\n')" " 19 error"
done
# The generated REST tools are a second generic writer, and at admin level their read policy is
# silent: the controller only checks that the row has the route's type, which a page that shares an
# order's id has. Measured before the write policy was asked there: delete_pages at admin trashed it.
if [ "$ON_HPOS" = 1 ]; then
  ON_REST_WAS=$(docker compose exec -T cli wp eval 'echo get_option("gmcp_options",[])["mcp_tools_rest"]??"unset";' 2>/dev/null | tr -d '\r\n')
  docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_rest"]=true;update_option("gmcp_options",$o,false);delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null 2>&1
  BEFORE=$(onsnap "$ON_B")
  ontool on_rest_ctl "$TOK" get_pages "{\"id\":$ON_PAGE}"
  check "CONTROL: the generated page tools are on and read an ordinary page" "$(verdict on_rest_ctl)" "ok"
  ontool on_rest_u "$TOK" update_pages "{\"id\":$ON_B,\"title\":\"ORDNOTE rest hacked\"}"
  ontool on_rest_d "$TOK" delete_pages "{\"id\":$ON_B}"
  ontool on_rest_df "$TOK" delete_pages "{\"id\":$ON_B,\"force\":true}"
  ontool on_rest_w "$ON_RW" update_pages "{\"id\":$ON_B,\"title\":\"ORDNOTE rest hacked\"}"
  check "(B) the generated tools neither update nor delete a page that shares an order's id" \
    "$(for f in on_rest_u on_rest_d on_rest_df on_rest_w; do verdict $f; done | sort | uniq -c | tr -s ' ' | tr -d '\n')" " 4 error"
  check "(B) and what is stored is as it was" "$(onsnap "$ON_B")" "$BEFORE"
  ontool on_rest_ctl_u "$TOK" update_pages "{\"id\":$ON_PAGE,\"title\":\"ORDNOTE control page again\"}"
  check "CONTROL: the same tool still updates an ordinary page, and it is stored" \
    "$(verdict on_rest_ctl_u)/$(docker compose exec -T cli wp eval "echo get_post($ON_PAGE)->post_title;" 2>/dev/null | tr -d '\r\n')" "ok/ORDNOTE control page again"
  if [ "$ON_REST_WAS" = unset ]; then
    docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);unset($o["mcp_tools_rest"]);update_option("gmcp_options",$o,false);delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null 2>&1
  else
    docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_rest"]='"$([ "$ON_REST_WAS" = 1 ] && echo true || echo false)"';update_option("gmcp_options",$o,false);delete_transient(GMCP_Tools_Rest::CACHE_KEY);' >/dev/null 2>&1
  fi
fi
# The control opposite the refusals: WooCommerce's own note tool is how a note is added, and
# it must still work on the storage under test, or the guard has cut the shop's own writer.
BEFORE_NOTE=$(onsnap "$ON_A")
ontool on_wc_note "$TOK" wc_add_order_note "{\"id\":$ON_A,\"note\":\"ORDNOTE-via-woo\"}"
check "CONTROL: wc_add_order_note still adds a note, and it is stored" \
  "$(verdict on_wc_note)/$(docker compose exec -T cli wp eval 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_type=\"order_note\" AND comment_content=%s", '"$ON_A"', "ORDNOTE-via-woo" ) );' 2>/dev/null | tr -d '\r\n')" "ok/1"
check "CONTROL: and the snapshot does move when something real is written" \
  "$([ "$(onsnap "$ON_A")" != "$BEFORE_NOTE" ] && echo moved)" "moved"
# The order-note rule itself, independent of WooCommerce hiding the type from every comment
# query: take that filter off, ask the way wp_get_comments asks, and see what a read-only key
# and an admin key get for an order_note comment that sits on an ordinary page (so the post
# policy cannot be what hides it).
check "an order_note is held by its type below admin, WooCommerce's own filter taken off" \
  "$(docker compose exec -T cli wp eval '
    remove_filter( "comments_clauses", [ "WC_Comments", "exclude_order_comments" ], 10 );
    $id = wp_insert_comment( [ "comment_post_ID" => '"$ON_PAGE"', "comment_content" => "ORDNOTE-on-page", "comment_approved" => 1, "comment_type" => "order_note" ] );
    $ask = function ( $level ) {
      $f = function () use ( $level ) { return $level; };
      add_filter( "gmcp_caller_access_level", $f );
      $n = count( GMCP_Core::with_readable_comments_sql( function () { return get_comments( [ "post_id" => '"$ON_PAGE"', "type" => "order_note", "status" => "all", "cache_domain" => "gmcp_ordnote_" . wp_rand() ] ); } ) );
      remove_filter( "gmcp_caller_access_level", $f );
      return $n;
    };
    echo "ro=", $ask( "readonly" ), " rw=", $ask( "readwrite" ), " admin=", $ask( "admin" );
    wp_delete_comment( $id, true );' 2>/dev/null | tr -d '\r\n')" "ro=0 rw=0 admin=1"

docker compose exec -T cli wp eval '
  foreach ( wc_get_orders( [ "limit" => -1, "return" => "ids", "status" => array_keys( wc_get_order_statuses() ) ] ) as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }
  global $wpdb;
  foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE \"ORDNOTE %\"" ) as $p ) { wp_delete_post( (int) $p, true ); }
  $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_content LIKE \"ORDNOTE-%\"" );
  foreach ( GMCP_Tokens::all() as $k ) { if ( in_array( $k["label"] ?? "", [ "ordnote rw", "ordnote ro" ], true ) ) { GMCP_Tokens::revoke( $k["id"] ); } }' >/dev/null 2>&1

echo "-- the switch really is a switch --"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_woo"]=false;update_option("gmcp_options",$o);' >/dev/null 2>&1
call w_off '{"jsonrpc":"2.0","id":16,"method":"tools/list"}'
check "switching the group off removes every woo tool" \
  "$(py 'import json,sys;print(len([t for t in json.load(sys.stdin)["result"]["tools"] if t["name"].startswith("wc_")]))' w_off)" "0"
call w_call '{"jsonrpc":"2.0","id":17,"method":"tools/call","params":{"name":"wc_list_orders","arguments":{}}}'
check "and calling one anyway is refused" "$(verdict w_call)" "error"
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_woo"]=true;update_option("gmcp_options",$o);' >/dev/null 2>&1

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
