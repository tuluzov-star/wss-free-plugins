<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WSS_BS_Settings {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function add_menu() {
		if ( defined( 'WSS_BSP_IS_PRO' ) && WSS_BSP_IS_PRO ) { return; }
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
		add_submenu_page( $parent, 'WSS Bookings Schedule', 'WSS Bookings Schedule', 'manage_woocommerce', 'wss-bookings-schedule', array( __CLASS__, 'render_page' ) );
	}

	public static function register_settings() {
		register_setting( 'wss_bs_settings_group', WSS_BS_Plugin::OPTION_NAME, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => WSS_BS_Plugin::defaults() ) );
	}

	public static function sanitize( $input ) {
		$defaults = WSS_BS_Plugin::defaults();
		$output = array();
		$checkboxes = array( 'show_price', 'show_duration', 'show_empty_days', 'hide_past_today', 'show_title' );
		foreach ( $defaults as $key => $default ) {
			if ( in_array( $key, $checkboxes, true ) ) { $output[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : 'no'; continue; }
			$value = isset( $input[ $key ] ) ? $input[ $key ] : $default;
			switch ( $key ) {
				case 'days': $output[ $key ] = max( 1, min( 7, absint( $value ) ) ); break;
				case 'start_mode': $output[ $key ] = in_array( $value, array( 'today', 'week' ), true ) ? $value : $default; break;
				case 'date_format': case 'range_date_format': case 'time_format': case 'title': case 'label_prev': case 'label_current': case 'label_next': case 'label_all_products': case 'label_no_events': case 'label_no_product_events': case 'label_book': case 'label_select': $output[ $key ] = sanitize_text_field( $value ); break;
				default: $output[ $key ] = $default; break;
			}
		}
		$output['selected_products'] = '';
		$output['excluded_products'] = '';
		$output['selected_categories'] = '';
		$output['show_product_filter'] = 'no';
		$output['show_capacity'] = 'no';
		$output['timezone_string'] = '';
		$output['wc_time_mode'] = 'local_wall';
		$output['hide_resource_products'] = 'yes';
		return wp_parse_args( $output, WSS_BS_Plugin::defaults() );
	}

	public static function normalize_timezone_value( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^UTC([+-])(\d{1,2})(?:\.5)?$/', $value, $m ) ) { return sprintf( '%s%02d:%s', $m[1], (int) $m[2], false !== strpos( $value, '.5' ) ? '30' : '00' ); }
		if ( preg_match( '/^UTC([+-])(\d{1,2}):(\d{2})$/', $value, $m ) ) { return sprintf( '%s%02d:%02d', $m[1], (int) $m[2], (int) $m[3] ); }
		if ( preg_match( '/^([+-])(\d{1,2})(?::?(\d{2}))?$/', $value, $m ) ) { return sprintf( '%s%02d:%02d', $m[1], (int) $m[2], isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : 0 ); }
		return $value;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Недостаточно прав.', 'wss-bookings-schedule' ) ); }
		$options = WSS_BS_Plugin::get_options();
		?>
		<div class="wrap wss-bs-admin">
			<h1>WSS Bookings Schedule</h1>
			<p>Шорткод для вывода расписания:</p><p><code>[wss_bookings_schedule]</code></p>
			<div class="notice notice-info inline"><p><strong>Установлена Lite-версия.</strong> На этой же странице отображаются возможности текущей версии. После перехода на Pro здесь появятся дополнительные вкладки и блок лицензии.</p><p><a class="button button-primary" href="<?php echo esc_url( WSS_BS_UPGRADE_URL ); ?>" target="_blank" rel="noopener">Посмотреть Pro-версию</a></p></div>
			<h2 class="nav-tab-wrapper">
				<a href="#wss-bs-main" class="nav-tab nav-tab-active">Основные</a>
				<a href="#wss-bs-texts" class="nav-tab">Тексты</a>
				<a href="#wss-bs-view" class="nav-tab">Вывод</a>
				<a href="#wss-bs-free-pro" class="nav-tab">Free / Pro</a>
			</h2>
			<form method="post" action="options.php" class="wss-bs-settings-form">
				<?php settings_fields( 'wss_bs_settings_group' ); ?>
				<div id="wss-bs-main" class="wss-bs-tab is-active"><table class="form-table" role="presentation">
					<?php self::number_row( 'days', 'Количество дней для показа', $options['days'], 1, 7, 'В Lite доступно до 7 дней.' ); ?>
					<?php self::select_row( 'start_mode', 'Начало периода по умолчанию', $options['start_mode'], array( 'week' => 'С начала недели', 'today' => 'С сегодняшнего дня' ) ); ?>
					<?php self::checkbox_row( 'hide_past_today', 'Скрывать прошедшие слоты текущего дня', $options['hide_past_today'] ); ?>
					<?php self::checkbox_row( 'show_empty_days', 'Показывать дни без доступных экскурсий', $options['show_empty_days'] ); ?>
				</table></div>
				<div id="wss-bs-texts" class="wss-bs-tab"><table class="form-table" role="presentation">
					<?php self::checkbox_row( 'show_title', 'Показывать заголовок расписания', $options['show_title'], 'Обычно выключено, если на странице уже есть H1.' ); ?>
					<?php foreach ( array( 'title'=>'Заголовок расписания, если включен', 'label_prev'=>'Кнопка назад', 'label_current'=>'Кнопка текущего периода', 'label_next'=>'Кнопка вперед', 'label_no_events'=>'Нет событий', 'label_no_product_events'=>'Нет выбранной экскурсии на дату', 'label_book'=>'Кнопка бронирования', 'label_select'=>'Кнопка выбора', 'date_format'=>'Формат даты дня', 'range_date_format'=>'Формат даты в диапазоне', 'time_format'=>'Формат времени' ) as $key => $label ) { self::text_row( $key, $label, $options[ $key ] ); } ?>
				</table></div>
				<div id="wss-bs-view" class="wss-bs-tab"><table class="form-table" role="presentation"><?php self::checkbox_row( 'show_price', 'Показывать цену', $options['show_price'] ); self::checkbox_row( 'show_duration', 'Показывать длительность', $options['show_duration'] ); ?></table></div>
				<?php submit_button( 'Сохранить настройки', 'primary', 'submit', true, array( 'data-wss-bs-settings-submit' => '1' ) ); ?>
			</form>
			<div id="wss-bs-free-pro" class="wss-bs-tab">
				<h2>Free / Pro</h2>
				<table class="widefat striped" style="max-width:900px"><tbody><tr><td>Недельное расписание</td><td><strong>Lite</strong></td></tr><tr><td>Фильтр по экскурсиям на фронте</td><td><strong>Pro</strong></td></tr><tr><td>Показ остатка мест</td><td><strong>Pro</strong></td></tr><tr><td>Выбор/исключение товаров и категорий</td><td><strong>Pro</strong></td></tr><tr><td>Настройка таймзоны и режима времени WooCommerce Bookings</td><td><strong>Pro</strong></td></tr><tr><td>Цвета, скругления карточек и кнопок</td><td><strong>Pro</strong></td></tr><tr><td>Период вывода до 31 дня</td><td><strong>Pro</strong></td></tr></tbody></table>
				<p><a class="button button-primary" href="<?php echo esc_url( WSS_BS_UPGRADE_URL ); ?>" target="_blank" rel="noopener">Перейти к Pro-версии</a></p>
			</div>
		</div>
		<?php self::admin_assets(); ?>
		<?php
	}

	private static function admin_assets() { ?><style>.wss-bs-tab{display:none}.wss-bs-tab.is-active{display:block}.wss-bs-admin code{font-size:14px}</style><script>document.addEventListener('DOMContentLoaded',function(){var tabs=[].slice.call(document.querySelectorAll('.wss-bs-admin .nav-tab')),panels=[].slice.call(document.querySelectorAll('.wss-bs-admin .wss-bs-tab')),btn=document.querySelector('[data-wss-bs-settings-submit]');function on(tab){if(!tab)return;tabs.forEach(function(x){x.classList.remove('nav-tab-active')});panels.forEach(function(x){x.classList.remove('is-active')});tab.classList.add('nav-tab-active');var panel=document.querySelector(tab.getAttribute('href'));if(panel)panel.classList.add('is-active');if(btn&&btn.closest('p'))btn.closest('p').style.display=tab.getAttribute('href')==='#wss-bs-free-pro'?'none':''}tabs.forEach(function(tab){tab.addEventListener('click',function(e){e.preventDefault();on(tab);if(history.replaceState)history.replaceState(null,'',tab.getAttribute('href'))})});on(document.querySelector('.wss-bs-admin .nav-tab-active'))});</script><?php }
	private static function field_name( $key ) { return WSS_BS_Plugin::OPTION_NAME . '[' . esc_attr( $key ) . ']'; }
	private static function text_row( $key, $label, $value, $description = '' ) { ?><tr><th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input class="regular-text" type="text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::field_name( $key ) ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php if ( $description ) : ?><p class="description"><?php echo esc_html( $description ); ?></p><?php endif; ?></td></tr><?php }
	private static function number_row( $key, $label, $value, $min, $max, $description = '' ) { ?><tr><th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input type="number" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::field_name( $key ) ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>"><?php if ( $description ) : ?><p class="description"><?php echo esc_html( $description ); ?></p><?php endif; ?></td></tr><?php }
	private static function checkbox_row( $key, $label, $value, $description = '' ) { ?><tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::field_name( $key ) ); ?>" value="1" <?php checked( $value, 'yes' ); ?>> Включено</label><?php if ( $description ) : ?><p class="description"><?php echo esc_html( $description ); ?></p><?php endif; ?></td></tr><?php }
	private static function select_row( $key, $label, $value, $choices, $description = '' ) { ?><tr><th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::field_name( $key ) ); ?>"><?php foreach ( $choices as $choice_value => $choice_label ) : ?><option value="<?php echo esc_attr( $choice_value ); ?>" <?php selected( $value, $choice_value ); ?>><?php echo esc_html( $choice_label ); ?></option><?php endforeach; ?></select><?php if ( $description ) : ?><p class="description"><?php echo esc_html( $description ); ?></p><?php endif; ?></td></tr><?php }
}
