<?php
/**
 * Πίνακας Ελέγχου: στατιστικά + μέλη με σελιδοποίηση.
 *
 * @package epappous-club
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$msg = isset( $_GET['epc_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['epc_msg'] ) ) : '';

$messages = [
	'created'  => [ 'notice-success', __( 'Το μέλος δημιουργήθηκε. Αν υπήρχε ήδη λογαριασμός WordPress με το ίδιο email, συνδέθηκε και ετέθη στην ομάδα Pappou Club (B2B King).', 'epappous-club' ) ],
	'exists'   => [ 'notice-error', __( 'Αυτό το email είναι ήδη εγγεγραμμένο στο club.', 'epappous-club' ) ],
	'invalid'  => [ 'notice-error', __( 'Συμπληρώστε όνομα, επώνυμο και έγκυρο email.', 'epappous-club' ) ],
	'disabled' => [ 'notice-warning', __( 'Το club δεν είναι ενεργό στις ρυθμίσεις.', 'epappous-club' ) ],
	'error'    => [ 'notice-error', __( 'Σφάλμα κατά την αποθήκευση.', 'epappous-club' ) ],
];

$page       = max( 1, (int) ( $_GET['members_paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$per_page   = class_exists( 'EPC_Admin_Screen_Options' )
	? EPC_Admin_Screen_Options::get_saved( EPC_Admin_Screen_Options::OPTION_MEMBERS )
	: 50;
$offset     = ( $page - 1 ) * $per_page;
$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members" );

$members = [];
if ( $total_rows > 0 ) {
	$members = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}epc_members ORDER BY joined_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		),
		ARRAY_A
	);
}
$members         = $members ?: [];
$total_members   = $total_rows;
$total_member_pg = max( 1, (int) ceil( max( $total_rows, 1 ) / max( $per_page, 1 ) ) );

$active_members       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members WHERE status = 'active'" );
$total_referrals      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals" );
$completed_referrals    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals WHERE status = 'completed'" );
$gift_redemptions_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_points_log WHERE reason = 'gift_redemption'" );
$gift_redemptions_pts   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(ABS(points)),0) FROM {$wpdb->prefix}epc_points_log WHERE reason = 'gift_redemption'" );

$members_base_url = add_query_arg(
	[
		'page'          => 'epc-dashboard',
		'members_paged' => '%#%',
	],
	admin_url( 'admin.php' )
);
?>
<div class="wrap epc-wrap">
	<div class="epc-header">
		<h1>
			<span class="dashicons dashicons-groups"></span>
			<?php esc_html_e( 'Πίνακας Ελέγχου', 'epappous-club' ); ?>
			<span class="epc-dash-subtitle"><?php echo esc_html( EPC_Settings::get( 'epc_club_name' ) ); ?></span>
		</h1>
	</div>

	<?php if ( $msg && isset( $messages[ $msg ] ) ) : ?>
		<div class="notice <?php echo esc_attr( $messages[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $msg ][1] ); ?></p></div>
	<?php endif; ?>

	<p class="description" style="margin:-8px 0 16px;">
		<?php esc_html_e( 'Σύνοψη συμμετοχής και γρήγορη πρόσβαση σε ρυθμίσεις και αναφορές.', 'epappous-club' ); ?>
	</p>

	<div class="epc-dashboard-grid">
		<div class="epc-stat-card epc-stat-members">
			<div class="epc-stat-icon"><span class="dashicons dashicons-admin-users"></span></div>
			<div class="epc-stat-body">
				<span class="epc-stat-number"><?php echo esc_html( (string) $active_members ); ?></span>
				<span class="epc-stat-label"><?php esc_html_e( 'Ενεργά Μέλη', 'epappous-club' ); ?></span>
				<span class="epc-stat-sub"><?php printf( esc_html__( '%d συνολικά', 'epappous-club' ), $total_members ); ?></span>
			</div>
		</div>

		<div class="epc-stat-card epc-stat-referrals">
			<div class="epc-stat-icon"><span class="dashicons dashicons-share"></span></div>
			<div class="epc-stat-body">
				<span class="epc-stat-number"><?php echo esc_html( (string) $completed_referrals ); ?></span>
				<span class="epc-stat-label"><?php esc_html_e( 'Referrals (ολοκληρωμένα)', 'epappous-club' ); ?></span>
				<span class="epc-stat-sub"><?php printf( esc_html__( '%d συνολικά', 'epappous-club' ), $total_referrals ); ?></span>
			</div>
		</div>

		<div class="epc-stat-card epc-stat-redemptions">
			<div class="epc-stat-icon"><span class="dashicons dashicons-star-filled"></span></div>
			<div class="epc-stat-body">
				<span class="epc-stat-number"><?php echo esc_html( (string) $gift_redemptions_total ); ?></span>
				<span class="epc-stat-label"><?php esc_html_e( 'Εξαργυρώσεις Δώρων', 'epappous-club' ); ?></span>
				<span class="epc-stat-sub"><?php printf( esc_html__( '%d πόντοι σύνολο', 'epappous-club' ), $gift_redemptions_pts ); ?></span>
			</div>
		</div>
	</div>

	<div id="epc-dashboard-members-anchor" style="scroll-margin-top:46px;"></div>

	<h2 class="nav-tab-wrapper" style="border:none;background:transparent;padding:0;margin:24px 0 16px;display:flex;align-items:center;gap:8px;">
		<span class="dashicons dashicons-admin-users" style="color:#64748b;"></span>
		<span style="margin:0;"><?php esc_html_e( 'Μέλη', 'epappous-club' ); ?></span>
	</h2>

	<p class="description" style="margin:-8px 0 12px;">
		<?php esc_html_e( 'Λίστα όλων των μελών. Χρησιμοποίησε τις Οθόνες (πάνω δεξιά) για «Μέλη ανά σελίδα».', 'epappous-club' ); ?>
	</p>

	<?php if ( EPC_Settings::get( 'epc_club_enabled' ) === '1' ) : ?>
		<div class="epc-card epc-dash-add-member" style="margin-bottom:24px;">
			<div class="epc-card-header">
				<h2><?php esc_html_e( 'Χειροκίνητη προσθήκη μέλους', 'epappous-club' ); ?></h2>
			</div>
			<div class="epc-card-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'epc_add_member' ); ?>
					<input type="hidden" name="action" value="epc_add_member" />
					<table class="form-table"><tbody>
						<tr>
							<th scope="row"><label for="epc_add_first"><?php esc_html_e( 'Όνομα', 'epappous-club' ); ?></label></th>
							<td><input type="text" class="regular-text" id="epc_add_first" name="first_name" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="epc_add_last"><?php esc_html_e( 'Επώνυμο', 'epappous-club' ); ?></label></th>
							<td><input type="text" class="regular-text" id="epc_add_last" name="last_name" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="epc_add_email"><?php esc_html_e( 'Email', 'epappous-club' ); ?></label></th>
							<td><input type="email" class="regular-text" id="epc_add_email" name="email" required autocomplete="email" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="epc_add_phone"><?php esc_html_e( 'Τηλέφωνο', 'epappous-club' ); ?></label></th>
							<td><input type="text" class="regular-text" id="epc_add_phone" name="phone" /></td>
						</tr>
					</tbody></table>
					<p>
						<?php submit_button( __( 'Προσθήκη μέλους', 'epappous-club' ), 'primary', 'submit', false ); ?>
					</p>
				</form>
			</div>
		</div>
	<?php endif; ?>

	<div class="epc-card">
		<div class="epc-card-header">
			<h2><?php esc_html_e( 'Λίστα μελών', 'epappous-club' ); ?></h2>
			<?php if ( $total_rows > 0 ) : ?>
				<span class="epc-muted" style="font-size:13px;">
					<?php
					printf(
						esc_html__( 'Εμφάνιση %1$d–%2$d από %3$d', 'epappous-club' ),
						(int) min( $offset + 1, $total_rows ),
						(int) min( $offset + count( $members ), $total_rows ),
						$total_rows
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<div class="epc-card-body" style="overflow-x:auto;">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'epappous-club' ); ?></th>
						<th><?php esc_html_e( 'Ονοματεπώνυμο', 'epappous-club' ); ?></th>
						<th><?php esc_html_e( 'Email', 'epappous-club' ); ?></th>
						<th><?php esc_html_e( 'User ID', 'epappous-club' ); ?></th>
						<th><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></th>
						<th><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $members ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Δεν υπάρχουν εγγραφές.', 'epappous-club' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $members as $m ) : ?>
							<tr>
								<td><?php echo (int) $m['id']; ?></td>
								<td><?php echo esc_html( trim( ( $m['first_name'] ?? '' ) . ' ' . ( $m['last_name'] ?? '' ) ) ); ?></td>
								<td><?php echo esc_html( $m['email'] ?? '' ); ?></td>
								<td><?php echo $m['user_id'] ? (int) $m['user_id'] : '—'; ?></td>
								<td><?php echo (int) ( $m['points'] ?? 0 ); ?></td>
								<td><?php echo esc_html( $m['status'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php if ( $total_member_pg > 1 ) : ?>
		<div class="tablenav bottom" style="padding:12px 0;">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						[
							'base'      => esc_url_raw( $members_base_url ),
							'format'    => '',
							'current'   => $page,
							'total'     => $total_member_pg,
							'type'      => 'plain',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						]
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<div class="epc-quick-links">
		<h2><?php esc_html_e( 'Γρήγοροι σύνδεσμοι', 'epappous-club' ); ?></h2>
		<div class="epc-links-grid">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-settings' ) ); ?>" class="epc-link-card">
				<span class="dashicons dashicons-admin-settings"></span>
				<span><?php esc_html_e( 'Ρυθμίσεις', 'epappous-club' ); ?></span>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-referrals' ) ); ?>" class="epc-link-card">
				<span class="dashicons dashicons-share"></span>
				<span><?php esc_html_e( 'Ιστορικό Referrals', 'epappous-club' ); ?></span>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-points-log' ) ); ?>" class="epc-link-card">
				<span class="dashicons dashicons-list-view"></span>
				<span><?php esc_html_e( 'Ιστορικό πόντων', 'epappous-club' ); ?></span>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-settings&tab=referral' ) ); ?>" class="epc-link-card">
				<span class="dashicons dashicons-info"></span>
				<span><?php esc_html_e( 'Ρυθμίσεις Referral', 'epappous-club' ); ?></span>
			</a>
		</div>
	</div>
</div>
