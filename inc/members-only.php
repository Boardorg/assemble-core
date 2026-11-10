<?php
/**
 * Members-only pieces stay off the public site (Cale, 2026-11-10).
 *
 * Even a title is sensitive. A Field Report or playbook whose `availableTo`
 * shares no audience with the visitor is left out of every front-end query:
 * the homepage, Insights, Community and topic lists, related stories, search,
 * RSS, oEmbed and its own URL (which 404s). No locked card, no teaser.
 *
 * Logged-out visitors and free accounts have only `Public`, so they see Public
 * pieces only. Admins, the feed's gate bypass and (after SSO) members see what
 * their audiences allow, through the feed's own AC_Audience::current_audiences().
 * Yoast's sitemaps and its REST head endpoint use the Public view, whoever asks.
 *
 * Not applied in wp-admin, WP-CLI, cron, or the feed's own REST routes (the
 * Contentful webhook), so the sync still finds every post it owns.
 *
 * Replaces the earlier rule that listing gated reports as cards was
 * deliberate (BUILD-INSTRUCTIONS §6.2, decision #6).
 */

defined( 'ABSPATH' ) || exit;

/** Post types whose pieces carry `availableTo`. */
const ASSEMBLE_GATED_POST_TYPES = [ 'field_report', 'playbook' ];

/** The feed's audiences meta key. */
const ASSEMBLE_META_AVAILABLE_TO = '_ac_available_to';

/**
 * Every gated piece and its audiences, any status. Cached until any post or
 * post meta changes (core bumps the `posts` last-changed key for both).
 *
 * @return array<int,string[]> Post ID => audience names.
 */
function assemble_piece_audiences(): array {
	global $wpdb;

	$key = 'piece_audiences:' . wp_cache_get_last_changed( 'posts' );
	$map = wp_cache_get( $key, 'assemble' );
	if ( is_array( $map ) ) {
		return $map;
	}

	$types = ASSEMBLE_GATED_POST_TYPES;
	$marks = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cached above; a WP_Query here would re-enter pre_get_posts.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $marks is only placeholders.
			"SELECT p.ID, m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type IN ($marks)",
			array_merge( [ ASSEMBLE_META_AVAILABLE_TO ], $types )
		)
	);

	$map = [];
	foreach ( (array) $rows as $row ) {
		$audiences = maybe_unserialize( (string) $row->meta_value );
		// No audiences at all fails closed: the piece is hidden from everyone but admins.
		$map[ (int) $row->ID ] = is_array( $audiences ) ? array_values( array_map( 'strval', $audiences ) ) : [];
	}

	wp_cache_set( $key, $map, 'assemble' );

	return $map;
}

/**
 * The current visitor's audiences, as the feed's gate sees them.
 *
 * @return string[]
 */
function assemble_visitor_audiences(): array {
	return class_exists( 'AC_Audience' ) ? AC_Audience::current_audiences() : [ 'Public' ];
}

/**
 * IDs of gated pieces these audiences may not see.
 *
 * @param string[]|null $audiences Defaults to the current visitor's.
 * @return int[]
 */
function assemble_hidden_piece_ids( ?array $audiences = null ): array {
	$audiences = $audiences ?? assemble_visitor_audiences();

	$hidden = [];
	foreach ( assemble_piece_audiences() as $id => $allowed ) {
		if ( ! array_intersect( $allowed, $audiences ) ) {
			$hidden[] = $id;
		}
	}

	return $hidden;
}

/** Is this a gated piece the given audiences may not see? */
function assemble_is_hidden_piece( int $post_id, ?array $audiences = null ): bool {
	return in_array( $post_id, assemble_hidden_piece_ids( $audiences ), true );
}

/** Does the rule apply to this request? */
function assemble_members_only_applies(): bool {
	$applies = true;

	if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		$applies = false;
	} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		// The feed's own routes (the Contentful webhook) must see every post.
		$route   = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		$applies = ! str_starts_with( ltrim( $route, '/' ), 'assemble/' );
	}

	/**
	 * Filter whether members-only pieces are hidden on this request. Tests use it.
	 *
	 * @param bool $applies
	 */
	return (bool) apply_filters( 'assemble/members_only/applies', $applies );
}

// Every front-end query, including the feed's get_posts() lists and the main query.
add_action(
	'pre_get_posts',
	static function ( WP_Query $query ): void {
		static $running = false;
		if ( $running || ! assemble_members_only_applies() ) {
			return;
		}

		$running = true;
		$hidden  = assemble_hidden_piece_ids();
		$running = false;

		if ( ! $hidden ) {
			return;
		}

		// WP_Query reads `p`, then `post__in`, then `post__not_in`, and uses only
		// the first one set, so each needs its own treatment. `post__in => [0]`
		// matches nothing (a 404 on the main query).
		$p = (int) $query->get( 'p' );
		if ( $p ) {
			if ( in_array( $p, $hidden, true ) ) {
				$query->set( 'p', 0 );
				$query->set( 'post__in', [ 0 ] );
			}
			return;
		}

		$in = array_map( 'intval', (array) $query->get( 'post__in' ) );
		if ( $in ) {
			$in = array_values( array_diff( $in, $hidden ) );
			$query->set( 'post__in', $in ? $in : [ 0 ] );
			return;
		}

		$query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $hidden ) ) ) );
	},
	20
);

// Yoast's XML sitemaps: Public pieces only, whoever builds them.
add_filter(
	'wpseo_exclude_from_sitemap_by_post_ids',
	static function ( $ids ): array {
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), assemble_hidden_piece_ids( [ 'Public' ] ) ) ) );
	}
);

// Yoast's public REST head endpoint renders a URL's <title> and og:title from its own index.
add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, WP_REST_Request $request ) {
		if ( null !== $result || '/yoast/v1/get_head' !== $request->get_route() ) {
			return $result;
		}

		$path = (string) wp_parse_url( (string) $request->get_param( 'url' ), PHP_URL_PATH );
		$path = untrailingslashit( $path );
		foreach ( assemble_hidden_piece_ids() as $id ) {
			if ( untrailingslashit( (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH ) ) === $path ) {
				return new WP_Error( 'not_found', 'Not found.', [ 'status' => 404 ] );
			}
		}

		return $result;
	},
	// After ACF (10), whose rest_pre_dispatch returns nothing when its REST API is off, dropping an earlier answer.
	100,
	3
);
