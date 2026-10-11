<?php
/**
 * Redirect map and `wp assemble setup` tests. Local only.
 *
 * Checks how request paths match the redirect map, and that setup is
 * idempotent: after one run, a second creates, adopts and assigns nothing.
 * The second half runs setup for real, so run it on a local site only.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/setup-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['ast'] = [ 'pass' => 0, 'fail' => 0 ];

function ast_check( bool $ok, string $label ): void {
	$GLOBALS['ast'][ $ok ? 'pass' : 'fail' ]++;
	if ( ! $ok ) {
		WP_CLI::warning( 'FAIL: ' . $label );
	}
}

// Redirect matching.
ast_check( '/' === assemble_redirect_target( '/our-product/' ), 'trailing slash matches' );
ast_check( '/' === assemble_redirect_target( '/our-product' ), 'no trailing slash matches' );
ast_check( '/' === assemble_redirect_target( '/Our-Product/' ), 'case-insensitive' );
ast_check( '' === assemble_redirect_target( '/our-product/extra/' ), 'deeper paths are left alone' );
ast_check( '' === assemble_redirect_target( '/' ), 'home is left alone' );
ast_check( '' === assemble_redirect_target( '/about-us/' ), 'rebuilt pages are left alone' );
ast_check( '' === assemble_redirect_target( '/benchmark/' ), 'benchmark pages are left alone (decision #10)' );
ast_check( '' === assemble_redirect_target( '/login/' ), 'Ultimate Member pages are left alone (Phase 7)' );

add_filter( 'assemble/redirects', static fn ( $map ) => $map + [ '/old-test' => '/new-test/' ] );
ast_check( '/new-test/' === assemble_redirect_target( '/old-test/' ), 'the map is filterable' );

// No redirect source is also a standard page setup creates.
foreach ( array_keys( assemble_setup_pages() ) as $path ) {
	ast_check( '' === assemble_redirect_target( '/' . $path . '/' ), "setup page /{$path}/ is not redirected" );
}

// Setup is idempotent.
assemble_setup_run();
$second  = assemble_setup_run();
$changed = array_filter( $second, static fn ( $row ) => in_array( $row['action'], [ 'created', 'adopted', 'assigned', 'failed' ], true ) );
ast_check( [] === $changed, 'a second run changes nothing' );
foreach ( array_keys( assemble_setup_pages() ) as $path ) {
	ast_check( get_page_by_path( $path ) instanceof WP_Post, "page /{$path}/ exists" );
}
ast_check( ! empty( get_nav_menu_locations()['primary'] ), 'the primary location holds a menu' );

$dry = assemble_setup_run( true );
ast_check( [] === array_filter( $dry, static fn ( $row ) => str_starts_with( $row['action'], 'would' ) ), 'a dry run after setup has nothing to do' );

$t = $GLOBALS['ast'];
$t['fail'] ? WP_CLI::error( "{$t['fail']} failed, {$t['pass']} passed." ) : WP_CLI::success( "{$t['pass']} checks passed." );
