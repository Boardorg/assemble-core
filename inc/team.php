<?php
/**
 * The team: one `team` post per person, at /our-team/<slug>/, listed on
 * /our-team/ (Cale, 2026-10-11). People data stays in WordPress, never in a repo.
 *
 * On the beta (and so at launch) the post type and its "Team Member Info" fields
 * (name, position, bio) were defined in Secure Custom Fields' admin, so they live
 * in the database. This file registers the same post type only where nothing
 * else has (a fresh local site), and gives templates one reader that works with
 * or without the fields plugin.
 */

defined( 'ABSPATH' ) || exit;

const ASSEMBLE_TEAM_POST_TYPE = 'team';

add_action(
	'init',
	static function (): void {
		if ( post_type_exists( ASSEMBLE_TEAM_POST_TYPE ) ) {
			return;
		}

		register_post_type(
			ASSEMBLE_TEAM_POST_TYPE,
			[
				'labels'       => [
					'name'          => 'Team Members',
					'singular_name' => 'Team Member',
					'add_new_item'  => 'Add team member',
					'edit_item'     => 'Edit team member',
				],
				'public'       => true,
				'has_archive'  => false,
				'hierarchical' => false,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-groups',
				'supports'     => [ 'title', 'thumbnail', 'page-attributes' ],
				'rewrite'      => [
					'slug'       => 'our-team',
					'with_front' => false,
					'feeds'      => false,
				],
			]
		);
	},
	20 // After Secure Custom Fields registers its own post types.
);

/**
 * One person, as templates read them.
 *
 * @return array{id:int,name:string,position:string,bio:string,image_id:int,url:string}
 */
function assemble_team_member( WP_Post $post ): array {
	$name = trim( (string) get_post_meta( $post->ID, 'name', true ) );

	return [
		'id'       => (int) $post->ID,
		'name'     => '' !== $name ? $name : get_the_title( $post ),
		'position' => trim( (string) get_post_meta( $post->ID, 'position', true ) ),
		'bio'      => trim( (string) get_post_meta( $post->ID, 'bio', true ) ),
		'image_id' => (int) get_post_thumbnail_id( $post ),
		'url'      => (string) get_permalink( $post ),
	];
}

/**
 * Everyone published, in the editors' order (Order field, then name).
 *
 * @return array<int,array> From assemble_team_member().
 */
function assemble_team_members(): array {
	if ( ! post_type_exists( ASSEMBLE_TEAM_POST_TYPE ) ) {
		return [];
	}

	$posts = get_posts(
		[
			'post_type'        => ASSEMBLE_TEAM_POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 100,
			'orderby'          => [
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			],
			'suppress_filters' => false,
		]
	);

	return array_map( 'assemble_team_member', $posts );
}
