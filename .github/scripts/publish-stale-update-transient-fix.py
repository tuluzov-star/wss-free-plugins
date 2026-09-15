from pathlib import Path
import json
import re

HELPER = r'''<?php
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
'''

items = [
    ('plugins/bookings-schedule-free/wss-bookings-schedule.php', '0.3.19', '0.3.20', "define( 'WSS_BS_VERSION', '0.3.19' );", "define( 'WSS_BS_VERSION', '0.3.20' );"),
    ('plugins/wss-woocommerce-bookings/wss-woocommerce-bookings.php', '0.5.6', '0.5.7', "const VERSION = '0.5.6';", "const VERSION = '0.5.7';"),
]

for path, old, new, const_old, const_new in items:
    p = Path(path)
    s = p.read_text(encoding='utf-8')
    if f' * Version: {old}' not in s or const_old not in s:
        raise SystemExit(f'version token missing: {path}')
    s = s.replace(f' * Version: {old}', f' * Version: {new}', 1)
    s = s.replace(const_old, const_new, 1)
    include = "require_once __DIR__ . '/includes/class-wss-update-state-sanitizer.php';\nWSS_Update_State_Sanitizer_20260915::register( __FILE__ );\n"
    if 'class-wss-update-state-sanitizer.php' not in s:
        marker = "require_once __DIR__ . '/includes/class-wss-update-cache-control.php';\n"
        if marker not in s:
            raise SystemExit(f'cache marker missing: {path}')
        s = s.replace(marker, marker + include, 1)
    p.write_text(s, encoding='utf-8')
    (p.parent/'includes'/'class-wss-update-state-sanitizer.php').write_text(HELPER, encoding='utf-8')

readme = Path('plugins/bookings-schedule-free/readme.txt')
rs = readme.read_text(encoding='utf-8')
rs = re.sub(r'^Stable tag:\s*[^\r\n]+', 'Stable tag: 0.3.20', rs, count=1, flags=re.M)
if '= 0.3.20 =' not in rs:
    rs = rs.replace('== Изменения ==\n', '== Изменения ==\n\n= 0.3.20 =\n* Исправлено повторное предложение обновить плагин до уже установленной версии.\n', 1)
readme.write_text(rs, encoding='utf-8')

manifest_path = Path('manifest.json')
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
manifest['plugins']['wss_bookings_schedule_free']['version'] = '0.3.20'
manifest['plugins']['wss_bookings_schedule_free']['zip_path'] = 'releases/bookings-schedule/free/wss-bookings-schedule-lite-0.3.20.zip'
manifest['plugins']['wss_woocommerce_bookings_free']['version'] = '0.5.7'
manifest['plugins']['wss_woocommerce_bookings_free']['zip_path'] = 'releases/wss-woocommerce-bookings/free/wss-woocommerce-bookings-0.5.7-free.zip'
manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
