<?php
/**
 * Guards for the Assemble Content feed that belong to the public site.
 *
 * On production the gate bypass is always off, whatever afr_settings says, so
 * a beta setting carried over by mistake can never expose gated reports.
 * (Launch adds the same filter as an mu-plugin, so it holds even if this
 * plugin is deactivated.)
 */

defined( 'ABSPATH' ) || exit;

if ( assemble_is_production_host() ) {
	add_filter(
		'afr_bypass_mode',
		static function (): string {
			return 'off';
		},
		PHP_INT_MAX
	);
}
