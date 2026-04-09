<?php
/**
 * Admin UI controller.
 *
 * @package DB_Backup_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 */
class DBBP_Admin_UI {
	/**
	 * Engine.
	 *
	 * @var DBBP_Backup_Engine
	 */
	private $engine;

	/**
	 * Logger.
	 *
	 * @var DBBP_Backup_Logger
	 */
	private $logger;

	/**
	 * Connector.
	 *
	 * @var DBBP_DB_Connector
	 */
	private $connector;

	/**
	 * Drive.
	 *
	 * @var DBBP_Google_Drive
	 */
	private $drive;

	/**
	 * Cron.
	 *
	 * @var DBBP_Cron_Manager
	 */
	private $cron;

	/**
	 * Constructor.
	 *
	 * @param DBBP_Backup_Engine $engine Engine.
	 * @param DBBP_Backup_Logger $logger Logger.
	 * @param DBBP_DB_Connector  $connector Connector.
	 * @param DBBP_Google_Drive  $drive Drive.
	 * @param DBBP_Cron_Manager  $cron Cron.
	 */
	public function __construct( $engine, $logger, $connector, $drive, $cron ) {
		$this->engine    = $engine;
		$this->logger    = $logger;
		$this->connector = $connector;
		$this->drive     = $drive;
		$this->cron      = $cron;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_requests' ) );
	}

	/**
	 * Register menu pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'DB Backup Pro', 'db-backup-pro' ),
			__( 'DB Backup Pro', 'db-backup-pro' ),
			'manage_options',
			'dbbp-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-database-export',
			58
		);

		add_submenu_page(
			'dbbp-dashboard',
			__( 'Dashboard', 'db-backup-pro' ),
			__( 'Dashboard', 'db-backup-pro' ),
			'manage_options',
			'dbbp-dashboard',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'dbbp-dashboard',
			__( 'Settings', 'db-backup-pro' ),
			__( 'Settings', 'db-backup-pro' ),
			'manage_options',
			'dbbp-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			'dbbp-dashboard',
			__( 'Logs', 'db-backup-pro' ),
			__( 'Logs', 'db-backup-pro' ),
			'manage_options',
			'dbbp-logs',
			array( $this, 'render_logs' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'dbbp' ) ) {
			return;
		}
		wp_enqueue_style( 'dbbp-admin', DBBP_PLUGIN_URL . 'admin/assets/admin.css', array(), DBBP_VERSION );
		wp_enqueue_script( 'dbbp-admin', DBBP_PLUGIN_URL . 'admin/assets/admin.js', array( 'jquery' ), DBBP_VERSION, true );
	}

	/**
	 * Handle actions from admin pages.
	 *
	 * @return void
	 */
	public function handle_requests() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->handle_google_callback();

		if ( empty( $_REQUEST['dbbp_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_REQUEST['dbbp_action'] ) );
		if ( ! isset( $_REQUEST['dbbp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['dbbp_nonce'] ) ), 'dbbp_action_' . $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'db-backup-pro' ) );
		}

		switch ( $action ) {
			case 'save_db_connection':
				$this->action_save_db_connection();
				break;
			case 'delete_db_connection':
				$this->action_delete_db_connection();
				break;
			case 'test_db_connection':
				$this->action_test_db_connection();
				break;
			case 'save_google_settings':
				$this->action_save_google_settings();
				break;
			case 'disconnect_google':
				$this->drive->disconnect();
				$this->redirect_with_notice( 'dbbp-settings', 'Google Drive disconnected.', 'updated' );
				break;
			case 'save_schedule_settings':
				$this->action_save_schedule_settings();
				break;
			case 'save_notification_settings':
				$this->action_save_notification_settings();
				break;
			case 'run_backup':
				$this->action_run_backup();
				break;
			case 'delete_drive_backup':
				$this->action_delete_drive_backup();
				break;
			case 'reupload_backup':
				$this->action_reupload_backup();
				break;
			case 'download_backup':
				$this->action_download_backup();
				break;
		}
	}

	/**
	 * Handle Google callback.
	 *
	 * @return void
	 */
	private function handle_google_callback() {
		$result = $this->drive->handle_oauth_callback();
		if ( empty( $result['handled'] ) ) {
			return;
		}
		$type = ! empty( $result['success'] ) ? 'updated' : 'error';
		$msg  = $result['message'] ?? 'Google OAuth callback processed.';
		$this->redirect_with_notice( 'dbbp-settings', $msg, $type, array( 'tab' => 'google_drive' ) );
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->require_admin();
		$data = array(
			'latest_daily'   => $this->logger->get_latest_by_type( 'daily' ),
			'latest_monthly' => $this->logger->get_latest_by_type( 'monthly' ),
			'latest_yearly'  => $this->logger->get_latest_by_type( 'yearly' ),
			'connections'    => $this->connector->get_connections(),
			'next_daily'     => $this->cron->get_next_run_timestamp( 'daily' ),
			'next_monthly'   => $this->cron->get_next_run_timestamp( 'monthly' ),
			'next_yearly'    => $this->cron->get_next_run_timestamp( 'yearly' ),
		);
		include DBBP_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings() {
		$this->require_admin();

		$settings = get_option( 'dbbp_settings', array() );
		$data     = array(
			'tab'         => isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'database_connections',
			'settings'    => $settings,
			'connections' => $this->connector->get_connections(),
			'oauth_url'   => $this->drive->get_oauth_url(),
			'google'      => $this->drive->get_google_settings(),
			'edit_key'    => isset( $_GET['edit_key'] ) ? sanitize_key( wp_unslash( $_GET['edit_key'] ) ) : '',
			'edit_conn'   => null,
		);
		if ( ! empty( $data['edit_key'] ) ) {
			$data['edit_conn'] = $this->connector->get_connection( $data['edit_key'] );
		}
		include DBBP_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Render logs page.
	 *
	 * @return void
	 */
	public function render_logs() {
		$this->require_admin();

		$filters = array(
			'backup_type' => isset( $_GET['backup_type'] ) ? sanitize_text_field( wp_unslash( $_GET['backup_type'] ) ) : '',
			'status'      => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
			'from'        => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'          => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'orderby'     => isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'created_at',
			'order'       => isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'DESC',
			'limit'       => 200,
		);

		$data = array(
			'filters' => $filters,
			'logs'    => $this->logger->get_logs( $filters ),
		);

		include DBBP_PLUGIN_DIR . 'admin/views/logs.php';
	}

	/**
	 * Save DB connection.
	 *
	 * @return void
	 */
	private function action_save_db_connection() {
		$conn = array(
			'key'           => sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ),
			'label'         => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'type'          => sanitize_text_field( wp_unslash( $_POST['type'] ?? 'mysql' ) ),
			'host'          => sanitize_text_field( wp_unslash( $_POST['host'] ?? '' ) ),
			'port'          => intval( wp_unslash( $_POST['port'] ?? 0 ) ),
			'database'      => sanitize_text_field( wp_unslash( $_POST['database'] ?? '' ) ),
			'username'      => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
			'password'      => sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) ),
			'ssl_enabled'   => ! empty( $_POST['ssl_enabled'] ) ? 1 : 0,
			'conn_string'   => sanitize_text_field( wp_unslash( $_POST['conn_string'] ?? '' ) ),
			'sqlite_path'   => sanitize_text_field( wp_unslash( $_POST['sqlite_path'] ?? '' ) ),
			'mongo_enabled' => ! empty( $_POST['mongo_enabled'] ) ? 1 : 0,
			'mssql_enabled' => ! empty( $_POST['mssql_enabled'] ) ? 1 : 0,
			'enabled'       => ! empty( $_POST['enabled'] ) ? 1 : 0,
		);

		$this->connector->save_connection( $conn );
		$this->redirect_with_notice( 'dbbp-settings', 'Database connection saved.', 'updated', array( 'tab' => 'database_connections' ) );
	}

	/**
	 * Delete DB connection.
	 *
	 * @return void
	 */
	private function action_delete_db_connection() {
		$key = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );
		$this->connector->delete_connection( $key );
		$this->redirect_with_notice( 'dbbp-settings', 'Database connection deleted.', 'updated', array( 'tab' => 'database_connections' ) );
	}

	/**
	 * Test DB connection.
	 *
	 * @return void
	 */
	private function action_test_db_connection() {
		$key  = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );
		$conn = $this->connector->get_connection( $key );
		if ( ! $conn ) {
			$this->redirect_with_notice( 'dbbp-settings', 'Connection not found.', 'error', array( 'tab' => 'database_connections' ) );
		}
		$result = $this->connector->test_connection( $conn );
		$type   = ! empty( $result['success'] ) ? 'updated' : 'error';
		$this->redirect_with_notice( 'dbbp-settings', $result['message'], $type, array( 'tab' => 'database_connections' ) );
	}

	/**
	 * Save Google settings.
	 *
	 * @return void
	 */
	private function action_save_google_settings() {
		$google = $this->drive->get_google_settings();
		$google['client_id']     = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$google['client_secret'] = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
		$google['root_folder']   = sanitize_text_field( wp_unslash( $_POST['root_folder'] ?? '/DB-Backups' ) );
		$this->drive->save_google_settings( $google );

		$this->redirect_with_notice( 'dbbp-settings', 'Google settings saved.', 'updated', array( 'tab' => 'google_drive' ) );
	}

	/**
	 * Save schedule settings.
	 *
	 * @return void
	 */
	private function action_save_schedule_settings() {
		$settings                               = get_option( 'dbbp_settings', array() );
		$settings['schedule']['daily_time']     = sanitize_text_field( wp_unslash( $_POST['daily_time'] ?? '02:00' ) );
		$settings['schedule']['monthly_time']   = sanitize_text_field( wp_unslash( $_POST['monthly_time'] ?? '02:10' ) );
		$settings['schedule']['yearly_time']    = sanitize_text_field( wp_unslash( $_POST['yearly_time'] ?? '02:20' ) );
		$settings['schedule']['daily_keep']     = intval( wp_unslash( $_POST['daily_keep'] ?? 8 ) );
		$settings['schedule']['monthly_keep']   = intval( wp_unslash( $_POST['monthly_keep'] ?? 12 ) );
		$settings['schedule']['yearly_keep']    = intval( wp_unslash( $_POST['yearly_keep'] ?? 5 ) );
		$settings['schedule']['use_server_cron']= ! empty( $_POST['use_server_cron'] ) ? 1 : 0;
		update_option( 'dbbp_settings', $settings );
		set_transient( 'dbbp_reschedule_needed', 1, 2 * MINUTE_IN_SECONDS );

		$this->redirect_with_notice( 'dbbp-settings', 'Schedule settings saved.', 'updated', array( 'tab' => 'schedule' ) );
	}

	/**
	 * Save notification settings.
	 *
	 * @return void
	 */
	private function action_save_notification_settings() {
		$settings = get_option( 'dbbp_settings', array() );
		$settings['notifications']['admin_email']   = sanitize_email( wp_unslash( $_POST['admin_email'] ?? '' ) );
		$settings['notifications']['slack_webhook'] = esc_url_raw( wp_unslash( $_POST['slack_webhook'] ?? '' ) );
		update_option( 'dbbp_settings', $settings );

		$this->redirect_with_notice( 'dbbp-settings', 'Notification settings saved.', 'updated', array( 'tab' => 'notifications' ) );
	}

	/**
	 * Manual run backup.
	 *
	 * @return void
	 */
	private function action_run_backup() {
		$backup_type = sanitize_text_field( wp_unslash( $_POST['backup_type'] ?? 'manual' ) );
		$db_key      = sanitize_key( wp_unslash( $_POST['db_key'] ?? 'all' ) );

		if ( 'all' === $db_key ) {
			$this->engine->run_backup_for_all( $backup_type );
		} else {
			$conn = $this->connector->get_connection( $db_key );
			if ( ! empty( $conn ) ) {
				$this->engine->run_single_backup( $conn, $backup_type );
			}
		}

		$this->redirect_with_notice( 'dbbp-dashboard', 'Backup execution finished. Check logs for details.', 'updated' );
	}

	/**
	 * Delete backup from drive.
	 *
	 * @return void
	 */
	private function action_delete_drive_backup() {
		$log_id = intval( wp_unslash( $_POST['log_id'] ?? 0 ) );
		$log    = $this->logger->get( $log_id );
		if ( empty( $log ) ) {
			$this->redirect_with_notice( 'dbbp-logs', 'Log not found.', 'error' );
		}

		if ( ! empty( $log['drive_file_id'] ) ) {
			$this->drive->delete_file( $log['drive_file_id'] );
		}
		$this->logger->delete( $log_id );
		$this->redirect_with_notice( 'dbbp-logs', 'Backup deleted from Drive and log removed.', 'updated' );
	}

	/**
	 * Re-upload backup (creates a fresh dump).
	 *
	 * @return void
	 */
	private function action_reupload_backup() {
		$log_id = intval( wp_unslash( $_POST['log_id'] ?? 0 ) );
		$log    = $this->logger->get( $log_id );
		if ( empty( $log ) ) {
			$this->redirect_with_notice( 'dbbp-logs', 'Log not found.', 'error' );
		}
		$conn = $this->connector->get_connection( $log['db_key'] );
		if ( empty( $conn ) ) {
			$this->redirect_with_notice( 'dbbp-logs', 'Connection no longer exists.', 'error' );
		}
		$this->engine->run_single_backup( $conn, $log['backup_type'] );
		$this->redirect_with_notice( 'dbbp-logs', 'Backup re-upload completed as a new backup.', 'updated' );
	}

	/**
	 * Download backup file from Google Drive.
	 *
	 * @return void
	 */
	private function action_download_backup() {
		$log_id = intval( wp_unslash( $_POST['log_id'] ?? 0 ) );
		$log    = $this->logger->get( $log_id );
		if ( empty( $log ) || empty( $log['drive_file_id'] ) ) {
			$this->redirect_with_notice( 'dbbp-logs', 'Download unavailable for this log.', 'error' );
		}
		$file = $this->drive->download_file( $log['drive_file_id'] );
		if ( empty( $file['success'] ) ) {
			$this->redirect_with_notice( 'dbbp-logs', 'Drive download failed: ' . ( $file['message'] ?? 'Unknown' ), 'error' );
		}

		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $log['filename'] ) . '"' );
		echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Ensure user can manage options.
	 *
	 * @return void
	 */
	private function require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'db-backup-pro' ) );
		}
	}

	/**
	 * Redirect with notice.
	 *
	 * @param string $page Target page.
	 * @param string $message Notice.
	 * @param string $type Type.
	 * @param array  $extra Extra args.
	 * @return void
	 */
	private function redirect_with_notice( $page, $message, $type = 'updated', $extra = array() ) {
		$args = array_merge(
			array(
				'page'        => $page,
				'dbbp_notice' => rawurlencode( $message ),
				'dbbp_type'   => $type,
			),
			$extra
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
