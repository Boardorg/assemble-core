<?php
/**
 * Community page tests (inc/communities.php). Local only; creates and deletes
 * a throwaway page.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/communities-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['act'] = [ 'pass' => 0, 'fail' => 0 ];

function act_check( bool $ok, string $label ): void {
	$GLOBALS['act'][ $ok ? 'pass' : 'fail' ]++;
	if ( ! $ok ) {
		WP_CLI::warning( 'FAIL: ' . $label );
	}
}

// Text helpers.
act_check( [ 'One.', 'Two.' ] === assemble_community_paragraphs( "One.\n\nTwo.\n" ), 'paragraphs split at blank lines' );
act_check( [ 'A', 'B' ] === assemble_community_lines( "A\n\n B \n" ), 'lines drop blanks and trim' );

// Pillars: own pillars go after the shared one they name, else before the last.
$p      = static fn ( string $t, int $after = 0 ) => [ 'title' => $t, 'body' => '', 'tags_in' => '', 'tags' => [], 'details' => [], 'after' => $after ];
$shared = [ $p( 'S1' ), $p( 'S2' ), $p( 'S3' ) ];
$titles = static fn ( array $items ) => implode( ',', array_column( $items, 'title' ) );
act_check( 'S1,S2,X,S3' === $titles( assemble_community_merge_pillars( $shared, [ $p( 'X', 2 ) ] ) ), 'own pillar after shared #2' );
act_check( 'S1,S2,X,S3' === $titles( assemble_community_merge_pillars( $shared, [ $p( 'X' ) ] ) ), 'own pillar with no position goes before the last shared one' );
act_check( 'S1,X,Y,S2,S3' === $titles( assemble_community_merge_pillars( $shared, [ $p( 'X', 1 ), $p( 'Y', 1 ) ] ) ), 'two own pillars keep their order' );
act_check( ! array_key_exists( 'after', assemble_community_merge_pillars( $shared, [] )[0] ), 'the position key is dropped' );

// Requirements group into the two cards, empty groups left out.
$groups = assemble_community_requirement_groups(
	[
		[ 'group' => 'company', 'title' => 'Enterprise', 'description' => '$500M+' ],
		[ 'group' => 'individual', 'title' => '', 'description' => 'no title, skipped' ],
	]
);
act_check( 1 === count( $groups ) && 'Company requirements' === $groups[0]['label'], 'requirements group, empty groups dropped' );

// Sessions need a title and a date.
act_check( 1 === count( assemble_community_session_rows( [ [ 'date' => '2030-01-01 12:00:00', 'title' => 'T' ], [ 'date' => '', 'title' => 'No date' ] ] ) ), 'sessions need a date and title' );

// Defaults: structural code defaults fill empty options.
act_check( '' !== assemble_community_default( 'apply_label' ), 'the apply label always has a value' );
act_check( 'Impact at three levels' === assemble_community_default( 'impact_eyebrow' ), 'a label with no field uses the code default' );

// The view model: only pages on the template, with a known Community.
$id   = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Community test' ] );
$post = get_post( $id );
act_check( null === assemble_community_page_view( $post ), 'a page without the template has no view model' );

update_post_meta( $id, '_wp_page_template', ASSEMBLE_COMMUNITY_TEMPLATE );
update_field( 'field_assemble_cp_community_type', 'board', $id );
update_field( 'field_assemble_cp_community_slug', 'no-such-community', $id );
act_check( null === assemble_community_page_view( get_post( $id ) ), 'an unknown Community gives no view model' );

$known = class_exists( 'AC_Communities' ) ? array_key_first( AC_Communities::all() ) : null;
if ( $known ) {
	update_field( 'field_assemble_cp_community_slug', $known, $id );
	update_field( 'field_assemble_cp_community_field', 'L&D', $id );
	update_field( 'field_assemble_cp_community_article', 'an', $id );
	update_field( 'field_assemble_cp_hero_headline', 'Headline', $id );
	$view = assemble_community_page_view( get_post( $id ) );
	act_check( is_array( $view ) && 'board' === $view['kind'], 'a Board page has a view model' );
	act_check( 'an L&D' === ( $view['a_field'] ?? '' ), 'a_field joins the article and field' );
	act_check( AC_Communities::get( $known )['name'] === ( $view['name'] ?? '' ), 'the name comes from the Community' );
	act_check( is_array( apply_filters( 'assemble/community_page', null, get_post( $id ) ) ), 'the theme filter is answered' );
}

update_field( 'field_assemble_cp_community_type', 'area', $id );
update_field( 'field_assemble_cp_community_area', 'marketing', $id );
$view = assemble_community_page_view( get_post( $id ) );
act_check( 'area' === ( $view['kind'] ?? '' ) && 'marketing' === $view['area'], 'a practice-area page reports its kind and area' );

wp_delete_post( $id, true );

$r = $GLOBALS['act'];
if ( $r['fail'] ) {
	WP_CLI::error( "{$r['fail']} failed, {$r['pass']} passed." );
}
WP_CLI::success( "{$r['pass']} passed." );
