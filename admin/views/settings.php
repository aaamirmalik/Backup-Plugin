<?php
/**
 * Settings view.
 *
 * @var array $data Page data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tab    = $data['tab'] ?? 'database_connections';
$notice = isset( $_GET['dbbp_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['dbbp_notice'] ) ) : '';
$type   = isset( $_GET['dbbp_type'] ) ? sanitize_html_class( wp_unslash( $_GET['dbbp_type'] ) ) : 'updated';
$google = $data['google'];
$sched  = $data['settings']['schedule'] ?? array();
$notif  = $data['settings']['notifications'] ?? array();
$edit   = is_array( $data['edit_conn'] ?? null ) ? $data['edit_conn'] : array();

$form_key         = $edit['key'] ?? '';
$form_label       = $edit['label'] ?? '';
$form_type        = $edit['type'] ?? 'mysql';
$form_host        = $edit['host'] ?? '';
$form_port        = isset( $edit['port'] ) ? (int) $edit['port'] : 3306;
$form_database    = $edit['database'] ?? '';
$form_username    = $edit['username'] ?? '';
$form_conn_string = $edit['conn_string'] ?? '';
$form_sqlite_path = $edit['sqlite_path'] ?? '';
$form_ssl         = ! empty( $edit['ssl_enabled'] );
$form_mongo       = ! empty( $edit['mongo_enabled'] );
$form_mssql       = ! empty( $edit['mssql_enabled'] );
$form_enabled     = isset( $edit['enabled'] ) ? ! empty( $edit['enabled'] ) : true;
?>
<div class="wrap dbbp-wrap">
	<h1><?php esc_html_e( 'DB Backup Pro Settings', 'db-backup-pro' ); ?></h1>

	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice <?php echo esc_attr( 'error' === $type ? 'notice-error' : 'notice-success' ); ?> is-dismissible"><p><?php echo esc_html( rawurldecode( $notice ) ); ?></p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=database_connections' ) ); ?>" class="nav-tab <?php echo esc_attr( 'database_connections' === $tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Database Connections', 'db-backup-pro' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=google_drive' ) ); ?>" class="nav-tab <?php echo esc_attr( 'google_drive' === $tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Google Drive', 'db-backup-pro' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=schedule' ) ); ?>" class="nav-tab <?php echo esc_attr( 'schedule' === $tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Schedule', 'db-backup-pro' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=notifications' ) ); ?>" class="nav-tab <?php echo esc_attr( 'notifications' === $tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Notifications', 'db-backup-pro' ); ?></a>
	</nav>

	<?php if ( 'database_connections' === $tab ) : ?>
		<h2><?php esc_html_e( 'Configured Databases', 'db-backup-pro' ); ?></h2>
		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Label', 'db-backup-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'db-backup-pro' ); ?></th>
				<th><?php esc_html_e( 'Host / Path', 'db-backup-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'db-backup-pro' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php if ( empty( $data['connections'] ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No databases configured yet.', 'db-backup-pro' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $data['connections'] as $conn_key => $conn ) : ?>
					<tr>
						<td><?php echo esc_html( $conn['label'] ); ?></td>
						<td><?php echo esc_html( $conn['type'] ); ?></td>
						<td><?php echo esc_html( ! empty( $conn['sqlite_path'] ) ? $conn['sqlite_path'] : ( $conn['host'] . ':' . $conn['port'] ) ); ?></td>
						<td>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=database_connections&edit_key=' . rawurlencode( $conn_key ) ) ); ?>"><?php esc_html_e( 'Edit', 'db-backup-pro' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=database_connections' ) ); ?>" style="display:inline-block;">
								<?php wp_nonce_field( 'dbbp_action_test_db_connection', 'dbbp_nonce' ); ?>
								<input type="hidden" name="dbbp_action" value="test_db_connection" />
								<input type="hidden" name="key" value="<?php echo esc_attr( $conn_key ); ?>" />
								<button type="submit" class="button"><?php esc_html_e( 'Test', 'db-backup-pro' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=database_connections' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('Delete this connection?');">
								<?php wp_nonce_field( 'dbbp_action_delete_db_connection', 'dbbp_nonce' ); ?>
								<input type="hidden" name="dbbp_action" value="delete_db_connection" />
								<input type="hidden" name="key" value="<?php echo esc_attr( $conn_key ); ?>" />
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete', 'db-backup-pro' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Add / Update Connection', 'db-backup-pro' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=database_connections' ) ); ?>" class="dbbp-form-grid">
			<?php wp_nonce_field( 'dbbp_action_save_db_connection', 'dbbp_nonce' ); ?>
			<input type="hidden" name="dbbp_action" value="save_db_connection" />
			<label><?php esc_html_e( 'Key (optional)', 'db-backup-pro' ); ?><input type="text" name="key" value="<?php echo esc_attr( $form_key ); ?>" /></label>
			<label><?php esc_html_e( 'Label', 'db-backup-pro' ); ?><input type="text" name="label" value="<?php echo esc_attr( $form_label ); ?>" required /></label>
			<label><?php esc_html_e( 'Type', 'db-backup-pro' ); ?>
				<select name="type">
					<?php foreach ( array( 'mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'postgresql' => 'PostgreSQL', 'neondb' => 'NeonDB', 'sqlite' => 'SQLite', 'mongodb' => 'MongoDB', 'mssql' => 'MSSQL' ) as $type_value => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_value ); ?>" <?php selected( $form_type, $type_value ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Host', 'db-backup-pro' ); ?><input type="text" name="host" value="<?php echo esc_attr( $form_host ); ?>" /></label>
			<label><?php esc_html_e( 'Port', 'db-backup-pro' ); ?><input type="number" name="port" value="<?php echo esc_attr( $form_port ); ?>" /></label>
			<label><?php esc_html_e( 'Database Name', 'db-backup-pro' ); ?><input type="text" name="database" value="<?php echo esc_attr( $form_database ); ?>" /></label>
			<label><?php esc_html_e( 'Username', 'db-backup-pro' ); ?><input type="text" name="username" value="<?php echo esc_attr( $form_username ); ?>" /></label>
			<label><?php esc_html_e( 'Password (leave empty to keep current)', 'db-backup-pro' ); ?><input type="password" name="password" /></label>
			<label><?php esc_html_e( 'Connection String Override', 'db-backup-pro' ); ?><input type="text" name="conn_string" value="<?php echo esc_attr( $form_conn_string ); ?>" /></label>
			<label><?php esc_html_e( 'SQLite File Path', 'db-backup-pro' ); ?><input type="text" name="sqlite_path" value="<?php echo esc_attr( $form_sqlite_path ); ?>" /></label>
			<label><input type="checkbox" name="ssl_enabled" value="1" <?php checked( $form_ssl ); ?> /> <?php esc_html_e( 'Use SSL/TLS', 'db-backup-pro' ); ?></label>
			<label><input type="checkbox" name="mongo_enabled" value="1" <?php checked( $form_mongo ); ?> /> <?php esc_html_e( 'Enable MongoDB dumps', 'db-backup-pro' ); ?></label>
			<label><input type="checkbox" name="mssql_enabled" value="1" <?php checked( $form_mssql ); ?> /> <?php esc_html_e( 'Enable MSSQL dumps', 'db-backup-pro' ); ?></label>
			<label><input type="checkbox" name="enabled" value="1" <?php checked( $form_enabled ); ?> /> <?php esc_html_e( 'Enabled', 'db-backup-pro' ); ?></label>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Connection', 'db-backup-pro' ); ?></button>
			<?php if ( ! empty( $form_key ) ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=database_connections' ) ); ?>"><?php esc_html_e( 'Clear Edit', 'db-backup-pro' ); ?></a>
			<?php endif; ?>
			</p>
		</form>
	<?php endif; ?>

	<?php if ( 'google_drive' === $tab ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=google_drive' ) ); ?>" class="dbbp-form-grid">
			<?php wp_nonce_field( 'dbbp_action_save_google_settings', 'dbbp_nonce' ); ?>
			<input type="hidden" name="dbbp_action" value="save_google_settings" />
			<label><?php esc_html_e( 'Google Client ID', 'db-backup-pro' ); ?><input type="text" name="client_id" value="<?php echo esc_attr( $google['client_id'] ?? '' ); ?>" /></label>
			<label><?php esc_html_e( 'Google Client Secret', 'db-backup-pro' ); ?><input type="password" name="client_secret" value="<?php echo esc_attr( $google['client_secret'] ?? '' ); ?>" /></label>
			<label><?php esc_html_e( 'Root Folder Path', 'db-backup-pro' ); ?><input type="text" name="root_folder" value="<?php echo esc_attr( $google['root_folder'] ?? '/DB-Backups' ); ?>" /></label>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Google Settings', 'db-backup-pro' ); ?></button></p>
		</form>

		<p>
			<strong><?php esc_html_e( 'Connection Status:', 'db-backup-pro' ); ?></strong>
			<?php echo ! empty( $google['oauth_connected'] ) ? '<span class="dbbp-status dbbp-status-success">Connected</span>' : '<span class="dbbp-status dbbp-status-failed">Not Connected</span>'; ?>
		</p>
		<?php if ( ! empty( $data['oauth_url'] ) ) : ?>
			<a class="button button-secondary" href="<?php echo esc_url( $data['oauth_url'] ); ?>"><?php esc_html_e( 'Connect Google Account', 'db-backup-pro' ); ?></a>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=google_drive' ) ); ?>" style="margin-top:12px;">
			<?php wp_nonce_field( 'dbbp_action_disconnect_google', 'dbbp_nonce' ); ?>
			<input type="hidden" name="dbbp_action" value="disconnect_google" />
			<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'db-backup-pro' ); ?></button>
		</form>
	<?php endif; ?>

	<?php if ( 'schedule' === $tab ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=schedule' ) ); ?>" class="dbbp-form-grid">
			<?php wp_nonce_field( 'dbbp_action_save_schedule_settings', 'dbbp_nonce' ); ?>
			<input type="hidden" name="dbbp_action" value="save_schedule_settings" />
			<label><?php esc_html_e( 'Daily Time', 'db-backup-pro' ); ?><input type="time" name="daily_time" value="<?php echo esc_attr( $sched['daily_time'] ?? '02:00' ); ?>" /></label>
			<label><?php esc_html_e( 'Monthly Time', 'db-backup-pro' ); ?><input type="time" name="monthly_time" value="<?php echo esc_attr( $sched['monthly_time'] ?? '02:10' ); ?>" /></label>
			<label><?php esc_html_e( 'Yearly Time', 'db-backup-pro' ); ?><input type="time" name="yearly_time" value="<?php echo esc_attr( $sched['yearly_time'] ?? '02:20' ); ?>" /></label>
			<label><?php esc_html_e( 'Daily Retention', 'db-backup-pro' ); ?><input type="number" name="daily_keep" value="<?php echo esc_attr( $sched['daily_keep'] ?? 8 ); ?>" /></label>
			<label><?php esc_html_e( 'Monthly Retention', 'db-backup-pro' ); ?><input type="number" name="monthly_keep" value="<?php echo esc_attr( $sched['monthly_keep'] ?? 12 ); ?>" /></label>
			<label><?php esc_html_e( 'Yearly Retention', 'db-backup-pro' ); ?><input type="number" name="yearly_keep" value="<?php echo esc_attr( $sched['yearly_keep'] ?? 5 ); ?>" /></label>
			<label><input type="checkbox" name="use_server_cron" value="1" <?php checked( ! empty( $sched['use_server_cron'] ) ); ?> /> <?php esc_html_e( 'Use server cron / WP-CLI instead of WP-Cron', 'db-backup-pro' ); ?></label>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'db-backup-pro' ); ?></button></p>
		</form>
	<?php endif; ?>

	<?php if ( 'notifications' === $tab ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dbbp-settings&tab=notifications' ) ); ?>" class="dbbp-form-grid">
			<?php wp_nonce_field( 'dbbp_action_save_notification_settings', 'dbbp_nonce' ); ?>
			<input type="hidden" name="dbbp_action" value="save_notification_settings" />
			<label><?php esc_html_e( 'Admin Email', 'db-backup-pro' ); ?><input type="email" name="admin_email" value="<?php echo esc_attr( $notif['admin_email'] ?? get_option( 'admin_email' ) ); ?>" /></label>
			<label><?php esc_html_e( 'Slack Webhook (optional)', 'db-backup-pro' ); ?><input type="url" name="slack_webhook" value="<?php echo esc_attr( $notif['slack_webhook'] ?? '' ); ?>" /></label>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Notifications', 'db-backup-pro' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>

