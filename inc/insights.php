<?php
/**
 * Insights URLs on the public site (Kristen's review, Cale 2026-11-06).
 *
 * - The Field Reports archive moves from /field-reports/ to /insights/ (reports
 *   at /insights/<slug>/, Communities at /insights/community/<slug>/). The feed
 *   keeps its `field_report` post type and taxonomy; only this site's slugs
 *   change, through the feed's own `ac_content_types` filter, so the Member
 *   Center's copy of the feed is untouched. Old /field-reports/ URLs 301 here.
 * - A filtered page for each practice area: /insights/area/<area>/.
 * - A page for each topic: /insights/topic/<slug>/ (topic slugs are the feed's
 *   `_ac_topic` meta, one row per topic).
 *
 * The theme renders both through archive-field_report.php and finds the URLs
 * through the `assemble/area_url` and `assemble/topic_url` filters.
 */

defined( 'ABSPATH' ) || exit;

const ASSEMBLE_INSIGHTS_SLUG = 'insights';
const ASSEMBLE_INSIGHTS_OLD  = 'field-reports';

/** Rewrite rules changed in this version: flush once after a deploy. */
const ASSEMBLE_INSIGHTS_RULES_VERSION = '1';

/**
 * Practice-area URL slugs by area key (the `data-area` keys). Slugs follow the
 * official names (Cale, 2026-10-26).
 *
 * @return array<string,string> area key => URL slug.
 */
function assemble_insights_area_slugs(): array {
	return [
		'human-resources' => 'human-resources',
		'manufacturing'   => 'supply-chain-manufacturing',
		'marketing'       => 'marketing-communications',
		'technology'      => 'data-technology',
		'finance'         => 'finance-legal',
	];
}

/** The area key for a URL slug, or ''. */
function assemble_insights_area_from_slug( string $slug ): string {
	$key = array_search( $slug, assemble_insights_area_slugs(), true );

	return false === $key ? '' : (string) $key;
}

/** The practice area or topic this request filters by, or ''. */
function assemble_insights_area(): string {
	return assemble_insights_area_from_slug( (string) get_query_var( 'assemble_area' ) );
}

function assemble_insights_topic(): string {
	return sanitize_title( (string) get_query_var( 'assemble_topic' ) );
}

/** /insights/ for the field_report type on this site. */
add_filter(
	'ac_content_types',
	static function ( array $types ): array {
		if ( isset( $types['fieldReport'] ) && is_array( $types['fieldReport'] ) ) {
			$types['fieldReport']['slug'] = ASSEMBLE_INSIGHTS_SLUG;
		}

		return $types;
	}
);

/** /insights/community/<slug>/ for the Community archives. */
add_filter(
	'register_taxonomy_args',
	static function ( array $args, string $taxonomy ): array {
		if ( 'field_report_community' === $taxonomy ) {
			$args['rewrite'] = [
				'slug'       => ASSEMBLE_INSIGHTS_SLUG . '/community',
				'with_front' => false,
			];
		}

		return $args;
	},
	10,
	2
);

/** Area and topic pages, ahead of the post type's own rules. */
add_action(
	'init',
	static function (): void {
		$base = ASSEMBLE_INSIGHTS_SLUG;
		$args = 'index.php?post_type=field_report&%s=$matches[1]';

		foreach ( [ 'area' => 'assemble_area', 'topic' => 'assemble_topic' ] as $segment => $var ) {
			add_rewrite_rule( "^{$base}/{$segment}/([^/]+)/page/([0-9]+)/?$", sprintf( $args, $var ) . '&paged=$matches[2]', 'top' );
			add_rewrite_rule( "^{$base}/{$segment}/([^/]+)/?$", sprintf( $args, $var ), 'top' );
		}

		if ( get_option( 'assemble_insights_rules' ) !== ASSEMBLE_INSIGHTS_RULES_VERSION ) {
			// Late, so every post type and taxonomy has registered its rules first.
			add_action(
				'wp_loaded',
				static function (): void {
					flush_rewrite_rules( false );
					update_option( 'assemble_insights_rules', ASSEMBLE_INSIGHTS_RULES_VERSION, true );
				}
			);
		}
	},
	20
);

add_filter(
	'query_vars',
	static function ( array $vars ): array {
		$vars[] = 'assemble_area';
		$vars[] = 'assemble_topic';

		return $vars;
	}
);

/** Narrow the archive to the area's Communities, or to the topic. */
add_action(
	'pre_get_posts',
	static function ( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'field_report' ) ) {
			return;
		}

		$area  = assemble_insights_area_from_slug( (string) $query->get( 'assemble_area' ) );
		$topic = sanitize_title( (string) $query->get( 'assemble_topic' ) );

		if ( '' !== (string) $query->get( 'assemble_area' ) ) {
			$slugs = '' !== $area && class_exists( 'AC_Communities' ) ? wp_list_pluck( AC_Communities::in_area( $area ), 'slug' ) : [];
			$query->set( 'posts_per_page', 12 );
			$query->set(
				'tax_query',
				[
					[
						'taxonomy' => 'field_report_community',
						'field'    => 'slug',
						// An unknown area, or one with no Communities, lists nothing.
						'terms'    => $slugs ? $slugs : [ '-' ],
					],
				]
			);
		} elseif ( '' !== $topic ) {
			$query->set( 'posts_per_page', 12 );
			$query->set(
				'meta_query',
				[
					[
						'key'   => '_ac_topic',
						'value' => $topic,
					],
				]
			);
		}
	}
);

/** An unknown area, or a topic no report carries, is a 404. */
add_action(
	'template_redirect',
	static function (): void {
		if ( ! is_post_type_archive( 'field_report' ) ) {
			return;
		}

		$unknown_area  = '' !== (string) get_query_var( 'assemble_area' ) && '' === assemble_insights_area();
		$unknown_topic = '' !== assemble_insights_topic() && '' === assemble_insights_topic_name( assemble_insights_topic() );

		if ( $unknown_area || $unknown_topic ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	},
	5
);

/**
 * A topic's name from the first published report that carries it, or ''.
 */
function assemble_insights_topic_name( string $slug ): string {
	if ( '' === $slug || ! class_exists( 'AC_Query' ) ) {
		return '';
	}

	$ids = get_posts(
		[
			'post_type'        => 'field_report',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key/value pair.
				[
					'key'   => '_ac_topic',
					'value' => $slug,
				],
			],
		]
	);

	foreach ( $ids ? AC_Query::topics( (int) $ids[0] ) : [] as $topic ) {
		if ( $topic['slug'] === $slug ) {
			return (string) $topic['name'];
		}
	}

	return '';
}

/** The theme's practice-area links (breadcrumbs, explorer) go to the area page. */
add_filter(
	'assemble/area_url',
	static function ( string $url, string $area ): string {
		$slug = assemble_insights_area_slugs()[ $area ] ?? '';

		return '' !== $slug ? home_url( '/' . ASSEMBLE_INSIGHTS_SLUG . '/area/' . $slug . '/' ) : $url;
	},
	10,
	2
);

/** Topic tags link to the topic page. */
add_filter(
	'assemble/topic_url',
	static function ( string $url, string $slug ): string {
		return '' !== $slug ? home_url( '/' . ASSEMBLE_INSIGHTS_SLUG . '/topic/' . sanitize_title( $slug ) . '/' ) : $url;
	},
	10,
	2
);

/**
 * /field-reports/… → /insights/…, keeping the rest of the path and the query
 * string. Runs before the redirect map and before canonical redirects.
 */
add_action(
	'template_redirect',
	static function (): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched, then rebuilt below.

		if ( ! preg_match( '#^/' . ASSEMBLE_INSIGHTS_OLD . '(/.*)?$#i', $path, $m ) ) {
			return;
		}

		$query = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through esc_url_raw below.
		$to    = home_url( '/' . ASSEMBLE_INSIGHTS_SLUG . ( $m[1] ?? '/' ) );

		wp_safe_redirect( esc_url_raw( '' !== $query ? $to . '?' . $query : $to ), 301 );
		exit;
	},
	0
);
