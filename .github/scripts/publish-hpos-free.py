from pathlib import Path
import json, re

DECL = """
add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( \\Automattic\\WooCommerce\\Utilities\\FeaturesUtil::class ) ) {
        \\Automattic\\WooCommerce\\Utilities\\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
"""

def patch_php(path, old_ver, new_ver, const_old, const_new):
    p = Path(path)
    s = p.read_text(encoding='utf-8')
    s = s.replace(f' * Version: {old_ver}', f' * Version: {new_ver}', 1)
    s = s.replace(const_old, const_new, 1)
    if "custom_order_tables" not in s:
        m = re.search(r"if\s*\(\s*!\s*defined\(\s*['\"]ABSPATH['\"]\s*\)\s*\)\s*\{\s*exit;\s*\}\s*", s)
        if not m:
            raise SystemExit(f'ABSPATH guard not found: {path}')
        s = s[:m.end()] + "\n" + DECL + "\n" + s[m.end():]
    p.write_text(s, encoding='utf-8')

patch_php(
    'plugins/bookings-schedule-free/wss-bookings-schedule.php',
    '0.3.18', '0.3.19',
    "define( 'WSS_BS_VERSION', '0.3.18' );",
    "define( 'WSS_BS_VERSION', '0.3.19' );"
)
patch_php(
    'plugins/wss-woocommerce-bookings/wss-woocommerce-bookings.php',
    '0.5.5', '0.5.6',
    "const VERSION = '0.5.5';",
    "const VERSION = '0.5.6';"
)

readme = Path('plugins/bookings-schedule-free/readme.txt')
rs = readme.read_text(encoding='utf-8')
rs = re.sub(r'^Stable tag:\s*[^\r\n]+', 'Stable tag: 0.3.19', rs, count=1, flags=re.M)
if '= 0.3.19 =' not in rs:
    rs = rs.replace('== Изменения ==\n', '== Изменения ==\n\n= 0.3.19 =\n* Объявлена совместимость с High-Performance Order Storage (HPOS) WooCommerce после проверки используемых API.\n', 1)
readme.write_text(rs, encoding='utf-8')

manifest_path = Path('manifest.json')
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
manifest['plugins']['wss_bookings_schedule_free']['version'] = '0.3.19'
manifest['plugins']['wss_bookings_schedule_free']['zip_path'] = 'releases/bookings-schedule/free/wss-bookings-schedule-lite-0.3.19.zip'
manifest['plugins']['wss_woocommerce_bookings_free']['version'] = '0.5.6'
manifest['plugins']['wss_woocommerce_bookings_free']['zip_path'] = 'releases/wss-woocommerce-bookings/free/wss-woocommerce-bookings-0.5.6-free.zip'
manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
