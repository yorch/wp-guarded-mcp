<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* WooCommerce: the shop, on its own switch.
*
* Separate from the admin tools, and off by default, because the risk is a different
* shape. The admin tools can break a site; these read customer names, email addresses
* and delivery addresses, and hand them to a model. That is a decision a shop owner
* should make deliberately rather than inherit from a checkbox about plugins.
*
* Everything here goes through the WooCommerce CRUD classes rather than touching posts
* or meta directly. High-Performance Order Storage moves orders out of wp_posts
* entirely, so a tool that queried posts would work on a fresh install, be tested on a
* fresh install, and return nothing at all on the majority of real shops.
*
* Two things deliberately absent:
*
* Refunds. wc_create_refund() moves money through the payment gateway. An agent that
* can be talked into anything by a product review has no business holding that, and a
* refund is not something a change journal can put back.
*
* Anything that emails a customer as a side effect is called out in the tool
* description, because "update the order status" and "send a stranger an email" look
* like the same action from the call and are not the same action at all.
*/
class GMCP_Tools_Woo {

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

  private function tools(): array {
    return [
      'wc_list_products' => [
        'name' => 'wc_list_products',
        'description' => 'List or search products. Returns id, name, sku, type, status, price, stock status and stock quantity for each.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'search' => [ 'type' => 'string', 'description' => 'Match against name, sku and description.' ],
            'status' => [ 'type' => 'string', 'description' => 'publish, draft, pending or private. Default any.' ],
            'stock_status' => [ 'type' => 'string', 'description' => 'instock, outofstock or onbackorder.' ],
            'category' => [ 'type' => 'string', 'description' => 'Product category slug.' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Default 20, maximum 100.' ],
            'page' => [ 'type' => 'integer' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wc_get_product' => [
        'name' => 'wc_get_product',
        'description' => 'Everything about one product: pricing, stock, dimensions, categories, tags, attributes, and its variations if it has any.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'id' => [ 'type' => 'integer' ] ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'read',
      ],
      'wc_create_product' => [
        'name' => 'wc_create_product',
        'description' => 'Create a simple product. Created as a draft unless status says otherwise, so a half-finished product is never briefly on sale.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'name' => [ 'type' => 'string' ],
            'regular_price' => [ 'type' => 'string' ],
            'sale_price' => [ 'type' => 'string' ],
            'description' => [ 'type' => 'string' ],
            'short_description' => [ 'type' => 'string' ],
            'sku' => [ 'type' => 'string' ],
            'status' => [ 'type' => 'string', 'description' => 'draft (default) or publish.' ],
            'manage_stock' => [ 'type' => 'boolean' ],
            'stock_quantity' => [ 'type' => 'integer' ],
            'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Category slugs or names. Existing categories only; unknown ones are reported, not created.' ],
          ],
          'required' => [ 'name' ],
        ],
        'accessLevel' => 'write',
      ],
      'wc_update_product' => [
        'name' => 'wc_update_product',
        'description' => 'Change fields on an existing product. Only the fields you pass are touched.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer' ],
            'name' => [ 'type' => 'string' ],
            'regular_price' => [ 'type' => 'string' ],
            'sale_price' => [ 'type' => 'string', 'description' => 'Empty string removes the sale price.' ],
            'description' => [ 'type' => 'string' ],
            'short_description' => [ 'type' => 'string' ],
            'sku' => [ 'type' => 'string' ],
            'status' => [ 'type' => 'string' ],
            'preview' => [ 'type' => 'boolean', 'description' => 'Describe what would change and change nothing.' ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'write',
      ],
      'wc_set_stock' => [
        'name' => 'wc_set_stock',
        'description' => 'Set or adjust the stock quantity of a product or variation. Pass quantity for an absolute figure, or delta to add or subtract. Turns stock management on if it is off.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer' ],
            'quantity' => [ 'type' => 'integer' ],
            'delta' => [ 'type' => 'integer', 'description' => 'Positive to add, negative to remove. Ignored if quantity is given.' ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'write',
      ],
      'wc_list_orders' => [
        'name' => 'wc_list_orders',
        'description' => 'List orders, newest first. Returns id, number, status, date, total, item count and the customer name. Contains personal data.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'status' => [ 'type' => 'string', 'description' => 'pending, processing, on-hold, completed, cancelled, refunded or failed.' ],
            'customer' => [ 'type' => 'string', 'description' => 'Customer email or user ID.' ],
            'after' => [ 'type' => 'string', 'description' => 'Only orders after this date, e.g. 2026-01-01.' ],
            'before' => [ 'type' => 'string' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Default 20, maximum 100.' ],
            'page' => [ 'type' => 'integer' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'wc_get_order' => [
        'name' => 'wc_get_order',
        'description' => 'One order in full: line items, totals, payment method, billing and shipping addresses, and its notes. Contains personal data including a postal address.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'id' => [ 'type' => 'integer' ] ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wc_update_order_status' => [
        'name' => 'wc_update_order_status',
        'description' => 'Move an order to another status. This often emails the customer, and the reply says exactly who WooCommerce wrote to, measured during the change rather than guessed from the status. A plugin that bypasses wp_mail entirely is invisible to that measurement. Setting "refunded" marks the order as refunded and does NOT move any money; use your payment provider for an actual refund.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer' ],
            'status' => [ 'type' => 'string' ],
            'note' => [ 'type' => 'string', 'description' => 'Recorded against the order. Private unless customer_note is true.' ],
            'customer_note' => [ 'type' => 'boolean', 'description' => 'Send the note to the customer as well, which emails it to them.' ],
          ],
          'required' => [ 'id', 'status' ],
        ],
        'accessLevel' => 'write',
      ],
      'wc_add_order_note' => [
        'name' => 'wc_add_order_note',
        'description' => 'Add a note to an order. Private by default. A customer note is emailed to the customer, so it reaches a real person.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer' ],
            'note' => [ 'type' => 'string' ],
            'customer_note' => [ 'type' => 'boolean' ],
          ],
          'required' => [ 'id', 'note' ],
        ],
        'accessLevel' => 'write',
      ],
      'wc_list_customers' => [
        'name' => 'wc_list_customers',
        'description' => 'List customer accounts with their order count and lifetime spend. Contains personal data. Guest checkouts have no account and do not appear here; find those through wc_list_orders.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'search' => [ 'type' => 'string' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Default 20, maximum 100.' ],
            'page' => [ 'type' => 'integer' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'wc_sales_summary' => [
        'name' => 'wc_sales_summary',
        'description' => 'Revenue, order count, average order value and the best-selling products over a period. Counts only orders that actually earned money: processing, completed and on-hold.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'days' => [ 'type' => 'integer', 'description' => 'How far back. Default 30, maximum 365.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wc_store_briefing' => [
        'name' => 'wc_store_briefing',
        'description' => 'Orient on this shop in one call: currency, product and order counts by status, what needs attention now (orders awaiting processing, products out of stock or low), and the last 30 days of takings.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'read',
      ],
    ];
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];

    // Belt and braces. The class is only constructed when WooCommerce is active, but
    // a plugin can be deactivated between the check and the call on a long request,
    // and every function below would then be a fatal rather than a message.
    if ( !function_exists( 'wc_get_product' ) ) {
      return $this->error( $r, 'WooCommerce is not active on this site, so its tools cannot run.' );
    }

    switch ( $tool ) {
      case 'wc_list_products': $r = $this->list_products( $args, $r ); break;
      case 'wc_get_product': $r = $this->get_product( $args, $r ); break;
      case 'wc_create_product': $r = $this->create_product( $args, $r ); break;
      case 'wc_update_product': $r = $this->update_product( $args, $r ); break;
      case 'wc_set_stock': $r = $this->set_stock( $args, $r ); break;
      case 'wc_list_orders': $r = $this->list_orders( $args, $r ); break;
      case 'wc_get_order': $r = $this->get_order( $args, $r ); break;
      case 'wc_update_order_status': $r = $this->update_order_status( $args, $r ); break;
      case 'wc_add_order_note': $r = $this->add_order_note( $args, $r ); break;
      case 'wc_list_customers': $r = $this->list_customers( $args, $r ); break;
      case 'wc_sales_summary': $r = $this->sales_summary( $args, $r ); break;
      case 'wc_store_briefing': $r = $this->store_briefing( $args, $r ); break;
      default:
        return $this->error( $r, 'Unknown tool', -32601 );
    }

    // The same post-write hook the other tool groups fire, and it was missing here.
    //
    // These tools go through the WooCommerce CRUD classes, which is right for storage but
    // means nothing in this file touches wp_update_post, so none of the paths that
    // normally announce a content change were running. An integration purging a full-page
    // cache saw a price change and a stock change as silence. Most WooCommerce sites run
    // such a cache, so the visible symptom is a shopper still being shown the old price,
    // which is a worse failure than the stale page it would be anywhere else.
    //
    // A failure is an isError result now, not an error field, so both have to be tested
    // or every refused write would announce itself as a change and purge caches for
    // nothing.
    if ( empty( $r['error'] ) && empty( $r['result']['isError'] ) && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }
    return $r;
  }

  /**
  * Tools here that change the shop.
  *
  * Named rather than derived from the access level: wc_get_order is admin level and
  * changes nothing, so a level test would fire the hook on reads and teach integrations
  * to ignore it.
  */
  const MUTATING = [
    'wc_create_product', 'wc_update_product', 'wc_set_stock',
    'wc_update_order_status', 'wc_add_order_note',
  ];

  #region Helpers

  /**
  * A tool failure the model is supposed to read and act on.
  *
  * These used to be JSON-RPC errors. A protocol error carries no result at all, so a
  * client reading result.content found nothing there, called the response malformed and
  * discarded it whole, including on calls that had already done their work. An isError
  * result is handed to the model as the tool's answer instead, which is what a refusal
  * about a price or a stock level needs to be.
  *
  * -32601 is "method not found", a genuine protocol-level condition, so that one stays a
  * real JSON-RPC error. Everything else is an outcome. tools-core.php, tools-admin.php
  * and server.php's catch block draw the line in the same place.
  *
  * The code is kept in the text because the result shape has nowhere else to put it, and
  * it is what separates a bad argument from a failed write.
  */
  private function error( array $r, string $message, int|string $code = -32602 ): array {
    if ( $code === -32601 ) {
      unset( $r['result'] );
      $r['error'] = [ 'code' => $code, 'message' => $message ];
      return $r;
    }
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

  private function limit( array $a, int $default = 20 ): int {
    return max( 1, min( 100, isset( $a['limit'] ) ? (int) $a['limit'] : $default ) );
  }

  /**
  * Strip the "wc-" that WooCommerce puts in front of its stored status keys.
  *
  * Not ltrim() with 'wc-' as the second argument. That takes a SET OF CHARACTERS, not
  * a prefix, so it eats every leading w, c and hyphen: "wc-completed" comes back as
  * "ompleted" and "wc-cancelled" as "ancelled". Both the incoming status and the list
  * of valid ones were mangled the same way, so validation passed, set_status() was
  * handed a status that does not exist, and WooCommerce quietly dropped the order back
  * to pending while the tool reported that it had been completed.
  */
  private function bare_status( string $key ): string {
    return strpos( $key, 'wc-' ) === 0 ? substr( $key, 3 ) : $key;
  }

  /** Statuses that represent money actually taken. */
  private function paid_statuses(): array {
    return [ 'wc-processing', 'wc-completed', 'wc-on-hold' ];
  }

  private function product_row( $product ): array {
    return [
      'id' => $product->get_id(),
      'name' => $product->get_name(),
      'sku' => $product->get_sku(),
      'type' => $product->get_type(),
      'status' => $product->get_status(),
      'price' => $product->get_price(),
      'regular_price' => $product->get_regular_price(),
      'sale_price' => $product->get_sale_price(),
      'stock_status' => $product->get_stock_status(),
      'stock_quantity' => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
      'permalink' => $product->get_permalink(),
    ];
  }

  #endregion

  #region Products

  private function list_products( array $a, array $r ): array {
    $query = [
      'limit' => $this->limit( $a ),
      'page' => max( 1, (int) ( $a['page'] ?? 1 ) ),
      'paginate' => false,
      'status' => !empty( $a['status'] ) ? sanitize_key( $a['status'] ) : [ 'publish', 'draft', 'pending', 'private' ],
    ];
    if ( !empty( $a['search'] ) ) {
      $query['s'] = sanitize_text_field( $a['search'] );
    }
    if ( !empty( $a['stock_status'] ) ) {
      $query['stock_status'] = sanitize_key( $a['stock_status'] );
    }
    if ( !empty( $a['category'] ) ) {
      $query['category'] = [ sanitize_title( $a['category'] ) ];
    }

    $out = [];
    foreach ( wc_get_products( $query ) as $product ) {
      $out[] = $this->product_row( $product );
    }
    return $this->json( $r, $out );
  }

  private function get_product( array $a, array $r ): array {
    $product = wc_get_product( (int) ( $a['id'] ?? 0 ) );
    if ( !$product ) {
      return $this->error( $r, 'No product with id ' . (int) ( $a['id'] ?? 0 ) . '.' );
    }

    $data = $this->product_row( $product );
    $data['description'] = $product->get_description();
    $data['short_description'] = $product->get_short_description();
    $data['categories'] = wp_list_pluck( get_the_terms( $product->get_id(), 'product_cat' ) ?: [], 'name' );
    $data['tags'] = wp_list_pluck( get_the_terms( $product->get_id(), 'product_tag' ) ?: [], 'name' );
    $data['weight'] = $product->get_weight();
    $data['dimensions'] = [
      'length' => $product->get_length(),
      'width' => $product->get_width(),
      'height' => $product->get_height(),
    ];
    $data['total_sales'] = (int) $product->get_total_sales();

    $attributes = [];
    foreach ( $product->get_attributes() as $attribute ) {
      $attributes[ $attribute->get_name() ] = $attribute->is_taxonomy()
        ? wp_list_pluck( $attribute->get_terms() ?: [], 'name' )
        : $attribute->get_options();
    }
    $data['attributes'] = $attributes;

    // Variations are where the real price and stock live on a variable product, so a
    // report that stopped at the parent would describe a product nobody can buy.
    if ( $product->is_type( 'variable' ) ) {
      $variations = [];
      foreach ( $product->get_children() as $child_id ) {
        $child = wc_get_product( $child_id );
        if ( !$child ) {
          continue;
        }
        $variations[] = [
          'id' => $child->get_id(),
          'attributes' => $child->get_attributes(),
          'sku' => $child->get_sku(),
          'price' => $child->get_price(),
          'stock_status' => $child->get_stock_status(),
          'stock_quantity' => $child->get_manage_stock() ? $child->get_stock_quantity() : null,
        ];
      }
      $data['variations'] = $variations;
    }

    return $this->json( $r, $data );
  }

  private function create_product( array $a, array $r ): array {
    if ( empty( $a['name'] ) ) {
      return $this->error( $r, 'name is required.' );
    }
    $product = new WC_Product_Simple();
    $product->set_name( sanitize_text_field( $a['name'] ) );
    // Draft unless asked otherwise. A product created live with no price and no image
    // is briefly for sale, and somebody can buy it.
    $product->set_status( ( $a['status'] ?? '' ) === 'publish' ? 'publish' : 'draft' );

    foreach ( [ 'regular_price', 'sale_price', 'sku' ] as $field ) {
      if ( isset( $a[ $field ] ) ) {
        $setter = 'set_' . $field;
        try {
          $product->$setter( sanitize_text_field( (string) $a[ $field ] ) );
        }
        catch ( Exception $e ) {
          // A duplicate SKU throws rather than returning an error, and losing the whole
          // creation to it without saying why is the unhelpful version.
          return $this->error( $r, ucfirst( str_replace( '_', ' ', $field ) ) . ' was refused: ' . $e->getMessage() );
        }
      }
    }
    if ( isset( $a['description'] ) ) {
      $product->set_description( wp_kses_post( (string) $a['description'] ) );
    }
    if ( isset( $a['short_description'] ) ) {
      $product->set_short_description( wp_kses_post( (string) $a['short_description'] ) );
    }
    if ( !empty( $a['manage_stock'] ) ) {
      $product->set_manage_stock( true );
      $product->set_stock_quantity( (int) ( $a['stock_quantity'] ?? 0 ) );
    }

    $unknown = [];
    if ( !empty( $a['categories'] ) && is_array( $a['categories'] ) ) {
      $ids = [];
      foreach ( $a['categories'] as $name ) {
        $term = get_term_by( 'slug', sanitize_title( $name ), 'product_cat' )
          ?: get_term_by( 'name', sanitize_text_field( $name ), 'product_cat' );
        if ( $term ) {
          $ids[] = $term->term_id;
        }
        else {
          // Reported rather than created. Creating taxonomy terms from a model's guess
          // at a name is how a shop ends up with Shoes, shoes and Shoes .
          $unknown[] = (string) $name;
        }
      }
      $product->set_category_ids( $ids );
    }

    $id = $product->save();
    if ( !$id ) {
      return $this->error( $r, 'WooCommerce refused to save the product.', -32603 );
    }

    $message = 'Product #' . $id . ' created as ' . $product->get_status() . '.';
    if ( $unknown ) {
      $message .= ' These categories do not exist and were not created: ' . implode( ', ', $unknown ) . '.';
    }
    return $this->text( $r, $message );
  }

  private function update_product( array $a, array $r ): array {
    $product = wc_get_product( (int) ( $a['id'] ?? 0 ) );
    if ( !$product ) {
      return $this->error( $r, 'No product with id ' . (int) ( $a['id'] ?? 0 ) . '.' );
    }

    $fields = [ 'name', 'regular_price', 'sale_price', 'sku', 'status', 'description', 'short_description' ];
    $changes = [];
    foreach ( $fields as $field ) {
      if ( !array_key_exists( $field, $a ) ) {
        continue;
      }
      $getter = 'get_' . $field;
      $old = (string) $product->$getter();
      $new = (string) $a[ $field ];
      if ( $old !== $new ) {
        $changes[ $field ] = [ 'from' => $old, 'to' => $new ];
      }
    }

    if ( !empty( $a['preview'] ) ) {
      return $this->text( $r, ( $changes
        ? "These fields would change on product #{$product->get_id()}:\n" . $this->describe( $changes )
        : 'Nothing in this call would change product #' . $product->get_id() . '.' )
        . "\n\nNothing has been changed. Call the same tool again without preview to go ahead." );
    }

    foreach ( $changes as $field => $change ) {
      $setter = 'set_' . $field;
      $value = in_array( $field, [ 'description', 'short_description' ], true )
        ? wp_kses_post( $change['to'] )
        : sanitize_text_field( $change['to'] );
      try {
        $product->$setter( $value );
      }
      catch ( Exception $e ) {
        return $this->error( $r, ucfirst( str_replace( '_', ' ', $field ) ) . ' was refused: ' . $e->getMessage() );
      }
    }
    if ( !$changes ) {
      return $this->text( $r, 'Nothing to change on product #' . $product->get_id() . '.' );
    }
    $product->save();
    return $this->text( $r, 'Product #' . $product->get_id() . ' updated: ' . implode( ', ', array_keys( $changes ) ) . '.' );
  }

  private function describe( array $changes ): string {
    $lines = [];
    foreach ( $changes as $field => $change ) {
      $from = mb_strlen( $change['from'] ) > 60 ? mb_substr( $change['from'], 0, 60 ) . '…' : $change['from'];
      $to = mb_strlen( $change['to'] ) > 60 ? mb_substr( $change['to'], 0, 60 ) . '…' : $change['to'];
      $lines[] = '  ' . $field . ': "' . $from . '" becomes "' . $to . '"';
    }
    return implode( "\n", $lines );
  }

  private function set_stock( array $a, array $r ): array {
    $product = wc_get_product( (int) ( $a['id'] ?? 0 ) );
    if ( !$product ) {
      return $this->error( $r, 'No product with id ' . (int) ( $a['id'] ?? 0 ) . '.' );
    }
    if ( !isset( $a['quantity'] ) && !isset( $a['delta'] ) ) {
      return $this->error( $r, 'Pass quantity for an absolute figure, or delta to add or subtract.' );
    }

    $was = $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null;
    $product->set_manage_stock( true );
    $new = isset( $a['quantity'] ) ? (int) $a['quantity'] : (int) $was + (int) $a['delta'];
    $product->set_stock_quantity( $new );
    // Set explicitly rather than left to WooCommerce: a product whose quantity drops to
    // zero while its status still says instock stays purchasable.
    $product->set_stock_status( $new > 0 ? 'instock' : 'outofstock' );
    $product->save();

    $from = $was === null ? 'unmanaged' : (string) $was;
    return $this->text( $r, 'Stock for "' . $product->get_name() . '" is now ' . $new
      . ' (was ' . $from . '), status ' . $product->get_stock_status() . '.' );
  }

  #endregion

  #region Orders

  private function list_orders( array $a, array $r ): array {
    $query = [
      'limit' => $this->limit( $a ),
      'page' => max( 1, (int) ( $a['page'] ?? 1 ) ),
      'orderby' => 'date',
      'order' => 'DESC',
    ];
    if ( !empty( $a['status'] ) ) {
      $query['status'] = 'wc-' . $this->bare_status( sanitize_key( $a['status'] ) );
    }
    if ( !empty( $a['customer'] ) ) {
      $query['customer'] = sanitize_text_field( $a['customer'] );
    }
    // wc_get_orders takes a single date_created clause, so a range is expressed as
    // "after...before" rather than two arguments.
    $after = !empty( $a['after'] ) ? sanitize_text_field( $a['after'] ) : '';
    $before = !empty( $a['before'] ) ? sanitize_text_field( $a['before'] ) : '';
    if ( $after && $before ) {
      $query['date_created'] = $after . '...' . $before;
    }
    elseif ( $after ) {
      $query['date_created'] = '>' . $after;
    }
    elseif ( $before ) {
      $query['date_created'] = '<' . $before;
    }

    $out = [];
    foreach ( wc_get_orders( $query ) as $order ) {
      $out[] = [
        'id' => $order->get_id(),
        'number' => $order->get_order_number(),
        'status' => $order->get_status(),
        'date' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : null,
        'total' => $order->get_total(),
        'currency' => $order->get_currency(),
        'items' => $order->get_item_count(),
        'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        'email' => $order->get_billing_email(),
        'payment_method' => $order->get_payment_method_title(),
      ];
    }
    return $this->json( $r, $out );
  }

  private function get_order( array $a, array $r ): array {
    $order = wc_get_order( (int) ( $a['id'] ?? 0 ) );
    if ( !$order ) {
      return $this->error( $r, 'No order with id ' . (int) ( $a['id'] ?? 0 ) . '.' );
    }

    $items = [];
    foreach ( $order->get_items() as $item ) {
      $items[] = [
        'name' => $item->get_name(),
        'product_id' => $item->get_product_id(),
        'variation_id' => $item->get_variation_id(),
        'quantity' => $item->get_quantity(),
        'subtotal' => $item->get_subtotal(),
        'total' => $item->get_total(),
      ];
    }

    $notes = [];
    foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id(), 'limit' => 20 ] ) as $note ) {
      $notes[] = [
        'date' => $note->date_created ? $note->date_created->date( 'Y-m-d H:i' ) : null,
        'author' => $note->added_by,
        'customer_note' => (bool) $note->customer_note,
        'content' => $note->content,
      ];
    }

    return $this->json( $r, [
      'id' => $order->get_id(),
      'number' => $order->get_order_number(),
      'status' => $order->get_status(),
      'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : null,
      'date_paid' => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d H:i' ) : null,
      'currency' => $order->get_currency(),
      'subtotal' => $order->get_subtotal(),
      'shipping_total' => $order->get_shipping_total(),
      'tax_total' => $order->get_total_tax(),
      'discount_total' => $order->get_discount_total(),
      'total' => $order->get_total(),
      'payment_method' => $order->get_payment_method_title(),
      'customer_id' => $order->get_customer_id(),
      'customer_note' => $order->get_customer_note(),
      'billing' => $order->get_address( 'billing' ),
      'shipping' => $order->get_address( 'shipping' ),
      'items' => $items,
      'notes' => $notes,
    ] );
  }

  private function update_order_status( array $a, array $r ): array {
    $order = wc_get_order( (int) ( $a['id'] ?? 0 ) );
    if ( !$order ) {
      return $this->error( $r, 'No order with id ' . (int) ( $a['id'] ?? 0 ) . '.' );
    }
    $status = $this->bare_status( sanitize_key( $a['status'] ?? '' ) );
    $valid = array_map( [ $this, 'bare_status' ], array_keys( wc_get_order_statuses() ) );
    if ( !in_array( $status, $valid, true ) ) {
      return $this->error( $r, 'Unknown status "' . $status . '". This shop uses: ' . implode( ', ', $valid ) . '.' );
    }
    if ( $order->get_status() === $status ) {
      return $this->text( $r, 'Order #' . $order->get_id() . ' is already ' . $status . '. Nothing changed, and no email was sent.' );
    }

    $was = $order->get_status();
    $note = isset( $a['note'] ) ? sanitize_textarea_field( (string) $a['note'] ) : '';
    $to_customer = !empty( $a['customer_note'] );

    // Watch what actually gets sent, rather than predicting it from a list of statuses.
    //
    // The list was wrong in both directions, and measuring it on a real shop corrected
    // both this code and the review that flagged it: on-hold and failed do mail the
    // customer, cancelled goes only to the shop, and a bare move to refunded sends
    // nothing at all, because that customer mail fires from woocommerce_order_fully_
    // refunded, which wc_create_refund() raises and a status change does not.
    //
    // Any list would also go stale. Shops add statuses, and plugins add mail to
    // transitions that had none. Reporting who was written to cannot go stale.
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

    // A private note goes through set_status. A customer note has to be added
    // separately, because set_status records whatever it is given privately and the
    // customer_note flag was documented but never read.
    $order->set_status( $status, $to_customer ? '' : $note, true );
    $order->save();
    if ( $to_customer && $note !== '' ) {
      $order->add_order_note( $note, 1, false );
    }

    remove_filter( 'wp_mail', $capture, PHP_INT_MAX );

    return $this->text( $r, 'Order #' . $order->get_id() . ' moved from ' . $was . ' to ' . $status . '.'
      . ' ' . $this->describe_mail( $sent, $order ) );
  }

  /**
  * Who was actually written to during the change.
  *
  * The wp_mail filter is the right hook and not by luck: in current WordPress it fires
  * before pre_wp_mail, so this still sees a message that an SMTP or mail-disabling plugin
  * later intercepts. Verified against a pre_wp_mail short-circuit. That ordering is the
  * useful way round, because the question is whether WooCommerce decided to write to the
  * customer, not whether the host's mail server was working.
  *
  * It follows that this reports an intention rather than a delivery, so the wording says
  * so. Over-reporting is the safe direction: an agent telling someone nobody was
  * contacted, when a stranger has a message in their inbox, is the failure that matters.
  *
  * Only pre_wp_mail is deliberately not hooked. A filter there that returns null falls
  * through to wp_mail, so hooking both would count the same message twice and need
  * deduplicating for no gain. A plugin that bypasses wp_mail altogether is outside what
  * this can see, and the tool description says so rather than implying otherwise.
  */
  private function describe_mail( array $sent, $order ): string {
    if ( !$sent ) {
      return 'No email was sent.';
    }
    $sent = array_values( array_unique( $sent ) );
    $customer = strtolower( trim( (string) $order->get_billing_email() ) );
    if ( $customer !== '' && in_array( $customer, $sent, true ) ) {
      return 'WooCommerce sent the customer notification for this change to ' . $customer
        . '. Treat that as having reached a real person: it cannot be taken back.';
    }
    return count( $sent ) . ' notification(s) went out, none of them to the customer.';
  }

  private function add_order_note( array $a, array $r ): array {
    $order = wc_get_order( (int) ( $a['id'] ?? 0 ) );
    if ( !$order ) {
      return $this->error( $r, 'No order with id ' . (int) ( $a['id'] ?? 0 ) . '.' );
    }
    $note = sanitize_textarea_field( (string) ( $a['note'] ?? '' ) );
    if ( $note === '' ) {
      return $this->error( $r, 'note cannot be empty.' );
    }
    $customer_note = !empty( $a['customer_note'] );
    $order->add_order_note( $note, $customer_note ? 1 : 0, false );

    return $this->text( $r, $customer_note
      ? 'Note added to order #' . $order->get_id() . ' and emailed to the customer.'
      : 'Private note added to order #' . $order->get_id() . '. The customer was not told.' );
  }

  #endregion

  #region Customers and reporting

  private function list_customers( array $a, array $r ): array {
    $args = [
      'role' => 'customer',
      'number' => $this->limit( $a ),
      'paged' => max( 1, (int) ( $a['page'] ?? 1 ) ),
      'orderby' => 'registered',
      'order' => 'DESC',
    ];
    if ( !empty( $a['search'] ) ) {
      $args['search'] = '*' . sanitize_text_field( $a['search'] ) . '*';
    }

    $out = [];
    foreach ( get_users( $args ) as $user ) {
      $customer = new WC_Customer( $user->ID );
      $out[] = [
        'id' => $user->ID,
        'name' => trim( $customer->get_first_name() . ' ' . $customer->get_last_name() ) ?: $user->display_name,
        'email' => $user->user_email,
        'registered' => $user->user_registered,
        'orders' => wc_get_customer_order_count( $user->ID ),
        'total_spent' => wc_get_customer_total_spent( $user->ID ),
        'country' => $customer->get_billing_country(),
      ];
    }
    return $this->json( $r, $out );
  }

  private function sales_summary( array $a, array $r ): array {
    $days = max( 1, min( 365, isset( $a['days'] ) ? (int) $a['days'] : 30 ) );
    $from = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

    $orders = wc_get_orders( [
      'limit' => -1,
      'status' => $this->paid_statuses(),
      'date_created' => '>=' . $from,
    ] );

    $revenue = 0.0;
    $products = [];
    foreach ( $orders as $order ) {
      $revenue += (float) $order->get_total();
      foreach ( $order->get_items() as $item ) {
        $name = $item->get_name();
        if ( !isset( $products[ $name ] ) ) {
          $products[ $name ] = [ 'name' => $name, 'quantity' => 0, 'revenue' => 0.0 ];
        }
        $products[ $name ]['quantity'] += $item->get_quantity();
        $products[ $name ]['revenue'] += (float) $item->get_total();
      }
    }
    usort( $products, function ( $x, $y ) { return $y['quantity'] <=> $x['quantity']; } );

    return $this->json( $r, [
      'period' => 'the last ' . $days . ' days, from ' . $from,
      'currency' => get_woocommerce_currency(),
      'orders' => count( $orders ),
      'revenue' => round( $revenue, 2 ),
      'average_order_value' => $orders ? round( $revenue / count( $orders ), 2 ) : 0,
      'counted_statuses' => 'processing, completed and on-hold. Cancelled, failed and refunded orders are left out.',
      'best_sellers' => array_slice( array_values( $products ), 0, 10 ),
    ] );
  }

  /** The shop equivalent of wp_site_briefing: one call instead of six. */
  private function store_briefing( array $a, array $r ): array {
    $order_counts = [];
    foreach ( wc_get_order_statuses() as $key => $label ) {
      $count = 0;
      $counts = function_exists( 'wc_get_order_count' ) ? wc_get_order_count( $key ) : null;
      if ( $counts === null ) {
        $counts = count( wc_get_orders( [ 'limit' => -1, 'status' => $key, 'return' => 'ids' ] ) );
      }
      $count = (int) $counts;
      if ( $count ) {
        $order_counts[ $this->bare_status( $key ) ] = $count;
      }
    }

    $out_of_stock = wc_get_products( [ 'limit' => 10, 'stock_status' => 'outofstock', 'status' => 'publish', 'return' => 'objects' ] );
    $low = [];
    $threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
    foreach ( wc_get_products( [ 'limit' => 50, 'status' => 'publish', 'stock_status' => 'instock' ] ) as $product ) {
      if ( $product->get_manage_stock() && (int) $product->get_stock_quantity() <= $threshold ) {
        $low[] = $product->get_name() . ' (' . (int) $product->get_stock_quantity() . ' left)';
      }
    }

    $recent = wc_get_orders( [ 'limit' => -1, 'status' => $this->paid_statuses(), 'date_created' => '>=' . gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ] );
    $revenue = 0.0;
    foreach ( $recent as $order ) {
      $revenue += (float) $order->get_total();
    }

    return $this->json( $r, [
      'store' => [
        'currency' => get_woocommerce_currency(),
        'country' => WC()->countries ? WC()->countries->get_base_country() : null,
        'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
      ],
      'products' => [
        'published' => (int) wp_count_posts( 'product' )->publish,
        'drafts' => (int) wp_count_posts( 'product' )->draft,
        'out_of_stock' => array_map( function ( $p ) { return $p->get_name(); }, $out_of_stock ),
        'low_stock' => $low,
        'low_stock_threshold' => $threshold,
      ],
      'orders' => $order_counts,
      'needs_attention' => [
        'awaiting_processing' => $order_counts['processing'] ?? 0,
        'on_hold' => $order_counts['on-hold'] ?? 0,
        'pending_payment' => $order_counts['pending'] ?? 0,
      ],
      'last_30_days' => [
        'orders' => count( $recent ),
        'revenue' => round( $revenue, 2 ),
      ],
    ] );
  }

  #endregion
}
