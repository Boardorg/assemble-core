<?php
/**
 * Summits, from the shared summits engine in Assemble Content (2.0.0 or later).
 *
 * The engine reads Executive Platforms' summit API and holds Assemble's own
 * extras per summit, for this site and the Member Center alike. These wrappers
 * keep the function names the theme has read since 0.3.0. Without the engine
 * they return nothing, and the theme shows its empty states.
 */

defined( 'ABSPATH' ) || exit;

const ASSEMBLE_SUMMITS_LEGACY_OPTION        = 'assemble_ep_summits';
const ASSEMBLE_SUMMITS_LEGACY_STATUS_OPTION = 'assemble_ep_summits_status';
const ASSEMBLE_SUMMITS_LEGACY_CRON          = 'assemble_summits_refresh';

/** Is the shared summits engine active? */
function assemble_has_summits_engine(): bool {
	return class_exists( 'AC_Summits' ) && class_exists( 'AC_Summit_Extras' );
}

/**
 * Summits from the last good refresh, soonest first.
 *
 * @param bool $upcoming_only Leave out summits whose end date has passed.
 * @return array<int,array>
 */
function assemble_summits( bool $upcoming_only = true ): array {
	return assemble_has_summits_engine() ? AC_Summits::all( $upcoming_only ) : [];
}

/**
 * Summits with Assemble's extras merged in (`area`, `area_label`, `communities`,
 * `blurb`, `logo` and the ad fields).
 *
 * @param bool $upcoming_only Leave out summits whose end date has passed.
 * @return array<int,array>
 */
function assemble_summits_with_extras( bool $upcoming_only = true ): array {
	return assemble_has_summits_engine() ? AC_Summits::with_extras( $upcoming_only ) : [];
}

/** The extras map, keyed by EP summit code. */
function assemble_summit_extras(): array {
	return assemble_has_summits_engine() ? AC_Summit_Extras::all() : [];
}

/** When the summits were last refreshed (site timezone, 'Y-m-d H:i'), or ''. */
function assemble_summits_refreshed_at(): string {
	return assemble_has_summits_engine() ? AC_Summits::refreshed_at() : '';
}

/*
 * Hand-over from 0.7.0, which read the API itself: its stored copy seeds the
 * engine (same shape), so the site has summits before the engine's first run.
 * Then the old option and schedule are removed.
 */
add_action(
	'init',
	static function (): void {
		if ( wp_next_scheduled( ASSEMBLE_SUMMITS_LEGACY_CRON ) ) {
			wp_clear_scheduled_hook( ASSEMBLE_SUMMITS_LEGACY_CRON );
		}

		$legacy = get_option( ASSEMBLE_SUMMITS_LEGACY_OPTION, null );

		if ( null === $legacy || ! assemble_has_summits_engine() ) {
			return;
		}

		if ( is_array( $legacy['summits'] ?? null ) && $legacy['summits'] && ! get_option( AC_Summits::OPTION ) ) {
			unset( $legacy['etag'] );
			update_option( AC_Summits::OPTION, $legacy, false );
		}

		delete_option( ASSEMBLE_SUMMITS_LEGACY_OPTION );
		delete_option( ASSEMBLE_SUMMITS_LEGACY_STATUS_OPTION );
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			if ( class_exists( 'AC_Summits_CLI' ) ) {
				// Old name for `wp assemble-content summits`.
				WP_CLI::add_command( 'assemble summits', 'AC_Summits_CLI' );
			}
		}
	);
}

/*
 * Board.org's Practitioner Summits (Kristen's review, Cale 2026-11-10). They
 * aren't Executive Platforms events, so EP's API doesn't list them; they're
 * kept here by hand and join EP's summits through the engine's filters, on
 * this site only. Update the dates and links each year from board.org.
 */

/** The Board.org summits, in the engine's stored-summit shape. */
function assemble_board_summits(): array {
	$base = [
		'season'     => '',
		'agenda_url' => '',
		'api_url'    => '',
		'logo_url'   => '',
		'timezone'   => 'America/New_York',
		'status'     => 'upcoming',
		'modified'   => '',
		'date_text'  => '',
		'stats'      => [],
	];

	return [
		$base + [
			'code'         => 'tmbps',
			'short_name'   => 'TMBPS',
			'title'        => 'Talent Marketing Practitioner Summit',
			'url'          => 'https://board.org/talent/meetings/practitioner-summit27/',
			'register_url' => 'https://board.org/board-registration-form/?Group=TMBPS&Event=2027%20Talent%20Marketing%20Practitioner%20Summit',
			'city'         => 'National Harbor',
			'state'        => 'MD',
			'venue'        => '',
			'start'        => '2027-05-25',
			'end'          => '2027-05-26',
			'year'         => 2027,
		],
		$base + [
			'code'         => 'dmcps',
			'short_name'   => 'DMCPS',
			'title'        => 'Digital Marketing & Communications Practitioner Summit',
			'url'          => 'https://board.org/meetings/dmc-practitioner-summit27/',
			'register_url' => 'https://board.org/board-registration-form/?Group=DMCPS&Event=2027%20Digital%20Marketing%20and%20Communications%20Practitioner%20Summit',
			'city'         => 'National Harbor',
			'state'        => 'MD',
			'venue'        => '',
			'start'        => '2027-05-25',
			'end'          => '2027-05-26',
			'year'         => 2027,
		],
	];
}

add_filter(
	'ac_summits',
	static function ( array $summits ): array {
		$today = wp_date( 'Y-m-d' );
		foreach ( assemble_board_summits() as $summit ) {
			if ( $summit['end'] >= $today && ! in_array( $summit['code'], array_column( $summits, 'code' ), true ) ) {
				$summits[] = $summit;
			}
		}
		usort( $summits, static fn( $a, $b ) => strcmp( (string) $a['start'], (string) $b['start'] ) );

		return $summits;
	}
);

add_filter(
	'ac_summit_extras',
	static function ( array $extras ): array {
		$extras['tmbps'] = [
			'area'        => 'human-resources',
			'area_label'  => __( 'Human Resources', 'assemble-core' ),
			'communities' => [ 'talent-marketing' ],
			'blurb'       => __( 'Talent marketing, employer brand, recruitment marketing, and candidate experience.', 'assemble-core' ),
		];
		$extras['dmcps'] = [
			'area'        => 'marketing',
			'area_label'  => __( 'Marketing & Communications', 'assemble-core' ),
			'communities' => [ '*' ],
			'blurb'       => __( 'Social media, AI search, and digital communications, from the leaders who run the programs.', 'assemble-core' ),
		];

		return $extras;
	}
);
