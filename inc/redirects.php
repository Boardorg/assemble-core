<?php
/**
 * Redirects from today's theassemble.com URLs (BUILD-INSTRUCTIONS §9.5).
 *
 * The launch is a fresh start, so every old URL is rebuilt, redirected or
 * deliberately dropped. This map is the "redirected" part: 301s, reviewed and
 * versioned here, so it travels with the launch copy. Paths are matched without
 * a trailing slash and case-insensitively; the query string is passed on.
 *
 * Not here on purpose: the benchmark pages (decision #10), except /my-report/,
 * and /login/, /register/ and /account/, which Phase 7 rebuilt at the same URLs.
 * /logout/ is a handler (inc/account-forms.php), not a redirect.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Old path => new site-relative path or absolute URL.
 *
 * @return array<string,string>
 */
function assemble_redirect_map(): array {
	$map = [
		// Replaced by the homepage explorer and /summits/.
		'/our-product'                                                 => '/',
		// The CEO bio now lives with the rest of the team.
		'/aaron-kissel-assemble-ceo'                                   => '/our-team/aaron-kissel/',
		// Newsletter and Field Notes sign-up pages.
		'/insider'                                                     => '/field-reports/',
		'/fieldnotes-socialmedia'                                      => '/field-reports/community/socialmedia-org/',
		'/fieldnotes-supplychain'                                      => '/field-reports/',
		// A webinar page (October 2026).
		'/how-mgm-resorts-is-thinking-about-digital-marketing-in-2027' => '/field-reports/',
		// Prototype leftovers.
		'/members-2'                                                   => '/',
		'/user-2'                                                      => '/',
		'/account-2'                                                   => '/',
		'/redirection-for-non-registered-users'                        => '/',
		// Ultimate Member pages (Phase 7): the new account flow.
		'/password-reset'                                              => assemble_account_url( 'lostpassword' ),
		'/account-confirmation'                                        => assemble_account_url( 'account' ),
		'/user'                                                        => assemble_account_url( 'account' ),
		'/members'                                                     => assemble_account_url( 'account' ),
		'/networking-profile'                                          => assemble_account_url( 'account' ),
		// The old benchmark "My report" is handled on the benchmark site now (Cale, 2026-10-11).
		'/my-report'                                                   => (string) assemble_site_setting( 'benchmark_url' ),
	];

	/**
	 * Filter the redirect map. Keys are paths without a trailing slash.
	 *
	 * @param array<string,string> $map
	 */
	return (array) apply_filters( 'assemble/redirects', $map );
}

/**
 * Where a request path should go, or '' to leave it alone.
 */
function assemble_redirect_target( string $path ): string {
	$key = strtolower( untrailingslashit( '/' . ltrim( $path, '/' ) ) );

	foreach ( assemble_redirect_map() as $from => $to ) {
		if ( strtolower( untrailingslashit( (string) $from ) ) === $key && '' !== $key ) {
			return (string) $to;
		}
	}

	return '';
}

/*
 * Before WordPress picks a template, and before canonical redirects: several old
 * pages still exist on the beta, so this fires whether or not the page does.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( is_admin() || is_preview() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared against the map.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		// Sites installed in a subdirectory: match the path below the home path.
		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$target = assemble_redirect_target( $path );
		if ( '' === $target ) {
			return;
		}

		$url   = str_starts_with( $target, '/' ) ? home_url( $target ) : $target;
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $query ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $query;
		}

		wp_safe_redirect( $url, 301, 'Assemble Core' );
		exit;
	},
	0
);

// Redirect targets on other hosts (none today) must be allowed explicitly.
add_filter(
	'allowed_redirect_hosts',
	static function ( array $hosts ): array {
		foreach ( assemble_redirect_map() as $to ) {
			$host = wp_parse_url( (string) $to, PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = $host;
			}
		}

		return array_unique( $hosts );
	}
);
