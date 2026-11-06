<?php
/**
 * Published Decision Intelligence Benchmark reports, for the Benchmarks page
 * (/benchmark/, the theme's page-benchmark.php). Reports live on
 * report.theassemble.com; this is the list of public links, newest edition
 * first, each report tagged with its practice area (the `data-area` keys).
 *
 * Add an edition at the top when a new round is released to the public (Comms
 * posts the links in #comms-mktg). Filter: `assemble/benchmark_reports`.
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return array<int,array{title:string,date:string,reports:array<int,array{label:string,area:string,url:string}>}>
 */
function assemble_benchmark_reports(): array {
	$base = 'https://report.theassemble.com/report/q3-2026/';

	$editions = [
		[
			'title'   => 'Talent and Teams: decisions for 2026–2027',
			'date'    => 'Q3 2026',
			'reports' => [
				[ 'label' => 'Culture & Experience', 'area' => 'human-resources', 'url' => $base . 'ceb' ],
				[ 'label' => 'DEI', 'area' => 'human-resources', 'url' => $base . 'dei' ],
				[ 'label' => 'Learning & Development', 'area' => 'human-resources', 'url' => $base . 'ldb' ],
				[ 'label' => 'Talent Marketing', 'area' => 'human-resources', 'url' => $base . 'tmb' ],
				[ 'label' => 'ESG & CSR', 'area' => 'manufacturing', 'url' => $base . 'esgcsr' ],
				[ 'label' => 'ESG / Sustainability', 'area' => 'manufacturing', 'url' => $base . 'esg' ],
				[ 'label' => 'CSR / Social Impact', 'area' => 'manufacturing', 'url' => $base . 'csr' ],
				[ 'label' => 'Manufacturing & Supply Chain', 'area' => 'manufacturing', 'url' => $base . 'mfb' ],
				[ 'label' => 'Supply Chain', 'area' => 'manufacturing', 'url' => $base . 'scb' ],
				[ 'label' => 'AEO', 'area' => 'marketing', 'url' => $base . 'aeo' ],
				[ 'label' => 'Social Media', 'area' => 'marketing', 'url' => $base . 'smorg' ],
				[ 'label' => 'Healthcare Social Media', 'area' => 'marketing', 'url' => $base . 'smoh' ],
				[ 'label' => 'Data Privacy', 'area' => 'technology', 'url' => $base . 'dpb' ],
			],
		],
	];

	/**
	 * Filter the published benchmark editions.
	 *
	 * @param array $editions
	 */
	return (array) apply_filters( 'assemble/benchmark_reports', $editions );
}
