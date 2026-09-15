<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WSS_Update_State_Sanitizer_20260915', false ) ) {
	final class WSS_Update_State_Sanitizer_20260915 {
		private static array $plugins = array();
		private static bool $booted = false;

		public static function register( string $plugin_file ): void {
			$basename = plugin_basename( $plugin_file );
			if ( '' === $basename ) {
				return;
			}
			self::$plugins[ $basename ] = true;
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;
			add_filter( 'site_transient_update_plugins', array( __CLASS__, 'sanitize' ), 999 );
			add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'sanitize' ), 999 );
			add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 999, 2 );
		}

		public static function sanitize( $transient ) {
			if ( ! is_object( $transient ) || empty( self::$plugins ) ) {
				return $transient;
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed = get_plugins();
			foreach ( array_keys( self::$plugins ) as $basename ) {
				$current = isset( $installed[ $basename ]['Version'] ) ? trim( (string) $installed[ $basename ]['Version'] ) : '';
				if ( '' === $current ) {
					continue;
				}
				if ( isset( $transient->checked ) && is_array( $transient->checked ) ) {
					$transient->checked[ $basename ] = $current;
				}
				if ( empty( $transient->response[ $basename ] ) || ! is_object( $transient->response[ $basename ] ) ) {
					continue;
				}
				$remote = isset( $transient->response[ $basename ]->new_version ) ? trim( (string) $transient->response[ $basename ]->new_version ) : '';
				if ( '' !== $remote && version_compare( $current, $remote, '>=' ) ) {
					unset( $transient->response[ $basename ] );
				}
			}
			return $transient;
		}

		public static function after_upgrade( $upgrader, array $hook_extra ): void {
			$changed = array();
			if ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
				$changed[] = $hook_extra['plugin'];
			}
			if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				$changed = array_merge( $changed, $hook_extra['plugins'] );
			}
			if ( ! array_intersect( array_keys( self::$plugins ), $changed ) ) {
				return;
			}
			delete_site_transient( 'update_plugins' );
			wp_clean_plugins_cache( true );
		}
	}
}
