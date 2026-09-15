<?php
/**
 * Keeps WSS secondary updater caches synchronized with WordPress core checks.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WSS_Update_Cache_Control_20260915', false ) ) {
	final class WSS_Update_Cache_Control_20260915 {
		private static array $keys = array();
		private static bool $cleared = false;
		private static bool $booted = false;
		public static function register( string $key ): void {
			$key = trim( $key );
			if ( '' === $key ) { return; }
			self::$keys[ $key ] = true;
			if ( ! self::$booted ) {
				self::$booted = true;
				add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'before_core_update_transient' ), 1 );
			}
		}
		public static function before_core_update_transient( $transient ) {
			if ( self::$cleared ) { return $transient; }
			self::$cleared = true;
			foreach ( array_keys( self::$keys ) as $key ) {
				delete_site_transient( $key );
				delete_transient( $key );
			}
			return $transient;
		}
	}
}
