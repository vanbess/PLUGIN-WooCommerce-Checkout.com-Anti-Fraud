<?php

/**
 * Plugin Name:       Checkout.com Anti-Fraud
 * Description:       Adds anti-fraud capabilities for WooCommerce Checkout.com payment gateway
 * Version:           1.0.0
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
define('CCOM_RETRY_LIMIT', 5);

/**
 * BEGIN ANTI FRAUD CHECK FOR CHECKOUT.COM  
 */
add_action('wp_footer', function () {

    if (is_checkout()) {

        /**************************************************************
         * IF BANNED USER ID AND/OR BANNED USER ID IS SET IN $_COOKIE,
         * HIDE CHECKOUT.COM PAYMENT METHOD RIGHT OFF THE BAT AND BAIL
         **************************************************************/

        // if user is logged in
        if (is_user_logged_in()) :

            // get current user id
            $user_id = get_current_user_id();

            // check if user id is blocked
            $blocked_id = isset($_COOKIE['ccom_banned_id']) ? $_COOKIE['ccom_banned_id'] : false;

            // if current user id === blocked cookie user id, remove checkout.com payment method
            if ($blocked_id  && $user_id === $blocked_id) : ?>

                <script id="ccom-af-block">
                    jQuery(document).ready(function($) {
                        $(document).ajaxComplete(function(event, xhr, settings) {
                            $(document).find('li.wc_payment_method.payment_method_wc_checkout_com_cards').remove();
                        });
                    });
                </script>

            <?php
            endif;
        endif;

        // get user IP
        $user_ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR'];

        // if current user ip === blocked cookie ip, remove checkout.com payment method
        if (isset($_COOKIE['ccom_banned_ip']) && $user_ip == $_COOKIE['ccom_banned_ip']) : ?>

            <script id="ccom-af-block">
                jQuery(document).ready(function($) {
                    $(document).ajaxComplete(function(event, xhr, settings) {
                        $(document).find('li.wc_payment_method.payment_method_wc_checkout_com_cards').remove();
                    });
                });
            </script>

        <?php
        endif;

        /**********************
         * RECAPTCHA ON SUBMIT
         **********************/
        if (isset($_POST['rc-submit'])) :

            // setup recaptcha vars
            $rc_response_key = $_POST['g-recaptcha-response'];
            $rc_secret_key   = RC_SECRET_KEY;

            // setup recaptcha request url
            $rc_url = "https://www.google.com/recaptcha/api/siteverify?secret=$rc_secret_key&response=$rc_response_key&remoteip=$user_ip";

            // retrieve recaptcha response and decode to object
            $response     = file_get_contents($rc_url);
            $response_obj = json_decode($response);

            // if response is successful, decrement session checkout attempts count if present and above 1
            if ($response_obj->success == 1 && isset($_SESSION['ccom_checkout_attempts']) && $_SESSION['ccom_checkout_attempts'] > 1) :

                // decrement session count
                $_SESSION['ccom_checkout_attempts'] -= 1;

                // register successful recaptcha submissions; if submission count gets to five, user id/ip will be banned for 24 hours
                if (isset($_SESSION['ccom_rc_subs'])) :
                    $_SESSION['ccom_rc_subs'] += 1;
                else :
                    $_SESSION['ccom_rc_subs']  = 1;
                endif;

            endif;

            // if recaptcha response not successful, ban user straightaway
            if ($response_obj->success != 1) :

                // ban user ip for a day
                setcookie('ccom_banned_ip', isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR'], time() + 86400, "/");

                // if user is logged in, ban user id for a day
                if (is_user_logged_in()) :
                    setcookie('ccom_banned_id', get_current_user_id(), time() + 86400, "/");
                endif;

            endif;

            // reload page 
        ?>

            <script>
                window.location.replace('<?php echo wc_get_checkout_url(); ?>');
            </script>

        <?php endif;

        /************************************************************************
         * IF TOTAL RECAPTCHA SUCCESSFUL SUBMITS IS === 5, BAN USER FOR 24 HOURS
         * AND REMOVE CHECKOUT.COM PAYMENT METHOD
         ************************************************************************/
        if (isset($_SESSION['ccom_rc_subs']) && $_SESSION['ccom_rc_subs'] === CCOM_RETRY_LIMIT) :

            // ban user ip for a day
            setcookie('ccom_banned_ip', isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR'], time() + 86400, "/");

            // if user is logged in, ban user id for a day
            if (is_user_logged_in()) :
                setcookie('ccom_banned_id', get_current_user_id(), time() + 86400, "/");
            endif;

        endif;

        ?>

        <!-- recaptcha modal -->
        <div id="rcv2-overlay" style="display: none;"></div>

        <div id="rcv2-modal" style="display: none;">

            <script src="https://www.google.com/recaptcha/api.js" async defer></script>

            <div id="rcv2checknote" class="alert alert-info text-center"><?php echo __('Please prove that you are human:', 'woocommerce'); ?></div>

            <form id="rcv2-ccom-af-form" method="post" action="">
                <div class="g-recaptcha" data-sitekey="<?php echo RC_SITE_KEY; ?>"></div>
                <input id="rc-submit" class="button button-primary" name="rc-submit" type="submit" value="<?php echo __('Submit', 'woocommerce'); ?>">
            </form>
        </div>

        <script id="ccom-af-js">
            $ = jQuery;

            $(document).ajaxComplete(function(event, xhr, settings) {

                // get request url
                var request_url = settings.url;

                // get ajax url check for presence of checkout
                var search = 'wc-ajax=checkout';
                var url_found = request_url.indexOf(search);

                // if is checkout ajax
                if (url_found > 0) {
                    
                    // get payment method and check for presence of checkout.com
                    var data_string = settings.data;
                    var ccom_found = data_string.indexOf('wc_checkout_com_cards');
                    
                    // transaction result
                    var response_json = xhr.responseJSON;

                    // if transaction result is failure and checkout.com is active, update session
                    if (response_json.result === 'failure' && ccom_found > 0) {

                        data = {
                            '_ajax_nonce': '<?php echo wp_create_nonce('ccom anti fraud') ?>',
                            'action': 'ccom_fraud_check_ajax',
                            'user_ip': '<?php echo isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR']; ?>',
                            'user_id': '<?php get_current_user_id() > 0 ? print get_current_user_id() : 'none'; ?>'
                        }

                        $.post('<?php echo admin_url('admin-ajax.php') ?>', data, function(response) {

                            console.log(response);

                            // if response is trigger recaptcha, show recaptcha modal and bail
                            if (response === 'trigger recaptcha') {
                                $('#rcv2-overlay, #rcv2-modal').show();
                                return
                            }

                            // if response is remove ccom method, remove checkout.com from payment method list
                            if (response === 'remove ccom method') {
                                $(document).find('li.wc_payment_method.payment_method_wc_checkout_com_cards').remove();
                            }

                        })
                    }
                }
            });
        </script>

        <style>
            div#rcv2-overlay {
                position: fixed;
                top: 0;
                left: 0;
                z-index: 1000;
                width: 100vw;
                height: 100vh;
                background: #0000009c;
            }

            div#rcv2-modal {
                position: fixed;
                top: 25vh;
                left: 41vw;
                width: 360px;
                height: auto;
                background: white;
                border-radius: 3px;
                box-sizing: border-box;
                padding: 15px;
                z-index: 1001;
            }

            div#rcv2checknote {
                color: white;
                padding: 10px;
                border-radius: 3px;
                margin-bottom: 20px;
            }

            #rcv2-ccom-af-form>.g-recaptcha {
                position: relative;
                left: 14px;
                margin-bottom: 20px;
            }

            input#rc-submit {
                width: 100%;
                margin-bottom: 0;
                font-size: 18px;
                border-radius: 2px;
            }

            form#rcv2-ccom-af-form {
                margin-bottom: 0;
            }
        </style>

<?php }
});

/**
 * AJAX to log checkout attempts to $_SESSION, set banned ip $_COOKIE
 * if checkout attempts === 5 and show recaptcha v2 challenge if 
 * checkout attempts >= 2
 */
add_action('wp_ajax_nopriv_ccom_fraud_check_ajax', 'ccom_fraud_check_ajax');
add_action('wp_ajax_ccom_fraud_check_ajax', 'ccom_fraud_check_ajax');

function ccom_fraud_check_ajax() {

    check_ajax_referer('ccom anti fraud');

    // set checkout attempt limit
    $limit = CCOM_RETRY_LIMIT;

    // increment session checkout attempts if set, else set session checkout attempts
    if (isset($_SESSION['ccom_checkout_attempts'])) :
        $_SESSION['ccom_checkout_attempts'] += 1;
    else :
        $_SESSION['ccom_checkout_attempts'] = 1;
    endif;

    // if checkout attempts >= 2, trigger recaptcha modal
    if ($_SESSION['ccom_checkout_attempts'] >= 2) :
        wp_send_json('trigger recaptcha');
        wp_die();
    endif;

    // if checkout attempts === 5, hide payment method and ban user id/user ip for a day
    if ($_SESSION['ccom_checkout_attempts'] == $limit) {

        // ban user ip for a day
        setcookie('ccom_banned_ip', $_POST['user_ip'], time() + 86400, "/");

        // if user id reveived with request, ban user ID for a day
        if (isset($_POST['user_id']) && $_POST['user_id'] !== 'none') :
            setcookie('ccom_banned_id', $_POST['user_id'], time() + 86400, "/");
        endif;

        // send message to remove payment method
        wp_send_json('remove ccom method');

        wp_die();
    }

    wp_die();
}
