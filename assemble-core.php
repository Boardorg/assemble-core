<?php
/**
 * Plugin Name:       Assemble Core
 * Description:       Public-site content model for theassemble.com: Community sales pages (SCF fields), summits (read through the shared engine in Assemble Content), Site Settings, free accounts (sign in, create account, account page, audiences for the Field Report gate, the free-read meter), the team, redirects from the old site's URLs, the noindex guard and the `wp assemble setup` command. Content authored in Contentful lives in Assemble Content instead.
 * Version:           0.10.1
 * Requires PHP:      8.0
 * Author:            Assemble
 * Text Domain:       assemble-core
 */

defined( 'ABSPATH' ) || exit;

define( 'ASSEMBLE_CORE_VERSION', '0.10.1' );
define( 'ASSEMBLE_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once ASSEMBLE_CORE_DIR . 'inc/environment.php';
require_once ASSEMBLE_CORE_DIR . 'inc/noindex.php';
require_once ASSEMBLE_CORE_DIR . 'inc/content-guards.php';
require_once ASSEMBLE_CORE_DIR . 'inc/site-settings.php';
require_once ASSEMBLE_CORE_DIR . 'inc/communities.php';
require_once ASSEMBLE_CORE_DIR . 'inc/summits.php';
require_once ASSEMBLE_CORE_DIR . 'inc/team.php';
require_once ASSEMBLE_CORE_DIR . 'inc/accounts.php';
require_once ASSEMBLE_CORE_DIR . 'inc/account-forms.php';
require_once ASSEMBLE_CORE_DIR . 'inc/account-admin.php';
require_once ASSEMBLE_CORE_DIR . 'inc/mail-guard.php';
require_once ASSEMBLE_CORE_DIR . 'inc/free-reads.php';
require_once ASSEMBLE_CORE_DIR . 'inc/redirects.php';
require_once ASSEMBLE_CORE_DIR . 'inc/setup.php';

register_deactivation_hook( __FILE__, static fn() => wp_clear_scheduled_hook( ASSEMBLE_SUMMITS_LEGACY_CRON ) );
