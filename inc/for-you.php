<?php
/**
 * The For You feed (wireframe 8, "Index (logged in)"): the newest pieces from
 * the Communities and practice areas a signed-in person follows. Rules only;
 * the theme's page-for-you.php renders it.
 *
 * - Field Reports in any followed Community (the feed's Community taxonomy).
 * - Playbooks in any followed practice area (they carry areas, not Communities).
 * - Members-only pieces never appear: inc/members-only.php already drops them
 *   from every front-end query, so nothing here re-checks audiences.
 *
 * Each item says why it's there ("Because you follow L&D"): the first followed
 * Community it's tagged with, or, for a playbook, its followed area.
 */

defined( 'ABSPATH' ) || exit;

/** Items a page. */
const ASSEMBLE_FOR_YOU_PER_PAGE = 12;

/**
 * The narrowing choices for someone: each followed Community, then each
 * followed area that has no followed Community (Finance today). Keys are
 * `c:<community-slug>` or `a:<area-key>`.
 *
 * @return array<string,array{type:string,key:string,label:string,area:string}>
 */
function assemble_for_you_chips( int $user_id ): array {
	$profile = assemble_account_profile( $user_id );
	$chips   = [];
	$covered = [];

	foreach ( (array) $profile['communities'] as $slug ) {
		$community = class_exists( 'AC_Communities' ) ? AC_Communities::get( (string) $slug ) : null;
		if ( ! $community ) {
			continue;
		}
		$area                  = (string) ( $community['practice_area'] ?? '' );
		$covered[ $area ]      = true;
		$chips[ 'c:' . $slug ] = [
			'type'  => 'community',
			'key'   => (string) $slug,
			'label' => (string) ( $community['short_name'] ?: $community['name'] ),
			'area'  => $area,
		];
	}

	foreach ( (array) $profile['areas'] as $area ) {
		if ( empty( $covered[ $area ] ) ) {
			$chips[ 'a:' . $area ] = [
				'type'  => 'area',
				'key'   => (string) $area,
				'label' => '',
				'area'  => (string) $area,
			];
		}
	}

	return $chips;
}

/**
 * IDs of followed pieces, newest first, before paging.
 *
 * @param string[] $communities Community slugs.
 * @param string[] $areas       Practice-area keys (for playbooks).
 * @return int[]
 */
function assemble_for_you_ids( array $communities, array $areas ): array {
	$base = [
		'post_status'      => 'publish',
		'posts_per_page'   => 200,
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'orderby'          => 'date',
		'order'            => 'DESC',
		'suppress_filters' => false,
	];
	$ids  = [];

	if ( $communities && taxonomy_exists( 'field_report_community' ) ) {
		$ids = array_merge(
			$ids,
			get_posts(
				$base + [
					'post_type' => 'field_report',
					'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one indexed term lookup.
						[
							'taxonomy' => 'field_report_community',
							'field'    => 'slug',
							'terms'    => $communities,
						],
					],
				]
			)
		);
	}

	if ( $areas && post_type_exists( 'playbook' ) ) {
		$ids = array_merge(
			$ids,
			get_posts(
				$base + [
					'post_type'  => 'playbook',
					'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a handful of playbooks.
						[
							'key'     => '_ac_practice_area',
							'value'   => $areas,
							'compare' => 'IN',
						],
					],
				]
			)
		);
	}

	return array_values( array_unique( array_map( 'intval', $ids ) ) );
}

/**
 * Why a piece is in someone's feed.
 *
 * @param array<string,array> $chips From assemble_for_you_chips().
 * @return array{type:string,key:string,label:string,area:string}|null
 */
function assemble_for_you_reason( WP_Post $post, array $chips ): ?array {
	if ( 'playbook' === $post->post_type ) {
		foreach ( (array) get_post_meta( $post->ID, '_ac_practice_area' ) as $area ) {
			foreach ( $chips as $chip ) {
				if ( $chip['area'] === $area ) {
					return [
						'type'  => 'area',
						'key'   => (string) $area,
						'label' => '',
						'area'  => (string) $area,
					];
				}
			}
		}
		return null;
	}

	$terms = wp_get_post_terms( $post->ID, 'field_report_community', [ 'fields' => 'slugs' ] );
	foreach ( is_array( $terms ) ? $terms : [] as $slug ) {
		if ( isset( $chips[ 'c:' . $slug ] ) ) {
			return $chips[ 'c:' . $slug ];
		}
	}

	return null;
}

/**
 * Someone's feed.
 *
 * @param array{for?:string,page?:int,per_page?:int} $args `for` is a chip key from assemble_for_you_chips();
 *                                                          anything else means "All for you".
 * @return array{chips:array,current:string,items:array<int,array{post:WP_Post,reason:?array}>,total:int,pages:int,page:int,follows_any:bool}
 */
function assemble_for_you( int $user_id, array $args = [] ): array {
	$chips    = assemble_for_you_chips( $user_id );
	$current  = isset( $chips[ (string) ( $args['for'] ?? '' ) ] ) ? (string) $args['for'] : '';
	$per_page = max( 1, (int) ( $args['per_page'] ?? ASSEMBLE_FOR_YOU_PER_PAGE ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$profile  = assemble_account_profile( $user_id );

	$result = [
		'chips'       => $chips,
		'current'     => $current,
		'items'       => [],
		'total'       => 0,
		'pages'       => 0,
		'page'        => $page,
		'follows_any' => (bool) $profile['areas'],
	];

	if ( ! $chips ) {
		return $result;
	}

	if ( '' === $current ) {
		$communities = wp_list_pluck( wp_list_filter( $chips, [ 'type' => 'community' ] ), 'key' );
		$areas       = (array) $profile['areas'];
	} elseif ( 'community' === $chips[ $current ]['type'] ) {
		$communities = [ $chips[ $current ]['key'] ];
		$areas       = [];
	} else {
		$communities = [];
		$areas       = [ $chips[ $current ]['key'] ];
	}

	$ids = assemble_for_you_ids( array_values( $communities ), array_values( $areas ) );
	if ( ! $ids ) {
		return $result;
	}

	$query = new WP_Query(
		[
			'post_type'      => [ 'field_report', 'playbook' ],
			'post_status'    => 'publish',
			'post__in'       => $ids,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		]
	);

	foreach ( $query->posts as $post ) {
		$result['items'][] = [
			'post'   => $post,
			'reason' => assemble_for_you_reason( $post, $chips ),
		];
	}
	$result['total'] = (int) $query->found_posts;
	$result['pages'] = (int) $query->max_num_pages;

	return $result;
}

// The page is personal: never indexed.
add_filter(
	'wp_robots',
	static function ( array $robots ): array {
		if ( 'for_you' === assemble_account_page_kind() ) {
			unset( $robots['index'], $robots['follow'] );
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}
);
