<?php
/**
 * For You tests. Local only: creates and deletes test users and fixtures.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/for-you-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['afy'] = [ 'pass' => 0, 'fail' => 0 ];

function afy_check( bool $ok, string $label ): void {
	$GLOBALS['afy'][ $ok ? 'pass' : 'fail' ]++;
	if ( ! $ok ) {
		WP_CLI::warning( 'FAIL: ' . $label );
	}
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$made  = [];
$users = [];
$user  = static function ( array $areas, array $communities ) use ( &$users ): int {
	$email = 'afy-' . wp_rand( 10000, 99999 ) . '@example.com';
	$id    = wp_insert_user( [ 'user_login' => $email, 'user_email' => $email, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
	$f     = assemble_normalise_follows( $areas, $communities );
	update_user_meta( $id, ASSEMBLE_META_FOLLOW_AREAS, $f['areas'] );
	update_user_meta( $id, ASSEMBLE_META_FOLLOW_GROUPS, $f['communities'] );
	$users[] = $id;
	return (int) $id;
};
$piece = static function ( string $type, array $audiences, string $community = '', string $area = '' ) use ( &$made ): int {
	$id = wp_insert_post( [ 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'AFY fixture ' . count( $made ), 'post_date' => wp_date( 'Y-m-d H:i:s', time() - 600 + count( $made ) ) ] );
	update_post_meta( $id, ASSEMBLE_META_AVAILABLE_TO, $audiences );
	if ( $community ) {
		wp_set_object_terms( $id, $community, 'field_report_community' );
	}
	if ( $area ) {
		add_post_meta( $id, '_ac_practice_area', $area );
	}
	$made[] = (int) $id;
	return (int) $id;
};
$ids = static fn ( array $feed ): array => array_map( static fn ( $i ) => (int) $i['post']->ID, $feed['items'] );

// The rule applies as on a front-end request.
add_filter( 'assemble/members_only/applies', '__return_true' );

// URLs and pages.
afy_check( home_url( '/for-you/' ) === assemble_account_url( 'for_you' ), 'For You URL from Site Settings' );
afy_check( isset( assemble_setup_account_pages()['for-you'] ), 'setup creates /for-you/' );

// Fixtures: newest first, so they top every feed.
$ld_public  = $piece( 'field_report', [ 'Public' ], 'learning-development' );
$ld_members = $piece( 'field_report', [ 'Board Member' ], 'learning-development' );
$dei_public = $piece( 'field_report', [ 'Public' ], 'dei' );
$mk_play    = $piece( 'playbook', [ 'Public' ], '', 'marketing' );
$mk_members = $piece( 'playbook', [ 'Board Member' ], '', 'marketing' );
$fin_play   = $piece( 'playbook', [ 'Public' ], '', 'finance' );

// One Community.
$one = $user( [ 'human-resources' ], [ 'learning-development' ] );
wp_set_current_user( $one );
$feed = assemble_for_you( $one );
afy_check( [ 'c:learning-development' ] === array_keys( $feed['chips'] ), 'one Community: one chip' );
afy_check( in_array( $ld_public, $ids( $feed ), true ), 'one Community: its Public report is listed' );
afy_check( ! in_array( $ld_members, $ids( $feed ), true ), 'one Community: its members-only report is not' );
afy_check( ! in_array( $dei_public, $ids( $feed ), true ), 'one Community: an unfollowed Community is not' );
afy_check( ! in_array( $mk_play, $ids( $feed ), true ), 'one Community: an unfollowed area’s playbook is not' );
afy_check( $ld_public === $ids( $feed )[0] && 'learning-development' === $feed['items'][0]['reason']['key'], 'newest first, with its reason' );
afy_check( count( $feed['items'] ) <= ASSEMBLE_FOR_YOU_PER_PAGE, 'paged' );
afy_check( '' === assemble_for_you( $one, [ 'for' => 'c:dei' ] )['current'], 'a forged chip is ignored' );
afy_check( $ids( $feed ) === $ids( assemble_for_you( $one, [ 'for' => 'c:dei' ] ) ), 'a forged chip shows All for you' );

// A whole area: every Community chip, plus its playbooks.
$area = $user( [ 'marketing' ], array_column( array_map( static fn ( $s ) => [ 's' => $s ], assemble_communities_by_area()['marketing'] ), 's' ) );
wp_set_current_user( $area );
$feed = assemble_for_you( $area );
afy_check( count( $feed['chips'] ) === count( assemble_communities_by_area()['marketing'] ), 'whole area: a chip per Community' );
afy_check( in_array( $mk_play, $ids( $feed ), true ), 'whole area: its Public playbook is listed' );
afy_check( ! in_array( $mk_members, $ids( $feed ), true ), 'whole area: its members-only playbook is not' );
$play_item = current( array_filter( $feed['items'], static fn ( $i ) => $mk_play === (int) $i['post']->ID ) );
afy_check( $play_item && 'area' === $play_item['reason']['type'] && 'marketing' === $play_item['reason']['key'], 'a playbook’s reason is its area' );
$narrow = assemble_for_you( $area, [ 'for' => 'c:aeo' ] );
afy_check( 'c:aeo' === $narrow['current'] && ! in_array( $mk_play, $ids( $narrow ), true ), 'a Community chip narrows to its reports' );
foreach ( $narrow['items'] as $item ) {
	afy_check( has_term( 'aeo', 'field_report_community', $item['post'] ), 'narrowed item is in AEO: ' . $item['post']->ID );
}

// An area with no Communities.
$fin = $user( [ 'finance' ], [] );
wp_set_current_user( $fin );
$feed = assemble_for_you( $fin );
afy_check( [ 'a:finance' ] === array_keys( $feed['chips'] ), 'Finance: an area chip' );
afy_check( [ $fin_play ] === $ids( $feed ), 'Finance: only its playbook' );

// Nothing followed.
$none = $user( [], [] );
wp_set_current_user( $none );
$feed = assemble_for_you( $none );
afy_check( [] === $feed['chips'] && [] === $feed['items'] && ! $feed['follows_any'], 'follows nothing: empty' );

// Landing and robots.
afy_check( str_contains( (string) file_get_contents( WP_PLUGIN_DIR . '/assemble-core/inc/account-forms.php' ), "add_query_arg( 'done', 'created', assemble_account_url( 'for_you' ) )" ), 'Create account lands on For You' );

// Clean up.
remove_filter( 'assemble/members_only/applies', '__return_true' );
foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $users as $id ) {
	wp_delete_user( $id );
}

WP_CLI::log( sprintf( 'for-you: %d passed, %d failed', $GLOBALS['afy']['pass'], $GLOBALS['afy']['fail'] ) );
