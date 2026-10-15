<?php
/**
 * Summits, read from Executive Platforms' summit API (live 2026-10-15).
 *
 * The EP sites stay the source of truth for summit facts until their editing
 * workflow moves. This copies EP's public summit index into an option, hourly
 * and on demand (`wp assemble summits refresh`), and never writes to EP. API
 * reference: the hub's EP-SUMMITS-API-SPEC.md.
 *
 * One request: GET /wp-json/ep/v1/summits?v=1, sent with the stored ETag, so an
 * unchanged index comes back as a 304 with no body. Each summit's current
 * edition is mapped into the stored shape the templates have read since 0.3.0.
 *
 * A refresh that looks wrong keeps the last good copy: an unreachable API, a
 * body that isn't the expected JSON, or a list that is empty or under half as
 * long as before replaces nothing. A summit with no current edition or no start
 * date is left out with a warning.
 *
 * Templates read only assemble_summits(), so moving summits to Contentful later
 * changes nothing else. The full edition endpoint (agendas, streams, speakers)
 * isn't read yet; summit pages on Assemble (5b) can read it the same way.
 */

defined( 'ABSPATH' ) || exit;

const ASSEMBLE_SUMMITS_OPTION = 'assemble_ep_summits';
const ASSEMBLE_SUMMITS_API = 'https://www.executiveplatforms.com/wp-json/ep/v1/summits?v=1';
const ASSEMBLE_SUMMITS_STATUS_OPTION = 'assemble_ep_summits_status';
const ASSEMBLE_SUMMITS_CRON = 'assemble_summits_refresh';

/**
 * Summits from the last good refresh, soonest first.
 *
 * @param bool $upcoming_only Leave out summits whose end date has passed.
 * @return array<int,array{code:string,short_name:string,title:string,season:string,url:string,register_url:string,agenda_url:string,api_url:string,logo_url:string,city:string,state:string,venue:string,start:string,end:string,timezone:string,status:string,year:int,modified:string,date_text:string,stats:array}>
 */
function assemble_summits( bool $upcoming_only = true ): array {
	$stored  = get_option( ASSEMBLE_SUMMITS_OPTION, [] );
	$summits = is_array( $stored['summits'] ?? null ) ? $stored['summits'] : [];
	$today   = wp_date( 'Y-m-d' );

	if ( $upcoming_only ) {
		$summits = array_values( array_filter( $summits, static fn( $s ) => ( $s['end'] ?: $s['start'] ) >= $today ) );
	}

	usort( $summits, static fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );

	/**
	 * Filter the summits list (e.g. to hide one, or to correct a value EP shows wrongly).
	 *
	 * @param array $summits
	 */
	return (array) apply_filters( 'assemble/summits', $summits );
}

/** When the summits were last refreshed (site timezone, 'Y-m-d H:i'), or ''. */
function assemble_summits_refreshed_at(): string {
	$stored = get_option( ASSEMBLE_SUMMITS_OPTION, [] );

	return (string) ( $stored['fetched_at'] ?? '' );
}


/**
 * Read EP's summit index and replace the stored summits if the result looks sound.
 *
 * @param bool $dry_run Fetch and map, store nothing (and skip the ETag, so the full list is shown).
 * @return array{ok:bool,stored:bool,unchanged:bool,summits:array,warnings:string[],error:string}
 */
function assemble_summits_refresh( bool $dry_run = false ): array {
	$result = [ 'ok' => false, 'stored' => false, 'unchanged' => false, 'summits' => [], 'warnings' => [], 'error' => '' ];
	$stored = get_option( ASSEMBLE_SUMMITS_OPTION, [] );
	$before = is_array( $stored['summits'] ?? null ) ? $stored['summits'] : [];
	$etag   = $dry_run ? '' : (string) ( $stored['etag'] ?? '' );

	$response = wp_remote_get(
		ASSEMBLE_SUMMITS_API,
		[
			'timeout'    => 20,
			'user-agent' => 'AssembleSiteSummits/2.0 (+' . home_url( '/' ) . ')',
			'headers'    => array_filter( [ 'Accept' => 'application/json', 'If-None-Match' => $etag ] ),
		]
	);

	if ( is_wp_error( $response ) ) {
		$result['error'] = 'Could not reach the EP summit API: ' . $response->get_error_message();
		return $result;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	// Unchanged since the last refresh: keep the stored list, note that it was checked.
	if ( 304 === $code && $before ) {
		$result = array_merge( $result, [ 'ok' => true, 'unchanged' => true, 'summits' => $before ] );
		if ( ! $dry_run ) {
			update_option( ASSEMBLE_SUMMITS_OPTION, array_merge( $stored, [ 'fetched_at' => wp_date( 'Y-m-d H:i' ) ] ), false );
		}
		return $result;
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code || ! is_array( $body ) || ! is_array( $body['summits'] ?? null ) ) {
		$result['error'] = sprintf( 'The EP summit API answered %d without a summits list. Keeping the stored copy.', $code );
		return $result;
	}

	$summits = [];
	foreach ( $body['summits'] as $item ) {
		$summit = is_array( $item ) ? assemble_summits_from_api( $item ) : null;

		if ( null === $summit ) {
			$result['warnings'][] = sprintf( '%s: no current edition with a start date; left out.', is_array( $item ) ? (string) ( $item['code'] ?? '?' ) : '?' );
			continue;
		}

		$summits[] = $summit;
	}

	if ( ! $summits || count( $summits ) < count( $before ) / 2 ) {
		$result['error'] = sprintf( 'The EP summit API returned %d usable summits (previously %d). Keeping the stored copy.', count( $summits ), count( $before ) );
		return $result;
	}

	$result['ok']      = true;
	$result['summits'] = $summits;

	if ( ! $dry_run ) {
		update_option(
			ASSEMBLE_SUMMITS_OPTION,
			[
				'fetched_at'   => wp_date( 'Y-m-d H:i' ),
				'source'       => ASSEMBLE_SUMMITS_API,
				'generated_at' => (string) ( $body['generated_at'] ?? '' ),
				'etag'         => (string) wp_remote_retrieve_header( $response, 'etag' ),
				'summits'      => $summits,
			],
			false
		);
		$result['stored'] = true;
	}

	return $result;
}

/**
 * The summit's display name. EP's `title` and `site_name` each name the edition
 * for only some summits ("The Fall Edition of the North American HR Executive
 * Summit" vs "North American HR Executive Summit - Fall Edition"; NAFES Spring
 * the other way round), so take whichever ends in "… Edition", else `title`
 * (which keeps subtitles such as FWS's). The dash before "Edition" is set as an
 * en dash, as on EP's own pages.
 */
function assemble_summits_title( string $title, string $site_name ): string {
	$edition = '/\s+[-–—]\s+((?:Spring|Fall)\s+Edition)$/u';
	$name    = preg_match( $edition, $site_name ) ? $site_name : ( '' !== $title ? $title : $site_name );

	return (string) preg_replace( $edition, ' – $1', $name );
}

/**
 * One summit from the API index, in the stored shape, or null when it has no
 * usable current edition. Missing values become '' rather than null.
 *
 * `logo_url` is EP's white wordmark (for dark backgrounds); the theme uses the
 * colour logos from the shared image library instead. `date_text` and `stats`
 * stay in the shape for older readers but are empty: the API has no stats
 * counters, and dates are formatted from `start` and `end`.
 */
function assemble_summits_from_api( array $item ): ?array {
	$edition = is_array( $item['current_edition'] ?? null ) ? $item['current_edition'] : [];
	$venue   = is_array( $edition['venue'] ?? null ) ? $edition['venue'] : [];
	$code    = sanitize_key( (string) ( $item['code'] ?? '' ) );
	$start   = (string) ( $edition['start_date'] ?? '' );
	$end     = (string) ( $edition['end_date'] ?? '' );
	$is_date = static fn( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
	$text    = static fn( $v ): string => is_scalar( $v ) ? trim( (string) $v ) : '';
	$url     = static fn( $v ): string => is_string( $v ) && str_starts_with( $v, 'https://' ) ? $v : '';

	if ( '' === $code || ! $is_date( $start ) ) {
		return null;
	}

	return [
		'code'         => $code,
		'short_name'   => $text( $item['short_name'] ?? '' ),
		'title'        => assemble_summits_title( $text( $item['title'] ?? '' ), $text( $item['site_name'] ?? '' ) ),
		'season'       => in_array( $item['season'] ?? '', [ 'spring', 'fall' ], true ) ? $item['season'] : '',
		'url'          => $url( $item['url'] ?? '' ),
		'register_url' => $url( $edition['register_url'] ?? '' ),
		'agenda_url'   => ! empty( $edition['agenda_available'] ) ? $url( $edition['agenda_url'] ?? '' ) : '',
		'api_url'      => $url( $item['api_url'] ?? '' ),
		'logo_url'     => $url( $item['logo_white']['url'] ?? '' ),
		'city'         => $text( $venue['city'] ?? '' ),
		'state'        => $text( $venue['state'] ?? '' ),
		'venue'        => $text( $venue['name'] ?? '' ),
		'start'        => $start,
		'end'          => $is_date( $end ) && $end >= $start ? $end : $start,
		'timezone'     => $text( $edition['timezone'] ?? '' ),
		'status'       => $text( $edition['status'] ?? '' ),
		'year'         => (int) ( $edition['year'] ?? 0 ),
		'modified'     => $text( $item['modified'] ?? '' ),
		'date_text'    => '',
		'stats'        => [],
	];
}

/**
 * Refresh and record the outcome for the admin notice. Used by the hourly
 * schedule and the CLI; a dry run records nothing.
 *
 * @return array Same shape as assemble_summits_refresh().
 */
function assemble_summits_refresh_and_record( bool $dry_run = false ): array {
	$result = assemble_summits_refresh( $dry_run );

	if ( ! $dry_run ) {
		update_option(
			ASSEMBLE_SUMMITS_STATUS_OPTION,
			[
				'at'       => wp_date( 'Y-m-d H:i' ),
				'ok'       => $result['ok'],
				'error'    => $result['error'],
				'warnings' => $result['warnings'],
			],
			false
		);
	}

	return $result;
}

/*
 * Hourly refresh (Cale, 2026-10-15; daily before). Cheap: an unchanged index is a
 * 304 with no body. WP-Cron runs on site traffic, so "hourly" is approximate. A
 * failed run keeps the last good copy and raises the notice below. Sites that
 * scheduled the old daily hook get it replaced.
 */
add_action( ASSEMBLE_SUMMITS_CRON, static fn() => assemble_summits_refresh_and_record() );

add_action(
	'init',
	static function (): void {
		if ( wp_next_scheduled( 'assemble_summits_daily_refresh' ) ) {
			wp_clear_scheduled_hook( 'assemble_summits_daily_refresh' );
		}

		if ( wp_next_scheduled( ASSEMBLE_SUMMITS_CRON ) && 'hourly' !== wp_get_schedule( ASSEMBLE_SUMMITS_CRON ) ) {
			wp_clear_scheduled_hook( ASSEMBLE_SUMMITS_CRON );
		}

		if ( ! wp_next_scheduled( ASSEMBLE_SUMMITS_CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', ASSEMBLE_SUMMITS_CRON );
		}
	}
);

/**
 * Why the stored summits may be stale, or '' when all is well: the last refresh
 * failed, or nothing has refreshed for three days.
 */
function assemble_summits_problem(): string {
	$status = (array) get_option( ASSEMBLE_SUMMITS_STATUS_OPTION, [] );
	$last   = assemble_summits_refreshed_at();

	if ( isset( $status['ok'] ) && ! $status['ok'] ) {
		return sprintf(
			/* translators: 1: date and time, 2: error message, 3: date and time of the last good copy. */
			__( 'The summits refresh from the EP summit API failed on %1$s: %2$s The site is showing the copy from %3$s.', 'assemble-core' ),
			$status['at'],
			rtrim( (string) $status['error'], '.' ) . '.',
			'' !== $last ? $last : __( 'never', 'assemble-core' )
		);
	}

	if ( '' !== $last && strtotime( $last ) < strtotime( wp_date( 'Y-m-d H:i' ) ) - 3 * DAY_IN_SECONDS ) {
		/* translators: %s: date and time. */
		return sprintf( __( 'Summits were last refreshed from the EP summit API on %s. Check that WP-Cron is running.', 'assemble-core' ), $last );
	}

	return '';
}

add_action(
	'admin_notices',
	static function (): void {
		$problem = current_user_can( 'manage_options' ) ? assemble_summits_problem() : '';

		if ( '' !== $problem ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s %3$s</p></div>',
				esc_html__( 'Assemble summits:', 'assemble-core' ),
				esc_html( $problem ),
				esc_html__( 'To retry: wp assemble summits refresh', 'assemble-core' )
			);
		}
	}
);


if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Summits read from Executive Platforms' summit API.
	 */
	class Assemble_Summits_CLI {

		/**
		 * Re-read the summits from EP's summit API and store them.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Show what would be stored without storing it.
		 *
		 * ## EXAMPLES
		 *
		 *     wp assemble summits refresh
		 *     wp assemble summits refresh --dry-run
		 */
		public function refresh( array $args, array $assoc ): void {
			$result = assemble_summits_refresh_and_record( isset( $assoc['dry-run'] ) );

			foreach ( $result['warnings'] as $warning ) {
				WP_CLI::warning( $warning );
			}

			if ( ! $result['ok'] ) {
				WP_CLI::error( $result['error'] );
			}

			self::table( $result['summits'] );

			if ( $result['unchanged'] ) {
				WP_CLI::success( sprintf( 'Unchanged since the last refresh (304); %d summits kept.', count( $result['summits'] ) ) );
				return;
			}

			WP_CLI::success( sprintf( '%d summits %s.', count( $result['summits'] ), $result['stored'] ? 'stored' : 'read (dry run, nothing stored)' ) );
		}

		/**
		 * List the stored summits.
		 *
		 * ## OPTIONS
		 *
		 * [--all]
		 * : Include summits that have ended.
		 */
		public function list( array $args, array $assoc ): void {
			self::table( assemble_summits( ! isset( $assoc['all'] ) ) );
			WP_CLI::line( 'Last refreshed: ' . ( assemble_summits_refreshed_at() ?: 'never' ) );
			WP_CLI::line( 'Next scheduled refresh: ' . ( wp_next_scheduled( ASSEMBLE_SUMMITS_CRON ) ? wp_date( 'Y-m-d H:i', (int) wp_next_scheduled( ASSEMBLE_SUMMITS_CRON ) ) : 'none' ) );

			$problem = assemble_summits_problem();
			if ( '' !== $problem ) {
				WP_CLI::warning( $problem );
			}
		}

		private static function table( array $summits ): void {
			WP_CLI\Utils\format_items(
				'table',
				array_map(
					static fn( $s ) => [
						'code'   => $s['code'],
						'start'  => $s['start'],
						'end'    => $s['end'],
						'city'   => trim( $s['city'] . ', ' . $s['state'], ', ' ),
						'venue'  => $s['venue'],
						'season' => $s['season'],
						'agenda' => '' !== ( $s['agenda_url'] ?? '' ) ? 'yes' : 'no',
						'title'  => $s['title'],
					],
					$summits
				),
				[ 'code', 'start', 'end', 'city', 'venue', 'season', 'agenda', 'title' ]
			);
		}
	}

	WP_CLI::add_command( 'assemble summits', 'Assemble_Summits_CLI' );
}
