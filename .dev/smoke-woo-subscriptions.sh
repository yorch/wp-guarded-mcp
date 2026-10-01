#!/bin/bash
# Smoke test for the WooCommerce Subscriptions tools.
#
# Its own file because it needs WooCommerce Subscriptions installed and its tool group
# switched on, and most runs of the other suites should not have to care.
#
#   docker compose exec -T cli wp plugin activate woocommerce-subscriptions
#   ./smoke-woo-subscriptions.sh
#
# Every check asserts stored state rather than the wording of the reply, and every "the tool
# refused" or "X is absent" check sits beside a control proving the same probe finds X when
# present. Blocks that exist to catch one specific defect say so.
#
# WHAT THIS SUITE CANNOT TEST. The fixture renews MANUALLY, because that is how a
# subscription is created without a payment gateway, and a manual subscription reports every
# gateway feature as supported. So the gateway-dependent branches — whether going
# pending-cancel can be undone on a gateway that schedules its own payments, and what the
# gateway does when told — are unreachable here and are NOT covered. The suite says so
# rather than implying otherwise.
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
TOK=$(gmcp_make_key "smoke wcs" admin)
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
dbq() { docker compose exec -T cli wp db query "$1" --skip-column-names 2>/dev/null | tr -d '\r\n'; }
wpc() { docker compose exec -T cli wp "$@" 2>/dev/null | tr -d '\r\n'; }
subjson() { py 'import json,sys
print(json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])[sys.argv[1]])' "$2" "$1"; }
group_on()  { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_woo_subscriptions"]=true;$o["mcp_tools_core"]=true;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }
group_off() { docker compose exec -T cli wp eval '$o=get_option("gmcp_options",[]);$o["mcp_tools_woo_subscriptions"]=false;update_option("gmcp_options",$o,false);' >/dev/null 2>&1; }

if ! docker compose exec -T cli wp plugin is-active woocommerce-subscriptions >/dev/null 2>&1; then
  echo "WooCommerce Subscriptions is not active. Activate it first:"
  echo "  docker compose exec -T cli wp plugin activate woocommerce-subscriptions"
  exit 2
fi
group_on

echo "-- seeding through WooCommerce Subscriptions' own writers --"
SEEDFILE=$(mktemp)
cat > "$SEEDFILE" <<'SEEDPHP'
<?php
if ( ! class_exists( 'WC_Subscriptions' ) ) { echo 'WCS_NOT_LOADED'; return; }
global $wpdb;

// Clean previous smoke fixtures by their distinctive email.
foreach ( get_posts( [ 'post_type' => 'shop_subscription', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $pid ) {
  $o = wc_get_order( $pid );
  if ( $o && strpos( (string) $o->get_billing_email(), 'wcs-suite-' ) === 0 ) { $o->delete( true ); }
}
foreach ( get_posts( [ 'post_type' => 'shop_order', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $pid ) {
  $o = wc_get_order( $pid );
  if ( $o && strpos( (string) $o->get_billing_email(), 'wcs-suite-' ) === 0 ) { $o->delete( true ); }
}

$uid = (int) ( get_user_by( 'login', 'gmcp_wcs_suite' )->ID ?? 0 );
if ( ! $uid ) {
  $uid = wp_insert_user( [ 'user_login' => 'gmcp_wcs_suite', 'user_pass' => wp_generate_password(), 'user_email' => 'wcs-suite-cust@example.test', 'role' => 'customer' ] );
  if ( is_wp_error( $uid ) ) { echo 'SEED_ERROR=' . $uid->get_error_message(); return; }
}

// A real product so the subscription carries a real total.
$product = wc_get_product( (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' LIMIT 1" ) );

$parent = wc_create_order( [ 'customer_id' => $uid ] );
$parent->set_billing_email( 'wcs-suite-parent@example.test' );
$parent->save();

$tag = wp_generate_password( 6, false );
$sub = wcs_create_subscription( [
  'order_id' => $parent->get_id(),
  'customer_id' => $uid,
  'billing_period' => 'month',
  'billing_interval' => 1,
  'start_date' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ),
  'status' => 'pending',
] );
if ( is_wp_error( $sub ) ) { echo 'SEED_ERROR=' . $sub->get_error_message(); return; }

if ( $product ) { $sub->add_product( $product, 1 ); }
$sub->set_billing_email( 'wcs-suite-' . $tag . '@example.test' );
$sub->calculate_totals();
// Manual renewal: no gateway ever runs, which is the only way to build a subscription on a
// stack with no payment gateway. The consequence is in this file's header.
$sub->set_requires_manual_renewal( true );
$sub->update_dates( [ 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 27 * DAY_IN_SECONDS ) ] );
$sub->save();
$sub->update_status( 'active' );

echo 'SEED sub=' . $sub->get_id() . ' parent=' . $parent->get_id() . ' customer=' . $uid . ' tag=' . $tag
  . ' total=' . $sub->get_total() . ' status=' . $sub->get_status();
SEEDPHP
docker compose cp "$SEEDFILE" cli:/var/www/html/gmcp-wcs-seed.php >/dev/null 2>&1
docker compose exec -T -u 0 cli chmod 644 /var/www/html/gmcp-wcs-seed.php >/dev/null 2>&1
SEED=$(docker compose exec -T cli wp eval-file /var/www/html/gmcp-wcs-seed.php 2>&1 | tr -d '\r')
docker compose exec -T -u 0 cli rm -f /var/www/html/gmcp-wcs-seed.php >/dev/null 2>&1
rm -f "$SEEDFILE"
case "$SEED" in
  *SEED\ sub=*) ;;
  *) echo "Seeding failed: $SEED" >&2; exit 1 ;;
esac
SUB=$( echo "$SEED" | sed -n 's/.*sub=\([0-9]*\).*/\1/p' )
PARENT=$( echo "$SEED" | sed -n 's/.*parent=\([0-9]*\).*/\1/p' )
CUST=$( echo "$SEED" | sed -n 's/.*customer=\([0-9]*\).*/\1/p' )
TAG=$( echo "$SEED" | sed -n 's/.*tag=\([A-Za-z0-9]*\).*/\1/p' )
echo "  sub=$SUB parent=$PARENT customer=$CUST tag=$TAG"

echo "-- the fixture exists, and the probe discriminates --"
check "CONTROL: the subscription exists and is active" \
  "$(wpc eval "echo wcs_get_subscription($SUB)->get_status();")" "active"
check "CONTROL: it really carries the customer's billing email" \
  "$(wpc eval "\$s = wcs_get_subscription($SUB); echo \$s ? \$s->get_billing_email() : 'NO_SUB';")" "wcs-suite-$TAG@example.test"
# The discriminating half, and the point of the pair: the same probe the absence checks use
# finds this suite's email when it is there, so "not in the reply" later is an absence and
# not a probe that never worked. Read through WooCommerce's own API rather than from
# postmeta, because with HPOS on — which is the default on a fresh store — billing data
# lives in wc_orders and wc_order_addresses and there is no postmeta row to find. Scoped to
# this run's tag rather than counting every subscription, because a fixture left by an
# earlier run is not a tool defect.
check "CONTROL: the same probe finds this run's email, so a later absence means absent" \
  "$(wpc eval "\$s = wcs_get_subscription($SUB); echo \$s ? \$s->get_billing_email() : 'NO_SUB';" | grep -c "^wcs-suite-$TAG@example.test\$")" "1"

echo "-- the tools are offered --"
call wcs_list '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
check "the Subscriptions group is listed when switched on" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
n={t["name"] for t in d["result"]["tools"]}
need={"wcs_list_subscriptions","wcs_get_subscription","wcs_subscriptions_briefing","wcs_set_subscription_status"}
print(sorted(need-n) or True)' "" wcs_list)" "True"
check "the Tools page declares the group to its save" \
  "$(docker compose exec -T cli wp eval '$m=new ReflectionMethod("GMCP_Settings","field_kinds");$m->setAccessible(true);$k=$m->invoke(null);echo isset($k["mcp_tools_woo_subscriptions"])?"declared":"missing";' 2>/dev/null | tr -d '\r\n')" "declared"
# The money-moving tools must not exist at any access level.
check "and no tool here can charge, move a date, or cancel outright" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
names=" ".join(t["name"] for t in d["result"]["tools"])
bad=[x for x in ("wcs_process_renewal","wcs_update_next_payment_date","wcs_cancel_subscription","wcs_create_subscription") if x in names]
print(bad or True)' "" wcs_list)" "True"

echo "-- reading, with no customer data --"
call wcs_one "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_get_subscription\",\"arguments\":{\"id\":$SUB}}}"
check "wcs_get_subscription succeeds" "$(verdict wcs_one)" "ok"
check "and reports the status" "$(subjson wcs_one status)" "active"
check "and reports both the stored date and the scheduled job" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["dates"]["next_payment"]["gmt"] is not None and d["scheduled_payment"]["checked"])' "" wcs_one)" "True"
# The absence checks, each with the control above proving the email IS in the database.
check "and never returns the billing email" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print("wcs-suite-" in json.dumps(d))' "" wcs_one)" "False"
check "and never returns the customer's address" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
s=json.dumps(d).lower()
print(any(k in s for k in ("billing_address","shipping_address","address_1","postcode")))' "" wcs_one)" "False"
check "and never returns the payment method's display string" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print("payment_method_title" in json.dumps(d))' "" wcs_one)" "False"

call wcs_many "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_list_subscriptions\",\"arguments\":{}}}"
check "wcs_list_subscriptions succeeds" "$(verdict wcs_many)" "ok"
check "and finds the seeded subscription" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(any(s["id"]==int(sys.argv[1]) for s in d["subscriptions"]))' "$SUB" wcs_many)" "True"
# The list is a JSON list, not an object keyed by id: wcs_get_subscriptions() returns an
# array keyed by id, which json_encode would turn into an object.
check "and the subscriptions are a list, not an object keyed by id" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(isinstance(d["subscriptions"], list))' "" wcs_many)" "True"
check "and the list carries no customer email either" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print("wcs-suite-" in json.dumps(d))' "" wcs_many)" "False"
call wcs_filter "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_list_subscriptions\",\"arguments\":{\"status\":\"cancelled\"}}}"
check "filtering by a status nothing has finds nothing" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(len(d["subscriptions"]))' "" wcs_filter)" "0"
call wcs_bad "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_list_subscriptions\",\"arguments\":{\"status\":\"teleported\"}}}"
check "an unknown status is refused" "$(verdict wcs_bad)" "error"

echo "-- the briefing counts, and says what it cannot know --"
call wcs_brief "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_subscriptions_briefing\",\"arguments\":{}}}"
check "wcs_subscriptions_briefing succeeds" "$(verdict wcs_brief)" "ok"
check "and counts the seeded subscription in the active bucket" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["by_status"].get("active", 0) >= 1)' "" wcs_brief)" "True"
# Retries have three states on a real shop: enabled, disabled and never set. A bare 0 would
# read as "nothing to retry" for all three.
check "and reports the retry setting as a state, not a count" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print(d["retries"]["state"] in ("enabled","disabled","unset"))' "" wcs_brief)" "True"
check "and the committed figure is labelled an approximation" \
  "$(py 'import json,sys
d=json.loads(json.load(sys.stdin)["result"]["content"][0]["text"])
print("approximation" in d["committed"]["note"])' "" wcs_brief)" "True"

echo "-- the status tool: allowed transitions --"
check "CONTROL: the subscription starts active" \
  "$(wpc eval "echo wcs_get_subscription($SUB)->get_status();")" "active"
check "CONTROL: and it has a scheduled payment job while active" \
  "$(wpc eval "echo as_next_scheduled_action('woocommerce_scheduled_subscription_payment',['subscription_id'=>$SUB]) ? 'yes' : 'no';")" "yes"
call wcs_hold "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_set_subscription_status\",\"arguments\":{\"id\":$SUB,\"status\":\"on-hold\"}}}"
check "active -> on-hold succeeds" "$(verdict wcs_hold)" "ok"
check "and the stored status is on-hold" \
  "$(wpc eval "echo wcs_get_subscription($SUB)->get_status();")" "on-hold"
# The schedule is the real answer to "will this charge", and it must follow the status.
check "and the payment job is gone, so nothing will charge" \
  "$(wpc eval "echo as_next_scheduled_action('woocommerce_scheduled_subscription_payment',['subscription_id'=>$SUB]) ? 'yes' : 'no';")" "no"
call wcs_back "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_set_subscription_status\",\"arguments\":{\"id\":$SUB,\"status\":\"active\"}}}"
check "on-hold -> active succeeds" "$(verdict wcs_back)" "ok"
check "and the status is active again" \
  "$(wpc eval "echo wcs_get_subscription($SUB)->get_status();")" "active"
check "and the payment job is scheduled again" \
  "$(wpc eval "echo as_next_scheduled_action('woocommerce_scheduled_subscription_payment',['subscription_id'=>$SUB]) ? 'yes' : 'no';")" "yes"

echo "-- refused transitions, and the states they would leave behind --"
# Cancelling is terminal, and cancels at the gateway on gateways that manage billing.
call wcs_cancel "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_set_subscription_status\",\"arguments\":{\"id\":$SUB,\"status\":\"cancelled\"}}}"
check "cancelling outright is refused" "$(verdict wcs_cancel)" "error"
check "CONTROL: and the status really is unchanged" \
  "$(wpc eval "echo wcs_get_subscription($SUB)->get_status();")" "active"
# 'completed' is an order status. WCS would map it onto the active branch, then store
# 'pending' — a status the caller never asked for.
call wcs_alias "{\"jsonrpc\":\"2.0\",\"id\":10,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_set_subscription_status\",\"arguments\":{\"id\":$SUB,\"status\":\"completed\"}}}"
check "an order-status alias is refused" "$(verdict wcs_alias)" "error"
check "CONTROL: and that left the status alone too" \
  "$(wpc eval "echo wcs_get_subscription($SUB)->get_status();")" "active"
call wcs_pending "{\"jsonrpc\":\"2.0\",\"id\":11,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_set_subscription_status\",\"arguments\":{\"id\":$SUB,\"status\":\"pending\"}}}"
check "and so is pending, which would activate without payment" "$(verdict wcs_pending)" "error"

echo "-- a subscription behind on payment is not reactivated over the debt --"
wpc eval "
  \$sub = wcs_get_subscription( $SUB );
  \$renewal = wcs_create_renewal_order( \$sub );
  if ( ! is_wp_error( \$renewal ) ) { \$renewal->set_total( 25 ); \$renewal->set_status( 'pending' ); \$renewal->save(); }
" >/dev/null 2>&1
check "CONTROL: the subscription now needs payment" \
  "$(wpc eval "echo wcs_get_subscription($SUB)->needs_payment() ? 'yes' : 'no';")" "yes"
call wcs_hold2 "{\"jsonrpc\":\"2.0\",\"id\":12,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_set_subscription_status\",\"arguments\":{\"id\":$SUB,\"status\":\"on-hold\"}}}"
check "it can still be put on hold" "$(verdict wcs_hold2)" "ok"
call wcs_debt "{\"jsonrpc\":\"2.0\",\"id\":13,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_set_subscription_status\",\"arguments\":{\"id\":$SUB,\"status\":\"active\"}}}"
check "but reactivating it over an unpaid renewal is refused" "$(verdict wcs_debt)" "error"
check "and the refusal names the unpaid renewal as the reason" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
t=d["result"]["content"][0]["text"].lower()
print("unpaid renewal" in t)' "" wcs_debt)" "True"
check "CONTROL: and it is still on hold, not quietly reactivated" \
  "$(wpc eval "echo wcs_get_subscription($SUB)->get_status();")" "on-hold"

echo "-- the writes announce themselves --"
docker exec -u 0 "${COMPOSE_PROJECT_NAME:-wptest}-wp-1" mkdir -p /var/www/html/wp-content/mu-plugins
docker compose exec -T wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/wcs-mutate-probe.php <<"PHPEOF"
<?php
add_action( "gmcp_mutate", function ( $tool ) {
  $seen = get_option( "wcs_probe_mutate", [] );
  $seen[] = $tool;
  update_option( "wcs_probe_mutate", $seen, false );
}, 10, 1 );
PHPEOF'
check "CONTROL: the mutation probe is installed and can observe" \
  "$(docker compose exec -T wp sh -c 'test -f /var/www/html/wp-content/mu-plugins/wcs-mutate-probe.php && echo present || echo absent' 2>/dev/null | tr -d '\r\n')" "present"
check "control: and WordPress has loaded it" \
  "$(docker compose exec -T cli wp eval 'echo has_action("gmcp_mutate") ? "hooked" : "not hooked";' 2>/dev/null | tr -d '\r\n')" "hooked"
wpc option delete wcs_probe_mutate >/dev/null 2>&1
call mu_status "{\"jsonrpc\":\"2.0\",\"id\":14,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_set_subscription_status\",\"arguments\":{\"id\":$SUB,\"status\":\"active\"}}}"
# The debt above makes reactivation a refusal; use a read and a successful write instead.
wpc eval "\$r = get_posts( [ 'post_type' => 'shop_order', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );" >/dev/null 2>&1
call mu_read '{"jsonrpc":"2.0","id":15,"method":"tools/call","params":{"name":"wcs_get_subscription","arguments":{"id":'"$SUB"'}}}'
wcs_fired() { wpc eval 'echo in_array("'"$1"'", (array) get_option("wcs_probe_mutate",[]), true) ? "fires" : "silent";'; }
check "a refused status change does not fire the mutation hook" "$(wcs_fired wcs_set_subscription_status)" "silent"
check "and neither does a read" "$(wcs_fired wcs_get_subscription)" "silent"
docker compose exec -T wp sh -c 'rm -f /var/www/html/wp-content/mu-plugins/wcs-mutate-probe.php' >/dev/null 2>&1
wpc option delete wcs_probe_mutate >/dev/null 2>&1

echo "-- a readonly key can read but not write --"
RO=$(gmcp_make_key "smoke wcs readonly" readonly)
call_ro() { curl -sS -X POST "$URL" -H "Authorization: Bearer $RO" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$2" -o "$OUT/$1"; }
call_ro ro_read "{\"jsonrpc\":\"2.0\",\"id\":16,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_list_subscriptions\",\"arguments\":{}}}"
check "CONTROL: a readonly key can call the read-level list" "$(verdict ro_read)" "ok"
call_ro ro_write "{\"jsonrpc\":\"2.0\",\"id\":17,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_set_subscription_status\",\"arguments\":{\"id\":$SUB,\"status\":\"active\"}}}"
check "but cannot call the admin-level status tool" "$(verdict ro_write)" "error"
docker compose exec -T cli wp eval 'foreach ( GMCP_Tokens::all() as $k ) { if ( ( $k["label"] ?? "" ) === "smoke wcs readonly" ) { GMCP_Tokens::revoke( $k["id"] ); } }' >/dev/null 2>&1

echo "-- the group switch --"
group_off
call wcs_off '{"jsonrpc":"2.0","id":18,"method":"tools/list"}'
check "switched off, no wcs_ tool is offered" \
  "$(py 'import json,sys
d=json.load(sys.stdin)
print(len([t for t in d["result"]["tools"] if t["name"].startswith("wcs_")]))' "" wcs_off)" "0"
call wcs_off_call "{\"jsonrpc\":\"2.0\",\"id\":19,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_list_subscriptions\",\"arguments\":{}}}"
check "and calling one anyway is refused" "$(verdict wcs_off_call)" "error"
group_on

echo "-- WooCommerce Subscriptions deactivated --"
docker compose exec -T cli wp plugin deactivate woocommerce-subscriptions >/dev/null 2>&1
call wcs_gone "{\"jsonrpc\":\"2.0\",\"id\":20,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_list_subscriptions\",\"arguments\":{}}}"
check "with Subscriptions gone, the tool refuses cleanly" "$(verdict wcs_gone)" "error"
check "saying it is not loaded, not that it does not exist" \
  "$(py 'import json,sys
t=json.load(sys.stdin)["result"]["content"][0]["text"].lower()
print("not loaded" in t and "unknown tool" not in t)' "" wcs_gone)" "True"
docker compose exec -T cli wp plugin activate woocommerce-subscriptions >/dev/null 2>&1
call wcs_back2 "{\"jsonrpc\":\"2.0\",\"id\":21,\"method\":\"tools/call\",\"params\":{\"name\":\"wcs_list_subscriptions\",\"arguments\":{}}}"
check "CONTROL: reactivating makes the tools work again" "$(verdict wcs_back2)" "ok"

echo "-- the shipping, stated rather than implied --"
echo "  NOTE  gateway-dependent behaviour is NOT covered: the fixture renews manually, and a"
echo "        manual subscription reports every gateway feature as supported."

echo "-- cleanup --"
CLEANFILE=$(mktemp)
cat > "$CLEANFILE" <<'CLEANPHP'
<?php
if ( ! class_exists( 'WC_Subscriptions' ) ) { return; }
foreach ( [ 'shop_subscription', 'shop_order' ] as $type ) {
  foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $pid ) {
    $o = wc_get_order( $pid );
    if ( $o && strpos( (string) $o->get_billing_email(), 'wcs-suite-' ) === 0 ) { $o->delete( true ); }
  }
}
CLEANPHP
docker compose cp "$CLEANFILE" cli:/var/www/html/gmcp-wcs-clean.php >/dev/null 2>&1
docker compose exec -T -u 0 cli chmod 644 /var/www/html/gmcp-wcs-clean.php >/dev/null 2>&1
docker compose exec -T cli wp eval-file /var/www/html/gmcp-wcs-clean.php >/dev/null 2>&1
docker compose exec -T -u 0 cli rm -f /var/www/html/gmcp-wcs-clean.php >/dev/null 2>&1
rm -f "$CLEANFILE"
group_off

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
