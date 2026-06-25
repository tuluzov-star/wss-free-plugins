<?php
/**
 * Plugin Name: WSS Order Status Colors for WooCommerce
 * Plugin URI: https://website-support.ru/plugins/order-status-colors-for-woocommerce/
 * Description: Цветовое выделение заказов WooCommerce в админке в зависимости от статуса заказа.
 * Version: 1.1.1
 * Author: WSS
 * Author URI: https://website-support.ru/
 * Text Domain: wss-order-status-colors
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSS_OSC_VERSION', '1.1.1' );
define( 'WSS_OSC_FILE', __FILE__ );
define( 'WSS_OSC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSS_OSC_URL', plugin_dir_url( __FILE__ ) );
require_once WSS_OSC_DIR . 'includes/class-wss-osc-updater.php';

add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

final class WSS_Order_Status_Colors {
	public const OPTION_COLORS  = 'wss_osc_colors';
	public const OPTION_BUTTONS = 'wss_osc_buttons';
	public const PAGE_SLUG      = 'wss-order-status-colors';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		if ( is_admin() && class_exists( 'WSS_OSC_Updater' ) ) {
			new WSS_OSC_Updater(
				WSS_OSC_FILE,
				WSS_OSC_VERSION,
				'https://website-support.ru/plugins/order-status-colors-for-woocommerce/'
			);
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'wss-order-status-colors', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public function plugin_action_links( array $links ): array {
		$settings_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Настройки', 'wss-order-status-colors' ) . '</a>' );

		return $links;
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Цвета заказов', 'wss-order-status-colors' ),
			__( 'Цвета заказов', 'wss-order-status-colors' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function handle_settings_save(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( empty( $_REQUEST['wss_osc_action'] ) ) {
			do_action( 'wss_osc_handle_settings_save' );
			return;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['wss_osc_action'] ) );

		if ( 'save_colors' === $action ) {
			$this->save_colors();
			return;
		}

		if ( 'save_buttons' === $action ) {
			$this->save_buttons();
			return;
		}

		do_action( 'wss_osc_handle_settings_save' );
	}

	private function save_colors(): void {
		check_admin_referer( 'wss_osc_save_colors', 'wss_osc_nonce' );

		$submitted = isset( $_POST['wss_osc_colors'] ) && is_array( $_POST['wss_osc_colors'] )
			? wp_unslash( $_POST['wss_osc_colors'] )
			: array();

		$colors = array();
		foreach ( $submitted as $status_key => $row ) {
			$status_key = $this->normalize_status_key( (string) $status_key );
			if ( ! $status_key || ! is_array( $row ) ) {
				continue;
			}

			$background = isset( $row['background'] ) ? sanitize_hex_color( $row['background'] ) : '';
			$text       = isset( $row['text'] ) ? sanitize_hex_color( $row['text'] ) : '';
			$enabled    = ! empty( $row['enabled'] );

			if ( ! $enabled || ! $background || ! $text ) {
				continue;
			}

			$colors[ $status_key ] = array(
				'background' => $background,
				'text'       => $text,
			);
		}

		update_option( self::OPTION_COLORS, $colors, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'tab'     => 'colors',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function save_buttons(): void {
		check_admin_referer( 'wss_osc_save_buttons', 'wss_osc_buttons_nonce' );

		$defaults  = $this->get_default_button_styles();
		$submitted = isset( $_POST['wss_osc_buttons'] ) && is_array( $_POST['wss_osc_buttons'] )
			? wp_unslash( $_POST['wss_osc_buttons'] )
			: array();

		$buttons = array(
			'enabled'          => ! empty( $submitted['enabled'] ) ? 1 : 0,
			'background'       => isset( $submitted['background'] ) ? sanitize_hex_color( $submitted['background'] ) : '',
			'text'             => isset( $submitted['text'] ) ? sanitize_hex_color( $submitted['text'] ) : '',
			'border'           => isset( $submitted['border'] ) ? sanitize_hex_color( $submitted['border'] ) : '',
			'hover_background' => isset( $submitted['hover_background'] ) ? sanitize_hex_color( $submitted['hover_background'] ) : '',
			'hover_text'       => isset( $submitted['hover_text'] ) ? sanitize_hex_color( $submitted['hover_text'] ) : '',
			'border_radius'    => isset( $submitted['border_radius'] ) ? min( 40, max( 0, absint( $submitted['border_radius'] ) ) ) : (int) $defaults['border_radius'],
		);

		foreach ( array( 'background', 'text', 'border', 'hover_background', 'hover_text' ) as $key ) {
			if ( empty( $buttons[ $key ] ) ) {
				$buttons[ $key ] = $defaults[ $key ];
			}
		}

		update_option( self::OPTION_BUTTONS, $buttons, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'tab'     => 'buttons',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! $this->is_orders_admin_screen() ) {
			return;
		}

		$colors = $this->get_colors();
		if ( ! $colors ) {
			return;
		}

		wp_enqueue_script(
			'wss-osc-admin',
			WSS_OSC_URL . 'assets/admin.js',
			array(),
			WSS_OSC_VERSION,
			true
		);

		wp_add_inline_script(
			'wss-osc-admin',
			'window.WSS_OSC = ' . wp_json_encode(
				array(
					'colors'  => $colors,
					'buttons' => $this->get_button_styles(),
				)
			) . ';',
			'before'
		);

		$css = '
			.wp-list-table tr.wss-osc-colored-row > th,
			.wp-list-table tr.wss-osc-colored-row > td {
				background-color: var(--wss-osc-bg);
				color: var(--wss-osc-text);
				transition: background-color .15s ease;
			}
			.wp-list-table tr.wss-osc-colored-row > th a:not(.button),
			.wp-list-table tr.wss-osc-colored-row > td a:not(.button),
			.wp-list-table tr.wss-osc-colored-row .row-actions a:not(.button) {
				color: var(--wss-osc-text);
			}
			.wp-list-table tr.wss-osc-colored-row .order-status,
			.wp-list-table tr.wss-osc-colored-row .order-status span {
				color: var(--wss-osc-text);
			}
			.wp-list-table tr.wss-osc-buttons-styled > th .button,
			.wp-list-table tr.wss-osc-buttons-styled > td .button,
			.wp-list-table tr.wss-osc-buttons-styled > th a.button,
			.wp-list-table tr.wss-osc-buttons-styled > td a.button,
			.wp-list-table tr.wss-osc-buttons-styled > th button.button,
			.wp-list-table tr.wss-osc-buttons-styled > td button.button,
			.wp-list-table tr.wss-osc-buttons-styled > th input.button,
			.wp-list-table tr.wss-osc-buttons-styled > td input.button,
			.wp-list-table tr.wss-osc-buttons-styled > td button:not(.toggle-row):not(.components-button),
			.wp-list-table tr.wss-osc-buttons-styled > td a[class*="button"] {
				background: var(--wss-osc-button-bg);
				border-color: var(--wss-osc-button-border);
				color: var(--wss-osc-button-text);
				border-radius: var(--wss-osc-button-radius);
				box-shadow: none;
				text-shadow: none;
			}
			.wp-list-table tr.wss-osc-buttons-styled > th .button:hover,
			.wp-list-table tr.wss-osc-buttons-styled > td .button:hover,
			.wp-list-table tr.wss-osc-buttons-styled > th a.button:hover,
			.wp-list-table tr.wss-osc-buttons-styled > td a.button:hover,
			.wp-list-table tr.wss-osc-buttons-styled > th button.button:hover,
			.wp-list-table tr.wss-osc-buttons-styled > td button.button:hover,
			.wp-list-table tr.wss-osc-buttons-styled > th input.button:hover,
			.wp-list-table tr.wss-osc-buttons-styled > td input.button:hover,
			.wp-list-table tr.wss-osc-buttons-styled > td button:not(.toggle-row):not(.components-button):hover,
			.wp-list-table tr.wss-osc-buttons-styled > td a[class*="button"]:hover,
			.wp-list-table tr.wss-osc-buttons-styled > th .button:focus,
			.wp-list-table tr.wss-osc-buttons-styled > td .button:focus {
				background: var(--wss-osc-button-hover-bg);
				border-color: var(--wss-osc-button-hover-bg);
				color: var(--wss-osc-button-hover-text);
				box-shadow: 0 0 0 1px rgba(0,0,0,.08);
			}
		';
		wp_register_style( 'wss-osc-admin-inline', false, array(), WSS_OSC_VERSION );
		wp_enqueue_style( 'wss-osc-admin-inline' );
		wp_add_inline_style( 'wss-osc-admin-inline', $css );
	}

	private function is_orders_admin_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		$id        = (string) $screen->id;
		$base      = (string) $screen->base;
		$post_type = isset( $screen->post_type ) ? (string) $screen->post_type : '';

		if ( 'shop_order' === $post_type && ( 'edit' === $base || 'post' === $base ) ) {
			return true;
		}

		return 'woocommerce_page_wc-orders' === $id
			|| false !== strpos( $id, 'wc-orders' )
			|| false !== strpos( $base, 'wc-orders' );
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wss-order-status-colors' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'colors';
		$tabs = array(
			'colors'  => __( 'Цвета статусов', 'wss-order-status-colors' ),
			'buttons' => __( 'Кнопки в заказах', 'wss-order-status-colors' ),
		);
		$tabs = apply_filters( 'wss_osc_settings_tabs', $tabs );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'colors';
		}

		echo '<div class="wrap wss-osc-settings">';
		echo '<h1>' . esc_html__( 'Цвета заказов WooCommerce', 'wss-order-status-colors' ) . '</h1>';

		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Настройки сохранены.', 'wss-order-status-colors' ) . '</p></div>';
		}

		if ( ! $this->is_woocommerce_active() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooCommerce не активен. Настройки будут доступны после активации WooCommerce.', 'wss-order-status-colors' ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$url = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $key,
				),
				admin_url( 'admin.php' )
			);
			echo '<a class="nav-tab ' . esc_attr( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		if ( 'colors' === $tab ) {
			$this->render_colors_tab();
		} elseif ( 'buttons' === $tab ) {
			$this->render_buttons_tab();
		} else {
			do_action( 'wss_osc_render_settings_tab', $tab );
		}

		echo '</div>';
	}

	private function render_colors_tab(): void {
		$statuses = $this->get_order_statuses();
		$colors   = $this->get_colors();
		$defaults = $this->get_default_colors();

		echo '<p>' . esc_html__( 'В бесплатной версии можно назначить цвета для уже существующих статусов заказов. Создание и удаление собственных статусов доступно в Pro-версии.', 'wss-order-status-colors' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=colors' ) ) . '">';
		wp_nonce_field( 'wss_osc_save_colors', 'wss_osc_nonce' );
		echo '<input type="hidden" name="wss_osc_action" value="save_colors">';

		echo '<table class="widefat striped" style="max-width: 920px;">';
		echo '<thead><tr>';
		echo '<th style="width: 80px;">' . esc_html__( 'Включить', 'wss-order-status-colors' ) . '</th>';
		echo '<th>' . esc_html__( 'Статус', 'wss-order-status-colors' ) . '</th>';
		echo '<th style="width: 170px;">' . esc_html__( 'Цвет фона', 'wss-order-status-colors' ) . '</th>';
		echo '<th style="width: 170px;">' . esc_html__( 'Цвет текста', 'wss-order-status-colors' ) . '</th>';
		echo '<th style="width: 190px;">' . esc_html__( 'Пример', 'wss-order-status-colors' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $statuses as $status_key => $status_label ) {
			$status_key = $this->normalize_status_key( (string) $status_key );
			$row        = $colors[ $status_key ] ?? $defaults[ $status_key ] ?? array(
				'background' => '#ffffff',
				'text'       => '#1d2327',
			);
			$enabled    = isset( $colors[ $status_key ] ) || isset( $defaults[ $status_key ] );
			$background = sanitize_hex_color( $row['background'] ?? '' ) ?: '#ffffff';
			$text       = sanitize_hex_color( $row['text'] ?? '' ) ?: '#1d2327';

			echo '<tr>';
			echo '<td><label><input type="checkbox" name="wss_osc_colors[' . esc_attr( $status_key ) . '][enabled]" value="1" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Да', 'wss-order-status-colors' ) . '</label></td>';
			echo '<td><strong>' . esc_html( $status_label ) . '</strong><br><code>' . esc_html( $status_key ) . '</code></td>';
			echo '<td><input type="color" name="wss_osc_colors[' . esc_attr( $status_key ) . '][background]" value="' . esc_attr( $background ) . '"></td>';
			echo '<td><input type="color" name="wss_osc_colors[' . esc_attr( $status_key ) . '][text]" value="' . esc_attr( $text ) . '"></td>';
			echo '<td><span style="display:inline-block;min-width:130px;padding:7px 12px;border-radius:6px;background:' . esc_attr( $background ) . ';color:' . esc_attr( $text ) . ';border:1px solid rgba(0,0,0,.08);">' . esc_html( $status_label ) . '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Сохранить цвета', 'wss-order-status-colors' ) );
		echo '</form>';
	}

	private function render_buttons_tab(): void {
		$buttons = $this->get_button_styles();

		echo '<p>' . esc_html__( 'Эти настройки применяются к кнопкам внутри окрашенных строк на странице заказов: например, к кнопкам CRM, просмотра контакта или кастомных действий в колонках заказа.', 'wss-order-status-colors' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=buttons' ) ) . '" style="max-width: 760px;">';
		wp_nonce_field( 'wss_osc_save_buttons', 'wss_osc_buttons_nonce' );
		echo '<input type="hidden" name="wss_osc_action" value="save_buttons">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Включить стили кнопок', 'wss-order-status-colors' ) . '</th><td><label><input type="checkbox" name="wss_osc_buttons[enabled]" value="1" ' . checked( ! empty( $buttons['enabled'] ), true, false ) . '> ' . esc_html__( 'Окрашивать кнопки в строках заказов', 'wss-order-status-colors' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wss_osc_btn_bg">' . esc_html__( 'Фон кнопки', 'wss-order-status-colors' ) . '</label></th><td><input id="wss_osc_btn_bg" type="color" name="wss_osc_buttons[background]" value="' . esc_attr( $buttons['background'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wss_osc_btn_text">' . esc_html__( 'Цвет текста кнопки', 'wss-order-status-colors' ) . '</label></th><td><input id="wss_osc_btn_text" type="color" name="wss_osc_buttons[text]" value="' . esc_attr( $buttons['text'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wss_osc_btn_border">' . esc_html__( 'Цвет рамки', 'wss-order-status-colors' ) . '</label></th><td><input id="wss_osc_btn_border" type="color" name="wss_osc_buttons[border]" value="' . esc_attr( $buttons['border'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wss_osc_btn_hover_bg">' . esc_html__( 'Фон при наведении', 'wss-order-status-colors' ) . '</label></th><td><input id="wss_osc_btn_hover_bg" type="color" name="wss_osc_buttons[hover_background]" value="' . esc_attr( $buttons['hover_background'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wss_osc_btn_hover_text">' . esc_html__( 'Цвет текста при наведении', 'wss-order-status-colors' ) . '</label></th><td><input id="wss_osc_btn_hover_text" type="color" name="wss_osc_buttons[hover_text]" value="' . esc_attr( $buttons['hover_text'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wss_osc_btn_radius">' . esc_html__( 'Скругление, px', 'wss-order-status-colors' ) . '</label></th><td><input id="wss_osc_btn_radius" type="number" min="0" max="40" name="wss_osc_buttons[border_radius]" value="' . esc_attr( (string) $buttons['border_radius'] ) . '"></td></tr>';
		echo '</tbody></table>';

		$preview_style = 'display:inline-block;padding:8px 16px;border-radius:' . absint( $buttons['border_radius'] ) . 'px;background:' . esc_attr( $buttons['background'] ) . ';color:' . esc_attr( $buttons['text'] ) . ';border:1px solid ' . esc_attr( $buttons['border'] ) . ';text-decoration:none;';
		echo '<p><strong>' . esc_html__( 'Пример:', 'wss-order-status-colors' ) . '</strong> <span style="' . esc_attr( $preview_style ) . '">' . esc_html__( 'View Contact', 'wss-order-status-colors' ) . '</span></p>';

		submit_button( __( 'Сохранить настройки кнопок', 'wss-order-status-colors' ) );
		echo '</form>';
	}

	public function get_order_statuses(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			return wc_get_order_statuses();
		}

		return array(
			'wc-pending'    => __( 'Ожидает оплаты', 'wss-order-status-colors' ),
			'wc-processing' => __( 'Обработка', 'wss-order-status-colors' ),
			'wc-on-hold'    => __( 'На удержании', 'wss-order-status-colors' ),
			'wc-completed'  => __( 'Выполнен', 'wss-order-status-colors' ),
			'wc-cancelled'  => __( 'Отменён', 'wss-order-status-colors' ),
			'wc-refunded'   => __( 'Возвращён', 'wss-order-status-colors' ),
			'wc-failed'     => __( 'Не удался', 'wss-order-status-colors' ),
		);
	}

	public function get_colors(): array {
		$saved = get_option( self::OPTION_COLORS, null );
		if ( null === $saved || false === $saved ) {
			return $this->get_default_colors();
		}

		if ( ! is_array( $saved ) ) {
			return array();
		}

		$colors = array();
		foreach ( $saved as $status_key => $row ) {
			$status_key = $this->normalize_status_key( (string) $status_key );
			if ( ! $status_key || ! is_array( $row ) ) {
				continue;
			}
			$background = isset( $row['background'] ) ? sanitize_hex_color( $row['background'] ) : '';
			$text       = isset( $row['text'] ) ? sanitize_hex_color( $row['text'] ) : '';
			if ( $background && $text ) {
				$colors[ $status_key ] = array(
					'background' => $background,
					'text'       => $text,
				);
			}
		}

		return $colors;
	}

	public function get_default_colors(): array {
		return array(
			'wc-pending'        => array( 'background' => '#fff7ed', 'text' => '#9a3412' ),
			'wc-processing'     => array( 'background' => '#e0f2fe', 'text' => '#075985' ),
			'wc-on-hold'        => array( 'background' => '#fef9c3', 'text' => '#854d0e' ),
			'wc-completed'      => array( 'background' => '#dcfce7', 'text' => '#166534' ),
			'wc-cancelled'      => array( 'background' => '#f3f4f6', 'text' => '#374151' ),
			'wc-refunded'       => array( 'background' => '#ede9fe', 'text' => '#5b21b6' ),
			'wc-failed'         => array( 'background' => '#fee2e2', 'text' => '#991b1b' ),
			'wc-checkout-draft' => array( 'background' => '#f1f5f9', 'text' => '#334155' ),
		);
	}

	public function get_button_styles(): array {
		$defaults = $this->get_default_button_styles();
		$saved    = get_option( self::OPTION_BUTTONS, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$buttons  = wp_parse_args( $saved, $defaults );

		$clean = array(
			'enabled'          => ! empty( $buttons['enabled'] ) ? 1 : 0,
			'background'       => sanitize_hex_color( $buttons['background'] ?? '' ) ?: $defaults['background'],
			'text'             => sanitize_hex_color( $buttons['text'] ?? '' ) ?: $defaults['text'],
			'border'           => sanitize_hex_color( $buttons['border'] ?? '' ) ?: $defaults['border'],
			'hover_background' => sanitize_hex_color( $buttons['hover_background'] ?? '' ) ?: $defaults['hover_background'],
			'hover_text'       => sanitize_hex_color( $buttons['hover_text'] ?? '' ) ?: $defaults['hover_text'],
			'border_radius'    => min( 40, max( 0, absint( $buttons['border_radius'] ?? $defaults['border_radius'] ) ) ),
		);

		return $clean;
	}

	public function get_default_button_styles(): array {
		return array(
			'enabled'          => 0,
			'background'       => '#3157e7',
			'text'             => '#ffffff',
			'border'           => '#3157e7',
			'hover_background' => '#2444bd',
			'hover_text'       => '#ffffff',
			'border_radius'    => 4,
		);
	}

	public function normalize_status_key( string $status_key ): string {
		$status_key = sanitize_key( $status_key );
		if ( '' === $status_key ) {
			return '';
		}

		if ( 0 !== strpos( $status_key, 'wc-' ) ) {
			$status_key = 'wc-' . $status_key;
		}

		return $status_key;
	}

	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'wc_get_order_statuses' );
	}
}

WSS_Order_Status_Colors::instance();
