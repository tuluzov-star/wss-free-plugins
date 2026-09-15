from pathlib import Path
import json, subprocess
from zipfile import ZipFile, ZipInfo, ZIP_STORED


def replace_exact(text, old, new, count=1, label=''):
    found = text.count(old)
    if found != count:
        raise SystemExit(f'{label or "replacement"}: expected {count} occurrence(s), found {found}: {old[:120]!r}')
    return text.replace(old, new, count)

free = Path('plugins/delivery-zones-free/yd-zones-shipping.php')
text = free.read_text(encoding='utf-8')
text = replace_exact(text, ' * Version: 1.4.30', ' * Version: 1.4.31', label='header')
text = replace_exact(text, "define( 'YDZS_VERSION', '1.4.30' );", "define( 'YDZS_VERSION', '1.4.31' );", label='const')
text = replace_exact(text, "\t\t'google_api_key'=> '',\n", "\t\t'google_api_key'         => '', // Legacy shared key; kept for backward compatibility.\n\t\t'google_browser_api_key' => '',\n\t\t'google_server_api_key'  => '',\n", count=2, label='defaults')

marker = "function ydzs_get_address_hint_text( ?array $settings = null ): string {"
helpers = r'''/** Return the browser-restricted Google key, falling back to the legacy shared key. */
function ydzs_get_google_browser_api_key( ?array $settings = null ): string {
	$settings = $settings ?? ydzs_get_settings();
	$key      = trim( (string) ( $settings['google_browser_api_key'] ?? '' ) );
	return '' !== $key ? $key : trim( (string) ( $settings['google_api_key'] ?? '' ) );
}

/** Return the server-restricted Google key, falling back to the legacy shared key. */
function ydzs_get_google_server_api_key( ?array $settings = null ): string {
	$settings = $settings ?? ydzs_get_settings();
	$key      = trim( (string) ( $settings['google_server_api_key'] ?? '' ) );
	return '' !== $key ? $key : trim( (string) ( $settings['google_api_key'] ?? '' ) );
}

'''
text = replace_exact(text, marker, helpers + marker, label='key helpers')
old_server = "\t$api_key  = 'google' === $provider ? trim( (string) ( $settings['google_api_key'] ?? '' ) ) : trim( (string) ( $settings['api_key'] ?? '' ) );"
new_server = "\t$api_key  = 'google' === $provider ? ydzs_get_google_server_api_key( $settings ) : trim( (string) ( $settings['api_key'] ?? '' ) );"
text = replace_exact(text, old_server, new_server, count=3, label='server key uses')
text = replace_exact(text, "\t$address_bounds = ydzs_get_effective_address_bounds( $settings );\n", "\t$address_bounds = ydzs_get_effective_address_bounds( $settings );\n\t$suggest_delay  = (int) apply_filters( 'ydzs_address_suggest_delay_ms', 600, $settings );\n\t$suggest_delay  = max( 250, min( 2000, $suggest_delay ) );\n", label='debounce config')
text = replace_exact(text, "\t\t'suggestEnabled'    => $suggest_enabled,\n", "\t\t'suggestEnabled'    => $suggest_enabled,\n\t\t'suggestDelay'      => $suggest_delay,\n", label='debounce localization')
text = replace_exact(text, "\t$api_key      = 'google' === $map_provider ? trim( (string) ( $settings['google_api_key'] ?? '' ) ) : trim( (string) ( $settings['api_key'] ?? '' ) );", "\t$api_key      = 'google' === $map_provider ? ydzs_get_google_browser_api_key( $settings ) : trim( (string) ( $settings['api_key'] ?? '' ) );", label='browser map key')
text = replace_exact(text, "\t\t$map_key_ok = 'google' === ydzs_get_map_provider( $settings ) ? ! empty( $settings['google_api_key'] ) : ! empty( $settings['api_key'] );\n\t\t$geo_key_ok = 'google' === ydzs_get_geocode_provider( $settings ) ? ! empty( $settings['google_api_key'] ) : ! empty( $settings['api_key'] );", "\t\t$map_key_ok = 'google' === ydzs_get_map_provider( $settings ) ? '' !== ydzs_get_google_browser_api_key( $settings ) : ! empty( $settings['api_key'] );\n\t\t$geo_key_ok = 'google' === ydzs_get_geocode_provider( $settings ) ? '' !== ydzs_get_google_server_api_key( $settings ) : ! empty( $settings['api_key'] );", label='key status')
old_ui='''\t\t\t\t\t\t<tr>\n\t\t\t\t\t\t\t<th><label for="ydzs_google_api_key"><?php echo esc_html__( 'API-ключ Google', 'ydzs' ); ?></label></th>\n\t\t\t\t\t\t\t<td>\n\t\t\t\t\t\t\t\t<input type="text" class="regular-text" id="ydzs_google_api_key" name="google_api_key" value="<?php echo esc_attr( $settings['google_api_key'] ?? '' ); ?>">\n\t\t\t\t\t\t\t\t<p class="description"><?php echo esc_html__( 'Нужен для Google Maps JavaScript API и Google Geocoding API. В Google Cloud должен быть включен billing.', 'ydzs' ); ?></p>\n\t\t\t\t\t\t\t</td>\n\t\t\t\t\t\t</tr>\n'''
new_ui='''\t\t\t\t\t\t<tr>\n\t\t\t\t\t\t\t<th><label for="ydzs_google_browser_api_key"><?php echo esc_html__( 'API-ключ Google для браузера', 'ydzs' ); ?></label></th>\n\t\t\t\t\t\t\t<td>\n\t\t\t\t\t\t\t\t<input type="text" class="regular-text" id="ydzs_google_browser_api_key" name="google_browser_api_key" value="<?php echo esc_attr( ydzs_get_google_browser_api_key( $settings ) ); ?>" autocomplete="off">\n\t\t\t\t\t\t\t\t<p class="description"><?php echo esc_html__( 'Используется только Google Maps JavaScript API в браузере. Ограничьте ключ по HTTP referrer доменами вашего сайта.', 'ydzs' ); ?></p>\n\t\t\t\t\t\t\t</td>\n\t\t\t\t\t\t</tr>\n\t\t\t\t\t\t<tr>\n\t\t\t\t\t\t\t<th><label for="ydzs_google_server_api_key"><?php echo esc_html__( 'API-ключ Google для сервера', 'ydzs' ); ?></label></th>\n\t\t\t\t\t\t\t<td>\n\t\t\t\t\t\t\t\t<input type="password" class="regular-text" id="ydzs_google_server_api_key" name="google_server_api_key" value="<?php echo esc_attr( ydzs_get_google_server_api_key( $settings ) ); ?>" autocomplete="new-password">\n\t\t\t\t\t\t\t\t<p class="description"><?php echo esc_html__( 'Используется на сервере для Google Geocoding API и Places API (New). Ограничьте ключ IP-адресом веб-сервера и не публикуйте его.', 'ydzs' ); ?></p>\n\t\t\t\t\t\t\t</td>\n\t\t\t\t\t\t</tr>\n'''
text = replace_exact(text, old_ui, new_ui, label='Google UI')
text = replace_exact(text, "<?php echo esc_html__( 'Например: ru, nl, ee. Используется как подсказка региона для Google Geocoding API.', 'ydzs' ); ?>", "<?php echo esc_html__( 'Например: uk, nl, ee. Используется как регион для Google Geocoding API и Places API (New).', 'ydzs' ); ?>", label='region help')
text = replace_exact(text, "<?php echo esc_html__( 'Показывать покупателю выпадающий список адресов Яндекс.Карт при вводе', 'ydzs' ); ?>", "<?php echo esc_html__( 'Показывать покупателю выпадающий список адресов при вводе', 'ydzs' ); ?>", label='suggest label')
text = replace_exact(text, "<?php echo esc_html__( 'Для работы нужен API-ключ Яндекс.Карт. Покупателю проще выбрать полный адрес из списка, а плагин сразу проверит, входит ли выбранный адрес в зону доставки.', 'ydzs' ); ?>", "<?php echo esc_html__( 'Используется активный провайдер геокодирования: Яндекс в Free или Google Places в Pro. Запрос отправляется только после короткой паузы во вводе, затем выбранный адрес проверяется по зонам доставки.', 'ydzs' ); ?>", label='suggest help')
text = replace_exact(text, "<?php echo esc_html__( 'Границы поиска адреса', 'ydzs' ); ?>", "<?php echo esc_html__( 'Ограничение поиска зоной доставки', 'ydzs' ); ?>", label='bounds label')
text = replace_exact(text, "<?php echo esc_html__( 'Плагин строит техническую рамку по полигонам зон и передаёт её в Яндекс.Карты/геокодер. Это лучше, чем общий регион вроде «Ленинградская область», потому что одинаковые улицы из других районов не должны попадать в приоритет.', 'ydzs' ); ?>", "<?php echo esc_html__( 'Плагин строит техническую рамку по полигонам и передаёт её активному провайдеру подсказок/геокодирования. Это уменьшает количество нерелевантных адресов за пределами области доставки; точное попадание всё равно проверяется по самим полигонам.', 'ydzs' ); ?>", label='bounds help')
text = replace_exact(text, "\t$settings = array(\n\t\t'api_key'       => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',\n\t\t'google_api_key'=> isset( $_POST['google_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['google_api_key'] ) ) : '',", "\t$current_settings = ydzs_get_settings();\n\t$legacy_google_key = trim( (string) ( $current_settings['google_api_key'] ?? '' ) );\n\n\t$settings = array(\n\t\t'api_key'       => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',\n\t\t'google_api_key'         => $legacy_google_key,\n\t\t'google_browser_api_key' => isset( $_POST['google_browser_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['google_browser_api_key'] ) ) : '',\n\t\t'google_server_api_key'  => isset( $_POST['google_server_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['google_server_api_key'] ) ) : '',", label='save keys')
free.write_text(text, encoding='utf-8')

front=Path('plugins/delivery-zones-free/includes/frontend-inline-script.php')
text=front.read_text(encoding='utf-8')
text=replace_exact(text, "\tconst suggestEnabled = !!cfg.suggestEnabled && typeof cfg.ajaxUrl === 'string' && cfg.ajaxUrl;\n", "\tconst suggestEnabled = !!cfg.suggestEnabled && typeof cfg.ajaxUrl === 'string' && cfg.ajaxUrl;\n\tconst suggestDelay = Number.isFinite(Number(cfg.suggestDelay)) ? Math.max(250, Math.min(2000, Number(cfg.suggestDelay))) : 600;\n", label='frontend delay')
text=replace_exact(text, "\t\t}, 300);\n\t}\n\n\tfunction applyValidationResponse", "\t\t}, suggestDelay);\n\t}\n\n\tfunction applyValidationResponse", label='frontend timer')
front.write_text(text, encoding='utf-8')

readme=Path('plugins/delivery-zones-free/README.md')
r=readme.read_text(encoding='utf-8')
if '## 1.4.31' not in r:
    readme.write_text(r.rstrip()+"\n\n## 1.4.31\n\n- Address suggestions now use a 600 ms debounce after the customer pauses typing (filterable with `ydzs_address_suggest_delay_ms`).\n- Google Pro settings now support separate browser and server API keys with backward compatibility for the legacy shared key.\n- Address suggestion and delivery-area restriction help text is provider-neutral for Yandex/Google.\n",encoding='utf-8')

translations={
'API-ключ Google для браузера':'Google browser API key',
'Используется только Google Maps JavaScript API в браузере. Ограничьте ключ по HTTP referrer доменами вашего сайта.':'Used only by Google Maps JavaScript API in the browser. Restrict this key by HTTP referrer to your site domains.',
'API-ключ Google для сервера':'Google server API key',
'Используется на сервере для Google Geocoding API и Places API (New). Ограничьте ключ IP-адресом веб-сервера и не публикуйте его.':'Used server-side by Google Geocoding API and Places API (New). Restrict this key by the web server IP address and do not expose it publicly.',
'Например: uk, nl, ee. Используется как регион для Google Geocoding API и Places API (New).':'For example: uk, nl, ee. Used as the region for Google Geocoding API and Places API (New).',
'Показывать покупателю выпадающий список адресов при вводе':'Show address suggestions while the customer types',
'Используется активный провайдер геокодирования: Яндекс в Free или Google Places в Pro. Запрос отправляется только после короткой паузы во вводе, затем выбранный адрес проверяется по зонам доставки.':'Uses the active geocoding provider: Yandex in Free or Google Places in Pro. A request is sent only after a short pause in typing, then the selected address is checked against the delivery zones.',
'Ограничение поиска зоной доставки':'Limit address search to the delivery area',
'Плагин строит техническую рамку по полигонам и передаёт её активному провайдеру подсказок/геокодирования. Это уменьшает количество нерелевантных адресов за пределами области доставки; точное попадание всё равно проверяется по самим полигонам.':'The plugin builds a bounding box from the delivery polygons and sends it to the active suggestion/geocoding provider. This reduces irrelevant addresses outside the delivery area; the exact match is still checked against the actual polygons.'}
json_path=Path('plugins/delivery-zones-free/languages/ydzs-en_US.json')
data=json.loads(json_path.read_text(encoding='utf-8'))
messages=data.setdefault('locale_data',{}).setdefault('messages',{})
for msgid,msgstr in translations.items(): messages[msgid]=[msgstr]
json_path.write_text(json.dumps(data,ensure_ascii=False,separators=(',',':')),encoding='utf-8')

def q(s): return json.dumps(s,ensure_ascii=False)
po=Path('plugins/delivery-zones-free/languages/ydzs-en_US.po'); pot=Path('plugins/delivery-zones-free/languages/ydzs.pot')
po_text=po.read_text(encoding='utf-8').rstrip()+'\n'; pot_text=pot.read_text(encoding='utf-8').rstrip()+'\n'
for msgid,msgstr in translations.items():
    if f'msgid {q(msgid)}' not in po_text: po_text += f'\n#: yd-zones-shipping.php\nmsgid {q(msgid)}\nmsgstr {q(msgstr)}\n'
    if f'msgid {q(msgid)}' not in pot_text: pot_text += f'\n#: yd-zones-shipping.php\nmsgid {q(msgid)}\nmsgstr ""\n'
po.write_text(po_text,encoding='utf-8'); pot.write_text(pot_text,encoding='utf-8')
subprocess.run(['msgfmt','-o','plugins/delivery-zones-free/languages/ydzs-en_US.mo',str(po)],check=True)

manifest_path=Path('manifest.json'); manifest=json.loads(manifest_path.read_text(encoding='utf-8-sig'))
manifest['plugins']['ydzs_free']['version']='1.4.31'; manifest['plugins']['ydzs_free']['zip_path']='releases/delivery-zones/free/yd-zones-shipping-free-1.4.31.zip'
manifest_path.write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
for php in Path('plugins/delivery-zones-free').rglob('*.php'): subprocess.run(['php','-l',str(php)],check=True,stdout=subprocess.DEVNULL)

source=Path('plugins/delivery-zones-free'); out=Path('releases/delivery-zones/free/yd-zones-shipping-free-1.4.31.zip'); out.parent.mkdir(parents=True,exist_ok=True)
if out.exists(): out.unlink()
with ZipFile(out,'w',compression=ZIP_STORED,allowZip64=True) as zf:
    for path in sorted(source.rglob('*')):
        if not path.is_file() or path.name in {'.DS_Store','Thumbs.db'} or path.suffix.lower() in {'.bak','.log','.map'}: continue
        info=ZipInfo('yd-zones-shipping/'+path.relative_to(source).as_posix(),date_time=(1980,1,1,0,0,0)); info.compress_type=ZIP_STORED; info.create_system=3; info.external_attr=(0o100644&0xFFFF)<<16
        zf.writestr(info,path.read_bytes())
with ZipFile(out) as zf:
    bad=zf.testzip()
    if bad: raise SystemExit(f'Bad ZIP member: {bad}')
print(f'Published {out} ({out.stat().st_size} bytes)')
