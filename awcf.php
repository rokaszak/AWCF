<?php
/**
 * Plugin Name: Advanced Woo Checkout Fields
 * Plugin URI: https://proven.lt
 * Description: Provides advanced control over WooCommerce checkout fields including forced shipping address, field enable/disable/required toggles, and VAT compliance mode with dynamic company fields.
 * Version: 2.1.2
 * Author: Rokas Zakarauskas
 * Author URI: https://proven.lt
 * Text Domain: advanced-woo-checkout-fields
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'AWCF_VERSION', '2.0.0' );
define( 'AWCF_PLUGIN_FILE', __FILE__ );
define( 'AWCF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AWCF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AWCF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare HPOS compatibility
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Main plugin class
 */
final class AWCF {

    /**
     * Single instance of the class
     *
     * @var AWCF
     */
    private static $instance = null;

    /**
     * Plugin settings
     *
     * @var array
     */
    private $settings = null;

    /**
     * Get single instance of the class
     *
     * @return AWCF
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Check WooCommerce dependency
        add_action( 'plugins_loaded', array( $this, 'check_woocommerce' ), 1 );
        
        // Load text domain
        add_action( 'init', array( $this, 'load_textdomain' ) );
        
        // Plugin action links
        add_filter( 'plugin_action_links_' . AWCF_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
    }

    /**
     * Check if WooCommerce is active and initialize plugin
     */
    public function check_woocommerce() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        // Include required files
        $this->includes();
        
        // Initialize components
        $this->init_components();
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once AWCF_PLUGIN_DIR . 'includes/class-awcf-checkout.php';
        require_once AWCF_PLUGIN_DIR . 'includes/class-awcf-order-data.php';
        
        if ( is_admin() ) {
            require_once AWCF_PLUGIN_DIR . 'includes/class-awcf-admin.php';
        }
    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize checkout handler
        AWCF_Checkout::instance();
        
        // Initialize order data handler
        new AWCF_Order_Data();
        
        // Initialize admin
        if ( is_admin() ) {
            AWCF_Admin::instance();
        }
    }

    /**
     * Get default settings
     *
     * @return array
     */
    public function get_default_settings() {
        return array(
            // Section 1: Checkout Layout
            'force_ship_to_different'  => false,
            'billing_title'            => __( 'Billing details', 'woocommerce' ),
            'shipping_title'           => __( 'Ship to a different address?', 'woocommerce' ),
            'checkout_order'           => 'billing_first',
            
            // Section 2: Field Controls
            'fields'                   => array(),
            
            // Section 3: VAT Compliance
            'vat_mode_enabled'         => false,
            'vat_checkbox_label'       => __( 'Buying as a company?', 'advanced-woo-checkout-fields' ),
            'company_name_label'       => __( 'Company Name', 'advanced-woo-checkout-fields' ),
            'company_code_label'       => __( 'Company Code', 'advanced-woo-checkout-fields' ),
            'company_vat_label'        => __( 'Company VAT Code', 'advanced-woo-checkout-fields' ),
            'company_address_label'    => __( 'Company Address', 'advanced-woo-checkout-fields' ),
            'company_info_message'     => __( 'VAT Invoice will be generated automatically after the order and attached to the order email in PDF format.', 'advanced-woo-checkout-fields' ),
            
            // Section 4: Frontend Text (Translatable)
            'required_field_error'     => __( '<strong>%s</strong> is a required field.', 'woocommerce' ),
            'company_information_label' => __( 'Company Information', 'advanced-woo-checkout-fields' ),
        );
    }

    /**
     * Get plugin settings
     *
     * @return array
     */
    public function get_settings() {
        if ( is_null( $this->settings ) ) {
            $this->settings = get_option( 'awcf_settings', array() );
            $this->settings = wp_parse_args( $this->settings, $this->get_default_settings() );
        }
        return $this->settings;
    }

    /**
     * Get a specific setting
     *
     * @param string $key Setting key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function get_setting( $key, $default = null ) {
        $settings = $this->get_settings();
        
        if ( isset( $settings[ $key ] ) ) {
            return $settings[ $key ];
        }
        
        $defaults = $this->get_default_settings();
        if ( isset( $defaults[ $key ] ) ) {
            return $defaults[ $key ];
        }
        
        return $default;
    }

    /**
     * Clear settings cache (call after saving settings)
     */
    public function clear_settings_cache() {
        $this->settings = null;
    }

    /**
     * Get default checkout fields configuration
     *
     * @return array
     */
    public function get_default_checkout_fields() {
        return array(
            'billing' => array(
                'billing_first_name' => __( 'First name', 'woocommerce' ),
                'billing_last_name'  => __( 'Last name', 'woocommerce' ),
                'billing_company'    => __( 'Company name', 'woocommerce' ),
                'billing_country'    => __( 'Country / Region', 'woocommerce' ),
                'billing_address_1'  => __( 'Street address', 'woocommerce' ),
                'billing_address_2'  => __( 'Apartment, suite, unit, etc.', 'woocommerce' ),
                'billing_city'       => __( 'Town / City', 'woocommerce' ),
                'billing_state'      => __( 'State / County', 'woocommerce' ),
                'billing_postcode'   => __( 'Postcode / ZIP', 'woocommerce' ),
                'billing_phone'      => __( 'Phone', 'woocommerce' ),
                'billing_email'      => __( 'Email address', 'woocommerce' ),
            ),
            'shipping' => array(
                'shipping_first_name' => __( 'First name', 'woocommerce' ),
                'shipping_last_name'  => __( 'Last name', 'woocommerce' ),
                'shipping_company'    => __( 'Company name', 'woocommerce' ),
                'shipping_country'    => __( 'Country / Region', 'woocommerce' ),
                'shipping_address_1'  => __( 'Street address', 'woocommerce' ),
                'shipping_address_2'  => __( 'Apartment, suite, unit, etc.', 'woocommerce' ),
                'shipping_city'       => __( 'Town / City', 'woocommerce' ),
                'shipping_state'      => __( 'State / County', 'woocommerce' ),
                'shipping_postcode'   => __( 'Postcode / ZIP', 'woocommerce' ),
                'shipping_phone'      => __( 'Phone', 'woocommerce' ),
            ),
        );
    }

    /**
     * Get VAT company fields configuration
     *
     * @return array
     */
    public function get_vat_fields() {
        $settings = $this->get_settings();
        $defaults = $this->get_default_settings();
        
        return array(
            'billing_company_name'    => $settings['company_name_label'] ?? $defaults['company_name_label'],
            'billing_company_code'    => $settings['company_code_label'] ?? $defaults['company_code_label'],
            'billing_company_vat'     => $settings['company_vat_label'] ?? $defaults['company_vat_label'],
            'billing_company_address' => $settings['company_address_label'] ?? $defaults['company_address_label'],
        );
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p>
                <?php
                printf(
                    /* translators: %1$s: Plugin name, %2$s: WooCommerce */
                    esc_html__( '%1$s requires %2$s to be installed and active.', 'advanced-woo-checkout-fields' ),
                    '<strong>' . esc_html__( 'Advanced Woo Checkout Fields', 'advanced-woo-checkout-fields' ) . '</strong>',
                    '<strong>' . esc_html__( 'WooCommerce', 'advanced-woo-checkout-fields' ) . '</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'advanced-woo-checkout-fields', false, dirname( AWCF_PLUGIN_BASENAME ) . '/languages' );
    }

    /**
     * Add plugin action links
     *
     * @param array $links Plugin action links.
     * @return array
     */
    public function plugin_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=awcf-settings' ) . '">' . esc_html__( 'Settings', 'advanced-woo-checkout-fields' ) . '</a>',
        );
        return array_merge( $plugin_links, $links );
    }
}

/**
 * Returns the main instance of the plugin
 *
 * @return AWCF
 */
function AWCF() {
    return AWCF::instance();
}

// Initialize the plugin
AWCF();
