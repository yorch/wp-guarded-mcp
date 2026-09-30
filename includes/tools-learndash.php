<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* LearnDash: courses, enrolment and progress, on their own switch.
*
* A course's structure is a tree LearnDash keeps in course meta, and its enrolment is a
* per-user meta row rather than a membership table, so the generic tools cannot reach
* either with any confidence. Two traps in particular make a tool that looks obviously
* correct behave wrongly, and both are about words that sound like the same thing.
*
* "Has access" is not "is enrolled". sfwd_lms_has_access() answers whether a user can VIEW
* a course, and it is true for an open course, for a paynow course with no price, whenever
* the course's join setting is empty (which is the default, so it is true for most courses),
* for anyone who can auto-enrol such as an administrator, and for a group member — all
* before it ever looks at the user's own enrolment. Measured on 4.10.3: after the direct
* enrolment was removed, sfwd_lms_has_access() still returned true. So this group never uses
* it as an enrolment oracle. It reads the direct enrolment from the user meta that records
* it, and it reports the OTHER reasons a user can reach a course separately, because they
* are what decides whether removing access actually does anything.
*
* "Enrolled" is not "reachable". learndash_user_get_enrolled_courses() returns open courses
* as well as enrolled ones, and returns every course for an administrator, so it answers
* "what courses can this user reach", not "what is this user enrolled in". The tools that
* use it say "reachable".
*
* What follows from that: enrolling and unenrolling act on DIRECT enrolment only, and both
* report what else grants access so the caller is not told that access was granted or
* removed when it was not. Unenrolling takes the same server-minted two-step as the other
* irreversible operations, because it is not faithfully reversible: re-enrolling writes a
* new access timestamp, and the old one drives content drip and access expiry. Removing an
* access row is also not something wp_undo_change can put back, because LearnDash keeps
* enrolment in user meta and the change journal does not read user meta.
*
* Student data (names, email addresses, progress) is admin level, like the WooCommerce
* order and customer tools. Course structure and counts are read level.
*/
class GMCP_Tools_Learndash {

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [ 'ld_enroll_user', 'ld_unenroll_user' ];

  const DEFAULT_LIMIT = 20;
  const MAX_LIMIT = 100;
  const COURSE_CAP = 300;
  const USER_CAP = 500;
  const TEXT_CHARS = 400;

  /** Set by a write that turned out to be a no-op, so gmcp_mutate does not fire for it. */
  private $noop = false;

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
  * Whether LearnDash is really usable, checked per call rather than only at registration.
  *
  * The constants are defined by the main plugin file, and the class and function are the
  * ones every tool here calls. A bare class_exists on an autoloaded name is true for a
  * copy that registered its autoloader and then bailed out, which is the trap the other
  * optional groups document.
  */
  private function loaded(): bool {
    return defined( 'LEARNDASH_VERSION' )
      && class_exists( 'LDLMS_Factory_Post' )
      && function_exists( 'ld_update_course_access' )
      && function_exists( 'learndash_get_course_users_access_from_meta' );
  }

  private function tools(): array {
    return [
      'ld_list_courses' => [
        'name' => 'ld_list_courses',
        'description' => 'List the LearnDash courses on this site with their ID, title, status, price, how many lessons, topics and quizzes they hold, and how many users hold a DIRECT enrolment. The direct-enrolment count is not "everyone who can view the course": an open course, or one whose join setting is empty (the default), is viewable by any registered user with nobody enrolled, and administrators can reach every course. Use ld_list_course_users to see who actually holds an enrolment, and ld_get_course for one course\'s structure.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'status' => [ 'type' => 'string', 'description' => 'Post status to list: publish (default), draft, any.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'ld_get_course' => [
        'name' => 'ld_get_course',
        'description' => 'Read one LearnDash course: its structure and its access settings. The structure is the ordered list of lessons, each with the topics and quizzes attached to it, plus the course-level and global quizzes; quizzes are attached to lessons or to the course rather than being steps in the lesson/topic tree, so they are returned under the lesson or course they belong to. The settings summary covers what decides whether a user can reach the course (price type, price, the join setting) and a few course options. It deliberately does not return the course_access_list setting, which is a list of user IDs. This is configuration only: nothing about any individual student is returned.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'course_id' => [ 'type' => 'integer', 'description' => 'The course ID.' ],
          ],
          'required' => [ 'course_id' ],
        ],
        'accessLevel' => 'read',
      ],
      'ld_courses_briefing' => [
        'name' => 'ld_courses_briefing',
        'description' => 'Counts-only overview of the LearnDash site: how many courses, lessons, topics, quizzes and questions exist, how many are published, and the total number of direct enrolments across the published courses it considered. No student content and no personal data is returned, which is why it is a read tool. Direct enrolments are counted from the per-user enrolment meta, so an open course viewable by everyone with nobody enrolled counts as zero here.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => new \stdClass(),
        ],
        'accessLevel' => 'read',
      ],
      'ld_list_course_users' => [
        'name' => 'ld_list_course_users',
        'description' => 'List the users who hold access to one course, with how they got it: a direct enrolment, or membership of a LearnDash Group that grants the course. Returns each user\'s ID, login, display name, email address, when the access was granted, and their progress percentage (lessons and topics completed, which excludes quizzes). This returns personal data, so it is an admin tool, and note that the reply text is recorded in this plugin\'s audit log for its retention window, as every tool reply is. Paginated; the reply reports the total.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'course_id' => [ 'type' => 'integer' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Rows per page, 1 to 100. Default 20.' ],
            'page' => [ 'type' => 'integer', 'description' => 'Page number, 1-based. Default 1.' ],
          ],
          'required' => [ 'course_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'ld_list_user_courses' => [
        'name' => 'ld_list_user_courses',
        'description' => 'List the courses one user can reach, with the reason for each — a direct enrolment, a LearnDash Group, or because the course is open to everyone — and their progress percentage. This is "reachable", not "enrolled": LearnDash returns open courses for any user, and returns every course for an administrator, so each row carries the reason rather than a bare yes. Personal data, so it is an admin tool.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'user_id' => [ 'type' => 'integer' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Rows per page, 1 to 100. Default 20.' ],
          ],
          'required' => [ 'user_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'ld_get_user_progress' => [
        'name' => 'ld_get_user_progress',
        'description' => 'Read one user\'s progress in one course: how many lessons and topics are complete out of the total, the percentage, and a per-lesson and per-topic breakdown, plus their enrolment state (direct and/or group). The percentage covers lessons and topics only — LearnDash keeps quiz attempts in separate tables and its own course-progress total does not include them, so a course with quizzes is not "100% complete" until they are graded elsewhere. Quiz attempts and grades are not returned by this tool.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'user_id' => [ 'type' => 'integer' ],
            'course_id' => [ 'type' => 'integer' ],
          ],
          'required' => [ 'user_id', 'course_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'ld_get_quiz_questions' => [
        'name' => 'ld_get_quiz_questions',
        'description' => 'List the questions in one LearnDash quiz: each question\'s ID, title, text and points. Answer keys are deliberately NOT returned. LearnDash keeps a question\'s correct answers, feedback and hints in its pro-quiz tables rather than on the question post, and those are what would let a caller read the answers to a live quiz; only the question text and its points are returned here. This is an admin tool because the questions of a graded assessment are not public content.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'quiz_id' => [ 'type' => 'integer' ],
          ],
          'required' => [ 'quiz_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'ld_enroll_user' => [
        'name' => 'ld_enroll_user',
        'description' => 'Give a user a DIRECT enrolment in a course, the same enrolment the course\'s users list shows. This grants access; it does not revoke anything and it is not a group membership. The reply reports whether the enrolment was newly created or already existed, and separately says when the user could already reach the course for another reason (an open course, a group, or an administrator\'s automatic access), so a caller is not told that access was granted when the user already had it. Not recorded by wp_undo_change: LearnDash keeps enrolment in user meta, which the change journal does not read, so there is no undo beyond calling ld_unenroll_user. Call ld_get_course first if you need the course\'s access settings.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'user_id' => [ 'type' => 'integer', 'description' => 'The user to enrol.' ],
            'course_id' => [ 'type' => 'integer' ],
          ],
          'required' => [ 'user_id', 'course_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'ld_unenroll_user' => [
        'name' => 'ld_unenroll_user',
        'description' => 'Remove a user\'s DIRECT enrolment from a course. Two steps: the first call changes nothing and returns a confirmation token, and only a second call carrying it removes the enrolment. It refuses when the user has no direct enrolment to remove, and it refuses when their access comes from a LearnDash Group, because deleting the direct enrolment would do nothing and a caller would wrongly believe access had been revoked; where access is a group membership the reply names the group. Access that comes from an open course or from an administrator\'s automatic access is not revoked here either, and a purchase-granted enrolment IS removed as a direct enrolment because that is how a purchase grants one: the transaction itself is untouched and a re-sync from the payment provider can grant it again. Removal is not a faithful undo of ld_enroll_user: the enrolment timestamp is replaced on re-enrolment, and the original drives content drip and access expiry. The record that the user was once enrolled is also kept, because LearnDash keeps it for reporting. Not recorded by wp_undo_change.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'user_id' => [ 'type' => 'integer' ],
            'course_id' => [ 'type' => 'integer' ],
            'confirm' => [ 'type' => 'string', 'description' => 'Confirmation token. Call without it first; the first reply supplies it.' ],
          ],
          'required' => [ 'user_id', 'course_id' ],
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
      return $this->error( $r, 'LearnDash is not loaded on this site, so its tools cannot run.' );
    }

    try {
      switch ( $tool ) {
        case 'ld_list_courses':      $r = $this->list_courses( $args, $r ); break;
        case 'ld_get_course':        $r = $this->get_course( $args, $r ); break;
        case 'ld_courses_briefing':  $r = $this->courses_briefing( $args, $r ); break;
        case 'ld_list_course_users': $r = $this->list_course_users( $args, $r ); break;
        case 'ld_list_user_courses': $r = $this->list_user_courses( $args, $r ); break;
        case 'ld_get_user_progress': $r = $this->get_user_progress( $args, $r ); break;
        case 'ld_get_quiz_questions':$r = $this->get_quiz_questions( $args, $r ); break;
        case 'ld_enroll_user':       $r = $this->enroll_user( $args, $r ); break;
        case 'ld_unenroll_user':     $r = $this->unenroll_user( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'LearnDash threw an error while running ' . $tool . ': ' . $e->getMessage() );
    }

    if ( empty( $r['result']['isError'] ) && !$this->noop && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }
    return $r;
  }

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

  /** A course by ID, or null. Refuses anything that is not actually a LearnDash course. */
  private function course( int $course_id ): ?\WP_Post {
    if ( $course_id <= 0 ) {
      return null;
    }
    $post = get_post( $course_id );
    if ( !$post || $post->post_type !== 'sfwd-courses' ) {
      return null;
    }
    return $post;
  }

  /**
  * A course a WRITE may act on: a real course that is published.
  *
  * Enrolling writes a user meta row that survives the course being a draft, so a write to an
  * unpublished or trashed course would quietly grant access the moment it is published.
   * Reads still accept any status so a draft can be inspected; only the writes are narrowed.
  *
  * @return \WP_Post|null
  */
  private function writable_course( int $course_id ): ?\WP_Post {
    $post = $this->course( $course_id );
    if ( !$post ) {
      return null;
    }
    return $post->post_status === 'publish' ? $post : null;
  }

  /** A user by ID, or null. */
  private function user( int $user_id ): ?\WP_User {
    if ( $user_id <= 0 ) {
      return null;
    }
    $user = get_userdata( $user_id );
    return $user instanceof \WP_User ? $user : null;
  }

  private function meta_setting( int $course_id, string $key ) {
    return function_exists( 'learndash_get_course_meta_setting' ) ? learndash_get_course_meta_setting( $course_id, $key ) : null;
  }

  /**
  * Whether a course is reachable by any registered user without an enrolment.
  *
  * Mirrors the early returns in sfwd_lms_has_access(), which is the only way to answer
  * "would removing this enrolment actually revoke access". The empty-join case is the
  * important one: an empty course_join setting makes the course viewable by everyone, and
  * it is the default, so most courses are open in this sense whether or not anyone meant
  * them to be. A deleted or non-course id cannot be open.
  */
  private function course_is_open( int $course_id ): bool {
    if ( !$this->course( $course_id ) ) {
      return false;
    }
    $price_type = (string) $this->meta_setting( $course_id, 'course_price_type' );
    if ( $price_type === 'open' ) {
      return true;
    }
    if ( $price_type === 'paynow' ) {
      $price = function_exists( 'learndash_get_course_price' ) ? learndash_get_course_price( $course_id ) : [];
      if ( empty( $price['price'] ) ) {
        return true;
      }
    }
    $join = function_exists( 'learndash_get_setting' ) ? learndash_get_setting( $course_id, 'course_join' ) : '';
    return empty( $join );
  }

  /**
  * Every reason a user can reach a course, computed from LearnDash's own sources.
  *
  * Deliberately not sfwd_lms_has_access(): that collapses all of these into one boolean and
  * is true for an open course and for an administrator regardless of enrolment. Reporting
  * the reasons separately is what lets the write tools say what they did and did not change.
  *
  * Expiry is computed here rather than asked of ld_course_access_expired(), and that is not
  * a style choice. That function is not a predicate: when access has lapsed it writes an
  * expired marker, DELETES the enrolment row, fires an action, and — on a course configured
  * to do so — deletes the user's course progress. Calling it from a read tool therefore
  * made a question into permanent data loss, from a read-level key, with nothing announced
  * and no undo. The expiry date itself is a pure computation over stored meta, so it is
  * recomputed from the same inputs and nothing is written.
  */
  private function access_state( int $user_id, int $course_id ): array {
    $direct_meta = get_user_meta( $user_id, 'course_' . $course_id . '_access_from', true );
    $direct = !empty( $direct_meta );

    // Mirrors ld_course_access_expires_on(): the expiry is the enrolment time plus the
    // configured number of days, and only when the course opts into expiry at all.
    $expired = false;
    if ( $direct ) {
      $expire_access = $this->meta_setting( $course_id, 'expire_access' );
      $days = (int) $this->meta_setting( $course_id, 'expire_access_days' );
      if ( !empty( $expire_access ) && $days > 0 && (int) $direct_meta + ( $days * DAY_IN_SECONDS ) <= time() ) {
        $expired = true;
      }
    }

    // The membership list is the same source the listing tool uses, so "via group" here and
    // "via group" there cannot disagree. Its date window (a group that has not started, or
    // has ended) is reported separately rather than folded into the boolean.
    $group = false;
    if ( function_exists( 'learndash_get_course_groups_users_access' ) ) {
      $group = in_array( $user_id, array_map( 'intval', (array) learndash_get_course_groups_users_access( $course_id ) ), true );
    }
    $group_live = function_exists( 'learndash_user_group_enrolled_to_course' ) && (bool) learndash_user_group_enrolled_to_course( $user_id, $course_id );
    $groups = [];
    if ( $group && function_exists( 'learndash_get_users_group_ids' ) && function_exists( 'learndash_group_has_course' ) ) {
      foreach ( (array) learndash_get_users_group_ids( $user_id ) as $group_id ) {
        $group_id = (int) $group_id;
        if ( $group_id > 0 && learndash_group_has_course( $group_id, $course_id ) ) {
          $groups[] = [ 'id' => $group_id, 'title' => (string) get_the_title( $group_id ) ];
        }
      }
    }

    $autoenroll = function_exists( 'learndash_can_user_autoenroll_courses' ) && (bool) learndash_can_user_autoenroll_courses( $user_id );
    $open = $this->course_is_open( $course_id );

    return [
      'direct' => $direct,
      'direct_since' => $direct ? gmdate( 'Y-m-d H:i', (int) $direct_meta ) : null,
      'direct_expired' => $expired,
      'group' => $group,
      'group_live' => $group_live,
      'groups' => $groups,
      'autoenroll' => $autoenroll,
      'open' => $open,
      'reachable' => ( ( $direct && !$expired ) || $group_live || $autoenroll || $open ),
    ];
  }

  /** The lessons/topics/quizzes of a course, read from LearnDash's own list functions. */
  private function course_structure( int $course_id ): array {
    $lessons = [];
    foreach ( (array) learndash_get_lesson_list( $course_id, [ 'num' => 0 ] ) as $lesson ) {
      if ( !$lesson instanceof \WP_Post ) {
        continue;
      }
      $topics = [];
      foreach ( (array) learndash_get_topic_list( $lesson->ID, $course_id ) as $topic ) {
        if ( $topic instanceof \WP_Post ) {
          $topics[] = [ 'id' => $topic->ID, 'title' => $topic->post_title, 'status' => $topic->post_status ];
        }
      }
      $lessons[] = [
        'id' => $lesson->ID,
        'title' => $lesson->post_title,
        'status' => $lesson->post_status,
        'topics' => $topics,
        'quizzes' => $this->quiz_rows( learndash_get_lesson_quiz_list( $lesson->ID ) ),
      ];
    }

    return [
      'lessons' => $lessons,
      'course_quizzes' => $this->quiz_rows( learndash_get_course_quiz_list( $course_id ) ),
      'global_quizzes' => $this->quiz_rows( learndash_get_global_quiz_list( $course_id ) ),
    ];
  }

  /**
  * Turn LearnDash's quiz list rows into {id,title}.
  *
  * These functions return associative arrays, not WP_Post objects — the poster child for a
  * tool that dereferences ->ID and quietly reports every quiz as null.
  */
  private function quiz_rows( $items ): array {
    $rows = [];
    foreach ( (array) $items as $item ) {
      if ( is_array( $item ) ) {
        $id = (int) ( $item['id'] ?? 0 );
        $title = isset( $item['post'] ) && $item['post'] instanceof \WP_Post ? $item['post']->post_title : '';
      } elseif ( $item instanceof \WP_Post ) {
        $id = $item->ID;
        $title = $item->post_title;
      } else {
        continue;
      }
      if ( $id > 0 ) {
        $rows[] = [ 'id' => $id, 'title' => (string) $title ];
      }
    }
    return $rows;
  }

  /** Lesson+topic progress, the only thing LearnDash's own percentage covers. */
  private function progress( int $user_id, int $course_id ): array {
    $p = learndash_user_get_course_progress( $user_id, $course_id, 'legacy' );
    $p = is_array( $p ) ? $p : [];
    $completed = (int) ( $p['completed'] ?? 0 );
    $total = (int) ( $p['total'] ?? 0 );
    return [
      'completed' => $completed,
      'total' => $total,
      'percent' => $total > 0 ? (int) round( $completed / $total * 100 ) : 0,
      'raw' => $p,
    ];
  }

  private function clamp_limit( $value ): int {
    $value = (int) $value;
    if ( $value <= 0 ) {
      return self::DEFAULT_LIMIT;
    }
    return min( self::MAX_LIMIT, $value );
  }

  private function page( $value ): int {
    return max( 1, (int) $value );
  }

  #endregion

  #region Reads

  private function list_courses( array $args, array $r ): array {
    $status = isset( $args['status'] ) && $args['status'] !== '' ? (string) $args['status'] : 'publish';
    if ( !in_array( $status, [ 'publish', 'draft', 'pending', 'private', 'any' ], true ) ) {
      return $this->error( $r, 'status must be one of: publish, draft, pending, private, any.' );
    }
    $ids = get_posts( [
      'post_type' => 'sfwd-courses',
      'post_status' => $status,
      'numberposts' => self::COURSE_CAP + 1,
      'orderby' => 'title',
      'order' => 'ASC',
      'fields' => 'ids',
    ] );
    $truncated = count( $ids ) > self::COURSE_CAP;
    $ids = array_slice( array_map( 'intval', $ids ), 0, self::COURSE_CAP );

    $rows = [];
    foreach ( $ids as $course_id ) {
      $structure = $this->course_structure( $course_id );
      $topic_count = 0;
      $quiz_count = count( $structure['course_quizzes'] ) + count( $structure['global_quizzes'] );
      foreach ( $structure['lessons'] as $lesson ) {
        $topic_count += count( $lesson['topics'] );
        $quiz_count += count( $lesson['quizzes'] );
      }
      $price = function_exists( 'learndash_get_course_price' ) ? learndash_get_course_price( $course_id ) : [];
      $rows[] = [
        'id' => $course_id,
        'title' => (string) get_the_title( $course_id ),
        'status' => (string) get_post_status( $course_id ),
        'price_type' => (string) ( $price['type'] ?? '' ),
        'price' => (string) ( $price['price'] ?? '' ),
        'lessons' => count( $structure['lessons'] ),
        'topics' => $topic_count,
        'quizzes' => $quiz_count,
        'direct_enrolments' => count( (array) learndash_get_course_users_access_from_meta( $course_id ) ),
        'open_to_all_registered_users' => $this->course_is_open( $course_id ),
      ];
    }

    $out = [ 'courses' => $rows, 'count' => count( $rows ) ];
    if ( $truncated ) {
      $out['truncated'] = 'Only the first ' . self::COURSE_CAP . ' courses are listed.';
    }
    return $this->json( $r, $out );
  }

  private function get_course( array $args, array $r ): array {
    $course_id = (int) ( $args['course_id'] ?? 0 );
    $course = $this->course( $course_id );
    if ( !$course ) {
      return $this->error( $r, 'No LearnDash course with ID ' . $course_id . ' exists.' );
    }

    // An explicit allowlist of settings, never the whole _sfwd-courses meta: that holds
    // course_access_list, an array of user IDs, which is personal data by reference and is
    // not needed to read a course's structure.
    $settings = [];
    foreach ( [ 'course_price_type', 'course_price', 'course_join', 'course_prerequisite', 'course_materials', 'certificate' ] as $key ) {
      $settings[ $key ] = $this->meta_setting( $course_id, $key );
    }

    $price = function_exists( 'learndash_get_course_price' ) ? learndash_get_course_price( $course_id ) : [];

    $groups = [];
    if ( function_exists( 'learndash_get_course_groups' ) ) {
      foreach ( (array) learndash_get_course_groups( $course_id ) as $group_id ) {
        $group_id = (int) $group_id;
        if ( $group_id > 0 ) {
          $groups[] = [ 'id' => $group_id, 'title' => (string) get_the_title( $group_id ) ];
        }
      }
    }

    return $this->json( $r, [
      'id' => $course_id,
      'title' => (string) $course->post_title,
      'status' => (string) $course->post_status,
      'excerpt' => wp_strip_all_tags( (string) $course->post_excerpt ),
      'structure' => $this->course_structure( $course_id ),
      'access' => [
        'price_type' => (string) ( $price['type'] ?? '' ),
        'price' => (string) ( $price['price'] ?? '' ),
        'open_to_all_registered_users' => $this->course_is_open( $course_id ),
        'join_setting' => $settings['course_join'],
        'prerequisite' => (int) $settings['course_prerequisite'],
        'groups' => $groups,
      ],
      'settings' => $settings,
    ] );
  }

  private function courses_briefing( array $args, array $r ): array {
    $counts = [];
    foreach ( [ 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question' ] as $type ) {
      $c = wp_count_posts( $type );
      $counts[ $type ] = [
        'publish' => (int) ( $c->publish ?? 0 ),
        'draft' => (int) ( $c->draft ?? 0 ),
      ];
    }
    $course_ids = get_posts( [ 'post_type' => 'sfwd-courses', 'post_status' => 'publish', 'numberposts' => self::COURSE_CAP, 'fields' => 'ids' ] );
    $enrolments = 0;
    foreach ( $course_ids as $course_id ) {
      $enrolments += count( (array) learndash_get_course_users_access_from_meta( (int) $course_id ) );
    }
    return $this->json( $r, [
      'counts' => $counts,
      'direct_enrolments' => $enrolments,
      'note' => 'direct_enrolments counts direct enrolments across up to ' . self::COURSE_CAP . ' published courses. It does not count users who can view a course without being enrolled.',
    ] );
  }

  private function list_course_users( array $args, array $r ): array {
    $course_id = (int) ( $args['course_id'] ?? 0 );
    if ( !$this->course( $course_id ) ) {
      return $this->error( $r, 'No LearnDash course with ID ' . $course_id . ' exists.' );
    }

    $direct_ids = array_map( 'intval', (array) learndash_get_course_users_access_from_meta( $course_id ) );
    $group_ids = function_exists( 'learndash_get_course_groups_users_access' ) ? array_map( 'intval', (array) learndash_get_course_groups_users_access( $course_id ) ) : [];
    $all = array_values( array_unique( array_merge( $direct_ids, $group_ids ) ) );
    sort( $all, SORT_NUMERIC );
    $total = count( $all );

    $limit = $this->clamp_limit( $args['limit'] ?? null );
    $page = $this->page( $args['page'] ?? 1 );
    $slice = array_slice( $all, ( $page - 1 ) * $limit, $limit );

    $rows = [];
    foreach ( $slice as $user_id ) {
      $user = $this->user( $user_id );
      if ( !$user ) {
        continue;
      }
      $state = $this->access_state( $user_id, $course_id );
      $p = $this->progress( $user_id, $course_id );
      $via = [];
      if ( in_array( $user_id, $direct_ids, true ) ) { $via[] = 'direct'; }
      if ( in_array( $user_id, $group_ids, true ) ) { $via[] = 'group'; }
      $rows[] = [
        'user_id' => $user_id,
        'login' => $user->user_login,
        'display_name' => $user->display_name,
        'email' => $user->user_email,
        'via' => $via,
        'direct_since' => $state['direct_since'],
        'progress_percent' => $p['percent'],
        'progress_completed' => $p['completed'],
        'progress_total' => $p['total'],
      ];
    }

    return $this->json( $r, [
      'course_id' => $course_id,
      'users' => $rows,
      'count' => count( $rows ),
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'note' => 'progress_percent covers lessons and topics only, not quizzes. The reply text is recorded in the audit log for its retention window.',
    ] );
  }

  private function list_user_courses( array $args, array $r ): array {
    $user_id = (int) ( $args['user_id'] ?? 0 );
    if ( !$this->user( $user_id ) ) {
      return $this->error( $r, 'No user with ID ' . $user_id . ' exists.' );
    }
    $limit = $this->clamp_limit( $args['limit'] ?? null );
    $course_ids = array_map( 'intval', (array) learndash_user_get_enrolled_courses( $user_id ) );
    sort( $course_ids, SORT_NUMERIC );
    $total = count( $course_ids );

    $rows = [];
    foreach ( array_slice( $course_ids, 0, $limit ) as $course_id ) {
      $post = get_post( $course_id );
      if ( !$post || $post->post_type !== 'sfwd-courses' ) {
        continue;
      }
      $state = $this->access_state( $user_id, $course_id );
      $p = $this->progress( $user_id, $course_id );
      $reasons = [];
      if ( $state['direct'] ) { $reasons[] = 'direct'; }
      if ( $state['group'] ) { $reasons[] = 'group'; }
      if ( $state['autoenroll'] ) { $reasons[] = 'autoenroll'; }
      if ( $state['open'] ) { $reasons[] = 'open'; }
      $rows[] = [
        'course_id' => $course_id,
        'title' => (string) $post->post_title,
        'reasons' => $reasons,
        'direct_since' => $state['direct_since'],
        'progress_percent' => $p['percent'],
      ];
    }

    return $this->json( $r, [
      'user_id' => $user_id,
      'courses' => $rows,
      'count' => count( $rows ),
      'total' => $total,
      'limit' => $limit,
      'note' => 'These are courses the user can REACH, which includes open courses and, for an administrator, every course. Each row carries the reasons so "reachable" is not read as "enrolled".',
    ] );
  }

  private function get_user_progress( array $args, array $r ): array {
    $user_id = (int) ( $args['user_id'] ?? 0 );
    $course_id = (int) ( $args['course_id'] ?? 0 );
    if ( !$this->user( $user_id ) ) {
      return $this->error( $r, 'No user with ID ' . $user_id . ' exists.' );
    }
    if ( !$this->course( $course_id ) ) {
      return $this->error( $r, 'No LearnDash course with ID ' . $course_id . ' exists.' );
    }

    $p = $this->progress( $user_id, $course_id );
    $raw = $p['raw'];

    $lesson_titles = [];
    foreach ( (array) learndash_get_lesson_list( $course_id, [ 'num' => 0 ] ) as $lesson ) {
      if ( $lesson instanceof \WP_Post ) {
        $lesson_titles[ $lesson->ID ] = $lesson->post_title;
      }
    }
    $topic_titles = [];
    foreach ( array_keys( $lesson_titles ) as $lesson_id ) {
      foreach ( (array) learndash_get_topic_list( $lesson_id, $course_id ) as $topic ) {
        if ( $topic instanceof \WP_Post ) {
          $topic_titles[ $topic->ID ] = $topic->post_title;
        }
      }
    }

    $lessons = [];
    foreach ( (array) ( $raw['lessons'] ?? [] ) as $lesson_id => $done ) {
      $lessons[] = [
        'lesson_id' => (int) $lesson_id,
        'title' => (string) ( $lesson_titles[ $lesson_id ] ?? '' ),
        'complete' => (int) $done === 1,
      ];
    }
    $topics = [];
    foreach ( (array) ( $raw['topics'] ?? [] ) as $lesson_id => $topic_set ) {
      foreach ( (array) $topic_set as $topic_id => $done ) {
        $topics[] = [
          'topic_id' => (int) $topic_id,
          'lesson_id' => (int) $lesson_id,
          'title' => (string) ( $topic_titles[ $topic_id ] ?? '' ),
          'complete' => (int) $done === 1,
        ];
      }
    }

    return $this->json( $r, [
      'user_id' => $user_id,
      'course_id' => $course_id,
      'access' => $this->access_state( $user_id, $course_id ),
      'progress' => [
        'completed' => $p['completed'],
        'total' => $p['total'],
        'percent' => $p['percent'],
        'last_step_id' => (int) ( $raw['last_id'] ?? 0 ),
        'status' => (string) ( $raw['status'] ?? '' ),
      ],
      'lessons' => $lessons,
      'topics' => $topics,
      'note' => 'The percentage and totals cover lessons and topics only. LearnDash keeps quiz attempts in separate tables and they are not included here or in its own course-progress total.',
    ] );
  }

  private function get_quiz_questions( array $args, array $r ): array {
    $quiz_id = (int) ( $args['quiz_id'] ?? 0 );
    $quiz = get_post( $quiz_id );
    if ( !$quiz || $quiz->post_type !== 'sfwd-quiz' ) {
      return $this->error( $r, 'No LearnDash quiz with ID ' . $quiz_id . ' exists.' );
    }

    $rows = [];
    foreach ( (array) learndash_get_quiz_questions( $quiz_id ) as $question_id ) {
      $question_id = (int) $question_id;
      $post = get_post( $question_id );
      if ( !$post || $post->post_type !== 'sfwd-question' ) {
        continue;
      }
      // Allowlist only. The answer key lives in LearnDash's pro-quiz tables, and the
      // question's own meta can hold more than the points, so nothing here reads meta
      // wholesale or touches the pro-quiz row.
      $rows[] = [
        'id' => $question_id,
        'title' => (string) $post->post_title,
        'text' => mb_substr( trim( wp_strip_all_tags( (string) $post->post_content ) ), 0, self::TEXT_CHARS ),
        'type' => (string) get_post_meta( $question_id, 'question_type', true ),
        // LearnDash stores the points under question_points; the 'points' name is the form
        // field, not the meta key. Reading 'points' reports 0 for every question on a real
        // site, and a suite that seeds the same wrong key agrees with it.
        'points' => (int) get_post_meta( $question_id, 'question_points', true ),
      ];
    }

    return $this->json( $r, [
      'quiz_id' => $quiz_id,
      'quiz_title' => (string) $quiz->post_title,
      'questions' => $rows,
      'count' => count( $rows ),
      'note' => 'Question text and points only. Correct answers, hints and feedback are not returned.',
    ] );
  }

  #endregion

  #region Writes

  private function enroll_user( array $args, array $r ): array {
    $user_id = (int) ( $args['user_id'] ?? 0 );
    $course_id = (int) ( $args['course_id'] ?? 0 );
    if ( !$this->user( $user_id ) ) {
      return $this->error( $r, 'No user with ID ' . $user_id . ' exists.' );
    }
    if ( !$this->writable_course( $course_id ) ) {
      return $this->error( $r, 'There is no PUBLISHED LearnDash course with ID ' . $course_id . '. Enrolling writes an enrolment that outlives the course being a draft or in the trash, so a write is limited to a published course; a draft can still be read with ld_get_course.' );
    }

    $before = $this->access_state( $user_id, $course_id );

    // The return value of ld_update_course_access() is not the evidence: it returns false
    // both for "already enrolled" and for bad input, so a caller that maps false to failure
    // reports a failure when the stored state is exactly what was asked for. The stored
    // state is what decides.
    if ( $before['direct'] ) {
      $this->noop = true;
      return $this->text( $r, 'User ' . $user_id . ' already holds a direct enrolment in course ' . $course_id . ( $before['direct_since'] ? ' (since ' . $before['direct_since'] . ')' : '' ) . '. Nothing was written.' . $this->reachability_note( $before ) );
    }

    $result = ld_update_course_access( $user_id, $course_id );

    $after = $this->access_state( $user_id, $course_id );
    if ( !$after['direct'] ) {
      return $this->error( $r, 'LearnDash did not record a direct enrolment for user ' . $user_id . ' in course ' . $course_id . ' (ld_update_course_access returned ' . var_export( $result, true ) . ' and no enrolment meta exists).', -32603 );
    }

    return $this->text( $r, 'Granted user ' . $user_id . ' a direct enrolment in course ' . $course_id . '.' . $this->reachability_note( $before ) );
  }

  private function unenroll_user( array $args, array $r ): array {
    $user_id = (int) ( $args['user_id'] ?? 0 );
    $course_id = (int) ( $args['course_id'] ?? 0 );
    if ( !$this->user( $user_id ) ) {
      return $this->error( $r, 'No user with ID ' . $user_id . ' exists.' );
    }
    if ( !$this->writable_course( $course_id ) ) {
      return $this->error( $r, 'There is no PUBLISHED LearnDash course with ID ' . $course_id . '. Removing an enrolment from a draft or trashed course is refused for the same reason enrolling into one is.' );
    }

    $state = $this->access_state( $user_id, $course_id );

    if ( !$state['direct'] ) {
      return $this->error( $r, 'User ' . $user_id . ' has no direct enrolment in course ' . $course_id . ' to remove.' . $this->source_explanation( $state ) );
    }

    // Access that survives removal is not access this tool can revoke, and the two-step
    // exists so a person sees that before it happens. Refuse the group case outright:
    // deleting the direct row would do nothing and the reply would read as "revoked".
    if ( $state['group'] ) {
      return $this->error( $r, 'User ' . $user_id . '\'s access to course ' . $course_id . ' comes from a LearnDash Group (' . $this->group_names( $state ) . '), so removing the direct enrolment would not revoke it. Remove the user from the group instead, or use ld_list_user_courses to confirm the source.' );
    }

    $summary = 'This removes the direct enrolment of user ' . $user_id . ' from course ' . $course_id . ' (held since ' . ( $state['direct_since'] ?? 'unknown' ) . '). It does not delete the record that they were once enrolled, and re-enrolling later writes a new enrolment date, so this is not a faithful undo.';
    $gate = \GMCP_Core::confirm_gate( 'ld_unenroll_user', $user_id . ':' . $course_id, $args, $summary );
    if ( $gate !== true ) {
      return $this->error( $r, $gate );
    }

    // Re-read on the confirming call, and re-apply the group guard: the first call may have
    // been minutes ago, and a group membership that appeared in between would make this
    // removal a no-op that the reply would still describe as a revocation. This also proves
    // the confirmation step did not half-run.
    $recheck = $this->access_state( $user_id, $course_id );
    if ( !$recheck['direct'] ) {
      return $this->error( $r, 'User ' . $user_id . ' no longer holds a direct enrolment in course ' . $course_id . ', so nothing was removed.' );
    }
    if ( $recheck['group'] ) {
      return $this->error( $r, 'User ' . $user_id . '\'s access to course ' . $course_id . ' now also comes from a LearnDash Group (' . $this->group_names( $recheck ) . '), so removing the direct enrolment would not revoke it. Nothing was removed; remove the user from the group instead.' );
    }

    ld_update_course_access( $user_id, $course_id, true );

    $after = $this->access_state( $user_id, $course_id );
    if ( $after['direct'] ) {
      return $this->error( $r, 'LearnDash did not remove the direct enrolment for user ' . $user_id . ' in course ' . $course_id . '; the enrolment meta is still present.', -32603 );
    }

    return $this->text( $r, 'Removed the direct enrolment of user ' . $user_id . ' from course ' . $course_id . '. The enrolment-date record is kept, as LearnDash does for reporting.' . $this->surviving_note( $after ) );
  }

  /** A sentence naming the NON-direct reasons a user can still reach a course, or ''. */
  private function reachability_note( array $state ): string {
    $words = [];
    if ( $state['group'] ) { $words[] = 'a LearnDash Group (' . $this->group_names( $state ) . ')'; }
    if ( $state['autoenroll'] ) { $words[] = 'automatic access for an administrator'; }
    if ( $state['open'] ) { $words[] = 'the course being open to all registered users'; }
    if ( !$words ) {
      return '';
    }
    return ' Note that this user could already reach the course for another reason: ' . implode( ', ', $words ) . '. A direct enrolment is recorded on top of that.';
  }

  private function surviving_note( array $state ): string {
    $reasons = $this->reason_words( $state );
    if ( !$reasons ) {
      return ' The user no longer has a direct enrolment; whether they can still view the course depends on the course\'s access settings.';
    }
    return ' The user can STILL reach the course because: ' . implode( ', ', $reasons ) . '. The direct enrolment was removed; that access was not.';
  }

  private function source_explanation( array $state ): string {
    $reasons = $this->reason_words( $state );
    if ( !$reasons ) {
      return ' They may be able to view it anyway if the course is open to all registered users.';
    }
    return ' Their access comes from: ' . implode( ', ', $reasons ) . '.';
  }

  private function reason_words( array $state ): array {
    $words = [];
    if ( $state['direct'] ) { $words[] = 'a direct enrolment'; }
    if ( $state['group'] ) { $words[] = 'a LearnDash Group (' . $this->group_names( $state ) . ')'; }
    if ( $state['autoenroll'] ) { $words[] = 'automatic access for an administrator'; }
    if ( $state['open'] ) { $words[] = 'the course being open to all registered users'; }
    return $words;
  }

  private function group_names( array $state ): string {
    $names = [];
    foreach ( (array) $state['groups'] as $group ) {
      $names[] = ( $group['title'] !== '' ? $group['title'] : 'group ' . $group['id'] );
    }
    return $names ? implode( ', ', $names ) : 'name unavailable';
  }

  #endregion

}
