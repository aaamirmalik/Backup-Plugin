<?php
/**
 * Google Drive integration.
 *
 * @package DB_Backup_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Drive class.
 */
class DBBP_Google_Drive {
	/**
	 * OAuth endpoint.
	 *
	 * @var string
	 */
	private $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * Token endpoint.
	 *
	 * @var string
	 */
	private $token_url = 'https://oauth2.googleapis.com/token';

	/**
	 * API endpoint.
	 *
	 * @var string
	 */
	private $api_url = 'https://www.googleapis.com/drive/v3';

	/**
	 * Upload endpoint.
	 *
	 * @var string
	 */
	private $upload_url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';

	/**
	 * Build OAuth URL.
	 *
	 * @return string
	 */
	public function get_oauth_url() {
		$settings = $this->get_google_settings();
		if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			return '';
		}

		$state = wp_generate_password( 24, false, false );
		set_transient( 'dbbp_google_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );

		$args = array(
			'client_id'     => $settings['client_id'],
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_type' => 'code',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'scope'         => 'https://www.googleapis.com/auth/drive',
			'state'         => $state,
		);

		return add_query_arg( $args, $this->auth_url );
	}

	/**
	 * Handle OAuth callback.
	 *
	 * @return array
	 */
	public function handle_oauth_callback() {
		if ( empty( $_GET['page'] ) || 'dbbp-settings' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return array( 'handled' => false );
		}
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			return array( 'handled' => false );
		}

		$state        = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$saved_state  = get_transient( 'dbbp_google_state_' . get_current_user_id() );
		$auth_code    = sanitize_text_field( wp_unslash( $_GET['code'] ) );

		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			return array( 'handled' => true, 'success' => false, 'message' => 'Invalid OAuth state.' );
		}

		$settings = $this->get_google_settings();
		$resp     = wp_remote_post(
			$this->token_url,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $auth_code,
					'client_id'     => $settings['client_id'],
					'client_secret' => $settings['client_secret'],
					'redirect_uri'  => $this->get_redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return array( 'handled' => true, 'success' => false, 'message' => $resp->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['access_token'] ) ) {
			return array( 'handled' => true, 'success' => false, 'message' => 'Unable to fetch access token: ' . wp_json_encode( $body ) );
		}

		$settings['access_token']    = $this->encrypt( $body['access_token'] );
		$settings['refresh_token']   = ! empty( $body['refresh_token'] ) ? $this->encrypt( $body['refresh_token'] ) : ( $settings['refresh_token'] ?? '' );
		$settings['token_expires']   = time() + (int) ( $body['expires_in'] ?? 3500 );
		$settings['oauth_connected'] = 1;
		$this->save_google_settings( $settings );

		delete_transient( 'dbbp_google_state_' . get_current_user_id() );

		return array( 'handled' => true, 'success' => true, 'message' => 'Google Drive connected successfully.' );
	}

	/**
	 * Disconnect OAuth.
	 *
	 * @return void
	 */
	public function disconnect() {
		$settings                   = $this->get_google_settings();
		$settings['access_token']   = '';
		$settings['refresh_token']  = '';
		$settings['token_expires']  = 0;
		$settings['oauth_connected']= 0;
		$this->save_google_settings( $settings );
	}

	/**
	 * Upload file to Drive.
	 *
	 * @param string $file_path Local path.
	 * @param string $file_name Name.
	 * @param string $folder_path Path.
	 * @return array
	 */
	public function upload_file( $file_path, $file_name, $folder_path ) {
		$token = $this->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}

		$folder = $this->ensure_folder_path( $folder_path );
		if ( empty( $folder['success'] ) ) {
			return $folder;
		}

		$file_data = file_get_contents( $file_path );
		if ( false === $file_data ) {
			return array( 'success' => false, 'message' => 'Unable to read backup file.' );
		}

		$boundary = wp_generate_password( 24, false, false );
		$metadata = wp_json_encode(
			array(
				'name'    => $file_name,
				'parents' => array( $folder['folder_id'] ),
			)
		);

		$body = "--{$boundary}\r\n";
		$body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
		$body .= $metadata . "\r\n";
		$body .= "--{$boundary}\r\n";
		$body .= "Content-Type: application/gzip\r\n\r\n";
		$body .= $file_data . "\r\n";
		$body .= "--{$boundary}--";

		$response = wp_remote_post(
			$this->upload_url,
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/related; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['id'] ) ) {
			return array( 'success' => false, 'message' => 'Google Drive upload failed.', 'response' => $data );
		}

		return array(
			'success'   => true,
			'file_id'   => sanitize_text_field( $data['id'] ),
			'folder_id' => $folder['folder_id'],
		);
	}

	/**
	 * Delete drive file.
	 *
	 * @param string $file_id File id.
	 * @return array
	 */
	public function delete_file( $file_id ) {
		$token = $this->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}

		$url  = $this->api_url . '/files/' . rawurlencode( $file_id );
		$resp = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 60,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 'success' => false, 'message' => $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 204 !== $code && 200 !== $code ) {
			return array( 'success' => false, 'message' => 'Google Drive deletion failed.' );
		}

		return array( 'success' => true );
	}

	/**
	 * Download file content by file ID.
	 *
	 * @param string $file_id File id.
	 * @return array
	 */
	public function download_file( $file_id ) {
		$token = $this->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}

		$url  = $this->api_url . '/files/' . rawurlencode( $file_id ) . '?alt=media';
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 120,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 'success' => false, 'message' => $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return array( 'success' => false, 'message' => 'Unable to download file from Drive.' );
		}

		return array( 'success' => true, 'body' => wp_remote_retrieve_body( $resp ) );
	}

	/**
	 * Ensure folder hierarchy exists.
	 *
	 * @param string $path Folder path.
	 * @return array
	 */
	public function ensure_folder_path( $path ) {
		$token = $this->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}

		$parts     = array_filter( array_map( 'trim', explode( '/', trim( $path, '/' ) ) ) );
		$parent_id = 'root';

		foreach ( $parts as $part ) {
			$existing = $this->find_folder( $part, $parent_id, $token );
			if ( ! empty( $existing['id'] ) ) {
				$parent_id = $existing['id'];
				continue;
			}

			$created = $this->create_folder( $part, $parent_id, $token );
			if ( empty( $created['success'] ) ) {
				return $created;
			}
			$parent_id = $created['id'];
		}

		return array( 'success' => true, 'folder_id' => $parent_id );
	}

	/**
	 * Get google settings.
	 *
	 * @return array
	 */
	public function get_google_settings() {
		$settings = get_option( 'dbbp_settings', array() );
		return isset( $settings['google'] ) && is_array( $settings['google'] ) ? $settings['google'] : array();
	}

	/**
	 * Save google settings.
	 *
	 * @param array $google Google settings.
	 * @return void
	 */
	public function save_google_settings( $google ) {
		$settings           = get_option( 'dbbp_settings', array() );
		$settings['google'] = $google;
		update_option( 'dbbp_settings', $settings );
	}

	/**
	 * Get OAuth redirect URI.
	 *
	 * @return string
	 */
	public function get_redirect_uri() {
		return admin_url( 'admin.php?page=dbbp-settings' );
	}

	/**
	 * Find folder by name under parent.
	 *
	 * @param string $name Folder name.
	 * @param string $parent_id Parent id.
	 * @param string $token Access token.
	 * @return array
	 */
	private function find_folder( $name, $parent_id, $token ) {
		$query = sprintf(
			"name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false and '%s' in parents",
			addslashes( $name ),
			addslashes( $parent_id )
		);
		$url = add_query_arg(
			array(
				'q'      => $query,
				'fields' => 'files(id,name)',
			),
			$this->api_url . '/files'
		);

		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 45,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! empty( $body['files'][0] ) ) {
			return $body['files'][0];
		}
		return array();
	}

	/**
	 * Create folder.
	 *
	 * @param string $name Name.
	 * @param string $parent_id Parent id.
	 * @param string $token Token.
	 * @return array
	 */
	private function create_folder( $name, $parent_id, $token ) {
		$resp = wp_remote_post(
			$this->api_url . '/files',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'name'     => $name,
						'mimeType' => 'application/vnd.google-apps.folder',
						'parents'  => array( $parent_id ),
					)
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return array( 'success' => false, 'message' => $resp->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['id'] ) ) {
			return array( 'success' => false, 'message' => 'Unable to create folder on Google Drive.' );
		}
		return array( 'success' => true, 'id' => sanitize_text_field( $body['id'] ) );
	}

	/**
	 * Get valid access token.
	 *
	 * @return string|WP_Error
	 */
	private function get_valid_access_token() {
		$settings = $this->get_google_settings();
		if ( empty( $settings['access_token'] ) ) {
			return new WP_Error( 'dbbp_no_token', 'Google Drive is not connected.' );
		}

		$expires = isset( $settings['token_expires'] ) ? (int) $settings['token_expires'] : 0;
		$token   = $this->decrypt( $settings['access_token'] );

		if ( $expires > ( time() + 120 ) && ! empty( $token ) ) {
			return $token;
		}

		$refresh_token = $this->decrypt( $settings['refresh_token'] ?? '' );
		if ( empty( $refresh_token ) ) {
			return new WP_Error( 'dbbp_no_refresh_token', 'Google refresh token is missing.' );
		}

		$resp = wp_remote_post(
			$this->token_url,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => $settings['client_id'],
					'client_secret' => $settings['client_secret'],
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'dbbp_refresh_failed', 'Google token refresh failed: ' . wp_json_encode( $data ) );
		}

		$settings['access_token']  = $this->encrypt( $data['access_token'] );
		$settings['token_expires'] = time() + (int) ( $data['expires_in'] ?? 3500 );
		$this->save_google_settings( $settings );

		return $data['access_token'];
	}

	/**
	 * Encrypt value.
	 *
	 * @param string $plain Plain.
	 * @return string
	 */
	private function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt() . AUTH_KEY, true );
		$iv  = random_bytes( openssl_cipher_iv_length( 'AES-256-CBC' ) );
		$enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, 0, $iv );
		if ( false === $enc ) {
			return '';
		}
		return base64_encode( $iv . '::' . $enc );
	}

	/**
	 * Decrypt value.
	 *
	 * @param string $encrypted Enc.
	 * @return string
	 */
	private function decrypt( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}
		$key    = hash( 'sha256', wp_salt() . AUTH_KEY, true );
		$raw    = base64_decode( $encrypted, true );
		$chunks = $raw ? explode( '::', $raw, 2 ) : array();
		if ( 2 !== count( $chunks ) ) {
			return '';
		}
		$plain = openssl_decrypt( $chunks[1], 'AES-256-CBC', $key, 0, $chunks[0] );
		return false === $plain ? '' : $plain;
	}
}






