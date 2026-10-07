<?php
/**
 * Site Settings: the few strings and URLs that change without a code deploy.
 *
 * Templates read every value through assemble_site_setting(), so no URL is ever
 * hard-coded in a template. The defaults below are the fallback; the "Site
 * Settings" options page (Secure Custom Fields, field group in acf-json/) stores
 * overrides, and an empty field falls back to its default.
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

		// Logged-out footer row above the columns (guide Draft 0.4).
		'footer_cta_text'    => 'Get the latest Insights from peers who share your role.',

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

		// Homepage links. Placeholders until those pages exist (Phase 6 / Communities index).
		'how_it_works_url'   => '/about-us/',
		'communities_url'    => '/field-reports/',

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

	// The Site Settings options page. Empty fields fall back to the default.
	if ( function_exists( 'get_field' ) ) {
		$stored = get_field( $key, 'option' );
		if ( 'footer_columns' === $key && is_array( $stored ) ) {
			$stored = assemble_site_setting_footer_columns( $stored );
		}
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

/**
 * Turn the footer repeater's rows into the shape templates read:
 * [ heading, links => [ [ label, url ], … ] ]. Columns without a heading or links are dropped.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array{heading:string,links:array<int,array{0:string,1:string}>}>
 */
function assemble_site_setting_footer_columns( array $rows ): array {
	$columns = [];
	foreach ( $rows as $row ) {
		$links = [];
		foreach ( (array) ( $row['links'] ?? [] ) as $link ) {
			$label = trim( (string) ( $link['label'] ?? '' ) );
			if ( '' !== $label ) {
				$links[] = [ $label, trim( (string) ( $link['url'] ?? '' ) ) ];
			}
		}

		$heading = trim( (string) ( $row['heading'] ?? '' ) );
		if ( '' !== $heading && $links ) {
			$columns[] = [
				'heading' => $heading,
				'links'   => $links,
			];
		}
	}

	return $columns;
}

/*
 * The options page and where its field group lives. Secure Custom Fields (or ACF)
 * loads and saves the group as JSON in acf-json/, so field changes are code:
 * edit them on a local site, then commit the JSON.
 */
add_action(
	'acf/init',
	static function (): void {
		if ( function_exists( 'acf_add_options_page' ) ) {
			acf_add_options_page(
				[
					'page_title'    => 'Site Settings',
					'menu_title'    => 'Site Settings',
					'menu_slug'     => 'assemble-site-settings',
					'capability'    => 'manage_options',
					'icon_url'      => 'dashicons-admin-generic',
					'position'      => 61,
					'redirect'      => false,
					'autoload'      => true,
					'update_button' => 'Save settings',
				]
			);
		}
	}
);

add_filter(
	'acf/settings/load_json',
	static function ( array $paths ): array {
		$paths[] = ASSEMBLE_CORE_DIR . 'acf-json';

		return $paths;
	}
);

// Save only this plugin's groups back into acf-json/; other groups keep their own location.
add_filter(
	'acf/settings/save_json/key=group_assemble_site_settings',
	static fn (): string => ASSEMBLE_CORE_DIR . 'acf-json'
);
