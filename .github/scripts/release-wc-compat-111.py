import json
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WC_TESTED = '11.1'

PRODUCTS = {
    'ydzs_free': ('plugins/delivery-zones-free/yd-zones-shipping.php','1.4.27','1.4.28','releases/delivery-zones/free/yd-zones-shipping-free-1.4.28.zip','yd-zones-shipping',"define( 'YDZS_VERSION', '{version}' );",'7.0'),
    'order_status_colors_free': ('plugins/order-status-colors-free/wss-order-status-colors.php','1.1.6','1.1.7','releases/order-status-colors/free/wss-order-status-colors-1.1.7.zip','wss-order-status-colors',"define( 'WSS_OSC_VERSION', '{version}' );",'5.0'),
    'wss_bookings_schedule_free': ('plugins/bookings-schedule-free/wss-bookings-schedule.php','0.3.15','0.3.16','releases/bookings-schedule/free/wss-bookings-schedule-lite-0.3.16.zip','wss-bookings-schedule-lite',"define( 'WSS_BS_VERSION', '{version}' );",'7.0'),
    'wss_yandex_calendar_sync_free': ('plugins/yandex-calendar-sync-free/wss-wc-bookings-yandex-calendar.php','1.0.2','1.0.3','releases/yandex-calendar-sync/free/wss-wc-bookings-yandex-calendar-free-1.0.3.zip','wss-wc-bookings-yandex-calendar',"define('WSS_WCB_YC_VERSION', '{version}');",'7.0'),
    'wss_woocommerce_bookings_free': ('plugins/wss-woocommerce-bookings/wss-woocommerce-bookings.php','0.5.3','0.5.4','releases/wss-woocommerce-bookings/free/wss-woocommerce-bookings-0.5.4-free.zip','wss-woocommerce-bookings',"    const VERSION = '{version}';",'6.0'),
}


def update_header(text, old_version, new_version, wc_requires):
    text, n = re.subn(r'(?m)^ \* Version: ' + re.escape(old_version) + r'\s*$', f' * Version: {new_version}', text, count=1)
    if n != 1:
        raise RuntimeError(f'Plugin header version {old_version} not found')
    if re.search(r'(?m)^ \* WC tested up to:', text):
        text = re.sub(r'(?m)^ \* WC tested up to:.*$', f' * WC tested up to: {WC_TESTED}', text, count=1)
    else:
        anchor = re.search(r'(?m)^ \* WC requires at least:.*$', text)
        if anchor:
            text = text.replace(anchor.group(0), anchor.group(0) + f'\n * WC tested up to: {WC_TESTED}', 1)
        else:
            anchor = re.search(r'(?m)^ \* Requires PHP:.*$', text)
            if anchor:
                text = text.replace(anchor.group(0), anchor.group(0) + f'\n * WC requires at least: {wc_requires}\n * WC tested up to: {WC_TESTED}', 1)
            else:
                anchor = re.search(r'(?m)^ \* Requires Plugins:.*$', text) or re.search(r'(?m)^ \* Domain Path:.*$', text)
                if not anchor:
                    raise RuntimeError('No header anchor for WC metadata')
                text = text.replace(anchor.group(0), f' * WC requires at least: {wc_requires}\n * WC tested up to: {WC_TESTED}\n' + anchor.group(0), 1)
    if not re.search(r'(?m)^ \* WC requires at least:', text):
        anchor = re.search(r'(?m)^ \* WC tested up to:.*$', text)
        text = text.replace(anchor.group(0), f' * WC requires at least: {wc_requires}\n' + anchor.group(0), 1)
    if not re.search(r'(?m)^ \* Requires Plugins:.*woocommerce', text):
        anchor = re.search(r'(?m)^ \* WC tested up to:.*$', text)
        text = text.replace(anchor.group(0), anchor.group(0) + '\n * Requires Plugins: woocommerce', 1)
    return text


def build_zip(source_dir, zip_path, package_dir):
    zip_path.parent.mkdir(parents=True, exist_ok=True)
    if zip_path.exists():
        zip_path.unlink()
    files = sorted(p for p in source_dir.rglob('*') if p.is_file() and p.name not in {'.DS_Store','Thumbs.db'} and p.suffix.lower() not in {'.bak','.log','.map'})
    with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_STORED) as zf:
        for path in files:
            rel = path.relative_to(source_dir).as_posix()
            info = zipfile.ZipInfo(f'{package_dir}/{rel}', date_time=(1980,1,1,0,0,0))
            info.compress_type = zipfile.ZIP_STORED
            info.external_attr = 0o100644 << 16
            zf.writestr(info, path.read_bytes())


manifest_path = ROOT / 'manifest.json'
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
for pid, (rel, old, new, zip_rel, package_dir, template, wc_requires) in PRODUCTS.items():
    path = ROOT / rel
    text = update_header(path.read_text(encoding='utf-8'), old, new, wc_requires)
    old_line = template.format(version=old)
    new_line = template.format(version=new)
    if old_line not in text:
        raise RuntimeError(f'{pid}: version constant not found')
    path.write_text(text.replace(old_line, new_line, 1), encoding='utf-8')
    readme = path.parent / 'readme.txt'
    if readme.exists():
        r = readme.read_text(encoding='utf-8')
        r = re.sub(r'(?mi)^Stable tag:\s*' + re.escape(old) + r'\s*$', f'Stable tag: {new}', r, count=1)
        readme.write_text(r, encoding='utf-8')
    item = manifest['plugins'][pid]
    item['version'] = new
    item['zip_path'] = zip_rel
    item['package_dir'] = package_dir
    item['compression'] = 'store'
    build_zip(path.parent, ROOT / zip_rel, package_dir)
manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print('Published public Free WooCommerce 11.1 compatibility packages')
