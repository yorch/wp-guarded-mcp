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
TOK='testtoken1234567890'
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

if [ "$(wpc plugin is-active woocommerce >/dev/null 2>&1 && echo yes || echo no)" != "yes" ]; then
  echo "WooCommerce is not active. Install it first:"
  echo "  docker compose exec -T cli wp plugin install woocommerce --activate"
  exit 2
fi
docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_woo"]=true;update_option("gmcp_options",$o);' >/dev/null 2>&1

# Leave nothing behind from a previous run.
docker compose exec -T cli wp eval '
  foreach ( get_posts( [ "post_type" => [ "product", "shop_order" ], "post_status" => "any", "numberposts" => -1 ] ) as $p ) { wp_delete_post( $p->ID, true ); }
  foreach ( wc_get_orders( [ "limit" => -1, "return" => "ids", "status" => array_keys( wc_get_order_statuses() ) ] ) as $id ) { wp_delete_post( $id, true ); }
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
check "a stock delta applies" "$(wpc post meta get "$P_ID" _stock)" "0"
# A quantity of zero with a status still saying instock leaves the product purchasable.
check "stock status follows the quantity down" "$(wpc post meta get "$P_ID" _stock_status)" "outofstock"

call p_prev "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$P_ID,\"regular_price\":\"24.99\",\"preview\":true}}}"
check "a product preview changes nothing" "$(wpc post meta get "$P_ID" _regular_price)" "19.99"
call p_upd "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$P_ID,\"regular_price\":\"24.99\"}}}"
check "and the real update does" "$(wpc post meta get "$P_ID" _regular_price)" "24.99"

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

echo "-- reporting --"
call s_sum '{"jsonrpc":"2.0","id":14,"method":"tools/call","params":{"name":"wc_sales_summary","arguments":{"days":30}}}'
check "the sales summary counts the paid order" \
  "$(py 'import json,sys;print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["orders"])' s_sum)" "1"
call s_brief '{"jsonrpc":"2.0","id":15,"method":"tools/call","params":{"name":"wc_store_briefing","arguments":{}}}'
check "the briefing reports order counts by real status names" \
  "$(py 'import json,sys;print("completed" in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["orders"])' s_brief)" "True"
check "and flags the out-of-stock product" \
  "$(py 'import json,sys;print("Test Widget" in json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])["products"]["out_of_stock"])' s_brief)" "True"

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
docker compose exec -T cli wp option delete gmcp_tokens >/dev/null 2>&1

echo "-- the shop tools announce their writes --"
# These go through the WooCommerce CRUD classes, so nothing here touches wp_update_post
# and none of the usual content-change paths ran. An integration purging a full-page cache
# saw a price change as silence, and most WooCommerce sites run such a cache, so the
# visible symptom was a shopper still being shown the old price.
docker compose exec -T wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/mutate-probe.php <<"PHPEOF"
<?php
add_action( "gmcp_mutate", function ( $tool ) {
  $seen = get_option( "probe_mutate", [] );
  $seen[] = $tool;
  update_option( "probe_mutate", $seen, false );
}, 10, 1 );
PHPEOF' >/dev/null 2>&1
docker compose exec -T cli wp option delete probe_mutate >/dev/null 2>&1
M_PROD=$(docker compose exec -T cli wp post list --post_type=product --format=ids 2>/dev/null | tr -d '\r\n' | awk '{print $1}')
call mu_price "{\"jsonrpc\":\"2.0\",\"id\":40,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_update_product\",\"arguments\":{\"id\":$M_PROD,\"regular_price\":\"44.00\"}}}"
call mu_stock "{\"jsonrpc\":\"2.0\",\"id\":41,\"method\":\"tools/call\",\"params\":{\"name\":\"wc_set_stock\",\"arguments\":{\"id\":$M_PROD,\"quantity\":11}}}"
call mu_read '{"jsonrpc":"2.0","id":42,"method":"tools/call","params":{"name":"wc_list_products","arguments":{}}}'
fired() { docker compose exec -T cli wp eval 'echo in_array("'"$1"'", (array) get_option("probe_mutate",[]), true) ? "fires" : "silent";' 2>/dev/null | tr -d '\r\n'; }
check "a price change announces itself" "$(fired wc_update_product)" "fires"
check "so does a stock change" "$(fired wc_set_stock)" "fires"
# The control, and the reason the list is named rather than derived from the access level:
# wc_get_order is admin level and changes nothing, so a level test would fire on reads and
# teach an integration to ignore the hook entirely.
check "but a read does not" "$(fired wc_list_products)" "silent"
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/mutate-probe.php' >/dev/null 2>&1
docker compose exec -T cli wp option delete probe_mutate >/dev/null 2>&1

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
