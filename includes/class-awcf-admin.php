<?php
/**
 * Admin Settings Class
 *
 * Handles the plugin admin settings page, settings registration,
 * and sanitization.
 *
 * @package AWCF
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AWCF_Admin class
 */
class AWCF_Admin {

    /**
     * Single instance of the class
     *
     * @var AWCF_Admin
     */
    private static $instance = null;

    /**
     * Settings page slug
     *
     * @var string
     */
    private $page_slug = 'awcf-settings';

    /**
     * Get single instance of the class
     *
     * @return AWCF_Admin
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
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * Add admin menu under WooCommerce
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Advanced Checkout Fields', 'advanced-woo-checkout-fields' ),
            __( 'Checkout Fields', 'advanced-woo-checkout-fields' ),
            'manage_woocommerce',
            $this->page_slug,
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'awcf_settings_group',
            'awcf_settings',
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => AWCF()->get_default_settings(),
            )
        );
    }

    /**
     * Sanitize settings
     *
     * @param array $input Input settings.
     * @return array
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        // Section 1: Checkout Layout
        $sanitized['force_ship_to_different'] = isset( $input['force_ship_to_different'] ) ? (bool) $input['force_ship_to_different'] : false;
        $sanitized['billing_title']           = isset( $input['billing_title'] ) ? sanitize_text_field( $input['billing_title'] ) : '';
        $sanitized['shipping_title']          = isset( $input['shipping_title'] ) ? sanitize_text_field( $input['shipping_title'] ) : '';
        $sanitized['checkout_order']          = isset( $input['checkout_order'] ) && in_array( $input['checkout_order'], array( 'billing_first', 'shipping_first' ), true ) ? $input['checkout_order'] : 'billing_first';

        // Section 2: Field controls (structured with status, required, and address_form)
        $sanitized['fields'] = array();
        if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
            $valid_statuses = array( 'enabled', 'disabled' );
            $valid_address_forms = array( 'nothing', 'disable', 'enable' );
            foreach ( $input['fields'] as $field_key => $field_settings ) {
                $field_key = sanitize_key( $field_key );
                
                if ( is_array( $field_settings ) ) {
                    $status   = isset( $field_settings['status'] ) && in_array( $field_settings['status'], $valid_statuses, true ) ? $field_settings['status'] : 'enabled';
                    $required = isset( $field_settings['required'] ) ? (bool) $field_settings['required'] : false;
                    $address_form = isset( $field_settings['address_form'] ) && in_array( $field_settings['address_form'], $valid_address_forms, true ) ? $field_settings['address_form'] : 'nothing';
                    
                    $sanitized['fields'][ $field_key ] = array(
                        'status'       => $status,
                        'required'     => $required,
                        'address_form' => $address_form,
                    );
                }
            }
        }

        // Section 3: VAT compliance mode
        $sanitized['vat_mode_enabled']      = isset( $input['vat_mode_enabled'] ) ? (bool) $input['vat_mode_enabled'] : false;
        $sanitized['vat_checkbox_label']    = isset( $input['vat_checkbox_label'] ) ? sanitize_text_field( $input['vat_checkbox_label'] ) : '';
        $sanitized['company_name_label']    = isset( $input['company_name_label'] ) ? sanitize_text_field( $input['company_name_label'] ) : '';
        $sanitized['company_code_label']    = isset( $input['company_code_label'] ) ? sanitize_text_field( $input['company_code_label'] ) : '';
        $sanitized['company_vat_label']     = isset( $input['company_vat_label'] ) ? sanitize_text_field( $input['company_vat_label'] ) : '';
        $sanitized['company_address_label'] = isset( $input['company_address_label'] ) ? sanitize_text_field( $input['company_address_label'] ) : '';
        $sanitized['company_info_message']  = isset( $input['company_info_message'] ) ? wp_kses_post( $input['company_info_message'] ) : '';

        // Section 4: Frontend Text (Translatable)
        $sanitized['required_field_error']      = isset( $input['required_field_error'] ) ? wp_kses_post( $input['required_field_error'] ) : '';
        $sanitized['company_information_label'] = isset( $input['company_information_label'] ) ? sanitize_text_field( $input['company_information_label'] ) : '';

        // Clear settings cache after save
        AWCF()->clear_settings_cache();

        return $sanitized;
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'woocommerce_page_' . $this->page_slug !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'awcf-admin',
            AWCF_PLUGIN_URL . 'assets/css/awcf-admin.css',
            array(),
            AWCF_VERSION
        );

        // Add inline script for field status toggle
        wp_add_inline_script(
            'jquery',
            "
            jQuery(document).ready(function($) {
                // Function to update row disabled state
                function updateRowState(\$select) {
                    var \$row = \$select.closest('tr');
                    var isDisabled = \$select.val() === 'disabled';
                    
                    if (isDisabled) {
                        \$row.addClass('awcf-field-disabled');
                    } else {
                        \$row.removeClass('awcf-field-disabled');
                    }
                }
                
                // Initialize on page load
                $('.awcf-fields-table .column-status select').each(function() {
                    updateRowState($(this));
                });
                
                // Update on change
                $('.awcf-fields-table .column-status select').on('change', function() {
                    updateRowState($(this));
                });
            });
            "
        );
    }

    /**
     * Get field settings helper
     *
     * @param array  $settings Plugin settings.
     * @param string $field_key Field key.
     * @return array
     */
    private function get_field_settings( $settings, $field_key ) {
        // Check for new format
        if ( isset( $settings['fields'][ $field_key ] ) && is_array( $settings['fields'][ $field_key ] ) ) {
            $field_config = $settings['fields'][ $field_key ];
            // Ensure address_form is set (for backward compatibility)
            if ( ! isset( $field_config['address_form'] ) ) {
                $field_config['address_form'] = 'nothing';
            }
            return $field_config;
        }
        
        // Legacy format conversion
        if ( isset( $settings['fields'][ $field_key ] ) && is_string( $settings['fields'][ $field_key ] ) ) {
            $legacy_state = $settings['fields'][ $field_key ];
            return array(
                'status'       => $legacy_state === 'disabled' ? 'disabled' : 'enabled',
                'required'     => $legacy_state === 'required',
                'address_form' => 'nothing',
            );
        }
        
        // Default
        return array(
            'status'       => 'enabled',
            'required'     => false,
            'address_form' => 'nothing',
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        $settings        = AWCF()->get_settings();
        $default_fields  = AWCF()->get_default_checkout_fields();
        $defaults        = AWCF()->get_default_settings();
        ?>
        <div class="wrap awcf-settings-wrap">
            <h1><?php esc_html_e( 'Advanced Checkout Fields', 'advanced-woo-checkout-fields' ); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields( 'awcf_settings_group' ); ?>
                
                <!-- Section 1: Checkout Layout -->
                <div class="awcf-section">
                    <h2><?php esc_html_e( 'Checkout Layout', 'advanced-woo-checkout-fields' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Configure the checkout page layout, section titles, and shipping address behavior.', 'advanced-woo-checkout-fields' ); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <?php esc_html_e( 'Force Separate Shipping Address', 'advanced-woo-checkout-fields' ); ?>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="awcf_settings[force_ship_to_different]" 
                                           value="1" 
                                           <?php checked( ! empty( $settings['force_ship_to_different'] ) ); ?> />
                                    <?php esc_html_e( 'Always show shipping address fields and require separate shipping information (hides the toggle checkbox)', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="billing_title">
                                    <?php esc_html_e( 'Billing Section Title', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="billing_title" 
                                       name="awcf_settings[billing_title]" 
                                       value="<?php echo esc_attr( $settings['billing_title'] ?? $defaults['billing_title'] ); ?>" 
                                       class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e( 'The title displayed above the billing fields section.', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="shipping_title">
                                    <?php esc_html_e( 'Shipping Section Title', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="shipping_title" 
                                       name="awcf_settings[shipping_title]" 
                                       value="<?php echo esc_attr( $settings['shipping_title'] ?? $defaults['shipping_title'] ); ?>" 
                                       class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e( 'The title/label for the shipping address section. When "Force Separate Shipping Address" is enabled, this becomes the section title.', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="checkout_order">
                                    <?php esc_html_e( 'Checkout Section Order', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <select id="checkout_order" name="awcf_settings[checkout_order]">
                                    <option value="billing_first" <?php selected( ( $settings['checkout_order'] ?? 'billing_first' ), 'billing_first' ); ?>>
                                        <?php esc_html_e( 'Billing first, then Shipping', 'advanced-woo-checkout-fields' ); ?>
                                    </option>
                                    <option value="shipping_first" <?php selected( ( $settings['checkout_order'] ?? 'billing_first' ), 'shipping_first' ); ?>>
                                        <?php esc_html_e( 'Shipping first, then Billing', 'advanced-woo-checkout-fields' ); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'Choose which address section appears first on the checkout page.', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Section 2: Checkout Fields Control -->
                <div class="awcf-section">
                    <h2><?php esc_html_e( 'Checkout Fields Control', 'advanced-woo-checkout-fields' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Configure the visibility and requirement status of default WooCommerce checkout fields.', 'advanced-woo-checkout-fields' ); ?>
                    </p>

                    <!-- Billing Fields -->
                    <h3><?php esc_html_e( 'Billing Fields', 'advanced-woo-checkout-fields' ); ?></h3>
                    <table class="wp-list-table widefat fixed striped awcf-fields-table">
                        <thead>
                            <tr>
                                <th class="column-field"><?php esc_html_e( 'Field', 'advanced-woo-checkout-fields' ); ?></th>
                                <th class="column-status"><?php esc_html_e( 'Status', 'advanced-woo-checkout-fields' ); ?></th>
                                <th class="column-required"><?php esc_html_e( 'Requirement', 'advanced-woo-checkout-fields' ); ?></th>
                                <th class="column-address-form"><?php esc_html_e( 'Address Form', 'advanced-woo-checkout-fields' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $default_fields['billing'] as $field_key => $field_label ) : ?>
                                <?php
                                $field_settings = $this->get_field_settings( $settings, $field_key );
                                ?>
                                <tr>
                                    <td class="column-field">
                                        <strong><?php echo esc_html( $field_label ); ?></strong>
                                        <code><?php echo esc_html( $field_key ); ?></code>
                                    </td>
                                    <td class="column-status">
                                        <select name="awcf_settings[fields][<?php echo esc_attr( $field_key ); ?>][status]">
                                            <option value="enabled" <?php selected( $field_settings['status'], 'enabled' ); ?>>
                                                <?php esc_html_e( 'Enabled', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                            <option value="disabled" <?php selected( $field_settings['status'], 'disabled' ); ?>>
                                                <?php esc_html_e( 'Disabled', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                        </select>
                                    </td>
                                    <td class="column-required">
                                        <select name="awcf_settings[fields][<?php echo esc_attr( $field_key ); ?>][required]">
                                            <option value="0" <?php selected( $field_settings['required'], false ); ?>>
                                                <?php esc_html_e( 'Optional', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                            <option value="1" <?php selected( $field_settings['required'], true ); ?>>
                                                <?php esc_html_e( 'Required', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                        </select>
                                    </td>
                                    <td class="column-address-form">
                                        <select name="awcf_settings[fields][<?php echo esc_attr( $field_key ); ?>][address_form]">
                                            <option value="nothing" <?php selected( $field_settings['address_form'], 'nothing' ); ?>>
                                                <?php esc_html_e( 'Do Nothing', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                            <option value="disable" <?php selected( $field_settings['address_form'], 'disable' ); ?>>
                                                <?php esc_html_e( 'Disable in Form', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                            <option value="enable" <?php selected( $field_settings['address_form'], 'enable' ); ?>>
                                                <?php esc_html_e( 'Enable in Form', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Shipping Fields -->
                    <h3><?php esc_html_e( 'Shipping Fields', 'advanced-woo-checkout-fields' ); ?></h3>
                    <table class="wp-list-table widefat fixed striped awcf-fields-table">
                        <thead>
                            <tr>
                                <th class="column-field"><?php esc_html_e( 'Field', 'advanced-woo-checkout-fields' ); ?></th>
                                <th class="column-status"><?php esc_html_e( 'Status', 'advanced-woo-checkout-fields' ); ?></th>
                                <th class="column-required"><?php esc_html_e( 'Requirement', 'advanced-woo-checkout-fields' ); ?></th>
                                <th class="column-address-form"><?php esc_html_e( 'Address Form', 'advanced-woo-checkout-fields' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $default_fields['shipping'] as $field_key => $field_label ) : ?>
                                <?php
                                $field_settings = $this->get_field_settings( $settings, $field_key );
                                ?>
                                <tr>
                                    <td class="column-field">
                                        <strong><?php echo esc_html( $field_label ); ?></strong>
                                        <code><?php echo esc_html( $field_key ); ?></code>
                                    </td>
                                    <td class="column-status">
                                        <select name="awcf_settings[fields][<?php echo esc_attr( $field_key ); ?>][status]">
                                            <option value="enabled" <?php selected( $field_settings['status'], 'enabled' ); ?>>
                                                <?php esc_html_e( 'Enabled', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                            <option value="disabled" <?php selected( $field_settings['status'], 'disabled' ); ?>>
                                                <?php esc_html_e( 'Disabled', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                        </select>
                                    </td>
                                    <td class="column-required">
                                        <select name="awcf_settings[fields][<?php echo esc_attr( $field_key ); ?>][required]">
                                            <option value="0" <?php selected( $field_settings['required'], false ); ?>>
                                                <?php esc_html_e( 'Optional', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                            <option value="1" <?php selected( $field_settings['required'], true ); ?>>
                                                <?php esc_html_e( 'Required', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                        </select>
                                    </td>
                                    <td class="column-address-form">
                                        <select name="awcf_settings[fields][<?php echo esc_attr( $field_key ); ?>][address_form]">
                                            <option value="nothing" <?php selected( $field_settings['address_form'], 'nothing' ); ?>>
                                                <?php esc_html_e( 'Do Nothing', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                            <option value="disable" <?php selected( $field_settings['address_form'], 'disable' ); ?>>
                                                <?php esc_html_e( 'Disable in Form', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                            <option value="enable" <?php selected( $field_settings['address_form'], 'enable' ); ?>>
                                                <?php esc_html_e( 'Enable in Form', 'advanced-woo-checkout-fields' ); ?>
                                            </option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Section 3: VAT Compliance Mode -->
                <div class="awcf-section">
                    <h2><?php esc_html_e( 'VAT Compliance Mode', 'advanced-woo-checkout-fields' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Enable VAT compliance mode to add a company checkbox and additional company fields at checkout. When the checkbox is checked, customers can enter their company details for VAT invoicing purposes. All company fields become required when the checkbox is checked.', 'advanced-woo-checkout-fields' ); ?>
                    </p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <?php esc_html_e( 'Enable VAT Compliance Mode', 'advanced-woo-checkout-fields' ); ?>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="awcf_settings[vat_mode_enabled]" 
                                           value="1" 
                                           <?php checked( ! empty( $settings['vat_mode_enabled'] ) ); ?> />
                                    <?php esc_html_e( 'Add company checkbox and fields to checkout', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <h3><?php esc_html_e( 'Field Labels', 'advanced-woo-checkout-fields' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="vat_checkbox_label">
                                    <?php esc_html_e( 'Company Checkbox Label', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="vat_checkbox_label" 
                                       name="awcf_settings[vat_checkbox_label]" 
                                       value="<?php echo esc_attr( $settings['vat_checkbox_label'] ?? $defaults['vat_checkbox_label'] ); ?>" 
                                       class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Internal field name: billing_is_company', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="company_name_label">
                                    <?php esc_html_e( 'Company Name Label', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="company_name_label" 
                                       name="awcf_settings[company_name_label]" 
                                       value="<?php echo esc_attr( $settings['company_name_label'] ?? $defaults['company_name_label'] ); ?>" 
                                       class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Internal field name: billing_company_name', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="company_code_label">
                                    <?php esc_html_e( 'Company Code Label', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="company_code_label" 
                                       name="awcf_settings[company_code_label]" 
                                       value="<?php echo esc_attr( $settings['company_code_label'] ?? $defaults['company_code_label'] ); ?>" 
                                       class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Internal field name: billing_company_code', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="company_vat_label">
                                    <?php esc_html_e( 'Company VAT Code Label', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="company_vat_label" 
                                       name="awcf_settings[company_vat_label]" 
                                       value="<?php echo esc_attr( $settings['company_vat_label'] ?? $defaults['company_vat_label'] ); ?>" 
                                       class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Internal field name: billing_company_vat', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="company_address_label">
                                    <?php esc_html_e( 'Company Address Label', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="company_address_label" 
                                       name="awcf_settings[company_address_label]" 
                                       value="<?php echo esc_attr( $settings['company_address_label'] ?? $defaults['company_address_label'] ); ?>" 
                                       class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Internal field name: billing_company_address', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="company_info_message">
                                    <?php esc_html_e( 'Information Message', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <textarea id="company_info_message" 
                                          name="awcf_settings[company_info_message]" 
                                          rows="3" 
                                          class="large-text"><?php echo esc_textarea( $settings['company_info_message'] ?? $defaults['company_info_message'] ); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e( 'This message will be displayed below the company fields when the company checkbox is checked.', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Section 4: Frontend Text (Translatable) -->
                <div class="awcf-section">
                    <h2><?php esc_html_e( 'Frontend Text', 'advanced-woo-checkout-fields' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Customize the text strings that appear on the frontend, in emails, and in order details. These can be translated or customized to match your store\'s language and style.', 'advanced-woo-checkout-fields' ); ?>
                    </p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="required_field_error">
                                    <?php esc_html_e( 'Required Field Error Message', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="required_field_error" 
                                       name="awcf_settings[required_field_error]" 
                                       value="<?php echo esc_attr( $settings['required_field_error'] ?? $defaults['required_field_error'] ); ?>" 
                                       class="large-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Message shown when a required field is empty. Use %s as a placeholder for the field label. Example: "<strong>%s</strong> is a required field."', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="company_information_label">
                                    <?php esc_html_e( 'Company Information Heading', 'advanced-woo-checkout-fields' ); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="company_information_label" 
                                       name="awcf_settings[company_information_label]" 
                                       value="<?php echo esc_attr( $settings['company_information_label'] ?? $defaults['company_information_label'] ); ?>" 
                                       class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Heading text displayed for company information sections in order details, emails, and admin.', 'advanced-woo-checkout-fields' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( __( 'Save Settings', 'advanced-woo-checkout-fields' ) ); ?>
            </form>
        </div>
        <?php
    }
}
