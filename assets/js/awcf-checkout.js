/**
 * AWCF Checkout JavaScript
 *
 * Handles:
 * - Conditional VAT company fields show/hide
 * - Disabled field tracking for server-side validation skip
 * - Ship to different address checkbox control
 *
 * @package AWCF
 */

(function($) {
    'use strict';

    /**
     * AWCF Checkout Handler
     */
    var AWCFCheckout = {

        /**
         * Cached elements
         */
        $checkbox: null,
        $companyFields: null,
        $infoMessage: null,
        $disabledFieldsInput: null,
        vatFields: [],

        /**
         * Initialize
         */
        init: function() {
            // Wait for DOM ready
            $(document).ready(this.onReady.bind(this));
        },

        /**
         * DOM Ready handler
         */
        onReady: function() {
            // Get params from localized script
            if (typeof awcf_params === 'undefined') {
                return;
            }

            // Store VAT field keys
            this.vatFields = awcf_params.vat_fields || [];

            // Cache elements
            this.cacheElements();

            // Initialize features based on settings
            if (awcf_params.vat_mode_enabled) {
                this.initVatFields();
            }

            if (awcf_params.force_ship_to_different) {
                this.initShipToDifferent();
            }
        },

        /**
         * Cache DOM elements
         */
        cacheElements: function() {
            this.$checkbox = $('#billing_is_company');
            this.$companyFields = $('.awcf-company-field');
            this.$infoMessage = $('.awcf-company-info-message');
            this.$disabledFieldsInput = $('#awcf_disabled_fields');
        },

        /**
         * Initialize VAT company fields functionality
         */
        initVatFields: function() {
            if (!this.$checkbox.length) {
                return;
            }

            // Bind checkbox change event
            this.$checkbox.on('change', this.toggleCompanyFields.bind(this));

            // Set initial state
            this.toggleCompanyFields();

            // Re-initialize on checkout update (for AJAX updates)
            $(document.body).on('updated_checkout', this.onCheckoutUpdated.bind(this));
        },

        /**
         * Handle checkout update event
         */
        onCheckoutUpdated: function() {
            // Re-cache elements as DOM may have changed
            this.cacheElements();

            // Re-apply current state
            if (this.$checkbox.length) {
                this.toggleCompanyFields();
            }
        },

        /**
         * Toggle company fields visibility and required state
         */
        toggleCompanyFields: function() {
            var isChecked = this.$checkbox.is(':checked');
            var self = this;

            if (isChecked) {
                // Show fields
                this.$companyFields.removeClass('awcf-hidden').addClass('awcf-visible');
                this.$infoMessage.removeClass('awcf-hidden').addClass('awcf-visible');

                // Make fields required
                this.$companyFields.each(function() {
                    self.setFieldRequired($(this), true);
                });

                // Update disabled fields tracking - clear the list since fields are now enabled
                this.updateDisabledFieldsInput([]);

            } else {
                // Hide fields
                this.$companyFields.removeClass('awcf-visible').addClass('awcf-hidden');
                this.$infoMessage.removeClass('awcf-visible').addClass('awcf-hidden');

                // Make fields optional
                this.$companyFields.each(function() {
                    self.setFieldRequired($(this), false);
                });

                // Update disabled fields tracking - add all VAT fields to disabled list
                this.updateDisabledFieldsInput(this.vatFields);

                // Clear field values when hidden (optional, but prevents stale data)
                this.clearHiddenFieldValues();
            }
        },

        /**
         * Set field required state
         *
         * @param {jQuery} $fieldRow Field row element (form-row wrapper)
         * @param {boolean} required Whether field should be required
         */
        setFieldRequired: function($fieldRow, required) {
            var $input = $fieldRow.find('input, select, textarea');
            var $label = $fieldRow.find('label');
            var $abbr = $label.find('abbr.required');
            var $optional = $label.find('.optional');

            if (required) {
                // Add required attribute
                $input.attr('required', 'required').prop('required', true);

                // Add required class to row
                $fieldRow.addClass('validate-required');

                // Add asterisk if not present
                if (!$abbr.length) {
                    $label.append('<abbr class="required" title="required">*</abbr>');
                }

                // Remove optional text
                $optional.remove();

            } else {
                // Remove required attribute
                $input.removeAttr('required').prop('required', false);

                // Remove required class
                $fieldRow.removeClass('validate-required woocommerce-invalid woocommerce-invalid-required-field');

                // Remove asterisk
                $abbr.remove();

                // Add optional text if not present
                if (!$optional.length && awcf_params.optional_text) {
                    $label.append('<span class="optional">' + awcf_params.optional_text + '</span>');
                }
            }
        },

        /**
         * Update the hidden input that tracks disabled fields
         *
         * @param {Array} disabledFields Array of disabled field names
         */
        updateDisabledFieldsInput: function(disabledFields) {
            if (this.$disabledFieldsInput.length) {
                this.$disabledFieldsInput.val(disabledFields.join(','));
            }
        },

        /**
         * Clear values of hidden fields
         */
        clearHiddenFieldValues: function() {
            this.$companyFields.find('input, select, textarea').val('');
        },

        /**
         * Initialize ship to different address functionality
         */
        initShipToDifferent: function() {
            var $shipCheckbox = $('#ship-to-different-address-checkbox');
            var $shippingFields = $('.woocommerce-shipping-fields .shipping_address');

            if (!$shipCheckbox.length) {
                return;
            }

            // Force checkbox to be checked
            $shipCheckbox.prop('checked', true);

            // Ensure shipping fields are visible
            $shippingFields.show();

            // Prevent unchecking if somehow the checkbox becomes visible
            $shipCheckbox.on('change', function() {
                $(this).prop('checked', true);
                $shippingFields.show();
            });

            // Re-apply on checkout update (for AJAX updates)
            $(document.body).on('updated_checkout', this.onCheckoutUpdatedShipToDifferent.bind(this));
        },

        /**
         * Handle checkout update for ship to different address
         */
        onCheckoutUpdatedShipToDifferent: function() {
            if (awcf_params.force_ship_to_different) {
                this.initShipToDifferent();
            }
        }
    };

    // Initialize
    AWCFCheckout.init();

})(jQuery);
