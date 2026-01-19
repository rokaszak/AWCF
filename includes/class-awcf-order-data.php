<?php
/**
 * Order Data Display Class
 *
 * Handles displaying VAT company information in admin orders and emails.
 * Uses HPOS-compatible methods for order meta access.
 *
 * @package AWCF
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AWCF_Order_Data class
 */
class AWCF_Order_Data {

    /**
     * Constructor
     */
    public function __construct() {
        // Admin order display
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_admin_order_meta' ), 20, 1 );
        
        // Save admin order meta
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_admin_order_meta' ), 20, 1 );
        
        // Email display
        add_action( 'woocommerce_email_after_order_table', array( $this, 'display_email_order_meta' ), 20, 4 );
        
        // Thank you page display
        add_action( 'woocommerce_thankyou', array( $this, 'display_thankyou_order_meta' ), 20, 1 );
        
        // Order details page (My Account)
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_order_details_meta' ), 20, 1 );
    }

    /**
     * Check if order has company information
     *
     * @param WC_Order $order Order object.
     * @return bool
     */
    private function order_has_company_info( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        
        return $order->get_meta( '_billing_is_company' ) === 'yes';
    }

    /**
     * Get company meta data from order
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    private function get_company_meta( $order ) {
        $settings = AWCF()->get_settings();
        $defaults = AWCF()->get_default_settings();
        
        return array(
            'company_name'    => array(
                'label' => $settings['company_name_label'] ?? $defaults['company_name_label'],
                'value' => $order->get_meta( '_billing_company_name' ),
            ),
            'company_code'    => array(
                'label' => $settings['company_code_label'] ?? $defaults['company_code_label'],
                'value' => $order->get_meta( '_billing_company_code' ),
            ),
            'company_vat'     => array(
                'label' => $settings['company_vat_label'] ?? $defaults['company_vat_label'],
                'value' => $order->get_meta( '_billing_company_vat' ),
            ),
            'company_address' => array(
                'label' => $settings['company_address_label'] ?? $defaults['company_address_label'],
                'value' => $order->get_meta( '_billing_company_address' ),
            ),
        );
    }

    /**
     * Display company meta in admin order details (editable form)
     *
     * @param WC_Order $order Order object.
     */
    public function display_admin_order_meta( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $settings = AWCF()->get_settings();
        $defaults = AWCF()->get_default_settings();
        
        // Only show if VAT mode is enabled
        if ( empty( $settings['vat_mode_enabled'] ) ) {
            return;
        }

        $company_label = $settings['company_information_label'] ?? $defaults['company_information_label'];
        $vat_fields = AWCF()->get_vat_fields();
        $is_company = $order->get_meta( '_billing_is_company' ) === 'yes';
        
        // Get current values
        $current_values = array();
        foreach ( array_keys( $vat_fields ) as $field_key ) {
            $current_values[ $field_key ] = $order->get_meta( '_' . $field_key );
        }
        ?>
        <div class="awcf-admin-company-details" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e5e5;">
            <h3 style="margin-bottom: 10px;"><?php echo esc_html( $company_label ); ?></h3>
            
            <?php wp_nonce_field( 'awcf_save_order_meta', 'awcf_order_meta_nonce' ); ?>
            
            <table class="form-table">
                <tr>
                    <th>
                        <label for="awcf_billing_is_company">
                            <?php echo esc_html( $settings['vat_checkbox_label'] ?? $defaults['vat_checkbox_label'] ); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   id="awcf_billing_is_company" 
                                   name="billing_is_company" 
                                   value="1" 
                                   <?php checked( $is_company, true ); ?> />
                            <?php esc_html_e( 'This order is for a company', 'advanced-woo-checkout-fields' ); ?>
                        </label>
                    </td>
                </tr>
                
                <?php foreach ( $vat_fields as $field_key => $field_label ) : ?>
                    <tr class="awcf-company-field-row" style="<?php echo $is_company ? '' : 'display: none;'; ?>">
                        <th>
                            <label for="awcf_<?php echo esc_attr( $field_key ); ?>">
                                <?php echo esc_html( $field_label ); ?>
                            </label>
                        </th>
                        <td>
                            <?php if ( $field_key === 'billing_company_address' ) : ?>
                                <textarea 
                                    id="awcf_<?php echo esc_attr( $field_key ); ?>" 
                                    name="<?php echo esc_attr( $field_key ); ?>" 
                                    rows="3" 
                                    class="large-text"><?php echo esc_textarea( $current_values[ $field_key ] ); ?></textarea>
                            <?php else : ?>
                                <input type="text" 
                                       id="awcf_<?php echo esc_attr( $field_key ); ?>" 
                                       name="<?php echo esc_attr( $field_key ); ?>" 
                                       value="<?php echo esc_attr( $current_values[ $field_key ] ); ?>" 
                                       class="regular-text" />
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#awcf_billing_is_company').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('.awcf-company-field-row').show();
                    } else {
                        $('.awcf-company-field-row').hide();
                    }
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Save admin order meta (HPOS compatible)
     *
     * @param int $order_id Order ID.
     */
    public function save_admin_order_meta( $order_id ) {
        // Verify nonce
        if ( ! isset( $_POST['awcf_order_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['awcf_order_meta_nonce'] ) ), 'awcf_save_order_meta' ) ) {
            return;
        }

        // Check user capabilities
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            return;
        }

        // Load order
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Check if VAT mode is enabled
        $settings = AWCF()->get_settings();
        if ( empty( $settings['vat_mode_enabled'] ) ) {
            return;
        }

        // Process company checkbox state
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $is_company = ! empty( $_POST['billing_is_company'] );
        
        // Save checkbox state (HPOS compatible)
        $order->update_meta_data( '_billing_is_company', $is_company ? 'yes' : 'no' );

        // Get VAT fields
        $vat_fields = array_keys( AWCF()->get_vat_fields() );

        if ( $is_company ) {
            // Save all company fields
            foreach ( $vat_fields as $field_key ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $field_value = isset( $_POST[ $field_key ] ) ? $_POST[ $field_key ] : '';
                
                // Sanitize based on field type
                if ( $field_key === 'billing_company_address' ) {
                    $field_value = sanitize_textarea_field( wp_unslash( $field_value ) );
                } else {
                    $field_value = sanitize_text_field( wp_unslash( $field_value ) );
                }
                
                // Save meta (HPOS compatible)
                $order->update_meta_data( '_' . $field_key, $field_value );
            }
        } else {
            // Optionally clear fields when unchecked, or leave them as-is
            // For now, we'll leave them as-is to preserve data
        }

        // Save order (HPOS compatible)
        $order->save();
    }

    /**
     * Display company meta in order emails
     *
     * @param WC_Order $order         Order object.
     * @param bool     $sent_to_admin Whether email is sent to admin.
     * @param bool     $plain_text    Whether email is plain text.
     * @param WC_Email $email         Email object.
     */
    public function display_email_order_meta( $order, $sent_to_admin, $plain_text, $email = null ) {
        if ( ! $this->order_has_company_info( $order ) ) {
            return;
        }

        $company_meta = $this->get_company_meta( $order );

        if ( $plain_text ) {
            $this->render_email_plain_text( $company_meta );
        } else {
            $this->render_email_html( $company_meta );
        }
    }

    /**
     * Render plain text email output
     *
     * @param array $company_meta Company meta data.
     */
    private function render_email_plain_text( $company_meta ) {
        $settings = AWCF()->get_settings();
        $defaults = AWCF()->get_default_settings();
        $company_label = $settings['company_information_label'] ?? $defaults['company_information_label'];
        echo "\n" . esc_html( $company_label ) . "\n";
        
        foreach ( $company_meta as $meta ) {
            if ( ! empty( $meta['value'] ) ) {
                echo esc_html( $meta['label'] ) . ': ' . esc_html( $meta['value'] ) . "\n";
            }
        }
        echo "\n";
    }

    /**
     * Render HTML email output
     *
     * @param array $company_meta Company meta data.
     */
    private function render_email_html( $company_meta ) {
        $settings = AWCF()->get_settings();
        $defaults = AWCF()->get_default_settings();
        $company_label = $settings['company_information_label'] ?? $defaults['company_information_label'];
        ?>
        <div style="margin-bottom: 40px;">
            <h2 style="color: #96588a; display: block; font-family: &quot;Helvetica Neue&quot;, Helvetica, Roboto, Arial, sans-serif; font-size: 18px; font-weight: bold; line-height: 130%; margin: 0 0 18px; text-align: left;">
                <?php echo esc_html( $company_label ); ?>
            </h2>
            <table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #e5e5e5;" border="1">
                <tbody>
                    <?php foreach ( $company_meta as $meta ) : ?>
                        <?php if ( ! empty( $meta['value'] ) ) : ?>
                            <tr>
                                <th scope="row" style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
                                    <?php echo esc_html( $meta['label'] ); ?>
                                </th>
                                <td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">
                                    <?php echo esc_html( $meta['value'] ); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Display company meta on thank you page
     *
     * @param int $order_id Order ID.
     */
    public function display_thankyou_order_meta( $order_id ) {
        $order = wc_get_order( $order_id );
        
        if ( ! $order || ! $this->order_has_company_info( $order ) ) {
            return;
        }

        $company_meta = $this->get_company_meta( $order );
        $settings = AWCF()->get_settings();
        $defaults = AWCF()->get_default_settings();
        $company_label = $settings['company_information_label'] ?? $defaults['company_information_label'];
        ?>
        <section class="woocommerce-company-details">
            <h2 class="woocommerce-company-details__title"><?php echo esc_html( $company_label ); ?></h2>
            <table class="woocommerce-table woocommerce-table--company-details shop_table company_details">
                <tbody>
                    <?php foreach ( $company_meta as $meta ) : ?>
                        <?php if ( ! empty( $meta['value'] ) ) : ?>
                            <tr>
                                <th><?php echo esc_html( $meta['label'] ); ?>:</th>
                                <td><?php echo esc_html( $meta['value'] ); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    }

    /**
     * Display company meta on order details page (My Account)
     *
     * @param WC_Order $order Order object.
     */
    public function display_order_details_meta( $order ) {
        if ( ! $this->order_has_company_info( $order ) ) {
            return;
        }

        $company_meta = $this->get_company_meta( $order );
        $settings = AWCF()->get_settings();
        $defaults = AWCF()->get_default_settings();
        $company_label = $settings['company_information_label'] ?? $defaults['company_information_label'];
        ?>
        <section class="woocommerce-company-details">
            <h2 class="woocommerce-company-details__title"><?php echo esc_html( $company_label ); ?></h2>
            <table class="woocommerce-table woocommerce-table--company-details shop_table company_details">
                <tbody>
                    <?php foreach ( $company_meta as $meta ) : ?>
                        <?php if ( ! empty( $meta['value'] ) ) : ?>
                            <tr>
                                <th><?php echo esc_html( $meta['label'] ); ?>:</th>
                                <td><?php echo esc_html( $meta['value'] ); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    }
}
