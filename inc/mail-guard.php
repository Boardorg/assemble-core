<?php
/**
 * Mail guard: on every host except theassemble.com, WordPress sends email only
 * to allowlisted test addresses.
 *
 * The beta sends real email (production's SendGrid), and it is on the open web,
 * so a sign-up form there would mail whatever address anyone types. The
 * allowlist is the option `assemble_mail_allowlist` (addresses, one per line or
 * an array), set per site and never in a repo; "name@example.com" also allows
 * "name+anything@example.com". Everything else is dropped and noted in
 * `assemble_mail_held` (recipient, subject, time; the body too on a local site,
 * so set-password links can be read there).
 *
 *   wp assemble mail held
 */

defined( 'ABSPATH' ) || exit;

const ASSEMBLE_MAIL_HELD_OPTION = 'assemble_mail_held';

/** True where the guard applies. */
function assemble_mail_guard_active(): bool {
	return ! assemble_is_production_host();
}

/**
 * The allowlist, lowercased.
 *
 * @return string[]
 */
function assemble_mail_allowlist(): array {
	$raw  = get_option( 'assemble_mail_allowlist', [] );
	$list = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );

	return array_values( array_filter( array_map( static fn ( $a ) => strtolower( trim( (string) $a ) ), (array) $list ) ) );
}

/** Is this address allowed? Plus-addresses of an allowed address are. */
function assemble_mail_allowed( string $address ): bool {
	if ( preg_match( '/<([^>]+)>/', $address, $m ) ) {
		$address = $m[1];
	}
	$address = strtolower( trim( $address ) );
	$base    = (string) preg_replace( '/\+[^@]*@/', '@', $address );

	$allow = assemble_mail_allowlist();

	return in_array( $address, $allow, true ) || in_array( $base, $allow, true );
}

/** Note a dropped message (the last 25). */
function assemble_mail_hold( string $to, string $subject, string $message ): void {
	$held   = (array) get_option( ASSEMBLE_MAIL_HELD_OPTION, [] );
	$entry  = [
		'time'    => gmdate( 'c' ),
		'to'      => $to,
		'subject' => $subject,
	];
	if ( 'local' === wp_get_environment_type() ) {
		$entry['message'] = $message;
	}
	array_unshift( $held, $entry );
	update_option( ASSEMBLE_MAIL_HELD_OPTION, array_slice( $held, 0, 25 ), false );
}

/*
 * Drop recipients that aren't allowed (To, Cc and Bcc), and stop the send if
 * none are left.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short, array $atts ) {
		if ( null !== $short || ! assemble_mail_guard_active() ) {
			return $short;
		}

		$to      = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
		$allowed = array_values( array_filter( array_map( 'trim', $to ), 'assemble_mail_allowed' ) );
		$headers = is_array( $atts['headers'] ) ? $atts['headers'] : explode( "\n", str_replace( "\r\n", "\n", (string) $atts['headers'] ) );
		$has_cc  = (bool) preg_grep( '/^\s*(cc|bcc)\s*:/i', $headers );

		if ( $allowed === array_values( array_filter( array_map( 'trim', $to ) ) ) && ! $has_cc ) {
			return null; // Every recipient is allowed: send as normal.
		}

		$dropped = array_diff( array_map( 'trim', $to ), $allowed );
		if ( $dropped || $has_cc ) {
			assemble_mail_hold( implode( ', ', $dropped ) . ( $has_cc ? ' (+Cc/Bcc)' : '' ), (string) $atts['subject'], (string) $atts['message'] );
		}

		if ( ! $allowed ) {
			return false;
		}

		// Resend to the allowed recipients only, without Cc/Bcc.
		$atts['to']      = $allowed;
		$atts['headers'] = array_values( array_filter( $headers, static fn ( $h ) => ! preg_match( '/^\s*(cc|bcc)\s*:/i', (string) $h ) ) );

		return wp_mail( $atts['to'], $atts['subject'], $atts['message'], $atts['headers'], $atts['attachments'] );
	},
	10,
	2
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'assemble mail held',
		static function (): void {
			$held = (array) get_option( ASSEMBLE_MAIL_HELD_OPTION, [] );
			if ( ! $held ) {
				WP_CLI::success( 'No held mail.' );
				return;
			}
			WP_CLI\Utils\format_items( 'table', $held, array_keys( $held[0] ) );
		},
		[ 'shortdesc' => 'List mail the guard dropped on this (non-production) site.' ]
	);
}
