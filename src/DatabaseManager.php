<?php
declare( strict_types=1 );

namespace InstaWP\Connect\Helpers;

use Exception;
use InstaWP\Connect\Helpers\Helper;
use InstaWP\Connect\Helpers\WPConfig;

class DatabaseManager {

	public string $file;
	public static string $query_var = 'instawp-database-manager';

    public function get(): array {
        $results = [];
		
		$this->clean();

		$file_name = Helper::get_random_string( 20 );
		$token     = md5( $file_name );
		$url       = 'https://github.com/vrana/adminer/releases/download/v4.8.1/adminer-4.8.1-mysql.php';

		$search  = [
			'/\bjs_escape\b/',
			'/\bget_temp_dir\b/',
			'/\bis_ajax\b/',
			'/\bsid\b/',
		];
		$replace = [
			'instawp_js_escape',
			'instawp_get_temp_dir',
			'instawp_is_ajax',
			'instawp_sid',
		];

		$file = file_get_contents( $url );
		$file = preg_replace( $search, $replace, $file );

		$file_path            = self::get_file_path( $file_name );
		$database_manager_url = self::get_database_manager_url( $file_name );

		$results = [
			'login_url' => add_query_arg( [
				'action' => 'instawp-database-manager-auto-login',
				'token'  => hash( 'sha256', $token ),
			], admin_url( 'admin-post.php' ) ),
		];

		try {
			$result = file_put_contents( $file_path, $file, LOCK_EX );
			if ( false === $result ) {
				throw new Exception( esc_html( 'Failed to create the database manager file.' ) );
			}

			$file       = file( $file_path );
			$new_line   = "if ( ! defined( 'INSTAWP_PLUGIN_DIR' ) ) { die; }";
			$first_line = array_shift( $file );
			array_unshift( $file, $new_line );
			array_unshift( $file, $first_line );

			$fp = fopen( $file_path, 'w' );
			fwrite( $fp, implode( '', $file ) );
			fclose( $fp );

			$constants = [
				'INSTAWP_DATABASE_MANAGER_URL'       => $database_manager_url,
				'INSTAWP_DATABASE_MANAGER_FILE_NAME' => $file_name,
			];

			$wp_config = new WPConfig( $constants );
			$wp_config->update();

			set_transient( 'instawp_database_manager_login_token', $token, ( 15 * MINUTE_IN_SECONDS ) );
			flush_rewrite_rules();

			do_action( 'instawp_connect_create_database_manager_task', $file_name );
		} catch ( Exception $e ) {
			$results = [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}
		
        return $results;
    }

	public function clean( $file_name = null ): void {
		$file_name = defined( 'INSTAWP_DATABASE_MANAGER_FILE_NAME' ) ? INSTAWP_DATABASE_MANAGER_FILE_NAME : $file_name;

		if ( ! empty( $file_name ) ) {
			$file_path = self::get_file_path( $file_name );
			
			if ( file_exists( $file_path ) ) {
				@unlink( $file_path );
			}

			$constants = [ 
				'INSTAWP_DATABASE_MANAGER_URL',
				'INSTAWP_DATABASE_MANAGER_FILE_NAME'
			];
			$wp_config = new WPConfig( $constants );
			$wp_config->delete();

			flush_rewrite_rules();
			do_action( 'instawp_connect_remove_database_manager_task', $file_name );
		}
	}

	public static function get_query_var(): string {
		return self::$query_var;
	}

	public static function get_file_path( $file_name ): string {
		return INSTAWP_PLUGIN_DIR . '/includes/database-manager/instawp' . $file_name . '.php';
	}

	public static function get_database_manager_url( $file_name ): string {
		return home_url( self::$query_var . '/' . $file_name );
	}
}