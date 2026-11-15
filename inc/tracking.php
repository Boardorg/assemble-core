<?php
/**
 * Analytics and consent tags, on theassemble.com only.
 *
 * The launch copy replaces production's database, and with it the tags
 * production loads today (WPCode and Elementor). They live here instead, keyed
 * off the host like the noindex guard: nothing loads on the beta, and
 * everything is live the moment the domain moves (Cale, 2026-11-15: GA4,
 * CookieYes, Microsoft Clarity and ZoomInfo). CookieYes loads first so it can
 * hold back the others until a visitor consents.
 */

defined( 'ABSPATH' ) || exit;

/** Tag IDs. Filterable, so a tag can be changed or dropped (empty string) without a release. */
function assemble_tracking_ids(): array {
	return (array) apply_filters(
		'assemble/tracking_ids',
		[
			'cookieyes' => 'e56e0a5bc78e60a2f11a796a000303a9',
			'ga4'       => 'G-RGC1CS8YFT',
			'clarity'   => 'vuscunbme8',
			'zoominfo'  => 'affa95c8331695055470',
		]
	);
}

/** The tag markup for <head>. */
function assemble_tracking_markup(): string {
	$ids = assemble_tracking_ids();
	$out = [];

	if ( ! empty( $ids['cookieyes'] ) ) {
		$out[] = sprintf(
			'<script id="cookieyes" type="text/javascript" src="%s"></script>',
			esc_url( 'https://cdn-cookieyes.com/client_data/' . rawurlencode( (string) $ids['cookieyes'] ) . '/script.js' )
		);
	}

	if ( ! empty( $ids['ga4'] ) ) {
		$ga4   = esc_js( (string) $ids['ga4'] );
		$out[] = sprintf( '<script async src="%s"></script>', esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( (string) $ids['ga4'] ) ) );
		$out[] = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$ga4}');</script>";
	}

	if ( ! empty( $ids['clarity'] ) ) {
		$clarity = esc_js( (string) $ids['clarity'] );
		$out[]   = "<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,'clarity','script','{$clarity}');</script>";
	}

	if ( ! empty( $ids['zoominfo'] ) ) {
		// ZoomInfo's WebSights tag, de-obfuscated from production's WPCode snippet.
		$zi    = esc_js( (string) $ids['zoominfo'] );
		$out[] = "<script>window['ZIProjectKey']='{$zi}';(function(){var zi=document.createElement('script');zi.type='text/javascript';zi.async=true;zi.src='https://js.zi-scripts.com/zi-tag.js';document.readyState==='complete'?document.body.appendChild(zi):window.addEventListener('load',function(){document.body.appendChild(zi);});})();</script>";
	}

	return implode( "\n", $out );
}

if ( assemble_is_production_host() ) {
	add_action(
		'wp_head',
		static function (): void {
			echo assemble_tracking_markup() . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped IDs above.
		},
		1
	);
}
