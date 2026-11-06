<?php
/**
* Test-only mu-plugin: stands in for another plugin that filters WP_Query / WP_Comment_Query
* (a multilingual, membership or "hide from admin" plugin, or a hostile one). The behaviour is
* chosen by the option gmcp_test_filter_mode; gmcp_test_filter_arg carries a post id or a list.
* Installed and removed by smoke-listing-filters.sh. Not shipped.
*/
$gmcp_ff_mode = get_option( 'gmcp_test_filter_mode', '' );
$gmcp_ff_arg = (int) get_option( 'gmcp_test_filter_arg', 0 );
// What the shapes that answer from "their own index" look for in titles and comments.
$gmcp_ff_marker = (string) get_option( 'gmcp_test_filter_marker', 'GMCPFF' );
if ( $gmcp_ff_mode === '' ) {
  return;
}
// Every hook below counts its calls, and the count is added to the option gmcp_test_filter_hits when
// the request ends, so a suite can prove that the filter it installed actually ran: "the guard held"
// means nothing if the hostile filter never fired.
$GLOBALS['gmcp_ff_hits'] = 0;
function gmcp_ff_add( $tag, $callback, $priority = 10, $args = 1 ) {
  add_filter( $tag, function ( ...$a ) use ( $callback ) {
    $GLOBALS['gmcp_ff_hits']++;
    return $callback( ...$a );
  }, $priority, $args );
}
register_shutdown_function( function () {
  if ( $GLOBALS['gmcp_ff_hits'] > 0 ) {
    update_option( 'gmcp_test_filter_hits', (int) get_option( 'gmcp_test_filter_hits', 0 ) + $GLOBALS['gmcp_ff_hits'], false );
  }
} );

// Takes the guard out of a finished SQL text or a clause, wherever it stands (the plugin puts it
// first in the WHERE, and a hostile filter must not depend on that): the joins it adds, the
// NOT (...) group that holds the held types, and the order-note condition. Used by the "late"
// shapes below, which run behind the guard.
function gmcp_ff_remove_guard( $sql ) {
  $sql = preg_replace( '/\s+LEFT JOIN\s+\S+\s+gmcp_\w+\s+ON\s+\S+\s*=\s*\S+/', ' ', (string) $sql );
  while ( ( $pos = stripos( $sql, 'NOT (LOWER(' ) ) !== false ) {
    $depth = 0;
    $end = false;
    for ( $i = $pos + 4; $i < strlen( $sql ); $i++ ) {
      if ( $sql[ $i ] === '(' ) {
        $depth++;
      }
      elseif ( $sql[ $i ] === ')' && --$depth === 0 ) {
        $end = $i;
        break;
      }
    }
    if ( $end === false ) {
      break;
    }
    $sql = substr( $sql, 0, $pos ) . '1=1' . substr( $sql, $end + 1 );
  }
  return preg_replace( '/[\w.`]*comment_type NOT IN \([^)]*\)/', '1=1', $sql );
}
global $wpdb;
switch ( $gmcp_ff_mode ) {
  case 'where_exclude': // benign: a visibility plugin hiding one post
    gmcp_ff_add( 'posts_where', function ( $w ) use ( $gmcp_ff_arg, $wpdb ) {
      return $w . " AND {$wpdb->posts}.ID <> " . $gmcp_ff_arg;
    } );
    break;
  case 'clauses_join': // benign: a plugin that joins a meta table and requires a row in it
    gmcp_ff_add( 'posts_clauses', function ( $c ) use ( $wpdb ) {
      $c['join'] .= " INNER JOIN {$wpdb->postmeta} gmcp_ff ON gmcp_ff.post_id = {$wpdb->posts}.ID AND gmcp_ff.meta_key = '_gmcp_ff_visible'";
      return $c;
    } );
    break;
  case 'pre_get_posts_type': // changes what a query is about: adds the personal-data type
    gmcp_ff_add( 'pre_get_posts', function ( $q ) {
      $q->set( 'post_type', [ 'post', 'nf_sub' ] );
    } );
    break;
  case 'where_or1': // hostile: OR 1=1 appended at a normal priority
    gmcp_ff_add( 'posts_where', function ( $w ) {
      return $w . ' OR 1=1';
    } );
    break;
  case 'where_reset_early': // hostile: throws away the where built so far, at the lowest priority
    gmcp_ff_add( 'posts_where', function ( $w ) {
      return " AND 1=1 ";
    }, -PHP_INT_MAX );
    break;
  case 'where_reset_max': // hostile: throws away the where at the highest priority, registered first
    gmcp_ff_add( 'posts_where', function ( $w ) {
      return " AND 1=1 ";
    }, PHP_INT_MAX );
    break;
  case 'clauses_reset': // hostile: rewrites the where of the whole clause set
    gmcp_ff_add( 'posts_clauses', function ( $c ) {
      $c['where'] = ' AND 1=1 ';
      $c['join'] = '';
      return $c;
    }, 10 );
    break;
  case 'clauses_request_reset': // hostile: the last clause-wise hook, highest priority, registered first
    gmcp_ff_add( 'posts_clauses_request', function ( $c ) {
      $c['where'] = ' AND 1=1 ';
      $c['join'] = '';
      return $c;
    }, PHP_INT_MAX );
    break;
  case 'clauses_request_or1': // hostile: OR 1=1 at the last clause-wise hook
    gmcp_ff_add( 'posts_clauses_request', function ( $c ) {
      $c['where'] .= ' OR 1=1 ';
      return $c;
    }, PHP_INT_MAX );
    break;
  case 'request_rewrite': // hostile: replaces the whole SQL text
    gmcp_ff_add( 'posts_request', function ( $sql ) use ( $wpdb ) {
      return "SELECT {$wpdb->posts}.* FROM {$wpdb->posts} WHERE 1=1 ORDER BY ID DESC LIMIT 0, 500";
    }, PHP_INT_MAX );
    break;
  case 'results_inject': // hostile: appends a row to the results
    gmcp_ff_add( 'posts_results', function ( $posts ) use ( $gmcp_ff_arg ) {
      $p = get_post( $gmcp_ff_arg );
      if ( $p && is_array( $posts ) ) {
        $posts[] = $p;
      }
      return $posts;
    } );
    break;
  case 'the_posts_inject':
    gmcp_ff_add( 'the_posts', function ( $posts ) use ( $gmcp_ff_arg ) {
      $p = get_post( $gmcp_ff_arg );
      if ( $p && is_array( $posts ) ) {
        array_unshift( $posts, $p );
      }
      return $posts;
    } );
    break;
  case 'pre_query_inject': // hostile: bypasses SQL entirely and returns one chosen post
    gmcp_ff_add( 'posts_pre_query', function ( $posts, $q ) use ( $gmcp_ff_arg ) {
      $p = get_post( $gmcp_ff_arg );
      return $p ? [ $p ] : $posts;
    }, 10, 2 );
    break;
  case 'pre_query_index': // answers attachment queries from "its own index": every file, newest first, the offset honoured
    gmcp_ff_add( 'posts_pre_query', function ( $posts, $q ) use ( $wpdb ) {
      if ( $q->get( 'post_type' ) !== 'attachment' ) {
        return $posts;
      }
      $n = max( 1, (int) $q->get( 'posts_per_page' ) );
      $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title LIKE %s ORDER BY post_date DESC, ID DESC LIMIT %d, %d",
        '%GMCPFF%', (int) $q->get( 'offset' ), $n
      ) );
      return array_values( array_filter( array_map( 'get_post', $ids ) ) );
    }, 10, 2 );
    break;
  case 'pre_get_posts_suppress': // switches the query filters off, which takes the guard's SQL with them
    gmcp_ff_add( 'pre_get_posts', function ( $q ) {
      $q->set( 'suppress_filters', true );
    }, PHP_INT_MAX );
    break;
  case 'comments_pre_query_ids': // hostile: answers a comment query without asking the database
    gmcp_ff_add( 'comments_pre_query', function ( $answer, $q ) use ( $gmcp_ff_arg ) {
      $ids = array_map( 'intval', explode( ',', (string) get_option( 'gmcp_test_filter_ids', '' ) ) );
      return empty( $q->query_vars['count'] ) ? array_values( array_filter( array_map( 'get_comment', $ids ) ) ) : count( $ids );
    }, 10, 2 );
    break;
  case 'comments_exclude': // benign: hides one comment
    gmcp_ff_add( 'comments_clauses', function ( $c ) use ( $gmcp_ff_arg, $wpdb ) {
      $c['where'] .= " AND {$wpdb->comments}.comment_ID <> " . $gmcp_ff_arg;
      return $c;
    } );
    break;
  case 'comments_reset': // hostile: replaces the where of the comment query
    gmcp_ff_add( 'comments_clauses', function ( $c ) {
      $c['where'] = '1=1';
      $c['join'] = '';
      return $c;
    }, 10 );
    break;
  case 'comments_or1':
    gmcp_ff_add( 'comments_clauses', function ( $c ) {
      $c['where'] .= ' OR 1=1';
      return $c;
    }, 10 );
    break;
  case 'comments_results_inject':
    gmcp_ff_add( 'the_comments', function ( $comments ) use ( $gmcp_ff_arg ) {
      $c = get_comment( $gmcp_ff_arg );
      if ( $c && is_array( $comments ) ) {
        array_unshift( $comments, $c );
      }
      return $comments;
    } );
    break;

  // ---- Shapes that run BEHIND the guard: registered from inside pre_get_posts / pre_get_comments
  // ---- at PHP_INT_MAX, so at the guard's own priority but later in the hook's order.
  case 'late_the_posts': // adds one chosen post to every result of a post query, behind the row judge
    add_action( 'pre_get_posts', function () use ( $gmcp_ff_arg ) {
      add_filter( 'the_posts', function ( $posts ) use ( $gmcp_ff_arg ) {
        if ( !is_array( $posts ) ) {
          return $posts;
        }
        foreach ( $posts as $p ) {
          if ( (int) $p->ID === $gmcp_ff_arg ) {
            return $posts;
          }
        }
        $p = get_post( $gmcp_ff_arg );
        if ( $p ) {
          $GLOBALS['gmcp_ff_hits']++;
          array_unshift( $posts, $p );
        }
        return $posts;
      }, PHP_INT_MAX );
    }, PHP_INT_MAX );
    break;
  case 'late_rest_inject': // adds a row naming a chosen post to the REST response at the last filter of the dispatch, behind everything
  case 'late_rest_inject_noid': // the same row with no id in it
    add_action( 'pre_get_posts', function () use ( $gmcp_ff_arg, $gmcp_ff_mode ) {
      add_filter( 'rest_request_after_callbacks', function ( $result ) use ( $gmcp_ff_arg, $gmcp_ff_mode ) {
        $data = $result instanceof WP_HTTP_Response ? $result->get_data() : null;
        if ( !is_array( $data ) || !wp_is_numeric_array( $data ) ) {
          return $result;
        }
        $row = [ 'title' => [ 'rendered' => get_the_title( $gmcp_ff_arg ) ] ];
        if ( $gmcp_ff_mode === 'late_rest_inject' ) {
          $row = [ 'id' => $gmcp_ff_arg ] + $row;
        }
        $GLOBALS['gmcp_ff_hits']++;
        array_unshift( $data, $row );
        $result->set_data( $data );
        return $result;
      }, PHP_INT_MAX );
    }, PHP_INT_MAX );
    break;
  case 'late_pre_query_index': // answers attachment queries from its own index, behind the guard's check
    add_action( 'pre_get_posts', function () use ( $wpdb, $gmcp_ff_marker ) {
      add_filter( 'posts_pre_query', function ( $posts, $q ) use ( $wpdb, $gmcp_ff_marker ) {
        if ( $posts !== null || $q->get( 'post_type' ) !== 'attachment' ) {
          return $posts;
        }
        $GLOBALS['gmcp_ff_hits']++;
        $ids = $wpdb->get_col( $wpdb->prepare(
          "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title LIKE %s ORDER BY post_date DESC, ID DESC LIMIT %d, %d",
          '%' . $gmcp_ff_marker . '%', (int) $q->get( 'offset' ), max( 1, (int) $q->get( 'posts_per_page' ) )
        ) );
        return array_values( array_filter( array_map( 'get_post', $ids ) ) );
      }, PHP_INT_MAX, 2 );
    }, PHP_INT_MAX );
    break;
  case 'late_request_strip': // takes the guard out of the finished SQL, behind the guard's check
    add_action( 'pre_get_posts', function () {
      add_filter( 'posts_request', function ( $sql ) {
        $new = gmcp_ff_remove_guard( $sql );
        if ( $new !== $sql ) {
          $GLOBALS['gmcp_ff_hits']++;
        }
        return $new;
      }, PHP_INT_MAX );
    }, PHP_INT_MAX );
    break;
  case 'where_comment_tail': // an unterminated comment at the end of its WHERE fragment, normal priority
    gmcp_ff_add( 'posts_where', function ( $w ) {
      return $w . ' ) OR 1=1 /*';
    } );
    break;
  case 'orderby_comment_tail': // an unterminated comment at the end of its ORDER BY, which swallows the LIMIT
    gmcp_ff_add( 'posts_orderby', function ( $o ) use ( $wpdb ) {
      return $o . ' /*';
    } );
    break;
  case 'late_comments_strip': // takes the guard out of the comment clauses, behind the guard
    add_action( 'pre_get_comments', function () {
      add_filter( 'comments_clauses', function ( $c ) {
        $new = gmcp_ff_remove_guard( (string) $c['where'] );
        $join = preg_replace( '/\s+LEFT JOIN\s+\S+\s+gmcp_\w+\s+ON\s+\S+\s*=\s*\S+/', ' ', (string) $c['join'] );
        if ( $new !== $c['where'] || $join !== $c['join'] ) {
          $GLOBALS['gmcp_ff_hits']++;
          $c['where'] = $new;
          $c['join'] = $join;
        }
        return $c;
      }, PHP_INT_MAX );
    }, PHP_INT_MAX );
    break;
  case 'late_comments_index': // answers comment queries from its own index, behind the guard's check
    add_action( 'pre_get_comments', function () use ( $wpdb, $gmcp_ff_marker ) {
      add_filter( 'comments_pre_query', function ( $answer, $q ) use ( $wpdb, $gmcp_ff_marker ) {
        if ( $answer !== null ) {
          return $answer;
        }
        $GLOBALS['gmcp_ff_hits']++;
        $ids = $wpdb->get_col( $wpdb->prepare(
          "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_content LIKE %s ORDER BY comment_date_gmt DESC, comment_ID DESC LIMIT %d, %d",
          '%' . $gmcp_ff_marker . '%', (int) $q->query_vars['offset'], max( 1, (int) $q->query_vars['number'] )
        ) );
        return array_values( array_filter( array_map( 'get_comment', $ids ) ) );
      }, PHP_INT_MAX, 2 );
    }, PHP_INT_MAX );
    break;
}
