<?php
if (!defined('ABSPATH')) {
    exit;
}

class WSS_WCB_YC_Plugin
{
    private const OPTION = 'wss_wcb_yc_options';
    private const LAST_NOTICE = 'wss_wcb_yc_last_notice';

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function is_pro_available(): bool
    {
        return (bool) apply_filters('wss_wcb_yc_is_pro', false);
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'admin_menu'], 70);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('admin_post_wss_wcb_yc_test_connection', [$this, 'handle_test_connection']);
        add_action('admin_post_wss_wcb_yc_sync_future', [$this, 'handle_manual_sync_future']);
        add_action('admin_post_wss_wcb_yc_clear_links', [$this, 'handle_clear_links']);

        add_action('woocommerce_new_booking', [$this, 'queue_booking_sync'], 30, 1);
        add_action('save_post_wc_booking', [$this, 'queue_booking_sync_from_save'], 30, 3);
        add_action('transition_post_status', [$this, 'queue_booking_sync_from_status_change'], 30, 3);
        add_action('woocommerce_order_status_changed', [$this, 'queue_order_bookings_sync'], 30, 4);

        add_action('updated_option', [$this, 'maybe_auto_sync_after_options_save'], 10, 3);
        add_action('wss_wcb_yc_sync_booking', [$this, 'sync_booking'], 10, 1);
        add_action('wss_wcb_yc_sync_future_batch', [$this, 'sync_future_batch'], 10, 1);
    }

    public static function defaults(): array
    {
        return [
            'enabled'         => '0',
            'email'           => '',
            'app_password'    => '',
            'caldav_url'      => '',
            'timezone'        => wp_timezone_string() ?: 'Europe/Moscow',
            'active_statuses' => ['processing', 'completed'],
            'auto_sync_after_save' => '1',
            'batch_size'      => 50,
            'title_template'  => 'Экскурсия: {product_name} — {persons} {persons_label} — заказ #{order_id}',
            'description_template' => "Бронирование: #{booking_id}\nЗаказ: #{order_id}\nЭкскурсия: {product_name}\n\nДата и время:\n{start_datetime} — {end_datetime}\n\nКоличество билетов/людей: {persons}\n\nКлиент:\n{customer_name}\n{customer_phone}\n{customer_email}\n\nСтатус заказа: {order_status}\nСтатус бронирования: {booking_status}\n\nАдминка:\nЗаказ: {admin_order_url}\nБронирование: {admin_booking_url}",
        ];
    }

    public static function options(): array
    {
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $options = wp_parse_args($saved, self::defaults());
        $options['active_statuses'] = is_array($options['active_statuses']) ? $options['active_statuses'] : ['processing', 'completed'];
        $options['batch_size'] = max(5, min(200, (int) $options['batch_size']));

        if (!self::is_pro_available()) {
            $defaults = self::defaults();
            $options['active_statuses'] = ['processing', 'completed'];
            $options['auto_sync_after_save'] = '0';
            $options['batch_size'] = $defaults['batch_size'];
            $options['title_template'] = $defaults['title_template'];
            $options['description_template'] = $defaults['description_template'];
        }

        return $options;
    }

    public function admin_menu(): void
    {
        $parent = class_exists('WooCommerce') ? 'woocommerce' : 'options-general.php';
        add_submenu_page(
            $parent,
            'Yandex Calendar',
            'Yandex Calendar',
            'manage_woocommerce',
            'wss-wcb-yandex-calendar',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting('wss_wcb_yc_settings', self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_options'],
            'default'           => self::defaults(),
        ]);
    }

    public function sanitize_options($input): array
    {
        $old = self::options();
        $input = is_array($input) ? $input : [];

        $password = isset($input['app_password']) ? (string) $input['app_password'] : '';
        if ($password === '') {
            $password = $old['app_password'];
        }

        $defaults = self::defaults();
        $is_pro = self::is_pro_available();
        $statuses = ['processing', 'completed'];

        if ($is_pro && !empty($input['active_statuses']) && is_array($input['active_statuses'])) {
            $statuses = [];
            $available_statuses = array_keys($this->available_order_statuses());
            foreach ($input['active_statuses'] as $status) {
                $status = $this->normalize_order_status_key($status);
                if (in_array($status, $available_statuses, true)) {
                    $statuses[] = $status;
                }
            }
            if (!$statuses) {
                $statuses = ['processing', 'completed'];
            }
        }

        $clean = [
            'enabled'         => !empty($input['enabled']) ? '1' : '0',
            'email'           => sanitize_email($input['email'] ?? ''),
            'app_password'    => $password,
            'caldav_url'      => esc_url_raw(trim((string) ($input['caldav_url'] ?? ''))),
            'timezone'        => sanitize_text_field($input['timezone'] ?? (wp_timezone_string() ?: 'Europe/Moscow')),
            'active_statuses' => array_values(array_unique($statuses)),
            'auto_sync_after_save' => ($is_pro && !empty($input['auto_sync_after_save'])) ? '1' : '0',
            'batch_size'      => $is_pro ? max(5, min(200, (int) ($input['batch_size'] ?? 50))) : $defaults['batch_size'],
            'title_template'  => $is_pro ? sanitize_text_field($input['title_template'] ?? $defaults['title_template']) : $defaults['title_template'],
            'description_template' => $is_pro ? wp_kses_post($input['description_template'] ?? $defaults['description_template']) : $defaults['description_template'],
        ];

        return $clean;
    }


    public function maybe_auto_sync_after_options_save(string $option, $old_value, $value): void
    {
        if ($option !== self::OPTION || !is_array($value)) {
            return;
        }

        $options = self::options();
        if (!$this->is_pro() || $options['enabled'] !== '1' || $options['auto_sync_after_save'] !== '1' || !$this->has_required_settings($options)) {
            return;
        }

        if (!$this->is_pro()) {
            $this->redirect_notice('warning', 'Первичная синхронизация будущих бронирований доступна в Pro-версии.');
        }

        $result = $this->schedule_future_sync();
        if (is_wp_error($result)) {
            set_transient(self::LAST_NOTICE, ['type' => 'error', 'message' => $result->get_error_message()], 60);
            return;
        }

        set_transient(self::LAST_NOTICE, ['type' => 'success', 'message' => sprintf('Настройки сохранены. Будущие бронирования поставлены в очередь синхронизации. Найдено: %d.', (int) $result)], 60);
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Недостаточно прав.');
        }

        $options = self::options();
        $is_pro = $this->is_pro();
        $statuses = $this->available_order_statuses();
        ?>
        <div class="wrap wss-wcb-yc-page">
            <h1>WSS Yandex Calendar for WooCommerce Bookings</h1>

            <?php $this->render_inline_notice(); ?>

            <?php if (!$is_pro) : ?>
                <div class="notice notice-info inline"><p><strong>Free-версия.</strong> В бесплатной версии доступны один Яндекс.Календарь, проверка подключения и отправка новых активных бронирований со статусами заказа «Обработка» и «Выполнен». Первичная синхронизация будущих броней, шаблоны, настраиваемые статусы, пометка отменённых событий и очистка связей доступны в Pro.</p></div>
            <?php else : ?>
                <div class="notice notice-success inline"><p><strong>Pro-версия активна.</strong> Доступны первичная синхронизация, шаблоны, настройка статусов и обработка отмен.</p></div>
            <?php endif; ?>

            <?php if (!class_exists('WC_Booking')) : ?>
                <div class="notice notice-warning"><p>Плагин WooCommerce Bookings не обнаружен. Настройки можно сохранить, но синхронизация начнёт работать только после активации WooCommerce Bookings.</p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('wss_wcb_yc_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Синхронизация</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked($options['enabled'], '1'); ?>>
                                Включить отправку будущих бронирований в Яндекс.Календарь
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wss-wcb-yc-email">Email Яндекса</label></th>
                        <td><input id="wss-wcb-yc-email" class="regular-text" type="email" name="<?php echo esc_attr(self::OPTION); ?>[email]" value="<?php echo esc_attr($options['email']); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wss-wcb-yc-password">Пароль приложения</label></th>
                        <td>
                            <input id="wss-wcb-yc-password" class="regular-text" type="password" name="<?php echo esc_attr(self::OPTION); ?>[app_password]" value="" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять">
                            <?php if (!empty($options['app_password'])) : ?>
                                <p class="description">Пароль приложения уже сохранён. Для замены введите новый.</p>
                            <?php else : ?>
                                <p class="description">Пароль приложения ещё не сохранён. Вставьте пароль и нажмите «Сохранить настройки» перед проверкой подключения.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wss-wcb-yc-caldav">CalDAV URL календаря</label></th>
                        <td>
                            <input id="wss-wcb-yc-caldav" class="large-text" type="url" name="<?php echo esc_attr(self::OPTION); ?>[caldav_url]" value="<?php echo esc_attr($options['caldav_url']); ?>" placeholder="https://caldav.yandex.ru/calendars/.../">
                            <p class="description">Вставьте URL конкретного календаря. События будут записываться в этот один календарь.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wss-wcb-yc-timezone">Часовой пояс</label></th>
                        <td><input id="wss-wcb-yc-timezone" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[timezone]" value="<?php echo esc_attr($options['timezone']); ?>" placeholder="Europe/Moscow"></td>
                    </tr>
                    <tr>
                        <th scope="row">Статусы заказов</th>
                        <td>
                            <?php if ($is_pro) : ?>
                                <?php foreach ($statuses as $key => $label) : ?>
                                    <label style="display:block;margin:0 0 6px;">
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[active_statuses][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $options['active_statuses'], true)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description">Если заказ выходит из этих статусов или бронь отменена, ранее созданное событие будет оставлено с пометкой [ОТМЕНЕНО].</p>
                            <?php else : ?>
                                <p><strong>Обработка</strong> и <strong>Выполнен</strong>.</p>
                                <p class="description">Изменение списка статусов и корректная пометка отменённых событий доступны в Pro.</p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($is_pro) : ?>
                        <tr>
                            <th scope="row">Первичная синхронизация</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auto_sync_after_save]" value="1" <?php checked($options['auto_sync_after_save'], '1'); ?>>
                                    После сохранения настроек поставить в очередь будущие бронирования за сегодня и дальше
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wss-wcb-yc-batch">Размер пакета</label></th>
                            <td><input id="wss-wcb-yc-batch" class="small-text" type="number" min="5" max="200" name="<?php echo esc_attr(self::OPTION); ?>[batch_size]" value="<?php echo esc_attr((string) $options['batch_size']); ?>"> бронирований</td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wss-wcb-yc-title">Шаблон заголовка</label></th>
                            <td>
                                <input id="wss-wcb-yc-title" class="large-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[title_template]" value="<?php echo esc_attr($options['title_template']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wss-wcb-yc-desc">Шаблон описания</label></th>
                            <td>
                                <textarea id="wss-wcb-yc-desc" class="large-text code" rows="12" name="<?php echo esc_attr(self::OPTION); ?>[description_template]"><?php echo esc_textarea($options['description_template']); ?></textarea>
                                <p class="description">Доступные переменные: {booking_id}, {order_id}, {product_name}, {persons}, {persons_label}, {customer_name}, {customer_phone}, {customer_email}, {order_status}, {booking_status}, {start_datetime}, {end_datetime}, {admin_order_url}, {admin_booking_url}.</p>
                            </td>
                        </tr>
                    <?php else : ?>
                        <tr>
                            <th scope="row">Pro-функции</th>
                            <td>
                                <p>В Pro доступны первичная синхронизация будущих броней, размер пакета, шаблоны заголовка/описания, выбор статусов заказов, обработка отмен и очистка связей.</p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php do_action('wss_wcb_yc_after_settings_fields', $options, $is_pro); ?>
                </table>

                <?php submit_button('Сохранить настройки'); ?>
            </form>

            <hr>

            <h2>Действия</h2>
            <p>Используйте кнопки после сохранения email, пароля приложения и CalDAV URL.</p>

            <div class="wss-wcb-yc-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                    <input type="hidden" name="action" value="wss_wcb_yc_test_connection">
                    <?php wp_nonce_field('wss_wcb_yc_test_connection'); ?>
                    <?php submit_button('Проверить подключение', 'secondary', 'submit', false); ?>
                </form>

                <?php if ($is_pro) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="wss_wcb_yc_sync_future">
                        <?php wp_nonce_field('wss_wcb_yc_sync_future'); ?>
                        <?php submit_button('Синхронизировать будущие бронирования', 'primary', 'submit', false); ?>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;" onsubmit="return confirm('Удалить только связь booking → Yandex UID? События в Яндекс.Календаре не будут удалены.');">
                        <input type="hidden" name="action" value="wss_wcb_yc_clear_links">
                        <?php wp_nonce_field('wss_wcb_yc_clear_links'); ?>
                        <?php submit_button('Очистить связь у броней', 'secondary', 'submit', false); ?>
                    </form>
                <?php else : ?>
                    <button type="button" class="button button-primary" disabled>Синхронизировать будущие бронирования — Pro</button>
                    <button type="button" class="button" disabled>Очистить связь у броней — Pro</button>
                <?php endif; ?>
            </div>
            <p class="description">Кнопки используют уже сохранённые настройки. Если вы только что вставили email, пароль или CalDAV URL, сначала нажмите «Сохранить настройки».</p>
            <?php do_action('wss_wcb_yc_after_actions', $options, $is_pro); ?>

            <h2>Как работает отмена</h2>
            <p>В Free-версии отправляются новые активные бронирования. В Pro-версии событие не удаляется из календаря: заголовок обновляется с префиксом <code>[ОТМЕНЕНО]</code>. Если позже появится новая активная бронь на то же время, она будет создана отдельным событием, потому что UID привязан к ID бронирования.</p>
        </div>
        <?php
    }

    public function admin_notices(): void
    {
        $notice = $this->get_current_notice();
        if (!$notice) {
            return;
        }

        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice['type']), esc_html($notice['message']));
    }

    private function render_inline_notice(): void
    {
        $notice = $this->get_current_notice();
        if (!$notice) {
            return;
        }

        printf('<div class="notice notice-%s inline is-dismissible"><p>%s</p></div>', esc_attr($notice['type']), esc_html($notice['message']));
    }

    private function get_current_notice(): ?array
    {
        static $notice = null;
        static $loaded = false;

        if ($loaded) {
            return $notice;
        }

        $loaded = true;
        $notice = null;

        if (isset($_GET['wss_wcb_yc_notice'], $_GET['wss_wcb_yc_notice_type'])) {
            $type = sanitize_key(wp_unslash($_GET['wss_wcb_yc_notice_type']));
            $message = sanitize_text_field(wp_unslash($_GET['wss_wcb_yc_notice']));
            if ($message !== '') {
                $notice = [
                    'type'    => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
                    'message' => $message,
                ];
                return $notice;
            }
        }

        $transient_notice = get_transient(self::LAST_NOTICE);
        if ($transient_notice && is_array($transient_notice)) {
            delete_transient(self::LAST_NOTICE);
            $type = sanitize_key($transient_notice['type'] ?? 'info');
            $message = sanitize_text_field($transient_notice['message'] ?? '');
            if ($message !== '') {
                $notice = [
                    'type'    => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
                    'message' => $message,
                ];
            }
        }

        return $notice;
    }

    public function handle_test_connection(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Недостаточно прав.');
        }
        check_admin_referer('wss_wcb_yc_test_connection');

        $client = $this->client();
        if (is_wp_error($client)) {
            $this->redirect_notice('error', $client->get_error_message());
        }

        $result = $client->test_connection();
        if (is_wp_error($result)) {
            $this->redirect_notice('error', $result->get_error_message());
        }

        $this->redirect_notice('success', 'Подключение к Яндекс.Календарю успешно проверено.');
    }

    public function handle_manual_sync_future(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Недостаточно прав.');
        }
        check_admin_referer('wss_wcb_yc_sync_future');

        if (!$this->is_pro()) {
            $this->redirect_notice('warning', 'Первичная синхронизация будущих бронирований доступна в Pro-версии.');
        }

        $result = $this->schedule_future_sync();
        if (is_wp_error($result)) {
            $this->redirect_notice('error', $result->get_error_message());
        }

        $this->redirect_notice('success', sprintf('Будущие бронирования поставлены в очередь синхронизации. Найдено: %d.', (int) $result));
    }

    public function handle_clear_links(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Недостаточно прав.');
        }
        check_admin_referer('wss_wcb_yc_clear_links');

        if (!$this->is_pro()) {
            $this->redirect_notice('warning', 'Очистка связей доступна в Pro-версии.');
        }

        $query = new WP_Query([
            'post_type'      => 'wc_booking',
            'post_status'    => 'any',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_wss_yandex_calendar_uid',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $count = 0;
        foreach ($query->posts as $booking_id) {
            delete_post_meta($booking_id, '_wss_yandex_calendar_uid');
            delete_post_meta($booking_id, '_wss_yandex_calendar_event_url');
            delete_post_meta($booking_id, '_wss_yandex_calendar_etag');
            delete_post_meta($booking_id, '_wss_yandex_calendar_synced_at');
            delete_post_meta($booking_id, '_wss_yandex_calendar_cancelled');
            $count++;
        }

        $this->redirect_notice('success', sprintf('Связь с Яндекс UID очищена у %d бронирований. События в календаре не удалялись.', $count));
    }

    public function queue_booking_sync_from_save(int $post_id, WP_Post $post, bool $update): void
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        $this->queue_booking_sync($post_id);
    }

    public function queue_booking_sync_from_status_change(string $new_status, string $old_status, WP_Post $post): void
    {
        if ($post->post_type !== 'wc_booking') {
            return;
        }
        $this->queue_booking_sync((int) $post->ID);
    }

    public function queue_order_bookings_sync(int $order_id, string $old_status, string $new_status, $order): void
    {
        $booking_ids = $this->get_booking_ids_by_order_id($order_id);
        foreach ($booking_ids as $booking_id) {
            $this->queue_booking_sync($booking_id);
        }
    }

    public function queue_booking_sync($booking_id): void
    {
        $booking_id = $this->normalize_booking_id($booking_id);
        if (!$booking_id || !$this->is_enabled()) {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('wss_wcb_yc_sync_booking', [$booking_id], 'wss-wcb-yandex-calendar');
            return;
        }

        if (!wp_next_scheduled('wss_wcb_yc_sync_booking', [$booking_id])) {
            wp_schedule_single_event(time() + 15, 'wss_wcb_yc_sync_booking', [$booking_id]);
        }
    }

    private function normalize_booking_id($booking_id): int
    {
        if (is_object($booking_id) && method_exists($booking_id, 'get_id')) {
            return absint($booking_id->get_id());
        }
        if (is_object($booking_id) && isset($booking_id->ID)) {
            return absint($booking_id->ID);
        }
        return absint($booking_id);
    }

    public function sync_booking($booking_id)
    {
        $booking_id = $this->normalize_booking_id($booking_id);
        if (!$booking_id || !$this->is_enabled()) {
            return new WP_Error('wss_wcb_yc_disabled', 'Синхронизация выключена.');
        }

        $booking = $this->get_booking($booking_id);
        if (!$booking) {
            return new WP_Error('wss_wcb_yc_booking_not_found', 'Бронирование не найдено.');
        }

        $start_ts = $this->get_booking_start_ts($booking, $booking_id);
        if (!$this->is_current_or_future($start_ts)) {
            return new WP_Error('wss_wcb_yc_past_booking', 'Прошедшие бронирования не синхронизируются.');
        }

        $order_id = $this->get_booking_order_id($booking, $booking_id);
        $order = $order_id ? wc_get_order($order_id) : false;
        $order_status = $order ? $order->get_status() : '';
        $booking_status = $this->get_booking_status($booking, $booking_id);

        $has_existing_event = (bool) get_post_meta($booking_id, '_wss_yandex_calendar_uid', true);
        $is_booking_cancelled = in_array($booking_status, ['cancelled', 'trash'], true);
        $is_order_active = $order && in_array($order_status, self::options()['active_statuses'], true);

        if (!$is_order_active && !$has_existing_event) {
            return new WP_Error('wss_wcb_yc_not_active', 'У бронирования нет активного заказа и ещё нет события в календаре.');
        }

        $cancelled = $is_booking_cancelled || !$is_order_active;
        if ($cancelled && !$this->is_pro()) {
            return new WP_Error('wss_wcb_yc_cancel_pro_required', 'Пометка отменённых событий доступна в Pro-версии.');
        }
        $event = $this->build_event_data($booking, $booking_id, $order, $cancelled);

        $client = $this->client();
        if (is_wp_error($client)) {
            $this->log_sync_result($booking_id, $client);
            return $client;
        }

        $result = $client->put_event($event['uid'], WSS_WCB_YC_ICS_Builder::build($event));
        $this->log_sync_result($booking_id, $result);

        if (is_wp_error($result)) {
            return $result;
        }

        update_post_meta($booking_id, '_wss_yandex_calendar_uid', $event['uid']);
        update_post_meta($booking_id, '_wss_yandex_calendar_event_url', $result['url'] ?? '');
        update_post_meta($booking_id, '_wss_yandex_calendar_etag', $result['etag'] ?? '');
        update_post_meta($booking_id, '_wss_yandex_calendar_synced_at', current_time('mysql'));
        update_post_meta($booking_id, '_wss_yandex_calendar_cancelled', $cancelled ? '1' : '0');

        return true;
    }

    public function schedule_future_sync()
    {
        if (!$this->is_pro()) {
            return new WP_Error('wss_wcb_yc_pro_required', 'Первичная синхронизация будущих бронирований доступна в Pro-версии.');
        }

        if (!$this->is_enabled()) {
            return new WP_Error('wss_wcb_yc_disabled', 'Синхронизация выключена.');
        }

        $options = self::options();
        if (!$this->has_required_settings($options)) {
            return new WP_Error('wss_wcb_yc_missing_settings', 'Заполните Email Яндекса, пароль приложения и CalDAV URL календаря.');
        }

        $today_start = $this->today_start_booking_value();
        $batch = $options['batch_size'];
        $paged = 1;
        $total = 0;

        do {
            $query = new WP_Query([
                'post_type'      => 'wc_booking',
                'post_status'    => 'any',
                'posts_per_page' => $batch,
                'paged'          => $paged,
                'fields'         => 'ids',
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
                'meta_key'       => '_booking_start',
                'meta_query'     => [
                    [
                        'key'     => '_booking_start',
                        'value'   => $today_start,
                        'compare' => '>=',
                        'type'    => 'NUMERIC',
                    ],
                ],
            ]);

            foreach ($query->posts as $booking_id) {
                $this->queue_booking_sync((int) $booking_id);
                $total++;
            }

            $paged++;
        } while ($query->max_num_pages >= $paged);

        return $total;
    }

    public function sync_future_batch($offset = 0): void
    {
        $this->schedule_future_sync();
    }

    private function build_event_data($booking, int $booking_id, $order, bool $cancelled): array
    {
        $start_ts = $this->get_booking_start_ts($booking, $booking_id);
        $end_ts   = $this->get_booking_end_ts($booking, $booking_id);
        if ($end_ts <= $start_ts) {
            $end_ts = $start_ts + HOUR_IN_SECONDS;
        }

        $product_name = $this->get_booking_product_name($booking, $booking_id);
        $persons = $this->get_booking_persons_count($booking, $booking_id);
        $persons_label = $this->plural_ru($persons, 'билет', 'билета', 'билетов');

        $order_id = $order ? $order->get_id() : $this->get_booking_order_id($booking, $booking_id);
        $customer_name = $this->get_customer_name($order);
        $customer_phone = $order ? $order->get_billing_phone() : '';
        $customer_email = $order ? $order->get_billing_email() : '';
        $order_status = $order ? wc_get_order_status_name($order->get_status()) : '';
        $booking_status = $this->get_booking_status($booking, $booking_id);

        $admin_order_url = $order_id ? $this->get_admin_order_url($order_id) : '';
        $admin_booking_url = admin_url('post.php?post=' . $booking_id . '&action=edit');

        $replacements = [
            '{booking_id}'       => (string) $booking_id,
            '{order_id}'         => (string) $order_id,
            '{product_name}'     => $product_name,
            '{persons}'          => (string) $persons,
            '{persons_label}'    => $persons_label,
            '{customer_name}'    => $customer_name,
            '{customer_phone}'   => $customer_phone,
            '{customer_email}'   => $customer_email,
            '{order_status}'     => $order_status,
            '{booking_status}'   => $booking_status,
            '{start_datetime}'   => $this->format_datetime($start_ts),
            '{end_datetime}'     => $this->format_datetime($end_ts),
            '{admin_order_url}'  => $admin_order_url,
            '{admin_booking_url}' => $admin_booking_url,
        ];

        $options = self::options();
        $summary = strtr($options['title_template'], $replacements);
        $description = strtr($options['description_template'], $replacements);

        if ($cancelled && mb_stripos($summary, '[ОТМЕНЕНО]', 0, 'UTF-8') === false) {
            $summary = '[ОТМЕНЕНО] ' . $summary;
        }

        return [
            'uid'         => $this->event_uid($booking_id),
            'summary'     => $summary,
            'description' => $description,
            'start_ts'    => $start_ts,
            'end_ts'      => $end_ts,
            'timezone'    => $this->event_timezone_string(),
            'cancelled'   => $cancelled,
        ];
    }

    private function is_pro(): bool
    {
        return self::is_pro_available();
    }

    private function available_order_statuses(): array
    {
        if (function_exists('wc_get_order_statuses')) {
            $statuses = [];
            foreach (wc_get_order_statuses() as $key => $label) {
                $statuses[$this->normalize_order_status_key($key)] = $label;
            }
            return $statuses;
        }

        return [
            'processing' => 'Обработка',
            'completed'  => 'Выполнен',
        ];
    }

    private function normalize_order_status_key($status): string
    {
        $status = sanitize_key((string) $status);
        if (strpos($status, 'wc-') === 0) {
            $status = substr($status, 3);
        }
        return $status;
    }

    private function client()
    {
        $options = self::options();
        if (!$this->has_required_settings($options)) {
            return new WP_Error('wss_wcb_yc_missing_settings', 'Заполните Email Яндекса, пароль приложения и CalDAV URL календаря.');
        }
        return new WSS_WCB_YC_CalDAV_Client($options['caldav_url'], $options['email'], $options['app_password']);
    }

    private function has_required_settings(array $options): bool
    {
        return !empty($options['email']) && !empty($options['app_password']) && !empty($options['caldav_url']);
    }

    private function is_enabled(): bool
    {
        $options = self::options();
        return $options['enabled'] === '1';
    }

    private function get_booking($booking_id)
    {
        if (class_exists('WC_Booking')) {
            try {
                return new WC_Booking($booking_id);
            } catch (Throwable $e) {
                return false;
            }
        }
        return get_post($booking_id);
    }

    private function get_booking_start_ts($booking, int $booking_id): int
    {
        // WooCommerce Bookings stores _booking_start as a local wall-clock value,
        // for example 20260604180000. WC_Booking::get_start('U') may already be
        // shifted to UTC on some installations, which caused Yandex to show +3 hours.
        // Therefore we prefer the raw booking meta and parse it in the configured timezone.
        $raw = get_post_meta($booking_id, '_booking_start', true);
        $timestamp = $this->parse_booking_datetime($raw);
        if ($timestamp > 0) {
            return $timestamp;
        }

        if (is_object($booking) && method_exists($booking, 'get_start')) {
            $value = $booking->get_start('U');
            if (is_numeric($value)) {
                return (int) $value;
            }
            $parsed = strtotime((string) $value);
            if ($parsed) {
                return $parsed;
            }
        }

        return 0;
    }

    private function get_booking_end_ts($booking, int $booking_id): int
    {
        $raw = get_post_meta($booking_id, '_booking_end', true);
        $timestamp = $this->parse_booking_datetime($raw);
        if ($timestamp > 0) {
            return $timestamp;
        }

        if (is_object($booking) && method_exists($booking, 'get_end')) {
            $value = $booking->get_end('U');
            if (is_numeric($value)) {
                return (int) $value;
            }
            $parsed = strtotime((string) $value);
            if ($parsed) {
                return $parsed;
            }
        }

        return 0;
    }

    private function parse_booking_datetime($raw): int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 0;
        }
        if (preg_match('/^\d{14}$/', $raw)) {
            $dt = DateTime::createFromFormat('!YmdHis', $raw, $this->event_timezone());
            return $dt ? $dt->getTimestamp() : 0;
        }
        if (preg_match('/^\d{8}$/', $raw)) {
            $dt = DateTime::createFromFormat('!Ymd', $raw, $this->event_timezone());
            return $dt ? $dt->getTimestamp() : 0;
        }
        if (is_numeric($raw)) {
            return (int) $raw;
        }
        $parsed = strtotime($raw);
        return $parsed ?: 0;
    }

    private function is_current_or_future(int $start_ts): bool
    {
        if ($start_ts <= 0) {
            return false;
        }
        $today = new DateTime('today', $this->event_timezone());
        return $start_ts >= $today->getTimestamp();
    }

    private function today_start_booking_value(): string
    {
        $today = new DateTime('today', $this->event_timezone());
        return $today->format('Ymd000000');
    }

    private function event_timezone_string(): string
    {
        $options = self::options();
        $timezone = trim((string) ($options['timezone'] ?? ''));
        if ($timezone === '') {
            $timezone = wp_timezone_string() ?: 'Europe/Moscow';
        }

        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (Throwable $e) {
            return 'Europe/Moscow';
        }
    }

    private function event_timezone(): DateTimeZone
    {
        return new DateTimeZone($this->event_timezone_string());
    }

    private function get_booking_order_id($booking, int $booking_id): int
    {
        if (is_object($booking) && method_exists($booking, 'get_order_id')) {
            $order_id = absint($booking->get_order_id());
            if ($order_id) {
                return $order_id;
            }
        }

        $order_id = absint(get_post_meta($booking_id, '_booking_order_id', true));
        if ($order_id) {
            return $order_id;
        }

        $order_item_id = absint(get_post_meta($booking_id, '_booking_order_item_id', true));
        if ($order_item_id && function_exists('wc_get_order_id_by_order_item_id')) {
            return absint(wc_get_order_id_by_order_item_id($order_item_id));
        }

        $post = get_post($booking_id);
        if ($post && $post->post_parent) {
            $parent = absint($post->post_parent);
            if (function_exists('wc_get_order') && wc_get_order($parent)) {
                return $parent;
            }
        }

        return 0;
    }

    private function get_booking_ids_by_order_id(int $order_id): array
    {
        $ids = [];

        $query = new WP_Query([
            'post_type'      => 'wc_booking',
            'post_status'    => 'any',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'   => '_booking_order_id',
                    'value' => $order_id,
                ],
                [
                    'key'   => '_order_id',
                    'value' => $order_id,
                ],
            ],
        ]);
        $ids = array_merge($ids, array_map('absint', $query->posts));

        $parent_query = new WP_Query([
            'post_type'      => 'wc_booking',
            'post_status'    => 'any',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'post_parent'    => $order_id,
        ]);
        $ids = array_merge($ids, array_map('absint', $parent_query->posts));

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if ($order) {
            foreach ($order->get_items() as $item_id => $item) {
                $booking_id = absint($item->get_meta('_booking_id', true));
                if ($booking_id) {
                    $ids[] = $booking_id;
                }

                foreach ($item->get_meta_data() as $meta) {
                    $key = is_object($meta) && method_exists($meta, 'get_data') ? ($meta->get_data()['key'] ?? '') : '';
                    $value = is_object($meta) && method_exists($meta, 'get_data') ? ($meta->get_data()['value'] ?? '') : '';
                    if (in_array($key, ['_booking_id', 'booking_id', 'Booking ID'], true) && is_numeric($value)) {
                        $ids[] = absint($value);
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function get_booking_status($booking, int $booking_id): string
    {
        if (is_object($booking) && method_exists($booking, 'get_status')) {
            return sanitize_key($booking->get_status());
        }
        $post = get_post($booking_id);
        return $post ? sanitize_key($post->post_status) : '';
    }

    private function get_booking_product_name($booking, int $booking_id): string
    {
        if (is_object($booking) && method_exists($booking, 'get_product')) {
            $product = $booking->get_product();
            if ($product && is_object($product) && method_exists($product, 'get_name')) {
                return $product->get_name();
            }
        }

        $product_id = 0;
        if (is_object($booking) && method_exists($booking, 'get_product_id')) {
            $product_id = absint($booking->get_product_id());
        }
        if (!$product_id) {
            $product_id = absint(get_post_meta($booking_id, '_booking_product_id', true));
        }

        if ($product_id) {
            $product = function_exists('wc_get_product') ? wc_get_product($product_id) : false;
            if ($product && method_exists($product, 'get_name')) {
                return $product->get_name();
            }
            return get_the_title($product_id);
        }

        return 'Бронирование';
    }

    private function get_booking_persons_count($booking, int $booking_id): int
    {
        $persons = [];
        if (is_object($booking) && method_exists($booking, 'get_person_counts')) {
            $persons = $booking->get_person_counts();
        }
        if (!$persons) {
            $persons = get_post_meta($booking_id, '_booking_persons', true);
        }

        if (is_array($persons)) {
            $sum = 0;
            foreach ($persons as $count) {
                $sum += absint($count);
            }
            return max(1, $sum);
        }

        $persons = absint($persons);
        return max(1, $persons);
    }

    private function get_customer_name($order): string
    {
        if (!$order) {
            return '';
        }
        $name = trim($order->get_formatted_billing_full_name());
        if ($name === '') {
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }
        return $name;
    }

    private function event_uid(int $booking_id): string
    {
        $existing = get_post_meta($booking_id, '_wss_yandex_calendar_uid', true);
        if ($existing) {
            return sanitize_text_field($existing);
        }
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'site';
        return 'wss-booking-' . $booking_id . '-' . substr(md5(home_url()), 0, 8) . '@' . sanitize_title($host);
    }


    private function get_admin_order_url(int $order_id): string
    {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            try {
                if (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                    return admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
                }
            } catch (Throwable $e) {
                // Fallback ниже.
            }
        }

        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    private function format_datetime(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        return wp_date('d.m.Y H:i', $timestamp, $this->event_timezone());
    }

    private function plural_ru(int $number, string $one, string $few, string $many): string
    {
        $n = abs($number) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 === 1) {
            return $one;
        }
        return $many;
    }

    private function log_sync_result(int $booking_id, $result): void
    {
        if (is_wp_error($result)) {
            update_post_meta($booking_id, '_wss_yandex_calendar_last_error', $result->get_error_message());
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('Yandex Calendar sync error for booking #' . $booking_id . ': ' . $result->get_error_message(), ['source' => 'wss-wcb-yandex-calendar']);
            }
            return;
        }

        delete_post_meta($booking_id, '_wss_yandex_calendar_last_error');
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info('Yandex Calendar sync success for booking #' . $booking_id, ['source' => 'wss-wcb-yandex-calendar']);
        }
    }

    private function redirect_notice(string $type, string $message): void
    {
        $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
        $message = wp_strip_all_tags($message);

        set_transient(self::LAST_NOTICE, ['type' => $type, 'message' => $message], 60);

        $url = add_query_arg(
            [
                'page' => 'wss-wcb-yandex-calendar',
                'wss_wcb_yc_notice_type' => $type,
                'wss_wcb_yc_notice' => rawurlencode($message),
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}
