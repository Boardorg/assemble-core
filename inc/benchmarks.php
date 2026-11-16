<?php
/**
 * Decision Intelligence Benchmark links for the Benchmarks page (/benchmark/,
 * the theme's page-benchmark.php). The reports, the survey and personal
 * results all live in the benchmark app on report.theassemble.com.
 *
 * Every community links to the app's landing opened on that community
 * (`/report?c=<slug>`), which shows every round for it: the full report, the
 * survey to get personalized results, or the member's own report once they
 * log in. So a new round needs no change here. Filter: `assemble/benchmark_reports`.
 */

defined( 'ABSPATH' ) || exit;

/**
 * A link into the benchmark app, built from the `benchmark_url` Site Setting.
 *
 * @param array<string,string> $args     Query args (`c` = community slug, `login` = '1').
 * @param string               $fragment Optional anchor, without the '#'.
 */
function assemble_benchmark_app_url( array $args = [], string $fragment = '' ): string {
	$base = untrailingslashit( (string) assemble_site_setting( 'benchmark_url' ) );
	if ( '' === $base ) {
		$base = 'https://report.theassemble.com';
	}
	// The app is served under /report (its basePath).
	if ( ! preg_match( '#/report$#', $base ) ) {
		$base .= '/report';
	}
	$url = $args ? add_query_arg( array_map( 'rawurlencode', $args ), $base ) : $base;

	return '' !== $fragment ? $url . '#' . $fragment : $url;
}

/**
 * One group of benchmark links: every community, tagged with its practice
 * area (the `data-area` keys), each opening the app on that community.
 *
 * @return array<int,array{title:string,lead:string,reports:array<int,array{label:string,area:string,url:string}>}>
 */
function assemble_benchmark_reports(): array {
	// App community slug => [ label, practice area ].
	$communities = [
		'tmb'    => [ 'Talent Marketing', 'human-resources' ],
		'ldb'    => [ 'Learning & Development', 'human-resources' ],
		'ceb'    => [ 'Culture & Experience', 'human-resources' ],
		'dei'    => [ 'DEI', 'human-resources' ],
		'scb'    => [ 'Supply Chain', 'manufacturing' ],
		'mfb'    => [ 'Manufacturing', 'manufacturing' ],
		'esg'    => [ 'ESG / Sustainability', 'manufacturing' ],
		'csr'    => [ 'CSR / Social Impact', 'manufacturing' ],
		'esgcsr' => [ 'ESG & CSR', 'manufacturing' ],
		'smorg'  => [ 'Social Media', 'marketing' ],
		'smoh'   => [ 'Healthcare Social Media', 'marketing' ],
		'aeo'    => [ 'AEO', 'marketing' ],
		'dpb'    => [ 'Data Privacy', 'technology' ],
		'eds'    => [ 'Data Strategy', 'technology' ],
	];

	$reports = [];
	foreach ( $communities as $slug => [ $label, $area ] ) {
		$reports[] = [
			'label' => $label,
			'area'  => $area,
			'url'   => assemble_benchmark_app_url( [ 'c' => $slug ], 'explore' ),
		];
	}

	$editions = [
		[
			'title'   => 'Three views of how your peers are deciding',
			'lead'    => 'Technology, Vendors, and Partnerships; Talent, Org Design, Comp, and Careers; and Strategic Priorities and Resourcing. Choose your community to see every round.',
			'reports' => $reports,
		],
	];

	/**
	 * Filter the benchmark link groups.
	 *
	 * @param array $editions
	 */
	return (array) apply_filters( 'assemble/benchmark_reports', $editions );
}
