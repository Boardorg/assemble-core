<?php
/**
 * Plugin Name:       Assemble Core
 * Description:       Public-site content model for theassemble.com: summits read from executiveplatforms.com (interim) with Assemble's own extras, Site Settings, the noindex guard and the `wp assemble setup` command. Content authored in Contentful lives in Assemble Content instead.
 * Version:           0.4.0
 * Requires PHP:      8.0
 * Author:            Assemble
 * Text Domain:       assemble-core
 */

defined( 'ABSPATH' ) || exit;

define( 'ASSEMBLE_CORE_VERSION', '0.4.0' );
define( 'ASSEMBLE_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once ASSEMBLE_CORE_DIR . 'inc/environment.php';
require_once ASSEMBLE_CORE_DIR . 'inc/noindex.php';
require_once ASSEMBLE_CORE_DIR . 'inc/content-guards.php';
require_once ASSEMBLE_CORE_DIR . 'inc/site-settings.php';
require_once ASSEMBLE_CORE_DIR . 'inc/summits-ep.php';
require_once ASSEMBLE_CORE_DIR . 'inc/summits-extras.php';

register_deactivation_hook( __FILE__, static fn() => wp_clear_scheduled_hook( ASSEMBLE_SUMMITS_CRON ) );
