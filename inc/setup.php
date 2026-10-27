<?php
/**
 * `wp assemble setup`: the standard pages and the masthead menu, on any install
 * (local, the beta, a clean copy at launch). Idempotent: it creates what's
 * missing and never overwrites what exists, so a second run changes nothing.
 *
 *   wp assemble setup [--dry-run] [--content-dir=<dir>] [--adopt-elementor] [--adopt-um]
 *
 * Page copy is content, not code, so it isn't in this public repo: a new page
 * gets a one-line placeholder, or <dir>/<slug>.html when --content-dir is given
 * (block markup; a child page's file is "<parent>--<slug>.html"). The ported
 * copy lives in the project hub's private/pages/.
 *
 * --adopt-elementor also replaces the content of a standard page that is still
 * an Elementor page (`_elementor_edit_mode = builder`) with its file, resets
 * its template and sentence-cases its title. WordPress keeps the old content as
 * a revision, and the page is marked so it is adopted only once.
 *
 * The account pages (Sign in, Create account, Account; Phase 7) are created
 * empty: their templates render the forms. --adopt-um empties an account page
 * that still holds an Ultimate Member shortcode and retitles it, once (the old
 * content stays as a revision).
 *
 * The footer stays on Site Settings → Footer columns, so only the "primary"
 * menu is created.
 */

defined( 'ABSPATH' ) || exit;

const ASSEMBLE_SETUP_MARKER = '_assemble_setup_adopted';

/**
 * The standard pages, parents first. Paths are today's theassemble.com URLs
 * (BUILD-INSTRUCTIONS §9.5).
 *
 * @return array<string,string> Path => title (sentence case).
 */
function assemble_setup_pages(): array {
	return (array) apply_filters(
		'assemble/setup_pages',
		[
			'about-us'             => 'About us',
			'our-team'             => 'Our team',
			'careers'              => 'Careers',
			'contact-us'           => 'Contact us',
			'contact-us/thank-you' => 'Thank you',
			'policies'             => 'Privacy policy',
		]
	);
}

/**
 * The account pages, at the paths of their Site Settings URLs (skipped when a
 * URL points off this site, e.g. to SSO).
 *
 * @return array<string,string> Path => title.
 */
function assemble_setup_account_pages(): array {
	$pages = [];
	foreach ( [ 'sign_in_url' => 'Sign in', 'create_account_url' => 'Create a free account', 'account_url' => 'Your account' ] as $key => $title ) {
		$url = (string) assemble_site_setting( $key );
		if ( str_starts_with( $url, '/' ) ) {
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			if ( '' !== $path ) {
				$pages[ $path ] = $title;
			}
		}
	}

	return (array) apply_filters( 'assemble/setup_account_pages', $pages );
}

/**
 * Masthead menu items. Insights is the Field Reports archive; Communities is a
 * Site Setting (a placeholder until a Communities index exists); Summits is the
 * Summits page.
 *
 * @return array<int,array{title:string,type:string,object?:string,object_id?:int,url?:string}>
 */
function assemble_setup_primary_items(): array {
	$items = [];

	$items[] = post_type_exists( 'field_report' )
		? [
			'title'  => 'Insights',
			'type'   => 'post_type_archive',
			'object' => 'field_report',
		]
		: [
			'title' => 'Insights',
			'type'  => 'custom',
			'url'   => '/field-reports/',
		];

	$communities = get_page_by_path( 'communities' );
	$items[]     = $communities instanceof WP_Post
		? [
			'title'     => 'Communities',
			'type'      => 'post_type',
			'object'    => 'page',
			'object_id' => (int) $communities->ID,
		]
		: [
			'title' => 'Communities',
			'type'  => 'custom',
			'url'   => (string) assemble_site_setting( 'communities_url' ),
		];

	$summits = get_page_by_path( 'summits' );
	$items[] = $summits instanceof WP_Post
		? [
			'title'     => 'Summits',
			'type'      => 'post_type',
			'object'    => 'page',
			'object_id' => (int) $summits->ID,
		]
		: [
			'title' => 'Summits',
			'type'  => 'custom',
			'url'   => '/summits/',
		];

	return (array) apply_filters( 'assemble/setup_primary_items', $items );
}

/** The menu `wp assemble setup` creates for the masthead. */
const ASSEMBLE_SETUP_PRIMARY_MENU = 'Masthead';

/**
 * Run the setup. Returns one row per thing checked.
 *
 * @return array<int,array{item:string,action:string,detail:string}>
 */
function assemble_setup_run( bool $dry_run = false, string $content_dir = '', bool $adopt_elementor = false, bool $adopt_um = false ): array {
	$log = [];
	$add = static function ( string $item, string $action, string $detail = '' ) use ( &$log ): void {
		$log[] = compact( 'item', 'action', 'detail' );
	};

	$content_dir = '' !== $content_dir ? trailingslashit( $content_dir ) : '';

	// Page files come from a folder the operator names, and may embed a form
	// script; WP-CLI runs with no user, so kses would strip it.
	kses_remove_filters();

	$account_pages = assemble_setup_account_pages();

	foreach ( assemble_setup_pages() + $account_pages as $path => $title ) {
		$is_account = isset( $account_pages[ $path ] );
		$file       = $content_dir && ! $is_account ? $content_dir . str_replace( '/', '--', $path ) . '.html' : '';
		$content    = $file && is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line.
		$page       = get_page_by_path( $path );

		if ( $page instanceof WP_Post && $is_account ) {
			$is_um   = str_contains( $page->post_content, '[ultimatemember' );
			$adopted = (bool) get_post_meta( $page->ID, ASSEMBLE_SETUP_MARKER, true );

			if ( $adopt_um && $is_um && ! $adopted ) {
				$add( "page /{$path}/", $dry_run ? 'would adopt' : 'adopted', "#{$page->ID}: Ultimate Member shortcode removed; old content kept as a revision" );
				if ( ! $dry_run ) {
					wp_update_post(
						[
							'ID'           => $page->ID,
							'post_title'   => $title,
							'post_content' => '',
							'post_status'  => 'publish',
						]
					);
					update_post_meta( $page->ID, '_wp_page_template', 'default' );
					update_post_meta( $page->ID, ASSEMBLE_SETUP_MARKER, gmdate( 'c' ) );
				}
				continue;
			}

			$note = $is_um && ! $adopted ? 'still an Ultimate Member page (run with --adopt-um)' : '';
			$add( "page /{$path}/", 'exists', trim( "#{$page->ID} {$page->post_status}. {$note}" ) );
			continue;
		}

		if ( $page instanceof WP_Post ) {
			$is_elementor = 'builder' === get_post_meta( $page->ID, '_elementor_edit_mode', true );
			$adopted      = (bool) get_post_meta( $page->ID, ASSEMBLE_SETUP_MARKER, true );

			if ( $adopt_elementor && $is_elementor && ! $adopted && '' !== $content ) {
				$add( "page /{$path}/", $dry_run ? 'would adopt' : 'adopted', "#{$page->ID}: Elementor content replaced from " . basename( $file ) . '; old content kept as a revision' );
				if ( ! $dry_run ) {
					wp_update_post(
						[
							'ID'           => $page->ID,
							'post_title'   => $title,
							'post_content' => $content,
						]
					);
					update_post_meta( $page->ID, '_wp_page_template', 'default' );
					update_post_meta( $page->ID, ASSEMBLE_SETUP_MARKER, gmdate( 'c' ) );
				}
				continue;
			}

			$note = $is_elementor && ! $adopted ? 'still an Elementor page (run with --adopt-elementor and a content file)' : '';
			$add( "page /{$path}/", 'exists', trim( "#{$page->ID} {$page->post_status}. {$note}" ) );
			continue;
		}

		$parent_id = 0;
		if ( str_contains( $path, '/' ) ) {
			$parent = get_page_by_path( dirname( $path ) );
			if ( ! $parent instanceof WP_Post && ! $dry_run ) {
				$add( "page /{$path}/", 'skipped', 'parent page missing' );
				continue;
			}
			$parent_id = $parent instanceof WP_Post ? (int) $parent->ID : 0;
		}

		if ( '' === $content && ! $is_account ) {
			$content = "<!-- wp:paragraph -->\n<p>Content to come.</p>\n<!-- /wp:paragraph -->";
		}

		$id = 0;
		if ( ! $dry_run ) {
			$id = wp_insert_post(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => basename( $path ),
					'post_parent'  => $parent_id,
					'post_content' => $content,
				],
				true
			);
			if ( is_wp_error( $id ) ) {
				$add( "page /{$path}/", 'failed', $id->get_error_message() );
				continue;
			}
		}
		$add( "page /{$path}/", $dry_run ? 'would create' : 'created', $id ? "#{$id}" : ( $file && is_readable( $file ) ? basename( $file ) : 'placeholder content' ) );
	}

	kses_init();

	// The masthead menu.
	$menu = wp_get_nav_menu_object( ASSEMBLE_SETUP_PRIMARY_MENU );
	if ( $menu ) {
		$add( 'menu ' . ASSEMBLE_SETUP_PRIMARY_MENU, 'exists', "#{$menu->term_id}, {$menu->count} items (left as is)" );
		$menu_id = (int) $menu->term_id;
	} elseif ( $dry_run ) {
		$add( 'menu ' . ASSEMBLE_SETUP_PRIMARY_MENU, 'would create', implode( ', ', wp_list_pluck( assemble_setup_primary_items(), 'title' ) ) );
		$menu_id = 0;
	} else {
		$menu_id = wp_create_nav_menu( ASSEMBLE_SETUP_PRIMARY_MENU );
		if ( is_wp_error( $menu_id ) ) {
			$add( 'menu ' . ASSEMBLE_SETUP_PRIMARY_MENU, 'failed', $menu_id->get_error_message() );
			$menu_id = 0;
		} else {
			foreach ( assemble_setup_primary_items() as $position => $item ) {
				$data = [
					'menu-item-title'    => $item['title'],
					'menu-item-type'     => $item['type'],
					'menu-item-status'   => 'publish',
					'menu-item-position' => $position + 1,
				];
				if ( 'custom' === $item['type'] ) {
					$data['menu-item-url'] = $item['url'];
				} else {
					$data['menu-item-object'] = $item['object'];
				}
				if ( ! empty( $item['object_id'] ) ) {
					$data['menu-item-object-id'] = $item['object_id'];
				}
				wp_update_nav_menu_item( $menu_id, 0, $data );
			}
			$add( 'menu ' . ASSEMBLE_SETUP_PRIMARY_MENU, 'created', "#{$menu_id}: " . implode( ', ', wp_list_pluck( assemble_setup_primary_items(), 'title' ) ) );
		}
	}

	// Assign it to the theme's "primary" location, unless something already holds it.
	$locations = (array) get_nav_menu_locations();
	if ( ! array_key_exists( 'primary', get_registered_nav_menus() ) ) {
		$add( 'location primary', 'skipped', 'the active theme registers no "primary" location' );
	} elseif ( ! empty( $locations['primary'] ) ) {
		$held = wp_get_nav_menu_object( (int) $locations['primary'] );
		$add( 'location primary', 'exists', $held ? "holds \"{$held->name}\" (left as is)" : 'holds a missing menu (left as is)' );
	} elseif ( $dry_run || ! $menu_id ) {
		$add( 'location primary', $dry_run ? 'would assign' : 'skipped', ASSEMBLE_SETUP_PRIMARY_MENU );
	} else {
		$locations['primary'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		$add( 'location primary', 'assigned', ASSEMBLE_SETUP_PRIMARY_MENU );
	}

	return $log;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * The `wp assemble setup` command.
	 */
	class Assemble_Setup_CLI {
		/**
		 * Create the standard pages and the masthead menu where they're missing.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Report what would change; change nothing.
		 *
		 * [--content-dir=<dir>]
		 * : A folder of <slug>.html block markup for new (or adopted) pages.
		 *
		 * [--adopt-elementor]
		 * : Replace the content of standard pages that are still Elementor pages with their file.
		 *
		 * [--adopt-um]
		 * : Empty account pages that still hold an Ultimate Member shortcode (their templates render the forms).
		 *
		 * ## EXAMPLES
		 *
		 *     wp assemble setup --dry-run
		 *     wp assemble setup --content-dir=/tmp/pages --adopt-elementor
		 *
		 * @param array<int,string>    $args
		 * @param array<string,string> $assoc_args
		 */
		public function __invoke( array $args, array $assoc_args ): void {
			$dir = (string) ( $assoc_args['content-dir'] ?? '' );
			if ( '' !== $dir && ! is_dir( $dir ) ) {
				WP_CLI::error( "No such folder: {$dir}" );
			}

			$dry  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
			$rows = assemble_setup_run(
				$dry,
				$dir,
				(bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'adopt-elementor', false ),
				(bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'adopt-um', false )
			);

			WP_CLI\Utils\format_items( 'table', $rows, [ 'item', 'action', 'detail' ] );

			$changed = count( array_filter( $rows, static fn ( $row ) => in_array( $row['action'], [ 'created', 'adopted', 'assigned' ], true ) ) );
			$failed  = count( array_filter( $rows, static fn ( $row ) => 'failed' === $row['action'] ) );
			if ( $failed ) {
				WP_CLI::error( "{$failed} step(s) failed." );
			}
			WP_CLI::success( $dry ? 'Dry run: nothing changed.' : ( $changed ? "{$changed} change(s)." : 'Nothing to do.' ) );
		}
	}

	WP_CLI::add_command( 'assemble setup', 'Assemble_Setup_CLI' );
}
