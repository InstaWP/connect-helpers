<?php
namespace InstaWP\Connect\Helpers;

class DebugLog {

	/**
	 * Most bytes read from the end of the log. The whole file used to be parsed into memory, so a log of a
	 * few hundred MB made the request run out of memory and the viewer showed nothing; the newest entries
	 * are what the viewer is for.
	 */
	const MAX_READ_BYTES = 2097152;

    public function fetch() {
		try {
			$debug_enabled  = false;
			$debug_log_file = WP_CONTENT_DIR . '/debug.log';

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				$debug_enabled = true;

				if ( is_string( WP_DEBUG_LOG ) && file_exists( WP_DEBUG_LOG ) ) {
					$debug_log_file = WP_DEBUG_LOG;
				}
			}

			if ( ! $debug_enabled ) {
				return [
					'success' => false,
					'message' => esc_html( 'WP Debug is not enabled!' ),
				];
			}

			$file = $debug_log_file;
			if ( ! file_exists( $file ) ) {
				return [
					'success' => false,
					'message' => esc_html( 'Debug file not found!' ),
				];
			}

			$fh = fopen( $file, 'r' );
			if ( ! $fh ) {
				return [
					'success' => false,
					'message' => esc_html( 'Debug file can\'t be opened!' ),
				];
			}

			$size = @filesize( $file );
			if ( $size !== false && $size > self::MAX_READ_BYTES ) {
				// Start in the last MAX_READ_BYTES and drop the partial line the cut landed in.
				fseek( $fh, -self::MAX_READ_BYTES, SEEK_END );
				fgets( $fh );
			}

			$logs  = [];
			$store = false;
			$index = 0;
			while ( $line = @fgets( $fh ) ) {
				$sep   = '$!$';
				$line  = preg_replace( "/^\[([0-9a-zA-Z-]+) ([0-9:]+) ([a-zA-Z_\/]+)\] (.*)$/i", "$1" . $sep . "$2" . $sep . "$3" . $sep . "$4", $line );
				$parts = explode( $sep, $line );

				if ( count( $parts ) >= 4 ) {
					$info = trim( preg_replace( '/\s+/', ' ', stripslashes( $parts[3] ) ) );
					$time = strtotime( $parts[1] );

					$logs[ $index ] = [
						'timestamp' => date( 'Y-m-d', strtotime( $parts[0] ) ) . ' ' . date( 'H:i:s', $time ),
						'timezone'  => $parts[2],
						'message'   => $info,
					];
					$index ++;
				} elseif ( $index > 0 ) {
					// A continuation line (e.g. a stack trace) belongs to the entry before it. One with no entry
					// before it (the read started mid-entry) is skipped.
					$last_index                     = $index - 1;
					$logs[ $last_index ]['message'] .= trim( preg_replace( '/\s+/', ' ', $line ) );
				}
			}
			@fclose( $fh );

			$results = $logs;
		} catch ( \Exception $e ) {
			$results = [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}

        return $results;
    }
}