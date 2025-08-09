<?php
/**
 * Plugin Name: Account Menu Enhancer for HivePress
 * Description: Integrates HivePress user account menu with WooCommerce My Account menu, with custom menu items, visibility controls, and position management.
 * Version: 1.0
 * Author: Chris Bruce
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Requires at least: 6.0
 * Tested up to: 6.8.2
 * Requires PHP: 8.0
 * Text Domain: account-menu-enhancer-for-hivepress
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AMEHP_VERSION', '1.3.0' );
define( 'AMEHP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMEHP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

class AMEHP_Account_Menu_Enhancer {
    private static $instance = null;

    // Re-entrancy guards
    private $running_hp_filter = false;
    private $running_wc_filter = false;
    private $running_endpoint  = false;

    // Caches (per request)
    private $cache_hp_base = null; // normalized HP items (no customs)
    private $cache_wc_base = null; // native WC endpoint => label

    public static function get_instance() {
        if ( self::$instance === null ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Hook late to avoid stepping on core/plugin defaults.
        add_filter( 'hivepress/v1/menus/user_account', array( $this, 'filter_hivepress_menu' ), 999 );
        add_action( 'init', array( $this, 'attach_wc_hooks' ) );

        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'account-menu-enhancer-for-hivepress', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function on_activate() {
        if ( ! file_exists( AMEHP_PLUGIN_DIR . 'assets/css' ) ) { wp_mkdir_p( AMEHP_PLUGIN_DIR . 'assets/css' ); }
        if ( ! file_exists( AMEHP_PLUGIN_DIR . 'assets/js' ) )  { wp_mkdir_p( AMEHP_PLUGIN_DIR . 'assets/js' ); }
    }

    public function attach_wc_hooks() {
        if ( class_exists( 'WooCommerce' ) ) {
            add_filter( 'woocommerce_account_menu_items', array( $this, 'filter_wc_menu' ), 999 );
            add_filter( 'woocommerce_get_endpoint_url', array( $this, 'filter_wc_endpoint_url' ), 10, 4 );
        }
    }

    /* ---------------- Settings UI ---------------- */

    public function add_settings_page() {
        add_options_page(
            __( 'Account Menu Enhancer for HivePress', 'account-menu-enhancer-for-hivepress' ),
            __( 'Account Menu Enhancer', 'account-menu-enhancer-for-hivepress' ),
            'manage_options',
            'amehp-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'amehp_settings_group', 'amehp_settings', array(
            'sanitize_callback' => array( $this, 'sanitize_settings' ),
        ) );

        add_settings_section( 'amehp_main', __( 'Menu Enhancer Settings', 'account-menu-enhancer-for-hivepress' ), '__return_false', 'amehp-settings' );

        add_settings_field( 'enable_integration', __( 'Enable Integration', 'account-menu-enhancer-for-hivepress' ), array( $this, 'field_enable_integration' ), 'amehp-settings', 'amehp_main' );
        add_settings_field( 'enable_custom_only', __( 'Enable Custom Links Only', 'account-menu-enhancer-for-hivepress' ), array( $this, 'field_enable_custom_only' ), 'amehp-settings', 'amehp_main' );

        add_settings_field( 'wc_hide', __( 'Hide WooCommerce Menu Items', 'account-menu-enhancer-for-hivepress' ), array( $this, 'field_wc_hide' ), 'amehp-settings', 'amehp_main' );

        add_settings_field( 'custom_menu_items', __( 'Custom Menu Items', 'account-menu-enhancer-for-hivepress' ), array( $this, 'field_custom_items' ), 'amehp-settings', 'amehp_main' );
    }

    public function field_enable_integration() {
        $opt = get_option( 'amehp_settings', array() );
        $val = isset( $opt['enable_integration'] ) ? (int) $opt['enable_integration'] : 1;
        echo '<label><input type="checkbox" name="amehp_settings[enable_integration]" value="1" ' . checked( 1, $val, false ) . '> ' . esc_html__( 'Enable full menu integration between HivePress and WooCommerce.', 'account-menu-enhancer-for-hivepress' ) . '</label>';
    }

    public function field_enable_custom_only() {
        $opt = get_option( 'amehp_settings', array() );
        $val = isset( $opt['enable_custom_only'] ) ? (int) $opt['enable_custom_only'] : 0;
        echo '<label><input type="checkbox" name="amehp_settings[enable_custom_only]" value="1" ' . checked( 1, $val, false ) . '> ' . esc_html__( 'Enable custom links independent of menu integration.', 'account-menu-enhancer-for-hivepress' ) . '</label>';
        echo '<br><small>' . esc_html__( 'Use custom links without merging the native menus.', 'account-menu-enhancer-for-hivepress' ) . '</small>';
    }

    public function field_wc_hide() {
        $opt = get_option( 'amehp_settings', array() );
        $hide = isset( $opt['woocommerce_items_to_hide'] ) ? (array) $opt['woocommerce_items_to_hide'] : array();
        $endpoints = array(
            'dashboard'       => __( 'Dashboard', 'woocommerce' ),
            'orders'          => __( 'Orders', 'woocommerce' ),
            'subscriptions'   => __( 'Subscriptions', 'woocommerce' ),
            'downloads'       => __( 'Downloads', 'woocommerce' ),
            'edit-address'    => __( 'Addresses', 'woocommerce' ),
            'payment-methods' => __( 'Payment Methods', 'woocommerce' ),
            'edit-account'    => __( 'Account Details', 'woocommerce' ),
            'customer-logout' => __( 'Logout', 'woocommerce' ),
        );
        echo '<p>' . esc_html__( 'Select WooCommerce menu items to hide:', 'account-menu-enhancer-for-hivepress' ) . '</p>';
        foreach ( $endpoints as $ep => $label ) {
            echo '<label><input type="checkbox" name="amehp_settings[woocommerce_items_to_hide][]" value="' . esc_attr( $ep ) . '" ' . checked( in_array( $ep, $hide, true ), true, false ) . '> ' . esc_html( $label ) . '</label><br>';
        }
    }

    public function field_custom_items() {
        $opt    = get_option( 'amehp_settings', array() );
        $items  = isset( $opt['custom_menu_items'] ) ? (array) $opt['custom_menu_items'] : array();
        $roles  = wp_roles()->get_names();
        $routes = $this->get_hivepress_routes_shortlist();

        echo '<div id="amehp-custom-menu-items">';
        echo '<p>' . esc_html__( 'Add custom menu items to HivePress and/or WooCommerce menus:', 'account-menu-enhancer-for-hivepress' ) . '</p>';
        echo '<div class="amehp-menu-items">';

        foreach ( $items as $i => $item ) {
            $this->render_custom_item_fields( $i, $item, $roles, $routes );
        }

        echo '</div>';
        echo '<button type="button" class="button amehp-add-menu-item">' . esc_html__( 'Add New Menu Item', 'account-menu-enhancer-for-hivepress' ) . '</button>';
        echo '</div>';

        echo '<script type="text/template" id="amehp-menu-item-template">';
        $this->render_custom_item_fields( '{{INDEX}}', array(), $roles, $routes );
        echo '</script>';
    }

    private function render_custom_item_fields( $index, $item, $roles, $routes ) {
        $label     = isset( $item['label'] ) ? $item['label'] : '';
        $type      = isset( $item['type'] ) ? $item['type'] : 'url';
        $url       = isset( $item['url'] ) ? $item['url'] : '';
        $route     = isset( $item['route'] ) ? $item['route'] : '';
        $page_id   = isset( $item['page_id'] ) ? (int) $item['page_id'] : 0;
        $menu      = isset( $item['menu'] ) ? $item['menu'] : 'both';
        $position  = isset( $item['position'] ) ? (int) $item['position'] : 10;
        $sel_roles = isset( $item['roles'] ) ? (array) $item['roles'] : array();

        $show_url   = ( $type === 'url' ) ? '' : 'style="display:none"';
        $show_route = ( $type === 'hivepress_route' ) ? '' : 'style="display:none"';
        $show_page  = ( $type === 'page' ) ? '' : 'style="display:none"';

        echo '<div class="amehp-menu-item" style="border:1px solid #ddd;padding:15px;margin-bottom:15px;">';

        echo '<p><label>' . esc_html__( 'Label:', 'account-menu-enhancer-for-hivepress' ) . '</label> ';
        echo '<input type="text" name="amehp_settings[custom_menu_items][' . esc_attr( $index ) . '][label]" value="' . esc_attr( $label ) . '" required></p>';

        echo '<p><label>' . esc_html__( 'Type:', 'account-menu-enhancer-for-hivepress' ) . '</label> ';
        echo '<select name="amehp_settings[custom_menu_items][' . esc_attr( $index ) . '][type]" class="amehp-type-field">';
        echo '<option value="url"' . selected( $type, 'url', false ) . '>' . esc_html__( 'URL', 'account-menu-enhancer-for-hivepress' ) . '</option>';
        echo '<option value="hivepress_route"' . selected( $type, 'hivepress_route', false ) . '>' . esc_html__( 'HivePress Route', 'account-menu-enhancer-for-hivepress' ) . '</option>';
        echo '<option value="page"' . selected( $type, 'page', false ) . '>' . esc_html__( 'Page', 'account-menu-enhancer-for-hivepress' ) . '</option>';
        echo '</select></p>';

        echo '<p class="amehp-url-field" ' . $show_url . '><label>' . esc_html__( 'URL:', 'account-menu-enhancer-for-hivepress' ) . '</label> ';
        echo '<input type="text" name="amehp_settings[custom_menu_items][' . esc_attr( $index ) . '][url]" value="' . esc_attr( $url ) . '"></p>';

        echo '<p class="amehp-route-field" ' . $show_route . '><label>' . esc_html__( 'HivePress Route:', 'account-menu-enhancer-for-hivepress' ) . '</label> ';
        echo '<select name="amehp_settings[custom_menu_items][' . esc_attr( $index ) . '][route]" class="amehp-route-select">';
        echo '<option value="">' . esc_html__( 'Select a route', 'account-menu-enhancer-for-hivepress' ) . '</option>';
        foreach ( $routes as $r_key => $r_label ) {
            echo '<option value="' . esc_attr( $r_key ) . '"' . selected( $route, $r_key, false ) . '>' . esc_html( $r_label ) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="amehp-page-field" ' . $show_page . '><label>' . esc_html__( 'Select Page:', 'account-menu-enhancer-for-hivepress' ) . '</label> ';
        wp_dropdown_pages( array(
            'name'             => 'amehp_settings[custom_menu_items][' . esc_attr( $index ) . '][page_id]',
            'selected'         => $page_id,
            'show_option_none' => __( 'Select a page', 'account-menu-enhancer-for-hivepress' ),
            'class'            => 'amehp-page-select',
        ) );
        echo '</p>';

        echo '<p><label>' . esc_html__( 'Menu:', 'account-menu-enhancer-for-hivepress' ) . '</label> ';
        echo '<select name="amehp_settings[custom_menu_items][' . esc_attr( $index ) . '][menu]">';
        echo '<option value="hivepress"' . selected( $menu, 'hivepress', false ) . '>' . esc_html__( 'HivePress User Menu Only', 'account-menu-enhancer-for-hivepress' ) . '</option>';
        echo '<option value="woocommerce"' . selected( $menu, 'woocommerce', false ) . '>' . esc_html__( 'WooCommerce My Account Only', 'account-menu-enhancer-for-hivepress' ) . '</option>';
        echo '<option value="both"' . selected( $menu, 'both', false ) . '>' . esc_html__( 'Both Menus', 'account-menu-enhancer-for-hivepress' ) . '</option>';
        echo '</select></p>';

        echo '<p><label>' . esc_html__( 'Position:', 'account-menu-enhancer-for-hivepress' ) . '</label> ';
        echo '<input type="number" name="amehp_settings[custom_menu_items][' . esc_attr( $index ) . '][position]" value="' . esc_attr( $position ) . '" min="0" step="10"></p>';

        echo '<p><strong>' . esc_html__( 'Visible to User Roles:', 'account-menu-enhancer-for-hivepress' ) . '</strong><br>';
        foreach ( $roles as $rk => $rn ) {
            echo '<label><input type="checkbox" name="amehp_settings[custom_menu_items][' . esc_attr( $index ) . '][roles][]" value="' . esc_attr( $rk ) . '" ' . checked( in_array( $rk, $sel_roles, true ), true, false ) . '> ' . esc_html( $rn ) . '</label><br>';
        }
        echo '<small>' . esc_html__( 'Leave unchecked to show to all roles.', 'account-menu-enhancer-for-hivepress' ) . '</small></p>';

        echo '<button type="button" class="button amehp-remove-menu-item">' . esc_html__( 'Remove Menu Item', 'account-menu-enhancer-for-hivepress' ) . '</button>';

        echo '</div>';
    }

    public function sanitize_settings( $input ) {
        if ( ! isset( $_POST['amehp_nonce'] ) || ! wp_verify_nonce( $_POST['amehp_nonce'], 'amehp_settings_save' ) ) {
            return get_option( 'amehp_settings', array() );
        }

        $out = array();
        $out['enable_integration'] = ! empty( $input['enable_integration'] ) ? 1 : 0;
        $out['enable_custom_only'] = ! empty( $input['enable_custom_only'] ) ? 1 : 0;

        // WC hides
        $out['woocommerce_items_to_hide'] = array();
        if ( isset( $input['woocommerce_items_to_hide'] ) && is_array( $input['woocommerce_items_to_hide'] ) ) {
            foreach ( $input['woocommerce_items_to_hide'] as $v ) {
                $out['woocommerce_items_to_hide'][] = sanitize_text_field( $v );
            }
        }

        // Custom items
        $out['custom_menu_items'] = array();
        if ( isset( $input['custom_menu_items'] ) && is_array( $input['custom_menu_items'] ) ) {
            foreach ( $input['custom_menu_items'] as $i => $item ) {
                $label = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
                if ( $label === '' ) { continue; }

                $type  = isset( $item['type'] ) ? sanitize_text_field( $item['type'] ) : 'url';
                $menu  = isset( $item['menu'] ) ? sanitize_text_field( $item['menu'] ) : 'both';
                $pos   = isset( $item['position'] ) ? absint( $item['position'] ) : 10;
                $roles = isset( $item['roles'] ) ? array_map( 'sanitize_text_field', (array) $item['roles'] ) : array();

                $menu = $this->normalize_target_value( $menu );

                $clean = array(
                    'label'    => $label,
                    'type'     => in_array( $type, array( 'url', 'hivepress_route', 'page' ), true ) ? $type : 'url',
                    'menu'     => $menu,
                    'position' => $pos,
                    'roles'    => $roles,
                );

                if ( $clean['type'] === 'url' ) {
                    $u = isset( $item['url'] ) ? trim( (string) $item['url'] ) : '';
                    if ( $u === '' || ! filter_var( $u, FILTER_VALIDATE_URL ) ) { continue; }
                    $clean['url'] = esc_url_raw( $u );
                } elseif ( $clean['type'] === 'page' ) {
                    $pid = isset( $item['page_id'] ) ? absint( $item['page_id'] ) : 0;
                    if ( ! $pid ) { continue; }
                    $clean['page_id'] = $pid;
                } else {
                    $route = isset( $item['route'] ) ? sanitize_text_field( $item['route'] ) : '';
                    if ( $route === '' ) { continue; }
                    $clean['route'] = $route;
                }

                $out['custom_menu_items'][ $i ] = $clean;
            }
        }

        return $out;
    }

    public function render_settings_page() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Account Menu Enhancer for HivePress', 'account-menu-enhancer-for-hivepress' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'amehp_settings_group' );
        wp_nonce_field( 'amehp_settings_save', 'amehp_nonce' );
        do_settings_sections( 'amehp-settings' );
        submit_button( __( 'Save Settings', 'account-menu-enhancer-for-hivepress' ) );
        echo '</form></div>';
    }

    public function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'settings_page_amehp-settings' ) { return; }
        wp_enqueue_style( 'amehp-admin-css', AMEHP_PLUGIN_URL . 'assets/css/admin.css', array(), AMEHP_VERSION );
        wp_enqueue_script( 'amehp-admin-js', AMEHP_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), AMEHP_VERSION, true );
    }

    /* ---------------- Core: Menu Filters ---------------- */

    public function filter_hivepress_menu( $menu ) {
        if ( $this->running_hp_filter ) { return $menu; }
        $this->running_hp_filter = true;

        if ( ! is_array( $menu ) ) { $menu = array(); }
        if ( ! isset( $menu['items'] ) || ! is_array( $menu['items'] ) ) { $menu['items'] = array(); }

        $opt         = get_option( 'amehp_settings', array() );
        $integrate   = ! empty( $opt['enable_integration'] );
        $custom_only = ! empty( $opt['enable_custom_only'] );

        if ( ! $integrate && ! $custom_only ) {
            $this->running_hp_filter = false;
            return $menu; // leave native HP alone
        }

        // Base HP (raw, no filters)
        $hp = $this->get_hp_base_items();

        // Seed with HP items (normalized)
        $result = array();
        foreach ( $hp as $key => $item ) {
            $result[ $key ] = $item; // label, url, order
        }

        // Custom items for HP or both
        $customs = $this->get_custom_items();
        foreach ( $customs as $cid => $ci ) {
            $target = $this->normalize_target_value( isset( $ci['menu'] ) ? $ci['menu'] : 'both' );
            if ( $target === 'woocommerce' ) { continue; }
            if ( ! $this->user_can_see( $ci ) ) { continue; }
            $url = $this->resolve_custom_url( $ci );
            if ( $url === '' ) { continue; }
            $result[ 'custom-' . $cid ] = array(
                'label' => $ci['label'],
                'url'   => $url,
                'order' => isset( $ci['position'] ) ? (int) $ci['position'] : 999,
            );
        }

        // Merge Woo natives into HP only if Integration ON
        if ( $integrate && function_exists( 'wc_get_account_menu_items' ) ) {
            $wc_hide = isset( $opt['woocommerce_items_to_hide'] ) ? (array) $opt['woocommerce_items_to_hide'] : array();
            $wc = $this->get_wc_base_items();
            foreach ( $wc as $ep => $label ) {
                if ( in_array( $ep, $wc_hide, true ) ) { continue; }
                if ( isset( $result[ $ep ] ) ) { continue; }
                $result[ $ep ] = array(
                    'label' => $label,
                    'url'   => wc_get_account_endpoint_url( $ep ),
                    'order' => 600,
                );
            }
        }

        uasort( $result, array( $this, 'cmp_order' ) );
        $menu['items'] = $result;

        $this->running_hp_filter = false;
        return $menu;
    }

    public function filter_wc_menu( $items ) {
        if ( $this->running_wc_filter ) { return $items; }
        $this->running_wc_filter = true;

        $opt         = get_option( 'amehp_settings', array() );
        $integrate   = ! empty( $opt['enable_integration'] );
        $custom_only = ! empty( $opt['enable_custom_only'] );
        $wc_hide     = isset( $opt['woocommerce_items_to_hide'] ) ? (array) $opt['woocommerce_items_to_hide'] : array();

        if ( ! $integrate && ! $custom_only ) {
            $this->running_wc_filter = false;
            return $items; // native WC
        }

        // Start from current WC, drop hidden, and drop any stray custom-* that arrived from elsewhere
        $merged = array();
        foreach ( (array) $items as $ep => $label ) {
            if ( in_array( $ep, $wc_hide, true ) ) { continue; }
            if ( strpos( $ep, 'custom-' ) === 0 ) { continue; } // scrub
            $merged[ $ep ] = array( 'label' => $label, 'position' => 500 );
        }

        // Merge HP natives only if Integration ON (raw HP only, no customs)
        if ( $integrate && class_exists( '\HivePress\Menus\User_Account' ) && function_exists( 'hivepress' ) ) {
            $hp = $this->get_hp_base_items();
            $hp_pos = 100;
            foreach ( $hp as $key => $item ) {
                if ( isset( $merged[ $key ] ) ) { continue; }
                $label = isset( $item['label'] ) ? $item['label'] : $key;
                $merged[ $key ] = array(
                    'label'    => $label,
                    'position' => ( isset( $item['order'] ) ? (int) $item['order'] : $hp_pos ),
                );
                $hp_pos += 10;
            }
        }

        // Inject custom items meant for Woo or both
        $customs = $this->get_custom_items();
        $custom_pos = 300;
        foreach ( $customs as $cid => $ci ) {
            $target = $this->normalize_target_value( isset( $ci['menu'] ) ? $ci['menu'] : 'both' );
            if ( $target === 'hivepress' ) { continue; } // HP-only: do NOT add to WC
            if ( ! $this->user_can_see( $ci ) ) { continue; }
            $url = $this->resolve_custom_url( $ci );
            if ( $url === '' ) { continue; }

            $ep = 'custom-' . $cid;
            $merged[ $ep ] = array(
                'label'    => $ci['label'],
                'position' => isset( $ci['position'] ) ? (int) $ci['position'] : $custom_pos,
            );
            $custom_pos += 10;
        }

        // Final scrub: ensure HP-only customs never remain
        foreach ( $customs as $cid => $ci ) {
            $target = $this->normalize_target_value( isset( $ci['menu'] ) ? $ci['menu'] : 'both' );
            if ( $target === 'hivepress' ) {
                $ep = 'custom-' . $cid;
                if ( isset( $merged[ $ep ] ) ) { unset( $merged[ $ep ] ); }
            }
        }

        // Sort and return endpoint => label
        uasort( $merged, array( $this, 'cmp_position' ) );
        $final = array();
        foreach ( $merged as $ep => $row ) {
            $final[ $ep ] = $row['label'];
        }

        $this->running_wc_filter = false;
        return $final;
    }

    public function filter_wc_endpoint_url( $url, $endpoint, $value, $permalink ) {
        if ( $this->running_endpoint ) { return $url; }
        $this->running_endpoint = true;

        $opt     = get_option( 'amehp_settings', array() );
        $customs = isset( $opt['custom_menu_items'] ) ? (array) $opt['custom_menu_items'] : array();

        // Map custom-* to their real URL, but ONLY if targeted to WC or Both
        if ( strpos( $endpoint, 'custom-' ) === 0 ) {
            $idx = (int) str_replace( 'custom-', '', $endpoint );
            if ( isset( $customs[ $idx ] ) ) {
                $item   = $customs[ $idx ];
                $target = $this->normalize_target_value( isset( $item['menu'] ) ? $item['menu'] : 'both' );
                if ( $target === 'woocommerce' || $target === 'both' ) {
                    if ( $this->user_can_see( $item ) ) {
                        $real = $this->resolve_custom_url( $item );
                        if ( $real !== '' ) {
                            $this->running_endpoint = false;
                            return $real;
                        }
                    }
                } else {
                    $this->running_endpoint = false;
                    return $url;
                }
            }
        }

        // If an HP endpoint was injected into WC (integration on), resolve its HP URL
        $hp = $this->get_hp_base_items();
        if ( isset( $hp[ $endpoint ] ) ) {
            $hpurl = isset( $hp[ $endpoint ]['url'] ) ? $hp[ $endpoint ]['url'] : '';
            if ( $hpurl !== '' ) {
                $this->running_endpoint = false;
                return $hpurl;
            }
        }

        $this->running_endpoint = false;
        return $url;
    }

    /* ---------------- Helpers ---------------- */

    private function normalize_target_value( $val ) {
        $v = is_string( $val ) ? strtolower( trim( $val ) ) : 'both';
        if ( $v !== 'hivepress' && $v !== 'woocommerce' && $v !== 'both' ) { $v = 'both'; }
        return $v;
    }

    // Get raw HP items (normalized) with our own HP filter detached; excludes any "custom-*" keys
    private function get_hp_base_items() {
        if ( $this->cache_hp_base !== null ) { return $this->cache_hp_base; }

        $items = array();
        if ( class_exists( '\HivePress\Menus\User_Account' ) && function_exists( 'hivepress' ) ) {
            remove_filter( 'hivepress/v1/menus/user_account', array( $this, 'filter_hivepress_menu' ), 999 );
            $hp  = new \HivePress\Menus\User_Account();
            $raw = method_exists( $hp, 'get_items' ) ? $hp->get_items() : array();
            add_filter( 'hivepress/v1/menus/user_account', array( $this, 'filter_hivepress_menu' ), 999 );

            foreach ( (array) $raw as $key => $item ) {
                if ( strpos( (string) $key, 'custom-' ) === 0 ) { continue; }
                $label = isset( $item['label'] ) ? $item['label'] : $key;
                $url   = '';
                if ( isset( $item['url'] ) && $item['url'] ) {
                    $url = $item['url'];
                } elseif ( isset( $item['route'] ) && $item['route'] && function_exists( 'hivepress' ) ) {
                    $url = hivepress()->router->get_url( $item['route'] );
                }
                if ( $url === '' ) { continue; }
                $order = isset( $item['_order'] ) ? (int) $item['_order'] : ( isset( $item['order'] ) ? (int) $item['order'] : 200 );
                $items[ $key ] = array(
                    'label' => $label,
                    'url'   => $url,
                    'order' => $order,
                );
            }
        }
        $this->cache_hp_base = $items;
        return $this->cache_hp_base;
    }

    // Get raw WC items (endpoint => label) with our WC filter detached
    private function get_wc_base_items() {
        if ( $this->cache_wc_base !== null ) { return $this->cache_wc_base; }

        $items = array();
        if ( function_exists( 'wc_get_account_menu_items' ) ) {
            remove_filter( 'woocommerce_account_menu_items', array( $this, 'filter_wc_menu' ), 999 );
            $raw = wc_get_account_menu_items();
            add_filter( 'woocommerce_account_menu_items', array( $this, 'filter_wc_menu' ), 999 );
            if ( is_array( $raw ) ) { $items = $raw; }
        }
        $this->cache_wc_base = $items;
        return $this->cache_wc_base;
    }

    private function get_custom_items() {
        $opt = get_option( 'amehp_settings', array() );
        return ( isset( $opt['custom_menu_items'] ) && is_array( $opt['custom_menu_items'] ) )
            ? $opt['custom_menu_items']
            : array();
    }

    private function user_can_see( $item ) {
        $roles_needed = isset( $item['roles'] ) ? (array) $item['roles'] : array();
        if ( empty( $roles_needed ) ) { return true; }
        $u = wp_get_current_user();
        if ( ! $u || ! $u->exists() ) { return false; }
        $user_roles = (array) $u->roles;
        if ( in_array( 'administrator', $user_roles, true ) ) { return true; }
        foreach ( $roles_needed as $r ) {
            if ( in_array( $r, $user_roles, true ) ) { return true; }
        }
        return false;
    }

    private function resolve_custom_url( $item ) {
        $type = isset( $item['type'] ) ? $item['type'] : 'url';
        if ( $type === 'url' ) {
            return isset( $item['url'] ) ? $item['url'] : '';
        }
        if ( $type === 'page' ) {
            $pid = isset( $item['page_id'] ) ? (int) $item['page_id'] : 0;
            if ( $pid > 0 ) {
                $p = get_permalink( $pid );
                return $p ? $p : '';
            }
            return '';
        }
        if ( $type === 'hivepress_route' && function_exists( 'hivepress' ) ) {
            $route = isset( $item['route'] ) ? $item['route'] : '';
            if ( $route === '' ) { return ''; }

            $params = array();

            // Vendor profile (official approach → vendor_id)
            if ( $route === 'vendor_view_page' && class_exists( '\HivePress\Models\Vendor' ) ) {
                $uid = get_current_user_id();
                if ( ! $uid ) { return ''; }
                $vendor_id = \HivePress\Models\Vendor::query()->filter( array( 'user' => $uid ) )->get_first_id();
                if ( ! $vendor_id ) { return ''; }
                $params = array( 'vendor_id' => $vendor_id );
            }

            // User profile: ask HP to build pretty URL via 'user' (ID). If not pretty, force /{base}/{username}/
            if ( $route === 'user_view_page' ) {
                $uid = get_current_user_id();
                if ( ! $uid ) { return ''; }
                $params = array( 'user' => $uid );
                $hp_url = hivepress()->router->get_url( 'user_view_page', $params );
                $pretty = $this->force_pretty_user_url_if_needed( $hp_url, $uid );
                return $pretty !== '' ? $pretty : $hp_url;
            }

            // Default route resolution
            $url = hivepress()->router->get_url( $route, $params );
            return $url ? $url : '';
        }
        return '';
    }

    // Build /{base}/{username}/ (username = user_login). Filter 'amehp_user_base_slug' to change base.
    private function force_pretty_user_url_if_needed( $url, $user_id ) {
        if ( is_string( $url ) && $url !== '' && strpos( $url, '?' ) === false ) {
            return $url; // already pretty
        }
        $u = get_userdata( $user_id );
        if ( ! $u ) {
            return is_string( $url ) ? $url : '';
        }
        $username = isset( $u->user_login ) ? $u->user_login : '';
        if ( $username === '' && isset( $u->user_nicename ) ) {
            $username = $u->user_nicename;
        }
        if ( $username === '' ) {
            return is_string( $url ) ? $url : '';
        }
        $base = apply_filters( 'amehp_user_base_slug', 'user' );
        $base = trim( (string) $base, '/' );
        return home_url( '/' . $base . '/' . rawurlencode( $username ) . '/' );
    }

    // Sorting helpers
    public function cmp_order( $a, $b ) {
        $oa = isset( $a['order'] ) ? (int) $a['order'] : 999;
        $ob = isset( $b['order'] ) ? (int) $b['order'] : 999;
        if ( $oa == $ob ) { return 0; }
        return ( $oa < $ob ) ? -1 : 1;
    }
    public function cmp_position( $a, $b ) {
        $pa = isset( $a['position'] ) ? (int) $a['position'] : 999;
        $pb = isset( $b['position'] ) ? (int) $b['position'] : 999;
        if ( $pa == $pb ) { return 0; }
        return ( $pa < $pb ) ? -1 : 1;
    }

    private function get_hivepress_routes_shortlist() {
        // Intentionally NOT including 'user_account_page' to avoid confusion.
        return array(
            'user_view_page'          => __( 'User Profile', 'account-menu-enhancer-for-hivepress' ),
            'user_edit_settings_page' => __( 'Edit Settings', 'account-menu-enhancer-for-hivepress' ),
            'user_logout_page'        => __( 'Log Out', 'account-menu-enhancer-for-hivepress' ),
            'vendor_view_page'        => __( 'Vendor Profile', 'account-menu-enhancer-for-hivepress' ),
            'listings_edit_page'      => __( 'Edit Listings', 'account-menu-enhancer-for-hivepress' ),
            'listings_favorite_page'  => __( 'Favorite Listings', 'account-menu-enhancer-for-hivepress' ),
        );
    }
}

AMEHP_Account_Menu_Enhancer::get_instance();
