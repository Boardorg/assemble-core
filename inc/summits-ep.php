<?php
/**
 * Summits, read from executiveplatforms.com (interim, decided 2026-10-09).
 *
 * The Executive Platforms sites stay the source of truth for summit facts until
 * their editing workflow moves. This copies the public summit list into an
 * option, daily and on demand (`wp assemble summits refresh`), and never writes to EP:
 *
 *   - executiveplatforms.com/summits/ (EP's [summits] shortcode): code, title,
 *     start month.day under a year heading, city, wordmark logo, register link;
 *   - each summit's home page hero: the full date range ("April 5-7, 2027"), the
 *     venue line under it, and the stats counters.
 *
 * A refresh that parses badly keeps the last good copy: if the list comes back
 * empty or under half as long as before, nothing is replaced; a summit whose home
 * page can't be read keeps its previous details.
 *
 * Replace this with EP's own feed once their developer adds one (see the hub's
 * PHASE-5-HANDOFF.md), and with Contentful when summits move there. Templates read
 * only assemble_summits(), so swapping the source changes nothing else.
 */

defined( 'ABSPATH' ) || exit;

const ASSEMBLE_SUMMITS_OPTION = 'assemble_ep_summits';
const ASSEMBLE_SUMMITS_SOURCE = 'https://www.executiveplatforms.com/summits/';
const ASSEMBLE_SUMMITS_STATUS_OPTION = 'assemble_ep_summits_status';
const ASSEMBLE_SUMMITS_CRON = 'assemble_summits_daily_refresh';

/**
 * Summits from the last good refresh, soonest first.
 *
 * @param bool $upcoming_only Leave out summits whose end date has passed.
 * @return array<int,array{code:string,title:string,season:string,url:string,register_url:string,logo_url:string,city:string,state:string,venue:string,start:string,end:string,date_text:string,stats:array<string,string>}>
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
 * Fetch EP's pages and replace the stored summits if the result looks sound.
 *
 * @param bool $dry_run Parse and report, store nothing.
 * @return array{ok:bool,stored:bool,summits:array,warnings:string[],error:string}
 */
function assemble_summits_refresh( bool $dry_run = false ): array {
	$result = [ 'ok' => false, 'stored' => false, 'summits' => [], 'warnings' => [], 'error' => '' ];

	$list_html = assemble_summits_fetch( ASSEMBLE_SUMMITS_SOURCE );
	if ( '' === $list_html ) {
		$result['error'] = 'Could not fetch ' . ASSEMBLE_SUMMITS_SOURCE;
		return $result;
	}

	$summits = assemble_summits_parse_list( $list_html );
	$stored  = get_option( ASSEMBLE_SUMMITS_OPTION, [] );
	$before  = is_array( $stored['summits'] ?? null ) ? $stored['summits'] : [];
	$by_code = array_column( $before, null, 'code' );

	if ( ! $summits || count( $summits ) < count( $before ) / 2 ) {
		$result['error'] = sprintf( 'The summits page parsed to %d summits (previously %d). Its layout may have changed; keeping the stored copy.', count( $summits ), count( $before ) );
		return $result;
	}

	foreach ( $summits as &$summit ) {
		$home    = assemble_summits_fetch( $summit['url'] );
		$details = '' !== $home ? assemble_summits_parse_home( $home ) : [];

		if ( ! $details || '' === $details['start'] ) {
			$result['warnings'][] = sprintf( '%s: could not read the date range on its home page; kept the previous details.', $summit['code'] );
			$details = array_intersect_key( $by_code[ $summit['code'] ] ?? [], array_flip( [ 'start', 'end', 'date_text', 'venue', 'stats' ] ) );
		} elseif ( '' !== $summit['start'] && $details['start'] !== $summit['start'] ) {
			$result['warnings'][] = sprintf( '%s: the list says %s, the home page says %s; using the home page.', $summit['code'], $summit['start'], $details['start'] );
		}

		$summit = array_merge( $summit, array_filter( $details, static fn( $v ) => '' !== $v && [] !== $v ) );

		$summit['venue'] = assemble_summits_venue_only( $summit['venue'], $summit['city'], $summit['state'] );
	}
	unset( $summit );

	$result['ok']      = true;
	$result['summits'] = $summits;

	if ( ! $dry_run ) {
		update_option(
			ASSEMBLE_SUMMITS_OPTION,
			[
				'fetched_at' => wp_date( 'Y-m-d H:i' ),
				'source'     => ASSEMBLE_SUMMITS_SOURCE,
				'summits'    => $summits,
			],
			false
		);
		$result['stored'] = true;
	}

	return $result;
}

/**
 * Refresh and record the outcome for the admin notice. Used by the daily
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
 * Daily refresh (Cale, 2026-10-09). WP-Cron runs on site traffic, so "daily" is
 * approximate. A failed run keeps the last good copy and raises the notice below.
 */
add_action( ASSEMBLE_SUMMITS_CRON, static fn() => assemble_summits_refresh_and_record() );

add_action(
	'init',
	static function (): void {
		if ( ! wp_next_scheduled( ASSEMBLE_SUMMITS_CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', ASSEMBLE_SUMMITS_CRON );
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
			__( 'The summits refresh from executiveplatforms.com failed on %1$s: %2$s The site is showing the copy from %3$s.', 'assemble-core' ),
			$status['at'],
			rtrim( (string) $status['error'], '.' ) . '.',
			'' !== $last ? $last : __( 'never', 'assemble-core' )
		);
	}

	if ( '' !== $last && strtotime( $last ) < strtotime( wp_date( 'Y-m-d H:i' ) ) - 3 * DAY_IN_SECONDS ) {
		/* translators: %s: date and time. */
		return sprintf( __( 'Summits were last refreshed from executiveplatforms.com on %s. Check that WP-Cron is running.', 'assemble-core' ), $last );
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

/**
 * The venue name from a home page line: "Venue | City (Area), ST", "Venue, City, ST"
 * or "Venue, City ST" all become "Venue".
 */
function assemble_summits_venue_only( string $line, string $city, string $state ): string {
	if ( str_contains( $line, '|' ) ) {
		return trim( explode( '|', $line, 2 )[0] );
	}

	if ( '' === $city ) {
		return trim( $line );
	}

	$pattern = '/[\s,]*' . preg_quote( $city, '/' ) . '(\s*\([^)]*\))?(,?\s*' . preg_quote( $state, '/' ) . ')?\s*$/i';

	return trim( (string) preg_replace( $pattern, '', $line ), " ,\t" );
}

/** GET a page from EP; '' on any failure. Identifies itself, follows redirects. */
function assemble_summits_fetch( string $url ): string {
	$response = wp_remote_get(
		$url,
		[
			'timeout'    => 20,
			'user-agent' => 'AssembleSiteSummits/1.0 (+' . home_url( '/' ) . ')',
		]
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}

	return (string) wp_remote_retrieve_body( $response );
}

/** An XPath over an HTML string, with libxml's warnings about real-world HTML silenced. */
function assemble_summits_xpath( string $html ): DOMXPath {
	$doc      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	return new DOMXPath( $doc );
}

/** XPath for elements carrying one class among others. */
function assemble_summits_class( string $class ): string {
	return sprintf( 'contains(concat(" ", normalize-space(@class), " "), " %s ")', $class );
}

/** Collapsed, decoded text of a node, or ''. */
function assemble_summits_text( ?DOMNode $node ): string {
	if ( ! $node ) {
		return '';
	}

	// Collapse whitespace and drop zero-width characters left by the page builder.
	$text = html_entity_decode( $node->textContent, ENT_QUOTES, 'UTF-8' );
	$text = (string) preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text );

	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Parse EP's summits list page.
 *
 * @return array<int,array> One row per summit, details from the home page still empty.
 */
function assemble_summits_parse_list( string $html ): array {
	$xp      = assemble_summits_xpath( $html );
	$summits = [];

	foreach ( $xp->query( '//div[' . assemble_summits_class( 'summit-wrapper' ) . ']' ) as $group ) {
		$year = assemble_summits_text( $xp->query( './/div[' . assemble_summits_class( 'summit-year' ) . ']', $group )->item( 0 ) );
		if ( ! preg_match( '/^\d{4}$/', $year ) ) {
			continue;
		}

		foreach ( $xp->query( './/div[' . assemble_summits_class( 'summit' ) . ']', $group ) as $card ) {
			$link = $xp->query( './a[@href]', $card )->item( 0 );
			$url  = $link instanceof DOMElement ? $link->getAttribute( 'href' ) : '';
			$code = (string) ( preg_match( '#executiveplatforms\.com/([a-z0-9-]+)/?$#', $url, $m ) ? $m[1] : '' );

			if ( '' === $code ) {
				continue;
			}

			$title    = assemble_summits_text( $xp->query( './/div[' . assemble_summits_class( 'summit-title' ) . ']', $card )->item( 0 ) );
			$md       = assemble_summits_text( $xp->query( './/div[' . assemble_summits_class( 'summit-date' ) . ']', $card )->item( 0 ) );
			$place    = assemble_summits_text( $xp->query( './/div[' . assemble_summits_class( 'summit-venue' ) . ']', $card )->item( 0 ) );
			$register = $xp->query( './/div[' . assemble_summits_class( 'summit-register-button' ) . ']//a[@href]', $card )->item( 0 );
			$logo     = $xp->query( './/img', $card )->item( 0 );
			$logo_url = $logo instanceof DOMElement ? ( $logo->getAttribute( 'data-src' ) ?: $logo->getAttribute( 'src' ) ) : '';
			[ $city, $state ] = array_pad( array_map( 'trim', explode( ',', $place, 2 ) ), 2, '' );

			$start = '';
			if ( preg_match( '/^(\d{1,2})\.(\d{1,2})$/', $md, $m ) && checkdate( (int) $m[1], (int) $m[2], (int) $year ) ) {
				$start = sprintf( '%04d-%02d-%02d', $year, $m[1], $m[2] );
			}

			$summits[] = [
				'code'         => $code,
				'title'        => $title,
				'season'       => preg_match( '/\b(Spring|Fall)\s+Edition\b/i', $title, $s ) ? strtolower( $s[1] ) : '',
				'url'          => $url,
				'register_url' => $register instanceof DOMElement ? $register->getAttribute( 'href' ) : '',
				'logo_url'     => str_starts_with( $logo_url, 'http' ) ? $logo_url : '',
				'city'         => $city,
				'state'        => $state,
				'venue'        => '',
				'start'        => $start,
				'end'          => '',
				'date_text'    => '',
				'stats'        => [],
			];
		}
	}

	return $summits;
}

/**
 * Parse a summit home page's hero: the first heading that is a date range, the
 * heading after it (venue line), and the counters ("Attendees" => "500").
 *
 * @return array{start:string,end:string,date_text:string,venue:string,stats:array<string,string>}
 */
function assemble_summits_parse_home( string $html ): array {
	$xp      = assemble_summits_xpath( $html );
	$out     = [ 'start' => '', 'end' => '', 'date_text' => '', 'venue' => '', 'stats' => [] ];
	$heading = '//*[self::h1 or self::h2 or self::h3 or self::h4][' . assemble_summits_class( 'elementor-heading-title' ) . ']';
	$nodes   = iterator_to_array( $xp->query( $heading ) );

	foreach ( $nodes as $i => $node ) {
		$range = assemble_summits_parse_range( assemble_summits_text( $node ) );
		if ( $range ) {
			$out = array_merge( $out, $range, [ 'date_text' => assemble_summits_text( $node ) ] );
			$out['venue'] = assemble_summits_text( $nodes[ $i + 1 ] ?? null );
			break;
		}
	}

	foreach ( $xp->query( '//div[' . assemble_summits_class( 'elementor-counter' ) . ']' ) as $counter ) {
		$title  = assemble_summits_text( $xp->query( './/*[' . assemble_summits_class( 'elementor-counter-title' ) . ']', $counter )->item( 0 ) );
		$number = $xp->query( './/*[' . assemble_summits_class( 'elementor-counter-number' ) . ']', $counter )->item( 0 );
		if ( '' !== $title && $number instanceof DOMElement && '' !== $number->getAttribute( 'data-to-value' ) ) {
			$suffix                = assemble_summits_text( $xp->query( './/*[' . assemble_summits_class( 'elementor-counter-number-suffix' ) . ']', $counter )->item( 0 ) );
			$out['stats'][ $title ] = $number->getAttribute( 'data-to-value' ) . $suffix;
		}
	}

	return $out;
}

/**
 * "April 5-7, 2027", "September 30 - October 2, 2026", "November 16 - November 18, 2026".
 *
 * @return array{start:string,end:string}|null
 */
function assemble_summits_parse_range( string $text ): ?array {
	$month = '(January|February|March|April|May|June|July|August|September|October|November|December)';
	$text  = str_replace( [ '–', '—' ], '-', $text );

	// Month D[, YYYY] [- [Month] D], YYYY: the first year only appears when the range crosses into a new year.
	if ( ! preg_match( "/^$month\\s+(\\d{1,2})(?:,\\s*(\\d{4}))?(?:\\s*-\\s*(?:$month\\s+)?(\\d{1,2}))?,\\s*(\\d{4})$/i", $text, $m ) ) {
		return null;
	}

	$end_year   = $m[6];
	$start_year = '' !== $m[3] ? $m[3] : $end_year;
	$start      = strtotime( "{$m[1]} {$m[2]} $start_year" );
	$end        = '' !== ( $m[5] ?? '' ) ? strtotime( ( '' !== $m[4] ? $m[4] : $m[1] ) . " {$m[5]} $end_year" ) : $start;

	return $start && $end && $end >= $start ? [ 'start' => gmdate( 'Y-m-d', $start ), 'end' => gmdate( 'Y-m-d', $end ) ] : null;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Summits read from executiveplatforms.com (interim source).
	 */
	class Assemble_Summits_CLI {

		/**
		 * Re-read the summits from executiveplatforms.com and store them.
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
			WP_CLI::success( sprintf( '%d summits %s.', count( $result['summits'] ), $result['stored'] ? 'stored' : 'parsed (dry run, nothing stored)' ) );
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
						'logo'   => '' !== $s['logo_url'] ? 'yes' : 'no',
						'title'  => $s['title'],
					],
					$summits
				),
				[ 'code', 'start', 'end', 'city', 'venue', 'season', 'logo', 'title' ]
			);
		}
	}

	WP_CLI::add_command( 'assemble summits', 'Assemble_Summits_CLI' );
}
