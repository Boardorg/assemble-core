<?php
/**
 * Accounts (Phase 7): free accounts as ordinary WordPress users, with the seams
 * Mark's SSO plugin fills in a later phase.
 *
 *   - assemble_account_url()      every account URL, from Site Settings; filter `assemble/account_url`
 *   - assemble_account_create()   creates a user; filter `assemble/account/create` hands off to SSO
 *   - assemble_user_audiences()   membership; filter `assemble/account/audiences` (SSO, the Member Center)
 *
 * The feed's gate is never rewritten: audiences reach it only through
 * `afr_current_audiences`. Until SSO, an admin-only "test audiences" field
 * stands in, and it is ignored on theassemble.com.
 *
 * Forms and their handlers: inc/account-forms.php. The user screen:
 * inc/account-admin.php. Newsletter choices are a later phase.
 */

defined( 'ABSPATH' ) || exit;

const ASSEMBLE_META_COMPANY        = 'assemble_company';
const ASSEMBLE_META_JOB_TITLE      = 'assemble_job_title';
const ASSEMBLE_META_FOLLOW_AREAS   = 'assemble_follow_areas';
const ASSEMBLE_META_FOLLOW_GROUPS  = 'assemble_follow_communities';
const ASSEMBLE_META_TEST_AUDIENCES = 'assemble_test_audiences';

/**
 * The practice-area keys shared with Contentful (`community.practiceArea`) and
 * the theme's `data-area`. The theme owns the labels.
 *
 * @return string[]
 */
function assemble_practice_area_keys(): array {
	return [ 'human-resources', 'manufacturing', 'marketing', 'technology', 'finance' ];
}

// ------------------------------------------------------------------ URLs

/**
 * An account URL. Sign in, create account and account come from Site Settings,
 * so SSO can point them elsewhere without a deploy.
 *
 * @param string $which    login | register | account | lostpassword | logout
 * @param string $redirect Where to go afterwards (login and register only).
 */
function assemble_account_url( string $which, string $redirect = '' ): string {
	$setting = [
		'login'        => 'sign_in_url',
		'lostpassword' => 'sign_in_url',
		'register'     => 'create_account_url',
		'account'      => 'account_url',
	];

	if ( 'logout' === $which ) {
		$url = add_query_arg( '_wpnonce', wp_create_nonce( 'log-out' ), home_url( '/logout/' ) );
	} else {
		$path = (string) assemble_site_setting( $setting[ $which ] ?? 'account_url' );
		$url  = str_starts_with( $path, '/' ) ? home_url( $path ) : $path;
		if ( 'lostpassword' === $which ) {
			$url = add_query_arg( 'action', 'lostpassword', $url );
		}
		if ( '' !== $redirect && in_array( $which, [ 'login', 'register' ], true ) ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
		}
	}

	/**
	 * Filter an account URL (for SSO).
	 *
	 * @param string $url
	 * @param string $which
	 * @param string $redirect
	 */
	return (string) apply_filters( 'assemble/account_url', $url, $which, $redirect );
}

/**
 * Which account page this request is for: login, register, account, or ''.
 * Matched on the path of each Site Settings URL, so renaming a page there
 * moves its behaviour with it.
 */
function assemble_account_page_kind(): string {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
	$path = strtolower( untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) ) );

	foreach ( [ 'login' => 'sign_in_url', 'register' => 'create_account_url', 'account' => 'account_url' ] as $kind => $key ) {
		$url = (string) assemble_site_setting( $key );
		if ( ! str_starts_with( $url, '/' ) && ! str_starts_with( $url, home_url() ) ) {
			continue;
		}
		$own = strtolower( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' !== $own && $own === $path ) {
			return $kind;
		}
	}

	return '';
}

/*
 * WordPress's own links (the register link, "Lost your password?", logout)
 * land on the account pages. Sign-in redirects into wp-admin keep wp-login.php,
 * so administrators keep their two-factor step.
 */
add_filter(
	'login_url',
	static function ( string $url, string $redirect ): string {
		if ( '' !== $redirect && str_starts_with( $redirect, admin_url() ) ) {
			return $url;
		}

		return assemble_account_url( 'login', $redirect );
	},
	10,
	2
);
add_filter( 'register_url', static fn (): string => assemble_account_url( 'register' ) );
add_filter( 'lostpassword_url', static fn (): string => assemble_account_url( 'lostpassword' ) );
add_filter(
	'logout_url',
	static function ( string $url, string $redirect ): string {
		$logout = assemble_account_url( 'logout' );

		return '' !== $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $logout ) : $logout;
	},
	10,
	2
);

// ------------------------------------------------------------------ user meta

add_action(
	'init',
	static function (): void {
		$strings = [
			ASSEMBLE_META_COMPANY   => 'Company',
			ASSEMBLE_META_JOB_TITLE => 'Job title',
		];
		foreach ( $strings as $key => $label ) {
			register_meta(
				'user',
				$key,
				[
					'type'              => 'string',
					'description'       => $label,
					'single'            => true,
					'sanitize_callback' => static fn ( $value ) => mb_substr( sanitize_text_field( (string) $value ), 0, 120 ),
					'show_in_rest'      => false,
				]
			);
		}

		register_meta(
			'user',
			ASSEMBLE_META_FOLLOW_AREAS,
			[
				'type'              => 'array',
				'description'       => 'Followed practice areas',
				'single'            => true,
				'sanitize_callback' => static fn ( $value ) => assemble_clean_areas( (array) $value ),
				'show_in_rest'      => false,
			]
		);
		register_meta(
			'user',
			ASSEMBLE_META_FOLLOW_GROUPS,
			[
				'type'              => 'array',
				'description'       => 'Followed Communities (functional areas)',
				'single'            => true,
				'sanitize_callback' => static fn ( $value ) => array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) $value ) ) ) ),
				'show_in_rest'      => false,
			]
		);
		register_meta(
			'user',
			ASSEMBLE_META_TEST_AUDIENCES,
			[
				'type'              => 'array',
				'description'       => 'Test audiences (until SSO; ignored on theassemble.com)',
				'single'            => true,
				'sanitize_callback' => static fn ( $value ) => assemble_clean_audiences( (array) $value ),
				'auth_callback'     => static fn () => current_user_can( 'promote_users' ),
				'show_in_rest'      => false,
			]
		);
	}
);

/**
 * Known practice-area keys only, in the canonical order.
 *
 * @param array<int,mixed> $areas
 * @return string[]
 */
function assemble_clean_areas( array $areas ): array {
	$areas = array_map( static fn ( $a ) => sanitize_key( (string) $a ), $areas );

	return array_values( array_intersect( assemble_practice_area_keys(), $areas ) );
}

/**
 * Community slugs per practice area, from the feed's community directory.
 * Empty when the feed isn't active.
 *
 * @return array<string,string[]> Area key => slugs.
 */
function assemble_communities_by_area(): array {
	$by_area = array_fill_keys( assemble_practice_area_keys(), [] );
	if ( class_exists( 'AFR_Communities' ) ) {
		foreach ( AFR_Communities::all() as $community ) {
			$area = (string) ( $community['practice_area'] ?? '' );
			if ( isset( $by_area[ $area ] ) && ! empty( $community['slug'] ) ) {
				$by_area[ $area ][] = (string) $community['slug'];
			}
		}
	}

	return $by_area;
}

/**
 * Normalise what someone follows: known areas only, and Communities only inside
 * a followed area. A followed area may hold no Communities (Finance has none yet).
 * Without the feed, Community slugs can't be checked, so they're kept as given.
 *
 * @param array<int,mixed> $areas
 * @param array<int,mixed> $communities
 * @return array{areas:string[],communities:string[]}
 */
function assemble_normalise_follows( array $areas, array $communities ): array {
	$areas       = assemble_clean_areas( $areas );
	$communities = array_values( array_unique( array_filter( array_map( static fn ( $c ) => sanitize_title( (string) $c ), $communities ) ) ) );

	if ( class_exists( 'AFR_Communities' ) ) {
		$allowed = [];
		foreach ( assemble_communities_by_area() as $area => $slugs ) {
			if ( in_array( $area, $areas, true ) ) {
				$allowed = array_merge( $allowed, $slugs );
			}
		}
		$communities = array_values( array_intersect( $communities, $allowed ) );
	}

	return compact( 'areas', 'communities' );
}

/**
 * Someone's profile as the forms read it.
 *
 * @return array{name:string,email:string,company:string,job_title:string,areas:string[],communities:string[]}
 */
function assemble_account_profile( int $user_id ): array {
	$user = get_userdata( $user_id );

	return [
		'name'        => $user ? trim( (string) $user->display_name ) : '',
		'email'       => $user ? (string) $user->user_email : '',
		'company'     => (string) get_user_meta( $user_id, ASSEMBLE_META_COMPANY, true ),
		'job_title'   => (string) get_user_meta( $user_id, ASSEMBLE_META_JOB_TITLE, true ),
		'areas'       => (array) ( get_user_meta( $user_id, ASSEMBLE_META_FOLLOW_AREAS, true ) ?: [] ),
		'communities' => (array) ( get_user_meta( $user_id, ASSEMBLE_META_FOLLOW_GROUPS, true ) ?: [] ),
	];
}

/**
 * Save the profile fields an account holder edits (not email: that changes
 * with SSO later). Returns the list of problems, empty on success.
 *
 * @param array<string,mixed> $data name, company, job_title, areas[], communities[]
 * @return array<string,string> Field => error code.
 */
function assemble_account_save_profile( int $user_id, array $data ): array {
	$errors = assemble_account_validate( $data, false );
	if ( $errors ) {
		return $errors;
	}

	$name = assemble_account_split_name( (string) $data['name'] );
	wp_update_user(
		[
			'ID'           => $user_id,
			'display_name' => $name['full'],
			'first_name'   => $name['first'],
			'last_name'    => $name['last'],
		]
	);

	$follows = assemble_normalise_follows( (array) ( $data['areas'] ?? [] ), (array) ( $data['communities'] ?? [] ) );
	update_user_meta( $user_id, ASSEMBLE_META_COMPANY, (string) $data['company'] );
	update_user_meta( $user_id, ASSEMBLE_META_JOB_TITLE, (string) ( $data['job_title'] ?? '' ) );
	update_user_meta( $user_id, ASSEMBLE_META_FOLLOW_AREAS, $follows['areas'] );
	update_user_meta( $user_id, ASSEMBLE_META_FOLLOW_GROUPS, $follows['communities'] );

	return [];
}

/**
 * Check the form fields. Email only when creating.
 *
 * @param array<string,mixed> $data
 * @return array<string,string> Field => error code.
 */
function assemble_account_validate( array $data, bool $creating ): array {
	$errors = [];

	if ( $creating ) {
		$email = trim( (string) ( $data['email'] ?? '' ) );
		if ( '' === $email ) {
			$errors['email'] = 'email_empty';
		} elseif ( ! is_email( $email ) ) {
			$errors['email'] = 'email_invalid';
		} elseif ( email_exists( $email ) ) {
			$errors['email'] = 'email_exists';
		}
	}

	if ( '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
		$errors['name'] = 'name_empty';
	}
	if ( '' === trim( (string) ( $data['company'] ?? '' ) ) ) {
		$errors['company'] = 'company_empty';
	}

	return $errors;
}

/**
 * "Ada Lovelace" => first Ada, last Lovelace; one word is a first name.
 *
 * @return array{full:string,first:string,last:string}
 */
function assemble_account_split_name( string $name ): array {
	$full  = trim( (string) preg_replace( '/\s+/', ' ', sanitize_text_field( $name ) ) );
	$parts = explode( ' ', $full, 2 );

	return [
		'full'  => $full,
		'first' => $parts[0],
		'last'  => $parts[1] ?? '',
	];
}

/**
 * Create a free account. Returns the new user ID, or a WP_Error whose data is
 * the field => error code list.
 *
 * Until SSO, this makes an ordinary WordPress user (login = email, role
 * subscriber) with a random password; the person sets their own from the
 * emailed link. SSO can take over through `assemble/account/create`.
 *
 * @param array<string,mixed> $data email, name, company, job_title, areas[], communities[]
 * @return int|WP_Error
 */
function assemble_account_create( array $data ) {
	$errors = assemble_account_validate( $data, true );
	if ( $errors ) {
		return new WP_Error( 'invalid', 'The form has errors.', $errors );
	}

	/**
	 * Create the account somewhere else (SSO). Return a user ID or WP_Error to
	 * short-circuit local creation; null to carry on.
	 *
	 * @param int|WP_Error|null   $result
	 * @param array<string,mixed> $data
	 */
	$result = apply_filters( 'assemble/account/create', null, $data );
	if ( null !== $result ) {
		return $result;
	}

	$email = sanitize_email( (string) $data['email'] );
	$login = sanitize_user( $email, true );
	if ( '' === $login || mb_strlen( $login ) > 60 || username_exists( $login ) ) {
		$login = 'user_' . substr( md5( $email . wp_rand() ), 0, 12 );
	}

	$name    = assemble_account_split_name( (string) $data['name'] );
	$user_id = wp_insert_user(
		[
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => $name['full'],
			'first_name'   => $name['first'],
			'last_name'    => $name['last'],
			'role'         => 'subscriber',
		]
	);
	if ( is_wp_error( $user_id ) ) {
		return new WP_Error( 'invalid', $user_id->get_error_message(), [ 'email' => 'create_failed' ] );
	}

	$follows = assemble_normalise_follows( (array) ( $data['areas'] ?? [] ), (array) ( $data['communities'] ?? [] ) );
	update_user_meta( $user_id, ASSEMBLE_META_COMPANY, (string) ( $data['company'] ?? '' ) );
	update_user_meta( $user_id, ASSEMBLE_META_JOB_TITLE, (string) ( $data['job_title'] ?? '' ) );
	update_user_meta( $user_id, ASSEMBLE_META_FOLLOW_AREAS, $follows['areas'] );
	update_user_meta( $user_id, ASSEMBLE_META_FOLLOW_GROUPS, $follows['communities'] );

	/**
	 * A free account was created on this site.
	 *
	 * @param int                 $user_id
	 * @param array<string,mixed> $data
	 */
	do_action( 'assemble/account/created', $user_id, $data );

	return (int) $user_id;
}

/**
 * Email someone a link to set (welcome) or reset their password, pointing at the
 * Sign in page rather than wp-login.php.
 */
function assemble_account_send_password_link( WP_User $user, string $purpose = 'reset' ): bool {
	$key = get_password_reset_key( $user );
	if ( is_wp_error( $key ) ) {
		return false;
	}

	$link = add_query_arg(
		[
			'action' => 'rp',
			'key'    => $key,
			'login'  => rawurlencode( $user->user_login ),
		],
		assemble_account_url( 'login' )
	);

	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	if ( 'welcome' === $purpose ) {
		$subject = sprintf( 'Welcome to %s: set your password', $site );
		$body    = "Thanks for creating a free {$site} account.\n\nSet your password here so you can sign in on any device:\n{$link}\n\nThis link expires in 24 hours. If it does, use \"Forgot your password?\" on the sign-in page.";
	} else {
		$subject = sprintf( '%s: reset your password', $site );
		$body    = "Someone asked to reset the password for your {$site} account.\n\nChoose a new password here:\n{$link}\n\nIf this wasn't you, ignore this email and nothing will change.";
	}

	return wp_mail( $user->user_email, $subject, $body );
}

// ------------------------------------------------------------------ audiences

/** True where the test-audience stub may grant access: everywhere but theassemble.com. */
function assemble_test_audiences_enabled(): bool {
	return ! assemble_is_production_host();
}

/**
 * Audience names the feed knows (AFR_Settings::audiences()), or none without it.
 *
 * @return string[]
 */
function assemble_known_audiences(): array {
	return class_exists( 'AFR_Settings' ) ? array_values( (array) AFR_Settings::audiences() ) : [];
}

/**
 * Known audience names only; "Public" is implied and never stored.
 *
 * @param array<int,mixed> $audiences
 * @return string[]
 */
function assemble_clean_audiences( array $audiences ): array {
	$known = array_diff( assemble_known_audiences(), [ 'Public' ] );

	return array_values( array_intersect( $known, array_map( 'strval', $audiences ) ) );
}

/**
 * The member audiences a user belongs to. Nothing for a free account.
 *
 * Until SSO, the test stub (an admin-set user field) is the only source, and it
 * never applies on theassemble.com. SSO or the Member Center adds its answer
 * through `assemble/account/audiences`.
 *
 * @return string[]
 */
function assemble_user_audiences( int $user_id ): array {
	$audiences = [];

	if ( $user_id && assemble_test_audiences_enabled() ) {
		$audiences = (array) ( get_user_meta( $user_id, ASSEMBLE_META_TEST_AUDIENCES, true ) ?: [] );
	}

	/**
	 * Filter a user's member audiences (SSO, the Member Center).
	 *
	 * @param string[] $audiences
	 * @param int      $user_id
	 */
	$audiences = (array) apply_filters( 'assemble/account/audiences', $audiences, $user_id );

	return assemble_clean_audiences( $audiences );
}

/*
 * The one place the public site answers the feed's gate. The feed calls this
 * only for signed-out visitors and signed-in non-admins, with no bypass or
 * preview in play; it already holds "Public" plus any role-mapped audiences.
 */
add_filter(
	'afr_current_audiences',
	static function ( array $audiences ): array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $audiences;
		}

		return array_values( array_unique( array_merge( $audiences, assemble_user_audiences( $user_id ) ) ) );
	}
);

// ------------------------------------------------------------------ front-end only

// Free accounts never see the admin bar, and land on the Account page, not wp-admin.
add_filter(
	'show_admin_bar',
	static fn ( bool $show ): bool => $show && current_user_can( 'edit_posts' )
);
add_action(
	'admin_init',
	static function (): void {
		if ( wp_doing_ajax() || current_user_can( 'edit_posts' ) || ! is_user_logged_in() ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
		if ( 'admin-post.php' === $script ) {
			return;
		}
		wp_safe_redirect( assemble_account_url( 'account' ) );
		exit;
	}
);
