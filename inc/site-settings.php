<?php
/**
 * Site Settings: the few strings and URLs that change without a code deploy.
 *
 * Templates read every value through assemble_site_setting(), so no URL is ever
 * hard-coded in a template. Today the values are the defaults below. When ACF Pro
 * arrives, an options page ("Site Settings") stores overrides and this function
 * reads them first; templates don't change.
 *
 * Add a key only when a template needs it.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Defaults. URLs are site-relative where the page lives on this site.
 *
 * @return array<string,mixed>
 */
function assemble_site_setting_defaults(): array {
	return [
		// Announcement banner above the masthead.
		'banner_enabled'     => false,
		'banner_text'        => '',
		'banner_url'         => '',

		// Masthead account actions.
		'sign_in_url'        => '/login/',
		'create_account_url' => '/register/',

		// Footer: four columns of [ label, url ]. An empty url renders plain text.
		'footer_columns'     => [
			[
				'heading' => 'Company',
				'links'   => [
					[ 'About', '/about-us/' ],
					[ 'Careers', '/careers/' ],
					[ 'Contact', '/contact-us/' ],
				],
			],
			[
				'heading' => 'Resources',
				'links'   => [
					[ 'Insights', '/field-reports/' ],
					[ 'Summits', '/summits/' ],
					[ 'Benchmark', 'https://report.theassemble.com/' ],
				],
			],
			[
				'heading' => 'Legal',
				'links'   => [
					[ 'Policies', '/policies/' ],
				],
			],
			[
				'heading' => 'Follow',
				'links'   => [
					[ 'LinkedIn', 'https://www.linkedin.com/company/assembleleaders/' ],
				],
			],
		],

		// External apps (BUILD-INSTRUCTIONS §9.3).
		'benchmark_url'      => 'https://report.theassemble.com/',

		// Default share image (attachment ID); 0 means none.
		'default_share_image' => 0,
	];
}

/**
 * Read one Site Setting: the stored override if there is one, else the default.
 *
 * @param mixed $fallback Returned when the key is unknown.
 * @return mixed
 */
function assemble_site_setting( string $key, $fallback = null ) {
	$defaults = assemble_site_setting_defaults();
	$value    = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : $fallback;

	// ACF Pro options page, once it exists. Empty fields fall back to the default.
	if ( function_exists( 'get_field' ) ) {
		$stored = get_field( $key, 'option' );
		if ( null !== $stored && '' !== $stored && false !== $stored && [] !== $stored ) {
			$value = $stored;
		}
	}

	/**
	 * Filter one Site Setting.
	 *
	 * @param mixed  $value
	 * @param string $key
	 */
	return apply_filters( 'assemble/site_setting', $value, $key );
}
