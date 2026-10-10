<?php
/**
 * Summits importer tests, offline: a fake executiveplatforms.com serves small
 * pages shaped like EP's. Checks parsing (list, home hero, date ranges, venue
 * lines) and that a bad refresh never replaces the stored copy.
 *
 * Local only. Restores the stored summits afterwards.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/summits-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['as_t'] = [ 'pass' => 0, 'fail' => 0, 'pages' => [] ];

function as_check( bool $ok, string $label ): void {
	$GLOBALS['as_t'][ $ok ? 'pass' : 'fail' ]++;
	if ( ! $ok ) {
		WP_CLI::warning( 'FAIL: ' . $label );
	}
}

function as_card( string $code, string $md, string $place, string $title ): string {
	return '<div class="summit"><a href="https://www.executiveplatforms.com/' . $code . '/"><div class="summit-date">' . $md . '</div><div class="summit-details"><div class="summit-image"><img class="summit-logo perfmatters-lazy" src="data:image/svg+xml,x" data-src="https://www.executiveplatforms.com/wp-content/uploads/' . $code . '.png" /></div><div class="summit-venue">' . $place . '</div></div><div class="summit-title"><h3>' . $title . '</h3></div></a><div class="summit-register-button"><a href="https://www.executiveplatforms.com/' . $code . '/register/">Register Now</a></div></div>';
}

function as_home( string $range, string $venue ): string {
	return '<h1 class="elementor-heading-title">Name</h1><h2 class="elementor-heading-title">' . $range . '</h2><h3 class="elementor-heading-title">' . $venue . '</h3>'
		. '<div class="elementor-counter"><div class="elementor-counter-title">Attendees</div><span class="elementor-counter-number" data-to-value="500"></span><span class="elementor-counter-number-suffix">+</span></div>';
}

$as_list = '<div class="summits-wrapper"><div class="summit-wrapper"><div class="summit-year"><h2>2026</h2></div><div class="summit-list">'
	. as_card( 'aaa', '11.16', 'Palm Springs, CA', 'Test Summit &#8211; Fall Edition' )
	. as_card( 'bbb', '12.30', 'Austin, TX', 'Cross Month Summit' )
	. '</div></div><div class="summit-wrapper"><div class="summit-year"><h2>2027</h2></div><div class="summit-list">'
	. as_card( 'ccc', '1.19', 'Alpharetta, GA', 'Next Year Summit' )
	. '</div></div></div>';

$GLOBALS['as_t']['pages'] = [
	'https://www.executiveplatforms.com/summits/' => $as_list,
	'https://www.executiveplatforms.com/aaa/'     => as_home( 'November 16 - November 18, 2026', 'Grand Hotel, Palm Springs, CA' ),
	'https://www.executiveplatforms.com/bbb/'     => as_home( 'December 30, 2026 - January 1, 2027', 'Lodge | Austin, TX' ),
	'https://www.executiveplatforms.com/ccc/'     => as_home( 'January 19–21, 2027', "The Hotel at Avalon, Alpharetta (Atlanta), GA\u{200B}" ),
];

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! str_contains( $url, 'executiveplatforms.com' ) ) {
			return $pre;
		}
		$body = $GLOBALS['as_t']['pages'][ $url ] ?? null;
		return [
			'headers'  => [],
			'body'     => (string) $body,
			'response' => [ 'code' => null === $body ? 404 : 200, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

$as_saved        = get_option( ASSEMBLE_SUMMITS_OPTION, null );
$as_saved_status = get_option( ASSEMBLE_SUMMITS_STATUS_OPTION, null );

try {
	// 1. Date ranges.
	as_check( [ 'start' => '2027-04-05', 'end' => '2027-04-07' ] === assemble_summits_parse_range( 'April 5-7, 2027' ), 'range: same month' );
	as_check( [ 'start' => '2026-09-30', 'end' => '2026-10-02' ] === assemble_summits_parse_range( 'September 30 - October 2, 2026' ), 'range: cross month' );
	as_check( [ 'start' => '2026-11-16', 'end' => '2026-11-18' ] === assemble_summits_parse_range( 'November 16 - November 18, 2026' ), 'range: repeated month' );
	as_check( [ 'start' => '2027-01-19', 'end' => '2027-01-21' ] === assemble_summits_parse_range( 'January 19–21, 2027' ), 'range: en dash' );
	as_check( [ 'start' => '2027-03-02', 'end' => '2027-03-02' ] === assemble_summits_parse_range( 'March 2, 2027' ), 'range: single day' );
	as_check( null === assemble_summits_parse_range( 'Register Now' ), 'range: not a date' );

	// 2. Venue lines.
	as_check( 'Omni Barton Creek' === assemble_summits_venue_only( 'Omni Barton Creek, Austin TX', 'Austin', 'TX' ), 'venue: no comma before state' );
	as_check( 'Hotel at Avalon' === assemble_summits_venue_only( 'Hotel at Avalon | Alpharetta (Atlanta), GA', 'Alpharetta', 'GA' ), 'venue: pipe' );
	as_check( 'Hotel at Avalon' === assemble_summits_venue_only( 'Hotel at Avalon, Alpharetta (Atlanta), GA', 'Alpharetta', 'GA' ), 'venue: parenthetical' );
	as_check( 'Hilton Bayfront' === assemble_summits_venue_only( 'Hilton Bayfront', 'San Diego', 'CA' ), 'venue: name only' );

	// 3. A full refresh against the fake EP.
	delete_option( ASSEMBLE_SUMMITS_OPTION );
	$r  = assemble_summits_refresh();
	$by = array_column( $r['summits'], null, 'code' );
	as_check( $r['ok'] && $r['stored'] && 3 === count( $r['summits'] ), 'refresh: 3 summits stored' );
	as_check( 'fall' === ( $by['aaa']['season'] ?? '' ) && 'Test Summit – Fall Edition' === $by['aaa']['title'], 'refresh: season and decoded title' );
	as_check( '2026-12-30' === $by['bbb']['start'] && '2027-01-01' === $by['bbb']['end'] && 'Lodge' === $by['bbb']['venue'], 'refresh: year-crossing range, piped venue' );
	as_check( 'The Hotel at Avalon' === $by['ccc']['venue'], 'refresh: zero-width character and parenthetical removed' );
	as_check( 'https://www.executiveplatforms.com/wp-content/uploads/aaa.png' === $by['aaa']['logo_url'], 'refresh: lazy-loaded logo URL' );
	as_check( 'https://www.executiveplatforms.com/aaa/register/' === $by['aaa']['register_url'], 'refresh: register URL' );
	as_check( [ 'Attendees' => '500+' ] === $by['aaa']['stats'], 'refresh: stats counter' );

	// 4. Upcoming only, soonest first.
	$codes = array_column( assemble_summits(), 'code' );
	as_check( [ 'aaa', 'bbb', 'ccc' ] === $codes || [ 'bbb', 'ccc' ] === $codes || [ 'ccc' ] === $codes, 'reader: sorted, past ones dropped' );
	as_check( 3 === count( assemble_summits( false ) ), 'reader: --all keeps every summit' );

	// 5. A summit home page that fails keeps its previous details.
	$GLOBALS['as_t']['pages']['https://www.executiveplatforms.com/aaa/'] = '<p>Maintenance</p>';
	$r  = assemble_summits_refresh();
	$by = array_column( $r['summits'], null, 'code' );
	as_check( $r['ok'] && '2026-11-18' === $by['aaa']['end'] && 'Grand Hotel' === $by['aaa']['venue'], 'home page broken: previous details kept' );
	as_check( (bool) array_filter( $r['warnings'], static fn( $w ) => str_starts_with( $w, 'aaa:' ) ), 'home page broken: warned' );

	// 6. A list page that changed layout, or fails, never replaces the stored copy.
	$before = get_option( ASSEMBLE_SUMMITS_OPTION );
	$GLOBALS['as_t']['pages']['https://www.executiveplatforms.com/summits/'] = '<div class="new-layout">Summits</div>';
	$r = assemble_summits_refresh();
	as_check( ! $r['ok'] && ! $r['stored'] && get_option( ASSEMBLE_SUMMITS_OPTION ) === $before, 'list layout changed: stored copy kept' );

	unset( $GLOBALS['as_t']['pages']['https://www.executiveplatforms.com/summits/'] );
	$r = assemble_summits_refresh();
	as_check( ! $r['ok'] && get_option( ASSEMBLE_SUMMITS_OPTION ) === $before, 'list unreachable: stored copy kept' );

	// 7. Dry run stores nothing.
	$GLOBALS['as_t']['pages']['https://www.executiveplatforms.com/summits/'] = $as_list;
	delete_option( ASSEMBLE_SUMMITS_OPTION );
	$r = assemble_summits_refresh( true );
	as_check( $r['ok'] && ! $r['stored'] && false === get_option( ASSEMBLE_SUMMITS_OPTION ), 'dry run: nothing stored' );

	// 8. Extras merge in without overriding EP's facts; an unmapped summit falls back.
	assemble_summits_refresh();
	$as_extras = static fn() => [
		'aaa' => [ 'area' => 'finance', 'area_label' => 'Finance', 'communities' => [ '*' ], 'blurb' => 'One line.', 'logo' => 'summits/aaa.png', 'title' => 'Not EP' ],
	];
	add_filter( 'assemble/summit_extras', $as_extras );
	$by = array_column( assemble_summits_with_extras( false ), null, 'code' );
	remove_filter( 'assemble/summit_extras', $as_extras );
	as_check( 'finance' === $by['aaa']['area'] && 'One line.' === $by['aaa']['blurb'] && [ '*' ] === $by['aaa']['communities'], 'extras: merged' );
	as_check( 'Test Summit – Fall Edition' === $by['aaa']['title'], 'extras: never override an EP fact' );
	as_check( content_url( 'assets/summits/aaa.png' ) === $by['aaa']['logo'], 'extras: logo from the shared image library' );
	as_check( '' === $by['bbb']['area'] && '' === $by['bbb']['blurb'] && [] === $by['bbb']['communities'] && '' === $by['bbb']['logo'], 'extras: unmapped summit falls back' );
	as_check( isset( assemble_summit_extras()['nasrsf'], assemble_summit_extras()['namesf'] ) && 16 === count( assemble_summit_extras() ), 'extras: all 16 EP codes mapped' );

	// 9. The outcome is recorded for the admin notice.
	assemble_summits_refresh_and_record();
	as_check( '' === assemble_summits_problem(), 'status: a good refresh raises no notice' );
	unset( $GLOBALS['as_t']['pages']['https://www.executiveplatforms.com/summits/'] );
	assemble_summits_refresh_and_record();
	as_check( str_contains( assemble_summits_problem(), 'failed' ), 'status: a failed refresh raises the notice' );
	as_check( (bool) wp_next_scheduled( ASSEMBLE_SUMMITS_CRON ), 'status: the daily refresh is scheduled' );
} finally {
	foreach ( [ ASSEMBLE_SUMMITS_OPTION => $as_saved, ASSEMBLE_SUMMITS_STATUS_OPTION => $as_saved_status ] as $as_option => $as_value ) {
		if ( null === $as_value ) {
			delete_option( $as_option );
		} else {
			update_option( $as_option, $as_value, false );
		}
	}
}

if ( $GLOBALS['as_t']['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $GLOBALS['as_t']['pass'], $GLOBALS['as_t']['fail'] ) );
}
WP_CLI::success( sprintf( 'Summits importer: %d checks passed.', $GLOBALS['as_t']['pass'] ) );
