<?php

/**
 * Plugin Name:       Checkout.com & Payoneer WC Payment Gateway Anti-Fraud
 * Description:       Adds anti-fraud capabilities for WooCommerce Checkout.com & Payoneer payment gateways
 * Version:           1.0.1
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Werner C. Bessinger
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ccom-af
 */

defined('ABSPATH') || exit();

/* prevent direct access */
if (!defined('ABSPATH')) :
    exit;
endif;

/**
 * Recaptcha site key and secret
 */
define('RC_SECRET_KEY', '6Lcy3-cUAAAAAEWOUkCIotXLqARURPyXcyX_k5xI');
define('RC_SITE_KEY', '6Lcy3-cUAAAAAL7Jo8mgABWtlR7I0TWJsGAtuKvU');
define('CCOM_RETRY_LIMIT', 6);

/**
 * Include functions
 */
include plugin_dir_path(__FILE__).'functions/custom-order-status.php';
include plugin_dir_path(__FILE__).'functions/active-gateways.php';
include plugin_dir_path(__FILE__).'functions/checkout.com.php';
include plugin_dir_path(__FILE__).'functions/payoneer.php';
include plugin_dir_path(__FILE__).'functions/payment-complete.php';