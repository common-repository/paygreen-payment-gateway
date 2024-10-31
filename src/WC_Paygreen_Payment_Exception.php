<?php
/**
 * WooCommerce Paygreen Payment Exception Class
 *
 * Extends Exception to provide additional data
 *
 * @since 0.0.0
 */

namespace Paygreen\Module;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Paygreen_Payment_Exception extends Exception {

    /**
     * String sanitized/localized error message.
     *
     * @var string */
    protected $localized_message;

    /**
     * Setup exception
     *
     * @since 0.0.0
     * @param string $error_message Full response
     * @param string $localized_message user-friendly translated error message
     */
    public function __construct( $error_message = '', $localized_message = '' ) {
        $this->localized_message = $localized_message;
        parent::__construct( $error_message );
    }

    /**
     * Returns the localized message.
     *
     * @since 0.0.0
     * @return string
     */
    public function getLocalizedMessage() {
        return $this->localized_message;
    }
}
