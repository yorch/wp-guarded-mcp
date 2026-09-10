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
URL='http://localhost:8080/wp-json/mcp/v1/http'
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

if [ "$(wpc plugin is-active woocommerce >/dev/null 2>&1 && echo yes || echo no)" != "yes" ]; then
  echo "WooCommerce is not active. Install it first:"
  echo "  docker compose exec -T cli wp plugin install woocommerce --activate"
  exit 2
fi
docker compose exec -T cli wp eval '$o=get_option("reeve_options",[]);$o["mcp_tools_woo"]=true;update_option("reeve_options",$o);' >/dev/null 2>&1

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
# Silence here would be worse than the wrong answer: somebody has been emailed.
check "and warns that the customer was emailed" "$(body o_done | grep -c 'email')" "1"
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

echo "-- the switch really is a switch --"
docker compose exec -T cli wp eval '$o=get_option("reeve_options",[]);$o["mcp_tools_woo"]=false;update_option("reeve_options",$o);' >/dev/null 2>&1
call w_off '{"jsonrpc":"2.0","id":16,"method":"tools/list"}'
check "switching the group off removes every woo tool" \
  "$(py 'import json,sys;print(len([t for t in json.load(sys.stdin)["result"]["tools"] if t["name"].startswith("wc_")]))' w_off)" "0"
call w_call '{"jsonrpc":"2.0","id":17,"method":"tools/call","params":{"name":"wc_list_orders","arguments":{}}}'
check "and calling one anyway is refused" "$(verdict w_call)" "error"
docker compose exec -T cli wp eval '$o=get_option("reeve_options",[]);$o["mcp_tools_woo"]=true;update_option("reeve_options",$o);' >/dev/null 2>&1

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
