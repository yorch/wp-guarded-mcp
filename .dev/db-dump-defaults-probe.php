<?php
/**
 * Test-only: the private option-file writer the dump group hands the client tool, read directly.
 *
 * WHAT IT IS FOR. The credentials file is written to a temporary path, read by the client and
 * removed in the same call, so nothing outside the group ever sees it — and the suite has to show
 * what it holds, because two defects lived exactly there: a `host:port` written whole as one value
 * names a host called `db:3307`, and a password holding a newline ended the line so the rest of it
 * was parsed as an option. Neither is visible in a reply, and both are decided in these two
 * functions.
 *
 * It also writes each generated file to /tmp/gmcp-record/probe/ so smoke-db-dump.sh can put it
 * through the REAL client's `--print-defaults`, which is the client's own statement of what it
 * decoded. That is the difference between "we wrote these bytes" and "the tool reads these values".
 *
 * The DB_HOST forms come from here rather than from the live site because DB_HOST is a constant in
 * wp-config.php: one stack can only ever be configured one way, and all three forms have to be
 * measured.
 *
 * Run in the container that has a mariadb client:
 *   wp eval-file wp-content/plugins/guarded-mcp/.dev/db-dump-defaults-probe.php
 * Output: one JSON object on stdout.
 */
if ( !class_exists( 'GMCP_Tools_Db_Dump' ) ) {
	echo json_encode( [ 'error' => 'GMCP_Tools_Db_Dump is not loaded' ] );
	return;
}

$host_parts_of = new ReflectionMethod( 'GMCP_Tools_Db_Dump', 'utility_host_parts' );
$host_parts_of->setAccessible( true );
$credentials_text = new ReflectionMethod( 'GMCP_Tools_Db_Dump', 'credentials_text' );
$credentials_text->setAccessible( true );

$dir = '/tmp/gmcp-record/probe';
@mkdir( $dir, 0700, true );

$cases = [
	// The form this stack is configured with.
	'plain' => [ 'db', 'wp', 'wp' ],
	// DB_HOST=host:port, which wpdb accepts and the client does not take as one value.
	'host_port' => [ 'db:3307', 'wp', 'wp' ],
	// DB_HOST=host:/path/to.sock, the socket form.
	'socket' => [ 'localhost:/var/run/mysqld/mysqld.sock', 'wp', 'wp' ],
	// The socket form with no host, and the port form with no host: wpdb reads both as localhost.
	'socket_no_host' => [ ':/var/run/mysqld/mysqld.sock', 'wp', 'wp' ],
	// A comma-separated list of hosts: no single-host equivalent, so it is refused.
	'list' => [ 'db1.example,db2.example', 'wp', 'wp' ],
	// A password holding a newline, which is a valid password and breaks a naive writer.
	'newline_password' => [ 'db', 'wp', "ab\ncd" ],
	// A password holding a carriage return.
	'cr_password' => [ 'db', 'wp', "ab\rcd" ],
	// A password holding a quote and a backslash, which end or escape a value.
	'quote_password' => [ 'db', 'wp', 'ab"c\\d' ],
	// A control character the client has no escape for: the writer refuses rather than guess.
	'nul_password' => [ 'db', 'wp', "ab\0cd" ],
];

$out = [ 'cases' => [], 'files' => [] ];
foreach ( $cases as $name => $case ) {
	[ $input, $user, $password ] = $case;
	$parts = $host_parts_of->invoke( null, $input );
	$text = $parts === null ? null : $credentials_text->invoke( null, $parts, $user, $password );
	$out['cases'][ $name ] = [
		'input' => $input,
		'host' => $parts['host'] ?? null,
		'port' => $parts['port'] ?? null,
		'socket' => $parts['socket'] ?? null,
		'file' => $text,
	];
	if ( $text !== null ) {
		$path = $dir . '/' . $name . '.cnf';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $text );
		chmod( $path, 0600 );
		$out['files'][ $name ] = $path;
	}
}

// What the live site's own password line is, so the suite can check the file the group really
// wrote against the escaping function rather than against a copy of its output.
$user = defined( 'DB_USER' ) ? (string) DB_USER : '';
$password = defined( 'DB_PASSWORD' ) ? (string) DB_PASSWORD : '';
$real = $credentials_text->invoke(
	null,
	[ 'host' => 'h', 'port' => null, 'socket' => null ],
	$user,
	$password
);
$out['live_password_line'] = $real === null ? null : explode( "\n", $real )[2];

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
