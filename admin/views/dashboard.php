<?php
/**
 * Dashboard view.
 *
 * @var array $data Page data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice = isset( $_GET['dbbp_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['dbbp_notice'] ) ) : '';
$type   = isset( $_GET['dbbp_type'] ) ? sanitize_html_class( wp_unslash( $_GET['dbbp_type'] ) ) : 'updated';
?>
<div class="wrap dbbp-wrap">
	<h1><?php esc_html_e( 'DB Backup Pro Dashboard', 'db-backup-pro' ); ?></h1>

	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice <?php echo esc_attr( 'error' === $type ? 'notice-error' : 'notice-success' ); ?> is-dismissible"><p><?php echo esc_html( rawurldecode( $notice ) ); ?></p></div>
	<?php endif; ?>

	<div class="dbbp-cards">
		<?php
		$cards = array(
			'daily'   => $data['latest_daily'],
			'monthly' => $data['latest_monthly'],
			'yearly'  => $data['latest_yearly'],
		);
		foreach ( $cards as $label => $row ) :
			$status = $row['status'] ?? 'pending';
			?>
			<div class="dbbp-card">
				<h3><?php echo esc_html( ucfirst( $label ) ); ?> <?php esc_html_e( 'Backup', 'db-backup-pro' ); ?></h3>
				<p><strong><?php esc_html_e( 'Status:', 'db-backup-pro' ); ?></strong> <span class="dbbp-status dbbp-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( strtoupper( $status ) ); ?></span></p>
				<p><strong><?php esc_html_e( 'File:', 'db-backup-pro' ); ?></strong> <?php echo esc_html( $row['filename'] ?? '-' ); ?></p>
				<p><strong><?php esc_html_e( 'Date:', 'db-backup-pro' ); ?></strong> <?php echo esc_html( $row['created_at'] ?? '-' ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="dbbp-cards">
		<div class="dbbp-card">
			<h3><?php esc_html_e( 'Next Daily Run', 'db-backup-pro' ); ?></h3>
			<p><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $data['next_daily'] ) ); ?></p>
		</div>
		<div class="dbbp-card">
			<h3><?php esc_html_e( 'Next Monthly Run', 'db-backup-pro' ); ?></h3>
			<p><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $data['next_monthly'] ) ); ?></p>
		</div>
		<div class="dbbp-card">
			<h3><?php esc_html_e( 'Next Yearly Run', 'db-backup-pro' ); ?></h3>
			<p><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $data['next_yearly'] ) ); ?></p>
		</div>
	</div>

	<div class="dbbp-manual-run">
		<h2><?php esc_html_e( 'Run Backup Now', 'db-backup-pro' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-dashboard' ) ); ?>">
			<?php wp_nonce_field( 'dbbp_action_run_backup', 'dbbp_nonce' ); ?>
			<input type="hidden" name="dbbp_action" value="run_backup" />
			<label>
				<?php esc_html_e( 'Backup Type', 'db-backup-pro' ); ?>
				<select name="backup_type">
					<option value="manual"><?php esc_html_e( 'Manual', 'db-backup-pro' ); ?></option>
					<option value="daily"><?php esc_html_e( 'Daily', 'db-backup-pro' ); ?></option>
					<option value="monthly"><?php esc_html_e( 'Monthly', 'db-backup-pro' ); ?></option>
					<option value="yearly"><?php esc_html_e( 'Yearly', 'db-backup-pro' ); ?></option>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Database', 'db-backup-pro' ); ?>
				<select name="db_key">
					<option value="all"><?php esc_html_e( 'All Databases', 'db-backup-pro' ); ?></option>
					<?php foreach ( $data['connections'] as $db_key => $conn ) : ?>
						<option value="<?php echo esc_attr( $db_key ); ?>"><?php echo esc_html( $conn['label'] . ' (' . $conn['type'] . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Run Backup Now', 'db-backup-pro' ); ?></button>
		</form>
	</div>
</div>
