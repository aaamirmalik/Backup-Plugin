<?php
/**
 * DB connector and credentials helper.
 *
 * @package DB_Backup_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DB connector class.
 */
class DBBP_DB_Connector {
	/**
	 * Encryption method.
	 *
	 * @var string
	 */
	private $cipher = 'AES-256-CBC';

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		return get_option( 'dbbp_settings', array() );
	}

	/**
	 * Get db connections.
	 *
	 * @return array
	 */
	public function get_connections() {
		$settings = $this->get_settings();
		return isset( $settings['db_connections'] ) && is_array( $settings['db_connections'] ) ? $settings['db_connections'] : array();
	}

	/**
	 * Save one connection.
	 *
	 * @param array $conn Connection data.
	 * @return array
	 */
	public function save_connection( $conn ) {
		$connections = $this->get_connections();

		$raw_key = ! empty( $conn['key'] ) ? (string) $conn['key'] : 'db_' . strtolower( wp_generate_password( 8, false, false ) );
		$key     = sanitize_key( $raw_key );
		if ( '' === $key ) {
			$key = 'db_' . strtolower( wp_generate_password( 10, false, false ) );
		}

		$existing_key = $this->resolve_connection_key( $key, $connections );
		if ( null !== $existing_key && $existing_key !== $key ) {
			$connections[ $key ] = $connections[ $existing_key ];
			unset( $connections[ $existing_key ] );
		}

		$clean = array(
			'key'                => $key,
			'label'              => sanitize_text_field( $conn['label'] ?? $key ),
			'type'               => sanitize_text_field( $conn['type'] ?? 'mysql' ),
			'host'               => sanitize_text_field( $conn['host'] ?? '' ),
			'port'               => (int) ( $conn['port'] ?? 0 ),
			'database'           => sanitize_text_field( $conn['database'] ?? '' ),
			'username'           => sanitize_text_field( $conn['username'] ?? '' ),
			'password_encrypted' => '',
			'ssl_enabled'        => ! empty( $conn['ssl_enabled'] ) ? 1 : 0,
			'conn_string'        => sanitize_text_field( $conn['conn_string'] ?? '' ),
			'sqlite_path'        => sanitize_text_field( $conn['sqlite_path'] ?? '' ),
			'mongo_enabled'      => ! empty( $conn['mongo_enabled'] ) ? 1 : 0,
			'mssql_enabled'      => ! empty( $conn['mssql_enabled'] ) ? 1 : 0,
			'enabled'            => isset( $conn['enabled'] ) ? (int) ! empty( $conn['enabled'] ) : 1,
		);

		if ( isset( $conn['password'] ) && '' !== (string) $conn['password'] ) {
			$clean['password_encrypted'] = $this->encrypt( (string) $conn['password'] );
		} else {
			$pwd_source = null !== $existing_key ? $existing_key : $key;
			if ( isset( $connections[ $pwd_source ]['password_encrypted'] ) ) {
				$clean['password_encrypted'] = $connections[ $pwd_source ]['password_encrypted'];
			}
		}

		$connections[ $key ] = $clean;
		$this->save_connections( $connections );

		return $clean;
	}

	/**
	 * Save all connections.
	 *
	 * @param array $connections Connections.
	 * @return void
	 */
	public function save_connections( $connections ) {
		$settings                   = $this->get_settings();
		$settings['db_connections'] = $connections;
		update_option( 'dbbp_settings', $settings );
	}

	/**
	 * Delete connection.
	 *
	 * @param string $key Connection key.
	 * @return void
	 */
	public function delete_connection( $key ) {
		$key         = sanitize_key( $key );
		$connections = $this->get_connections();
		$resolved    = $this->resolve_connection_key( $key, $connections );
		if ( null !== $resolved ) {
			unset( $connections[ $resolved ] );
			$this->save_connections( $connections );
		}
	}

	/**
	 * Get connection by key with decrypted password.
	 *
	 * @param string $key Connection key.
	 * @return array|null
	 */
	public function get_connection( $key ) {
		$connections = $this->get_connections();
		$resolved    = $this->resolve_connection_key( sanitize_key( $key ), $connections );
		if ( null === $resolved || empty( $connections[ $resolved ] ) ) {
			return null;
		}

		$conn             = $connections[ $resolved ];
		$conn['key']      = $resolved;
		$conn['password'] = $this->decrypt( $conn['password_encrypted'] ?? '' );

		return $conn;
	}

	/**
	 * Resolve a connection key against existing entries.
	 *
	 * @param string $key Requested key.
	 * @param array  $connections Connections map.
	 * @return string|null
	 */
	private function resolve_connection_key( $key, $connections ) {
		if ( isset( $connections[ $key ] ) ) {
			return $key;
		}

		foreach ( $connections as $stored_key => $connection ) {
			if ( 0 === strcasecmp( (string) $stored_key, (string) $key ) ) {
				return $stored_key;
			}
			if ( ! empty( $connection['key'] ) && 0 === strcasecmp( (string) $connection['key'], (string) $key ) ) {
				return $stored_key;
			}
		}

		return null;
	}

	/**
	 * Test database connection.
	 *
	 * @param array $conn Connection.
	 * @return array
	 */
	public function test_connection( $conn ) {
		$type = $conn['type'] ?? '';
		try {
			switch ( $type ) {
				case 'mysql':
				case 'mariadb':
					$dsn = sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $conn['host'], (int) $conn['port'], $conn['database'] );
					new PDO( $dsn, $conn['username'], $conn['password'] );
					break;
				case 'postgresql':
				case 'neondb':
					if ( ! empty( $conn['conn_string'] ) ) {
						$dsn = 'pgsql:' . $conn['conn_string'];
					} else {
						$dsn = sprintf( 'pgsql:host=%s;port=%d;dbname=%s', $conn['host'], (int) $conn['port'], $conn['database'] );
					}
					new PDO( $dsn, $conn['username'], $conn['password'] );
					break;
				case 'sqlite':
					if ( empty( $conn['sqlite_path'] ) || ! file_exists( $conn['sqlite_path'] ) ) {
						throw new Exception( 'SQLite database file not found.' );
					}
					new PDO( 'sqlite:' . $conn['sqlite_path'] );
					break;
				case 'mongodb':
					if ( ! $this->binary_exists( 'mongodump' ) ) {
						throw new Exception( 'mongodump binary is not available.' );
					}
					break;
				case 'mssql':
					if ( ! $this->binary_exists( 'sqlcmd' ) ) {
						throw new Exception( 'sqlcmd binary is not available.' );
					}
					break;
				default:
					throw new Exception( 'Unsupported database type.' );
			}
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		return array(
			'success' => true,
			'message' => 'Connection successful.',
		);
	}

	/**
	 * Encrypt plain value.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt() . AUTH_KEY, true );
		$iv  = random_bytes( openssl_cipher_iv_length( $this->cipher ) );
		$enc = openssl_encrypt( $plain, $this->cipher, $key, 0, $iv );
		if ( false === $enc ) {
			return '';
		}
		return base64_encode( $iv . '::' . $enc );
	}

	/**
	 * Decrypt value.
	 *
	 * @param string $encrypted Encrypted.
	 * @return string
	 */
	public function decrypt( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}
		$key    = hash( 'sha256', wp_salt() . AUTH_KEY, true );
		$raw    = base64_decode( $encrypted, true );
		$chunks = $raw ? explode( '::', $raw, 2 ) : array();
		if ( 2 !== count( $chunks ) ) {
			return '';
		}
		$plain = openssl_decrypt( $chunks[1], $this->cipher, $key, 0, $chunks[0] );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Test MySQL or MariaDB connection using mysqli.
	 *
	 * @param array $conn Connection data.
	 * @return void
	 * @throws Exception When connection fails.
	 */
	private function test_mysql_mariadb_connection( $conn ) {
		if ( ! function_exists( 'mysqli_init' ) ) {
			$dsn = sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $conn['host'], (int) $conn['port'], $conn['database'] );
			new PDO( $dsn, $conn['username'], $conn['password'] );
			return;
		}

		$mysqli = mysqli_init();
		if ( false === $mysqli ) {
			throw new Exception( 'Unable to initialize mysqli.' );
		}

		$host = (string) ( $conn['host'] ?? '127.0.0.1' );
		$port = (int) ( $conn['port'] ?? 3306 );
		$db   = (string) ( $conn['database'] ?? '' );
		$user = (string) ( $conn['username'] ?? '' );
		$pass = (string) ( $conn['password'] ?? '' );

		$connected = @mysqli_real_connect( $mysqli, $host, $user, $pass, $db, $port );
		if ( ! $connected ) {
			$error = mysqli_connect_error();
			if ( empty( $error ) ) {
				$error = 'MySQL connection failed.';
			}
			throw new Exception( $error );
		}

		mysqli_close( $mysqli );
	}
	/**
	 * Normalize port value by database type.
	 *
	 * @param string $type DB type.
	 * @param int    $port Port value.
	 * @return int
	 */
	private function normalize_port( $type, $port ) {
		$port = (int) $port;
		if ( $port > 0 ) {
			return $port;
		}

		switch ( $type ) {
			case 'postgresql':
			case 'neondb':
				return 5432;
			case 'mssql':
				return 1433;
			case 'mongodb':
				return 27017;
			case 'mysql':
			case 'mariadb':
			default:
				return 3306;
		}
	}
	/**
	 * Check command binary.
	 *
	 * @param string $binary Binary name.
	 * @return bool
	 */
	private function binary_exists( $binary ) {
		$cmd    = strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ? 'where ' : 'command -v ';
		$output = shell_exec( $cmd . escapeshellarg( $binary ) . ' 2>&1' );
		return ! empty( $output );
	}
}






