<?php
/**
 * Community sales pages (/communities/…): ordinary WordPress pages on the
 * theme's "Community page" template, with their copy in Secure Custom Fields
 * (hub COMMUNITY-PAGES-PROPOSAL.md §5).
 *
 * Two field groups, both JSON in acf-json/:
 * - "Community page" on pages using that template: the page type, the
 *   Community it sells, and the Board's own copy.
 * - "Community defaults", an options page under Site Settings: the copy every
 *   Board page shares, with {board}, {field} and {a_field} filled in per page.
 *
 * The Community itself (name, logo, practice area) comes from the Assemble
 * Content feed's directory, so it's never typed twice. This file answers the
 * theme's `assemble/community_page` filter with the page's view model.
 *
 * Code holds only the structural defaults (section labels, the button). The
 * shared copy is content: it lives in the options page, so a public repo never
 * carries unapproved marketing copy.
 */

defined( 'ABSPATH' ) || exit;

/** The theme template these pages use. */
const ASSEMBLE_COMMUNITY_TEMPLATE = 'page-community.php';

/**
 * Page types, stored value => label.
 *
 * @return array<string,string>
 */
function assemble_community_page_types(): array {
	return [
		'board'   => 'Board',
		'council' => 'Council',
		'area'    => 'Practice area',
		'index'   => 'Communities index',
	];
}

/**
 * Structural defaults: used when the matching Community defaults field is empty.
 *
 * @return array<string,string>
 */
function assemble_community_defaults(): array {
	return [
		'impact_eyebrow'     => 'Impact at three levels',
		'included_eyebrow'   => 'What’s included',
		'fit_eyebrow'        => 'Is this you?',
		'company_eyebrow'    => 'You’re in good company',
		'challenges_heading' => 'The challenges our {field} members are working through now',
		'apply_label'        => 'Apply to join the {board}',
	];
}

/**
 * One Community defaults value: the options page's, else the code default.
 *
 * @return mixed
 */
function assemble_community_default( string $key ) {
	$value = function_exists( 'get_field' ) ? get_field( 'cd_' . $key, 'option' ) : null;

	if ( null === $value || '' === $value || false === $value || [] === $value ) {
		$value = assemble_community_defaults()[ $key ] ?? '';
	}

	return $value;
}

/** A field from a page, trimmed when it's text. */
function assemble_community_field( string $name, int $post_id ) {
	$value = function_exists( 'get_field' ) ? get_field( $name, $post_id ) : null;

	return is_string( $value ) ? trim( $value ) : $value;
}

/**
 * An image field (attachment ID) as [ url, alt ], or null.
 *
 * @return array{url:string,alt:string}|null
 */
function assemble_community_image( $id, string $size = 'large' ): ?array {
	$id  = (int) $id;
	$url = $id ? wp_get_attachment_image_url( $id, $size ) : false;

	return $url ? [
		'url' => (string) $url,
		'alt' => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
	] : null;
}

/**
 * A Board's logo: the Community entry's in Contentful, else the shared image
 * library's file (wp-content/assets/boards/<slug>-logo-color.png), else null.
 *
 * @return array{url:string,alt:string}|null
 */
function assemble_community_logo( array $community ): ?array {
	$name = (string) ( $community['name'] ?? '' );

	if ( ! empty( $community['logo'] ) && class_exists( 'AC_Contentful' ) ) {
		$url = AC_Contentful::asset_url( is_array( $community['logo'] ) ? $community['logo'] : null );
		if ( '' !== $url ) {
			return [ 'url' => $url, 'alt' => $name ];
		}
	}

	$file = 'assets/boards/' . sanitize_file_name( (string) ( $community['slug'] ?? '' ) ) . '-logo-color.png';
	if ( '' !== (string) ( $community['slug'] ?? '' ) && is_readable( WP_CONTENT_DIR . '/' . $file ) ) {
		return [ 'url' => content_url( $file ), 'alt' => $name ];
	}

	return null;
}

/** Split text into paragraphs at blank lines. */
function assemble_community_paragraphs( string $text ): array {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\R\s*\R/', $text ) ?: [] ) ) );
}

/** Split a one-per-line field into items. */
function assemble_community_lines( string $text ): array {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $text ) ?: [] ) ) );
}

/**
 * Impact rows (repeater) in the view shape.
 *
 * @return array<int,array{level:string,title:string,body:string}>
 */
function assemble_community_impact_rows( $rows ): array {
	$items = [];
	foreach ( (array) $rows as $row ) {
		if ( '' !== trim( (string) ( $row['title'] ?? '' ) ) ) {
			$items[] = [
				'level' => (string) ( $row['level'] ?? '' ),
				'title' => trim( (string) $row['title'] ),
				'body'  => trim( (string) ( $row['body'] ?? '' ) ),
			];
		}
	}

	return $items;
}

/**
 * Pillar rows (repeater) in the view shape: tags, or details of [ name, description ].
 *
 * @return array<int,array<string,mixed>>
 */
function assemble_community_pillar_rows( $rows ): array {
	$items = [];
	foreach ( (array) $rows as $row ) {
		$title = trim( (string) ( $row['title'] ?? '' ) );
		if ( '' === $title ) {
			continue;
		}

		$details = [];
		foreach ( (array) ( $row['details'] ?? [] ) as $detail ) {
			$name = trim( (string) ( $detail['name'] ?? '' ) );
			if ( '' !== $name ) {
				$details[] = [ $name, trim( (string) ( $detail['description'] ?? '' ) ) ];
			}
		}

		$items[] = [
			'title'   => $title,
			'body'    => trim( (string) ( $row['body'] ?? '' ) ),
			'tags_in' => trim( (string) ( $row['tags_intro'] ?? '' ) ),
			'tags'    => assemble_community_lines( (string) ( $row['tags'] ?? '' ) ),
			'details' => $details,
			'after'   => (int) ( $row['after'] ?? 0 ),
		];
	}

	return $items;
}

/**
 * Requirement rows (repeater) grouped as the view's two cards.
 *
 * @return array<int,array{label:string,items:array<int,array{0:string,1:string}>}>
 */
function assemble_community_requirement_groups( $rows ): array {
	$groups = [
		'individual' => [ 'label' => 'Individual requirements', 'items' => [] ],
		'company'    => [ 'label' => 'Company requirements', 'items' => [] ],
	];

	foreach ( (array) $rows as $row ) {
		$group = 'company' === ( $row['group'] ?? '' ) ? 'company' : 'individual';
		$title = trim( (string) ( $row['title'] ?? '' ) );
		if ( '' !== $title ) {
			$groups[ $group ]['items'][] = [ $title, trim( (string) ( $row['description'] ?? '' ) ) ];
		}
	}

	return array_values( array_filter( $groups, static fn ( $g ) => [] !== $g['items'] ) );
}

/**
 * The shared pillars with a page's own added: each own pillar goes after the
 * shared pillar its "after" names (0 or out of range = before the last shared one).
 *
 * @return array<int,array<string,mixed>>
 */
function assemble_community_merge_pillars( array $shared, array $own ): array {
	$pillars = $shared;
	$offset  = 0;

	foreach ( $own as $pillar ) {
		$after = (int) $pillar['after'];
		$at    = $after >= 1 && $after <= count( $shared ) ? $after : max( count( $shared ) - 1, 0 );
		array_splice( $pillars, $at + $offset, 0, [ $pillar ] );
		++$offset;
	}

	return array_map(
		static function ( array $p ): array {
			unset( $p['after'] );
			return $p;
		},
		$pillars
	);
}

/**
 * Upcoming Working Session rows: [ date (Y-m-d H:i:s), title, body ].
 *
 * @return array<int,array{date:string,title:string,body:string}>
 */
function assemble_community_session_rows( $rows ): array {
	$sessions = [];
	foreach ( (array) $rows as $row ) {
		$title = trim( (string) ( $row['title'] ?? '' ) );
		$date  = trim( (string) ( $row['date'] ?? '' ) );
		if ( '' !== $title && '' !== $date ) {
			$sessions[] = [
				'date'  => $date,
				'title' => $title,
				'body'  => trim( (string) ( $row['description'] ?? '' ) ),
			];
		}
	}

	return $sessions;
}

/**
 * The view model for a Community page, or null when the page isn't one (or
 * names no Community the directory knows).
 *
 * Shape (what the theme's page-community.php reads): kind, name, field, a_field,
 * community_slug, area, logo, one_liner, hero, members, problem, challenges,
 * sessions, impact, included, fit, company, closing. The index adds board,
 * points and finder (assemble_community_index_view()). Copy may still hold
 * {board}, {field} and {a_field}; the theme fills them in.
 */
function assemble_community_page_view( WP_Post $post ): ?array {
	if ( 'page' !== $post->post_type || ASSEMBLE_COMMUNITY_TEMPLATE !== get_page_template_slug( $post ) || ! function_exists( 'get_field' ) ) {
		return null;
	}

	$id   = $post->ID;
	$kind = (string) assemble_community_field( 'community_type', $id );
	if ( ! isset( assemble_community_page_types()[ $kind ] ) ) {
		return null;
	}

	$view = [
		'kind'      => $kind,
		'one_liner' => (string) assemble_community_field( 'community_one_liner', $id ),
		'hero'      => [
			'headline' => (string) assemble_community_field( 'hero_headline', $id ),
			'dek'      => (string) assemble_community_field( 'hero_dek', $id ),
			'image'    => assemble_community_image( assemble_community_field( 'hero_image', $id ) ),
		],
		'closing'   => [
			'heading' => (string) ( assemble_community_field( 'closing_heading', $id ) ?: assemble_community_default( 'closing_heading' ) ),
			'body'    => (string) ( assemble_community_field( 'closing_body', $id ) ?: assemble_community_default( 'closing_body' ) ),
			'cta'     => (string) assemble_community_default( 'apply_label' ),
			'image'   => assemble_community_image( assemble_community_field( 'closing_image', $id ), 'medium_large' ),
		],
	];

	$logos = [];
	foreach ( (array) assemble_community_field( 'member_logos', $id ) as $row ) {
		$company = trim( (string) ( $row['company'] ?? '' ) );
		if ( '' !== $company ) {
			$image   = assemble_community_image( $row['logo'] ?? 0, 'medium' );
			$logos[] = [ 'name' => $company, 'url' => $image['url'] ?? '' ];
		}
	}
	$view['members'] = [
		'count'      => (string) assemble_community_field( 'member_count', $id ),
		'descriptor' => (string) assemble_community_field( 'member_descriptor', $id ),
		'functions'  => (string) assemble_community_field( 'member_functions', $id ),
		'logos'      => $logos,
	];

	if ( 'index' === $kind ) {
		return (array) apply_filters( 'assemble/community_page_view', assemble_community_index_view( $view, $post ), $post );
	}
	if ( 'area' === $kind ) {
		$view['area'] = (string) assemble_community_field( 'community_area', $id );
		$view['name'] = get_the_title( $post );

		return (array) apply_filters( 'assemble/community_page_view', $view, $post );
	}

	// Board and Council pages: the Community supplies name, logo and colour.
	$slug      = (string) assemble_community_field( 'community_slug', $id );
	$community = class_exists( 'AC_Communities' ) && '' !== $slug ? AC_Communities::get( $slug ) : null;
	if ( ! $community ) {
		return null;
	}

	$field   = (string) assemble_community_field( 'community_field', $id );
	$field   = '' !== $field ? $field : (string) ( $community['short_name'] ?: $community['name'] );
	$article = 'an' === assemble_community_field( 'community_article', $id ) ? 'an' : 'a';

	$impact = assemble_community_field( 'own_impact', $id ) ? assemble_community_field( 'impact', $id ) : assemble_community_default( 'impact' );
	$reqs   = assemble_community_field( 'own_requirements', $id ) ? assemble_community_field( 'requirements', $id ) : assemble_community_default( 'requirements' );

	$view += [
		'name'           => (string) $community['name'],
		'field'          => $field,
		'a_field'        => $article . ' ' . $field,
		'community_slug' => (string) $community['slug'],
		'area'           => (string) $community['practice_area'],
		'logo'           => assemble_community_logo( $community ),
		'problem'        => [
			'heading' => (string) assemble_community_field( 'problem_heading', $id ),
			'body'    => assemble_community_paragraphs( (string) assemble_community_field( 'problem_body', $id ) ),
		],
		'challenges'     => [
			'heading' => (string) ( assemble_community_field( 'challenges_heading', $id ) ?: assemble_community_default( 'challenges_heading' ) ),
			'items'   => array_values( array_filter( array_map( static fn ( $r ) => trim( (string) ( $r['question'] ?? '' ) ), (array) assemble_community_field( 'challenges', $id ) ) ) ),
		],
		'sessions'       => assemble_community_session_rows( assemble_community_field( 'sessions', $id ) ),
		'impact'         => [
			'eyebrow' => (string) assemble_community_default( 'impact_eyebrow' ),
			'heading' => (string) assemble_community_default( 'impact_heading' ),
			'items'   => assemble_community_impact_rows( $impact ),
		],
		'included'       => [
			'eyebrow' => (string) assemble_community_default( 'included_eyebrow' ),
			'heading' => (string) assemble_community_default( 'included_heading' ),
			'items'   => assemble_community_merge_pillars(
				assemble_community_pillar_rows( assemble_community_default( 'included' ) ),
				assemble_community_pillar_rows( assemble_community_field( 'own_pillars', $id ) )
			),
		],
		'fit'            => [
			'eyebrow' => (string) assemble_community_default( 'fit_eyebrow' ),
			'heading' => (string) assemble_community_default( 'fit_heading' ),
			'lead'    => (string) assemble_community_default( 'fit_lead' ),
			'groups'  => assemble_community_requirement_groups( $reqs ),
		],
		'company'        => [
			'eyebrow' => (string) assemble_community_default( 'company_eyebrow' ),
			'heading' => (string) assemble_community_default( 'company_heading' ),
		],
	];

	/**
	 * Filter a Community page's view model.
	 *
	 * @param array   $view
	 * @param WP_Post $post
	 */
	return (array) apply_filters( 'assemble/community_page_view', $view, $post );
}

/**
 * The Communities index (/communities/): the shared view plus the value points,
 * the filter's copy, and the shared pillars and requirements, worded for no one
 * Board ({board} reads "Assemble Community", {field} "functional").
 */
function assemble_community_index_view( array $view, WP_Post $post ): array {
	$id      = $post->ID;
	$reqs    = assemble_community_field( 'index_requirements', $id ) ?: assemble_community_default( 'requirements' );
	$points  = [];
	foreach ( (array) assemble_community_field( 'points', $id ) as $row ) {
		$title = trim( (string) ( $row['title'] ?? '' ) );
		if ( '' !== $title ) {
			$points[] = [ 'title' => $title, 'body' => trim( (string) ( $row['body'] ?? '' ) ) ];
		}
	}

	$view['closing']['cta'] = (string) assemble_community_field( 'closing_button', $id ) ?: 'Talk to us';

	return $view + [
		'name'     => get_the_title( $post ),
		'area'     => 'neutral',
		'board'    => 'Assemble Community',
		'field'    => 'functional',
		'a_field'  => 'a functional',
		'points'   => [
			'heading' => (string) assemble_community_field( 'points_heading', $id ),
			'items'   => array_slice( $points, 0, 4 ),
		],
		'finder'   => [
			'heading' => (string) assemble_community_field( 'finder_heading', $id ),
			'lead'    => (string) assemble_community_field( 'finder_lead', $id ),
			'empty'   => (string) assemble_community_field( 'finder_empty', $id ),
		],
		'included' => [
			'eyebrow' => (string) assemble_community_default( 'included_eyebrow' ),
			'heading' => (string) ( assemble_community_field( 'index_included_heading', $id ) ?: assemble_community_default( 'included_heading' ) ),
			'items'   => assemble_community_merge_pillars( assemble_community_pillar_rows( assemble_community_default( 'included' ) ), [] ),
		],
		'fit'      => [
			'eyebrow' => (string) assemble_community_default( 'fit_eyebrow' ),
			'heading' => (string) ( assemble_community_field( 'index_fit_heading', $id ) ?: assemble_community_default( 'fit_heading' ) ),
			'lead'    => (string) ( assemble_community_field( 'index_fit_lead', $id ) ?: assemble_community_default( 'fit_lead' ) ),
			'groups'  => assemble_community_requirement_groups( $reqs ),
		],
		'company'  => [
			'eyebrow' => (string) assemble_community_default( 'company_eyebrow' ),
			'heading' => '',
		],
	];
}

/**
 * Who a Community is for, from its Contentful boilerplate: "senior search, SEO
 * … leaders at the world's largest companies". Empty when the boilerplate
 * doesn't follow the house pattern.
 */
function assemble_community_audience( string $boilerplate ): string {
	$text = trim( (string) preg_replace( '/\s+/', ' ', $boilerplate ) );

	return preg_match( '/\b(senior\b.+?\b(?:companies|organizations|organisations|hospitals|brands|enterprises))\b/u', $text, $m ) ? $m[1] : '';
}

/**
 * Every Community in the feed's directory, grouped by practice (in the shared
 * key order), Boards before Councils, each joined to its published Community
 * page when it has one:
 *   slug, name, label (no " Board"), area, kind (board|council), boilerplate,
 *   audience, logo, page: null | { url, one_liner, member_count, session }.
 * Every practice key is present, empty when it has no Communities yet.
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function assemble_community_directory(): array {
	static $directory = null;
	if ( null !== $directory ) {
		return $directory;
	}

	$directory = array_fill_keys( assemble_practice_area_keys(), [] );
	if ( ! class_exists( 'AC_Communities' ) ) {
		return $directory;
	}

	$pages = [];
	$ids   = get_posts(
		[
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a handful of pages, once per request.
				[ 'key' => '_wp_page_template', 'value' => ASSEMBLE_COMMUNITY_TEMPLATE ],
				[ 'key' => 'community_type', 'value' => [ 'board', 'council' ], 'compare' => 'IN' ],
			],
		]
	);
	foreach ( $ids as $id ) {
		$slug = (string) assemble_community_field( 'community_slug', $id );
		if ( '' === $slug || isset( $pages[ $slug ] ) ) {
			continue;
		}

		$sessions = assemble_community_session_rows( assemble_community_field( 'sessions', $id ) );
		$sessions = array_values( array_filter( $sessions, static fn ( $s ) => strtotime( $s['date'] ) >= strtotime( wp_date( 'Y-m-d' ) ) ) );
		usort( $sessions, static fn ( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

		$pages[ $slug ] = [
			'url'          => (string) get_permalink( $id ),
			'one_liner'    => (string) assemble_community_field( 'community_one_liner', $id ),
			'member_count' => (string) assemble_community_field( 'member_count', $id ),
			'session'      => $sessions[0] ?? null,
		];
	}

	foreach ( AC_Communities::all() as $slug => $community ) {
		$area = (string) ( $community['practice_area'] ?? '' );
		if ( ! isset( $directory[ $area ] ) ) {
			continue;
		}

		$name                 = (string) $community['name'];
		$directory[ $area ][] = [
			'slug'        => (string) $slug,
			'name'        => $name,
			'label'       => (string) preg_replace( '/\s+Board$/', '', $name ),
			'area'        => $area,
			'kind'        => preg_match( '/\bCouncil\b/', $name ) ? 'council' : 'board',
			'boilerplate' => (string) ( $community['boilerplate'] ?? '' ),
			'audience'    => assemble_community_audience( (string) ( $community['boilerplate'] ?? '' ) ),
			'logo'        => assemble_community_logo( $community ),
			'page'        => $pages[ $slug ] ?? null,
		];
	}

	foreach ( $directory as $area => $communities ) {
		usort( $communities, static fn ( $a, $b ) => [ 'council' === $a['kind'], $a['name'] ] <=> [ 'council' === $b['kind'], $b['name'] ] );
		$directory[ $area ] = $communities;
	}

	return $directory;
}

add_filter(
	'assemble/community_page',
	static function ( $page, $post ) {
		return null === $page && $post instanceof WP_Post ? assemble_community_page_view( $post ) : $page;
	},
	10,
	2
);

// The Community dropdown lists the feed's Community directory (Boards and Councils).
add_filter(
	'acf/load_field/name=community_slug',
	static function ( array $field ): array {
		$choices = [];
		if ( class_exists( 'AC_Communities' ) ) {
			foreach ( AC_Communities::all() as $slug => $community ) {
				$choices[ $slug ] = (string) $community['name'];
			}
		}
		$field['choices'] = $choices;

		return $field;
	}
);

// The practice dropdown lists the theme's official practice names when it has them.
add_filter(
	'acf/load_field/name=community_area',
	static function ( array $field ): array {
		if ( function_exists( 'assemble_practice_areas' ) ) {
			$field['choices'] = assemble_practice_areas();
		}

		return $field;
	}
);

add_action(
	'acf/init',
	static function (): void {
		if ( function_exists( 'acf_add_options_sub_page' ) ) {
			acf_add_options_sub_page(
				[
					'page_title'    => 'Community defaults',
					'menu_title'    => 'Community defaults',
					'menu_slug'     => 'assemble-community-defaults',
					'parent_slug'   => 'assemble-site-settings',
					'capability'    => 'manage_options',
					'autoload'      => false,
					'update_button' => 'Save defaults',
				]
			);
		}
	}
);

foreach ( [ 'group_assemble_community_page', 'group_assemble_community_defaults' ] as $assemble_group ) {
	add_filter( 'acf/settings/save_json/key=' . $assemble_group, static fn (): string => ASSEMBLE_CORE_DIR . 'acf-json' );
}
