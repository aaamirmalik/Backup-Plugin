<?php
/**
 * Logs view.
 *
 * @var array $data Page data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice  = isset( $_GET['dbbp_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['dbbp_notice'] ) ) : '';
$type    = isset( $_GET['dbbp_type'] ) ? sanitize_html_class( wp_unslash( $_GET['dbbp_type'] ) ) : 'updated';
$filters = $data['filters'];
$logs    = $data['logs'];
?>
<div class="wrap dbbp-wrap">
	<h1><?php esc_html_e( 'Backup Logs', 'db-backup-pro' ); ?></h1>

	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice <?php echo esc_attr( 'error' === $type ? 'notice-error' : 'notice-success' ); ?> is-dismissible"><p><?php echo esc_html( rawurldecode( $notice ) ); ?></p></div>
	<?php endif; ?>

	<form method="get" class="dbbp-filter-row">
		<input type="hidden" name="page" value="dbbp-logs" />
		<select name="backup_type">
			<option value=""><?php esc_html_e( 'All Types', 'db-backup-pro' ); ?></option>
			<?php foreach ( array( 'daily', 'monthly', 'yearly', 'manual' ) as $type_option ) : ?>
				<option value="<?php echo esc_attr( $type_option ); ?>" <?php selected( $filters['backup_type'], $type_option ); ?>><?php echo esc_html( ucfirst( $type_option ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="status">
			<option value=""><?php esc_html_e( 'All Statuses', 'db-backup-pro' ); ?></option>
			<option value="success" <?php selected( $filters['status'], 'success' ); ?>><?php esc_html_e( 'Success', 'db-backup-pro' ); ?></option>
			<option value="failed" <?php selected( $filters['status'], 'failed' ); ?>><?php esc_html_e( 'Failed', 'db-backup-pro' ); ?></option>
			<option value="pending" <?php selected( $filters['status'], 'pending' ); ?>><?php esc_html_e( 'Pending', 'db-backup-pro' ); ?></option>
		</select>
		<input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
		<input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
		<select name="orderby">
			<option value="created_at" <?php selected( $filters['orderby'], 'created_at' ); ?>><?php esc_html_e( 'Date', 'db-backup-pro' ); ?></option>
			<option value="db_name" <?php selected( $filters['orderby'], 'db_name' ); ?>><?php esc_html_e( 'DB Name', 'db-backup-pro' ); ?></option>
			<option value="backup_type" <?php selected( $filters['orderby'], 'backup_type' ); ?>><?php esc_html_e( 'Backup Type', 'db-backup-pro' ); ?></option>
			<option value="status" <?php selected( $filters['orderby'], 'status' ); ?>><?php esc_html_e( 'Status', 'db-backup-pro' ); ?></option>
		</select>
		<select name="order">
			<option value="DESC" <?php selected( strtoupper( $filters['order'] ), 'DESC' ); ?>>DESC</option>
			<option value="ASC" <?php selected( strtoupper( $filters['order'] ), 'ASC' ); ?>>ASC</option>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'db-backup-pro' ); ?></button>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'db-backup-pro' ); ?></th>
				<th><?php esc_html_e( 'DB Name', 'db-backup-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'db-backup-pro' ); ?></th>
				<th><?php esc_html_e( 'File', 'db-backup-pro' ); ?></th>
				<th><?php esc_html_e( 'File Size', 'db-backup-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'db-backup-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'db-backup-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No logs found.', 'db-backup-pro' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log['created_at'] ); ?></td>
						<td><?php echo esc_html( $log['db_name'] . ' (' . $log['db_type'] . ')' ); ?></td>
						<td><?php echo esc_html( ucfirst( $log['backup_type'] ) ); ?></td>
						<td><?php echo esc_html( $log['filename'] ); ?></td>
						<td><?php echo esc_html( size_format( (int) $log['file_size'] ) ); ?></td>
						<td><span class="dbbp-status dbbp-status-<?php echo esc_attr( $log['status'] ); ?>"><?php echo esc_html( strtoupper( $log['status'] ) ); ?></span></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-logs' ) ); ?>" style="display:inline-block;">
								<?php wp_nonce_field( 'dbbp_action_download_backup', 'dbbp_nonce' ); ?>
								<input type="hidden" name="dbbp_action" value="download_backup" />
								<input type="hidden" name="log_id" value="<?php echo esc_attr( $log['id'] ); ?>" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Download', 'db-backup-pro' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-logs' ) ); ?>" style="display:inline-block;">
								<?php wp_nonce_field( 'dbbp_action_reupload_backup', 'dbbp_nonce' ); ?>
								<input type="hidden" name="dbbp_action" value="reupload_backup" />
								<input type="hidden" name="log_id" value="<?php echo esc_attr( $log['id'] ); ?>" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Re-upload', 'db-backup-pro' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-logs' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('Delete backup from Drive?');">
								<?php wp_nonce_field( 'dbbp_action_delete_drive_backup', 'dbbp_nonce' ); ?>
								<input type="hidden" name="dbbp_action" value="delete_drive_backup" />
								<input type="hidden" name="log_id" value="<?php echo esc_attr( $log['id'] ); ?>" />
								<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'db-backup-pro' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
