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

// The index: value points (blank rows dropped, four at most), the filter copy, the shared blocks.
update_field( 'field_assemble_cp_community_type', 'index', $id );
update_field( 'field_assemble_cp_points_heading', 'Why join', $id );
update_field( 'field_assemble_cp_points', [ [ 'title' => 'One', 'body' => 'B' ], [ 'title' => '', 'body' => 'skipped' ], [ 'title' => 'Two', 'body' => '' ] ], $id );
update_field( 'field_assemble_cp_finder_heading', 'Find your Community', $id );
update_field( 'field_assemble_cp_closing_button', 'Talk to us', $id );
$view = assemble_community_page_view( get_post( $id ) );
act_check( 'index' === ( $view['kind'] ?? '' ) && 'neutral' === $view['area'], 'the index reports its kind' );
act_check( [ 'One', 'Two' ] === array_column( $view['points']['items'] ?? [], 'title' ), 'index points drop blank rows' );
act_check( 'Find your Community' === ( $view['finder']['heading'] ?? '' ), 'the index has its filter heading' );
act_check( 'Talk to us' === ( $view['closing']['cta'] ?? '' ), 'the index closing button is its own' );
act_check( isset( $view['included']['items'], $view['fit']['groups'], $view['board'], $view['field'] ), 'the index has the shared pillars, requirements and placeholders' );
update_field( 'field_assemble_cp_index_requirements', [ [ 'group' => 'company', 'title' => 'Big', 'description' => '' ] ], $id );
$view = assemble_community_page_view( get_post( $id ) );
act_check( 1 === count( $view['fit']['groups'] ) && 'Big' === $view['fit']['groups'][0]['items'][0][0], 'index requirements replace the shared ones' );

wp_delete_post( $id, true );

// The audience clause from a boilerplate.
act_check( 'senior search, SEO and content leaders at the world’s largest companies' === assemble_community_audience( 'The AEO Board is an Assemble community that helps senior search, SEO and content leaders at the world’s largest companies make better decisions.' ), 'audience from the boilerplate' );
act_check( '' === assemble_community_audience( 'Something else entirely.' ), 'no audience without the pattern' );

// The directory: every practice present, Boards before Councils, pages joined by Community.
$directory = assemble_community_directory();
act_check( assemble_practice_area_keys() === array_keys( $directory ), 'the directory lists every practice in order' );
act_check( [] === $directory['finance'], 'Finance and Legal has no Communities yet' );
act_check( assemble_community_is_network( 'HR Network' ) && ! assemble_community_is_network( 'AEO Board' ), 'Networks are recognised by name' );
act_check( [] === array_filter( array_merge( ...array_values( $directory ) ), static fn ( $c ) => assemble_community_is_network( $c['name'] ) ), 'the directory leaves Networks out' );
foreach ( $directory as $communities ) {
	$kinds = array_column( $communities, 'kind' );
	act_check( $kinds === array_merge( array_diff( $kinds, [ 'council' ] ), array_intersect( $kinds, [ 'council' ] ) ) || [] === $kinds, 'Councils come after Boards' );
}
$aeo = get_page_by_path( 'communities/aeo' );
if ( $aeo && 'publish' === $aeo->post_status && 'aeo' === get_field( 'community_slug', $aeo->ID ) ) {
	$joined = array_values( array_filter( $directory['marketing'], static fn ( $c ) => 'aeo' === $c['slug'] ) )[0] ?? [];
	act_check( get_permalink( $aeo ) === ( $joined['page']['url'] ?? '' ), 'a Community with a published page is joined to it' );
}
$pageless = array_filter( array_merge( ...array_values( $directory ) ), static fn ( $c ) => null === $c['page'] );
act_check( ! class_exists( 'AC_Communities' ) || [] !== $pageless, 'Communities without a page have page = null' );

$r = $GLOBALS['act'];
if ( $r['fail'] ) {
	WP_CLI::error( "{$r['fail']} failed, {$r['pass']} passed." );
}
WP_CLI::success( "{$r['pass']} passed." );
