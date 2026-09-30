<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* WooCommerce Subscriptions: reading the shop's recurring revenue, and the one status
* change that is safe to make from here.
*
* A subscription is a WC_Order subclass, and that is the fact everything below turns on. It
* means the shop's order tools already resolve one, which is why the write guards in
* GMCP_Tools_Woo and the post-type policy in GMCP_Core had to exist before this file: a tool
* that could reach a subscription as though it were an order was already reachable, at a
* lower access level than anything here, on shops that never switch this group on. Those
* fixes are in this slice's first commit.
*
* WHAT IS DELIBERATELY ABSENT, and is not an omission to be helpfully filled in.
*
* There is no tool that makes a payment. No renewal charge, no retry, no "process now". A
* charge moves money through a gateway and cannot be put back by anything this plugin has,
* which is the same reason the WooCommerce group excludes refunds.
*
* There is no tool that changes the next payment DATE. This one is worth stating plainly
* because it looks harmless and is not: WooCommerce Subscriptions validates a date change
* for ORDERING only — that it falls after the start and trial end and before the end — and
* never for whether it is in the future. A date in the past is accepted, the payment job is
* rescheduled to it, and the next queue run charges the customer. Passing an empty value
* deletes the date instead, which stops the subscription renewing ever again. So "move a
* date" is a charge trigger and a way to end the revenue, in one argument.
*
* There is no immediate cancellation. Cancelled is a terminal state with no path back
* through WooCommerce Subscriptions, and on a gateway that manages its own billing it
* cancels the customer's agreement there irreversibly. A customer asking to stop is what
* active -> pending-cancel is for, which this file does offer, and stopping the charges now
* without ending the agreement is on-hold.
*
* There is no tool that creates a subscription or resubscribes one. Both create a billing
* agreement the customer did not enter.
*
* AND THE ONE WRITE. wcs_set_subscription_status moves a subscription between four states
* that a shop owner actually asks for: active <-> on-hold, and active <-> pending-cancel.
* It refuses everything else, including the transitions WooCommerce Subscriptions itself
* would permit, and it refuses two of the four in the cases where they would give away
* service or take it away irreversibly. Its reasoning is at each guard.
*
* The reads return counts and state, never customer data. A subscription's billing email and
* address, its line-item names, its notes and its payment-method meta all live on the same
* object, and the WooCommerce group's order tool already returns them at admin level; these
* tools deliberately do not, so that a shop's recurring revenue can be read without moving
* personal data through the model.
*/
class GMCP_Tools_Woo_Subscriptions {

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [ 'wcs_set_subscription_status' ];

  /**
  * The only status changes this tool will make, as from => [to, ...].
  *
  * Narrower than can_be_updated_to() on purpose. That function runs filters, so a
  * third-party plugin can widen it, and it also accepts aliases: it maps 'completed' onto
  * the active branch and 'failed' onto the on-hold branch, so update_status('completed')
  * runs the reactivation code and then stores 'pending'. The allow-list is exact pairs and
  * the value passed on is this constant, never the caller's string.
  */
  const ALLOWED_TRANSITIONS = [
    'active'         => [ 'on-hold', 'pending-cancel' ],
    'on-hold'        => [ 'active' ],
    'pending-cancel' => [ 'active' ],
  ];

  const DEFAULT_LIMIT = 20;
  const MAX_LIMIT = 100;

  public function __construct() {
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    add_filter( 'gmcp_tools', [ $this, 'register_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }

  public function register_tools( $tools ) {
    return array_merge( is_array( $tools ) ? $tools : [], array_values( $this->tools() ) );
  }

  /**
  * Whether WooCommerce Subscriptions is present AND working, checked per call.
  *
  * There is no WCS_VERSION constant in 7.7.0, so presence is the classes plus the functions
  * a tool here actually calls. WC_Subscription and the wcs_ helpers are not a sufficient
  * signal on their own: a payment gateway that bundles the subscriptions core library
  * provides them without WooCommerce Subscriptions being installed at all.
  */
  private function loaded(): bool {
    return class_exists( 'WC_Subscriptions' )
      && class_exists( 'WC_Subscription' )
      && function_exists( 'wcs_get_subscription' )
      && function_exists( 'wcs_get_subscriptions' )
      && function_exists( 'wcs_get_subscription_statuses' );
  }

  private function tools(): array {
    return [
      'wcs_list_subscriptions' => [
        'name' => 'wcs_list_subscriptions',
        'description' => 'List WooCommerce subscriptions with the state a shop owner manages them by: id, status, customer id, the billing interval and period, the total, the start and next payment dates, and the gateway rather than the payment method. Filter by status or by customer, ordered by start date newest first. Deliberately no customer data: no billing email or address, no line-item names (an admin can put anything in those, and variation attributes are appended to them), no notes, no payment-method detail and no meta. Read a single order with wc_get_order at admin level if you need those. The retry state is reported as one of enabled, disabled or unset, because unset defaults to disabled and a bare "0 retries" would read as "nothing to retry".',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'status' => [ 'type' => 'string', 'description' => 'active, on-hold, pending, cancelled, expired, switched, pending-cancel, trash, or any. Default any.' ],
            'customer_id' => [ 'type' => 'integer', 'description' => 'Only this customer\'s subscriptions.' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Default 20, maximum 100.' ],
            'page' => [ 'type' => 'integer', 'description' => 'Page number, 1-based. Default 1.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wcs_get_subscription' => [
        'name' => 'wcs_get_subscription',
        'description' => 'Read one subscription: its status, every date it holds (start, trial end, next payment, last payment, end, cancelled) in both GMT and the site\'s time zone, the billing interval and period, the total and currency, the gateway, whether it renews automatically or requires manual renewal, the parent order and the renewal orders related to it, and the signed numbers that matter for support — how many times it has been suspended and whether it is behind on payment. Also reports whether the payment job is actually scheduled and for when, which is the real answer to "will this charge", since it can disagree with the stored date. Deliberately no billing email or address, no line-item names, no notes and no payment-method meta. Changes nothing.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer', 'description' => 'The subscription id.' ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'read',
      ],
      'wcs_subscriptions_briefing' => [
        'name' => 'wcs_subscriptions_briefing',
        'description' => 'The recurring-revenue picture in one call: how many subscriptions exist in each status, how many renew in the next 7 and 30 days, how many are on hold or behind on payment, and the committed monthly and annual amount broken down by currency. Counts and totals only, so it is a read tool. Two honesty notes live in the reply: the monthly figure is derived by normalising each subscription\'s interval to a month, which is an approximation and says so, and the payment retry setting is reported as enabled, disabled or unset rather than as a number of retries.',
        'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
        'accessLevel' => 'read',
      ],
      'wcs_set_subscription_status' => [
        'name' => 'wcs_set_subscription_status',
        'description' => 'Move a subscription between the states a shop owner manages: active to on-hold (stop charging, keep the agreement), on-hold to active (start again), active to pending-cancel (the customer asked to stop, so it ends at the period end), and pending-cancel back to active (they changed their mind). Everything else is refused, including transitions WooCommerce Subscriptions itself allows. Two of the four can also be refused for this particular subscription: reactivating one that is behind on payment is refused, because it would restore service and cancel the pending retry without collecting the debt, and reactivating a pending-cancel one is refused when the original end date was not recorded, because the subscription would then renew forever. This goes through the subscription\'s own API, so the payment schedule, the dates and the gateway are kept in step — which is why it takes the call and not a plain write. It can email the customer: the reply names every address written to. Not reversible in one step and not recorded by wp_undo_change, so the reply reports what changed. Requires admin.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer' ],
            'status' => [ 'type' => 'string', 'description' => 'on-hold, active or pending-cancel. Which ones are available depends on the current status, which the refusal will name.' ],
            'note' => [ 'type' => 'string', 'description' => 'A note recorded against the subscription, private by default.' ],
            'customer_note' => [ 'type' => 'boolean', 'description' => 'Email the note to the customer as well. Default false.' ],
            'confirm' => [ 'type' => 'string', 'description' => 'Confirmation token, supplied by a refusal when the change cannot be undone from here.' ],
          ],
          'required' => [ 'id', 'status' ],
        ],
        'accessLevel' => 'admin',
      ],
    ];
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];
    $this->noop = false;

    if ( !$this->loaded() ) {
      return $this->error( $r, 'WooCommerce Subscriptions is not loaded on this site, so its tools cannot run.' );
    }

    try {
      switch ( $tool ) {
        case 'wcs_list_subscriptions':       $r = $this->list_subscriptions( $args, $r ); break;
        case 'wcs_get_subscription':         $r = $this->get_subscription( $args, $r ); break;
        case 'wcs_subscriptions_briefing':   $r = $this->briefing( $args, $r ); break;
        case 'wcs_set_subscription_status':  $r = $this->set_status( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'WooCommerce Subscriptions threw an error while running ' . $tool . ': ' . $e->getMessage() );
    }

    if ( empty( $r['result']['isError'] ) && !$this->noop && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }
    return $r;
  }

  /** Set by a call that turned out to be a no-op, so gmcp_mutate does not fire for it. */
  private $noop = false;

  #region Helpers

  private function error( array $r, string $message, int $code = -32602 ): array {
    $r['result'] = [
      'content' => [ [ 'type' => 'text', 'text' => $message . ' [error ' . $code . ']' ] ],
      'isError' => true,
    ];
    unset( $r['error'] );
    return $r;
  }

  private function text( array $r, string $message ): array {
    $r['result'] = [ 'content' => [ [ 'type' => 'text', 'text' => $message ] ] ];
    return $r;
  }

  private function json( array $r, $data ): array {
    return $this->text( $r, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
  }

  /**
  * A subscription by id, or null.
  *
  * wcs_get_subscription() returns false for an id it cannot find rather than a WP_Error, so
  * "does not exist" and "could not be loaded" arrive as the same value. Both are reported as
  * not found, which is the answer a caller can act on, and the distinction is not one this
  * tool can make.
  */
  private function subscription( int $id ): ?\WC_Subscription {
    if ( $id <= 0 ) {
      return null;
    }
    $sub = wcs_get_subscription( $id );
    return $sub instanceof \WC_Subscription ? $sub : null;
  }

  /** Subscriptions are prefixed wc- in storage; the tools speak the bare name. */
  private function bare( string $status ): string {
    return strpos( $status, 'wc-' ) === 0 ? substr( $status, 3 ) : $status;
  }

  /** Every status a subscription can hold, unprefixed. */
  private function statuses(): array {
    return array_map( [ $this, 'bare' ], array_keys( (array) wcs_get_subscription_statuses() ) );
  }

  private function limit( array $a ): int {
    $n = (int) ( $a['limit'] ?? 0 );
    if ( $n <= 0 ) {
      return self::DEFAULT_LIMIT;
    }
    return min( self::MAX_LIMIT, $n );
  }

  /** A date from the subscription, as both GMT and the site's local time. */
  private function dates( \WC_Subscription $sub ): array {
    // The date types are named here rather than asked of get_valid_date_types(), which is
    // protected, and this list is the same one WooCommerce Subscriptions documents.
    $types = [ 'start', 'trial_end', 'next_payment', 'last_payment', 'end', 'cancelled' ];
    $out = [];
    foreach ( $types as $type ) {
      $stamp = (int) $sub->get_time( $type );
      $out[ $type ] = [
        'gmt' => $stamp > 0 ? gmdate( 'Y-m-d H:i', $stamp ) : null,
        'local' => $stamp > 0 ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $stamp ), 'Y-m-d H:i' ) : null,
        'timestamp' => $stamp > 0 ? $stamp : null,
      ];
    }
    return $out;
  }

  /** The scheduled payment job, which is the real answer to "will this charge". */
  private function scheduled_payment( int $id ) {
    if ( !function_exists( 'as_next_scheduled_action' ) ) {
      return [ 'checked' => false, 'note' => 'Action Scheduler is not available, so a scheduled payment could not be looked for.' ];
    }
    $next = as_next_scheduled_action( 'woocommerce_scheduled_subscription_payment', [ 'subscription_id' => $id ] );
    if ( $next === false ) {
      return [ 'checked' => true, 'scheduled' => false, 'gmt' => null, 'timestamp' => null ];
    }
    // as_next_scheduled_action() returns true for an action that is running right now, which
    // is not a timestamp and must not be compared as one.
    if ( $next === true ) {
      return [ 'checked' => true, 'scheduled' => true, 'running_now' => true, 'gmt' => null, 'timestamp' => null ];
    }
    $stamp = (int) $next;
    return [
      'checked' => true,
      'scheduled' => true,
      'gmt' => $stamp > 0 ? gmdate( 'Y-m-d H:i', $stamp ) : null,
      'timestamp' => $stamp,
    ];
  }

  /** Whether payment retries are on, off, or unset — three states, not two. */
  private function retry_state(): array {
    $option = get_option( 'woocommerce_subscriptions_enable_retry', null );
    if ( $option === null ) {
      return [ 'state' => 'unset', 'enabled' => false, 'note' => 'The retry setting has never been saved, and unset defaults to disabled. That is not the same as a site that decided against retries.' ];
    }
    return [ 'state' => $option === 'yes' ? 'enabled' : 'disabled', 'enabled' => $option === 'yes' ];
  }

  /** The gateway slug only, never the payment method's display string. */
  private function gateway( \WC_Subscription $sub ): string {
    $method = (string) $sub->get_payment_method();
    return $method;
  }

  #endregion

  #region Reads

  private function list_subscriptions( array $a, array $r ): array {
    $status = isset( $a['status'] ) && $a['status'] !== '' ? sanitize_key( (string) $a['status'] ) : 'any';
    if ( $status !== 'any' && !in_array( $status, $this->statuses(), true ) ) {
      return $this->error( $r, 'Unknown subscription status "' . $status . '". This shop uses: ' . implode( ', ', $this->statuses() ) . ', any.' );
    }

    $limit = $this->limit( $a );
    $page = max( 1, (int) ( $a['page'] ?? 1 ) );

    $args = [
      'subscriptions_per_page' => $limit,
      'paged' => $page,
      'orderby' => 'start_date',
      'order' => 'DESC',
      'subscription_status' => $status,
    ];
    if ( !empty( $a['customer_id'] ) ) {
      $args['customer_id'] = (int) $a['customer_id'];
    }

    $subs = wcs_get_subscriptions( $args );
    $subs = is_array( $subs ) ? $subs : [];

    $rows = [];
    foreach ( $subs as $sub ) {
      if ( !$sub instanceof \WC_Subscription ) {
        continue;
      }
      $rows[] = $this->summary_row( $sub );
    }
    // wcs_get_subscriptions() keys its array by id, which json_encode turns into an object
    // for a caller expecting a list.
    sort( $rows );
    $rows = array_values( $rows );

    return $this->json( $r, [
      'subscriptions' => $rows,
      'count' => count( $rows ),
      'page' => $page,
      'limit' => $limit,
      'statuses_queried' => $status,
      'retries' => $this->retry_state(),
      'note' => 'No customer data is returned here. Billing email and address, line-item names, notes and payment-method detail live on the same object and are reachable through wc_get_order at admin level.',
    ] );
  }

  private function summary_row( \WC_Subscription $sub ): array {
    $items = [];
    foreach ( $sub->get_items() as $item ) {
      // Product id and quantity only. An item's NAME is free text an admin can edit and a
      // variation's attributes are appended to it, so it is customer-influenced content
      // rather than catalogue data.
      $items[] = [
        'product_id' => (int) $item->get_product_id(),
        'variation_id' => (int) $item->get_variation_id(),
        'quantity' => (int) $item->get_quantity(),
      ];
    }
    $started = (int) $sub->get_time( 'start' );
    return [
      'id' => $sub->get_id(),
      'status' => $this->bare( $sub->get_status() ),
      'customer_id' => (int) $sub->get_customer_id(),
      'currency' => (string) $sub->get_currency(),
      'total' => (string) $sub->get_total(),
      'billing_period' => (string) $sub->get_billing_period(),
      'billing_interval' => (int) $sub->get_billing_interval(),
      'started_gmt' => $started > 0 ? gmdate( 'Y-m-d H:i', $started ) : null,
      'next_payment_gmt' => (int) $sub->get_time( 'next_payment' ) > 0 ? gmdate( 'Y-m-d H:i', (int) $sub->get_time( 'next_payment' ) ) : null,
      'gateway' => $this->gateway( $sub ),
      'items' => $items,
    ];
  }

  private function get_subscription( array $a, array $r ): array {
    $id = (int) ( $a['id'] ?? 0 );
    $sub = $this->subscription( $id );
    if ( !$sub ) {
      return $this->error( $r, 'No subscription with id ' . $id . '.' );
    }

    // Read the related orders through the object's own accessor. WCS writes a cache row
    // onto the subscription the first time these are asked for, so those keys are listed as
    // journal noise in GMCP_Changes rather than a read being recorded as a change.
    $parent_id = (int) $sub->get_parent_id();
    $renewals = [];
    foreach ( (array) $sub->get_related_orders( 'ids', [ 'renewal', 'switch', 'resubscribe' ] ) as $rid ) {
      $order = wc_get_order( (int) $rid );
      $renewals[] = [
        'id' => (int) $rid,
        'status' => $order ? $this->bare( $order->get_status() ) : null,
        'date_gmt' => $order && $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : null,
        'total' => $order ? (string) $order->get_total() : null,
      ];
    }

    $needs_payment = method_exists( $sub, 'needs_payment' ) ? (bool) $sub->needs_payment() : null;

    return $this->json( $r, [
      'id' => $sub->get_id(),
      'status' => $this->bare( $sub->get_status() ),
      'customer_id' => (int) $sub->get_customer_id(),
      'currency' => (string) $sub->get_currency(),
      'total' => (string) $sub->get_total(),
      'billing_period' => (string) $sub->get_billing_period(),
      'billing_interval' => (int) $sub->get_billing_interval(),
      'dates' => $this->dates( $sub ),
      'gateway' => $this->gateway( $sub ),
      'is_manual' => method_exists( $sub, 'is_manual' ) ? (bool) $sub->is_manual() : null,
      'requires_manual_renewal' => (bool) $sub->get_requires_manual_renewal(),
      'needs_payment' => $needs_payment,
      'suspension_count' => (int) $sub->get_suspension_count(),
      'scheduled_payment' => $this->scheduled_payment( $sub->get_id() ),
      'parent_order' => $parent_id > 0 ? [ 'id' => $parent_id ] : null,
      'related_orders' => $renewals,
      'retries' => $this->retry_state(),
      'note' => 'No billing email or address, no line-item names, no notes and no payment-method meta. Use wc_get_order at admin level for those.',
    ] );
  }

  private function briefing( array $a, array $r ): array {
    $counts = [];
    $total = 0;
    foreach ( $this->statuses() as $status ) {
      $ids = wcs_get_subscriptions( [ 'subscriptions_per_page' => -1, 'subscription_status' => $status, 'return' => 'ids' ] );
      $n = is_array( $ids ) ? count( $ids ) : 0;
      if ( $n > 0 ) {
        $counts[ $status ] = $n;
      }
      $total += $n;
    }

    // Renewals due, from the payment schedule rather than from the stored dates, because the
    // schedule is what actually charges.
    $due = [ 'next_7_days' => 0, 'next_30_days' => 0 ];
    if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( '\ActionScheduler_Store' ) ) {
      foreach ( [ 7, 30 ] as $days ) {
        // Action Scheduler takes a bare date plus a separate comparison. A "<=" prefix on
        // the date itself fails to parse and would make the whole briefing throw.
        $found = as_get_scheduled_actions( [
          'hook' => 'woocommerce_scheduled_subscription_payment',
          'status' => \ActionScheduler_Store::STATUS_PENDING,
          'date' => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
          'date_compare' => '<=',
          'per_page' => -1,
        ], 'ids' );
        $due[ 'next_' . $days . '_days' ] = is_array( $found ) ? count( $found ) : 0;
      }
    } else {
      $due = null;
    }

    // Committed recurring amount, normalised to a month, grouped by currency. This is an
    // approximation and is labelled as one: a yearly subscription contributes a twelfth, and
    // an interval is not always a whole number of months.
    $mrr = [];
    $annual = [];
    $active = wcs_get_subscriptions( [ 'subscriptions_per_page' => -1, 'subscription_status' => 'active' ] );
    foreach ( (array) $active as $sub ) {
      if ( !$sub instanceof \WC_Subscription ) {
        continue;
      }
      $currency = (string) $sub->get_currency();
      $amount = (float) $sub->get_total();
      $period = (string) $sub->get_billing_period();
      $interval = max( 1, (int) $sub->get_billing_interval() );
      $per_month = 0.0;
      if ( $period === 'day' ) {
        $per_month = $amount * ( 30 / $interval );
      } elseif ( $period === 'week' ) {
        $per_month = $amount * ( 4.348 / $interval );
      } elseif ( $period === 'month' ) {
        $per_month = $amount / $interval;
      } elseif ( $period === 'year' ) {
        $per_month = $amount / ( 12 * $interval );
      }
      if ( !isset( $mrr[ $currency ] ) ) {
        $mrr[ $currency ] = 0.0;
        $annual[ $currency ] = 0.0;
      }
      $mrr[ $currency ] += $per_month;
      $annual[ $currency ] += $per_month * 12;
    }
    foreach ( $mrr as $currency => $value ) {
      $mrr[ $currency ] = round( $value, 2 );
      $annual[ $currency ] = round( $annual[ $currency ], 2 );
    }

    return $this->json( $r, [
      'total_subscriptions' => $total,
      'by_status' => $counts,
      'renewals_due' => $due,
      'committed' => [
        'per_month' => $mrr,
        'per_year' => $annual,
        'note' => 'The committed amount is each active subscription\'s total normalised to a month and to a year, per currency. It is an approximation: an interval is not always a whole number of months, and it ignores tax, shipping and anything a gateway adds.',
      ],
      'retries' => $this->retry_state(),
    ] );
  }

  #endregion

  #region The one write

  private function set_status( array $a, array $r ): array {
    $id = (int) ( $a['id'] ?? 0 );
    $sub = $this->subscription( $id );
    if ( !$sub ) {
      return $this->error( $r, 'No subscription with id ' . $id . '.' );
    }

    $target = $this->bare( sanitize_key( (string) ( $a['status'] ?? '' ) ) );
    $from = $this->bare( $sub->get_status() );

    if ( !isset( self::ALLOWED_TRANSITIONS[ $from ] ) || !in_array( $target, self::ALLOWED_TRANSITIONS[ $from ], true ) ) {
      $allowed = isset( self::ALLOWED_TRANSITIONS[ $from ] ) ? implode( ', ', self::ALLOWED_TRANSITIONS[ $from ] ) : 'nothing';
      return $this->error( $r, 'A subscription that is ' . $from . ' can be moved to ' . $allowed . ' by this tool, not to ' . $target . '. Other transitions exist in WooCommerce Subscriptions but are refused here: cancelling is terminal and cancels at the gateway on gateways that manage billing, and the states this tool does not offer are reached by the shop or the customer, not by an agent.' );
    }

    if ( $from === $target ) {
      $this->noop = true;
      return $this->text( $r, 'Subscription ' . $id . ' is already ' . $target . '. Nothing changed, and no email was sent.' );
    }

    // Guard one: reactivating a subscription that is behind on a renewal payment.
    //
    // needs_payment() alone is too blunt to be the test. It is true for a subscription whose
    // PARENT order is still pending, which on a manually renewed subscription is the normal
    // state after a fixture is created and is not a debt anybody is owed: the parent was
    // never a charge. What the guard exists for is an unpaid RENEWAL, which is a charge that
    // was attempted and not collected. So the unpaid orders are read and the renewals among
    // them are what decides.
    if ( $target === 'active' && $from === 'on-hold' ) {
      $unpaid = $this->unpaid_renewals( $sub );
      if ( $unpaid ) {
        return $this->error( $r, 'Subscription ' . $id . ' is on hold with an unpaid renewal order (' . implode( ', ', $unpaid ) . '), so reactivating it would restore the customer\'s access and cancel the pending retry without collecting what is owed. Settle or cancel that order first, or leave the subscription on hold.' );
      }
    }

    // Guard two: reactivating a subscription the customer cancelled. The original end date
    // is restored from a meta row written when it was cancelled; without it a fixed-term
    // subscription would come back with no end and renew forever.
    if ( $target === 'active' && $from === 'pending-cancel' && !$sub->meta_exists( 'end_date_pre_cancellation' ) ) {
      return $this->error( $r, 'Subscription ' . $id . ' is pending cancellation but its original end date was not recorded, so reactivating it would leave a fixed-term subscription with no end date. Refused.' );
    }

    // Undoability, computed rather than assumed, and asked before the confirmation so the
    // person reading it knows which kind of change they are approving. On a gateway that
    // manages its own billing, going pending-cancel can suspend the agreement there, and
    // WooCommerce Subscriptions will then refuse to reactivate it.
    $reversible = $this->is_reversible_from_here( $sub, $from, $target );

    // The token is bound to the outcome, not only to the subscription. The same tool makes
    // four different changes and two of them are refused above, so a token minted from a
    // preview of one must not complete another: "$id:$from>$to" plus the values a
    // reactivation recalculates.
    $target_key = $id . ':' . $from . '>' . $target . ':' . md5( (string) $sub->get_time( 'next_payment' ) . '|' . (string) $sub->get_time( 'end' ) );
    if ( !$reversible ) {
      $summary = 'This moves subscription ' . $id . ' from ' . $from . ' to ' . $target . '. It CANNOT be undone from this plugin: ' . $this->why_irreversible( $sub, $from, $target ) . ' The change will be made through WooCommerce Subscriptions, so the payment schedule, the dates and the gateway are kept in step.';
      $gate = \GMCP_Core::confirm_gate( 'wcs_set_subscription_status', $target_key, $a, $summary );
      if ( $gate !== true ) {
        return $this->error( $r, $gate );
      }
    }

    // The state may have moved between the first call and the confirming one, and the guards
    // above were decided on the old one, so every one of them is asked again from a fresh
    // read.
    $fresh = $this->subscription( $id );
    if ( !$fresh ) {
      return $this->error( $r, 'Subscription ' . $id . ' no longer exists, so nothing was changed.' );
    }
    $fresh_from = $this->bare( $fresh->get_status() );
    if ( $fresh_from !== $from ) {
      return $this->error( $r, 'Subscription ' . $id . ' is now ' . $fresh_from . ', not ' . $from . ', so the change was not made. Ask again.', -32603 );
    }
    // The unpaid-renewal check is deliberately NOT repeated here. It was, and the two copies
    // were redundant: the first runs before the confirmation and refuses, so nothing reaches
    // this point with a debt. A second copy that no test can reach is a second copy that can
    // rot silently, which is what disabling it proved.
    if ( $target === 'active' && $fresh_from === 'pending-cancel' && !$fresh->meta_exists( 'end_date_pre_cancellation' ) ) {
      return $this->error( $r, 'Subscription ' . $id . ' no longer has a recorded end date, so it was not reactivated.' );
    }

    $note = isset( $a['note'] ) ? sanitize_textarea_field( (string) $a['note'] ) : '';
    $to_customer = !empty( $a['customer_note'] );

    // Watch what is actually sent rather than predicting it from a status. The subscription
    // plugin hooks its own emails onto the transition actions, and a shop can add more.
    $sent = [];
    $capture = function ( $args ) use ( &$sent ) {
      foreach ( (array) ( $args['to'] ?? [] ) as $to ) {
        foreach ( explode( ',', (string) $to ) as $one ) {
          $one = strtolower( trim( $one ) );
          if ( $one !== '' ) {
            $sent[] = $one;
          }
        }
      }
      return $args;
    };
    add_filter( 'wp_mail', $capture, PHP_INT_MAX );

    // The note ids before the call, so the notes the transition writes can be reported
    // verbatim. A gateway error inside status_transition() is caught by the subscription
    // plugin and turned into a note while update_status() still returns true, so the notes
    // are the only place that failure appears.
    $notes_before = $this->note_ids( $id );

    $before_snapshot = $this->state_snapshot( $sub );
    $threw = '';
    try {
      $sub->update_status( $target, $note, $to_customer ? false : true );
      if ( $to_customer && $note !== '' ) {
        $sub->add_order_note( $note, 1, false );
      }
    } catch ( \Throwable $e ) {
      // Caught and reported rather than returned: update_status() can store the status and
      // then throw from a hook, so the answer is in the stored state either way.
      $threw = $e->getMessage();
    }

    remove_filter( 'wp_mail', $capture, PHP_INT_MAX );

    // Verify against stored state read fresh, never against the return value.
    $after = $this->subscription( $id );
    $stored = $after ? $this->bare( $after->get_status() ) : null;
    if ( $stored !== $target ) {
      return $this->error( $r, 'Subscription ' . $id . ' was asked to move from ' . $from . ' to ' . $target . ' but its stored status is ' . var_export( $stored, true ) . ( $threw !== '' ? ' (the call also threw: ' . $threw . ')' : '' ) . '.', -32603 );
    }

    // A hook that failed is recorded by the subscription plugin as a note, not as an error,
    // so the notes written during the call are surfaced.
    $new_notes = array_diff( $this->note_ids( $id ), $notes_before );
    $hook_error = '';
    foreach ( $this->notes_text( $id, $new_notes ) as $text ) {
      if ( stripos( $text, 'Error during subscription status transition' ) !== false ) {
        $hook_error = $text;
      }
    }
    if ( $hook_error !== '' ) {
      return $this->error( $r, 'Subscription ' . $id . ' is now ' . $stored . ', but WooCommerce Subscriptions recorded an error during the change: ' . $hook_error . ' The gateway may not have been told. Check the subscription in wp-admin.', -32603 );
    }

    $after_snapshot = $after ? $this->state_snapshot( $after ) : [];
    $moved = [];
    foreach ( $after_snapshot as $key => $value ) {
      if ( ( $before_snapshot[ $key ] ?? null ) !== $value ) {
        $moved[] = $key . ': ' . var_export( $before_snapshot[ $key ] ?? null, true ) . ' -> ' . var_export( $value, true );
      }
    }

    $msg = 'Subscription ' . $id . ' moved from ' . $from . ' to ' . $stored . '.'
      . ' ' . $this->describe_mail( $sent );
    if ( $target === 'pending-cancel' ) {
      $msg .= ' The subscription is not cancelled yet: it ends at the period end, and an admin "cancelled subscription" email is sent for that, which does not mean it has already stopped.';
    }
    if ( $moved ) {
      $msg .= ' What else changed: ' . implode( '; ', $moved ) . '.';
    }
    $msg .= ' Not recorded by wp_undo_change, so this cannot be put back with a tool.';
    return $this->text( $r, $msg );
  }

  /** The ids of unpaid RENEWAL orders, which is a debt rather than a pending parent order. */
  private function unpaid_renewals( \WC_Subscription $sub ): array {
    $ids = [];
    foreach ( (array) $sub->get_related_orders( 'ids', [ 'renewal' ] ) as $oid ) {
      $order = wc_get_order( (int) $oid );
      if ( !$order ) {
        continue;
      }
      // Only an order still awaiting payment counts. A cancelled or refunded renewal is not
      // money owed.
      $status = $this->bare( $order->get_status() );
      if ( in_array( $status, [ 'pending', 'failed', 'on-hold' ], true ) && (float) $order->get_total() > 0 ) {
        $ids[] = '#' . $order->get_id() . ' (' . $status . ')';
      }
    }
    return $ids;
  }

  /** The state a caller would want to know moved, for the reply. */
  private function state_snapshot( \WC_Subscription $sub ): array {
    return [
      'status' => $this->bare( $sub->get_status() ),
      'next_payment' => (int) $sub->get_time( 'next_payment' ) ?: null,
      'end' => (int) $sub->get_time( 'end' ) ?: null,
      'suspension_count' => (int) $sub->get_suspension_count(),
    ];
  }

  /** Note ids on a subscription, so the notes written during one call can be found. */
  private function note_ids( int $id ): array {
    $ids = [];
    foreach ( (array) wc_get_order_notes( [ 'order_id' => $id, 'limit' => 200 ] ) as $note ) {
      $ids[] = (int) $note->id;
    }
    return $ids;
  }

  /** The text of the given note ids. */
  private function notes_text( int $id, array $ids ): array {
    $out = [];
    foreach ( (array) wc_get_order_notes( [ 'order_id' => $id, 'limit' => 200 ] ) as $note ) {
      if ( in_array( (int) $note->id, $ids, true ) ) {
        $out[] = (string) $note->content;
      }
    }
    return $out;
  }

  /**
  * Whether this change can be put back from here, computed rather than assumed.
  *
  * On a gateway that schedules its own payments, going pending-cancel can suspend the
  * agreement at the gateway and WooCommerce Subscriptions will then refuse to reactivate it,
  * so the change is one-way even though both endpoints have a tool.
  */
  private function is_reversible_from_here( \WC_Subscription $sub, string $from, string $target ): bool {
    if ( $target !== 'pending-cancel' ) {
      return true;
    }
    $manual = method_exists( $sub, 'is_manual' ) ? $sub->is_manual() : true;
    if ( $manual ) {
      return true;
    }
    $supports = function ( $feature ) use ( $sub ) {
      return method_exists( $sub, 'payment_method_supports' ) && $sub->payment_method_supports( $feature );
    };
    $end = (int) $sub->get_time( 'end' );
    return !$supports( 'gateway_scheduled_payments' ) && $supports( 'subscription_date_changes' ) && $supports( 'subscription_reactivation' ) && $end > time();
  }

  private function why_irreversible( \WC_Subscription $sub, string $from, string $target ): string {
    if ( $target === 'pending-cancel' ) {
      return 'the gateway manages its own billing, so cancelling at the period end can also end the agreement there, and WooCommerce Subscriptions will then refuse to reactivate it.';
    }
    return 'the transition is not one this plugin can reverse.';
  }

  /** Who was written to, in the same shape the order tool uses. */
  private function describe_mail( array $sent ): string {
    $sent = array_values( array_unique( $sent ) );
    if ( !$sent ) {
      return 'No email was sent.';
    }
    return count( $sent ) . ' notification(s) went out to: ' . implode( ', ', $sent ) . '. Treat that as having reached a real person: it cannot be taken back.';
  }

  #endregion

}
