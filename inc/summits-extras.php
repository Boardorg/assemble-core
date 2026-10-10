<?php
/**
 * Assemble-only extras per summit (agreed with Cale, 2026-10-09).
 *
 * EP holds the summit facts (title, dates, place, links). These are Assemble's
 * own presentation choices that EP doesn't keep:
 *
 *   - area:        the practice-area key that colours the summit (`data-area`);
 *                  '' renders neutral (Life Sciences has no colour ramp);
 *   - area_label:  the eyebrow, using EP's practice areas ("Manufacturing & Safety");
 *   - communities: Community slugs (the feed's community directory) the summit is
 *                  featured under on /summits/ and the homepage; ['*'] means every
 *                  Community in its area; [] means none;
 *   - blurb:       the one-line card description;
 *   - logo:        a colour logo in the shared image library, relative to
 *                  wp-content/assets/ (for light backgrounds).
 *
 * Merged by assemble_summits_with_extras(). Extras never override an EP fact,
 * and a summit missing from this map still renders: neutral, no blurb, no logo.
 * To change one without a commit, use the `assemble/summit_extras` filter.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The extras map, keyed by EP summit code.
 *
 * @return array<string,array{area:string,area_label:string,communities:string[],blurb:string,logo:string}>
 */
function assemble_summit_extras(): array {
	$human_capital = [ 'area' => 'human-resources', 'area_label' => __( 'Human Capital', 'assemble-core' ) ];
	$supply_chain  = [ 'area' => 'manufacturing', 'area_label' => __( 'Supply Chain', 'assemble-core' ) ];
	$manufacturing = [ 'area' => 'manufacturing', 'area_label' => __( 'Manufacturing & Safety', 'assemble-core' ) ];
	$marketing     = [ 'area' => 'marketing', 'area_label' => __( 'Marketing & Comms', 'assemble-core' ) ];
	$finance       = [ 'area' => 'finance', 'area_label' => __( 'Finance', 'assemble-core' ) ];
	$life_sciences = [ 'area' => '', 'area_label' => __( 'Life Sciences', 'assemble-core' ) ];

	$extras = [
		'nasces'  => $supply_chain + [
			'communities' => [ 'supply-chain' ],
			'blurb'       => __( 'Supply chain strategy, planning, resilience, logistics, and operations.', 'assemble-core' ),
			'logo'        => 'summits/nasces-wordmark-color.png',
		],
		'napes'   => $supply_chain + [
			'communities' => [ 'supply-chain' ],
			'blurb'       => __( 'Procurement, sourcing, supplier strategy, and value creation.', 'assemble-core' ),
			'logo'        => 'summits/napes-wordmark-color.png',
		],
		'nasrs'   => $supply_chain + [
			'communities' => [ 'esg-csr' ],
			'blurb'       => __( 'Sustainability, responsibility, ESG, and measurable progress.', 'assemble-core' ),
			'logo'        => 'summits/nasrs-wordmark-color.png',
		],
		'fws'     => $manufacturing + [
			'communities' => [ 'manufacturing' ],
			'blurb'       => __( 'Food safety, quality, manufacturing, and operational excellence.', 'assemble-core' ),
			'logo'        => 'summits/fws-wordmark-color.png',
		],
		'names'   => $manufacturing + [
			'communities' => [ 'manufacturing' ],
			'blurb'       => __( 'Manufacturing leadership, operational excellence, automation, and resilience.', 'assemble-core' ),
			'logo'        => 'summits/names-wordmark-color.png',
		],
		'namls'   => $marketing + [
			'communities' => [ '*' ],
			'blurb'       => __( 'Marketing leadership, brand, demand generation, and commercial growth.', 'assemble-core' ),
			'logo'        => 'summits/namls-wordmark-color.png',
		],
		'nafes'   => $finance + [
			'communities' => [],
			'blurb'       => __( 'Finance leadership, planning discipline, and enterprise transformation.', 'assemble-core' ),
			'logo'        => 'summits/nafes-spring-wordmark-color.png',
		],
		'nafesf'  => $finance + [
			'communities' => [],
			'blurb'       => __( 'Planning, transformation, AI adoption, and enterprise performance.', 'assemble-core' ),
			'logo'        => 'summits/nafes-fall-wordmark-color.png',
		],
		'nahres'  => $human_capital + [
			'communities' => [ '*' ],
			'blurb'       => __( 'Talent strategy, organization design, culture, workforce planning, and people leadership.', 'assemble-core' ),
			'logo'        => 'summits/nahres-spring-wordmark-color.png',
		],
		'nahresf' => $human_capital + [
			'communities' => [ '*' ],
			'blurb'       => __( 'People strategy, talent, culture, workforce experience, and capability.', 'assemble-core' ),
			'logo'        => 'summits/nahres-fall-wordmark-color.png',
		],
		'nales'   => $human_capital + [
			'communities' => [ 'learning-development' ],
			'blurb'       => __( 'Enterprise learning, leadership development, and workforce readiness.', 'assemble-core' ),
			'logo'        => 'summits/nales-wordmark-color.png',
		],
		'bmws'    => $life_sciences + [
			'communities' => [],
			'blurb'       => __( 'Biomanufacturing operations, quality, capacity, and next-generation production.', 'assemble-core' ),
			'logo'        => 'summits/bmws-wordmark-color.png',
		],
		'mdws'    => $life_sciences + [
			'communities' => [],
			'blurb'       => __( 'Medical device quality, operations, supply chain, regulatory, and innovation leaders.', 'assemble-core' ),
			'logo'        => 'summits/mdws-wordmark-color.png',
		],
		'pmws'    => $life_sciences + [
			'communities' => [],
			'blurb'       => __( 'Pharma manufacturing, quality, technology transfer, and operational readiness.', 'assemble-core' ),
			'logo'        => 'summits/pmws-wordmark-color.png',
		],
	];

	// Fall editions share their Spring summit's extras (the library has no separate Fall logo for these).
	$extras['namesf'] = $extras['names'];
	$extras['nasrsf'] = $extras['nasrs'];

	/**
	 * Filter the Assemble-only extras (practice area, Communities, blurb, logo) per summit code.
	 *
	 * @param array $extras
	 */
	return (array) apply_filters( 'assemble/summit_extras', $extras );
}

/**
 * Summits from assemble_summits() with their extras merged in: `area`,
 * `area_label`, `communities`, `blurb` and `logo` (an absolute URL, or '').
 *
 * @param bool $upcoming_only Leave out summits whose end date has passed.
 * @return array<int,array>
 */
function assemble_summits_with_extras( bool $upcoming_only = true ): array {
	$extras   = assemble_summit_extras();
	$defaults = [ 'area' => '', 'area_label' => '', 'communities' => [], 'blurb' => '', 'logo' => '' ];

	return array_map(
		static function ( array $summit ) use ( $extras, $defaults ): array {
			$extra = array_intersect_key( array_merge( $defaults, (array) ( $extras[ $summit['code'] ] ?? [] ) ), $defaults );

			$extra['communities'] = array_values( array_filter( array_map( 'strval', (array) $extra['communities'] ) ) );
			$extra['logo']        = '' !== $extra['logo'] ? content_url( 'assets/' . ltrim( (string) $extra['logo'], '/' ) ) : '';

			// EP's facts win: an extra never replaces a key the importer filled.
			return $summit + $extra;
		},
		assemble_summits( $upcoming_only )
	);
}
