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
     * Display company meta in admin order details
     *
     * @param WC_Order $order Order object.
     */
    public function display_admin_order_meta( $order ) {
        if ( ! $this->order_has_company_info( $order ) ) {
            return;
        }

        $company_meta = $this->get_company_meta( $order );
        $settings = AWCF()->get_settings();
        $defaults = AWCF()->get_default_settings();
        $company_label = $settings['company_information_label'] ?? $defaults['company_information_label'];
        ?>
        <div class="awcf-admin-company-details" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e5e5;">
            <h3 style="margin-bottom: 10px;"><?php echo esc_html( $company_label ); ?></h3>
            
            <?php foreach ( $company_meta as $meta ) : ?>
                <?php if ( ! empty( $meta['value'] ) ) : ?>
                    <p>
                        <strong><?php echo esc_html( $meta['label'] ); ?>:</strong>
                        <?php echo esc_html( $meta['value'] ); ?>
                    </p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
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
