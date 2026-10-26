<?php
/**
 * Accounts tests (Phase 7). Local only: creates and deletes test users.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/accounts-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['aat'] = [ 'pass' => 0, 'fail' => 0 ];

function aat_check( bool $ok, string $label ): void {
	$GLOBALS['aat'][ $ok ? 'pass' : 'fail' ]++;
	if ( ! $ok ) {
		WP_CLI::warning( 'FAIL: ' . $label );
	}
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$created = [];
$email   = 'phase7-test-' . wp_rand( 1000, 9999 ) . '@example.com';

// URLs.
aat_check( home_url( '/login/' ) === assemble_account_url( 'login' ), 'login URL from Site Settings' );
aat_check( home_url( '/register/' ) === assemble_account_url( 'register' ), 'register URL' );
aat_check( home_url( '/account/' ) === assemble_account_url( 'account' ), 'account URL' );
aat_check( str_contains( assemble_account_url( 'lostpassword' ), 'action=lostpassword' ), 'lost password URL' );
aat_check( str_contains( assemble_account_url( 'login', home_url( '/field-reports/x/' ) ), 'redirect_to=' ), 'login URL carries redirect_to' );
aat_check( str_starts_with( wp_registration_url(), home_url( '/register/' ) ), 'core register link lands on Create account' );
aat_check( str_starts_with( wp_lostpassword_url(), home_url( '/login/' ) ), 'core lost-password link lands on Sign in' );
aat_check( str_contains( wp_login_url( admin_url() ), 'wp-login.php' ), 'wp-admin sign-in keeps wp-login.php' );

// Validation.
$bad = assemble_account_create( [ 'email' => 'not-an-email', 'name' => '', 'company' => '' ] );
aat_check( is_wp_error( $bad ), 'invalid data is refused' );
$codes = is_wp_error( $bad ) ? (array) $bad->get_error_data() : [];
aat_check( 'email_invalid' === ( $codes['email'] ?? '' ) && 'name_empty' === ( $codes['name'] ?? '' ) && 'company_empty' === ( $codes['company'] ?? '' ), 'each field gets its error code' );
aat_check( '' !== assemble_account_message( 'email_exists' ), 'error codes have messages' );

// Follows.
$have_feed = class_exists( 'AC_Communities' ) && AC_Communities::all();
$by_area   = assemble_communities_by_area();
$mkt       = $by_area['marketing'][0] ?? '';
$tech      = $by_area['technology'][0] ?? '';
$norm      = assemble_normalise_follows( [ 'marketing', 'finance', 'bogus' ], array_filter( [ $mkt, $tech, 'not-a-community' ] ) );
aat_check( [ 'marketing', 'finance' ] === $norm['areas'], 'unknown areas are dropped, order is canonical' );
if ( $have_feed && $mkt && $tech ) {
	aat_check( [ $mkt ] === $norm['communities'], 'Communities outside a followed area, and unknown ones, are dropped' );
} else {
	WP_CLI::warning( 'No community directory locally: Community checks skipped (run sync --all).' );
}

// Create.
$user_id = assemble_account_create(
	[
		'email'       => $email,
		'name'        => '  Test   Person Example ',
		'company'     => 'Example Co',
		'job_title'   => 'Tester',
		'areas'       => [ 'marketing' ],
		'communities' => array_filter( [ $mkt ] ),
	]
);
aat_check( is_int( $user_id ) && $user_id > 0, 'an account is created' );
if ( is_int( $user_id ) ) {
	$created[] = $user_id;
	$user      = get_userdata( $user_id );
	aat_check( $user && in_array( 'subscriber', $user->roles, true ), 'role is subscriber' );
	aat_check( $user && 'Test' === $user->first_name && 'Person Example' === $user->last_name && 'Test Person Example' === $user->display_name, 'name is split and tidied' );
	$profile = assemble_account_profile( $user_id );
	aat_check( 'Example Co' === $profile['company'] && 'Tester' === $profile['job_title'], 'company and title saved' );
	aat_check( [ 'marketing' ] === $profile['areas'], 'areas saved' );

	$dupe = assemble_account_create( [ 'email' => strtoupper( $email ), 'name' => 'X', 'company' => 'Y' ] );
	aat_check( is_wp_error( $dupe ) && 'email_exists' === ( $dupe->get_error_data()['email'] ?? '' ), 'a duplicate email is refused' );

	$errors = assemble_account_save_profile( $user_id, [ 'name' => 'New Name', 'company' => 'New Co', 'job_title' => '', 'areas' => [], 'communities' => array_filter( [ $mkt ] ) ] );
	$after  = assemble_account_profile( $user_id );
	aat_check( [] === $errors && 'New Co' === $after['company'] && [] === $after['areas'] && [] === $after['communities'], 'unfollowing an area clears its Communities' );
	aat_check( [ 'name' => 'name_empty' ] === assemble_account_save_profile( $user_id, [ 'name' => '', 'company' => 'New Co' ] ), 'saving checks the name' );
}

// Audiences: the seam and the test stub.
if ( is_int( $user_id ) && class_exists( 'AC_Audience' ) ) {
	wp_set_current_user( $user_id );
	aat_check( [ 'Public' ] === AC_Audience::current_audiences(), 'a free account is Public only' );

	update_user_meta( $user_id, ASSEMBLE_META_TEST_AUDIENCES, [ 'Board Member', 'Not An Audience' ] );
	aat_check( [ 'Board Member' ] === assemble_user_audiences( $user_id ), 'unknown audience names are dropped' );
	aat_check( in_array( 'Board Member', AC_Audience::current_audiences(), true ), 'the stub reaches ac_current_audiences' );

	$host                 = $_SERVER['HTTP_HOST'] ?? null;
	$_SERVER['HTTP_HOST'] = 'theassemble.com';
	aat_check( [] === assemble_user_audiences( $user_id ), 'the stub is ignored on theassemble.com' );
	aat_check( [ 'Public' ] === AC_Audience::current_audiences(), 'production: the free account is Public only' );
	if ( null === $host ) {
		unset( $_SERVER['HTTP_HOST'] );
	} else {
		$_SERVER['HTTP_HOST'] = $host;
	}

	$seam = static fn ( array $a ): array => array_merge( $a, [ 'Council Chair' ] );
	add_filter( 'assemble/account/audiences', $seam );
	aat_check( in_array( 'Council Chair', assemble_user_audiences( $user_id ), true ), 'SSO can add audiences through the seam' );
	remove_filter( 'assemble/account/audiences', $seam );

	// The gate itself, on a stored report (the bypass must be off locally).
	$report = get_posts( [ 'post_type' => 'field_report', 'numberposts' => 1, 'post_status' => 'publish' ] );
	if ( $report && 'off' === AC_Bypass::mode() ) {
		$id      = (int) $report[0]->ID;
		$allowed = AC_Audience::report_audiences( $id );
		delete_user_meta( $user_id, ASSEMBLE_META_TEST_AUDIENCES );
		aat_check( in_array( 'Public', $allowed, true ) ? 'public' === AC_Audience::view_for( $id ) : 'denied' === AC_Audience::view_for( $id ), 'free account: denied (or public on a Public report)' );
		if ( in_array( 'Board Member', $allowed, true ) ) {
			update_user_meta( $user_id, ASSEMBLE_META_TEST_AUDIENCES, [ 'Board Member' ] );
			aat_check( 'standard' === AC_Audience::view_for( $id ), 'stubbed Board Member: standard view' );
		}
		if ( in_array( 'Council Chair', $allowed, true ) ) {
			update_user_meta( $user_id, ASSEMBLE_META_TEST_AUDIENCES, [ 'Council Chair' ] );
			aat_check( 'council' === AC_Audience::view_for( $id ), 'stubbed Council Chair: council view' );
		}
	} else {
		WP_CLI::warning( 'No report, or the bypass is not off: gate checks skipped.' );
	}
	wp_set_current_user( 0 );
}

// Mail guard.
$saved = get_option( 'assemble_mail_allowlist', null );
update_option( 'assemble_mail_allowlist', "tester@example.com\nother@example.org" );
aat_check( assemble_mail_guard_active(), 'the guard is on off production' );
aat_check( assemble_mail_allowed( 'tester@example.com' ), 'an allowlisted address is allowed' );
aat_check( assemble_mail_allowed( 'Tester+phase7@Example.com' ), 'plus-addresses of it are allowed' );
aat_check( assemble_mail_allowed( 'Test Person <tester@example.com>' ), 'named addresses are parsed' );
aat_check( ! assemble_mail_allowed( 'someone@example.com' ), 'other addresses are not' );
$sent = null;
$spy  = static function ( $short, $atts ) use ( &$sent ) {
	if ( null !== $short ) {
		return $short;
	}
	$sent = $atts['to'];
	return true;
};
add_filter( 'pre_wp_mail', $spy, 20, 2 );
aat_check( false === wp_mail( 'someone@example.com', 'Held', 'Body' ), 'mail to a stranger is dropped' );
aat_check( null === $sent, 'nothing reaches the mailer' );
$held = (array) get_option( ASSEMBLE_MAIL_HELD_OPTION, [] );
aat_check( 'someone@example.com' === ( $held[0]['to'] ?? '' ), 'the dropped mail is noted' );
wp_mail( [ 'tester+x@example.com', 'someone@example.com' ], 'Mixed', 'Body', [ 'Cc: cc@example.net' ] );
aat_check( [ 'tester+x@example.com' ] === $sent, 'mixed recipients: only the allowed one is sent, without Cc' );
remove_filter( 'pre_wp_mail', $spy, 20 );
null === $saved ? delete_option( 'assemble_mail_allowlist' ) : update_option( 'assemble_mail_allowlist', $saved );

// Clean up.
foreach ( $created as $id ) {
	wp_delete_user( $id );
}

$t = $GLOBALS['aat'];
$t['fail'] ? WP_CLI::error( "{$t['fail']} failed, {$t['pass']} passed." ) : WP_CLI::success( "{$t['pass']} checks passed." );
