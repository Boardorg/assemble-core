<?php
/**
 * Summits importer tests, offline: a fake EP summit API answers in the shape of
 * the live one (EP-SUMMITS-API-SPEC.md in the hub). Checks the mapping (nulls,
 * dates, URLs), the ETag round trip, that a bad answer never replaces the stored
 * copy, the Assemble-only extras and the admin notice.
 *
 * Local only. Restores the stored summits afterwards.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/summits-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['as_t'] = [ 'pass' => 0, 'fail' => 0, 'answer' => null, 'sent' => [] ];

function as_check( bool $ok, string $label ): void {
	$GLOBALS['as_t'][ $ok ? 'pass' : 'fail' ]++;
	if ( ! $ok ) {
		WP_CLI::warning( 'FAIL: ' . $label );
	}
}

/** One index entry, as the live API sends it. $edition null means no current edition. */
function as_summit( string $code, ?array $edition, ?string $season = null ): array {
	return [
		'id'              => $code,
		'code'            => $code,
		'short_name'      => strtoupper( $code ),
		'title'           => 'Summit ' . strtoupper( $code ) . ' - Fall Edition',
		'season'          => $season,
		'url'             => "https://www.executiveplatforms.com/$code/",
		'api_url'         => "https://www.executiveplatforms.com/$code/wp-json/ep/v1/summit?v=1",
		'logo_white'      => [ 'url' => "https://www.executiveplatforms.com/wp-content/uploads/$code-white.png", 'width' => 100, 'height' => 20 ],
		'current_edition' => $edition,
		'editions'        => [],
		'modified'        => '2026-10-14T13:23:20Z',
		'stale'           => false,
	];
}

function as_edition( string $code, string $start, ?string $end, array $more = [] ): array {
	return array_merge(
		[
			'id'               => "$code-2027",
			'year'             => 2027,
			'start_date'       => $start,
			'end_date'         => $end,
			'status'           => 'upcoming',
			'timezone'         => 'America/Chicago',
			'venue'            => [ 'name' => 'Grand Hotel', 'city' => 'Austin', 'state' => 'TX' ],
			'agenda_available' => true,
			'agenda_url'       => "https://www.executiveplatforms.com/$code/event/2027-agenda/",
			'register_url'     => "https://www.executiveplatforms.com/$code/register/",
		],
		$more
	);
}

/** Make the fake API answer with $code and $body (an array is JSON-encoded), or fail to connect when $code is 0. */
function as_answer( int $code, $body = '', string $etag = 'W/"v1"' ): void {
	$GLOBALS['as_t']['answer'] = [ $code, is_array( $body ) ? wp_json_encode( $body ) : (string) $body, $etag ];
}

$as_index = [
	'generated_at' => '2026-10-15T19:42:11Z',
	'summits'      => [
		as_summit( 'aaa', as_edition( 'aaa', '2026-11-16', '2026-11-18' ), 'fall' ),
		as_summit( 'bbb', as_edition( 'bbb', '2026-12-30', null, [ 'venue' => null, 'agenda_available' => false, 'timezone' => null ] ) ),
		as_summit( 'ccc', as_edition( 'ccc', '2027-01-19', '2027-01-21', [ 'register_url' => 'http://insecure.example/' ] ) ),
		as_summit( 'ddd', null ),
	],
];

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ASSEMBLE_SUMMITS_API !== $url ) {
			return $pre;
		}

		$GLOBALS['as_t']['sent'] = (array) ( $args['headers'] ?? [] );
		[ $code, $body, $etag ] = $GLOBALS['as_t']['answer'];

		if ( 0 === $code ) {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		}

		$matches = 200 === $code && $etag === ( $GLOBALS['as_t']['sent']['If-None-Match'] ?? '' );

		return [
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( [ 'etag' => $etag ] ),
			'body'     => $matches ? '' : $body,
			'response' => [ 'code' => $matches ? 304 : $code, 'message' => '' ],
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
	// 1. Mapping one entry into the stored shape.
	$m = assemble_summits_from_api( as_summit( 'aaa', as_edition( 'aaa', '2026-11-16', '2026-11-18' ), 'fall' ) );
	as_check( 'aaa' === $m['code'] && 'fall' === $m['season'] && '2026-11-16' === $m['start'] && '2026-11-18' === $m['end'], 'map: code, season, dates' );
	as_check( 'Austin' === $m['city'] && 'TX' === $m['state'] && 'Grand Hotel' === $m['venue'], 'map: venue' );
	as_check( 'https://www.executiveplatforms.com/aaa/register/' === $m['register_url'] && 'https://www.executiveplatforms.com/aaa/' === $m['url'], 'map: links' );
	as_check( 'America/Chicago' === $m['timezone'] && 'upcoming' === $m['status'] && 2027 === $m['year'] && str_ends_with( $m['agenda_url'], '/2027-agenda/' ), 'map: timezone, status, year, agenda' );
	as_check( str_ends_with( $m['logo_url'], 'aaa-white.png' ) && '' === $m['date_text'] && [] === $m['stats'], 'map: white logo; no date text or stats' );

	$m = assemble_summits_from_api( as_summit( 'bbb', as_edition( 'bbb', '2026-12-30', null, [ 'venue' => null, 'agenda_available' => false, 'timezone' => null ] ) ) );
	as_check( '' === $m['season'] && '2026-12-30' === $m['end'], 'map: null season is empty; null end is the start' );
	as_check( '' === $m['city'] && '' === $m['venue'] && '' === $m['timezone'] && '' === $m['agenda_url'], 'map: null venue and timezone are empty; no agenda link before one exists' );
	as_check( '' === assemble_summits_from_api( as_summit( 'ccc', as_edition( 'ccc', '2027-01-19', '2027-01-21', [ 'register_url' => 'http://insecure.example/' ] ) ) )['register_url'], 'map: a non-https link is dropped' );
	as_check( null === assemble_summits_from_api( as_summit( 'ddd', null ) ), 'map: no current edition is left out' );
	as_check( null === assemble_summits_from_api( as_summit( 'eee', as_edition( 'eee', '05/04/2027', null ) ) ), 'map: a date not in Y-m-d is left out' );
	as_check( '2027-03-02' === assemble_summits_from_api( as_summit( 'fff', as_edition( 'fff', '2027-03-02', '2027-03-01' ) ) )['end'], 'map: an end before the start becomes the start' );

	as_check( 'North American HR Executive Summit – Fall Edition' === assemble_summits_title( 'The Fall Edition of the North American HR Executive Summit', 'North American HR Executive Summit - Fall Edition' ), 'title: site name when it names the edition' );
	as_check( 'North American Finance Executive Summit – Spring Edition' === assemble_summits_title( 'North American Finance Executive Summit - Spring Edition', 'North American Finance Executive Summit' ), 'title: title when it names the edition' );
	as_check( 'Food World Summit: Safety, Quality & Manufacturing' === assemble_summits_title( 'Food World Summit: Safety, Quality & Manufacturing', 'Food World Summit' ), 'title: title keeps a subtitle' );
	as_check( 'Only Site Name' === assemble_summits_title( '', 'Only Site Name' ), 'title: site name when the title is empty' );

	// 2. A full refresh.
	delete_option( ASSEMBLE_SUMMITS_OPTION );
	as_answer( 200, $as_index );
	$r = assemble_summits_refresh();
	as_check( $r['ok'] && $r['stored'] && 3 === count( $r['summits'] ), 'refresh: 3 usable summits stored' );
	as_check( (bool) array_filter( $r['warnings'], static fn( $w ) => str_starts_with( $w, 'ddd:' ) ), 'refresh: the summit with no edition is warned about' );
	as_check( 'W/"v1"' === get_option( ASSEMBLE_SUMMITS_OPTION )['etag'] && ASSEMBLE_SUMMITS_API === get_option( ASSEMBLE_SUMMITS_OPTION )['source'], 'refresh: ETag and source stored' );
	as_check( ! isset( $GLOBALS['as_t']['sent']['If-None-Match'] ), 'refresh: no ETag sent when none is stored' );

	// 3. Unchanged: the stored ETag is sent, the 304 keeps the list and counts as a refresh.
	$before = get_option( ASSEMBLE_SUMMITS_OPTION );
	update_option( ASSEMBLE_SUMMITS_OPTION, array_merge( $before, [ 'fetched_at' => '2026-01-01 00:00' ] ), false );
	$r = assemble_summits_refresh();
	as_check( 'W/"v1"' === ( $GLOBALS['as_t']['sent']['If-None-Match'] ?? '' ), '304: stored ETag sent' );
	as_check( $r['ok'] && $r['unchanged'] && 3 === count( $r['summits'] ), '304: unchanged, list kept' );
	as_check( get_option( ASSEMBLE_SUMMITS_OPTION )['summits'] === $before['summits'] && '2026-01-01 00:00' !== assemble_summits_refreshed_at(), '304: refresh time updated, summits untouched' );

	// 4. Upcoming only, soonest first.
	$codes = array_column( assemble_summits(), 'code' );
	as_check( [ 'aaa', 'bbb', 'ccc' ] === $codes || [ 'bbb', 'ccc' ] === $codes || [ 'ccc' ] === $codes, 'reader: sorted, past ones dropped' );
	as_check( 3 === count( assemble_summits( false ) ), 'reader: --all keeps every summit' );

	// 5. Bad answers never replace the stored copy.
	$before = get_option( ASSEMBLE_SUMMITS_OPTION );
	foreach (
		[
			'unreachable'        => [ 0, '' ],
			'server error'       => [ 500, '<html>Error</html>' ],
			'not JSON'           => [ 200, '<html>Maintenance</html>' ],
			'no summits key'     => [ 200, [ 'code' => 'rest_no_route' ] ],
			'empty list'         => [ 200, [ 'summits' => [] ] ],
			'much shorter list'  => [ 200, [ 'summits' => [ $as_index['summits'][0] ] ] ],
			'only unusable ones' => [ 200, [ 'summits' => [ as_summit( 'ddd', null ), as_summit( 'eee', null ), as_summit( 'fff', null ) ] ] ],
		] as $as_label => [ $as_code, $as_body ]
	) {
		as_answer( $as_code, $as_body, 'W/"v2"' );
		$r = assemble_summits_refresh();
		as_check( ! $r['ok'] && ! $r['stored'] && '' !== $r['error'] && get_option( ASSEMBLE_SUMMITS_OPTION ) === $before, "bad answer kept the stored copy: $as_label" );
	}

	// 6. A 304 with nothing stored can't stand in for a list.
	delete_option( ASSEMBLE_SUMMITS_OPTION );
	as_answer( 304, '' );
	as_check( ! assemble_summits_refresh()['ok'], '304 with nothing stored: not ok' );

	// 7. Dry run stores nothing and sends no ETag.
	as_answer( 200, $as_index );
	assemble_summits_refresh();
	$before = get_option( ASSEMBLE_SUMMITS_OPTION );
	delete_option( ASSEMBLE_SUMMITS_OPTION );
	$r = assemble_summits_refresh( true );
	as_check( $r['ok'] && ! $r['stored'] && false === get_option( ASSEMBLE_SUMMITS_OPTION ), 'dry run: nothing stored' );
	update_option( ASSEMBLE_SUMMITS_OPTION, $before, false );
	assemble_summits_refresh( true );
	as_check( ! isset( $GLOBALS['as_t']['sent']['If-None-Match'] ), 'dry run: no ETag sent, so the full list is shown' );

	// 8. Extras merge in without overriding EP's facts; an unmapped summit falls back.
	$as_extras = static fn() => [
		'aaa' => [ 'area' => 'finance', 'area_label' => 'Finance', 'communities' => [ '*' ], 'blurb' => 'One line.', 'logo' => 'summits/aaa.png', 'title' => 'Not EP' ],
	];
	add_filter( 'assemble/summit_extras', $as_extras );
	$by = array_column( assemble_summits_with_extras( false ), null, 'code' );
	remove_filter( 'assemble/summit_extras', $as_extras );
	as_check( 'finance' === $by['aaa']['area'] && 'One line.' === $by['aaa']['blurb'] && [ '*' ] === $by['aaa']['communities'], 'extras: merged' );
	as_check( 'Summit AAA – Fall Edition' === $by['aaa']['title'], 'extras: never override an EP fact' );
	as_check( content_url( 'assets/summits/aaa.png' ) === $by['aaa']['logo'], 'extras: logo from the shared image library' );
	as_check( '' === $by['bbb']['area'] && '' === $by['bbb']['blurb'] && [] === $by['bbb']['communities'] && '' === $by['bbb']['logo'], 'extras: unmapped summit falls back' );
	as_check( isset( assemble_summit_extras()['nasrsf'], assemble_summit_extras()['namesf'] ) && 16 === count( assemble_summit_extras() ), 'extras: all 16 EP codes mapped' );

	// 9. The outcome is recorded for the admin notice.
	as_answer( 200, $as_index, 'W/"v3"' );
	assemble_summits_refresh_and_record();
	as_check( '' === assemble_summits_problem(), 'status: a good refresh raises no notice' );
	as_answer( 0, '' );
	assemble_summits_refresh_and_record();
	as_check( str_contains( assemble_summits_problem(), 'failed' ), 'status: a failed refresh raises the notice' );
	as_check( (bool) wp_next_scheduled( ASSEMBLE_SUMMITS_CRON ) && 'hourly' === wp_get_schedule( ASSEMBLE_SUMMITS_CRON ), 'status: the hourly refresh is scheduled' );
	as_check( ! wp_next_scheduled( 'assemble_summits_daily_refresh' ), 'status: the old daily refresh is gone' );
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
