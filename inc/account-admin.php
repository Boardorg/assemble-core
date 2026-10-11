<?php
/**
 * The account fields on wp-admin's user screens (Users → Profile / Edit User)
 * and two columns in the Users list. Test audiences are editable only by
 * people who can promote users, and only off theassemble.com.
 */

defined( 'ABSPATH' ) || exit;

/** Practice-area labels for wp-admin (the theme owns the public ones). */
function assemble_admin_area_labels(): array {
	return [
		'human-resources' => 'Human Capital',
		'manufacturing'   => 'Supply Chain',
		'marketing'       => 'Marketing & Comms',
		'technology'      => 'Technology',
		'finance'         => 'Finance',
	];
}

/** Print the Assemble section on a user screen. */
function assemble_account_admin_fields( WP_User $user ): void {
	$profile     = assemble_account_profile( $user->ID );
	$communities = class_exists( 'AFR_Communities' ) ? AFR_Communities::all() : [];
	$by_area     = assemble_communities_by_area();
	$tests       = (array) ( get_user_meta( $user->ID, ASSEMBLE_META_TEST_AUDIENCES, true ) ?: [] );

	wp_nonce_field( 'assemble_account_admin', 'assemble_account_admin_nonce' );
	?>
	<h2>Assemble account</h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="assemble_company">Company</label></th>
			<td><input type="text" class="regular-text" id="assemble_company" name="assemble_company" value="<?php echo esc_attr( $profile['company'] ); ?>"></td>
		</tr>
		<tr>
			<th><label for="assemble_job_title">Job title</label></th>
			<td><input type="text" class="regular-text" id="assemble_job_title" name="assemble_job_title" value="<?php echo esc_attr( $profile['job_title'] ); ?>"></td>
		</tr>
		<tr>
			<th>Follows</th>
			<td>
				<?php foreach ( assemble_admin_area_labels() as $area => $label ) : ?>
					<fieldset style="margin-bottom:8px">
						<label><input type="checkbox" name="assemble_areas[]" value="<?php echo esc_attr( $area ); ?>" <?php checked( in_array( $area, $profile['areas'], true ) ); ?>> <strong><?php echo esc_html( $label ); ?></strong></label>
						<?php foreach ( $by_area[ $area ] ?? [] as $slug ) : ?>
							<label style="margin-left:16px"><input type="checkbox" name="assemble_communities[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $profile['communities'], true ) ); ?>> <?php echo esc_html( (string) ( $communities[ $slug ]['name'] ?? $slug ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				<?php endforeach; ?>
				<p class="description">Communities count only inside a followed practice area.</p>
			</td>
		</tr>
		<?php if ( current_user_can( 'promote_users' ) ) : ?>
			<tr>
				<th>Test audiences (until SSO)</th>
				<td>
					<?php if ( assemble_test_audiences_enabled() ) : ?>
						<?php foreach ( array_diff( assemble_known_audiences(), [ 'Public' ] ) as $audience ) : ?>
							<label style="margin-right:16px"><input type="checkbox" name="assemble_test_audiences[]" value="<?php echo esc_attr( $audience ); ?>" <?php checked( in_array( $audience, $tests, true ) ); ?>> <?php echo esc_html( $audience ); ?></label>
						<?php endforeach; ?>
						<p class="description">For testing which view of a gated Field Report this user gets. Ignored on theassemble.com; SSO replaces it.</p>
					<?php else : ?>
						<p class="description">Membership comes from SSO on this site; test audiences are off.</p>
					<?php endif; ?>
				</td>
			</tr>
		<?php endif; ?>
	</table>
	<?php
}
add_action( 'show_user_profile', 'assemble_account_admin_fields' );
add_action( 'edit_user_profile', 'assemble_account_admin_fields' );

/** Save the Assemble section. */
function assemble_account_admin_save( int $user_id ): void {
	if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['assemble_account_admin_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['assemble_account_admin_nonce'] ) ), 'assemble_account_admin' ) ) {
		return;
	}

	$list    = static fn ( string $key ): array => isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) ) : [];
	$follows = assemble_normalise_follows( $list( 'assemble_areas' ), $list( 'assemble_communities' ) );

	update_user_meta( $user_id, ASSEMBLE_META_COMPANY, sanitize_text_field( wp_unslash( (string) ( $_POST['assemble_company'] ?? '' ) ) ) );
	update_user_meta( $user_id, ASSEMBLE_META_JOB_TITLE, sanitize_text_field( wp_unslash( (string) ( $_POST['assemble_job_title'] ?? '' ) ) ) );
	update_user_meta( $user_id, ASSEMBLE_META_FOLLOW_AREAS, $follows['areas'] );
	update_user_meta( $user_id, ASSEMBLE_META_FOLLOW_GROUPS, $follows['communities'] );

	if ( current_user_can( 'promote_users' ) && assemble_test_audiences_enabled() ) {
		$tests = assemble_clean_audiences( $list( 'assemble_test_audiences' ) );
		$tests ? update_user_meta( $user_id, ASSEMBLE_META_TEST_AUDIENCES, $tests ) : delete_user_meta( $user_id, ASSEMBLE_META_TEST_AUDIENCES );
	}
}
add_action( 'personal_options_update', 'assemble_account_admin_save' );
add_action( 'edit_user_profile_update', 'assemble_account_admin_save' );

// Users list: Company and Follows.
add_filter(
	'manage_users_columns',
	static function ( array $columns ): array {
		$columns['assemble_company'] = 'Company';
		$columns['assemble_follows'] = 'Follows';

		return $columns;
	}
);
add_filter(
	'manage_users_custom_column',
	static function ( string $output, string $column, int $user_id ): string {
		if ( 'assemble_company' === $column ) {
			return esc_html( (string) get_user_meta( $user_id, ASSEMBLE_META_COMPANY, true ) );
		}
		if ( 'assemble_follows' === $column ) {
			$labels = assemble_admin_area_labels();
			$areas  = (array) ( get_user_meta( $user_id, ASSEMBLE_META_FOLLOW_AREAS, true ) ?: [] );

			return esc_html( implode( ', ', array_map( static fn ( $a ) => $labels[ $a ] ?? $a, $areas ) ) );
		}

		return $output;
	},
	10,
	3
);
