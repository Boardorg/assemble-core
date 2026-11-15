<?php
/**
 * Search rules that depend on content, not settings.
 *
 * The Playbooks archive stays out of search, and out of Yoast's sitemap, until
 * at least three playbooks are public (David's SEO review, Cale 2026-11-15).
 * It switches on by itself; the page stays reachable either way.
 */

defined( 'ABSPATH' ) || exit;

/** How many public playbooks an archive needs before search engines see it. */
const ASSEMBLE_PLAYBOOKS_MIN_PUBLIC = 3;

/** Published playbooks that anonymous visitors can see. */
function assemble_public_playbook_count(): int {
	$ids = get_posts(
		[
			'post_type'        => 'playbook',
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		]
	);

	return count( array_diff( array_map( 'intval', $ids ), assemble_hidden_piece_ids( [ 'Public' ] ) ) );
}

/** Should the Playbooks archive be kept out of search? */
function assemble_playbooks_archive_hidden(): bool {
	return post_type_exists( 'playbook' ) && assemble_public_playbook_count() < ASSEMBLE_PLAYBOOKS_MIN_PUBLIC;
}

add_filter(
	'wpseo_robots',
	static function ( $robots ) {
		if ( is_post_type_archive( 'playbook' ) && assemble_playbooks_archive_hidden() ) {
			return 'noindex, follow';
		}

		return $robots;
	}
);

add_filter(
	'wpseo_sitemap_post_type_archive_link',
	static function ( $link, $post_type ) {
		return ( 'playbook' === $post_type && assemble_playbooks_archive_hidden() ) ? false : $link;
	},
	10,
	2
);
