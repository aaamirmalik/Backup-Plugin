<?php
/**
 * Backup logger.
 *
 * @package DB_Backup_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger class.
 */
class DBBP_Backup_Logger {
	/**
	 * Table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'dbbackup_log';
	}

	/**
	 * Return table name.
	 *
	 * @return string
	 */
	public function table_name() {
		return $this->table;
	}

	/**
	 * Create table if needed.
	 *
	 * @return void
	 */
	public function maybe_create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$this->table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			backup_type VARCHAR(20) NOT NULL,
			db_key VARCHAR(100) NOT NULL,
			db_name VARCHAR(191) NOT NULL,
			db_type VARCHAR(50) NOT NULL,
			filename VARCHAR(255) NOT NULL,
			file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			drive_file_id VARCHAR(255) DEFAULT NULL,
			drive_folder VARCHAR(255) DEFAULT NULL,
			status VARCHAR(20) NOT NULL,
			message TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY backup_type (backup_type),
			KEY db_key (db_key),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert log row.
	 *
	 * @param array $data Log data.
	 * @return int
	 */
	public function insert( $data ) {
		global $wpdb;

		$defaults = array(
			'backup_type'   => 'manual',
			'db_key'        => '',
			'db_name'       => '',
			'db_type'       => '',
			'filename'      => '',
			'file_size'     => 0,
			'drive_file_id' => null,
			'drive_folder'  => null,
			'status'        => 'pending',
			'message'       => '',
			'created_at'    => current_time( 'mysql' ),
		);

		$row = wp_parse_args( $data, $defaults );

		$wpdb->insert(
			$this->table,
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update log row.
	 *
	 * @param int   $id Log id.
	 * @param array $data Data to update.
	 * @return void
	 */
	public function update( $id, $data ) {
		global $wpdb;

		$allowed = array(
			'backup_type'   => '%s',
			'db_key'        => '%s',
			'db_name'       => '%s',
			'db_type'       => '%s',
			'filename'      => '%s',
			'file_size'     => '%d',
			'drive_file_id' => '%s',
			'drive_folder'  => '%s',
			'status'        => '%s',
			'message'       => '%s',
		);

		$formats = array();
		$payload = array();
		foreach ( $allowed as $key => $format ) {
			if ( array_key_exists( $key, $data ) ) {
				$payload[ $key ] = $data[ $key ];
				$formats[]       = $format;
			}
		}

		if ( empty( $payload ) ) {
			return;
		}

		$wpdb->update(
			$this->table,
			$payload,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Get latest by backup type.
	 *
	 * @param string $type Backup type.
	 * @return array|null
	 */
	public function get_latest_by_type( $type ) {
		global $wpdb;
		$query = $wpdb->prepare( "SELECT * FROM {$this->table} WHERE backup_type = %s ORDER BY id DESC LIMIT 1", $type );
		return $wpdb->get_row( $query, ARRAY_A );
	}

	/**
	 * Get logs.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	public function get_logs( $filters = array() ) {
		global $wpdb;

		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $filters['backup_type'] ) ) {
			$where   .= ' AND backup_type = %s';
			$params[] = $filters['backup_type'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where   .= ' AND DATE(created_at) >= %s';
			$params[] = $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$where   .= ' AND DATE(created_at) <= %s';
			$params[] = $filters['to'];
		}

		$order_by = 'created_at';
		$order    = 'DESC';
		if ( ! empty( $filters['orderby'] ) && in_array( $filters['orderby'], array( 'created_at', 'db_name', 'status', 'backup_type' ), true ) ) {
			$order_by = $filters['orderby'];
		}
		if ( ! empty( $filters['order'] ) && in_array( strtoupper( $filters['order'] ), array( 'ASC', 'DESC' ), true ) ) {
			$order = strtoupper( $filters['order'] );
		}

		$limit = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 100;

		$sql = "SELECT * FROM {$this->table} {$where} ORDER BY {$order_by} {$order} LIMIT {$limit}";
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Get logs exceeding retention.
	 *
	 * @param string $backup_type Type.
	 * @param string $db_key DB key.
	 * @param int    $keep Keep count.
	 * @return array
	 */
	public function get_excess_for_retention( $backup_type, $db_key, $keep ) {
		global $wpdb;

		$keep     = max( 1, (int) $keep );
		$offset   = max( 0, $keep - 1 );
		$subquery = $wpdb->prepare(
			"SELECT id FROM {$this->table} WHERE backup_type = %s AND db_key = %s AND status = %s ORDER BY created_at DESC LIMIT 18446744073709551615 OFFSET %d",
			$backup_type,
			$db_key,
			'success',
			$offset
		);

		return $wpdb->get_results( "SELECT * FROM {$this->table} WHERE id IN ({$subquery})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get one row.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id );
		return $wpdb->get_row( $sql, ARRAY_A );
	}

	/**
	 * Delete row.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public function delete( $id ) {
		global $wpdb;
		$wpdb->delete( $this->table, array( 'id' => (int) $id ), array( '%d' ) );
	}
}
