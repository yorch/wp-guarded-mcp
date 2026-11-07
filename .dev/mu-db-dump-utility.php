<?php
/**
 * Test-only: choose the dump utility the database-dump group resolves.
 *
 * smoke-db-dump.sh copies this next to the stand-in scripts and removes it again. It exists because
 * the container that serves the tool over HTTP has neither mariadb-dump nor mysqldump, and the
 * suite has to measure both sides of that: the refusal when there is no utility (option `none`,
 * which is what an uninstalled server looks like) and the whole success path through a stand-in
 * (a path in the shared wp-content volume, which both containers can run).
 *
 * An option rather than a constant so one run can point at four differently-shaped stand-ins — a
 * whole dump, a partial one, one whose markers are in the wrong place — without reinstalling the
 * file between them. No plugin code reads this option; it is read here and nowhere else.
 */
add_filter( 'gmcp_db_dump_utility_paths', function ( $paths ) {
	$choice = (string) get_option( 'gmcp_test_db_dump_utility', '' );
	if ( $choice === 'none' ) {
		return [];
	}
	if ( $choice !== '' ) {
		return [ $choice ];
	}
	return $paths;
} );
