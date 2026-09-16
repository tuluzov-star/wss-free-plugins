from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_STORED
import json


def replace_exact(text, old, new, count=1, label='replacement'):
    found = text.count(old)
    if found != count:
        raise SystemExit(f'{label}: expected {count} occurrence(s), found {found}: {old[:160]!r}')
    return text.replace(old, new, count)


free = Path('plugins/delivery-zones-free/yd-zones-shipping.php')
text = free.read_text(encoding='utf-8')
text = replace_exact(text, ' * Version: 1.4.31', ' * Version: 1.4.32', label='free header')
text = replace_exact(text, "define( 'YDZS_VERSION', '1.4.31' );", "define( 'YDZS_VERSION', '1.4.32' );", label='free const')
old_tail = "\t\t'checkoutContext'   => $is_checkout_context,\n\t\t'globalPopupMode'   => ! $is_checkout_context && $force_frontend,\n\t) );"
new_tail = "\t\t'checkoutContext'   => $is_checkout_context,\n\t\t'globalPopupMode'   => ! $is_checkout_context && $force_frontend,\n\t\t'messages'          => array(\n\t\t\t'chooseExact'      => __( 'Выберите точный адрес из списка подсказок, чтобы мы не рассчитали доставку по другому адресу.', 'ydzs' ),\n\t\t\t'insideZone'       => __( 'Адрес входит в зону доставки', 'ydzs' ),\n\t\t\t'outsideZones'     => __( 'Адрес отсутствует в зонах доставки. Для этого адреса доступен только самовывоз.', 'ydzs' ),\n\t\t\t'notFound'         => __( 'Не удалось найти подходящий адрес. Уточните населённый пункт, улицу и дом или выберите адрес из списка подсказок.', 'ydzs' ),\n\t\t\t'suggestionsLabel' => __( 'Подсказки адреса', 'ydzs' ),\n\t\t\t'zoneLabel'        => __( 'Зона доставки: ', 'ydzs' ),\n\t\t\t'noExactInZones'   => __( 'Не нашли точный адрес в зонах доставки. Уточните населённый пункт, улицу и дом или выберите адрес из списка подсказок.', 'ydzs' ),\n\t\t\t'refineAddress'     => __( 'Уточните адрес и выберите вариант из списка.', 'ydzs' ),\n\t\t\t'checkingAddress'   => __( 'Проверяем адрес...', 'ydzs' ),\n\t\t\t'checkFailedLong'   => __( 'Не удалось проверить адрес сейчас. Уточните адрес или попробуйте оформить заказ ещё раз.', 'ydzs' ),\n\t\t\t'checkingSelected'  => __( 'Проверяем выбранный адрес...', 'ydzs' ),\n\t\t\t'validateOnSubmit'  => __( 'Адрес будет проверен при оформлении заказа.', 'ydzs' ),\n\t\t\t'checkFailedShort'  => __( 'Не удалось проверить адрес сейчас. Попробуйте ещё раз.', 'ydzs' ),\n\t\t\t'fieldNotFound'     => __( 'Поле адреса не найдено.', 'ydzs' ),\n\t\t),\n\t) );"
text = replace_exact(text, old_tail, new_tail, label='frontend localized messages')
free.write_text(text, encoding='utf-8')

front = Path('plugins/delivery-zones-free/includes/frontend-inline-script.php')
text = front.read_text(encoding='utf-8')
needle = "\tconst houseHint = typeof cfg.houseHint === 'string' ? cfg.houseHint.trim() : '';\n"
insert = needle + "\tconst messages = cfg.messages && typeof cfg.messages === 'object' ? cfg.messages : {};\n\tfunction tr(key, fallback) {\n\t\tconst value = typeof messages[key] === 'string' ? messages[key].trim() : '';\n\t\treturn value || fallback;\n\t}\n"
text = replace_exact(text, needle, insert, label='frontend translation helper')
replacements = {
    'wp.i18n.__( "Выберите точный адрес из списка подсказок, чтобы мы не рассчитали доставку по другому адресу.", "ydzs" )': "tr('chooseExact', 'Choose the exact address from the suggestions so delivery is calculated for the correct address.')",
    'wp.i18n.__( "Адрес входит в зону доставки", "ydzs" )': "tr('insideZone', 'The address is within a delivery zone')",
    'wp.i18n.__( "Адрес отсутствует в зонах доставки. Для этого адреса доступен только самовывоз.", "ydzs" )': "tr('outsideZones', 'The address is outside the delivery zones. Only local pickup is available for this address.')",
    'wp.i18n.__( "Не удалось найти подходящий адрес. Уточните населённый пункт, улицу и дом или выберите адрес из списка подсказок.", "ydzs" )': "tr('notFound', 'Could not find a matching address. Check the town or city, street and house number, or select an address from the suggestions.')",
    'wp.i18n.__( "Подсказки адреса", "ydzs" )': "tr('suggestionsLabel', 'Address suggestions')",
    'wp.i18n.__( "Зона доставки: ", "ydzs" )': "tr('zoneLabel', 'Delivery zone: ')",
    'wp.i18n.__( "Не нашли точный адрес в зонах доставки. Уточните населённый пункт, улицу и дом или выберите адрес из списка подсказок.", "ydzs" )': "tr('noExactInZones', 'No exact address was found in the delivery zones. Check the town or city, street and house number, or select an address from the suggestions.')",
    'wp.i18n.__( "Уточните адрес и выберите вариант из списка.", "ydzs" )': "tr('refineAddress', 'Check the address and select a suggestion.')",
    'wp.i18n.__( "Проверяем адрес...", "ydzs" )': "tr('checkingAddress', 'Checking address...')",
    'wp.i18n.__( "Не удалось проверить адрес сейчас. Уточните адрес или попробуйте оформить заказ ещё раз.", "ydzs" )': "tr('checkFailedLong', 'Could not check the address right now. Check the address or try placing the order again.')",
    'wp.i18n.__( "Проверяем выбранный адрес...", "ydzs" )': "tr('checkingSelected', 'Checking selected address...')",
    'wp.i18n.__( "Адрес будет проверен при оформлении заказа.", "ydzs" )': "tr('validateOnSubmit', 'The address will be checked when the order is placed.')",
    'wp.i18n.__( "Не удалось проверить адрес сейчас. Попробуйте ещё раз.", "ydzs" )': "tr('checkFailedShort', 'Could not check the address right now. Try again.')",
    'wp.i18n.__( "Поле адреса не найдено.", "ydzs" )': "tr('fieldNotFound', 'Address field not found.')",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'frontend message not found: {old}')
    text = text.replace(old, new)
front.write_text(text, encoding='utf-8')

readme = Path('plugins/delivery-zones-free/README.md')
r = readme.read_text(encoding='utf-8')
if '## 1.4.32' not in r:
    readme.write_text(r.rstrip() + "\n\n## 1.4.32\n\n- Frontend helper and suggestion strings are localized on the PHP side, which also works reliably for the inline checkout script.\n- Keeps the 600 ms suggestion debounce introduced in 1.4.31.\n", encoding='utf-8')

manifest_path = Path('manifest.json')
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
plugin = manifest['plugins']['ydzs_free']
plugin['version'] = '1.4.32'
plugin['zip_path'] = 'releases/delivery-zones/free/yd-zones-shipping-free-1.4.32.zip'
manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')

source = Path(plugin['source_path'])
output = Path(plugin['zip_path'])
package_dir = plugin['package_dir']
output.parent.mkdir(parents=True, exist_ok=True)
skip_names = {'.DS_Store', 'Thumbs.db'}
skip_ext = {'.bak', '.log', '.map'}
files = [p for p in source.rglob('*') if p.is_file() and p.name not in skip_names and p.suffix.lower() not in skip_ext]
files.sort(key=lambda p: p.relative_to(source).as_posix())
with ZipFile(output, 'w', compression=ZIP_STORED, allowZip64=True) as zf:
    for path in files:
        rel = path.relative_to(source).as_posix()
        info = ZipInfo(f'{package_dir}/{rel}', date_time=(1980, 1, 1, 0, 0, 0))
        info.compress_type = ZIP_STORED
        info.create_system = 3
        info.external_attr = (0o100644 & 0xFFFF) << 16
        zf.writestr(info, path.read_bytes())
print(f'Published {output} ({output.stat().st_size} bytes)')
