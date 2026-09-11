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

  /**
  * Entries fetched for this page, before folding drew some of them as one row.
  *
  * Kept because the two numbers are different things and both are needed: the pager
  * reports how many entries match, and it goes on reporting the true number whatever
  * this screen does with them, while the line above the table says how many rows those
  * entries were drawn as. A count of rows presented as a count of entries would be this
  * screen lying about how much is in the log, which is the one thing it may never do.
  */
  private int $entries_on_page = 0;

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
    $this->entries_on_page = count( $this->items );

    if ( !$this->expanded() ) {
      $this->items = $this->fold( $this->items );
    }

    // The totals are the query's, not the folded list's. Folding happens after this
    // point and never changes which entries a page holds, so the pager keeps counting
    // entries and the page numbers keep meaning what they meant.
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

  /**
  * Whether the reader has asked to see every row.
  *
  * Read here rather than with the filters, because it is not one. The query, the count,
  * the pager and the export are identical either way; this decides only how many rows
  * the same entries are drawn as. It rides in the query string like orderby does, so the
  * pager links and the sortable headers, which WP_List_Table builds from the current
  * request, carry it without being told to. The forms on this screen have to be told, so
  * fold_control() puts it back as a hidden field inside the one form they share.
  */
  private function expanded(): bool {
    return !empty( $_GET['gmcp_expand'] );
  }

  /**
  * Consecutive entries recording the same call, drawn as one row carrying a count.
  *
  * Agent traffic repeats: twenty-eight identical `wp_get_post_types` calls filled the
  * first page of this log, every cell the same, and the four refusals that were the
  * reason to open it were on page three.
  *
  * Only consecutive entries, and only within the page being rendered. Grouping across
  * the whole match would mean a page of twenty-five entries could draw rows from
  * anywhere in fifty thousand, which breaks paging, the offsets and the totals with it.
  * The cost is that a run crossing a page break is folded on each page separately, and
  * that is the honest failure of the two: the reader sees a smaller count twice rather
  * than a number that does not match the log.
  */
  private function fold( array $rows ): array {
    $out = [];
    $last = null;
    $last_key = null;
    foreach ( $rows as $row ) {
      $key = self::fold_key( $row );
      $stamp = (int) strtotime( (string) $row['ts'] . ' UTC' );
      if ( $key !== null && $key === $last_key ) {
        $out[ $last ]['__fold_ids'][] = (int) $row['id'];
        $out[ $last ]['__fold_ms'][0] = min( $out[ $last ]['__fold_ms'][0], (int) $row['ms'] );
        $out[ $last ]['__fold_ms'][1] = max( $out[ $last ]['__fold_ms'][1], (int) $row['ms'] );
        $out[ $last ]['__fold_span'][0] = min( $out[ $last ]['__fold_span'][0], $stamp );
        $out[ $last ]['__fold_span'][1] = max( $out[ $last ]['__fold_span'][1], $stamp );
        continue;
      }
      // Every row carries the three, folded or not, so the columns have one shape to
      // render rather than a present case and an absent one.
      $row['__fold_ids'] = [ (int) $row['id'] ];
      $row['__fold_ms'] = [ (int) $row['ms'], (int) $row['ms'] ];
      $row['__fold_span'] = [ $stamp, $stamp ];
      $out[] = $row;
      $last = array_key_last( $out );
      $last_key = $key;
    }
    return $out;
  }

  /**
  * What makes two entries the same call, or null for an entry that must stand alone.
  *
  * ANYTHING THAT CHANGED THE SITE STANDS ALONE, however identical it looks. Five rows
  * reading "Option blogdescription updated" are five separate writes with five
  * before-and-after pairs, and a reader asking what happened to that setting has to see
  * five. The test is the one column_changed() uses on the same column, so a row the
  * Changed column has something to say about is never one of several.
  *
  * The rest of the key is every column a folded row goes on to show: tool, target,
  * outcome and the refusal reason, plus the three that say who called. Two unlike
  * refusals must not merge into a row that then prints one of the two reasons, and two
  * callers must not merge into a row that names one of them. ms is deliberately out: a
  * call is not a different call for having taken a millisecond longer, and the folded
  * row reports the range it saw.
  *
  * Length-prefixed rather than joined by a separator, for the reason the row hash is
  * (@see GMCP_Audit::hash). target and detail hold text the caller chose, and a plain
  * join says only "these pieces in this order", so a target carrying the separator could
  * key alike to a different call and hide inside its count.
  */
  private static function fold_key( array $row ): ?string {
    $records = json_decode( (string) ( $row['changes'] ?? '' ), true );
    if ( is_array( $records ) && $records ) {
      return null;
    }
    $key = '';
    foreach ( [ 'tool', 'target', 'outcome', 'detail', 'actor', 'client', 'auth_method' ] as $field ) {
      $value = (string) ( $row[ $field ] ?? '' );
      $key .= $field . ':' . strlen( $value ) . ':' . $value;
    }
    return $key;
  }

  /** How many entries this row stands for. One, for a row that stands for itself. */
  private static function folded( array $item ): int {
    return count( $item['__fold_ids'] ?? [] );
  }

  /** The link to one entry's full record, preserving whatever the reader had filtered to. */
  public static function entry_url( int $id ): string {
    return add_query_arg( 'entry', $id, GMCP_Settings::page_url( 'logs' ) );
  }

  /**
  * The entry id, and for a folded row, every id it stands for.
  *
  * A count on its own would be a dead end: the reader who wants the fourth of twenty-
  * eight calls has no way to reach it, and a row that cannot be got back to is a summary
  * rather than a log. The ids are listed inside a `details` element, which opens with no
  * JavaScript on this screen and carries none. The alternative, a link that re-renders
  * the page unfolded, exists too and is the control above the table; this one opens one
  * run without disturbing the rest of the page.
  */
  public function column_entry( array $item ): string {
    $id = (int) $item['id'];
    $link = sprintf( '<a href="%s"><strong>#%d</strong></a>',
      esc_url( self::entry_url( $id ) ), $id );
    $count = self::folded( $item );
    if ( $count < 2 ) {
      $actions = [
        'view' => sprintf( '<a href="%s">%s</a>',
          esc_url( self::entry_url( $id ) ), esc_html__( 'Full record', 'guarded-mcp' ) ),
      ];
      return $link . $this->row_actions( $actions );
    }
    $ids = [];
    foreach ( $item['__fold_ids'] as $each ) {
      $ids[] = sprintf( '<a href="%s">#%d</a>', esc_url( self::entry_url( (int) $each ) ), (int) $each );
    }
    return $link . sprintf(
      '<details><summary class="gmcp-summary">%s</summary><p class="gmcp-detail">%s</p></details>',
      esc_html( sprintf(
        /* translators: %d: how many entries this one row stands for. */
        _n( '%d identical call', '%d identical calls', $count, 'guarded-mcp' ), $count ) ),
      implode( ' ', $ids ) );
  }

  public function column_when( array $item ): string {
    $stamp = strtotime( $item['ts'] . ' UTC' );
    // Relative in the cell, absolute in the tooltip. A log is mostly read minutes after
    // the fact, where "3 mins ago" is the useful reading, and occasionally read months
    // later, where only the date will do.
    $out = sprintf( '<span title="%s">%s</span>',
      esc_attr( wp_date( 'Y-m-d H:i:s', $stamp ) ),
      esc_html( GMCP_Settings::ago_for( $stamp ) ) );
    if ( self::folded( $item ) < 2 ) {
      return $out;
    }
    // A folded row happened over a stretch of time, and how long that stretch was is
    // most of what the count means: twenty-eight calls in one second is a loop, and the
    // same twenty-eight over an hour is something else. Timestamps have second
    // resolution, so a run inside one second is reported as that rather than as nothing.
    [ $first, $last ] = $item['__fold_span'];
    return $out . sprintf( '<br><span class="gmcp-detail" title="%s">%s</span>',
      esc_attr( sprintf(
        /* translators: 1: earliest timestamp, 2: latest timestamp. */
        __( 'From %1$s to %2$s', 'guarded-mcp' ),
        wp_date( 'Y-m-d H:i:s', $first ), wp_date( 'Y-m-d H:i:s', $last ) ) ),
      esc_html( $last > $first
        ? sprintf(
            /* translators: %s: a length of time, such as "4 mins". */
            __( 'over %s', 'guarded-mcp' ), human_time_diff( $first, $last ) )
        : __( 'within one second', 'guarded-mcp' ) ) );
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

  /**
  * Which door the call came in through, and which account it then ran as.
  *
  * Both, where before naming the client hid the method. They are different facts and the
  * method is the one that decides what the call could do at all: oauth is a grant one
  * administrator gave, and it stops working the moment that account stops being an
  * administrator; bearer is a secret that works for whoever holds it; bearer_url is that
  * same secret sent in the URL, where every proxy and access log in front of the site
  * writes it down, and which this plugin therefore holds to a lower ceiling. "Claude
  * Desktop" says none of that, and it was all the cell said.
  *
  * The method is printed as recorded rather than translated into a friendlier word. The
  * entry page and the export show the same string, and a friendlier word would be this
  * screen's opinion of the method rather than the thing the site wrote down. Where the
  * client name IS the method, which is what a shared token records, it is said once.
  */
  public function column_called_by( array $item ): string {
    $client = (string) $item['client'];
    $method = (string) $item['auth_method'];
    $notes = [];
    if ( $client !== '' && $client !== $method ) {
      $out = esc_html( $client );
      if ( $method !== '' ) {
        /* translators: %s: how the call authenticated, such as oauth or bearer. */
        $notes[] = sprintf( __( 'via %s', 'guarded-mcp' ), $method );
      }
    } else {
      $out = esc_html( $client !== '' ? $client : $method );
    }
    if ( (string) $item['actor_name'] !== '' ) {
      /* translators: %s: a WordPress account name. */
      $notes[] = sprintf( __( 'ran as %s', 'guarded-mcp' ), $item['actor_name'] );
    }
    if ( !$notes ) {
      return $out;
    }
    return $out . sprintf(
      '<br><span class="gmcp-muted" title="%s">%s</span>',
      esc_attr__( 'How the call authenticated, and the WordPress account it ran as. oauth is a grant one administrator approved; bearer is a shared secret; bearer_url is that secret sent in the URL, where servers log it. A shared token borrows one administrator account, so the account does not identify a person.', 'guarded-mcp' ),
      esc_html( implode( ' · ', $notes ) )
    );
  }

  /** What became of the call. Why, for a refusal, is a line of its own: @see single_row(). */
  public function column_outcome( array $item ): string {
    if ( (string) $item['outcome'] === 'ok' ) {
      // A folded row saw a range of durations, and reporting one of them as though it
      // were the call's would be inventing a measurement.
      [ $fastest, $slowest ] = $item['__fold_ms'] ?? [ (int) $item['ms'], (int) $item['ms'] ];
      $took = $fastest === $slowest
        ? sprintf( '(%dms)', $fastest )
        : sprintf(
            /* translators: 1: the fastest call, 2: the slowest, in milliseconds. */
            __( '(%1$d–%2$dms)', 'guarded-mcp' ), $fastest, $slowest );
      return sprintf( '<span class="gmcp-ok">%s</span> <span class="gmcp-muted">%s</span>',
        esc_html__( 'Done', 'guarded-mcp' ),
        esc_html( $took ) );
    }
    return '<span class="gmcp-fail">' . esc_html__( 'Refused', 'guarded-mcp' ) . '</span>';
  }

  public function column_default( $item, $column_name ) {
    return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
  }

  /**
  * A refused row is worth spotting from across the table rather than by reading it, and
  * worth reading without opening it.
  *
  * WHY THE REASON IS A ROW AND NOT A CELL. The word "Refused" on its own was not an
  * answer: four refusals with four unrelated causes, a guard on the plugin's own
  * scheduled events and a guard on its own credentials among them, all rendered
  * identically, so the Refusals view could not be read without opening every row in it.
  * The reason is already in the entry, stripped of tags and capped at a thousand
  * characters when it was written, and already on the entry page.
  *
  * It went in the Result column first, which is where it belongs by meaning. On the
  * screen that column is about a hundred pixels wide, because six columns of short
  * values are ahead of it and nothing makes a table column widen for the last one, so a
  * two-sentence refusal wrapped to eight lines and made the rows that matter most the
  * hardest to read. A full-width line under the row gives the sentence the width of the
  * table. Core does the same thing for the plugin update notices, with the same shape.
  *
  * ONLY REFUSALS GET ONE. A successful call's detail is the tool's own report, "Post
  * created ID 16", which the Tool, Target and Changed columns have already said between
  * them; a second row for every entry to repeat the row above it is how the entries that
  * matter got pushed off the screen in the first place.
  *
  * Cut at 140 characters, about a line, which holds the identifying first sentence of
  * every refusal this plugin writes. The rest is in the tooltip and on the entry page. A
  * refusal quotes what the caller asked for, so this is the likeliest text in the table
  * to have been written by someone hoping it would not be escaped.
  */
  public function single_row( $item ): void {
    $refused = (string) $item['outcome'] !== 'ok';
    printf( '<tr class="%s">', $refused ? 'gmcp-row-refused' : '' );
    $this->single_row_columns( $item );
    echo '</tr>';

    $why = $refused ? trim( (string) ( $item['detail'] ?? '' ) ) : '';
    if ( $why === '' ) {
      return;
    }
    // Cut back to a word boundary, because a sentence that stops mid-word reads as
    // damaged text rather than as a message with more of it elsewhere.
    $short = mb_strwidth( $why ) > 140
      ? preg_replace( '/\s+\S*$/u', '', mb_strimwidth( $why, 0, 140 ) ) . '…'
      : $why;
    printf( '<tr class="gmcp-row-refused"><td colspan="%d" class="gmcp-detail"%s>%s%s</td></tr>',
      count( $this->get_columns() ),
      $short === $why ? '' : sprintf( ' title="%s"', esc_attr( $why ) ),
      // The row above carries the entry number and the word; a reader who is hearing
      // this rather than seeing it gets neither back, since the cell spans the table.
      sprintf( '<span class="screen-reader-text">%s </span>',
        esc_html( sprintf(
          /* translators: %d: an audit log entry number. */
          __( 'Why entry %d was refused:', 'guarded-mcp' ), (int) $item['id'] ) ) ),
      esc_html( $short ) );
  }

  /**
  * The search box, and the one control that changes what a search reads.
  *
  * Overridden rather than placed beside it, because where to look belongs next to the
  * words being looked for: a checkbox among the tool and account menus would read as
  * another way of narrowing the list, which is the opposite of what it does. The markup
  * below is core's, so the box keeps the position, the styling and the hidden sort
  * fields every other list table has; the addition is the checkbox and its note.
  *
  * A search reads target and detail, both small and both bounded. Ticking the box adds
  * the recorded arguments and the recorded changes, which are longtext and are where the
  * answer to "what touched post 12" lives, and which cost about two orders of magnitude
  * more to search. Hence the wording: it says what is added and that it is slower, and
  * leaves the reader to decide, rather than describing the cost in units nobody has.
  */
  public function search_box( $text, $input_id ): void {
    if ( empty( $_REQUEST['s'] ) && !$this->has_items() ) {
      return;
    }
    $input_id .= '-search-input';
    // The sort travels with the search, or submitting the box silently reorders the
    // table. Core's search_box does this too, and it is the reason to reproduce it here.
    foreach ( [ 'orderby', 'order' ] as $key ) {
      if ( !empty( $_REQUEST[ $key ] ) ) {
        printf( '<input type="hidden" name="%s" value="%s">',
          esc_attr( $key ), esc_attr( sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) ) );
      }
    }
    ?>
    <p class="search-box">
      <label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
      <input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php _admin_search_query(); ?>">
      <?php submit_button( $text, '', '', false, [ 'id' => 'search-submit' ] ); ?>
      <label for="gmcp_deep" class="gmcp-detail">
        <input type="checkbox" name="gmcp_deep" id="gmcp_deep" value="1" <?php checked( !empty( $this->filters['deep'] ) ); ?>>
        <?php esc_html_e( 'Also search the recorded arguments and changes. Slower on a large log.', 'guarded-mcp' ); ?>
      </label>
    </p>
    <?php
  }

  /**
  * The line that says the page is folded, and the link that unfolds it.
  *
  * A link rather than a checkbox. The controls beside it are filters and wait for the
  * Filter button; this is not a filter, it is one click, it needs no JavaScript, and
  * built on the current request it carries the filters, the sort and the page number
  * with it. The page number still means what it meant, because folding never moves an
  * entry from one page to another.
  *
  * Nothing is said when nothing was folded. A notice on every page announcing that
  * nothing happened is how a reader learns to stop reading the line that will one day
  * matter. The caveat about page breaks is in the tooltip rather than the sentence for
  * the same reason: it is true on every page and worth reading once.
  */
  private function fold_control(): void {
    if ( $this->expanded() ) {
      // The field, not the link: this rides in the query string, and the Filter button
      // and the search box both submit a GET form that would drop anything not in it.
      echo '<input type="hidden" name="gmcp_expand" value="1">';
      printf( '<span class="gmcp-detail">%s</span> <a href="%s">%s</a>',
        esc_html__( 'Every entry is on its own row.', 'guarded-mcp' ),
        esc_url( remove_query_arg( 'gmcp_expand' ) ),
        esc_html__( 'Fold repeated calls', 'guarded-mcp' ) );
      return;
    }
    $rows = count( $this->items );
    if ( $rows >= $this->entries_on_page ) {
      return;
    }
    printf( '<span class="gmcp-detail" title="%s">%s</span> <a href="%s">%s</a>',
      esc_attr__( 'Consecutive entries recording the same call are drawn as one row. Entries that changed the site are never folded, and folding happens within a page, so a run crossing a page break is folded on each page separately.', 'guarded-mcp' ),
      esc_html( sprintf(
        /* translators: 1: entries on this page, 2: rows they are drawn as. */
        _n( '%1$d entries here, folded into %2$d row.', '%1$d entries here, folded into %2$d rows.', $rows, 'guarded-mcp' ),
        $this->entries_on_page, $rows ) ),
      esc_url( add_query_arg( 'gmcp_expand', '1' ) ),
      esc_html__( 'Show every row', 'guarded-mcp' ) );
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

    <div class="alignleft actions"><?php $this->fold_control(); ?></div>
    <?php
  }
}
