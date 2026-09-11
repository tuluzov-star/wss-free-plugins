from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_STORED
import json

# Order Status Colors: replace JavaScript-driven row styling with server CSS.
osc = Path('plugins/order-status-colors-free/wss-order-status-colors.php')
text = osc.read_text(encoding='utf-8')
text = text.replace('Version: 1.1.4', 'Version: 1.1.5', 1)
text = text.replace("define( 'WSS_OSC_VERSION', '1.1.4' );", "define( 'WSS_OSC_VERSION', '1.1.5' );", 1)
start = text.index('\tpublic function enqueue_admin_assets( string $hook_suffix ): void {')
end = text.index('\n\tprivate function is_orders_admin_screen(): bool {', start)
replacement = r'''	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! $this->is_orders_admin_screen() ) {
			return;
		}

		$colors = $this->get_colors();
		if ( ! $colors ) {
			return;
		}

		$buttons = $this->get_button_styles();
		$css     = '';

		foreach ( $colors as $status_key => $color ) {
			$slug = sanitize_html_class( preg_replace( '/^wc-/', '', (string) $status_key ) );
			if ( ! $slug ) {
				continue;
			}

			$background = sanitize_hex_color( $color['background'] ?? '' ) ?: '#ffffff';
			$text_color = sanitize_hex_color( $color['text'] ?? '' ) ?: '#1d2327';
			$row_selectors = array(
				'.wp-list-table tr.status-' . $slug,
				'.wp-list-table tr.status-wc-' . $slug,
				'.wp-list-table tr.order-status-' . $slug,
				'.wp-list-table tr.order-status-wc-' . $slug,
				'.wp-list-table tr:has(.order-status.status-' . $slug . ')',
				'.wp-list-table tr:has(.order-status.status-wc-' . $slug . ')',
				'.wp-list-table tr:has(mark.status-' . $slug . ')',
				'.wp-list-table tr:has(mark.status-wc-' . $slug . ')',
			);

			$cell_selectors = array();
			$link_selectors = array();
			$button_selectors = array();
			$button_hover_selectors = array();
			foreach ( $row_selectors as $selector ) {
				$cell_selectors[] = $selector . ' > th';
				$cell_selectors[] = $selector . ' > td';
				$link_selectors[] = $selector . ' a:not(.button)';
				$link_selectors[] = $selector . ' .row-actions a:not(.button)';
				$button_selectors[] = $selector . ' .button';
				$button_selectors[] = $selector . ' a[class*="button"]';
				$button_selectors[] = $selector . ' button:not(.toggle-row):not(.components-button)';
				$button_hover_selectors[] = $selector . ' .button:hover';
				$button_hover_selectors[] = $selector . ' .button:focus';
				$button_hover_selectors[] = $selector . ' a[class*="button"]:hover';
				$button_hover_selectors[] = $selector . ' button:not(.toggle-row):not(.components-button):hover';
			}

			$css .= implode( ",\n", $cell_selectors ) . "{background-color:{$background};color:{$text_color};transition:background-color .15s ease;}\n";
			$css .= implode( ",\n", $link_selectors ) . "{color:{$text_color};}\n";
			$css .= '.wp-list-table .order-status.status-' . $slug . ',.wp-list-table .order-status.status-wc-' . $slug . ',.wp-list-table mark.status-' . $slug . ',.wp-list-table mark.status-wc-' . $slug . "{background-color:{$background};color:{$text_color};border-color:rgba(0,0,0,.12);}\n";
			$css .= '.wp-list-table .order-status.status-' . $slug . ' span,.wp-list-table .order-status.status-wc-' . $slug . ' span,.wp-list-table mark.status-' . $slug . ' span,.wp-list-table mark.status-wc-' . $slug . " span{color:{$text_color};}\n";

			if ( ! empty( $buttons['enabled'] ) ) {
				$button_background = sanitize_hex_color( $buttons['background'] ?? '' ) ?: '#3157e7';
				$button_text = sanitize_hex_color( $buttons['text'] ?? '' ) ?: '#ffffff';
				$button_border = sanitize_hex_color( $buttons['border'] ?? '' ) ?: $button_background;
				$button_hover_background = sanitize_hex_color( $buttons['hover_background'] ?? '' ) ?: '#2444bd';
				$button_hover_text = sanitize_hex_color( $buttons['hover_text'] ?? '' ) ?: '#ffffff';
				$button_radius = min( 40, max( 0, absint( $buttons['border_radius'] ?? 4 ) ) );
				$css .= implode( ",\n", $button_selectors ) . "{background:{$button_background};border-color:{$button_border};color:{$button_text};border-radius:{$button_radius}px;box-shadow:none;text-shadow:none;}\n";
				$css .= implode( ",\n", $button_hover_selectors ) . "{background:{$button_hover_background};border-color:{$button_hover_background};color:{$button_hover_text};box-shadow:0 0 0 1px rgba(0,0,0,.08);}\n";
			}
		}

		wp_register_style( 'wss-osc-admin-inline', false, array(), WSS_OSC_VERSION );
		wp_enqueue_style( 'wss-osc-admin-inline' );
		wp_add_inline_style( 'wss-osc-admin-inline', $css );
	}
'''
text = text[:start] + replacement + text[end:]
osc.write_text(text, encoding='utf-8')
osc_js = Path('plugins/order-status-colors-free/assets/admin.js')
if osc_js.exists():
    osc_js.unlink()

# Bookings Schedule Lite: remove the optional flagged admin helper entirely.
bs = Path('plugins/bookings-schedule-free/wss-bookings-schedule.php')
text = bs.read_text(encoding='utf-8')
text = text.replace('Version: 0.3.10', 'Version: 0.3.11', 1)
text = text.replace("define( 'WSS_BS_VERSION', '0.3.10' );", "define( 'WSS_BS_VERSION', '0.3.11' );", 1)
admin_start = text.find("add_action(\n    'admin_enqueue_scripts',")
activation = text.find('register_activation_hook', admin_start if admin_start >= 0 else 0)
if admin_start < 0 or activation < 0:
    raise SystemExit('Bookings Schedule admin helper block not found')
text = text[:admin_start] + text[activation:]
bs.write_text(text, encoding='utf-8')
bs_js = Path('plugins/bookings-schedule-free/assets/js/admin-tools.js')
if bs_js.exists():
    bs_js.unlink()

manifest_path = Path('manifest.json')
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
manifest['plugins']['order_status_colors_free'].update({
    'version': '1.1.5',
    'zip_path': 'releases/order-status-colors/free/wss-order-status-colors-1.1.5.zip',
    'package_dir': 'wss-order-status-colors',
    'compression': 'store',
})
manifest['plugins']['wss_bookings_schedule_free'].update({
    'version': '0.3.11',
    'zip_path': 'releases/bookings-schedule/free/wss-bookings-schedule-lite-0.3.11.zip',
    'package_dir': 'wss-bookings-schedule-lite',
    'compression': 'store',
})
manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')

# Build deterministic uncompressed ZIPs. No flagged JavaScript files are present.
for key in ('order_status_colors_free', 'wss_bookings_schedule_free'):
    item = manifest['plugins'][key]
    source = Path(item['source_path'])
    output = Path(item['zip_path'])
    package_dir = item['package_dir']
    output.parent.mkdir(parents=True, exist_ok=True)
    files = sorted([p for p in source.rglob('*') if p.is_file()], key=lambda p: p.relative_to(source).as_posix())
    with ZipFile(output, 'w', compression=ZIP_STORED, allowZip64=True) as zf:
        for path in files:
            rel = path.relative_to(source).as_posix()
            info = ZipInfo(f'{package_dir}/{rel}', date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = ZIP_STORED
            info.create_system = 3
            info.external_attr = (0o100644 & 0xFFFF) << 16
            zf.writestr(info, path.read_bytes())
    print(f'Built {output} ({output.stat().st_size} bytes)')
