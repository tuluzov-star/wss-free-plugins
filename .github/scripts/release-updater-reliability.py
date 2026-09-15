import json,re,shutil,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
HELPER=ROOT/'.github/scripts/wss-update-cache-control.php'
WCB=ROOT/'.github/scripts/wss-wc-bookings-free-updater.php'
P={
'ydzs_free':('plugins/delivery-zones-free/yd-zones-shipping.php','1.4.28','1.4.29','yd-zones-shipping',['ydzs_free_update_info'],"define( 'YDZS_VERSION', '1.4.28' );","define( 'YDZS_VERSION', '1.4.29' );",'https://website-support.ru/plugins/delivery-zones-for-woocommerce/'),
'order_status_colors_free':('plugins/order-status-colors-free/wss-order-status-colors.php','1.1.7','1.1.8','wss-order-status-colors',['wss_osc_free_update_info'],"define( 'WSS_OSC_VERSION', '1.1.7' );","define( 'WSS_OSC_VERSION', '1.1.8' );",'https://website-support.ru/plugins/order-status-colors-for-woocommerce/'),
'wss_bookings_schedule_free':('plugins/bookings-schedule-free/wss-bookings-schedule.php','0.3.16','0.3.17','wss-bookings-schedule-lite',['wss_bs_free_update_info'],"define( 'WSS_BS_VERSION', '0.3.16' );","define( 'WSS_BS_VERSION', '0.3.17' );",'https://website-support.ru/plugins/wss-bookings-schedule/'),
'wss_yandex_calendar_sync_free':('plugins/yandex-calendar-sync-free/wss-wc-bookings-yandex-calendar.php','1.0.3','1.0.4','wss-wc-bookings-yandex-calendar',['wss_wcb_yc_free_update_info'],"define('WSS_WCB_YC_VERSION', '1.0.3');","define('WSS_WCB_YC_VERSION', '1.0.4');",'https://website-support.ru/plugins/yandex-calendar-for-woocommerce-bookings/'),
'wss_woocommerce_bookings_free':('plugins/wss-woocommerce-bookings/wss-woocommerce-bookings.php','0.5.4','0.5.5','wss-woocommerce-bookings',['wss_wc_bookings_free_update_info'],"    const VERSION = '0.5.4';","    const VERSION = '0.5.5';",'https://website-support.ru/plugins/wss-woocommerce-bookings/'),
}
for pid,d in P.items():
    rel,old,new,pkg,keys,a,b,home=d; path=ROOT/rel; text=path.read_text(encoding='utf-8')
    if f' * Version: {new}' not in text:
        text=text.replace(f' * Version: {old}',f' * Version: {new}',1)
        if a not in text: raise RuntimeError(pid+' runtime version marker missing')
        text=text.replace(a,b,1)
        if ' * Update URI:' not in text:text=re.sub(r'(?m)^( \* Author URI:.*)$',r'\1\n * Update URI: '+home,text,count=1)
        m=re.search(r"WSS_Plugin_I18n_202609::register\([^;]+;",text)
        if not m: raise RuntimeError(pid+' i18n anchor missing')
        inc="require_once __DIR__ . '/includes/class-wss-update-cache-control.php';"; regs='\n'.join([f"WSS_Update_Cache_Control_20260915::register( '{k}' );" for k in keys])
        if inc not in text:text=text.replace(m.group(0),m.group(0)+'\n'+inc+'\n'+regs,1)
        text=text.replace("if ( is_admin() && class_exists( 'YDZS_Updater' ) ) {","if ( class_exists( 'YDZS_Updater' ) ) {",1)
        text=text.replace("if ( is_admin() && class_exists( 'WSS_BS_Updater' ) ) {","if ( class_exists( 'WSS_BS_Updater' ) ) {",1)
        text=text.replace("if (is_admin() && class_exists('WSS_WCB_YC_Updater')) {","if (class_exists('WSS_WCB_YC_Updater')) {",1)
        text=text.replace("\t\tif ( is_admin() && class_exists( 'WSS_OSC_Updater' ) ) {","\t\tif ( class_exists( 'WSS_OSC_Updater' ) ) {",1)
        path.write_text(text,encoding='utf-8')
    incdir=path.parent/'includes';incdir.mkdir(exist_ok=True);shutil.copyfile(HELPER,incdir/'class-wss-update-cache-control.php')

# Native updater for WSS WooCommerce Bookings Free.
wdir=ROOT/'plugins/wss-woocommerce-bookings';shutil.copyfile(WCB,wdir/'includes/class-wss-wc-bookings-free-updater.php')
p=wdir/'wss-woocommerce-bookings.php';t=p.read_text(encoding='utf-8');inc="require_once __DIR__ . '/includes/class-wss-wc-bookings-free-updater.php';"
if inc not in t:t=t.replace("require_once __DIR__ . '/includes/class-wss-update-cache-control.php';","require_once __DIR__ . '/includes/class-wss-update-cache-control.php';\n"+inc,1)
init="if ( class_exists( 'WSS_WC_Bookings_Free_Updater' ) ) {\n    new WSS_WC_Bookings_Free_Updater( __FILE__, WSS_WooCommerce_Bookings::VERSION, 'https://website-support.ru/plugins/wss-woocommerce-bookings/' );\n}"
if init not in t:t=t.rstrip()+'\n\n'+init+'\n'
p.write_text(t,encoding='utf-8')

mp=ROOT/'manifest.json';man=json.loads(mp.read_text(encoding='utf-8'))
for pid,d in P.items():
    item=man['plugins'][pid];item['version']=d[2]
    oldzip=item['zip_path'];item['zip_path']=oldzip.replace(d[1],d[2])
mp.write_text(json.dumps(man,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')

# Deterministic STORE ZIPs, preserving package folder.
for pid,d in P.items():
    item=man['plugins'][pid];src=ROOT/item['source_path'];dest=ROOT/item['zip_path'];dest.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(dest,'w',compression=zipfile.ZIP_STORED) as z:
        for f in sorted(src.rglob('*')):
            if f.is_file() and f.suffix.lower() not in {'.bak','.log','.map'}:
                info=zipfile.ZipInfo(item['package_dir']+'/'+f.relative_to(src).as_posix(),date_time=(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_STORED
                z.writestr(info,f.read_bytes())
print('Prepared public Free updater reliability releases')
