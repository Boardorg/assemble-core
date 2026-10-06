<?php
/**
 * Keep every non-production copy of the site out of search engines.
 *
 * On any host other than theassemble.com: an X-Robots-Tag header on every
 * front-end response (pages, feeds, REST), a robots meta tag, and a robots.txt
 * that disallows everything. This covers the WP Engine beta and any custom
 * staging domain added later, which WP Engine's own robots block does not.
 */

defined( 'ABSPATH' ) || exit;

if ( ! assemble_is_production_host() ) {
	add_action(
		'send_headers',
		static function (): void {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	);

	add_filter(
		'wp_robots',
		static function ( array $robots ): array {
			unset( $robots['index'], $robots['follow'] );
			$robots['noindex']  = true;
			$robots['nofollow'] = true;

			return $robots;
		},
		PHP_INT_MAX
	);

	add_filter(
		'robots_txt',
		static function (): string {
			return "User-agent: *\nDisallow: /\n";
		},
		PHP_INT_MAX
	);
}
