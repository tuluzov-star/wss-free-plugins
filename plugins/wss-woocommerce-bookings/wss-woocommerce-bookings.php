<?php
/**
 * Plugin Name: WSS WooCommerce Bookings
 * Description: Lightweight booking slots manager for WooCommerce products: schedule slots, frontend calendar, capacity checks and order item metadata. Pro add-on unlocks migrations, blocks, booking calendar, ticket types and exports.
 * Version: 0.5.1
 * Author: WSS
 * Author URI: https://website-support.ru/
 * Text Domain: wss-wc-bookings
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WSS_WooCommerce_Bookings {
    const VERSION = '0.5.1';
    const PRODUCT_META_ENABLED = '_wss_booking_enabled';
    const PRODUCT_META_DISABLE_AUTO_ALL_DAY = '_wss_booking_disable_auto_all_day';
    const ORDER_META_RESERVED = '_wss_booking_reserved';
    const ORDER_META_RESERVED_MAP = '_wss_booking_reserved_map';
    const IMPORT_REPORT_TRANSIENT = 'wss_wc_bookings_last_import_report';
    const ALL_DAY_NOTE = 'Автоматический слот на весь день';
    const ALL_DAY_START = '00:00:00';
    const ALL_DAY_END = '23:59:00';
    const ADMIN_PAGE_MAIN = 'wss-wc-bookings';
    const ADMIN_PAGE_TOOLS = 'wss-wc-bookings-tools';
    const ADMIN_PAGE_BLOCKS = 'wss-wc-bookings-blocks';
    const ADMIN_PAGE_CALENDAR = 'wss-wc-bookings-calendar';
    const ADMIN_PAGE_SETTINGS = 'wss-wc-bookings-settings';
    const ADMIN_PAGE_GUESTS = 'wss-wc-bookings-guests';
    const ADMIN_PAGE_PRO = 'wss-wc-bookings-pro';
    const OPTION_SETTINGS = 'wss_wc_bookings_settings';
    const PRODUCT_META_TICKET_TYPES = '_wss_booking_ticket_types';

    private static $instance = null;
    private $frontend_selector_rendered = [];

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'load']);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
    }

    public static function activate(): void {
        self::create_tables();
        update_option('wss_wc_bookings_version', self::VERSION, false);
    }

    private function maybe_upgrade(): void {
        $stored_version = (string) get_option('wss_wc_bookings_version', '');
        if ($stored_version !== self::VERSION) {
            self::create_tables();
            update_option('wss_wc_bookings_version', self::VERSION, false);
        }
    }

    public function load(): void {
        $this->maybe_upgrade();
        add_action('admin_notices', [$this, 'maybe_show_woocommerce_notice']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);

        if ($this->is_woocommerce_active()) {
            add_filter('woocommerce_product_class', [$this, 'maybe_map_legacy_booking_product_class'], 20, 4);
            add_filter('woocommerce_is_purchasable', [$this, 'booking_product_is_purchasable'], 20, 2);
            add_filter('woocommerce_product_get_price', [$this, 'fallback_booking_price'], 20, 2);
            add_filter('woocommerce_product_get_regular_price', [$this, 'fallback_booking_price'], 20, 2);
            add_action('woocommerce_product_options_general_product_data', [$this, 'product_options']);
            add_action('woocommerce_admin_process_product_object', [$this, 'save_product_options']);
            add_action('woocommerce_before_add_to_cart_button', [$this, 'render_frontend_slot_selector']);
            add_action('woocommerce_single_product_summary', [$this, 'render_fallback_frontend_booking_form'], 31);
            add_shortcode('wss_booking_form', [$this, 'booking_form_shortcode']);
            add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 20, 5);
            add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 20, 3);
            add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 20, 2);
            add_action('woocommerce_check_cart_items', [$this, 'validate_cart_before_checkout']);
            add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_item_meta'], 20, 4);
            add_action('woocommerce_checkout_order_processed', [$this, 'reserve_order_slots'], 20, 1);
            add_action('woocommerce_order_status_cancelled', [$this, 'release_order_slots']);
            add_action('woocommerce_order_status_failed', [$this, 'release_order_slots']);
            add_action('woocommerce_order_status_refunded', [$this, 'release_order_slots']);
            add_filter('woocommerce_add_to_cart_quantity', [$this, 'maybe_adjust_add_to_cart_quantity'], 20, 2);
            add_action('woocommerce_before_calculate_totals', [$this, 'apply_ticket_type_cart_prices'], 20, 1);
        }
    }

    private function is_woocommerce_active(): bool {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    private function is_pro_active(): bool {
        return (bool) apply_filters('wss_wc_bookings_pro_active', false);
    }

    private function require_pro_for_action(string $action): bool {
        $pro_actions = [
            'import_wc_bookings',
            'repair_wss_flags',
            'cleanup_inactive_wss',
            'create_missing_all_day_slots',
            'delete_all_slots',
            'create_block_dates',
            'create_block_weekdays',
            'delete_block',
            'update_settings',
            'export_guests_csv',
        ];

        if (in_array($action, $pro_actions, true) && !$this->is_pro_active()) {
            $this->redirect_admin(0, 'pro_required', [], self::ADMIN_PAGE_MAIN);
            return false;
        }

        return true;
    }


    public function maybe_map_legacy_booking_product_class(string $classname, string $product_type, string $post_type = 'product', int $product_id = 0): string {
        if ($product_type === 'booking' && !class_exists('WC_Product_Booking') && class_exists('WC_Product_Simple')) {
            return 'WC_Product_Simple';
        }
        return $classname;
    }

    public function booking_product_is_purchasable(bool $purchasable, $product): bool {
        if (!$product || !is_a($product, 'WC_Product')) {
            return $purchasable;
        }

        if (self::is_booking_product($product->get_id()) && $this->is_active_product((int) $product->get_id())) {
            return true;
        }

        return $purchasable;
    }

    public function fallback_booking_price($price, $product) {
        if ($price !== '' || !$product || !is_a($product, 'WC_Product') || !self::is_booking_product($product->get_id())) {
            return $price;
        }

        $meta_keys = [
            '_regular_price',
            '_wc_booking_display_cost',
            '_wc_booking_cost',
            '_wc_booking_block_cost',
            '_wc_booking_base_cost',
        ];

        foreach ($meta_keys as $meta_key) {
            $meta_value = get_post_meta($product->get_id(), $meta_key, true);
            if ($meta_value !== '' && is_numeric($meta_value)) {
                return (string) $meta_value;
            }
        }

        return $price;
    }

    public function maybe_show_woocommerce_notice(): void {
        if (!current_user_can('activate_plugins') || $this->is_woocommerce_active()) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>WSS WooCommerce Bookings:</strong> для работы плагина нужен активный WooCommerce.</p></div>';
    }

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'wss_booking_slots';
    }

    public static function blocks_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'wss_booking_blocks';
    }

    public static function create_tables(): void {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            slot_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            capacity INT UNSIGNED NOT NULL DEFAULT 0,
            booked INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY product_date (product_id, slot_date),
            KEY product_datetime (product_id, slot_date, start_time),
            KEY status (status)
        ) {$charset_collate};";

        $blocks_table = self::blocks_table_name();
        $blocks_sql = "CREATE TABLE {$blocks_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            block_date DATE NULL,
            weekday TINYINT UNSIGNED NOT NULL DEFAULT 0,
            date_from DATE NULL,
            date_to DATE NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY product_date (product_id, block_date),
            KEY weekday_range (weekday, date_from, date_to),
            KEY product_id (product_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($blocks_sql);
    }

    public function admin_menu(): void {
        add_menu_page(
            'WSS Bookings',
            'WSS Bookings',
            'manage_woocommerce',
            self::ADMIN_PAGE_MAIN,
            [$this, 'render_admin_page'],
            'dashicons-calendar-alt',
            56
        );

        add_submenu_page(
            self::ADMIN_PAGE_MAIN,
            'Расписание',
            'Расписание',
            'manage_woocommerce',
            self::ADMIN_PAGE_MAIN,
            [$this, 'render_admin_page']
        );

        if ($this->is_pro_active()) {
            add_submenu_page(
                self::ADMIN_PAGE_MAIN,
                'Массовые действия',
                'Массовые действия',
                'manage_woocommerce',
                self::ADMIN_PAGE_TOOLS,
                [$this, 'render_tools_page']
            );

            add_submenu_page(
                self::ADMIN_PAGE_MAIN,
                'Блокировки',
                'Блокировки',
                'manage_woocommerce',
                self::ADMIN_PAGE_BLOCKS,
                [$this, 'render_blocks_page']
            );

            add_submenu_page(
                self::ADMIN_PAGE_MAIN,
                'Календарь броней',
                'Календарь броней',
                'manage_woocommerce',
                self::ADMIN_PAGE_CALENDAR,
                [$this, 'render_bookings_calendar_page']
            );

            add_submenu_page(
                self::ADMIN_PAGE_MAIN,
                'Списки гостей',
                'Списки гостей',
                'manage_woocommerce',
                self::ADMIN_PAGE_GUESTS,
                [$this, 'render_guests_page']
            );

            add_submenu_page(
                self::ADMIN_PAGE_MAIN,
                'Настройки',
                'Настройки',
                'manage_woocommerce',
                self::ADMIN_PAGE_SETTINGS,
                [$this, 'render_settings_page']
            );
        } else {
            add_submenu_page(
                self::ADMIN_PAGE_MAIN,
                'Возможности Pro',
                'Возможности Pro',
                'manage_woocommerce',
                self::ADMIN_PAGE_PRO,
                [$this, 'render_pro_page']
            );
        }
    }

    public function admin_assets(string $hook): void {
        $allowed_hooks = [
            'toplevel_page_' . self::ADMIN_PAGE_MAIN,
            'wss-bookings_page_' . self::ADMIN_PAGE_TOOLS,
            'wss-bookings_page_' . self::ADMIN_PAGE_BLOCKS,
            'wss-bookings_page_' . self::ADMIN_PAGE_CALENDAR,
            'wss-bookings_page_' . self::ADMIN_PAGE_SETTINGS,
            'wss-bookings_page_' . self::ADMIN_PAGE_GUESTS,
            'wss-bookings_page_' . self::ADMIN_PAGE_PRO,
        ];
        if (!in_array($hook, $allowed_hooks, true)) {
            return;
        }
        wp_enqueue_style(
            'wss-wc-bookings-admin',
            plugins_url('assets/admin.css', __FILE__),
            [],
            self::VERSION
        );
        wp_enqueue_script(
            'wss-wc-bookings-admin',
            plugins_url('assets/admin.js', __FILE__),
            [],
            self::VERSION,
            true
        );
    }

    public function frontend_assets(): void {
        if (!is_product()) {
            return;
        }
        wp_enqueue_style(
            'wss-wc-bookings-frontend',
            plugins_url('assets/frontend.css', __FILE__),
            [],
            self::VERSION
        );
        $settings = $this->get_public_settings();
        $accent = isset($settings['accent_color']) ? (string) $settings['accent_color'] : '#1f3d35';
        $radius = isset($settings['radius']) ? max(0, min(32, (int) $settings['radius'])) : 12;
        wp_add_inline_style('wss-wc-bookings-frontend', '.wss-booking-selector{--wss-booking-accent:' . esc_attr($accent) . ';--wss-booking-radius:' . esc_attr((string) $radius) . 'px;}');
        wp_enqueue_script(
            'wss-wc-bookings-frontend',
            plugins_url('assets/frontend.js', __FILE__),
            ['jquery'],
            self::VERSION,
            true
        );
    }

    public function product_options(): void {
        if (!function_exists('woocommerce_wp_checkbox')) {
            return;
        }

        echo '<div class="options_group">';
        echo '<input type="hidden" name="wss_booking_options_present" value="1">';
        woocommerce_wp_checkbox([
            'id' => self::PRODUCT_META_ENABLED,
            'label' => 'WSS Bookings',
            'description' => 'Включить выбор даты и времени бронирования для этого товара.',
            'desc_tip' => true,
        ]);

        if ($this->is_pro_active()) {
            $ticket_lines = $this->ticket_types_to_text($this->get_ticket_types((int) get_the_ID()));
            echo '<p class="form-field wss-booking-ticket-types-field"><label for="wss_booking_ticket_types_text">Типы билетов</label>';
            echo '<textarea id="wss_booking_ticket_types_text" name="wss_booking_ticket_types_text" rows="4" style="width:50%;" placeholder="Взрослый|1000&#10;Детский|700">' . esc_textarea($ticket_lines) . '</textarea>';
            echo '<span class="description">Pro: один тип билета на строку в формате <code>Название|Цена</code>. Если поле пустое, используется обычное количество товара WooCommerce.</span></p>';
        } else {
            echo '<p class="form-field"><label>Типы билетов</label><span class="description">Типы билетов, разные цены и списки гостей доступны в WSS WooCommerce Bookings Pro.</span></p>';
        }
        echo '</div>';
    }

    public function save_product_options($product): void {
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }

        // Не сбрасываем метку у старых товаров типа booking, если WooCommerce не вывел
        // наш чекбокс в форме редактирования товара. Иначе простое сохранение товара
        // могло превращать _wss_booking_enabled обратно в no.
        if (!isset($_POST['wss_booking_options_present'])) {
            return;
        }

        $enabled = isset($_POST[self::PRODUCT_META_ENABLED]) ? 'yes' : 'no';
        $this->set_product_booking_enabled((int) $product->get_id(), $enabled === 'yes');

        if ($this->is_pro_active()) {
            $ticket_text = isset($_POST['wss_booking_ticket_types_text']) ? wp_unslash($_POST['wss_booking_ticket_types_text']) : '';
            $this->save_ticket_types((int) $product->get_id(), (string) $ticket_text);
        }
    }

    private function set_product_booking_enabled(int $product_id, bool $enabled = true): bool {
        if (!$product_id || get_post_type($product_id) !== 'product') {
            return false;
        }

        // Метку WSS нельзя автоматически включать у архивных/скрытых/неактивных товаров.
        // Иначе такие товары попадают в список расписаний и на фронт после массового импорта.
        if ($enabled && !$this->is_active_product($product_id)) {
            update_post_meta($product_id, self::PRODUCT_META_ENABLED, 'no');
            clean_post_cache($product_id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
            return false;
        }

        $value = $enabled ? 'yes' : 'no';
        update_post_meta($product_id, self::PRODUCT_META_ENABLED, $value);
        clean_post_cache($product_id);

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }

        return get_post_meta($product_id, self::PRODUCT_META_ENABLED, true) === $value;
    }

    private function enable_wss_for_products(array $product_ids): int {
        $product_ids = array_values(array_unique(array_filter(array_map('absint', $product_ids))));
        $enabled = 0;

        foreach ($product_ids as $product_id) {
            if ($this->set_product_booking_enabled($product_id, true)) {
                $enabled++;
            }
        }

        return $enabled;
    }

    private function get_products_for_flag_repair(): array {
        return $this->filter_active_product_ids(array_values(array_unique(array_merge(
            $this->get_wc_booking_product_ids_for_import(),
            $this->get_slot_product_ids()
        ))));
    }

    public static function is_booking_product($product_id): bool {
        return get_post_meta((int) $product_id, self::PRODUCT_META_ENABLED, true) === 'yes';
    }

    public function handle_admin_actions(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $action = isset($_REQUEST['wss_booking_action']) ? sanitize_key(wp_unslash($_REQUEST['wss_booking_action'])) : '';
        if (!$action) {
            return;
        }

        check_admin_referer('wss_wc_bookings_action');

        if (!$this->require_pro_for_action($action)) {
            return;
        }

        if ($action === 'export_guests_csv') {
            $this->export_guests_csv();
            return;
        }

        if ($action === 'update_settings') {
            $this->handle_update_settings();
            return;
        }

        if ($action === 'create_slot') {
            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $slot_date = isset($_POST['slot_date']) ? sanitize_text_field(wp_unslash($_POST['slot_date'])) : '';
            $start_time = isset($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : '';
            $end_time = isset($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : '';
            $capacity = isset($_POST['capacity']) ? absint($_POST['capacity']) : 0;
            $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'open';
            $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

            $result = $this->insert_slot($product_id, $slot_date, $start_time, $end_time, $capacity, $status, $note);
            $this->redirect_admin($product_id, $result ? 'slot_created' : 'slot_error');
        }

        if ($action === 'generate_slots') {
            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $selected_dates_raw = isset($_POST['selected_dates']) ? sanitize_text_field(wp_unslash($_POST['selected_dates'])) : '';
            $dates = $this->parse_dates_list($selected_dates_raw);
            $weekdays = $this->is_pro_active() && isset($_POST['generate_weekdays']) && is_array($_POST['generate_weekdays']) ? array_map('absint', wp_unslash($_POST['generate_weekdays'])) : [];
            $date_from = $this->is_pro_active() && isset($_POST['generate_date_from']) ? sanitize_text_field(wp_unslash($_POST['generate_date_from'])) : '';
            $date_to = $this->is_pro_active() && isset($_POST['generate_date_to']) ? sanitize_text_field(wp_unslash($_POST['generate_date_to'])) : '';
            $dates = array_values(array_unique(array_merge($dates, $this->dates_for_selected_weekdays($weekdays, $date_from, $date_to))));
            sort($dates);
            $times_raw = isset($_POST['times']) ? sanitize_text_field(wp_unslash($_POST['times'])) : '';
            $duration = isset($_POST['duration']) ? max(1, absint($_POST['duration'])) : 60;
            $capacity = isset($_POST['capacity']) ? absint($_POST['capacity']) : 0;
            $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'open';

            $created = $this->generate_slots($product_id, $dates, $times_raw, $duration, $capacity, $status);
            $this->redirect_admin($product_id, 'generated_' . $created);
        }

        if ($action === 'import_wc_bookings') {
            $months = isset($_POST['import_months']) ? absint($_POST['import_months']) : 24;
            $months = min(60, max(1, $months));
            $result = $this->import_wc_bookings_schedule($months);

            $this->redirect_admin((int) $result['first_product_id'], 'wc_bookings_imported', [
                'wss_import_products' => (int) $result['products'],
                'wss_import_slots' => (int) $result['slots'],
                'wss_import_booked' => (int) $result['booked'],
                'wss_import_skipped' => (int) $result['skipped_rules'],
                'wss_import_ignored_generic' => (int) $result['ignored_generic_rules'],
                'wss_inactive_cleaned' => (int) $result['inactive_cleaned'],
                'wss_import_expired' => (int) ($result['expired_products'] ?? 0),
            ], self::ADMIN_PAGE_TOOLS);
        }

        if ($action === 'repair_wss_flags') {
            $cleaned = $this->cleanup_inactive_wss_products();
            $count = $this->enable_wss_for_products($this->get_products_for_flag_repair());
            $this->redirect_admin(0, 'wss_flags_repaired', [
                'wss_flags_fixed' => (int) $count,
                'wss_deleted_slots' => (int) $cleaned,
            ], self::ADMIN_PAGE_TOOLS);
        }

        if ($action === 'cleanup_inactive_wss') {
            $deleted = $this->cleanup_inactive_wss_products();
            $this->redirect_admin(0, 'inactive_wss_cleaned', [
                'wss_deleted_slots' => (int) $deleted,
            ], self::ADMIN_PAGE_TOOLS);
        }

        if ($action === 'create_missing_all_day_slots') {
            $months = isset($_POST['all_day_months']) ? absint($_POST['all_day_months']) : 24;
            $months = min(60, max(1, $months));
            $created = $this->create_missing_all_day_slots($months);
            $this->redirect_admin(0, 'all_day_slots_created', [
                'wss_all_day_created' => (int) $created,
            ], self::ADMIN_PAGE_TOOLS);
        }

        if ($action === 'enable_product_wss') {
            $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            $this->set_product_booking_enabled($product_id, true);
            $this->redirect_admin($product_id, 'wss_product_enabled');
        }

        if ($action === 'delete_all_slots') {
            $checked = !empty($_POST['confirm_delete_all']);
            $confirm_text = isset($_POST['confirm_delete_text']) ? trim(sanitize_text_field(wp_unslash($_POST['confirm_delete_text']))) : '';

            if (!$checked || $confirm_text !== 'УДАЛИТЬ') {
                $this->redirect_admin(0, 'delete_all_confirm_error', [], self::ADMIN_PAGE_TOOLS);
            }

            $deleted = $this->delete_all_slots();
            $this->redirect_admin(0, 'all_slots_deleted', [
                'wss_deleted_slots' => (int) $deleted,
            ], self::ADMIN_PAGE_TOOLS);
        }

        if ($action === 'create_block_dates') {
            $product_id = isset($_POST['block_product_id']) ? absint($_POST['block_product_id']) : 0;
            $selected_dates_raw = isset($_POST['block_selected_dates']) ? sanitize_text_field(wp_unslash($_POST['block_selected_dates'])) : '';
            $dates = $this->parse_dates_list($selected_dates_raw);
            $note = isset($_POST['block_note']) ? sanitize_textarea_field(wp_unslash($_POST['block_note'])) : '';
            $created = $this->create_date_blocks($product_id, $dates, $note);
            $this->redirect_admin(0, 'blocks_created_' . $created, [], self::ADMIN_PAGE_BLOCKS);
        }

        if ($action === 'create_block_weekdays') {
            $product_id = isset($_POST['block_product_id']) ? absint($_POST['block_product_id']) : 0;
            $weekdays = isset($_POST['block_weekdays']) && is_array($_POST['block_weekdays']) ? array_map('absint', wp_unslash($_POST['block_weekdays'])) : [];
            $date_from = isset($_POST['block_date_from']) ? sanitize_text_field(wp_unslash($_POST['block_date_from'])) : '';
            $date_to = isset($_POST['block_date_to']) ? sanitize_text_field(wp_unslash($_POST['block_date_to'])) : '';
            $note = isset($_POST['block_note']) ? sanitize_textarea_field(wp_unslash($_POST['block_note'])) : '';
            $created = $this->create_weekday_blocks($product_id, $weekdays, $date_from, $date_to, $note);
            $this->redirect_admin(0, 'blocks_created_' . $created, [], self::ADMIN_PAGE_BLOCKS);
        }

        if ($action === 'delete_block') {
            $block_id = isset($_GET['block_id']) ? absint($_GET['block_id']) : 0;
            $this->delete_block($block_id);
            $this->redirect_admin(0, 'block_deleted', [], self::ADMIN_PAGE_BLOCKS);
        }

        if ($action === 'delete_slot') {
            $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            $slot_id = isset($_GET['slot_id']) ? absint($_GET['slot_id']) : 0;
            $this->delete_slot($slot_id);
            $this->redirect_admin($product_id, 'slot_deleted');
        }

        if ($action === 'toggle_slot_status') {
            $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            $slot_id = isset($_GET['slot_id']) ? absint($_GET['slot_id']) : 0;
            $this->toggle_slot_status($slot_id);
            $this->redirect_admin($product_id, 'slot_updated');
        }
    }

    private function redirect_admin(int $product_id, string $message, array $extra_args = [], string $page = self::ADMIN_PAGE_MAIN): void {
        $args = array_merge([
            'page' => $page,
            'wss_message' => $message,
        ], $extra_args);

        if ($product_id > 0) {
            $args['product_id'] = $product_id;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function render_admin_tabs(string $current_page): void {
        $tabs = [
            self::ADMIN_PAGE_MAIN => 'Расписание',
        ];

        if ($this->is_pro_active()) {
            $tabs += [
                self::ADMIN_PAGE_TOOLS => 'Массовые действия',
                self::ADMIN_PAGE_BLOCKS => 'Блокировки',
                self::ADMIN_PAGE_CALENDAR => 'Календарь броней',
                self::ADMIN_PAGE_GUESTS => 'Списки гостей',
                self::ADMIN_PAGE_SETTINGS => 'Настройки',
            ];
        } else {
            $tabs[self::ADMIN_PAGE_PRO] = 'Возможности Pro';
        }

        echo '<nav class="nav-tab-wrapper wss-bookings-tabs">';
        foreach ($tabs as $page => $label) {
            $url = add_query_arg(['page' => $page], admin_url('admin.php'));
            echo '<a class="nav-tab ' . esc_attr($page === $current_page ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function is_valid_date(string $date): bool {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date;
    }

    private function parse_dates_list(string $dates_raw): array {
        $dates = array_filter(array_map('trim', preg_split('/[,;\n]+/', $dates_raw)));
        $valid_dates = [];

        foreach ($dates as $date) {
            if ($this->is_valid_date($date)) {
                $valid_dates[] = $date;
            }
        }

        $valid_dates = array_values(array_unique($valid_dates));
        sort($valid_dates);

        return $valid_dates;
    }

    private function dates_for_selected_weekdays(array $weekdays, string $from_date, string $to_date): array {
        $weekdays = array_values(array_unique(array_filter(array_map('absint', $weekdays), static function ($weekday) {
            return $weekday >= 1 && $weekday <= 7;
        })));

        if (!$weekdays || !$this->is_valid_date($from_date) || !$this->is_valid_date($to_date)) {
            return [];
        }

        $start = new DateTime($from_date);
        $end = new DateTime($to_date);
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $dates = [];
        $cursor = clone $start;
        $guard = 0;
        while ($cursor <= $end && $guard < 2500) {
            if (in_array((int) $cursor->format('N'), $weekdays, true)) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor->modify('+1 day');
            $guard++;
        }

        return array_values(array_unique($dates));
    }

    private function weekday_label(int $weekday): string {
        $labels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
        return $labels[$weekday] ?? '';
    }

    private function normalize_time(string $time): string {
        $time = trim($time);
        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            [$h, $m] = array_map('absint', explode(':', $time));
            if ($h >= 0 && $h <= 23 && $m >= 0 && $m <= 59) {
                return sprintf('%02d:%02d:00', $h, $m);
            }
        }
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $time)) {
            [$h, $m, $s] = array_map('absint', explode(':', $time));
            if ($h >= 0 && $h <= 23 && $m >= 0 && $m <= 59 && $s >= 0 && $s <= 59) {
                return sprintf('%02d:%02d:%02d', $h, $m, $s);
            }
        }
        return '';
    }

    private function allowed_status(string $status): string {
        return in_array($status, ['open', 'closed'], true) ? $status : 'open';
    }

    private function insert_slot(int $product_id, string $slot_date, string $start_time, string $end_time, int $capacity, string $status = 'open', string $note = ''): bool {
        global $wpdb;

        if (!$product_id || !$this->is_valid_date($slot_date) || !$capacity) {
            return false;
        }

        $start_time = $this->normalize_time($start_time);
        $end_time = $this->normalize_time($end_time);
        if (!$start_time || !$end_time || $end_time <= $start_time) {
            return false;
        }

        if ($note !== self::ALL_DAY_NOTE) {
            $this->delete_auto_all_day_slots_for_product($product_id);
        }

        $now = current_time('mysql');
        $table = self::table_name();

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND slot_date = %s AND start_time = %s",
            $product_id,
            $slot_date,
            $start_time
        ));

        if ($exists) {
            return false;
        }

        return (bool) $wpdb->insert(
            $table,
            [
                'product_id' => $product_id,
                'slot_date' => $slot_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'capacity' => $capacity,
                'booked' => 0,
                'status' => $this->allowed_status($status),
                'note' => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );
    }

    private function generate_slots(int $product_id, array $dates, string $times_raw, int $duration, int $capacity, string $status): int {
        if (!$product_id || !$dates || !$capacity) {
            return 0;
        }

        $times = array_filter(array_map('trim', preg_split('/[,;\n]+/', $times_raw)));
        if (!$times) {
            return 0;
        }

        $created = 0;

        foreach ($dates as $date) {
            if (!$this->is_valid_date($date)) {
                continue;
            }

            foreach ($times as $time) {
                $normalized_start = $this->normalize_time($time);
                if (!$normalized_start) {
                    continue;
                }

                $start_dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $normalized_start);
                if (!$start_dt) {
                    continue;
                }

                $end_dt = clone $start_dt;
                $end_dt->modify('+' . $duration . ' minutes');

                $ok = $this->insert_slot(
                    $product_id,
                    $date,
                    $start_dt->format('H:i:s'),
                    $end_dt->format('H:i:s'),
                    $capacity,
                    $status
                );

                if ($ok) {
                    $created++;
                }
            }
        }

        return $created;
    }

    private function delete_slot(int $slot_id): void {
        global $wpdb;
        if (!$slot_id) {
            return;
        }
        $wpdb->delete(self::table_name(), ['id' => $slot_id], ['%d']);
    }

    private function toggle_slot_status(int $slot_id): void {
        global $wpdb;
        if (!$slot_id) {
            return;
        }
        $table = self::table_name();
        $slot = $this->get_slot($slot_id);
        if (!$slot) {
            return;
        }
        $new_status = $slot->status === 'open' ? 'closed' : 'open';
        $wpdb->update(
            $table,
            ['status' => $new_status, 'updated_at' => current_time('mysql')],
            ['id' => $slot_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    private function get_total_slots_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table_name());
    }

    private function delete_all_slots(): int {
        global $wpdb;
        $table = self::table_name();
        $count = $this->get_total_slots_count();
        $wpdb->query("DELETE FROM {$table}");
        delete_transient(self::IMPORT_REPORT_TRANSIENT);
        return $count;
    }

    private function insert_block(int $product_id, string $block_date, int $weekday, string $date_from, string $date_to, string $note = ''): bool {
        global $wpdb;

        $product_id = max(0, $product_id);
        $weekday = ($weekday >= 1 && $weekday <= 7) ? $weekday : 0;
        $block_date = $this->is_valid_date($block_date) ? $block_date : null;
        $date_from = $this->is_valid_date($date_from) ? $date_from : null;
        $date_to = $this->is_valid_date($date_to) ? $date_to : null;

        if (!$block_date && (!$weekday || !$date_from || !$date_to)) {
            return false;
        }

        if ($date_from && $date_to && $date_to < $date_from) {
            [$date_from, $date_to] = [$date_to, $date_from];
        }

        $table = self::blocks_table_name();
        $now = current_time('mysql');

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND ((block_date <=> %s) OR (block_date IS NULL AND %s IS NULL)) AND weekday = %d AND ((date_from <=> %s) OR (date_from IS NULL AND %s IS NULL)) AND ((date_to <=> %s) OR (date_to IS NULL AND %s IS NULL))",
            $product_id,
            $block_date,
            $block_date,
            $weekday,
            $date_from,
            $date_from,
            $date_to,
            $date_to
        ));

        if ($exists) {
            return false;
        }

        return (bool) $wpdb->insert(
            $table,
            [
                'product_id' => $product_id,
                'block_date' => $block_date,
                'weekday' => $weekday,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'note' => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private function create_date_blocks(int $product_id, array $dates, string $note = ''): int {
        $created = 0;
        foreach ($dates as $date) {
            if ($this->insert_block($product_id, $date, 0, '', '', $note)) {
                $created++;
            }
        }
        return $created;
    }

    private function create_weekday_blocks(int $product_id, array $weekdays, string $date_from, string $date_to, string $note = ''): int {
        $weekdays = array_values(array_unique(array_filter(array_map('absint', $weekdays), static function ($weekday) {
            return $weekday >= 1 && $weekday <= 7;
        })));

        if (!$weekdays || !$this->is_valid_date($date_from) || !$this->is_valid_date($date_to)) {
            return 0;
        }

        $created = 0;
        foreach ($weekdays as $weekday) {
            if ($this->insert_block($product_id, '', $weekday, $date_from, $date_to, $note)) {
                $created++;
            }
        }
        return $created;
    }

    private function delete_block(int $block_id): void {
        global $wpdb;
        if ($block_id > 0) {
            $wpdb->delete(self::blocks_table_name(), ['id' => $block_id], ['%d']);
        }
    }

    private function get_booking_blocks(int $limit = 300): array {
        global $wpdb;
        $table = self::blocks_table_name();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY COALESCE(block_date, date_from) ASC, product_id ASC, weekday ASC, id DESC LIMIT %d",
            max(1, $limit)
        ));
        return is_array($rows) ? $rows : [];
    }

    private function is_date_blocked(int $product_id, string $date): bool {
        global $wpdb;
        if (!$this->is_valid_date($date)) {
            return false;
        }

        $weekday = (int) date('N', strtotime($date . ' 00:00:00'));
        $table = self::blocks_table_name();
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE product_id IN (0, %d)
               AND (
                    block_date = %s
                    OR (weekday = %d AND date_from <= %s AND date_to >= %s)
               )",
            $product_id,
            $date,
            $weekday,
            $date,
            $date
        ));

        return $count > 0;
    }

    private function filter_unblocked_slots(array $slots, int $product_id): array {
        return array_values(array_filter($slots, function ($slot) use ($product_id) {
            $date = is_array($slot) ? (string) ($slot['date'] ?? '') : (string) ($slot->slot_date ?? '');
            return !$this->is_date_blocked($product_id, $date);
        }));
    }

    private function count_slots_for_product(int $product_id, bool $future_only = false): int {
        global $wpdb;

        if (!$product_id) {
            return 0;
        }

        $table = self::table_name();
        if ($future_only) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND slot_date >= %s",
                $product_id,
                current_time('Y-m-d')
            ));
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE product_id = %d",
            $product_id
        ));
    }



    private function delete_auto_all_day_slots_for_product(int $product_id): int {
        global $wpdb;

        if (!$product_id) {
            return 0;
        }

        $table = self::table_name();
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND note = %s",
            $product_id,
            self::ALL_DAY_NOTE
        ));

        if ($count > 0) {
            $wpdb->delete($table, [
                'product_id' => $product_id,
                'note' => self::ALL_DAY_NOTE,
            ], ['%d', '%s']);
        }

        return $count;
    }

    private function count_manual_slots_for_product(int $product_id): int {
        global $wpdb;

        if (!$product_id) {
            return 0;
        }

        $table = self::table_name();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND (note IS NULL OR note <> %s)",
            $product_id,
            self::ALL_DAY_NOTE
        ));
    }

    private function count_future_all_day_slots_for_product(int $product_id): int {
        global $wpdb;

        if (!$product_id) {
            return 0;
        }

        $table = self::table_name();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND slot_date >= %s AND note = %s",
            $product_id,
            current_time('Y-m-d'),
            self::ALL_DAY_NOTE
        ));
    }

    private function get_all_day_capacity(int $product_id): int {
        $capacity = $this->get_wc_booking_capacity($product_id, 1);
        return max(1, (int) apply_filters('wss_wc_bookings_all_day_capacity', $capacity, $product_id));
    }

    private function is_all_day_slot($slot): bool {
        if (!$slot) {
            return false;
        }

        return (string) $slot->start_time === self::ALL_DAY_START
            && in_array((string) $slot->end_time, [self::ALL_DAY_END, '23:59:59'], true)
            && (string) $slot->note === self::ALL_DAY_NOTE;
    }

    private function auto_all_day_is_disabled(int $product_id): bool {
        return get_post_meta($product_id, self::PRODUCT_META_DISABLE_AUTO_ALL_DAY, true) === 'yes';
    }

    private function set_auto_all_day_disabled(int $product_id, bool $disabled): void {
        if ($disabled) {
            update_post_meta($product_id, self::PRODUCT_META_DISABLE_AUTO_ALL_DAY, 'yes');
        } else {
            delete_post_meta($product_id, self::PRODUCT_META_DISABLE_AUTO_ALL_DAY);
        }
    }

    private function should_use_auto_all_day_slots(int $product_id): bool {
        return $this->is_active_product($product_id)
            && self::is_booking_product($product_id)
            && !$this->auto_all_day_is_disabled($product_id)
            && $this->count_manual_slots_for_product($product_id) === 0;
    }

    private function ensure_all_day_slots_for_product(int $product_id, int $months = 24): int {
        if (!$this->should_use_auto_all_day_slots($product_id)) {
            return 0;
        }

        if ($this->count_future_all_day_slots_for_product($product_id) > 30) {
            return 0;
        }

        $today = new DateTime(current_time('Y-m-d'));
        $horizon = clone $today;
        $horizon->modify('+' . max(1, $months) . ' months');
        $capacity = $this->get_all_day_capacity($product_id);
        $created = 0;
        $cursor = clone $today;
        $guard = 0;

        while ($cursor <= $horizon && $guard < 2500) {
            $ok = $this->insert_slot(
                $product_id,
                $cursor->format('Y-m-d'),
                self::ALL_DAY_START,
                self::ALL_DAY_END,
                $capacity,
                'open',
                self::ALL_DAY_NOTE
            );

            if ($ok) {
                $created++;
            }

            $cursor->modify('+1 day');
            $guard++;
        }

        return $created;
    }

    private function get_slot_product_ids(): array {
        global $wpdb;

        $table = self::table_name();
        $ids = $wpdb->get_col("SELECT DISTINCT product_id FROM {$table} WHERE product_id > 0");
        return array_values(array_filter(array_map('absint', is_array($ids) ? $ids : [])));
    }

    private function is_active_product(int $product_id): bool {
        if (get_post_type($product_id) !== 'product') {
            return false;
        }

        $status = (string) get_post_status($product_id);
        if ($status !== 'publish') {
            return false;
        }

        if ($this->is_archived_product($product_id)) {
            return false;
        }

        return (bool) apply_filters('wss_wc_bookings_is_active_product', true, $product_id);
    }

    private function is_archived_product(int $product_id): bool {
        $status = (string) get_post_status($product_id);
        $archived_statuses = [
            'archive',
            'archived',
            'wc-archived',
            'product_archive',
            'product-archive',
            'archived-product',
        ];

        if (in_array($status, $archived_statuses, true)) {
            return true;
        }

        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product && is_a($product, 'WC_Product')) {
                $visibility = method_exists($product, 'get_catalog_visibility') ? (string) $product->get_catalog_visibility() : '';
                if ($visibility === 'hidden') {
                    return true;
                }
            }
        }

        $archive_meta_keys = (array) apply_filters('wss_wc_bookings_archive_meta_keys', [
            '_product_archive',
            '_product_archived',
            '_archived',
            '_is_archived',
            '_wc_archived_product',
            '_woocommerce_product_archived',
            '_woocommerce_product_is_archived',
            'archived',
            'is_archived',
        ], $product_id);
        $archive_values = ['1', 'yes', 'true', 'on', 'archive', 'archived'];
        foreach ($archive_meta_keys as $meta_key) {
            $meta_value = get_post_meta($product_id, (string) $meta_key, true);
            if ($meta_value !== '' && in_array(strtolower((string) $meta_value), $archive_values, true)) {
                return true;
            }
        }

        $archive_term_taxonomies = (array) apply_filters('wss_wc_bookings_archive_term_taxonomies', [
            'product_visibility',
            'product_cat',
            'product_tag',
        ], $product_id);
        foreach ($archive_term_taxonomies as $taxonomy) {
            if (!taxonomy_exists((string) $taxonomy)) {
                continue;
            }
            $terms = get_the_terms($product_id, (string) $taxonomy);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $slug = strtolower((string) $term->slug);
                $name = mb_strtolower((string) $term->name, 'UTF-8');
                if (in_array($slug, ['archive', 'archived', 'arhiv', 'arhivnye', 'arhivnye-tovary'], true)) {
                    return true;
                }
                if (strpos($name, 'архив') !== false || strpos($slug, 'archive') !== false || strpos($slug, 'arhiv') !== false) {
                    return true;
                }
            }
        }

        return (bool) apply_filters('wss_wc_bookings_is_archived_product', false, $product_id);
    }

    private function filter_active_product_ids(array $product_ids): array {
        $product_ids = array_values(array_unique(array_filter(array_map('absint', $product_ids))));
        return array_values(array_filter($product_ids, function ($product_id) {
            return $this->is_active_product((int) $product_id);
        }));
    }

    private function get_inactive_wss_product_ids(): array {
        $ids = [];

        // Берём все товары с меткой WSS независимо от статуса, затем уже фильтруем через
        // is_active_product(). Так мы ловим не только draft/private/trash, но и кастомные
        // статусы архивирования, а также опубликованные, но скрытые/архивные товары.
        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => self::PRODUCT_META_ENABLED,
                    'value' => 'yes',
                    'compare' => '=',
                ],
            ],
        ]);

        if (!empty($query->posts)) {
            $ids = array_merge($ids, array_map('absint', $query->posts));
        }

        foreach ($this->get_slot_product_ids() as $product_id) {
            $ids[] = (int) $product_id;
        }

        $ids = array_values(array_unique(array_filter($ids)));
        return array_values(array_filter($ids, function ($product_id) {
            return !$this->is_active_product((int) $product_id);
        }));
    }

    private function cleanup_inactive_wss_products(): int {
        $product_ids = $this->get_inactive_wss_product_ids();
        if (!$product_ids) {
            return 0;
        }

        $deleted = $this->delete_slots_for_products($product_ids);
        foreach ($product_ids as $product_id) {
            $this->set_product_booking_enabled((int) $product_id, false);
        }

        delete_transient(self::IMPORT_REPORT_TRANSIENT);
        return $deleted;
    }

    private function product_status_label(int $product_id): string {
        $status = (string) get_post_status($product_id);
        $labels = [
            'publish' => 'Опубликован',
            'draft' => 'Черновик',
            'pending' => 'На утверждении',
            'private' => 'Приватный',
            'future' => 'Запланирован',
            'trash' => 'В корзине',
            'archive' => 'Архив',
            'archived' => 'Архив',
            'wc-archived' => 'Архив',
        ];

        $label = $labels[$status] ?? ($status ?: 'Неизвестно');
        if ($this->is_archived_product($product_id)) {
            $label .= ' / архивный или скрытый';
        }

        return $label;
    }

    private function get_enabled_booking_product_ids(): array {
        $ids = [];

        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish'],
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => self::PRODUCT_META_ENABLED,
                    'value' => 'yes',
                    'compare' => '=',
                ],
            ],
        ]);

        if (!empty($query->posts)) {
            $ids = array_merge($ids, array_map('absint', $query->posts));
        }

        $ids = array_merge($ids, $this->get_slot_product_ids());
        $ids = $this->filter_active_product_ids($ids);

        usort($ids, static function ($a, $b) {
            return strcasecmp(get_the_title($a), get_the_title($b));
        });

        return $ids;
    }


    private function get_products_missing_slots_for_all_day(): array {
        $ids = array_merge(
            $this->get_enabled_booking_product_ids(),
            $this->get_wc_booking_product_ids_for_import()
        );

        $ids = $this->filter_active_product_ids($ids);
        $ids = array_values(array_filter($ids, function ($product_id) {
            return self::is_booking_product((int) $product_id) && !$this->auto_all_day_is_disabled((int) $product_id) && $this->count_slots_for_product((int) $product_id, true) === 0;
        }));

        usort($ids, static function ($a, $b) {
            return strcasecmp(get_the_title($a), get_the_title($b));
        });

        return array_values(array_unique($ids));
    }

    private function create_missing_all_day_slots(int $months = 24): int {
        $product_ids = $this->get_products_missing_slots_for_all_day();
        if (!$product_ids) {
            return 0;
        }

        $created = 0;
        foreach ($product_ids as $product_id) {
            $this->set_product_booking_enabled((int) $product_id, true);
            $created += $this->ensure_all_day_slots_for_product((int) $product_id, $months);
        }

        return $created;
    }

    private function delete_slots_for_products(array $product_ids): int {
        global $wpdb;

        $product_ids = array_values(array_filter(array_map('absint', $product_ids)));
        if (!$product_ids) {
            return 0;
        }

        $table = self::table_name();
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE product_id IN ({$placeholders})",
            $product_ids
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE product_id IN ({$placeholders})",
            $product_ids
        ));

        return $count;
    }

    private function get_wc_booking_product_ids_for_import(): array {
        if (!$this->is_woocommerce_active()) {
            return [];
        }

        $ids = [];

        $booking_term = get_term_by('slug', 'booking', 'product_type');
        if ($booking_term && !is_wp_error($booking_term)) {
            $query = new WP_Query([
                'post_type' => 'product',
                'post_status' => ['publish'],
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
                'tax_query' => [
                    [
                        'taxonomy' => 'product_type',
                        'field' => 'slug',
                        'terms' => ['booking'],
                    ],
                ],
            ]);
            $ids = array_merge($ids, array_map('absint', $query->posts));
        }

        $meta_query = new WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish'],
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_wc_booking_availability',
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => '_wc_booking_duration',
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => '_wc_booking_qty',
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => '_wc_booking_has_persons',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);
        $ids = array_merge($ids, array_map('absint', $meta_query->posts));

        $ids = $this->filter_active_product_ids(array_values(array_unique(array_filter($ids))));
        usort($ids, static function ($a, $b) {
            return strcasecmp(get_the_title($a), get_the_title($b));
        });

        return $ids;
    }

    private function get_wc_booking_capacity(int $product_id, int $default_capacity = 20): int {
        $has_persons = get_post_meta($product_id, '_wc_booking_has_persons', true) === 'yes';
        $preferred_keys = $has_persons
            ? ['_wc_booking_max_persons_group', '_wc_booking_qty', '_wc_booking_min_persons_group']
            : ['_wc_booking_qty', '_wc_booking_max_persons_group'];

        foreach ($preferred_keys as $meta_key) {
            $value = get_post_meta($product_id, $meta_key, true);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return (int) apply_filters('wss_wc_bookings_import_default_capacity', $default_capacity, $product_id);
    }

    private function get_wc_booking_duration_minutes(int $product_id): int {
        $duration = get_post_meta($product_id, '_wc_booking_duration', true);
        if (!is_numeric($duration) || (int) $duration <= 0) {
            $duration = get_post_meta($product_id, '_wc_booking_min_duration', true);
        }

        $duration = is_numeric($duration) && (int) $duration > 0 ? (int) $duration : 60;
        $unit = (string) get_post_meta($product_id, '_wc_booking_duration_unit', true);

        switch ($unit) {
            case 'hour':
            case 'hours':
                return $duration * 60;
            case 'day':
            case 'days':
                return $duration * 24 * 60;
            case 'month':
            case 'months':
                return $duration * 30 * 24 * 60;
            case 'minute':
            case 'minutes':
            default:
                return $duration;
        }
    }

    private function import_wc_bookings_schedule(int $months): array {
        $product_ids = $this->get_wc_booking_product_ids_for_import();
        $result = [
            'products' => 0,
            'slots' => 0,
            'booked' => 0,
            'skipped_rules' => 0,
            'ignored_generic_rules' => 0,
            'inactive_cleaned' => 0,
            'expired_products' => 0,
            'first_product_id' => 0,
            'report' => [],
        ];

        $result['inactive_cleaned'] = $this->cleanup_inactive_wss_products();

        if (!$product_ids) {
            delete_transient(self::IMPORT_REPORT_TRANSIENT);
            return $result;
        }

        $this->delete_slots_for_products($product_ids);

        foreach ($product_ids as $product_id) {
            $this->set_product_booking_enabled($product_id, true);

            $availability = get_post_meta($product_id, '_wc_booking_availability', true);
            if (!is_array($availability)) {
                $availability = [];
            }

            $duration = $this->get_wc_booking_duration_minutes($product_id);
            $capacity = $this->get_wc_booking_capacity($product_id);
            $parsed = $this->parse_wc_booking_availability($availability, $duration, $capacity, $months);
            $slots = $this->remove_blocked_import_slots($parsed['slots'], $parsed['blocked']);
            $today = current_time('Y-m-d');
            $expired_slots = 0;
            $future_slots_to_import = [];

            foreach ($slots as $slot) {
                if (!empty($slot['date']) && $slot['date'] < $today) {
                    $expired_slots++;
                    continue;
                }
                $future_slots_to_import[] = $slot;
            }

            $has_dated_rules = !empty($parsed['has_dated_rules']);
            $schedule_expired = $has_dated_rules && empty($future_slots_to_import);
            $this->set_auto_all_day_disabled($product_id, $schedule_expired);

            $product_created = 0;
            $result['skipped_rules'] += (int) $parsed['skipped'];
            $result['ignored_generic_rules'] += (int) ($parsed['ignored_generic'] ?? 0);
            if ($schedule_expired) {
                $result['expired_products']++;
            }

            foreach ($future_slots_to_import as $slot) {
                $is_all_day = !empty($slot['all_day']) || ($slot['start'] === self::ALL_DAY_START && $slot['end'] === self::ALL_DAY_END);
                $created = $this->insert_slot(
                    $product_id,
                    $slot['date'],
                    $slot['start'],
                    $slot['end'],
                    $capacity,
                    'open',
                    $is_all_day ? self::ALL_DAY_NOTE : 'Импортировано из WooCommerce Bookings'
                );

                if ($created) {
                    $product_created++;
                    $result['slots']++;
                }
            }

            // Если у опубликованного booking-товара нет расписания по датам/времени,
            // считаем его услугой с открытой датой/на весь день. Но если в старом
            // WooCommerce Bookings расписание было, просто все даты уже прошли, дневные
            // слоты создавать нельзя — у такой экскурсии нет актуальных дат.
            if ($product_created === 0 && !$schedule_expired && $this->is_active_product($product_id)) {
                $product_created = $this->ensure_all_day_slots_for_product($product_id, $months);
                $result['slots'] += $product_created;
            }

            if (!$result['first_product_id'] && $product_created > 0) {
                $result['first_product_id'] = $product_id;
            }

            $future_slots = $this->count_slots_for_product($product_id, true);
            $total_slots = $this->count_slots_for_product($product_id, false);
            $result['report'][] = [
                'product_id' => $product_id,
                'title' => get_the_title($product_id),
                'post_status' => (string) get_post_status($product_id),
                'post_status_label' => $this->product_status_label($product_id),
                'rules' => count($availability),
                'created' => $product_created,
                'total_slots' => $total_slots,
                'future_slots' => $future_slots,
                'all_day' => $this->count_manual_slots_for_product($product_id) === 0 ? 1 : 0,
                'capacity' => $capacity,
                'duration' => $duration,
                'skipped' => (int) $parsed['skipped'],
                'ignored_generic' => (int) ($parsed['ignored_generic'] ?? 0),
                'expired_slots' => (int) $expired_slots,
                'schedule_expired' => $schedule_expired ? 1 : 0,
                'has_dated_rules' => !empty($parsed['has_dated_rules']) ? 1 : 0,
                'enabled' => self::is_booking_product($product_id) ? 1 : 0,
                'meta_value' => (string) get_post_meta($product_id, self::PRODUCT_META_ENABLED, true),
            ];

            $result['products']++;
        }

        $this->enable_wss_for_products($product_ids);
        $result['booked'] = $this->sync_imported_wc_booking_counts($product_ids);

        set_transient(self::IMPORT_REPORT_TRANSIENT, $result['report'], HOUR_IN_SECONDS);

        return $result;
    }

    private function parse_wc_booking_availability(array $availability, int $duration, int $capacity, int $months): array {
        $slots = [];
        $blocked = [];
        $skipped = 0;
        $ignored_generic = 0;
        $has_dated_rules = $this->availability_has_explicit_dated_rules($availability);

        foreach ($availability as $rule) {
            if (!is_array($rule)) {
                $skipped++;
                continue;
            }

            $rule_has_dates = $this->wc_booking_rule_has_explicit_dates($rule);
            $is_generic_time = $this->wc_booking_rule_is_generic_time($rule);

            // В WooCommerce Bookings часто есть общие правила времени вида "каждый день с 13:30".
            // Если в этом же товаре уже заведены конкретные даты экскурсии, такие общие правила
            // нельзя переносить на каждый день горизонта — иначе одна экскурсия получает сотни лишних дат.
            if ($has_dated_rules && $is_generic_time && !$rule_has_dates) {
                $ignored_generic++;
                continue;
            }

            $dates = $this->get_dates_from_wc_booking_rule($rule, $months, !$has_dated_rules || $rule_has_dates);
            $time_ranges = $this->get_time_ranges_from_wc_booking_rule($rule, $duration);
            $bookable = $this->wc_booking_rule_is_bookable($rule);

            if (!$dates) {
                $skipped++;
                continue;
            }

            if (!$time_ranges) {
                if (!$bookable) {
                    foreach ($dates as $date) {
                        $blocked[] = [
                            'date' => $date,
                            'start' => '',
                            'end' => '',
                        ];
                    }
                } else {
                    foreach ($dates as $date) {
                        $slots[] = [
                            'date' => $date,
                            'start' => self::ALL_DAY_START,
                            'end' => self::ALL_DAY_END,
                            'capacity' => $capacity,
                            'all_day' => true,
                        ];
                    }
                }
                continue;
            }

            foreach ($dates as $date) {
                foreach ($time_ranges as $range) {
                    if ($bookable) {
                        $slots[] = [
                            'date' => $date,
                            'start' => $range['start'],
                            'end' => $range['end'],
                            'capacity' => $capacity,
                            'all_day' => false,
                        ];
                    } else {
                        $blocked[] = [
                            'date' => $date,
                            'start' => $range['start'],
                            'end' => $range['end'],
                        ];
                    }
                }
            }
        }

        $unique_slots = [];
        foreach ($slots as $slot) {
            $key = $slot['date'] . '|' . $slot['start'] . '|' . $slot['end'];
            $unique_slots[$key] = $slot;
        }

        return [
            'slots' => array_values($unique_slots),
            'blocked' => $blocked,
            'skipped' => $skipped,
            'ignored_generic' => $ignored_generic,
            'has_dated_rules' => $has_dated_rules,
        ];
    }

    private function availability_has_explicit_dated_rules(array $availability): bool {
        foreach ($availability as $rule) {
            if (is_array($rule) && $this->wc_booking_rule_has_explicit_dates($rule)) {
                return true;
            }
        }

        return false;
    }

    private function wc_booking_rule_has_explicit_dates(array $rule): bool {
        return $this->extract_date_from_rule($rule, [
            'from_date',
            'start_date',
            'date_from',
            'from',
            'to_date',
            'end_date',
            'date_to',
            'to',
        ]) !== '';
    }

    private function wc_booking_rule_is_generic_time(array $rule): bool {
        $type = isset($rule['type']) ? (string) $rule['type'] : '';

        if ($type === 'time' || preg_match('/^time:\d$/', $type)) {
            return true;
        }

        if (!$this->wc_booking_rule_has_explicit_dates($rule)) {
            return $this->extract_time_from_rule($rule, [
                'from_time',
                'start_time',
                'time_from',
                'from_range',
                'from',
            ]) !== '';
        }

        return false;
    }

    private function wc_booking_rule_is_bookable(array $rule): bool {
        $bookable = isset($rule['bookable']) ? strtolower((string) $rule['bookable']) : 'yes';
        return in_array($bookable, ['yes', '1', 'true', 'bookable'], true);
    }

    private function get_dates_from_wc_booking_rule(array $rule, int $months, bool $allow_generic_repeating = true): array {
        $type = isset($rule['type']) ? (string) $rule['type'] : '';
        $today = new DateTime(current_time('Y-m-d'));
        $horizon = clone $today;
        $horizon->modify('+' . max(1, $months) . ' months');

        if ($allow_generic_repeating && preg_match('/^time:(\d)$/', $type, $matches)) {
            return $this->dates_for_weekday((int) $matches[1], $today, $horizon);
        }

        $from_date = $this->extract_date_from_rule($rule, ['from_date', 'start_date', 'date_from', 'from']);
        $to_date = $this->extract_date_from_rule($rule, ['to_date', 'end_date', 'date_to', 'to']);

        if ($from_date && !$to_date) {
            $to_date = $from_date;
        }
        if (!$from_date && $to_date) {
            $from_date = $to_date;
        }

        if ($from_date && $to_date) {
            return $this->enumerate_dates($from_date, $to_date);
        }

        if ($allow_generic_repeating && $type === 'time') {
            return $this->enumerate_dates($today->format('Y-m-d'), $horizon->format('Y-m-d'));
        }

        return [];
    }

    private function extract_date_from_rule(array $rule, array $keys): string {
        foreach ($keys as $key) {
            if (!isset($rule[$key])) {
                continue;
            }

            $value = trim((string) $rule[$key]);
            if ($value === '') {
                continue;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && $this->is_valid_date($value)) {
                return $value;
            }

            if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $value)) {
                $date = str_replace('/', '-', $value);
                if ($this->is_valid_date($date)) {
                    return $date;
                }
            }

            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
                [$day, $month, $year] = explode('.', $value);
                $date = $year . '-' . $month . '-' . $day;
                if ($this->is_valid_date($date)) {
                    return $date;
                }
            }

            if (preg_match('/^\d{8}$/', $value)) {
                $date = substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
                if ($this->is_valid_date($date)) {
                    return $date;
                }
            }
        }

        return '';
    }

    private function enumerate_dates(string $from_date, string $to_date): array {
        if (!$this->is_valid_date($from_date) || !$this->is_valid_date($to_date)) {
            return [];
        }

        $start = new DateTime($from_date);
        $end = new DateTime($to_date);
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $dates = [];
        $guard = 0;
        while ($start <= $end && $guard < 2500) {
            $dates[] = $start->format('Y-m-d');
            $start->modify('+1 day');
            $guard++;
        }

        return $dates;
    }

    private function dates_for_weekday(int $weekday, DateTime $from, DateTime $to): array {
        if ($weekday < 1 || $weekday > 7) {
            return [];
        }

        $date = clone $from;
        while ((int) $date->format('N') !== $weekday) {
            $date->modify('+1 day');
        }

        $dates = [];
        while ($date <= $to) {
            $dates[] = $date->format('Y-m-d');
            $date->modify('+1 week');
        }

        return $dates;
    }

    private function get_time_ranges_from_wc_booking_rule(array $rule, int $duration): array {
        $start = $this->extract_time_from_rule($rule, ['from_time', 'start_time', 'time_from', 'from_range', 'from']);
        $end = $this->extract_time_from_rule($rule, ['to_time', 'end_time', 'time_to', 'to_range', 'to']);

        if (!$start) {
            return [];
        }

        if (!$end) {
            $end = $this->add_minutes_to_time($start, $duration);
        }

        if (!$end || $end <= $start) {
            return [];
        }

        return $this->split_time_range_into_slots($start, $end, $duration);
    }

    private function extract_time_from_rule(array $rule, array $keys): string {
        foreach ($keys as $key) {
            if (!isset($rule[$key])) {
                continue;
            }

            $time = $this->normalize_time((string) $rule[$key]);
            if ($time) {
                return $time;
            }
        }

        return '';
    }

    private function add_minutes_to_time(string $time, int $minutes): string {
        $dt = DateTime::createFromFormat('H:i:s', $time);
        if (!$dt) {
            return '';
        }

        $dt->modify('+' . max(1, $minutes) . ' minutes');
        return $dt->format('H:i:s');
    }

    private function split_time_range_into_slots(string $start, string $end, int $duration): array {
        $duration = max(1, $duration);
        $start_dt = DateTime::createFromFormat('H:i:s', $start);
        $end_dt = DateTime::createFromFormat('H:i:s', $end);
        if (!$start_dt || !$end_dt || $end_dt <= $start_dt) {
            return [];
        }

        $ranges = [];
        $cursor = clone $start_dt;
        $guard = 0;

        while ($cursor < $end_dt && $guard < 96) {
            $slot_end = clone $cursor;
            $slot_end->modify('+' . $duration . ' minutes');

            if ($slot_end > $end_dt) {
                break;
            }

            $ranges[] = [
                'start' => $cursor->format('H:i:s'),
                'end' => $slot_end->format('H:i:s'),
            ];

            $cursor = $slot_end;
            $guard++;
        }

        if (!$ranges) {
            $ranges[] = [
                'start' => $start_dt->format('H:i:s'),
                'end' => $end_dt->format('H:i:s'),
            ];
        }

        return $ranges;
    }

    private function remove_blocked_import_slots(array $slots, array $blocked): array {
        if (!$blocked) {
            return $slots;
        }

        return array_values(array_filter($slots, function ($slot) use ($blocked) {
            foreach ($blocked as $block) {
                if ($slot['date'] !== $block['date']) {
                    continue;
                }

                if (empty($block['start']) || empty($block['end'])) {
                    return false;
                }

                if ($slot['start'] < $block['end'] && $slot['end'] > $block['start']) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function sync_imported_wc_booking_counts(array $product_ids): int {
        global $wpdb;

        $product_ids = array_values(array_filter(array_map('absint', $product_ids)));
        if (!$product_ids) {
            return 0;
        }

        $bookings = get_posts([
            'post_type' => 'wc_booking',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => '_booking_product_id',
                    'value' => $product_ids,
                    'compare' => 'IN',
                    'type' => 'NUMERIC',
                ],
            ],
        ]);

        if (!$bookings) {
            return 0;
        }

        $inactive_statuses = ['cancelled', 'trash', 'draft', 'auto-draft', 'was-in-cart', 'in-cart'];
        $reserved = 0;
        $table = self::table_name();

        foreach ($bookings as $booking_id) {
            $status = str_replace('wc-', '', (string) get_post_status($booking_id));
            if (in_array($status, $inactive_statuses, true)) {
                continue;
            }

            $product_id = absint(get_post_meta($booking_id, '_booking_product_id', true));
            if (!$product_id || !in_array($product_id, $product_ids, true)) {
                continue;
            }

            $start = $this->parse_wc_booking_datetime(get_post_meta($booking_id, '_booking_start', true));
            if (!$start) {
                continue;
            }

            $date = $start->format('Y-m-d');
            $time = $start->format('H:i:s');
            $slot_id = $this->find_slot_id_for_imported_booking($product_id, $date, $time);
            if (!$slot_id) {
                continue;
            }

            $qty = $this->get_wc_booking_reserved_quantity($booking_id);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET booked = booked + %d, updated_at = %s WHERE id = %d",
                $qty,
                current_time('mysql'),
                $slot_id
            ));
            $reserved += $qty;
        }

        return $reserved;
    }

    private function parse_wc_booking_datetime($value): ?DateTime {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string() ?: 'UTC');

        if (preg_match('/^\d{14}$/', $value)) {
            $dt = DateTime::createFromFormat('YmdHis', $value, $timezone);
            return $dt ?: null;
        }

        if (preg_match('/^\d{12}$/', $value)) {
            $dt = DateTime::createFromFormat('YmdHi', $value, $timezone);
            return $dt ?: null;
        }

        if (preg_match('/^\d{10}$/', $value)) {
            $dt = new DateTime('@' . $value);
            $dt->setTimezone($timezone);
            return $dt;
        }

        try {
            return new DateTime($value, $timezone);
        } catch (Exception $e) {
            return null;
        }
    }

    private function find_slot_id_for_imported_booking(int $product_id, string $date, string $time): int {
        global $wpdb;
        $table = self::table_name();

        $slot_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE product_id = %d AND slot_date = %s AND start_time = %s LIMIT 1",
            $product_id,
            $date,
            $time
        ));

        if ($slot_id) {
            return $slot_id;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE product_id = %d AND slot_date = %s AND start_time <= %s AND end_time > %s ORDER BY start_time DESC LIMIT 1",
            $product_id,
            $date,
            $time,
            $time
        ));
    }

    private function get_wc_booking_reserved_quantity(int $booking_id): int {
        $persons = get_post_meta($booking_id, '_booking_persons', true);
        if (is_array($persons)) {
            $sum = 0;
            foreach ($persons as $value) {
                if (is_numeric($value)) {
                    $sum += (int) $value;
                }
            }
            if ($sum > 0) {
                return $sum;
            }
        }

        $qty = get_post_meta($booking_id, '_booking_qty', true);
        if (is_numeric($qty) && (int) $qty > 0) {
            return (int) $qty;
        }

        return 1;
    }

    private function get_products_for_select(): array {
        if (!$this->is_woocommerce_active()) {
            return [];
        }

        // Не полагаемся только на wc_get_products: старые товары типа booking
        // после отключения WooCommerce Bookings могут не попадать в выборку.
        // Поэтому сначала собираем ID через WP_Query/meta и ID товаров из таблицы слотов,
        // а уже затем аккуратно получаем WC_Product-объекты.
        $products = [];
        foreach ($this->get_enabled_booking_product_ids() as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && is_a($product, 'WC_Product')) {
                $products[] = $product;
            }
        }

        return $products;
    }


    private function get_default_settings(): array {
        return [
            'title_time' => 'Выберите дату и время',
            'title_date' => 'Выберите дату',
            'no_slots' => 'Сейчас нет доступных дат для бронирования.',
            'hint_time' => 'Сначала выберите дату в календаре.',
            'hint_date' => 'Выберите дату в календаре. Время для этой услуги не указывается.',
            'button_text' => 'Забронировать',
            'accent_color' => '#1f3d35',
            'radius' => 12,
        ];
    }

    private function get_public_settings(): array {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge($this->get_default_settings(), $stored);
    }

    private function get_public_text(string $key): string {
        $settings = $this->get_public_settings();
        return isset($settings[$key]) && $settings[$key] !== '' ? (string) $settings[$key] : (string) $this->get_default_settings()[$key];
    }

    private function handle_update_settings(): void {
        $defaults = $this->get_default_settings();
        $settings = [];
        foreach (['title_time','title_date','no_slots','hint_time','hint_date','button_text'] as $key) {
            $settings[$key] = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $defaults[$key];
        }
        $accent = isset($_POST['accent_color']) ? sanitize_hex_color(wp_unslash($_POST['accent_color'])) : $defaults['accent_color'];
        $settings['accent_color'] = $accent ?: $defaults['accent_color'];
        $settings['radius'] = isset($_POST['radius']) ? max(0, min(32, absint($_POST['radius']))) : (int) $defaults['radius'];
        update_option(self::OPTION_SETTINGS, $settings, false);
        $this->redirect_admin(0, 'settings_saved', [], self::ADMIN_PAGE_SETTINGS);
    }

    private function ticket_types_to_text(array $types): string {
        $lines = [];
        foreach ($types as $type) {
            $lines[] = $type['name'] . '|' . $type['price'];
        }
        return implode("\n", $lines);
    }

    private function parse_ticket_types_text(string $text): array {
        $rows = preg_split('/\r\n|\r|\n/', $text);
        $types = [];
        $index = 1;
        foreach ($rows as $row) {
            $row = trim((string) $row);
            if ($row === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $row, 2));
            $name = sanitize_text_field($parts[0] ?? '');
            if ($name === '') {
                continue;
            }
            $price_raw = str_replace(',', '.', (string) ($parts[1] ?? '0'));
            $price = is_numeric($price_raw) ? max(0, (float) $price_raw) : 0;
            $key = sanitize_key(sanitize_title($name));
            if ($key === '') {
                $key = 'ticket_' . $index;
            }
            $types[] = [
                'key' => $key . '_' . $index,
                'name' => $name,
                'price' => $price,
            ];
            $index++;
        }
        return $types;
    }

    private function save_ticket_types(int $product_id, string $text): void {
        $types = $this->parse_ticket_types_text($text);
        if (!$types) {
            delete_post_meta($product_id, self::PRODUCT_META_TICKET_TYPES);
            return;
        }
        update_post_meta($product_id, self::PRODUCT_META_TICKET_TYPES, $types);
    }

    private function get_ticket_types(int $product_id): array {
        if (!$this->is_pro_active()) {
            return [];
        }
        $types = get_post_meta($product_id, self::PRODUCT_META_TICKET_TYPES, true);
        return is_array($types) ? $types : [];
    }

    private function get_posted_ticket_selection(int $product_id): array {
        $types = $this->get_ticket_types($product_id);
        if (!$types || empty($_POST['wss_booking_tickets']) || !is_array($_POST['wss_booking_tickets'])) {
            return ['enabled' => false, 'items' => [], 'qty' => 0, 'total' => 0.0, 'label' => ''];
        }

        $posted = wp_unslash($_POST['wss_booking_tickets']);
        $items = [];
        $qty = 0;
        $total = 0.0;
        foreach ($types as $type) {
            $count = isset($posted[$type['key']]) ? absint($posted[$type['key']]) : 0;
            if ($count <= 0) {
                continue;
            }
            $line_total = $count * (float) $type['price'];
            $items[] = [
                'key' => $type['key'],
                'name' => $type['name'],
                'price' => (float) $type['price'],
                'qty' => $count,
                'total' => $line_total,
            ];
            $qty += $count;
            $total += $line_total;
        }

        $labels = [];
        foreach ($items as $item) {
            $labels[] = $item['name'] . ' × ' . $item['qty'];
        }

        return [
            'enabled' => true,
            'items' => $items,
            'qty' => $qty,
            'total' => $total,
            'label' => implode(', ', $labels),
        ];
    }

    public function maybe_adjust_add_to_cart_quantity($quantity, $product_id) {
        if (!self::is_booking_product((int) $product_id)) {
            return $quantity;
        }
        $tickets = $this->get_posted_ticket_selection((int) $product_id);
        if (!empty($tickets['enabled']) && (int) $tickets['qty'] > 0) {
            return (int) $tickets['qty'];
        }
        return $quantity;
    }

    public function apply_ticket_type_cart_prices($cart): void {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart || !method_exists($cart, 'get_cart')) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['wss_booking_tickets_total']) || empty($cart_item['data']) || !is_object($cart_item['data'])) {
                continue;
            }
            $qty = !empty($cart_item['quantity']) ? max(1, (int) $cart_item['quantity']) : 1;
            $unit_price = (float) $cart_item['wss_booking_tickets_total'] / $qty;
            $cart_item['data']->set_price($unit_price);
        }
    }

    private function render_ticket_type_controls(int $product_id): void {
        $types = $this->get_ticket_types($product_id);
        if (!$types) {
            return;
        }
        echo '<div class="wss-booking-ticket-types" data-wss-ticket-types>';
        echo '<h4>Билеты</h4>';
        foreach ($types as $type) {
            echo '<div class="wss-booking-ticket-row" data-wss-ticket-row data-price="' . esc_attr((string) $type['price']) . '">';
            echo '<div class="wss-booking-ticket-info"><strong>' . esc_html($type['name']) . '</strong><span>' . wp_kses_post(wc_price((float) $type['price'])) . '</span></div>';
            echo '<div class="wss-booking-ticket-qty"><button type="button" data-wss-ticket-minus>−</button><input type="number" min="0" step="1" value="0" name="wss_booking_tickets[' . esc_attr($type['key']) . ']" data-wss-ticket-input><button type="button" data-wss-ticket-plus>+</button></div>';
            echo '</div>';
        }
        echo '<input type="hidden" name="wss_booking_ticket_total_qty" data-wss-ticket-total-qty value="0">';
        echo '<p class="wss-booking-ticket-summary" data-wss-ticket-summary>Выберите количество билетов.</p>';
        echo '</div>';
    }

    private function get_guest_rows(string $date = '', int $product_id = 0): array {
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        $orders = wc_get_orders([
            'limit' => 300,
            'status' => ['pending', 'processing', 'completed', 'on-hold'],
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ]);
        $rows = [];
        foreach ($orders as $order) {
            if (!$order || !is_a($order, 'WC_Order')) {
                continue;
            }
            foreach ($order->get_items() as $item) {
                $slot_id = (int) $item->get_meta('_wss_booking_slot_id', true);
                if (!$slot_id) {
                    continue;
                }
                $slot = $this->get_slot($slot_id);
                if (!$slot) {
                    continue;
                }
                if ($product_id > 0 && (int) $slot->product_id !== $product_id) {
                    continue;
                }
                if ($date !== '' && (string) $slot->slot_date !== $date) {
                    continue;
                }
                $rows[] = [
                    'order_id' => $order->get_id(),
                    'status' => wc_get_order_status_name($order->get_status()),
                    'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'phone' => $order->get_billing_phone(),
                    'email' => $order->get_billing_email(),
                    'product' => get_the_title((int) $slot->product_id),
                    'product_id' => (int) $slot->product_id,
                    'date' => (string) $slot->slot_date,
                    'time' => $this->is_all_day_slot($slot) ? 'Весь день' : $this->format_time($slot->start_time) . '–' . $this->format_time($slot->end_time),
                    'qty' => (int) $item->get_quantity(),
                    'tickets' => (string) $item->get_meta('Билеты', true),
                ];
            }
        }
        return $rows;
    }

    private function export_guests_csv(): void {
        $date = isset($_GET['wss_date']) ? sanitize_text_field(wp_unslash($_GET['wss_date'])) : '';
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        $rows = $this->get_guest_rows($date, $product_id);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wss-booking-guests-' . ($date ?: current_time('Y-m-d')) . '.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Заказ', 'Статус', 'Имя', 'Телефон', 'Email', 'Экскурсия', 'Дата', 'Время', 'Кол-во', 'Билеты'], ';');
        foreach ($rows as $row) {
            fputcsv($out, [$row['order_id'], $row['status'], $row['name'], $row['phone'], $row['email'], $row['product'], $this->format_date($row['date']), $row['time'], $row['qty'], $row['tickets']], ';');
        }
        exit;
    }


    public function render_pro_page(): void {
        echo '<div class="wrap wss-bookings-admin">';
        echo '<h1>WSS WooCommerce Bookings Pro</h1>';
        $this->render_admin_tabs(self::ADMIN_PAGE_PRO);
        echo '<div class="wss-bookings-card" style="max-width:900px">';
        echo '<h2>Что открывает Pro</h2>';
        echo '<p>Бесплатная версия содержит базовое расписание, календарь на сайте, кнопки времени, вместимость и запись даты/времени в заказ.</p>';
        echo '<ul class="ul-disc"><li>Импорт из стандартного WooCommerce Bookings</li><li>Массовые действия и безопасная очистка расписаний</li><li>Блокировки дат и дней недели</li><li>Календарь броней в админке</li><li>Режим “весь день, без времени”</li><li>Типы билетов и разные цены</li><li>Настройки текстов и внешнего вида</li><li>Списки гостей и экспорт CSV</li></ul>';
        echo '<p><strong>Установите и активируйте WSS WooCommerce Bookings Pro</strong>, чтобы разблокировать эти разделы.</p>';
        echo '</div></div>';
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        $settings = $this->get_public_settings();
        echo '<div class="wrap wss-bookings-admin">';
        echo '<h1>WSS WooCommerce Bookings</h1>';
        $this->render_admin_tabs(self::ADMIN_PAGE_SETTINGS);
        echo '<form method="post" class="wss-bookings-card" style="max-width:900px">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="update_settings">';
        echo '<h2>Тексты и внешний вид</h2>';
        foreach ([
            'title_time' => 'Заголовок для расписания со временем',
            'title_date' => 'Заголовок для услуг без времени',
            'no_slots' => 'Текст, если дат нет',
            'hint_time' => 'Подсказка до выбора даты',
            'hint_date' => 'Подсказка для услуг без времени',
            'button_text' => 'Текст кнопки бронирования',
        ] as $key => $label) {
            echo '<p><label>' . esc_html($label) . '<br><input type="text" name="' . esc_attr($key) . '" value="' . esc_attr((string) $settings[$key]) . '" class="large-text"></label></p>';
        }
        echo '<div class="wss-bookings-two-cols">';
        echo '<p><label>Акцентный цвет<br><input type="text" name="accent_color" value="' . esc_attr((string) $settings['accent_color']) . '" placeholder="#1f3d35"></label></p>';
        echo '<p><label>Скругление, px<br><input type="number" name="radius" min="0" max="32" value="' . esc_attr((string) $settings['radius']) . '"></label></p>';
        echo '</div>';
        submit_button('Сохранить настройки', 'primary', '', false);
        echo '</form></div>';
    }

    public function render_guests_page(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        $products = $this->get_products_for_select();
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        $date = isset($_GET['wss_date']) ? sanitize_text_field(wp_unslash($_GET['wss_date'])) : current_time('Y-m-d');
        if (!$this->is_valid_date($date)) { $date = current_time('Y-m-d'); }
        $rows = $this->get_guest_rows($date, $product_id);
        echo '<div class="wrap wss-bookings-admin">';
        echo '<h1>WSS WooCommerce Bookings</h1>';
        $this->render_admin_tabs(self::ADMIN_PAGE_GUESTS);
        echo '<p class="description">Список гостей строится по заказам WooCommerce, в которых есть WSS-слот бронирования.</p>';
        echo '<form method="get" class="wss-bookings-product-select">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::ADMIN_PAGE_GUESTS) . '">';
        echo '<label><strong>Дата</strong> <input type="date" name="wss_date" value="' . esc_attr($date) . '"></label> ';
        echo '<label><strong>Экскурсия</strong> <select name="product_id"><option value="0">Все экскурсии</option>';
        foreach ($products as $product) {
            printf('<option value="%d" %s>%s</option>', (int) $product->get_id(), selected($product_id, (int) $product->get_id(), false), esc_html($product->get_name()));
        }
        echo '</select></label> ';
        submit_button('Показать', 'secondary', '', false);
        $export_url = wp_nonce_url(add_query_arg(['page' => self::ADMIN_PAGE_GUESTS, 'wss_booking_action' => 'export_guests_csv', 'wss_date' => $date, 'product_id' => $product_id], admin_url('admin.php')), 'wss_wc_bookings_action');
        echo ' <a class="button" href="' . esc_url($export_url) . '">Экспорт CSV</a>';
        echo '</form>';
        if (!$rows) { echo '<p>На выбранную дату бронирований не найдено.</p></div>'; return; }
        echo '<table class="widefat striped wss-bookings-summary-table"><thead><tr><th>Заказ</th><th>Статус</th><th>Гость</th><th>Контакты</th><th>Экскурсия</th><th>Время</th><th>Кол-во</th><th>Билеты</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $order_link = admin_url('post.php?post=' . (int) $row['order_id'] . '&action=edit');
            echo '<tr><td><a href="' . esc_url($order_link) . '">#' . esc_html((string) $row['order_id']) . '</a></td><td>' . esc_html($row['status']) . '</td><td>' . esc_html($row['name']) . '</td><td>' . esc_html($row['phone']) . '<br>' . esc_html($row['email']) . '</td><td>' . esc_html($row['product']) . '</td><td>' . esc_html($row['time']) . '</td><td>' . esc_html((string) $row['qty']) . '</td><td>' . esc_html($row['tickets']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $products = $this->get_products_for_select();
        $selected_product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        $available_product_ids = array_map(static function ($product) {
            return (int) $product->get_id();
        }, $products);

        if ($selected_product_id && !in_array($selected_product_id, $available_product_ids, true)) {
            $selected_product_id = 0;
        }

        if (!$selected_product_id && $products) {
            $selected_product_id = (int) $products[0]->get_id();
        }

        $message = isset($_GET['wss_message']) ? sanitize_text_field(wp_unslash($_GET['wss_message'])) : '';
        $slots = $selected_product_id ? $this->get_admin_slots($selected_product_id) : [];

        echo '<div class="wrap wss-bookings-admin">';
        echo '<h1>WSS WooCommerce Bookings</h1>';
        $this->render_admin_tabs(self::ADMIN_PAGE_MAIN);
        echo '<p class="description">Расписание слотов, календарь дат в админке и на сайте, кнопки времени, вместимость и запись бронирования в заказ WooCommerce.' . ($this->is_pro_active() ? ' Pro активен: доступны массовые действия, блокировки, календарь броней, билеты и экспорт гостей.' : ' Расширенные функции доступны в Pro.') . '</p>';

        if ($message) {
            $notice_class = in_array($message, ['delete_all_confirm_error', 'slot_error'], true) ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . esc_html($this->admin_message_text($message)) . '</p></div>';
        }

        if (!$this->is_woocommerce_active()) {
            echo '<div class="notice notice-warning"><p>WooCommerce не активен. Управление расписанием доступно после включения WooCommerce.</p></div>';
            echo '</div>';
            return;
        }


        if (!$products) {
            echo '<div class="notice notice-info"><p>Пока нет товаров, у которых включен <strong>WSS Bookings</strong>. Включите чекбокс вручную в нужной экскурсии' . ($this->is_pro_active() ? ' или воспользуйтесь импортом из стандартного WooCommerce Bookings в разделе “Массовые действия”' : '') . '.</p></div>';
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=product')) . '">Открыть товары WooCommerce</a></p>';
            echo '</div>';
            return;
        }

        echo '<form method="get" class="wss-bookings-product-select">';
        echo '<input type="hidden" name="page" value="wss-wc-bookings">';
        echo '<label for="wss-product-id"><strong>Товар / экскурсия</strong></label> ';
        echo '<select id="wss-product-id" name="product_id">';
        foreach ($products as $product) {
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $product->get_id(),
                selected($selected_product_id, (int) $product->get_id(), false),
                esc_html($product->get_name())
            );
        }
        echo '</select> ';
        submit_button('Показать', 'secondary', '', false);
        echo '<span class="description wss-bookings-product-select-note">Показаны только опубликованные товары с включенным WSS Bookings или будущими слотами.</span>';
        echo '</form>';

        if (!$selected_product_id) {
            echo '<p>Сначала включите WSS Bookings хотя бы у одного товара WooCommerce.</p>';
            echo '</div>';
            return;
        }

        echo '<div class="wss-bookings-grid">';
        $this->render_create_slot_card($selected_product_id);
        $this->render_generate_slots_card($selected_product_id);
        echo '</div>';

        echo '<h2>Ближайшие слоты</h2>';
        echo '<p class="description">Показаны ближайшие слоты для экскурсии: <strong>' . esc_html(get_the_title($selected_product_id)) . '</strong> (ID ' . esc_html((string) $selected_product_id) . ').</p>';
        $this->render_slots_table($selected_product_id, $slots);

        echo '</div>';
    }

    public function render_tools_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $message = isset($_GET['wss_message']) ? sanitize_text_field(wp_unslash($_GET['wss_message'])) : '';

        echo '<div class="wrap wss-bookings-admin">';
        echo '<h1>WSS WooCommerce Bookings</h1>';
        $this->render_admin_tabs(self::ADMIN_PAGE_TOOLS);
        echo '<p class="description">Массовые операции вынесены отдельно от текущего расписания: импорт из WooCommerce Bookings, очистка, проставление меток и удаление слотов.</p>';

        if ($message) {
            $notice_class = in_array($message, ['delete_all_confirm_error', 'slot_error'], true) ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . esc_html($this->admin_message_text($message)) . '</p></div>';
        }

        if (!$this->is_woocommerce_active()) {
            echo '<div class="notice notice-warning"><p>WooCommerce не активен. Массовые действия доступны после включения WooCommerce.</p></div>';
            echo '</div>';
            return;
        }

        $this->render_tools_card();
        $this->render_import_report();
        $this->render_products_summary(0);
        echo '</div>';
    }

    public function render_blocks_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $message = isset($_GET['wss_message']) ? sanitize_text_field(wp_unslash($_GET['wss_message'])) : '';
        $products = $this->get_products_for_select();
        $blocks = $this->get_booking_blocks();

        echo '<div class="wrap wss-bookings-admin">';
        echo '<h1>WSS WooCommerce Bookings</h1>';
        $this->render_admin_tabs(self::ADMIN_PAGE_BLOCKS);
        echo '<p class="description">Блокировки перекрывают расписание: если день заблокирован, бронирование на фронте будет недоступно, даже если слоты на эту дату уже созданы.</p>';

        if ($message) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($this->admin_message_text($message)) . '</p></div>';
        }

        echo '<div class="wss-bookings-grid">';
        echo '<div class="wss-bookings-card">';
        echo '<h2>Заблокировать конкретные даты</h2>';
        echo '<form method="post" class="wss-bookings-block-dates-form">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="create_block_dates">';
        $this->render_product_scope_select('block_product_id', $products);
        echo '<input type="hidden" id="wss-bookings-block-selected-dates" name="block_selected_dates" value="">';
        echo '<p class="description">Можно выбрать один или несколько отдельных дней. Область применения: все экскурсии или одна выбранная экскурсия.</p>';
        echo '<div class="wss-bookings-date-picker" data-input="#wss-bookings-block-selected-dates" data-start-month="' . esc_attr(current_time('Y-m-01')) . '">';
        echo '<div class="wss-bookings-calendar-head"><button type="button" class="button" data-wss-calendar-prev>‹</button><strong data-wss-calendar-title></strong><button type="button" class="button" data-wss-calendar-next>›</button></div>';
        echo '<div class="wss-bookings-calendar-weekdays"><span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span></div>';
        echo '<div class="wss-bookings-calendar-grid" data-wss-calendar-grid></div>';
        echo '<div class="wss-bookings-calendar-actions"><button type="button" class="button" data-wss-calendar-select-month>Выбрать месяц</button><button type="button" class="button" data-wss-calendar-clear>Очистить</button></div>';
        echo '<p class="wss-bookings-selected-dates"><strong>Выбрано дат:</strong> <span data-wss-selected-count>0</span><br><span data-wss-selected-dates-text>Пока ничего не выбрано</span></p>';
        echo '<p class="wss-bookings-calendar-error" data-wss-calendar-error hidden>Выберите хотя бы одну дату.</p>';
        echo '</div>';
        echo '<p><label>Причина / заметка<br><textarea name="block_note" rows="3" placeholder="Например: санитарный день, закрытое мероприятие"></textarea></label></p>';
        submit_button('Создать блокировку дат', 'primary', '', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="wss-bookings-card">';
        echo '<h2>Заблокировать дни недели</h2>';
        echo '<form method="post">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="create_block_weekdays">';
        $this->render_product_scope_select('block_product_id', $products);
        echo '<p><strong>Дни недели</strong><br>';
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            echo '<label class="wss-bookings-weekday"><input type="checkbox" name="block_weekdays[]" value="' . esc_attr((string) $weekday) . '"> ' . esc_html($this->weekday_label($weekday)) . '</label>';
        }
        echo '</p>';
        echo '<div class="wss-bookings-two-cols">';
        echo '<p><label>С даты<br><input type="date" name="block_date_from" required></label></p>';
        echo '<p><label>По дату<br><input type="date" name="block_date_to" required></label></p>';
        echo '</div>';
        echo '<p><label>Причина / заметка<br><textarea name="block_note" rows="3" placeholder="Например: выходные по понедельникам"></textarea></label></p>';
        submit_button('Создать блокировку дней недели', 'primary', '', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<h2>Активные блокировки</h2>';
        $this->render_blocks_table($blocks);
        echo '</div>';
    }

    private function render_product_scope_select(string $name, array $products): void {
        echo '<p><label>Область применения<br><select name="' . esc_attr($name) . '">';
        echo '<option value="0">Все экскурсии</option>';
        foreach ($products as $product) {
            printf('<option value="%d">%s</option>', (int) $product->get_id(), esc_html($product->get_name()));
        }
        echo '</select></label></p>';
    }

    private function render_blocks_table(array $blocks): void {
        if (!$blocks) {
            echo '<p>Блокировок пока нет.</p>';
            return;
        }

        echo '<table class="widefat striped wss-bookings-summary-table">';
        echo '<thead><tr><th>Область</th><th>Тип</th><th>Период / дата</th><th>Заметка</th><th>Действия</th></tr></thead><tbody>';
        foreach ($blocks as $block) {
            $product_id = (int) $block->product_id;
            $scope = $product_id > 0 ? get_the_title($product_id) . ' (ID ' . $product_id . ')' : 'Все экскурсии';
            $type = !empty($block->block_date) ? 'Дата' : 'День недели';
            $period = !empty($block->block_date)
                ? $this->format_date((string) $block->block_date)
                : $this->weekday_label((int) $block->weekday) . ', ' . $this->format_date((string) $block->date_from) . ' — ' . $this->format_date((string) $block->date_to);
            $delete_url = wp_nonce_url(add_query_arg([
                'page' => self::ADMIN_PAGE_BLOCKS,
                'wss_booking_action' => 'delete_block',
                'block_id' => (int) $block->id,
            ], admin_url('admin.php')), 'wss_wc_bookings_action');

            echo '<tr>';
            echo '<td><strong>' . esc_html($scope) . '</strong></td>';
            echo '<td>' . esc_html($type) . '</td>';
            echo '<td>' . esc_html($period) . '</td>';
            echo '<td>' . esc_html((string) $block->note) . '</td>';
            echo '<td><a class="wss-bookings-delete" href="' . esc_url($delete_url) . '" onclick="return confirm(\'Удалить блокировку?\')">Удалить</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function render_bookings_calendar_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $products = $this->get_products_for_select();
        $selected_product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        $month = isset($_GET['wss_month']) ? sanitize_text_field(wp_unslash($_GET['wss_month'])) : current_time('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = current_time('Y-m');
        }

        $start = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: new DateTime(current_time('Y-m-01'));
        $prev = (clone $start)->modify('-1 month')->format('Y-m');
        $next = (clone $start)->modify('+1 month')->format('Y-m');
        $booked_slots = $this->get_booked_slots_for_calendar($start->format('Y-m-d'), $selected_product_id);

        echo '<div class="wrap wss-bookings-admin">';
        echo '<h1>WSS WooCommerce Bookings</h1>';
        $this->render_admin_tabs(self::ADMIN_PAGE_CALENDAR);
        echo '<p class="description">Календарь показывает занятые даты и время по слотам WSS Bookings. Это быстрый обзор уже забронированных экскурсий.</p>';

        echo '<form method="get" class="wss-bookings-product-select">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::ADMIN_PAGE_CALENDAR) . '">';
        echo '<label><strong>Экскурсия</strong></label> <select name="product_id"><option value="0">Все экскурсии</option>';
        foreach ($products as $product) {
            printf('<option value="%d" %s>%s</option>', (int) $product->get_id(), selected($selected_product_id, (int) $product->get_id(), false), esc_html($product->get_name()));
        }
        echo '</select> ';
        echo '<label><strong>Месяц</strong></label> <input type="month" name="wss_month" value="' . esc_attr($start->format('Y-m')) . '"> ';
        submit_button('Показать', 'secondary', '', false);
        echo '</form>';

        echo '<div class="wss-bookings-calendar-nav-row">';
        echo '<a class="button" href="' . esc_url(add_query_arg(['page' => self::ADMIN_PAGE_CALENDAR, 'product_id' => $selected_product_id, 'wss_month' => $prev], admin_url('admin.php'))) . '">← Предыдущий месяц</a>';
        echo '<h2>' . esc_html(date_i18n('F Y', $start->getTimestamp())) . '</h2>';
        echo '<a class="button" href="' . esc_url(add_query_arg(['page' => self::ADMIN_PAGE_CALENDAR, 'product_id' => $selected_product_id, 'wss_month' => $next], admin_url('admin.php'))) . '">Следующий месяц →</a>';
        echo '</div>';
        $this->render_bookings_calendar_grid($start, $booked_slots);
        echo '</div>';
    }

    private function get_booked_slots_for_calendar(string $month_start, int $product_id = 0): array {
        global $wpdb;
        if (!$this->is_valid_date($month_start)) {
            return [];
        }

        $start = new DateTime($month_start);
        $end = (clone $start)->modify('last day of this month')->format('Y-m-d');
        $table = self::table_name();
        $where_product = $product_id > 0 ? $wpdb->prepare(' AND product_id = %d', $product_id) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE booked > 0 AND slot_date BETWEEN %s AND %s {$where_product} ORDER BY slot_date ASC, start_time ASC",
            $start->format('Y-m-d'),
            $end
        ));

        $by_date = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $by_date[(string) $row->slot_date][] = $row;
        }
        return $by_date;
    }

    private function render_bookings_calendar_grid(DateTime $month_start, array $booked_slots): void {
        $first = clone $month_start;
        $days_in_month = (int) $first->format('t');
        $offset = ((int) $first->format('N')) - 1;

        echo '<div class="wss-bookings-admin-month">';
        foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $label) {
            echo '<div class="wss-bookings-admin-month-weekday">' . esc_html($label) . '</div>';
        }
        for ($i = 0; $i < $offset; $i++) {
            echo '<div class="wss-bookings-admin-month-day is-empty"></div>';
        }
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = $first->format('Y-m-') . sprintf('%02d', $day);
            $items = $booked_slots[$date] ?? [];
            echo '<div class="wss-bookings-admin-month-day ' . esc_attr($items ? 'has-bookings' : '') . '">';
            echo '<div class="wss-bookings-admin-day-number">' . esc_html((string) $day) . '</div>';
            foreach ($items as $slot) {
                echo '<div class="wss-bookings-admin-booking-item">';
                echo '<strong>' . esc_html($this->is_all_day_slot($slot) ? 'Весь день' : $this->format_time((string) $slot->start_time)) . '</strong> ';
                echo esc_html(get_the_title((int) $slot->product_id));
                echo '<br><span>' . esc_html((string) $slot->booked) . ' / ' . esc_html((string) $slot->capacity) . ' мест</span>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private function admin_message_text(string $message): string {
        if (strpos($message, 'generated_') === 0) {
            $count = (int) str_replace('generated_', '', $message);
            return sprintf('Создано слотов: %d.', $count);
        }

        if (strpos($message, 'blocks_created_') === 0) {
            $count = (int) str_replace('blocks_created_', '', $message);
            return sprintf('Создано блокировок: %d.', $count);
        }

        if ($message === 'pro_required') {
            return 'Это действие доступно в WSS WooCommerce Bookings Pro.';
        }

        if ($message === 'settings_saved') {
            return 'Настройки WSS Bookings сохранены.';
        }

        if ($message === 'wc_bookings_imported') {
            $products = isset($_GET['wss_import_products']) ? absint($_GET['wss_import_products']) : 0;
            $slots = isset($_GET['wss_import_slots']) ? absint($_GET['wss_import_slots']) : 0;
            $booked = isset($_GET['wss_import_booked']) ? absint($_GET['wss_import_booked']) : 0;
            $skipped = isset($_GET['wss_import_skipped']) ? absint($_GET['wss_import_skipped']) : 0;
            $ignored_generic = isset($_GET['wss_import_ignored_generic']) ? absint($_GET['wss_import_ignored_generic']) : 0;
            $inactive_cleaned = isset($_GET['wss_inactive_cleaned']) ? absint($_GET['wss_inactive_cleaned']) : 0;
            $expired_products = isset($_GET['wss_import_expired']) ? absint($_GET['wss_import_expired']) : 0;
            return sprintf('Импорт из WooCommerce Bookings завершен. Обработаны только опубликованные товары. Товаров обработано: %d. Создано слотов: %d. Перенесено занятых мест из существующих бронирований: %d. Пропущено правил без понятной даты/времени: %d. Проигнорировано общих правил времени у товаров с конкретными датами: %d. Товаров с просроченным расписанием без актуальных дат: %d. Удалено слотов у черновиков/неактивных товаров: %d.', $products, $slots, $booked, $skipped, $ignored_generic, $expired_products, $inactive_cleaned);
        }

        if ($message === 'all_slots_deleted') {
            $deleted = isset($_GET['wss_deleted_slots']) ? absint($_GET['wss_deleted_slots']) : 0;
            return sprintf('Удалено слотов расписания: %d.', $deleted);
        }

        if ($message === 'wss_flags_repaired') {
            $count = isset($_GET['wss_flags_fixed']) ? absint($_GET['wss_flags_fixed']) : 0;
            $deleted = isset($_GET['wss_deleted_slots']) ? absint($_GET['wss_deleted_slots']) : 0;
            return sprintf('Метки WSS Bookings проставлены/обновлены у активных товаров: %d. У архивных/неактивных товаров удалено слотов: %d.', $count, $deleted);
        }

        if ($message === 'inactive_wss_cleaned') {
            $deleted = isset($_GET['wss_deleted_slots']) ? absint($_GET['wss_deleted_slots']) : 0;
            return sprintf('Удалено слотов у черновиков/архивных/неактивных товаров и отключены их метки WSS: %d.', $deleted);
        }

        if ($message === 'all_day_slots_created') {
            $created = isset($_GET['wss_all_day_created']) ? absint($_GET['wss_all_day_created']) : 0;
            return sprintf('Создано дневных слотов без времени для активных товаров без расписания: %d.', $created);
        }

        $map = [
            'slot_created' => 'Слот создан.',
            'slot_error' => 'Слот не создан. Проверьте поля или убедитесь, что такого слота еще нет.',
            'slot_deleted' => 'Слот удален.',
            'slot_updated' => 'Статус слота обновлен.',
            'delete_all_confirm_error' => 'Расписание не удалено: нужно поставить галку подтверждения и ввести УДАЛИТЬ.',
            'wss_product_enabled' => 'Метка WSS Bookings для товара включена.',
            'block_deleted' => 'Блокировка удалена.',
        ];

        return $map[$message] ?? 'Готово.';
    }

    private function product_booking_mode_label(int $product_id): string {
        $future = $this->count_slots_for_product($product_id, true);
        if ($future === 0) {
            return $this->auto_all_day_is_disabled($product_id) ? 'Нет актуальных дат' : 'Нет будущих слотов';
        }

        if ($this->count_future_all_day_slots_for_product($product_id) > 0 && $this->count_manual_slots_for_product($product_id) === 0) {
            return 'Весь день, без времени';
        }

        return 'По времени';
    }

    private function render_import_report(): void {
        $report = get_transient(self::IMPORT_REPORT_TRANSIENT);
        if (!is_array($report) || !$report) {
            return;
        }

        echo '<div class="wss-bookings-import-report">';
        echo '<h2>Последний отчет импорта</h2>';
        echo '<p class="description">По этой таблице видно, в какие именно товары попали импортированные слоты. Импорт по умолчанию берет только опубликованные товары. Нажмите «Открыть», чтобы посмотреть расписание конкретной экскурсии.</p>';
        echo '<table class="widefat striped wss-bookings-summary-table">';
        echo '<thead><tr><th>Товар / экскурсия</th><th>ID</th><th>Статус товара</th><th>Правил</th><th>Создано слотов</th><th>Будущих слотов сейчас</th><th>Режим</th><th>Мест</th><th>Длительность</th><th>Пропущено правил</th><th>Игнор. общих правил</th><th>Просроченных дат</th><th>Актуальность</th><th>Метка WSS</th><th></th></tr></thead><tbody>';

        foreach ($report as $row) {
            $product_id = isset($row['product_id']) ? absint($row['product_id']) : 0;
            $url = add_query_arg([
                'page' => 'wss-wc-bookings',
                'product_id' => $product_id,
            ], admin_url('admin.php'));
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($row['title'] ?? get_the_title($product_id))) . '</strong></td>';
            echo '<td>' . esc_html((string) $product_id) . '</td>';
            echo '<td>' . esc_html((string) ($row['post_status_label'] ?? $this->product_status_label($product_id))) . '</td>';
            echo '<td>' . esc_html((string) absint($row['rules'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) absint($row['created'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) absint($row['future_slots'] ?? 0)) . '</td>';
            echo '<td>' . esc_html($this->product_booking_mode_label($product_id)) . '</td>';
            echo '<td>' . esc_html((string) absint($row['capacity'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) absint($row['duration'] ?? 0)) . ' мин</td>';
            echo '<td>' . esc_html((string) absint($row['skipped'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) absint($row['ignored_generic'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) absint($row['expired_slots'] ?? 0)) . '</td>';
            echo '<td>' . esc_html(!empty($row['schedule_expired']) ? 'Нет актуальных дат' : 'Есть актуальное расписание / услуга без времени') . '</td>';
            $enabled_label = !empty($row['enabled']) ? 'Включена' : 'Не включена (' . (string) ($row['meta_value'] ?? '') . ')';
            echo '<td>' . esc_html($enabled_label) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($url) . '">Открыть</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_products_summary(int $selected_product_id = 0): void {
        $ids = $this->get_enabled_booking_product_ids();
        if (!$ids) {
            return;
        }

        echo '<div class="wss-bookings-products-summary">';
        echo '<h2>Товары с WSS Bookings</h2>';
        echo '<p class="description">Сводка помогает проверить, куда попало расписание после импорта. Здесь показываются только опубликованные товары; черновики и неактивные экскурсии не выводятся на фронте и не участвуют в новом импорте.</p>';
        echo '<table class="widefat striped wss-bookings-summary-table">';
        echo '<thead><tr><th>Товар / экскурсия</th><th>ID</th><th>Статус товара</th><th>Всего слотов</th><th>Будущих слотов</th><th>Режим</th><th>Статус WSS</th><th></th></tr></thead><tbody>';

        foreach ($ids as $product_id) {
            $total = $this->count_slots_for_product($product_id, false);
            $future = $this->count_slots_for_product($product_id, true);
            $enabled = self::is_booking_product($product_id);
            $url = add_query_arg([
                'page' => 'wss-wc-bookings',
                'product_id' => $product_id,
            ], admin_url('admin.php'));
            $row_class = $selected_product_id === $product_id ? ' class="is-selected-product"' : '';

            echo '<tr' . $row_class . '>';
            echo '<td><strong>' . esc_html(get_the_title($product_id)) . '</strong></td>';
            echo '<td>' . esc_html((string) $product_id) . '</td>';
            echo '<td>' . esc_html($this->product_status_label($product_id)) . '</td>';
            echo '<td>' . esc_html((string) $total) . '</td>';
            echo '<td>' . esc_html((string) $future) . '</td>';
            echo '<td>' . esc_html($this->product_booking_mode_label($product_id)) . '</td>';
            echo '<td>' . esc_html($enabled ? 'Включен' : 'Есть слоты, но метка не включена') . '</td>';
            $actions = '<a class="button button-small" href="' . esc_url($url) . '">Открыть</a>';
            if (!$enabled) {
                $enable_url = wp_nonce_url(add_query_arg([
                    'page' => 'wss-wc-bookings',
                    'product_id' => $product_id,
                    'wss_booking_action' => 'enable_product_wss',
                ], admin_url('admin.php')), 'wss_wc_bookings_action');
                $actions .= ' <a class="button button-small" href="' . esc_url($enable_url) . '">Включить метку</a>';
            }
            echo '<td>' . $actions . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_create_slot_card(int $product_id): void {
        echo '<div class="wss-bookings-card">';
        echo '<h2>Добавить слот</h2>';
        echo '<form method="post">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="create_slot">';
        echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">';
        echo '<p><label>Дата<br><input type="date" name="slot_date" required></label></p>';
        echo '<p><label>Начало<br><input type="time" name="start_time" required></label></p>';
        echo '<p><label>Конец<br><input type="time" name="end_time" required></label></p>';
        echo '<p><label>Мест<br><input type="number" name="capacity" min="1" step="1" value="20" required></label></p>';
        echo '<p><label>Статус<br><select name="status"><option value="open">Открыт</option><option value="closed">Закрыт</option></select></label></p>';
        echo '<p><label>Заметка<br><textarea name="note" rows="3"></textarea></label></p>';
        submit_button('Добавить слот', 'primary', '', false);
        echo '</form>';
        echo '</div>';
    }

    private function render_generate_slots_card(int $product_id): void {
        echo '<div class="wss-bookings-card">';
        echo '<h2>Генератор расписания</h2>';
        echo '<form method="post" class="wss-bookings-generator-form">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="generate_slots">';
        echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">';
        echo '<input type="hidden" id="wss-bookings-selected-dates" name="selected_dates" value="">';
        echo '<p class="description">Выберите одну или несколько дат в календаре.' . ($this->is_pro_active() ? ' Ниже можно дополнительно создать такие же слоты по определенным дням недели в заданном периоде.' : ' Генератор по дням недели доступен в Pro.') . '</p>';
        echo '<div class="wss-bookings-date-picker" data-input="#wss-bookings-selected-dates" data-start-month="' . esc_attr(current_time('Y-m-01')) . '">';
        echo '<div class="wss-bookings-calendar-head"><button type="button" class="button" data-wss-calendar-prev>‹</button><strong data-wss-calendar-title></strong><button type="button" class="button" data-wss-calendar-next>›</button></div>';
        echo '<div class="wss-bookings-calendar-weekdays"><span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span></div>';
        echo '<div class="wss-bookings-calendar-grid" data-wss-calendar-grid></div>';
        echo '<div class="wss-bookings-calendar-actions"><button type="button" class="button" data-wss-calendar-select-month>Выбрать месяц</button><button type="button" class="button" data-wss-calendar-clear>Очистить</button></div>';
        echo '<p class="wss-bookings-selected-dates"><strong>Выбрано дат:</strong> <span data-wss-selected-count>0</span><br><span data-wss-selected-dates-text>Пока ничего не выбрано</span></p>';
        echo '<p class="wss-bookings-calendar-error" data-wss-calendar-error hidden>Выберите хотя бы одну дату.</p>';
        echo '</div>';
        if ($this->is_pro_active()) {
            echo '<div class="wss-bookings-weekday-generator">';
            echo '<h3>Или добавить по дням недели</h3>';
            echo '<p class="description">Выберите дни недели и период. Эти даты будут добавлены к датам, выбранным в календаре выше.</p>';
            echo '<p><strong>Дни недели</strong><br>';
            for ($weekday = 1; $weekday <= 7; $weekday++) {
                echo '<label class="wss-bookings-weekday"><input type="checkbox" name="generate_weekdays[]" value="' . esc_attr((string) $weekday) . '"> ' . esc_html($this->weekday_label($weekday)) . '</label>';
            }
            echo '</p>';
            echo '<div class="wss-bookings-two-cols">';
            echo '<p><label>С даты<br><input type="date" name="generate_date_from"></label></p>';
            echo '<p><label>По дату<br><input type="date" name="generate_date_to"></label></p>';
            echo '</div>';
            echo '</div>';
        }
        echo '<p><label>Время начала через запятую<br><input type="text" name="times" value="12:00, 14:00, 16:00, 18:00" required></label></p>';
        echo '<div class="wss-bookings-two-cols">';
        echo '<p><label>Длительность, минут<br><input type="number" name="duration" min="1" step="1" value="60" required></label></p>';
        echo '<p><label>Мест в каждом слоте<br><input type="number" name="capacity" min="1" step="1" value="20" required></label></p>';
        echo '</div>';
        echo '<p><label>Статус<br><select name="status"><option value="open">Открыт</option><option value="closed">Закрыт</option></select></label></p>';
        submit_button('Создать расписание', 'primary', '', false);
        echo '</form>';
        echo '</div>';
    }

    private function render_tools_card(): void {
        $import_product_count = count($this->get_wc_booking_product_ids_for_import());
        $inactive_wss_count = count($this->get_inactive_wss_product_ids());
        $total_slots = $this->get_total_slots_count();

        echo '<div class="wss-bookings-tools">';
        echo '<div class="wss-bookings-card wss-bookings-card-tool">';
        echo '<h2>Импорт из стандартного WooCommerce Bookings</h2>';
        echo '<p class="description">Инструмент найдет только <strong>опубликованные и не архивные</strong> товары старого типа <code>booking</code> и опубликованные товары с метаданными WooCommerce Bookings, включит у них чекбокс WSS Bookings и перенесет доступные правила расписания в слоты WSS.</p>';
        echo '<p><strong>Найдено активных кандидатов для импорта:</strong> ' . esc_html((string) $import_product_count) . '</p>';
        echo '<form method="post" class="wss-bookings-import-form">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="import_wc_bookings">';
        echo '<p><label>Горизонт для повторяющихся правил, месяцев<br><input type="number" name="import_months" min="1" max="60" step="1" value="24"></label></p>';
        echo '<p class="description">Для правил с конкретными датами будут импортированы сами даты. Для повторяющихся недельных правил будет создано расписание от текущей даты на указанный срок. Если у опубликованной услуги не найдено расписание по времени, будут созданы дневные слоты без указания времени.</p>';
        echo '<p class="wss-bookings-warning">Перед импортом текущие WSS-слоты у импортируемых активных товаров будут удалены и заменены расписанием из WooCommerce Bookings. Слоты и метки у черновиков, приватных, скрытых и архивных товаров будут очищены.</p>';
        submit_button('Импортировать расписание из WooCommerce Bookings', 'primary', '', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="wss-bookings-card wss-bookings-card-tool">';
        echo '<h2>Очистить неактивные и архивные экскурсии</h2>';
        echo '<p class="description">Удаляет WSS-слоты у черновиков, приватных, товаров в корзине, а также опубликованных скрытых/архивных товаров, и отключает у них метку WSS Bookings. Заказы WooCommerce и сами товары не удаляются.</p>';
        echo '<p><strong>Неактивных/архивных товаров с WSS-слотами/меткой:</strong> ' . esc_html((string) $inactive_wss_count) . '</p>';
        echo '<form method="post" class="wss-bookings-cleanup-form">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="cleanup_inactive_wss">';
        submit_button('Очистить расписание архивных/неактивных', 'secondary', '', false);
        echo '</form>';
        echo '</div>';

        $repair_product_count = count($this->get_products_for_flag_repair());
        echo '<div class="wss-bookings-card wss-bookings-card-tool">';
        echo '<h2>Проставить метки WSS Bookings</h2>';
        echo '<p class="description">Служебная кнопка на случай, если расписание импортировалось, но товары не появились на фронте как товары WSS Bookings. Метка будет включена только у активных опубликованных товаров старого WooCommerce Bookings и у активных опубликованных товаров, для которых уже есть слоты WSS. Архивные и скрытые товары будут пропущены.</p>';
        echo '<p><strong>Товаров для проверки:</strong> ' . esc_html((string) $repair_product_count) . '</p>';
        echo '<form method="post">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="repair_wss_flags">';
        submit_button('Проставить метки WSS Bookings', 'secondary', '', false);
        echo '</form>';
        echo '</div>';

        $missing_all_day_count = count($this->get_products_missing_slots_for_all_day());
        echo '<div class="wss-bookings-card wss-bookings-card-tool">';
        echo '<h2>Создать дневные слоты без времени</h2>';
        echo '<p class="description">Для активных неархивных товаров WSS Bookings, у которых нет будущих слотов, создаёт бронирование на весь день без указания времени. Это нужно для фотосессий и услуг с открытой датой.</p>';
        echo '<p><strong>Активных товаров без будущего расписания:</strong> ' . esc_html((string) $missing_all_day_count) . '</p>';
        echo '<form method="post">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="create_missing_all_day_slots">';
        echo '<p><label>Горизонт, месяцев<br><input type="number" name="all_day_months" min="1" max="60" step="1" value="24"></label></p>';
        submit_button('Создать дневные слоты', 'secondary', '', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="wss-bookings-card wss-bookings-card-danger">';
        echo '<h2>Удалить всё расписание WSS</h2>';
        echo '<p><strong>Сейчас слотов в WSS Bookings:</strong> ' . esc_html((string) $total_slots) . '</p>';
        echo '<p class="wss-bookings-danger-text">Это действие удалит все слоты расписания из нового плагина WSS WooCommerce Bookings для всех товаров. Заказы WooCommerce и сами товары удалены не будут.</p>';
        echo '<form method="post" class="wss-bookings-danger-form">';
        wp_nonce_field('wss_wc_bookings_action');
        echo '<input type="hidden" name="wss_booking_action" value="delete_all_slots">';
        echo '<p><label><input type="checkbox" name="confirm_delete_all" value="1"> Я понимаю, что будут удалены все расписания WSS для всех товаров.</label></p>';
        echo '<p><label>Введите <strong>УДАЛИТЬ</strong> для подтверждения<br><input type="text" name="confirm_delete_text" autocomplete="off"></label></p>';
        submit_button('Удалить всё расписание', 'delete', '', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    private function render_slots_table(int $product_id, array $slots): void {
        if (!$slots) {
            echo '<p>Слотов пока нет.</p>';
            return;
        }

        echo '<table class="widefat striped wss-bookings-slots-table">';
        echo '<thead><tr>';
        echo '<th>Экскурсия</th><th>Дата</th><th>Время</th><th>Мест</th><th>Занято</th><th>Свободно</th><th>Статус</th><th>Заметка</th><th>Действия</th>';
        echo '</tr></thead><tbody>';
        foreach ($slots as $slot) {
            $available = max(0, (int) $slot->capacity - (int) $slot->booked);
            $toggle_url = wp_nonce_url(add_query_arg([
                'page' => 'wss-wc-bookings',
                'product_id' => $product_id,
                'wss_booking_action' => 'toggle_slot_status',
                'slot_id' => (int) $slot->id,
            ], admin_url('admin.php')), 'wss_wc_bookings_action');
            $delete_url = wp_nonce_url(add_query_arg([
                'page' => 'wss-wc-bookings',
                'product_id' => $product_id,
                'wss_booking_action' => 'delete_slot',
                'slot_id' => (int) $slot->id,
            ], admin_url('admin.php')), 'wss_wc_bookings_action');

            echo '<tr>';
            echo '<td><strong>' . esc_html(get_the_title((int) $slot->product_id)) . '</strong><br><span class="description">ID ' . esc_html((string) (int) $slot->product_id) . '</span></td>';
            echo '<td>' . esc_html($this->format_date($slot->slot_date)) . '</td>';
            echo '<td>' . esc_html($this->is_all_day_slot($slot) ? 'Весь день' : $this->format_time($slot->start_time) . '–' . $this->format_time($slot->end_time)) . '</td>';
            echo '<td>' . esc_html((string) $slot->capacity) . '</td>';
            echo '<td>' . esc_html((string) $slot->booked) . '</td>';
            echo '<td>' . esc_html((string) $available) . '</td>';
            $blocked = $this->is_date_blocked((int) $slot->product_id, (string) $slot->slot_date);
            if ($blocked) {
                echo '<td><span class="wss-bookings-status wss-bookings-status-blocked">Заблокирован</span></td>';
            } else {
                echo '<td><span class="wss-bookings-status wss-bookings-status-' . esc_attr($slot->status) . '">' . esc_html($slot->status === 'open' ? 'Открыт' : 'Закрыт') . '</span></td>';
            }
            echo '<td>' . esc_html((string) $slot->note) . '</td>';
            echo '<td><a href="' . esc_url($toggle_url) . '">' . esc_html($slot->status === 'open' ? 'Закрыть' : 'Открыть') . '</a> | <a class="wss-bookings-delete" href="' . esc_url($delete_url) . '" onclick="return confirm(\'Удалить слот?\')">Удалить</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function get_admin_slots(int $product_id): array {
        $this->ensure_all_day_slots_for_product($product_id, 24);

        global $wpdb;
        $table = self::table_name();
        $today = current_time('Y-m-d');
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE product_id = %d AND slot_date >= %s ORDER BY slot_date ASC, start_time ASC LIMIT 300",
            $product_id,
            $today
        );
        $rows = $wpdb->get_results($sql);
        return is_array($rows) ? $rows : [];
    }

    private function get_frontend_slots(int $product_id): array {
        if ($this->is_pro_active()) {
            $this->ensure_all_day_slots_for_product($product_id, 24);
        }

        global $wpdb;
        $table = self::table_name();
        $today = current_time('Y-m-d');
        $now_time = current_time('H:i:s');

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE product_id = %d
               AND status = 'open'
               AND capacity > booked
               AND (slot_date > %s OR (slot_date = %s AND (start_time >= %s OR note = %s)))
             ORDER BY slot_date ASC, start_time ASC
             LIMIT 400",
            $product_id,
            $today,
            $today,
            $now_time,
            self::ALL_DAY_NOTE
        );

        $rows = $wpdb->get_results($sql);
        $rows = is_array($rows) ? $rows : [];
        return $this->is_pro_active() ? $this->filter_unblocked_slots($rows, $product_id) : $rows;
    }

    private function get_slot(int $slot_id) {
        global $wpdb;
        $slot_id = absint($slot_id);
        if (!$slot_id) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE id = %d", $slot_id));
    }

    public function render_frontend_slot_selector(): void {
        global $product;
        if (!$product || !self::is_booking_product($product->get_id())) {
            return;
        }

        $product_id = (int) $product->get_id();
        if (!$this->is_active_product($product_id)) {
            return;
        }
        $this->frontend_selector_rendered[$product_id] = true;
        $slots = $this->get_frontend_slots($product_id);
        $this->render_slot_selector_markup($slots, $product_id);
    }

    private function render_slot_selector_markup(array $slots, int $product_id = 0): void {
        $has_time_slots = false;
        foreach ($slots as $slot) {
            if (!$this->is_all_day_slot($slot)) {
                $has_time_slots = true;
                break;
            }
        }

        echo '<div class="wss-booking-selector">';
        echo '<h3>' . esc_html($has_time_slots ? $this->get_public_text('title_time') : $this->get_public_text('title_date')) . '</h3>';

        if (!$slots) {
            echo '<p class="wss-booking-empty">' . esc_html($this->get_public_text('no_slots')) . '</p>';
            echo '<input type="hidden" name="wss_booking_no_slots" value="1">';
            echo '</div>';
            return;
        }

        $slots_data = [];
        foreach ($slots as $slot) {
            $available = max(0, (int) $slot->capacity - (int) $slot->booked);
            $is_all_day = $this->is_all_day_slot($slot);
            $slots_data[] = [
                'id' => (int) $slot->id,
                'date' => (string) $slot->slot_date,
                'dateLabel' => $this->format_date_with_weekday($slot->slot_date),
                'start' => $this->format_time($slot->start_time),
                'end' => $this->format_time($slot->end_time),
                'timeLabel' => $is_all_day ? 'Весь день' : $this->format_time($slot->start_time),
                'rangeLabel' => $is_all_day ? 'весь день' : $this->format_time($slot->start_time) . '–' . $this->format_time($slot->end_time),
                'allDay' => $is_all_day ? 1 : 0,
                'available' => $available,
                'availableLabel' => $available . ' ' . $this->plural_tickets($available),
            ];
        }

        $this->render_ticket_type_controls($product_id);
        echo '<input type="hidden" name="wss_booking_slot_id" class="wss-booking-slot-input" value="">';
        echo '<script type="application/json" class="wss-booking-slots-data">' . wp_json_encode($slots_data, JSON_UNESCAPED_UNICODE) . '</script>';
        echo '<div class="wss-booking-calendar" data-wss-booking-calendar data-wss-all-day="' . esc_attr($has_time_slots ? '0' : '1') . '">';
        echo '<div class="wss-booking-calendar-head"><button type="button" class="wss-booking-calendar-nav" data-wss-booking-prev aria-label="Предыдущий месяц">‹</button><strong data-wss-booking-title></strong><button type="button" class="wss-booking-calendar-nav" data-wss-booking-next aria-label="Следующий месяц">›</button></div>';
        echo '<div class="wss-booking-calendar-weekdays"><span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span></div>';
        echo '<div class="wss-booking-calendar-grid" data-wss-booking-grid></div>';
        echo '</div>';
        echo '<div class="wss-booking-times" hidden>';
        echo '<div class="wss-booking-times-title" data-wss-booking-date-title></div>';
        echo '<div class="wss-booking-time-buttons" data-wss-booking-times></div>';
        echo '</div>';
        echo '<div class="wss-booking-hint" aria-live="polite">' . esc_html($has_time_slots ? $this->get_public_text('hint_time') : $this->get_public_text('hint_date')) . '</div>';
        echo '</div>';
    }

    public function render_fallback_frontend_booking_form(): void {
        global $product;
        if (!$product || !self::is_booking_product($product->get_id())) {
            return;
        }

        $product_id = (int) $product->get_id();
        if (!$this->is_active_product($product_id)) {
            return;
        }

        if (!empty($this->frontend_selector_rendered[$product_id])) {
            return;
        }

        $slots = $this->get_frontend_slots($product_id);
        $this->frontend_selector_rendered[$product_id] = true;

        echo '<form class="cart wss-booking-cart wss-booking-cart-fallback" action="' . esc_url(apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())) . '" method="post" enctype="multipart/form-data">';
        $this->render_slot_selector_markup($slots, $product_id);

        if ($slots) {
            if ($product->is_sold_individually()) {
                echo '<input type="hidden" name="quantity" value="1">';
            } else {
                woocommerce_quantity_input([
                    'min_value' => apply_filters('woocommerce_quantity_input_min', 1, $product),
                    'max_value' => apply_filters('woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product),
                    'input_value' => 1,
                ], $product);
            }

            echo '<button type="submit" name="add-to-cart" value="' . esc_attr($product_id) . '" class="single_add_to_cart_button button alt">' . esc_html($this->get_public_text('button_text')) . '</button>';
        }

        echo '</form>';
    }

    public function booking_form_shortcode(): string {
        if (!is_product()) {
            return '';
        }

        global $product;
        if (!$product || !self::is_booking_product($product->get_id())) {
            return '';
        }

        if (!$this->is_active_product((int) $product->get_id())) {
            return '';
        }

        ob_start();
        $product_id = (int) $product->get_id();
        unset($this->frontend_selector_rendered[$product_id]);
        $this->render_fallback_frontend_booking_form();
        return (string) ob_get_clean();
    }

    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = []): bool {
        if (!self::is_booking_product($product_id)) {
            return (bool) $passed;
        }

        if (!$this->is_active_product((int) $product_id)) {
            wc_add_notice('Эта экскурсия сейчас недоступна для бронирования.', 'error');
            return false;
        }

        if (!empty($_POST['wss_booking_no_slots'])) {
            wc_add_notice('Для этого товара сейчас нет доступных дат бронирования.', 'error');
            return false;
        }

        $slot_id = isset($_POST['wss_booking_slot_id']) ? absint($_POST['wss_booking_slot_id']) : 0;
        if (!$slot_id) {
            wc_add_notice('Выберите дату бронирования.', 'error');
            return false;
        }

        $slot = $this->get_slot($slot_id);
        $tickets = $this->get_posted_ticket_selection((int) $product_id);
        if (!empty($tickets['enabled']) && (int) $tickets['qty'] <= 0) {
            wc_add_notice('Выберите количество билетов.', 'error');
            return false;
        }
        $requested_qty = !empty($tickets['enabled']) ? max(1, (int) $tickets['qty']) : max(1, (int) $quantity);
        if (!$this->slot_can_accept($slot, (int) $product_id, $requested_qty)) {
            wc_add_notice('На выбранную дату уже недостаточно свободных мест. Выберите другую дату или уменьшите количество билетов.', 'error');
            return false;
        }

        return (bool) $passed;
    }

    private function slot_can_accept($slot, int $product_id, int $requested_qty): bool {
        if (!$slot || (int) $slot->product_id !== $product_id || $slot->status !== 'open') {
            return false;
        }

        $today = current_time('Y-m-d');
        $now_time = current_time('H:i:s');
        if ($slot->slot_date < $today) {
            return false;
        }

        if (!$this->is_all_day_slot($slot) && $slot->slot_date === $today && $slot->start_time < $now_time) {
            return false;
        }

        if ($this->is_pro_active() && $this->is_date_blocked($product_id, (string) $slot->slot_date)) {
            return false;
        }

        $available = max(0, (int) $slot->capacity - (int) $slot->booked);
        return $available >= $requested_qty;
    }

    public function add_cart_item_data(array $cart_item_data, int $product_id, int $variation_id): array {
        if (!self::is_booking_product($product_id)) {
            return $cart_item_data;
        }

        $slot_id = isset($_POST['wss_booking_slot_id']) ? absint($_POST['wss_booking_slot_id']) : 0;
        $slot = $this->get_slot($slot_id);
        if (!$slot) {
            return $cart_item_data;
        }

        $cart_item_data['wss_booking'] = [
            'slot_id' => (int) $slot->id,
            'slot_date' => $slot->slot_date,
            'start_time' => $slot->start_time,
            'end_time' => $slot->end_time,
            'all_day' => $this->is_all_day_slot($slot) ? 1 : 0,
        ];
        $tickets = $this->get_posted_ticket_selection((int) $product_id);
        if (!empty($tickets['enabled']) && !empty($tickets['items'])) {
            $cart_item_data['wss_booking_tickets'] = $tickets['items'];
            $cart_item_data['wss_booking_tickets_label'] = $tickets['label'];
            $cart_item_data['wss_booking_tickets_total'] = (float) $tickets['total'];
        }
        $cart_item_data['wss_booking_key'] = md5(wp_json_encode($cart_item_data['wss_booking']) . wp_json_encode($cart_item_data['wss_booking_tickets'] ?? []) . microtime(true));

        return $cart_item_data;
    }

    public function display_cart_item_data(array $item_data, array $cart_item): array {
        if (empty($cart_item['wss_booking'])) {
            return $item_data;
        }

        $booking = $cart_item['wss_booking'];
        $item_data[] = [
            'key' => 'Дата экскурсии',
            'value' => $this->format_date($booking['slot_date']),
            'display' => $this->format_date($booking['slot_date']),
        ];
        if (empty($booking['all_day'])) {
            $item_data[] = [
                'key' => 'Время экскурсии',
                'value' => $this->format_time($booking['start_time']) . '–' . $this->format_time($booking['end_time']),
                'display' => $this->format_time($booking['start_time']) . '–' . $this->format_time($booking['end_time']),
            ];
        }
        if (!empty($cart_item['wss_booking_tickets_label'])) {
            $item_data[] = [
                'key' => 'Билеты',
                'value' => (string) $cart_item['wss_booking_tickets_label'],
                'display' => (string) $cart_item['wss_booking_tickets_label'],
            ];
        }

        return $item_data;
    }

    public function validate_cart_before_checkout(): void {
        if (!WC()->cart) {
            return;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (empty($cart_item['wss_booking']['slot_id'])) {
                continue;
            }

            $product_id = !empty($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            $qty = !empty($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;
            $slot = $this->get_slot((int) $cart_item['wss_booking']['slot_id']);

            if (!$this->slot_can_accept($slot, $product_id, $qty)) {
                wc_add_notice('На выбранную дату бронирования уже недостаточно мест. Обновите корзину и выберите другую дату.', 'error');
            }
        }
    }

    public function add_order_item_meta($item, $cart_item_key, $values, $order): void {
        if (empty($values['wss_booking'])) {
            return;
        }

        $booking = $values['wss_booking'];
        $item->add_meta_data('_wss_booking_slot_id', (int) $booking['slot_id'], true);
        $item->add_meta_data('Дата экскурсии', $this->format_date($booking['slot_date']), true);
        if (empty($booking['all_day'])) {
            $item->add_meta_data('Время экскурсии', $this->format_time($booking['start_time']) . '–' . $this->format_time($booking['end_time']), true);
        }
        if (!empty($values['wss_booking_tickets_label'])) {
            $item->add_meta_data('Билеты', (string) $values['wss_booking_tickets_label'], true);
        }
    }

    public function reserve_order_slots(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta(self::ORDER_META_RESERVED) === 'yes') {
            return;
        }

        $map = [];
        foreach ($order->get_items() as $item) {
            $slot_id = (int) $item->get_meta('_wss_booking_slot_id', true);
            if (!$slot_id) {
                continue;
            }
            $qty = max(1, (int) $item->get_quantity());
            if (!isset($map[$slot_id])) {
                $map[$slot_id] = 0;
            }
            $map[$slot_id] += $qty;
        }

        if (!$map) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        foreach ($map as $slot_id => $qty) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET booked = booked + %d, updated_at = %s WHERE id = %d",
                $qty,
                current_time('mysql'),
                $slot_id
            ));
        }

        $order->update_meta_data(self::ORDER_META_RESERVED, 'yes');
        $order->update_meta_data(self::ORDER_META_RESERVED_MAP, $map);
        $order->save();
    }

    public function release_order_slots(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta(self::ORDER_META_RESERVED) !== 'yes') {
            return;
        }

        $map = $order->get_meta(self::ORDER_META_RESERVED_MAP, true);
        if (!is_array($map)) {
            $map = [];
        }

        if (!$map) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        foreach ($map as $slot_id => $qty) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET booked = GREATEST(0, booked - %d), updated_at = %s WHERE id = %d",
                absint($qty),
                current_time('mysql'),
                absint($slot_id)
            ));
        }

        $order->update_meta_data(self::ORDER_META_RESERVED, 'released');
        $order->save();
    }

    private function format_date(string $date): string {
        $timestamp = strtotime($date . ' 00:00:00');
        return $timestamp ? date_i18n('d.m.Y', $timestamp) : $date;
    }

    private function format_date_with_weekday(string $date): string {
        $timestamp = strtotime($date . ' 00:00:00');
        if (!$timestamp) {
            return $date;
        }

        $weekdays = [
            1 => 'пн',
            2 => 'вт',
            3 => 'ср',
            4 => 'чт',
            5 => 'пт',
            6 => 'сб',
            7 => 'вс',
        ];
        $weekday = $weekdays[(int) date_i18n('N', $timestamp)] ?? '';

        return trim(date_i18n('d.m.Y', $timestamp) . ', ' . $weekday);
    }

    private function format_time(string $time): string {
        return substr($time, 0, 5);
    }

    private function plural_tickets(int $number): string {
        $number = abs($number);
        $n1 = $number % 10;
        $n2 = $number % 100;

        if ($n1 === 1 && $n2 !== 11) {
            return 'билет';
        }
        if ($n1 >= 2 && $n1 <= 4 && ($n2 < 12 || $n2 > 14)) {
            return 'билета';
        }
        return 'билетов';
    }
}

WSS_WooCommerce_Bookings::instance();
