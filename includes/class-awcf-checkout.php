<?php
/**
 * Checkout Field Modifications Class
 *
 * Handles all checkout field modifications, validation, and order meta saving.
 * Uses high-priority hooks (1000) following patterns from woocommerce-checkout-field-editor-pro.
 *
 * @package AWCF
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AWCF_Checkout class
 */
class AWCF_Checkout {

    /**
     * Single instance of the class
     *
     * @var AWCF_Checkout
     */
    private static $instance = null;

    /**
     * Hook priority for checkout field filters
     * High priority ensures we run after other plugins
     *
     * @var int
     */
    const HOOK_PRIORITY = 9999;

    /**
     * Get single instance of the class
     *
     * @return AWCF_Checkout
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
     * Initialize all hooks
     */
    private function init_hooks() {
        // Section titles via gettext filter
        add_filter( 'gettext', array( $this, 'modify_checkout_titles' ), 20, 3 );
        
        // Ship to different address checkbox
        add_filter( 'woocommerce_ship_to_different_address_checked', array( $this, 'force_ship_to_different_address' ) );
        
        // Single filter to handle ALL field modifications at the final checkout_fields level
        // This runs after all other plugins/themes have modified fields, ensuring our settings are enforced
        add_filter( 'woocommerce_checkout_fields', array( $this, 'enforce_checkout_fields_settings' ), self::HOOK_PRIORITY + 10 );
        
        // Address form fields (affects both checkout and My Account address editing)
        add_filter( 'woocommerce_billing_fields', array( $this, 'modify_billing_address_fields' ), self::HOOK_PRIORITY );
        add_filter( 'woocommerce_shipping_fields', array( $this, 'modify_shipping_address_fields' ), self::HOOK_PRIORITY );
        
        // Reorder checkout sections
        add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'maybe_reorder_checkout_sections' ), 1 );
        
        // Output hidden field for tracking disabled fields
        add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'output_hidden_tracking_fields' ) );
        
        // VAT info message display
        add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'display_company_info_message' ) );
        
        // Validation hooks
        add_action( 'woocommerce_checkout_process', array( $this, 'prepare_checkout_fields' ) );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 10, 2 );
        
        // Save order meta (HPOS compatible)
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 20, 2 );
        
        // Enqueue frontend scripts and styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );
        
        // Add inline styles
        add_action( 'wp_head', array( $this, 'add_inline_styles' ) );
    }

    /**
     * Get field settings from plugin configuration
     *
     * @param string $field_key Field key.
     * @return array
     */
    private function get_field_config( $field_key ) {
        $settings = AWCF()->get_settings();
        
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
        
        // Default - field enabled, don't override WooCommerce default required
        return array(
            'status'       => 'enabled',
            'required'     => null,
            'address_form' => 'nothing',
        );
    }

    /**
     * Force ship to different address checkbox to be checked
     *
     * @param bool $checked Current checked state.
     * @return bool
     */
    public function force_ship_to_different_address( $checked ) {
        if ( AWCF()->get_setting( 'force_ship_to_different' ) ) {
            return true;
        }
        return $checked;
    }

    /**
     * Modify checkout section titles via gettext filter
     *
     * @param string $translated Translated text.
     * @param string $text       Original text.
     * @param string $domain     Text domain.
     * @return string
     */
    public function modify_checkout_titles( $translated, $text, $domain ) {
        if ( 'woocommerce' !== $domain ) {
            return $translated;
        }

        // Only process specific strings
        if ( 'Billing details' !== $text && 'Billing &amp; Shipping' !== $text && 'Ship to a different address?' !== $text ) {
            return $translated;
        }

        // Prevent recursion
        static $is_processing = false;
        if ( $is_processing ) {
            return $translated;
        }
        $is_processing = true;

        $settings = AWCF()->get_settings();
        $defaults = AWCF()->get_default_settings();

        // Modify billing title
        if ( 'Billing details' === $text || 'Billing &amp; Shipping' === $text ) {
            $custom_title = $settings['billing_title'] ?? $defaults['billing_title'];
            if ( ! empty( $custom_title ) && $custom_title !== $defaults['billing_title'] ) {
                $is_processing = false;
                return $custom_title;
            }
        }

        // Modify shipping title / checkbox label
        if ( 'Ship to a different address?' === $text ) {
            $custom_title = $settings['shipping_title'] ?? $defaults['shipping_title'];
            if ( ! empty( $custom_title ) && $custom_title !== $defaults['shipping_title'] ) {
                $is_processing = false;
                return $custom_title;
            }
        }

        $is_processing = false;
        return $translated;
    }


    /**
     * Enforce all checkout field settings at the woocommerce_checkout_fields level
     * This is the final filter, so our settings will always be applied correctly
     * Combines all field modification logic: removal, required state, and VAT fields
     *
     * @param array $fields Checkout fields.
     * @return array
     */
    public function enforce_checkout_fields_settings( $fields ) {
        if ( ! is_array( $fields ) ) {
            return $fields;
        }

        $settings = AWCF()->get_settings();

        // Process billing fields
        if ( isset( $fields['billing'] ) && is_array( $fields['billing'] ) ) {
            foreach ( $fields['billing'] as $field_key => $field ) {
                if ( strpos( $field_key, 'billing_' ) !== 0 ) {
                    continue;
                }

                // Get field config
                $field_config = $this->get_field_config( $field_key );

                // Handle disabled status - remove field entirely
                if ( $field_config['status'] === 'disabled' ) {
                    unset( $fields['billing'][ $field_key ] );
                    continue;
                }

                // Handle required setting - apply if config exists
                if ( $field_config['required'] !== null ) {
                    $is_required = (bool) $field_config['required'];
                    $fields['billing'][ $field_key ]['required'] = $is_required;
                    
                    // Ensure class array exists, preserving existing classes
                    if ( ! is_array( $fields['billing'][ $field_key ]['class'] ) ) {
                        // Convert string to array, preserving existing classes
                        $existing_class = $fields['billing'][ $field_key ]['class'];
                        $fields['billing'][ $field_key ]['class'] = ! empty( $existing_class ) ? explode( ' ', trim( $existing_class ) ) : array();
                    }
                    
                    // Add/remove validate-required class
                    if ( $is_required ) {
                        if ( ! in_array( 'validate-required', $fields['billing'][ $field_key ]['class'], true ) ) {
                            $fields['billing'][ $field_key ]['class'][] = 'validate-required';
                        }
                    } else {
                        $fields['billing'][ $field_key ]['class'] = array_values( 
                            array_diff( $fields['billing'][ $field_key ]['class'], array( 'validate-required' ) ) 
                        );
                    }
                }
            }
        }

        // Process shipping fields
        if ( isset( $fields['shipping'] ) && is_array( $fields['shipping'] ) ) {
            foreach ( $fields['shipping'] as $field_key => $field ) {
                if ( strpos( $field_key, 'shipping_' ) !== 0 ) {
                    continue;
                }

                // Get field config
                $field_config = $this->get_field_config( $field_key );

                // Handle disabled status - remove field entirely
                if ( $field_config['status'] === 'disabled' ) {
                    unset( $fields['shipping'][ $field_key ] );
                    continue;
                }

                // Handle required setting - apply if config exists
                if ( $field_config['required'] !== null ) {
                    $is_required = (bool) $field_config['required'];
                    $fields['shipping'][ $field_key ]['required'] = $is_required;
                    
                    // Ensure class array exists, preserving existing classes
                    if ( ! is_array( $fields['shipping'][ $field_key ]['class'] ) ) {
                        // Convert string to array, preserving existing classes
                        $existing_class = $fields['shipping'][ $field_key ]['class'];
                        $fields['shipping'][ $field_key ]['class'] = ! empty( $existing_class ) ? explode( ' ', trim( $existing_class ) ) : array();
                    }
                    
                    // Add/remove validate-required class
                    if ( $is_required ) {
                        if ( ! in_array( 'validate-required', $fields['shipping'][ $field_key ]['class'], true ) ) {
                            $fields['shipping'][ $field_key ]['class'][] = 'validate-required';
                        }
                    } else {
                        $fields['shipping'][ $field_key ]['class'] = array_values( 
                            array_diff( $fields['shipping'][ $field_key ]['class'], array( 'validate-required' ) ) 
                        );
                    }
                }
            }
        }

        // Add VAT fields if enabled
        if ( ! empty( $settings['vat_mode_enabled'] ) ) {
            $defaults = AWCF()->get_default_settings();

            // Add company checkbox
            $fields['billing']['billing_is_company'] = array(
                'type'     => 'checkbox',
                'label'    => $settings['vat_checkbox_label'] ?? $defaults['vat_checkbox_label'],
                'required' => false,
                'class'    => array( 'form-row-wide', 'awcf-company-checkbox' ),
                'clear'    => true,
                'priority' => 120,
            );

            // Add company fields - NOT required in PHP, requirement handled by JS + validation
            // These fields have awcf-company-field class for JS targeting
            $fields['billing']['billing_company_name'] = array(
                'type'        => 'text',
                'label'       => $settings['company_name_label'] ?? $defaults['company_name_label'],
                'required'    => false,
                'class'       => array( 'form-row-wide', 'awcf-company-field', 'awcf-hidden' ),
                'clear'       => true,
                'priority'    => 121,
                'placeholder' => '',
            );

            $fields['billing']['billing_company_code'] = array(
                'type'        => 'text',
                'label'       => $settings['company_code_label'] ?? $defaults['company_code_label'],
                'required'    => false,
                'class'       => array( 'form-row-wide', 'awcf-company-field', 'awcf-hidden' ),
                'clear'       => true,
                'priority'    => 122,
                'placeholder' => '',
            );

            $fields['billing']['billing_company_vat'] = array(
                'type'        => 'text',
                'label'       => $settings['company_vat_label'] ?? $defaults['company_vat_label'],
                'required'    => false,
                'class'       => array( 'form-row-wide', 'awcf-company-field', 'awcf-hidden' ),
                'clear'       => true,
                'priority'    => 123,
                'placeholder' => '',
            );

            $fields['billing']['billing_company_address'] = array(
                'type'        => 'text',
                'label'       => $settings['company_address_label'] ?? $defaults['company_address_label'],
                'required'    => false,
                'class'       => array( 'form-row-wide', 'awcf-company-field', 'awcf-hidden' ),
                'clear'       => true,
                'priority'    => 124,
                'placeholder' => '',
            );
        }

        return $fields;
    }

    /**
     * Modify billing address fields for My Account address editing
     * This filter affects both checkout and My Account address forms
     *
     * @param array $fields Billing address fields.
     * @return array
     */
    public function modify_billing_address_fields( $fields ) {
        if ( ! is_array( $fields ) ) {
            return $fields;
        }

        $default_fields = AWCF()->get_default_checkout_fields();
        
        // Process each billing field
        foreach ( $fields as $field_key => $field ) {
            if ( strpos( $field_key, 'billing_' ) !== 0 ) {
                continue;
            }

            // Get field config
            $field_config = $this->get_field_config( $field_key );

            // Handle address_form setting
            if ( $field_config['address_form'] === 'disable' ) {
                // Remove field from address forms
                unset( $fields[ $field_key ] );
                continue;
            } elseif ( $field_config['address_form'] === 'enable' ) {
                // Ensure field exists and apply required setting
                if ( $field_config['required'] !== null ) {
                    $fields[ $field_key ]['required'] = (bool) $field_config['required'];
                    
                    // Update validate-required class
                    if ( ! is_array( $fields[ $field_key ]['class'] ) ) {
                        $existing_class = $fields[ $field_key ]['class'];
                        $fields[ $field_key ]['class'] = ! empty( $existing_class ) ? explode( ' ', trim( $existing_class ) ) : array();
                    }
                    
                    if ( $field_config['required'] ) {
                        if ( ! in_array( 'validate-required', $fields[ $field_key ]['class'], true ) ) {
                            $fields[ $field_key ]['class'][] = 'validate-required';
                        }
                    } else {
                        $fields[ $field_key ]['class'] = array_values( 
                            array_diff( $fields[ $field_key ]['class'], array( 'validate-required' ) ) 
                        );
                    }
                }
            }
            // 'nothing' - do nothing, let WooCommerce handle it
        }

        return $fields;
    }

    /**
     * Modify shipping address fields for My Account address editing
     * This filter affects both checkout and My Account address forms
     * Special handling for shipping_phone which WooCommerce doesn't include by default
     *
     * @param array $fields Shipping address fields.
     * @return array
     */
    public function modify_shipping_address_fields( $fields ) {
        if ( ! is_array( $fields ) ) {
            return $fields;
        }

        $default_fields = AWCF()->get_default_checkout_fields();
        
        // Process each shipping field
        foreach ( $fields as $field_key => $field ) {
            if ( strpos( $field_key, 'shipping_' ) !== 0 ) {
                continue;
            }

            // Get field config
            $field_config = $this->get_field_config( $field_key );

            // Handle address_form setting
            if ( $field_config['address_form'] === 'disable' ) {
                // Remove field from address forms
                unset( $fields[ $field_key ] );
                continue;
            } elseif ( $field_config['address_form'] === 'enable' ) {
                // Ensure field exists and apply required setting
                if ( $field_config['required'] !== null ) {
                    $fields[ $field_key ]['required'] = (bool) $field_config['required'];
                    
                    // Update validate-required class
                    if ( ! is_array( $fields[ $field_key ]['class'] ) ) {
                        $existing_class = $fields[ $field_key ]['class'];
                        $fields[ $field_key ]['class'] = ! empty( $existing_class ) ? explode( ' ', trim( $existing_class ) ) : array();
                    }
                    
                    if ( $field_config['required'] ) {
                        if ( ! in_array( 'validate-required', $fields[ $field_key ]['class'], true ) ) {
                            $fields[ $field_key ]['class'][] = 'validate-required';
                        }
                    } else {
                        $fields[ $field_key ]['class'] = array_values( 
                            array_diff( $fields[ $field_key ]['class'], array( 'validate-required' ) ) 
                        );
                    }
                }
            }
            // 'nothing' - do nothing, let WooCommerce handle it
        }

        // Special handling for shipping_phone - add it if enabled in form
        $shipping_phone_config = $this->get_field_config( 'shipping_phone' );
        if ( $shipping_phone_config['address_form'] === 'enable' && ! isset( $fields['shipping_phone'] ) ) {
            // Get label from default fields
            $field_label = isset( $default_fields['shipping']['shipping_phone'] ) 
                ? $default_fields['shipping']['shipping_phone'] 
                : __( 'Phone', 'woocommerce' );
            
            $fields['shipping_phone'] = array(
                'label'    => $field_label,
                'required' => (bool) $shipping_phone_config['required'],
                'class'    => array( 'form-row-wide' ),
                'clear'    => true,
                'type'     => 'tel',
                'priority' => 100,
            );
            
            // Add validate-required class if required
            if ( $shipping_phone_config['required'] ) {
                $fields['shipping_phone']['class'][] = 'validate-required';
            }
        }

        return $fields;
    }


    /**
     * Maybe reorder checkout sections (shipping first)
     */
    public function maybe_reorder_checkout_sections() {
        $checkout_order = AWCF()->get_setting( 'checkout_order', 'billing_first' );
        
        if ( $checkout_order === 'shipping_first' ) {
            // Remove default actions
            remove_action( 'woocommerce_checkout_billing', array( WC()->checkout(), 'checkout_form_billing' ) );
            remove_action( 'woocommerce_checkout_shipping', array( WC()->checkout(), 'checkout_form_shipping' ) );
            
            // Re-add in reversed order
            add_action( 'woocommerce_checkout_billing', array( WC()->checkout(), 'checkout_form_shipping' ), 10 );
            add_action( 'woocommerce_checkout_shipping', array( WC()->checkout(), 'checkout_form_billing' ), 10 );
        }
    }

    /**
     * Output hidden fields for tracking disabled/conditional fields
     * This is critical for proper server-side validation
     */
    public function output_hidden_tracking_fields() {
        $settings = AWCF()->get_settings();
        
        if ( empty( $settings['vat_mode_enabled'] ) ) {
            return;
        }
        
        // Hidden field to track which fields are currently disabled/hidden
        // JS will update this value based on checkbox state
        echo '<input type="hidden" id="awcf_disabled_fields" name="awcf_disabled_fields" value="billing_company_name,billing_company_code,billing_company_vat,billing_company_address" />';
    }

    /**
     * Display company info message after billing form
     */
    public function display_company_info_message() {
        $settings = AWCF()->get_settings();

        if ( empty( $settings['vat_mode_enabled'] ) ) {
            return;
        }

        $defaults = AWCF()->get_default_settings();
        $message  = $settings['company_info_message'] ?? $defaults['company_info_message'];

        if ( empty( $message ) ) {
            return;
        }
        ?>
        <div class="awcf-company-info-message awcf-hidden">
            <p class="awcf-info-text"><?php echo wp_kses_post( $message ); ?></p>
        </div>
        <?php
    }

    /**
     * Prepare checkout fields before validation
     * Filters out disabled fields from WC checkout fields
     */
    public function prepare_checkout_fields() {
        // Get disabled fields from hidden input
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $disabled_fields_str = isset( $_POST['awcf_disabled_fields'] ) ? sanitize_text_field( wp_unslash( $_POST['awcf_disabled_fields'] ) ) : '';
        
        if ( empty( $disabled_fields_str ) ) {
            return;
        }
        
        $disabled_fields = array_filter( array_map( 'trim', explode( ',', $disabled_fields_str ) ) );
        
        if ( empty( $disabled_fields ) ) {
            return;
        }
        
        // Get current checkout fields
        $checkout_fields = WC()->checkout->checkout_fields;
        
        // Remove disabled fields from checkout to prevent validation
        foreach ( $checkout_fields as $fieldset_key => $fieldset ) {
            foreach ( $disabled_fields as $field_name ) {
                if ( isset( $checkout_fields[ $fieldset_key ][ $field_name ] ) ) {
                    unset( $checkout_fields[ $fieldset_key ][ $field_name ] );
                }
            }
        }
        
        // Update checkout fields
        WC()->checkout->checkout_fields = $checkout_fields;
    }

    /**
     * Validate checkout fields
     * Only validates VAT fields when company checkbox is checked
     *
     * @param array    $data   Posted checkout data.
     * @param WP_Error $errors Validation errors.
     */
    public function validate_checkout( $data, $errors ) {
        $settings = AWCF()->get_settings();

        // Only validate VAT fields if VAT mode is enabled
        if ( empty( $settings['vat_mode_enabled'] ) ) {
            return;
        }

        // Check if company checkbox is checked
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $is_company = ! empty( $_POST['billing_is_company'] );

        if ( ! $is_company ) {
            return;
        }

        // Get disabled fields to double-check
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $disabled_fields_str = isset( $_POST['awcf_disabled_fields'] ) ? sanitize_text_field( wp_unslash( $_POST['awcf_disabled_fields'] ) ) : '';
        $disabled_fields = array_filter( array_map( 'trim', explode( ',', $disabled_fields_str ) ) );

        // Company fields that should be required when checkbox is checked
        $vat_fields = AWCF()->get_vat_fields();

        foreach ( $vat_fields as $field_key => $field_label ) {
            // Skip if field is in disabled list (shouldn't happen when checkbox is checked, but safety check)
            if ( in_array( $field_key, $disabled_fields, true ) ) {
                continue;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $field_value = isset( $_POST[ $field_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field_key ] ) ) : '';

            if ( empty( $field_value ) ) {
                $defaults = AWCF()->get_default_settings();
                $error_template = $settings['required_field_error'] ?? $defaults['required_field_error'];
                
                $errors->add(
                    $field_key . '_required',
                    sprintf(
                        $error_template,
                        esc_html( $field_label )
                    ),
                    array( 'id' => $field_key )
                );
            }
        }
    }

    /**
     * Save order meta (HPOS compatible)
     *
     * @param WC_Order $order Order object.
     * @param array    $data  Posted data.
     */
    public function save_order_meta( $order, $data ) {
        $settings = AWCF()->get_settings();

        if ( empty( $settings['vat_mode_enabled'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $is_company = ! empty( $_POST['billing_is_company'] );

        $order->update_meta_data( '_billing_is_company', $is_company ? 'yes' : 'no' );

        if ( $is_company ) {
            $vat_fields = array_keys( AWCF()->get_vat_fields() );

            foreach ( $vat_fields as $field_key ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ( isset( $_POST[ $field_key ] ) ) {
                    $order->update_meta_data( '_' . $field_key, sanitize_text_field( wp_unslash( $_POST[ $field_key ] ) ) );
                }
            }
        }
    }

    /**
     * Enqueue checkout scripts
     */
    public function enqueue_checkout_scripts() {
        if ( ! is_checkout() ) {
            return;
        }

        $settings = AWCF()->get_settings();

        $vat_mode_enabled        = ! empty( $settings['vat_mode_enabled'] );
        $force_ship_to_different = ! empty( $settings['force_ship_to_different'] );

        if ( ! $vat_mode_enabled && ! $force_ship_to_different ) {
            return;
        }

        wp_enqueue_style(
            'awcf-checkout',
            AWCF_PLUGIN_URL . 'assets/css/awcf-public.css',
            array(),
            AWCF_VERSION
        );

        wp_enqueue_script(
            'awcf-checkout',
            AWCF_PLUGIN_URL . 'assets/js/awcf-checkout.js',
            array( 'jquery' ),
            AWCF_VERSION,
            true
        );

        // Localize script with settings
        wp_localize_script(
            'awcf-checkout',
            'awcf_params',
            array(
                'force_ship_to_different' => $force_ship_to_different,
                'vat_mode_enabled'        => $vat_mode_enabled,
                'vat_fields'              => array_keys( AWCF()->get_vat_fields() ),
                'optional_text'           => esc_html__( '(optional)', 'woocommerce' ),
            )
        );
    }

    /**
     * Add inline styles
     */
    public function add_inline_styles() {
        if ( ! is_checkout() ) {
            return;
        }

        $settings = AWCF()->get_settings();
        
        // Ensure shipping address is always visible when forced and hide checkbox
        if ( ! empty( $settings['force_ship_to_different'] ) ) {
            ?>
            <style type="text/css">
                .woocommerce-shipping-fields .shipping_address {
                    display: block !important;
                }
                /* Hide only the checkbox input, not the entire container */
                .woocommerce-checkout #ship-to-different-address-checkbox {
                    display: none !important;
                    visibility: hidden !important;
                    position: absolute !important;
                    opacity: 0 !important;
                    pointer-events: none !important;
                }
            </style>
            <?php
        }

        // Hide company fields initially if VAT mode is enabled
        if ( ! empty( $settings['vat_mode_enabled'] ) ) {
            ?>
            <style type="text/css">
                .awcf-hidden {
                    display: none !important;
                }
                .awcf-visible {
                    display: block !important;
                }
            </style>
            <?php
        }
    }
}
