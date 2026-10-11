<?php
/**
 * The account forms' behaviour: Create account, Sign in (with lost and reset
 * password), the Account page, and sign-out. The theme renders the pages and
 * reads assemble_account_state(); everything that decides anything is here.
 *
 * Each form posts back to its own page with an `assemble_account` action field.
 * A success redirects (post, redirect, get); a failure renders the same page
 * with the errors and the values typed. Every account page is uncacheable.
 *
 * Sign-in goes through wp_signon(), so `authenticate` filters (where an SSO
 * plugin normally hooks) stay in play.
 */

defined( 'ABSPATH' ) || exit;

/** What the current account page should show. Filled by the handlers below. */
$GLOBALS['assemble_account_state'] = [];

/**
 * The state of the current account page, for the theme.
 *
 * @return array{kind:string,mode:string,errors:array<string,string>,values:array<string,mixed>,notice:string,redirect_to:string,reset:array<string,string>}
 */
function assemble_account_state(): array {
	$kind = assemble_account_page_kind();

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display state.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	$mode   = match ( $kind ) {
		'login'    => in_array( $action, [ 'lostpassword', 'rp' ], true ) ? $action : 'signin',
		'register' => 'register',
		'account'  => 'account',
		default    => '',
	};
	$notice = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
	// phpcs:enable

	$defaults = [
		'kind'        => $kind,
		'mode'        => $mode,
		'errors'      => [],
		'values'      => 'account' === $kind && is_user_logged_in() ? assemble_account_profile( get_current_user_id() ) : [],
		'notice'      => $notice,
		'redirect_to' => assemble_account_redirect_param(),
		'reset'       => [],
	];

	return array_merge( $defaults, (array) $GLOBALS['assemble_account_state'] );
}

/**
 * Readable text for an error or notice code. The theme prints these as is.
 */
function assemble_account_message( string $code ): string {
	$messages = [
		'email_empty'       => 'Enter your email address.',
		'email_invalid'     => 'Enter an email address like name@company.com.',
		'email_exists'      => 'An account already uses this email. Sign in, or reset your password.',
		'name_empty'        => 'Enter your full name.',
		'company_empty'     => 'Enter your company.',
		'create_failed'     => 'We couldn’t create your account. Please try again.',
		'bad_credentials'   => 'That email and password don’t match an account.',
		'password_short'    => 'Use at least 10 characters.',
		'reset_invalid'     => 'This password link has expired or was already used. Ask for a new one below.',
		'expired'           => 'The form expired. Please try again.',
		'created'           => 'Your account is ready. We’ve emailed you a link to set your password.',
		'saved'             => 'Your changes are saved.',
		'lost_sent'         => 'If an account uses that email, we’ve sent it a link to choose a new password.',
		'password_set'      => 'Your new password is set.',
		'signed_out'        => 'You’re signed out.',
	];

	/**
	 * Filter the account messages.
	 *
	 * @param array<string,string> $messages
	 */
	$messages = (array) apply_filters( 'assemble/account/messages', $messages );

	return (string) ( $messages[ $code ] ?? '' );
}

/** A safe, same-site redirect_to from the request, or ''. */
function assemble_account_redirect_param(): string {
	// phpcs:ignore WordPress.Security.NonceVerification -- a redirect target, validated below.
	$raw = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : '';

	return '' !== $raw ? (string) wp_validate_redirect( esc_url_raw( $raw ), '' ) : '';
}

/** Redirect and stop. */
function assemble_account_go( string $url ): void {
	wp_safe_redirect( $url );
	exit;
}

/*
 * Before the page renders: no caching, page access rules, and the form handlers.
 * Priority 1, after the redirect map (0).
 */
add_action(
	'template_redirect',
	static function (): void {
		$kind = assemble_account_page_kind();
		if ( '' === $kind ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- each handler checks its own nonce.
		$action = isset( $_POST['assemble_account'] ) ? sanitize_key( wp_unslash( $_POST['assemble_account'] ) ) : '';
		if ( '' !== $action && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			assemble_account_handle( $kind, $action );
		}

		$logged_in = is_user_logged_in();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		$get_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'account' === $kind && ! $logged_in ) {
			assemble_account_go( assemble_account_url( 'login', assemble_account_url( 'account' ) ) );
		}
		if ( $logged_in && ( 'register' === $kind || ( 'login' === $kind && 'rp' !== $get_action ) ) ) {
			assemble_account_go( assemble_account_redirect_param() ?: assemble_account_url( 'account' ) );
		}
		if ( 'login' === $kind && 'rp' === $get_action && empty( $GLOBALS['assemble_account_state'] ) ) {
			assemble_account_prepare_reset();
		}
	},
	1
);

/** Route a posted form. */
function assemble_account_handle( string $kind, string $action ): void {
	switch ( $kind . ':' . $action ) {
		case 'register:create':
			assemble_account_handle_create();
			break;
		case 'login:signin':
			assemble_account_handle_signin();
			break;
		case 'login:lostpassword':
			assemble_account_handle_lostpassword();
			break;
		case 'login:resetpass':
			assemble_account_handle_resetpass();
			break;
		case 'account:save':
			assemble_account_handle_save();
			break;
		case 'account:signout':
			if ( is_user_logged_in() && wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'log-out' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nonce.
				wp_logout();
				assemble_account_go( add_query_arg( 'done', 'signed_out', assemble_account_url( 'login' ) ) );
			}
			break;
	}
}

/**
 * The fields the Create account and Account forms post.
 *
 * @return array<string,mixed>
 */
function assemble_account_posted_fields(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
	$text = static fn ( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	$list = static fn ( string $key ): array => isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) ) : [];

	$fields = [
		'email'       => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '',
		// "full_name", not "name": WordPress treats a posted "name" as its own query variable (a 404).
		'name'        => $text( 'full_name' ),
		'company'     => $text( 'company' ),
		'job_title'   => $text( 'job_title' ),
		'areas'       => $list( 'areas' ),
		'communities' => $list( 'communities' ),
	];
	// phpcs:enable

	return $fields;
}

function assemble_account_handle_create(): void {
	$fields = assemble_account_posted_fields();

	if ( ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'assemble_account_create' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nonce.
		$GLOBALS['assemble_account_state'] = [ 'errors' => [ 'form' => 'expired' ], 'values' => $fields ];
		return;
	}
	// The honeypot: people never see this field; bots fill it.
	if ( ! empty( $_POST['website'] ) ) {
		assemble_account_go( home_url( '/' ) );
	}

	$result = assemble_account_create( $fields );
	if ( is_wp_error( $result ) ) {
		$GLOBALS['assemble_account_state'] = [ 'errors' => (array) $result->get_error_data(), 'values' => $fields ];
		return;
	}

	$user = get_userdata( $result );
	if ( $user ) {
		assemble_account_send_password_link( $user, 'welcome' );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
	}

	$next = assemble_account_redirect_param();
	assemble_account_go( $next ? $next : add_query_arg( 'done', 'created', assemble_account_url( 'account' ) ) );
}

function assemble_account_handle_signin(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- sign-in has no nonce (a cached form would carry a stale one); credentials are the check.
	$email    = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['email'] ) ) : '';
	$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.
	$remember = ! empty( $_POST['remember'] );
	// phpcs:enable

	$user = wp_signon(
		[
			'user_login'    => $email,
			'user_password' => $password,
			'remember'      => $remember,
		],
		is_ssl()
	);

	if ( is_wp_error( $user ) ) {
		$GLOBALS['assemble_account_state'] = [
			'mode'   => 'signin',
			'errors' => [ 'form' => 'bad_credentials' ],
			'values' => [ 'email' => $email ],
		];
		return;
	}

	$next = assemble_account_redirect_param();
	assemble_account_go( $next ? $next : assemble_account_url( 'account' ) );
}

function assemble_account_handle_lostpassword(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the reply is the same whatever is posted.
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
	$user  = $email ? get_user_by( 'email', $email ) : false;

	// Same answer whether or not the account exists.
	if ( $user instanceof WP_User ) {
		assemble_account_send_password_link( $user, 'reset' );
	}

	assemble_account_go( add_query_arg( 'done', 'lost_sent', assemble_account_url( is_user_logged_in() ? 'account' : 'login' ) ) );
}

/** Check the reset link in the URL and keep it for the new-password form. */
function assemble_account_prepare_reset(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the reset key is the check.
	$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : '';
	$login = isset( $_GET['login'] ) ? sanitize_user( rawurldecode( wp_unslash( (string) $_GET['login'] ) ) ) : '';
	// phpcs:enable

	$user = check_password_reset_key( $key, $login );
	$GLOBALS['assemble_account_state'] = is_wp_error( $user )
		? [ 'mode' => 'lostpassword', 'errors' => [ 'form' => 'reset_invalid' ] ]
		: [ 'mode' => 'rp', 'reset' => compact( 'key', 'login' ) ];
}

function assemble_account_handle_resetpass(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the reset key is the check.
	$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';
	$login    = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( (string) $_POST['login'] ) ) : '';
	$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.
	// phpcs:enable

	$user = check_password_reset_key( $key, $login );
	if ( is_wp_error( $user ) ) {
		$GLOBALS['assemble_account_state'] = [ 'mode' => 'lostpassword', 'errors' => [ 'form' => 'reset_invalid' ] ];
		return;
	}
	if ( mb_strlen( $password ) < 10 ) {
		$GLOBALS['assemble_account_state'] = [
			'mode'   => 'rp',
			'reset'  => compact( 'key', 'login' ),
			'errors' => [ 'password' => 'password_short' ],
		];
		return;
	}

	reset_password( $user, $password );
	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, false );

	assemble_account_go( add_query_arg( 'done', 'password_set', assemble_account_url( 'account' ) ) );
}

function assemble_account_handle_save(): void {
	$fields = assemble_account_posted_fields();

	if ( ! is_user_logged_in() ) {
		assemble_account_go( assemble_account_url( 'login', assemble_account_url( 'account' ) ) );
	}
	if ( ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'assemble_account_save' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nonce.
		$GLOBALS['assemble_account_state'] = [ 'errors' => [ 'form' => 'expired' ], 'values' => $fields ];
		return;
	}

	$user_id = get_current_user_id();
	$errors  = assemble_account_save_profile( $user_id, $fields );
	if ( $errors ) {
		$fields['email']                   = (string) wp_get_current_user()->user_email;
		$GLOBALS['assemble_account_state'] = [ 'errors' => $errors, 'values' => $fields ];
		return;
	}

	assemble_account_go( add_query_arg( 'done', 'saved', assemble_account_url( 'account' ) ) );
}

/*
 * /logout/: signs out when the link carries a valid nonce (WordPress's own
 * logout links do, through the logout_url filter); otherwise the Account page
 * offers a Sign out button. Never a cached redirect.
 */
add_action(
	'template_redirect',
	static function (): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
		$path = strtolower( untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) ) );
		if ( '/logout' !== $path ) {
			return;
		}

		nocache_headers();
		if ( ! is_user_logged_in() ) {
			assemble_account_go( home_url( '/' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nonce.
		if ( wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'log-out' ) ) {
			wp_logout();
			assemble_account_go( assemble_account_redirect_param() ?: add_query_arg( 'done', 'signed_out', assemble_account_url( 'login' ) ) );
		}
		assemble_account_go( assemble_account_url( 'account' ) );
	},
	0
);
