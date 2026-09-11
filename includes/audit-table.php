<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

if ( !class_exists( 'WP_List_Table' ) ) {
  require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
* The audit log as a WordPress list table.
*
* The hand-rolled table it replaces showed the fifty most recent rows with no way to
* reach the fifty-first, in a table allowed to hold fifty thousand. Everything older was
* reachable only by guessing a search term. It also never printed an entry id, so the
* chain verdict could say "the chain breaks at entry 412" and leave the reader with a
* number and nowhere to put it.
*
* WP_List_Table is worth inheriting rather than reimplementing: pagination, sortable
* column headers, the per-page setting under Screen Options, the search box and the
* filter bar all come with it, and they behave the way the rest of wp-admin behaves,
* which is the part that cannot be retrofitted by adding markup.
*
* Two things it offers that this deliberately does not use.
*
* No checkbox column and no bulk actions. Every bulk action a log could offer is a
* deletion, and rows here hash the row before them: removing one from the middle is
* exactly what the chain exists to make visible, so putting a convenient button on it
* would be building the attack into the product. Pruning is bounded and automatic, and
* "Clear everything" is one deliberate control elsewhere on the screen.
*
* No inline editing, for the same reason one step further along.
*/
class GMCP_Audit_Table extends WP_List_Table {

  /** Filters in force, already sanitised by the caller. */
  private array $filters = [];

  public function __construct( array $filters = [] ) {
    $this->filters = $filters;
    parent::__construct( [
      'singular' => 'gmcp_entry',
      'plural' => 'gmcp_entries',
      // The rows are rendered here, not by AJAX, and saying so stops the base class
      // emitting the script hooks for a feature this screen does not have.
      'ajax' => false,
    ] );
  }

  public function get_columns(): array {
    return [
      'entry' => __( 'Entry', 'guarded-mcp' ),
      'when' => __( 'When', 'guarded-mcp' ),
      'tool' => __( 'Tool', 'guarded-mcp' ),
      'target' => __( 'Target', 'guarded-mcp' ),
      'changed' => __( 'Changed', 'guarded-mcp' ),
      // Not "Who". A shared token borrows one administrator account, so the account is
      // the same whoever sent the request, and a column headed "Who" invites a reader to
      // believe something this site cannot know.
      'called_by' => __( 'Called by', 'guarded-mcp' ),
      'outcome' => __( 'Result', 'guarded-mcp' ),
    ];
  }

  /**
  * Only columns the query can actually order by are offered.
  *
  * A sortable header on a column the query ignores is worse than no header: it responds
  * to the click, redraws, and shows the same order, so the reader concludes the data is
  * already sorted that way.
  */
  public function get_sortable_columns(): array {
    return [
      'entry' => [ 'id', true ],
      'when' => [ 'when', true ],
      'tool' => [ 'tool', false ],
      'outcome' => [ 'outcome', false ],
    ];
  }

  public function get_default_primary_column_name(): string {
    return 'entry';
  }

  public function prepare_items(): void {
    $per_page = $this->get_items_per_page( 'gmcp_audit_per_page', 25 );
    $paged = max( 1, (int) $this->get_pagenum() );

    $filters = $this->filters + [
      'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'when',
      'order' => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
    ];

    // The same filters to both calls. They used to disagree, because count() took a
    // filter array and ignored it, which nothing noticed until a pager divided one by
    // the other and offered pages of a narrowed list that did not exist.
    $total = GMCP_Audit::count( $filters );

    $this->items = GMCP_Audit::query( $filters + [
      'limit' => $per_page,
      'offset' => ( $paged - 1 ) * $per_page,
    ] );

    $this->set_pagination_args( [
      'total_items' => $total,
      'per_page' => $per_page,
      'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
    ] );

    $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns(), 'entry' ];
  }

  public function no_items(): void {
    esc_html_e( 'Nothing matches that. Anything an agent does will appear here.', 'guarded-mcp' );
  }

  /** The link to one entry's full record, preserving whatever the reader had filtered to. */
  public static function entry_url( int $id ): string {
    return add_query_arg( 'entry', $id, GMCP_Settings::page_url( 'logs' ) );
  }

  public function column_entry( array $item ): string {
    $id = (int) $item['id'];
    $actions = [
      'view' => sprintf( '<a href="%s">%s</a>',
        esc_url( self::entry_url( $id ) ), esc_html__( 'Full record', 'guarded-mcp' ) ),
    ];
    return sprintf( '<a href="%s"><strong>#%d</strong></a>%s',
      esc_url( self::entry_url( $id ) ), $id, $this->row_actions( $actions ) );
  }

  public function column_when( array $item ): string {
    $stamp = strtotime( $item['ts'] . ' UTC' );
    // Relative in the cell, absolute in the tooltip. A log is mostly read minutes after
    // the fact, where "3 mins ago" is the useful reading, and occasionally read months
    // later, where only the date will do.
    return sprintf( '<span title="%s">%s</span>',
      esc_attr( wp_date( 'Y-m-d H:i:s', $stamp ) ),
      esc_html( GMCP_Settings::ago_for( $stamp ) ) );
  }

  public function column_tool( array $item ): string {
    return '<code>' . esc_html( (string) $item['tool'] ) . '</code>';
  }

  public function column_target( array $item ): string {
    $target = (string) $item['target'];
    return $target !== '' ? '<code>' . esc_html( $target ) . '</code>' : '&mdash;';
  }

  /**
  * What the call changed, in one line.
  *
  * One line is the constraint, not a simplification. The previous table printed every
  * changed field inline, so a single call that touched eight objects pushed the next
  * call off the screen, and a log you have to scroll past to reach the next entry stops
  * being a list. The full record is one click away and has room for all of it.
  */
  public function column_changed( array $item ): string {
    $records = json_decode( (string) ( $item['changes'] ?? '' ), true );
    if ( !is_array( $records ) || !$records ) {
      return '<span class="gmcp-muted">&mdash;</span>';
    }
    $more = 0;
    $named = [];
    foreach ( $records as $record ) {
      if ( isset( $record['__gmcp_more'] ) ) {
        $more += (int) $record['__gmcp_more'];
        continue;
      }
      $named[] = trim( (string) ( $record['op'] ?? '' ) . ' ' . (string) ( $record['what'] ?? '' ) );
    }
    $first = $named ? array_shift( $named ) : '';
    $rest = count( $named ) + $more;
    $text = $rest > 0
      ? sprintf(
          /* translators: 1: the first object changed, 2: how many others. */
          _n( '%1$s and %2$d other', '%1$s and %2$d others', $rest, 'guarded-mcp' ), $first, $rest )
      : $first;
    return '<span class="gmcp-change">' . esc_html( $text ) . '</span>';
  }

  public function column_called_by( array $item ): string {
    $out = esc_html( (string) ( $item['client'] ?: $item['auth_method'] ) );
    if ( (string) $item['actor_name'] !== '' ) {
      $out .= sprintf(
        '<br><span class="gmcp-muted" title="%s">%s</span>',
        esc_attr__( 'The WordPress account the call ran as. A shared bearer token borrows one administrator, so this does not identify a person.', 'guarded-mcp' ),
        esc_html( sprintf( __( 'ran as %s', 'guarded-mcp' ), $item['actor_name'] ) )
      );
    }
    return $out;
  }

  public function column_outcome( array $item ): string {
    if ( (string) $item['outcome'] === 'ok' ) {
      return sprintf( '<span class="gmcp-ok">%s</span> <span class="gmcp-muted">%s</span>',
        esc_html__( 'Done', 'guarded-mcp' ),
        esc_html( sprintf( '(%dms)', (int) $item['ms'] ) ) );
    }
    return '<span class="gmcp-fail">' . esc_html__( 'Refused', 'guarded-mcp' ) . '</span>';
  }

  public function column_default( $item, $column_name ) {
    return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
  }

  /** A refused row is worth spotting from across the table rather than by reading it. */
  public function single_row( $item ): void {
    printf( '<tr class="%s">', (string) $item['outcome'] === 'ok' ? '' : 'gmcp-row-refused' );
    $this->single_row_columns( $item );
    echo '</tr>';
  }

  /**
  * The filter bar above the table: tool and account, drawn from what the log holds.
  *
  * The outcome filter is a view link rather than a menu, because it is the one people
  * use and it deserves to be one click. Refusals are why this table is kept.
  */
  protected function extra_tablenav( $which ): void {
    if ( $which !== 'top' ) {
      return;
    }
    $facets = GMCP_Audit::facets();
    $tool = (string) ( $this->filters['tool'] ?? '' );
    $actor = (int) ( $this->filters['actor'] ?? 0 );
    ?>
    <div class="alignleft actions">
      <label class="screen-reader-text" for="gmcp_tool"><?php esc_html_e( 'Filter by tool', 'guarded-mcp' ); ?></label>
      <select name="gmcp_tool" id="gmcp_tool">
        <option value=""><?php esc_html_e( 'Every tool', 'guarded-mcp' ); ?></option>
        <?php foreach ( $facets['tools'] as $name ) : ?>
          <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $tool, $name ); ?>>
            <?php echo esc_html( $name ); ?></option>
        <?php endforeach; ?>
      </select>

      <?php if ( count( $facets['actors'] ) > 1 ) : // One account is every row: no choice to make. ?>
        <label class="screen-reader-text" for="gmcp_actor"><?php esc_html_e( 'Filter by account', 'guarded-mcp' ); ?></label>
        <select name="gmcp_actor" id="gmcp_actor">
          <option value="0"><?php esc_html_e( 'Every account', 'guarded-mcp' ); ?></option>
          <?php foreach ( $facets['actors'] as $id => $login ) : ?>
            <option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $actor, $id ); ?>>
              <?php echo esc_html( $login ); ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <label class="screen-reader-text" for="gmcp_since"><?php esc_html_e( 'Only entries since', 'guarded-mcp' ); ?></label>
      <select name="gmcp_since" id="gmcp_since">
        <?php
        // A span in days rather than a date, because the reader is asking "recently",
        // and because a bookmarked "last 7 days" should still mean the last 7 days. The
        // caller turns it into a UTC cutoff for the query.
        $spans = [
          '' => __( 'Any time', 'guarded-mcp' ),
          '1' => __( 'Last 24 hours', 'guarded-mcp' ),
          '7' => __( 'Last 7 days', 'guarded-mcp' ),
          '30' => __( 'Last 30 days', 'guarded-mcp' ),
        ];
        foreach ( $spans as $days => $label ) : ?>
          <option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( (string) ( $this->filters['since_days'] ?? '' ), (string) $days ); ?>>
            <?php echo esc_html( $label ); ?></option>
        <?php endforeach; ?>
      </select>

      <?php submit_button( __( 'Filter', 'guarded-mcp' ), '', 'gmcp_filter', false ); ?>
    </div>
    <?php
  }
}
