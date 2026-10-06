<?php
/**
 * Which site is this request on?
 *
 * Everything that must differ between production and every other copy of the
 * site keys off the request host, never a setting: a setting can be forgotten
 * at launch or carried over by a database copy, the host can't.
 */

defined( 'ABSPATH' ) || exit;

const ASSEMBLE_PRODUCTION_HOST = 'theassemble.com';

/**
 * The request host, lowercased, without a port. Falls back to the site URL's
 * host for WP-CLI and cron, which have no HTTP_HOST.
 */
function assemble_request_host(): string {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

	if ( '' === $host ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	return strtolower( (string) preg_replace( '/:\d+$/', '', $host ) );
}

/**
 * True only on the production domain, exactly.
 */
function assemble_is_production_host(): bool {
	return ASSEMBLE_PRODUCTION_HOST === assemble_request_host();
}
