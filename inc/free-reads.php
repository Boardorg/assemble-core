<?php
/**
 * The free-read meter (Phase 7, decision #9): signed-out visitors can read a
 * few reports ticked Public in full, then are asked to create a free account.
 * Signed-in free accounts always read Public reports in full.
 *
 * Here: the server side. Public reports carry their full text in the page
 * (the feed's opt-in `afr_public_full_text`); the theme's meter counts reads
 * in the browser, because WP Engine caches signed-out pages and can't count
 * per visitor. That makes it a soft meter, acceptable only because these
 * reports are Public. Members-only reports never reach the public view, so
 * they're never affected (the feed's gate is unchanged).
 *
 * The number of reads is the `free_reads` Site Setting (per rolling 8 hours).
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'afr_public_full_text', '__return_true' );

/** Free reads per 8 hours for signed-out visitors; 0 asks for an account straight away. */
function assemble_free_reads(): int {
	return max( 0, (int) assemble_site_setting( 'free_reads', 2 ) );
}
