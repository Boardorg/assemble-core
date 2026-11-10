<?php
/**
 * Members-only tests. Local only: creates and deletes two fixture reports, a
 * playbook and a test user.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/members-only-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['amo'] = [ 'pass' => 0, 'fail' => 0 ];

function amo_check( bool $ok, string $label ): void {
	$GLOBALS['amo'][ $ok ? 'pass' : 'fail' ]++;
	if ( ! $ok ) {
		WP_CLI::warning( 'FAIL: ' . $label );
	}
}

/** IDs a front-end query of these args returns. */
function amo_ids( array $args ): array {
	return array_map( 'intval', wp_list_pluck( ( new WP_Query( array_merge( [ 'post_status' => 'publish', 'posts_per_page' => -1 ], $args ) ) )->posts, 'ID' ) );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$community = 'members-only-test';
$make      = static function ( string $type, string $title, array $audiences ) use ( $community ): int {
	$id = wp_insert_post( [ 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ] );
	update_post_meta( $id, ASSEMBLE_META_AVAILABLE_TO, $audiences );
	if ( 'field_report' === $type ) {
		wp_set_object_terms( $id, $community, 'field_report_community' );
	}
	return (int) $id;
};

$public  = $make( 'field_report', 'AMO public fixture', [ 'Public', 'Board Member' ] );
$members = $make( 'field_report', 'AMO members fixture', [ 'Board Member' ] );
$none    = $make( 'field_report', 'AMO no-audience fixture', [] );
$play    = $make( 'playbook', 'AMO members playbook', [ 'Board Member' ] );

// The rule is off under WP-CLI, so the sync can see everything.
amo_check( ! assemble_members_only_applies(), 'off under WP-CLI' );
amo_check( in_array( $members, amo_ids( [ 'post_type' => 'field_report' ] ), true ), 'CLI queries still find members-only posts (sync)' );

add_filter( 'assemble/members_only/applies', '__return_true' );
wp_set_current_user( 0 );

// The audience map.
$hidden = assemble_hidden_piece_ids();
amo_check( ! in_array( $public, $hidden, true ), 'a Public piece is not hidden' );
amo_check( in_array( $members, $hidden, true ), 'a members-only report is hidden' );
amo_check( in_array( $play, $hidden, true ), 'a members-only playbook is hidden' );
amo_check( in_array( $none, $hidden, true ), 'a piece with no audiences fails closed' );

// Lists, logged out.
$list = amo_ids( [ 'post_type' => 'field_report' ] );
amo_check( in_array( $public, $list, true ) && ! in_array( $members, $list, true ), 'archive list: Public only' );
$tax = amo_ids( [ 'post_type' => 'field_report', 'tax_query' => [ [ 'taxonomy' => 'field_report_community', 'field' => 'slug', 'terms' => $community ] ] ] );
amo_check( [ $public ] === $tax, 'Community list: Public only' );
amo_check( ! in_array( $play, amo_ids( [ 'post_type' => 'playbook' ] ), true ), 'playbook list: no members-only' );
$search = amo_ids( [ 's' => 'AMO', 'post_type' => 'any' ] );
amo_check( in_array( $public, $search, true ) && ! array_intersect( [ $members, $none, $play ], $search ), 'search: Public only' );
amo_check( [] === amo_ids( [ 'p' => $members, 'post_type' => 'field_report' ] ), 'own URL query finds nothing (404)' );
amo_check( [] === amo_ids( [ 'post__in' => [ $members, $play ], 'post_type' => 'any' ] ), 'post__in cannot pull one in' );
$gp = array_map( 'intval', wp_list_pluck( get_posts( [ 'post_type' => 'field_report', 'numberposts' => -1 ] ), 'ID' ) );
amo_check( ! in_array( $members, $gp, true ), 'get_posts (the feed lists) is covered' );
if ( class_exists( 'AC_Query' ) ) {
	$feed = array_map( 'intval', wp_list_pluck( AC_Query::in_community( $community, 10 ), 'ID' ) );
	amo_check( [ $public ] === $feed, 'AC_Query::in_community: Public only' );
}
amo_check( 0 === url_to_postid( get_permalink( $members ) ), 'oEmbed lookup (url_to_postid) finds nothing' );

// A free account is still Public only.
$user = wp_insert_user( [ 'user_login' => 'amo-' . wp_rand( 1000, 9999 ) . '@example.com', 'user_email' => 'amo-' . wp_rand( 1000, 9999 ) . '@example.com', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
wp_set_current_user( $user );
amo_check( in_array( $members, assemble_hidden_piece_ids(), true ), 'a free account cannot see members-only' );

// A member (the test-audience stub) sees what their audience allows.
update_user_meta( $user, 'assemble_test_audiences', [ 'Board Member' ] );
$member_hidden = assemble_hidden_piece_ids();
amo_check( ! in_array( $members, $member_hidden, true ) && ! in_array( $play, $member_hidden, true ), 'a Board Member sees Board Member pieces' );
amo_check( in_array( $none, $member_hidden, true ), 'a no-audience piece stays hidden from members' );

// Admins see everything.
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) $admins[0] );
amo_check( [] === array_intersect( [ $members, $play ], assemble_hidden_piece_ids() ), 'admins see members-only pieces' );

// Sitemaps and Yoast's head endpoint use the Public view whoever asks.
$excluded = apply_filters( 'wpseo_exclude_from_sitemap_by_post_ids', [] );
amo_check( in_array( $members, $excluded, true ) && in_array( $play, $excluded, true ) && ! in_array( $public, $excluded, true ), 'sitemap excludes members-only' );
wp_set_current_user( 0 );
$head = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/yoast/v1/get_head' ) ); // warm the server
$req  = new WP_REST_Request( 'GET', '/yoast/v1/get_head' );
$req->set_param( 'url', get_permalink( $members ) );
$res  = apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $req );
amo_check( is_wp_error( $res ) && 404 === ( $res->get_error_data()['status'] ?? 0 ), 'Yoast get_head 404s for a members-only URL' );
$req->set_param( 'url', get_permalink( $public ) );
amo_check( null === apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $req ), 'Yoast get_head untouched for a Public URL' );

// Clean up.
remove_filter( 'assemble/members_only/applies', '__return_true' );
foreach ( [ $public, $members, $none, $play ] as $id ) {
	wp_delete_post( $id, true );
}
$term = get_term_by( 'slug', $community, 'field_report_community' );
if ( $term ) {
	wp_delete_term( $term->term_id, 'field_report_community' );
}
wp_delete_user( $user );

WP_CLI::log( sprintf( 'members-only: %d passed, %d failed', $GLOBALS['amo']['pass'], $GLOBALS['amo']['fail'] ) );
