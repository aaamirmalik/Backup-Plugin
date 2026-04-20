<?php
/**
 * Backup engine.
 *
 * @package DB_Backup_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backup engine class.
 */
class DBBP_Backup_Engine {
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
	 * Constructor.
	 *
	 * @param DBBP_Backup_Logger $logger Logger.
	 * @param DBBP_DB_Connector  $connector Connector.
	 * @param DBBP_Google_Drive  $drive Drive.
	 */
	public function __construct( $logger, $connector, $drive ) {
		$this->logger    = $logger;
		$this->connector = $connector;
		$this->drive     = $drive;
	}

	/**
	 * Backup all configured databases.
	 *
	 * @param string $backup_type Backup type.
	 * @return array
	 */
	public function run_backup_for_all( $backup_type = 'manual' ) {
		$connections = $this->connector->get_connections();
		$results     = array();

		foreach ( $connections as $key => $conn ) {
			if ( empty( $conn['enabled'] ) ) {
				continue;
			}
			$live_conn = $this->connector->get_connection( $key );
			if ( empty( $live_conn ) ) {
				continue;
			}
			$results[ $key ] = $this->run_single_backup( $live_conn, $backup_type );
		}

		$this->cleanup_temp_dir();

		return $results;
	}

	/**
	 * Run one backup.
	 *
	 * @param array  $conn Connection.
	 * @param string $backup_type Type.
	 * @return array
	 */
	public function run_single_backup( $conn, $backup_type = 'manual' ) {
		$db_name     = sanitize_text_field( $conn['database'] ?: $conn['label'] );
		$db_key      = sanitize_key( $conn['key'] );
		$db_type     = sanitize_text_field( $conn['type'] );
		$folder_path = $this->get_drive_folder_path( $backup_type );

		$log_id = $this->logger->insert(
			array(
				'backup_type' => $backup_type,
				'db_key'      => $db_key,
				'db_name'     => $db_name,
				'db_type'     => $db_type,
				'filename'    => '',
				'status'      => 'pending',
				'message'     => 'Backup in queue.',
			)
		);

		$dump = $this->create_dump( $conn, $backup_type );
		if ( empty( $dump['success'] ) ) {
			$this->logger->update(
				$log_id,
				array(
					'status'  => 'failed',
					'message' => $dump['message'],
				)
			);
			$this->notify_failure( $db_name, $backup_type, $dump['message'] );
			return $dump;
		}

		$this->logger->update(
			$log_id,
			array(
				'filename'  => $dump['filename'],
				'file_size' => filesize( $dump['path'] ),
				'message'   => 'Dump created, uploading to Google Drive.',
			)
		);

		$upload_result = array( 'success' => false, 'message' => 'Upload was not attempted.' );
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$upload_result = $this->drive->upload_file( $dump['path'], $dump['filename'], $folder_path );
			if ( ! empty( $upload_result['success'] ) ) {
				break;
			}
			sleep( 1 );
		}

		if ( empty( $upload_result['success'] ) ) {
			$this->logger->update(
				$log_id,
				array(
					'status'  => 'failed',
					'message' => 'Upload failed after 3 attempts: ' . ( $upload_result['message'] ?? 'Unknown error' ),
				)
			);
			$this->notify_failure( $db_name, $backup_type, $upload_result['message'] ?? 'Upload failure.' );
			return $upload_result;
		}

		$this->logger->update(
			$log_id,
			array(
				'drive_file_id' => $upload_result['file_id'],
				'drive_folder'  => $folder_path,
				'status'        => 'success',
				'message'       => 'Backup uploaded successfully.',
			)
		);

		$this->apply_retention( $backup_type, $db_key );

		return array(
			'success' => true,
			'file'    => $dump['filename'],
			'log_id'  => $log_id,
		);
	}

	/**
	 * Create dump and gzip.
	 *
	 * @param array  $conn Connection.
	 * @param string $backup_type Type.
	 * @return array
	 */
	private function create_dump( $conn, $backup_type ) {
		$temp_dir   = $this->get_temp_dir();
		$db_name    = sanitize_file_name( $conn['database'] ?: $conn['label'] );
		$db_type    = sanitize_file_name( $conn['type'] );
		$stamp      = wp_date( 'Y-m-d_H-i' );
		$base_name  = sprintf( '%s_%s_%s', $db_name, $db_type, $stamp );
		$type       = $conn['type'];
		$ext        = in_array( $type, array( 'mongodb', 'mssql' ), true ) ? '.archive.gz' : '.sql.gz';
		$file_name  = $base_name . $ext;
		$file_path  = trailingslashit( $temp_dir ) . $file_name;
		$plain_path = trailingslashit( $temp_dir ) . $base_name . ( '.archive.gz' === $ext ? '.archive' : '.sql' );

		$result = $this->execute_dump_command( $conn, $plain_path );
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$data = file_get_contents( $plain_path );
		if ( false === $data ) {
			@unlink( $plain_path );
			return array( 'success' => false, 'message' => 'Could not read generated dump file.' );
		}
		$gz = gzencode( $data, 9 );
		if ( false === $gz ) {
			@unlink( $plain_path );
			return array( 'success' => false, 'message' => 'Compression failed.' );
		}
		$written = file_put_contents( $file_path, $gz );
		@unlink( $plain_path );
		if ( false === $written ) {
			return array( 'success' => false, 'message' => 'Failed writing compressed file.' );
		}

		return array(
			'success'  => true,
			'filename' => $file_name,
			'path'     => $file_path,
		);
	}

	/**
	 * Execute database dump command.
	 *
	 * @param array  $conn Connection.
	 * @param string $output_file Output file path.
	 * @return array
	 */
	private function execute_dump_command( $conn, $output_file ) {
		$type = $conn['type'] ?? '';
		$cmd  = '';

		switch ( $type ) {
			case 'mysql':
			case 'mariadb':
				$ssl = ! empty( $conn['ssl_enabled'] ) ? ' --ssl-mode=REQUIRED' : '';
				$cmd = sprintf(
					'mysqldump --host=%s --port=%d --user=%s --password=%s %s%s > %s',
					escapeshellarg( $conn['host'] ),
					(int) $conn['port'],
					escapeshellarg( $conn['username'] ),
					escapeshellarg( $conn['password'] ),
					escapeshellarg( $conn['database'] ),
					$ssl,
					escapeshellarg( $output_file )
				);
				break;
			case 'postgresql':
			case 'neondb':
				$conn_string = ! empty( $conn['conn_string'] ) ? escapeshellarg( $conn['conn_string'] ) : '';
				if ( ! empty( $conn_string ) ) {
					$cmd = sprintf( 'pg_dump %s -f %s', $conn_string, escapeshellarg( $output_file ) );
				} else {
					$ssl_mode = ! empty( $conn['ssl_enabled'] ) ? ' --sslmode=require' : '';
					$cmd      = sprintf(
						'pg_dump -h %s -p %d -U %s -d %s%s -f %s',
						escapeshellarg( $conn['host'] ),
						(int) $conn['port'],
						escapeshellarg( $conn['username'] ),
						escapeshellarg( $conn['database'] ),
						$ssl_mode,
						escapeshellarg( $output_file )
					);
				}
				$password = (string) $conn['password'];
				$cmd      = $this->prepend_env_command( 'PGPASSWORD', $password, $cmd );
				break;
			case 'sqlite':
				if ( empty( $conn['sqlite_path'] ) || ! file_exists( $conn['sqlite_path'] ) ) {
					return array( 'success' => false, 'message' => 'SQLite source file does not exist.' );
				}
				if ( ! copy( $conn['sqlite_path'], $output_file ) ) {
					return array( 'success' => false, 'message' => 'Could not copy SQLite file.' );
				}
				return array( 'success' => true );
			case 'mongodb':
				if ( empty( $conn['mongo_enabled'] ) ) {
					return array( 'success' => false, 'message' => 'MongoDB backup is disabled in settings.' );
				}
				if ( ! $this->binary_exists( 'mongodump' ) ) {
					return array( 'success' => false, 'message' => 'mongodump binary is missing.' );
				}
				$uri = ! empty( $conn['conn_string'] ) ? escapeshellarg( $conn['conn_string'] ) : sprintf(
					'"mongodb://%s:%s@%s:%d/%s"',
					rawurlencode( $conn['username'] ),
					rawurlencode( $conn['password'] ),
					$conn['host'],
					(int) $conn['port'],
					$conn['database']
				);
				$cmd = sprintf( 'mongodump --uri=%s --archive=%s', $uri, escapeshellarg( $output_file ) );
				break;
			case 'mssql':
				if ( empty( $conn['mssql_enabled'] ) ) {
					return array( 'success' => false, 'message' => 'MSSQL backup is disabled in settings.' );
				}
				if ( ! $this->binary_exists( 'sqlcmd' ) ) {
					return array( 'success' => false, 'message' => 'sqlcmd binary is missing.' );
				}
				$bak_path = str_replace( '.archive', '.bak', $output_file );
                    $query    = sprintf( "BACKUP DATABASE [%s] TO DISK = '%s' WITH INIT, COMPRESSION", $conn['database'], $bak_path );
				$cmd      = sprintf(
					'sqlcmd -S %s,%d -U %s -P %s -Q %s',
					escapeshellarg( $conn['host'] ),
					(int) $conn['port'],
					escapeshellarg( $conn['username'] ),
					escapeshellarg( $conn['password'] ),
					escapeshellarg( $query )
				);
				break;
			default:
				return array( 'success' => false, 'message' => 'Unsupported database type: ' . $type );
		}

		if ( '' === $cmd ) {
			return array( 'success' => false, 'message' => 'Dump command was not created.' );
		}

		$output   = array();
		$exitcode = 0;
		exec( $cmd . ' 2>&1', $output, $exitcode );
		if ( 0 !== $exitcode ) {
			return array(
				'success' => false,
				'message' => 'Dump command failed: ' . implode( PHP_EOL, $output ),
			);
		}

		if ( 'mssql' === $type ) {
			$bak_path = str_replace( '.archive', '.bak', $output_file );
			if ( file_exists( $bak_path ) ) {
				rename( $bak_path, $output_file );
			}
		}

		if ( ! file_exists( $output_file ) ) {
			return array( 'success' => false, 'message' => 'Dump command did not produce a file.' );
		}

		return array( 'success' => true );
	}

	/**
	 * Build folder path for backup type.
	 *
	 * @param string $backup_type Type.
	 * @return string
	 */
	private function get_drive_folder_path( $backup_type ) {
		$google = $this->drive->get_google_settings();
		$root   = ! empty( $google['root_folder'] ) ? $google['root_folder'] : '/DB-Backups';

		switch ( $backup_type ) {
			case 'daily':
				return trailingslashit( untrailingslashit( $root ) ) . 'Daily';
			case 'monthly':
				return trailingslashit( untrailingslashit( $root ) ) . 'Monthly';
			case 'yearly':
				return trailingslashit( untrailingslashit( $root ) ) . 'Yearly';
			default:
				return trailingslashit( untrailingslashit( $root ) ) . 'Manual';
		}
	}

	/**
	 * Apply retention policy.
	 *
	 * @param string $backup_type Type.
	 * @param string $db_key DB key.
	 * @return void
	 */
	private function apply_retention( $backup_type, $db_key ) {
		$settings = get_option( 'dbbp_settings', array() );
		$keep     = 0;

		if ( 'daily' === $backup_type ) {
			$keep = (int) ( $settings['schedule']['daily_keep'] ?? 8 );
		}
		if ( 'monthly' === $backup_type ) {
			$keep = (int) ( $settings['schedule']['monthly_keep'] ?? 12 );
		}
		if ( 'yearly' === $backup_type ) {
			$keep = (int) ( $settings['schedule']['yearly_keep'] ?? 5 );
		}
		if ( $keep <= 0 ) {
			return;
		}

		$excess = $this->logger->get_excess_for_retention( $backup_type, $db_key, $keep );
		foreach ( $excess as $row ) {
			if ( ! empty( $row['drive_file_id'] ) ) {
				$this->drive->delete_file( $row['drive_file_id'] );
			}
			$this->logger->delete( (int) $row['id'] );
		}
	}

	/**
	 * Notify admin and Slack on failure.
	 *
	 * @param string $db_name DB name.
	 * @param string $type Type.
	 * @param string $message Error.
	 * @return void
	 */
	private function notify_failure( $db_name, $type, $message ) {
		$settings    = get_option( 'dbbp_settings', array() );
		$email       = sanitize_email( $settings['notifications']['admin_email'] ?? get_option( 'admin_email' ) );
		$webhook_url = esc_url_raw( $settings['notifications']['slack_webhook'] ?? '' );
		$subject     = sprintf( '[DB Backup Pro] %s %s backup failed', $db_name, $type );
		$body        = sprintf( "Database: %s\nType: %s\nError: %s", $db_name, $type, $message );

		if ( ! empty( $email ) ) {
			wp_mail( $email, $subject, $body );
		}

		if ( ! empty( $webhook_url ) ) {
			wp_remote_post(
				$webhook_url,
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array( 'text' => $subject . ' - ' . $message ) ),
				)
			);
		}
	}

	/**
	 * Ensure temp dir.
	 *
	 * @return string
	 */
	private function get_temp_dir() {
		$upload = wp_upload_dir();
		$path   = trailingslashit( $upload['basedir'] ) . 'db-backup-pro/temp';
		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( $path );
		}
		return $path;
	}

	/**
	 * Cleanup old temp files.
	 *
	 * @return void
	 */
	public function cleanup_temp_dir() {
		$dir = $this->get_temp_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( trailingslashit( $dir ) . '*' );
		if ( ! is_array( $files ) ) {
			return;
		}
		$max_age = time() - ( 30 * DAY_IN_SECONDS );
		foreach ( $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $max_age ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Check whether binary exists.
	 *
	 * @param string $binary Binary.
	 * @return bool
	 */
	private function binary_exists( $binary ) {
		$output   = array();
		$exitcode = 1;

		if ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
			$cmd = 'where ' . escapeshellarg( $binary ) . ' > NUL 2>&1';
		} else {
			$cmd = 'command -v ' . escapeshellarg( $binary ) . ' > /dev/null 2>&1';
		}

		exec( $cmd, $output, $exitcode );
		return 0 === (int) $exitcode;
	}

	/**
	 * Prefix command with env variable assignment.
	 *
	 * @param string $env_key Environment variable.
	 * @param string $value Value.
	 * @param string $cmd Command.
	 * @return string
	 */
	private function prepend_env_command( $env_key, $value, $cmd ) {
		if ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
			return 'set ' . $env_key . '=' . escapeshellarg( $value ) . '&& ' . $cmd;
		}
		return $env_key . '=' . escapeshellarg( $value ) . ' ' . $cmd;
	}
}



