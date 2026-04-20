<?php
/**
 * Plugin Name: DB Backup Pro
 * Plugin URI: https://example.com/db-backup-pro
 * Description: Multi-database backup plugin with Google Drive integration, retention policies, and scheduled backups.
 * Version: 1.0.0
 * Author: DB Backup Pro Team
 * Text Domain: db-backup-pro
 * Domain Path: /languages
 *
 * @package DB_Backup_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DBBP_VERSION', '1.0.0' );
define( 'DBBP_PLUGIN_FILE', __FILE__ );
define( 'DBBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once DBBP_PLUGIN_DIR . 'includes/class-backup-logger.php';
require_once DBBP_PLUGIN_DIR . 'includes/class-db-connector.php';
require_once DBBP_PLUGIN_DIR . 'includes/class-google-drive.php';
require_once DBBP_PLUGIN_DIR . 'includes/class-backup-engine.php';
require_once DBBP_PLUGIN_DIR . 'includes/class-cron-manager.php';
require_once DBBP_PLUGIN_DIR . 'admin/class-admin-ui.php';

/**
 * Main plugin class.
 */
final class DBBP_Plugin {
	/**
	 * Logger instance.
	 *
	 * @var DBBP_Backup_Logger
	 */
	private $logger;

	/**
	 * Connector instance.
	 *
	 * @var DBBP_DB_Connector
	 */
	private $connector;

	/**
	 * Drive instance.
	 *
	 * @var DBBP_Google_Drive
	 */
	private $drive;

	/**
	 * Engine instance.
	 *
	 * @var DBBP_Backup_Engine
	 */
	private $engine;

	/**
	 * Cron manager.
	 *
	 * @var DBBP_Cron_Manager
	 */
	private $cron;

	/**
	 * Admin UI.
	 *
	 * @var DBBP_Admin_UI
	 */
	private $admin;

	/**
	 * Boot plugin.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_cli' ) );

		$this->logger    = new DBBP_Backup_Logger();
		$this->connector = new DBBP_DB_Connector();
		$this->drive     = new DBBP_Google_Drive();
		$this->engine    = new DBBP_Backup_Engine( $this->logger, $this->connector, $this->drive );
		$this->cron      = new DBBP_Cron_Manager( $this->engine );
		$this->admin     = new DBBP_Admin_UI( $this->engine, $this->logger, $this->connector, $this->drive, $this->cron );

		$this->cron->register_hooks();
		$this->admin->register_hooks();
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'db-backup-pro', false, dirname( plugin_basename( DBBP_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Register WP-CLI command.
	 *
	 * @return void
	 */
	public function register_cli() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$engine = $this->engine;

		WP_CLI::add_command(
			'dbbp run',
			function( $args, $assoc_args ) use ( $engine ) {
				$type = isset( $assoc_args['type'] ) ? sanitize_text_field( wp_unslash( $assoc_args['type'] ) ) : 'daily';
				if ( ! in_array( $type, array( 'daily', 'monthly', 'yearly', 'manual' ), true ) ) {
					WP_CLI::error( 'Invalid type. Use daily|monthly|yearly|manual.' );
				}
				$result = $engine->run_backup_for_all( $type );
				WP_CLI::success( wp_json_encode( $result ) );
			}
		);
	}
}

/**
 * Activation callback.
 *
 * @return void
 */
function dbbp_activate() {
	$logger = new DBBP_Backup_Logger();
	$logger->maybe_create_table();

	$defaults = array(
		'db_connections'   => array(),
		'google'           => array(
			'client_id'       => '',
			'client_secret'   => '',
			'access_token'    => '',
			'refresh_token'   => '',
			'token_expires'   => 0,
			'root_folder'     => '/DB-Backups',
			'oauth_connected' => 0,
		),
		'schedule'         => array(
			'use_server_cron' => 0,
			'daily_time'      => '02:00',
			'monthly_time'    => '02:10',
			'yearly_time'     => '02:20',
			'daily_keep'      => 8,
			'monthly_keep'    => 12,
			'yearly_keep'     => 5,
		),
		'notifications'    => array(
			'admin_email'   => get_option( 'admin_email' ),
			'slack_webhook' => '',
		),
		'last_schedule_run' => array(
			'daily'   => '',
			'monthly' => '',
			'yearly'  => '',
		),
	);

	$current = get_option( 'dbbp_settings', array() );
	update_option( 'dbbp_settings', wp_parse_args( $current, $defaults ) );

	$cron = new DBBP_Cron_Manager();
	$cron->activate();
}

/**
 * Deactivation callback.
 *
 * @return void
 */
function dbbp_deactivate() {
	$cron = new DBBP_Cron_Manager();
	$cron->deactivate();
}

register_activation_hook( DBBP_PLUGIN_FILE, 'dbbp_activate' );
register_deactivation_hook( DBBP_PLUGIN_FILE, 'dbbp_deactivate' );

$dbbp_plugin = new DBBP_Plugin();
$dbbp_plugin->boot();
