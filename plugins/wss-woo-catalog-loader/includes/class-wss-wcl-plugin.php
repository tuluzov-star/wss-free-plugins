<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WSS_WCL_Plugin {
    private static $instance;

    public static function instance() {
        return self::$instance ?: self::$instance = new self();
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp', array( $this, 'configure_catalog_output' ), 20 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 30 );
        add_filter( 'plugin_action_links_' . plugin_basename( WSS_WCL_FILE ), array( $this, 'action_links' ) );
    }

    public function action_links( $links ) {
        array_unshift(
            $links,
            '<a href="' . esc_url( admin_url( 'admin.php?page=wss-wcl' ) ) . '">' . esc_html__( 'Settings', 'wss-woo-catalog-loader' ) . '</a>'
        );
        return $links;
    }

    public function register_settings() {
        register_setting(
            'wss_wcl',
            WSS_WCL_Settings::OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( 'WSS_WCL_Settings', 'sanitize' ),
                'default'           => WSS_WCL_Settings::defaults(),
            )
        );
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Catalog Loader', 'wss-woo-catalog-loader' ),
            __( 'Catalog Loader', 'wss-woo-catalog-loader' ),
            'manage_woocommerce',
            'wss-wcl',
            array( $this, 'settings_page' )
        );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $s = WSS_WCL_Settings::get();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WSS Woo Catalog Loader', 'wss-woo-catalog-loader' ); ?></h1>
            <p><?php esc_html_e( 'Modern catalog loading without jQuery. The plugin requests the next regular WooCommerce page and imports its product cards, which keeps classic archive templates, sorting and URL-based filters compatible.', 'wss-woo-catalog-loader' ); ?></p>
            <p><strong><?php esc_html_e( 'Compatibility:', 'wss-woo-catalog-loader' ); ?></strong> <?php esc_html_e( 'Version 1.1 targets classic WooCommerce product archives. Product Collection Blocks keep their native WooCommerce behavior and are not modified.', 'wss-woo-catalog-loader' ); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields( 'wss_wcl' ); ?>
                <table class="form-table" role="presentation">
                    <?php $this->checkbox( 'enabled', __( 'Enable', 'wss-woo-catalog-loader' ), $s, __( 'Enable the loader on WooCommerce product archives.', 'wss-woo-catalog-loader' ) ); ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Main mode', 'wss-woo-catalog-loader' ); ?></th>
                        <td>
                            <?php
                            $this->select(
                                'mode',
                                $s['mode'],
                                array(
                                    'infinite'   => __( 'Infinite scroll', 'wss-woo-catalog-loader' ),
                                    'load_more'  => __( 'Load More button', 'wss-woo-catalog-loader' ),
                                    'pagination' => __( 'AJAX pagination', 'wss-woo-catalog-loader' ),
                                )
                            );
                            ?>
                            <p class="description"><?php esc_html_e( 'Used on desktop and mobile unless the separate mobile mode is enabled.', 'wss-woo-catalog-loader' ); ?></p>
                        </td>
                    </tr>

                    <?php $this->checkbox( 'mobile_override', __( 'Separate mobile mode', 'wss-woo-catalog-loader' ), $s, __( 'Enable only when phones need a different loading method. By default mobile inherits the main mode.', 'wss-woo-catalog-loader' ) ); ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Mobile mode', 'wss-woo-catalog-loader' ); ?></th>
                        <td>
                            <?php
                            $this->select(
                                'mobile_mode',
                                $s['mobile_mode'],
                                array(
                                    'infinite'   => __( 'Infinite scroll', 'wss-woo-catalog-loader' ),
                                    'load_more'  => __( 'Load More button', 'wss-woo-catalog-loader' ),
                                    'pagination' => __( 'AJAX pagination', 'wss-woo-catalog-loader' ),
                                )
                            );
                            ?>
                            <p class="description"><?php esc_html_e( 'Applied only when the separate mobile mode is enabled.', 'wss-woo-catalog-loader' ); ?></p>
                        </td>
                    </tr>

                    <?php $this->number( 'mobile_breakpoint', __( 'Mobile breakpoint, px', 'wss-woo-catalog-loader' ), $s, 320, 2000 ); ?>
                    <?php $this->number( 'root_margin', __( 'Preload distance, px', 'wss-woo-catalog-loader' ), $s, 0, 3000, __( 'In Infinite Scroll mode, loading starts before the end of the catalog reaches the viewport.', 'wss-woo-catalog-loader' ) ); ?>
                    <?php $this->number( 'auto_pages', __( 'Automatic pages before button', 'wss-woo-catalog-loader' ), $s, 0, 50, __( '0 means unlimited automatic loading. A value greater than 0 switches Infinite Scroll to a Load More button after that many pages.', 'wss-woo-catalog-loader' ) ); ?>
                    <?php $this->checkbox( 'history', __( 'History API for AJAX pagination', 'wss-woo-catalog-loader' ), $s, __( 'Change the URL only in AJAX pagination mode. Infinite Scroll and Load More always keep the original catalog URL.', 'wss-woo-catalog-loader' ) ); ?>
                    <?php $this->checkbox( 'session_cache', __( 'Session cache', 'wss-woo-catalog-loader' ), $s, __( 'Cache fetched catalog pages in sessionStorage for the current browser tab.', 'wss-woo-catalog-loader' ) ); ?>
                    <?php $this->checkbox( 'hide_result_count', __( 'Hide result count', 'wss-woo-catalog-loader' ), $s, __( 'Hide text such as “Showing 1–12 of 404” in Infinite Scroll and Load More modes. AJAX pagination keeps and updates it.', 'wss-woo-catalog-loader' ) ); ?>

                    <?php
                    foreach (
                        array(
                            'button_text'  => __( 'Button text', 'wss-woo-catalog-loader' ),
                            'loading_text' => __( 'Loading text', 'wss-woo-catalog-loader' ),
                            'error_text'   => __( 'Error text', 'wss-woo-catalog-loader' ),
                            'retry_text'   => __( 'Retry text', 'wss-woo-catalog-loader' ),
                        ) as $key => $label
                    ) {
                        $this->text( $key, $label, $s );
                    }
                    ?>

                    <tr>
                        <th colspan="2">
                            <h2><?php esc_html_e( 'Theme selectors', 'wss-woo-catalog-loader' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'The defaults match classic WooCommerce markup. Change these only for a custom classic theme.', 'wss-woo-catalog-loader' ); ?></p>
                        </th>
                    </tr>
                    <?php
                    foreach (
                        array(
                            'products_selector'     => __( 'Products container', 'wss-woo-catalog-loader' ),
                            'product_selector'      => __( 'Product item', 'wss-woo-catalog-loader' ),
                            'pagination_selector'   => __( 'Pagination', 'wss-woo-catalog-loader' ),
                            'next_selector'         => __( 'Next page link', 'wss-woo-catalog-loader' ),
                            'result_count_selector' => __( 'Result count', 'wss-woo-catalog-loader' ),
                        ) as $key => $label
                    ) {
                        $this->text( $key, $label, $s );
                    }
                    ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function field_name( $key ) {
        return WSS_WCL_Settings::OPTION . '[' . $key . ']';
    }

    private function checkbox( $key, $label, $s, $desc = '' ) {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr( $this->field_name( $key ) ); ?>" value="1" <?php checked( $s[ $key ], 'yes' ); ?>>
                    <?php esc_html_e( 'Yes', 'wss-woo-catalog-loader' ); ?>
                </label>
                <?php if ( $desc ) : ?>
                    <p class="description"><?php echo esc_html( $desc ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function number( $key, $label, $s, $min, $max, $desc = '' ) {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <input class="small-text" type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" name="<?php echo esc_attr( $this->field_name( $key ) ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>">
                <?php if ( $desc ) : ?>
                    <p class="description"><?php echo esc_html( $desc ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function text( $key, $label, $s ) {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td><input class="regular-text" type="text" name="<?php echo esc_attr( $this->field_name( $key ) ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>"></td>
        </tr>
        <?php
    }

    private function select( $key, $value, $options ) {
        ?>
        <select name="<?php echo esc_attr( $this->field_name( $key ) ); ?>">
            <?php foreach ( $options as $option_value => $label ) : ?>
                <option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private function is_catalog() {
        if ( ! function_exists( 'is_shop' ) ) {
            return false;
        }

        if ( is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag() ) {
            return true;
        }

        if ( is_search() ) {
            $post_type = get_query_var( 'post_type' );
            return 'product' === $post_type || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) );
        }

        return false;
    }

    private function effective_modes( $s ) {
        $desktop = $s['mode'];
        $mobile  = ( 'yes' === ( $s['mobile_override'] ?? 'no' ) ) ? $s['mobile_mode'] : $desktop;
        return array( $desktop, $mobile );
    }

    public function configure_catalog_output() {
        if ( ! $this->is_catalog() ) {
            return;
        }

        $s = WSS_WCL_Settings::get();
        if ( 'yes' !== $s['enabled'] || 'yes' !== $s['hide_result_count'] ) {
            return;
        }

        list( $desktop_mode, $mobile_mode ) = $this->effective_modes( $s );

        if ( 'pagination' !== $desktop_mode && 'pagination' !== $mobile_mode ) {
            remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
            remove_action( 'woocommerce_after_shop_loop', 'woocommerce_result_count', 20 );
        }
    }

    private function mixed_mode_result_count_css( $s ) {
        if ( 'yes' !== $s['hide_result_count'] ) {
            return '';
        }

        list( $desktop_mode, $mobile_mode ) = $this->effective_modes( $s );
        $desktop_hides = 'pagination' !== $desktop_mode;
        $mobile_hides  = 'pagination' !== $mobile_mode;

        if ( $desktop_hides === $mobile_hides ) {
            return '';
        }

        $selector = trim( (string) $s['result_count_selector'] );
        if ( '' === $selector || preg_match( '/[{}<>]/', $selector ) ) {
            $selector = '.woocommerce-result-count';
        }

        $bp = max( 320, min( 2000, (int) $s['mobile_breakpoint'] ) );
        if ( $mobile_hides ) {
            return '@media (max-width:' . $bp . 'px){' . $selector . '{display:none!important}}';
        }

        return '@media (min-width:' . ( $bp + 1 ) . 'px){' . $selector . '{display:none!important}}';
    }

    public function enqueue() {
        if ( ! $this->is_catalog() ) {
            return;
        }

        $s = WSS_WCL_Settings::get();
        if ( 'yes' !== $s['enabled'] ) {
            return;
        }

        wp_enqueue_style( 'wss-wcl', WSS_WCL_URL . 'assets/css/catalog-loader.css', array(), WSS_WCL_VERSION );

        $early_count_css = $this->mixed_mode_result_count_css( $s );
        if ( '' !== $early_count_css ) {
            wp_add_inline_style( 'wss-wcl', $early_count_css );
        }

        wp_enqueue_script( 'wss-wcl', WSS_WCL_URL . 'assets/js/catalog-loader.js', array(), WSS_WCL_VERSION, true );
        wp_add_inline_script(
            'wss-wcl',
            'window.WSS_WCL=' . wp_json_encode(
                array(
                    'mode'                => $s['mode'],
                    'mobileOverride'      => 'yes' === $s['mobile_override'],
                    'mobileMode'          => $s['mobile_mode'],
                    'mobileBreakpoint'    => (int) $s['mobile_breakpoint'],
                    'rootMargin'          => (int) $s['root_margin'],
                    'autoPages'           => (int) $s['auto_pages'],
                    'history'             => 'yes' === $s['history'],
                    'sessionCache'        => 'yes' === $s['session_cache'],
                    'hideResultCount'     => 'yes' === $s['hide_result_count'],
                    'buttonText'          => $s['button_text'],
                    'loadingText'         => $s['loading_text'],
                    'errorText'           => $s['error_text'],
                    'retryText'           => $s['retry_text'],
                    'productsSelector'    => $s['products_selector'],
                    'productSelector'     => $s['product_selector'],
                    'paginationSelector'  => $s['pagination_selector'],
                    'nextSelector'        => $s['next_selector'],
                    'resultCountSelector' => $s['result_count_selector'],
                )
            ) . ';',
            'before'
        );
    }
}
