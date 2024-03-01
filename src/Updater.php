<?php
declare( strict_types=1 );

namespace InstaWP\Connect\Helpers;

class Updater {

	public array $args;

	public function __construct( array $args = [] ) {
		$this->args = $args;
	}

	public function update(): array {
		if ( count( $this->args ) < 1 || count( $this->args ) > 5 ) {
			return [
				'success' => false,
				'message' => esc_html( 'Minimum 1 and Maximum 5 updates are allowed!' ),
			];
		}

		$results = [];
		foreach ( $this->args as $update ) {
			if ( empty( $update['type'] ) || count( $update ) != 2 ) {
				$results[] = [
					'success' => false,
					'message' => esc_html( 'Required parameters are missing!' ),
				];
				continue;
			}

			$results[] = 'core' === $update['type'] ? $this->core_updater( $update ) : $this->updater( $update['type'], $update['slug'] );
		}

		return $results;
	}

	private function core_updater( array $args = [] ): array {
		$args = wp_parse_args( $args, [
			'locale'  => get_locale(),
			'version' => get_bloginfo( 'version' )
		] );

		if ( ! function_exists( 'find_core_update' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'show_message' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$update = find_core_update( $args['version'], $args['locale'] );
		if ( ! $update ) {
			return [
				'message' => esc_html( 'Update not found!' ),
				'status'  => false,
			];
		}

		/*
		 * Allow relaxed file ownership writes for User-initiated upgrades when the API specifies
		* that it's safe to do so. This only happens when there are no new files to create.
		*/
		$allow_relaxed_file_ownership = isset( $update->new_files ) && ! $update->new_files;

		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		if ( ! class_exists( 'Core_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		}

		if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		}

		if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $update, [
			'allow_relaxed_file_ownership' => $allow_relaxed_file_ownership,
		] );

		if ( is_wp_error( $result ) ) {
			if ( $result->get_error_data() && is_string( $result->get_error_data() ) ) {
				$error_message = $result->get_error_message() . ': ' . $result->get_error_data();
			} else {
				$error_message = $result->get_error_message();
			}

			if ( 'up_to_date' !== $result->get_error_code() && 'locked' !== $result->get_error_code() ) {
				$error_message = __( 'Installation failed.' );
			}
		}

		$message = isset( $error_message ) ? trim( $error_message ) : '';

		return [
			'message' => empty( $message ) ? esc_html( 'Success!' ) : $message,
			'status'  => empty( $message ),
		];
	}

	private function updater( string $type, string $item ): array {
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		}

		if ( ! class_exists( 'Theme_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
		}

		if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		}

		if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}

		if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}

		add_filter( 'automatic_updater_disabled', '__return_false', 201 );
		add_filter( "auto_update_{$type}", '__return_true', 201 );
		
		$upgrader = new \WP_Automatic_Updater();
		$result   = false;

		if ( 'plugin' === $type ) {
			wp_update_plugins();

			$plugin_updates = get_site_transient( 'update_plugins' );
			if ( $plugin_updates && ! empty( $plugin_updates->response ) && ! empty( $plugin_updates->response[ $item ] ) ) {
				if ( ! function_exists( 'activate_plugin' ) || ! function_exists( 'is_plugin_active' ) ) {
					include_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$is_plugin_active = is_plugin_active( $item );

				$result = $upgrader->update( 'plugin', $plugin_updates->response[ $item ] );
				wp_clean_plugins_cache();
				wp_update_plugins();
				
				if ( $is_plugin_active ) {
					activate_plugin( $item, '', false, true );
				}
			}
		} elseif ( 'theme' === $type ) {
			wp_update_themes();

			$theme_updates = get_site_transient( 'update_themes' );
			if ( $theme_updates && ! empty( $theme_updates->response ) && ! empty( $theme_updates->response[ $item ] ) ) {
				$result = $upgrader->update( 'theme', ( object ) $theme_updates->response[ $item ] );
				wp_clean_themes_cache();
				wp_update_themes();
			}
		}

		remove_filter( 'automatic_updater_disabled', '__return_false', 201 );
		remove_filter( "auto_update_{$type}", '__return_true', 201 );

		if ( is_wp_error( $result ) ) {
			if ( $result->get_error_data() && is_string( $result->get_error_data() ) ) {
				$error_message = $result->get_error_message() . ': ' . $result->get_error_data();
			} else {
				$error_message = $result->get_error_message();
			}

			$message = isset( $error_message ) ? trim( $error_message ) : '';

			return [
				'message' => empty( $message ) ? esc_html( 'Success!' ) : $message,
				'status'  => empty( $message ),
			];
		}

		return [
			'message' => $result ? esc_html( 'Success!' ) : esc_html( 'Update Failed!' ),
			'status'  => $result,
		];
	}
}