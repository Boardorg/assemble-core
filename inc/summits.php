<?php
/**
 * Summits, from the shared summits engine in Assemble Content (1.10.0 or later).
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
	return class_exists( 'AFR_Summits' ) && class_exists( 'AFR_Summit_Extras' );
}

/**
 * Summits from the last good refresh, soonest first.
 *
 * @param bool $upcoming_only Leave out summits whose end date has passed.
 * @return array<int,array>
 */
function assemble_summits( bool $upcoming_only = true ): array {
	return assemble_has_summits_engine() ? AFR_Summits::all( $upcoming_only ) : [];
}

/**
 * Summits with Assemble's extras merged in (`area`, `area_label`, `communities`,
 * `blurb`, `logo` and the ad fields).
 *
 * @param bool $upcoming_only Leave out summits whose end date has passed.
 * @return array<int,array>
 */
function assemble_summits_with_extras( bool $upcoming_only = true ): array {
	return assemble_has_summits_engine() ? AFR_Summits::with_extras( $upcoming_only ) : [];
}

/** The extras map, keyed by EP summit code. */
function assemble_summit_extras(): array {
	return assemble_has_summits_engine() ? AFR_Summit_Extras::all() : [];
}

/** When the summits were last refreshed (site timezone, 'Y-m-d H:i'), or ''. */
function assemble_summits_refreshed_at(): string {
	return assemble_has_summits_engine() ? AFR_Summits::refreshed_at() : '';
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

		if ( is_array( $legacy['summits'] ?? null ) && $legacy['summits'] && ! get_option( AFR_Summits::OPTION ) ) {
			unset( $legacy['etag'] );
			update_option( AFR_Summits::OPTION, $legacy, false );
		}

		delete_option( ASSEMBLE_SUMMITS_LEGACY_OPTION );
		delete_option( ASSEMBLE_SUMMITS_LEGACY_STATUS_OPTION );
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			if ( class_exists( 'AFR_Summits_CLI' ) ) {
				// Old name for `wp assemble-content summits`.
				WP_CLI::add_command( 'assemble summits', 'AFR_Summits_CLI' );
			}
		}
	);
}
