<?php
/**
* Test-only mu-plugin: stands in for another plugin that filters WP_Query / WP_Comment_Query
* (a multilingual, membership or "hide from admin" plugin, or a hostile one). The behaviour is
* chosen by the option gmcp_test_filter_mode; gmcp_test_filter_arg carries a post id or a list.
* Installed and removed by smoke-listing-filters.sh. Not shipped.
*/
$gmcp_ff_mode = get_option( 'gmcp_test_filter_mode', '' );
$gmcp_ff_arg = (int) get_option( 'gmcp_test_filter_arg', 0 );
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
}
