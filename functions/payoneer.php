<?php

/**
 * BEGIN ANTI FRAUD CHECK FOR PAYONEER  
 */
add_action('wp_footer', function () {

    // get woocommerce session data and then chosen payment method
    $wc_session_data       = wc()->session->get_session_data();
    $chosen_payment_method = $wc_session_data['chosen_payment_method'];

    // echo '<pre>';
    // print_r($wc_session_data);
    // echo '</pre>';

    // **************************************************************************
    // if checkout endpoint is order-pay and payment method is payoneer-checkout
    // **************************************************************************
    if (is_wc_endpoint_url('order-pay') && $chosen_payment_method == 'payoneer-checkout') :

        // update order status to flagged - suspected fraud
        $order_id = $wc_session_data['order_awaiting_payment'] ? $wc_session_data['order_awaiting_payment'] : wc_get_order_id_by_order_key($_GET['key']);

        // retrieve payoneer long id
        $long_id = isset($_GET['longId']) ? $_GET['longId'] : get_post_meta($order_id, '_payoneer_payment_charge_id', true);

        // retrieve order object
        $order    = wc_get_order($order_id);

        // add order note
        $order->add_order_note(__('Potential fraud - Payoneer payment failed. Payoneer long ID: ' . $long_id . '. Detailed Payoneer transaction data to follow.', 'woocommerce'), 0, false);

        // if $long_id, retrieve payoneer settings
        if ($long_id) :

            $py_meta        = get_option('woocommerce_payoneer-checkout_settings');
            $is_sandbox     = $py_meta['is_sandbox'];
            $merchant_code  = $py_meta['merchant_code'];
            $merchant_token = $py_meta['merchant_token'];

            // if gateway in sandbox mode, add order note stating as much since we cannot get any valid data from Payoneer in Sandbox mode (yeah, I know)
            if ($is_sandbox == 'yes') :
                $order->add_order_note(__('Could not retrieve data for Payoneer charge ID ' . $long_id . ' because Payoneer payment gateway is in sandbox mode. Detailed Payoneer transaction data to follow.', 'woocommerce'), 0, false);
            endif;

            // setup request url, sandbox or live
            if ($is_sandbox == 'no') :

                // long id used for testing - comment out to disable
                // $long_id = '62ea23f39be4f61492d001acc';

                // basic auth string base64 encoded
                $auth_string = 'Basic ' . base64_encode($merchant_code . ':' . $merchant_token);

                // init curl
                $curl = curl_init();

                // setup request params
                curl_setopt_array($curl, array(
                    CURLOPT_URL            => "https://api.live.oscato.com/api/charges/$long_id",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_MAXREDIRS      => 10,
                    CURLOPT_TIMEOUT        => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST  => 'GET',
                    CURLOPT_HTTPHEADER     => array(
                        "Authorization: $auth_string"
                    ),
                ));

                // send request and get response
                $response = json_decode(curl_exec($curl), true);

                // close curl
                curl_close($curl);

                // retrieve relevant info
                $result_info = $response['resultInfo'];
                $masked_info = $response['maskedAccount'];

                // if result info is not approved, add order note and set order status to follow up
                if ($result_info !== 'Approved') :

                    // update order status
                    $order->update_status('follow-up');

                    // grab info which is to be added to order note
                    $acc_info      = $result_info['accountInfo'];
                    $bank_name     = $acc_info['bankName'];
                    $country       = $acc_info['country'];
                    $card_type     = $acc_info['type'];
                    $card_currency = $acc_info['cardCurrency'];
                    $holder_name   = $masked_info['holderName'];

                    // build order note
                    $note = __("<b>Payoneer payment not approved. Status returned by Payoneer: $result_info</b><br>", "woocommerce");
                    $note .= __("<b>Issuing Bank: $bank_name</b><br>", "woocommerce");
                    $note .= __("<b>Issuing Country: $country</b><br>", "woocommerce");
                    $note .= __("<b>Card Type: $card_type</b><br>", "woocommerce");
                    $note .= __("<b>Card Currency: $card_currency</b><br>", "woocommerce");
                    $note .= __("<b>Holder Name: $holder_name</b><br>", "woocommerce");

                    // add order note
                    $order->add_order_note($note, 0, false);

                endif;

            endif;

        endif;
    endif;

    // check if payoneer is active and bail if not
    $active_gateways = af_active_gateways();

    if ($active_gateways && !in_array('payoneer-checkout', $active_gateways)) :
        return;
    endif;

    if (is_checkout()) {

        /**************************************************************
         * IF BANNED USER ID AND/OR BANNED USER ID IS SET IN $_COOKIE,
         * HIDE PAYONEER PAYMENT METHOD RIGHT OFF THE BAT AND BAIL
         **************************************************************/

        // if user is logged in
        if (is_user_logged_in()) :

            // get current user id
            $user_id = get_current_user_id();

            // check if user id is blocked
            $blocked_id = isset($_COOKIE['payon_banned_id']) ? $_COOKIE['payon_banned_id'] : false;

            // if current user id === blocked cookie user id, remove payoneer payment method
            if ($blocked_id  && $user_id === $blocked_id) : ?>

                <script>
                    jQuery(document).ready(function($) {
                        $(document).ajaxComplete(function(event, xhr, settings) {
                            $(document).find('li.wc_payment_method.payment_method_payoneer-checkout').remove();
                        });
                    });
                </script>

            <?php endif;
        endif;

        // get user IP
        $user_ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR'];

        // if current user ip === blocked cookie ip, remove payoneer payment method
        if (isset($_COOKIE['payon_banned_ip']) && $user_ip == $_COOKIE['payon_banned_ip']) : ?>

            <script>
                jQuery(document).ready(function($) {
                    $(document).ajaxComplete(function(event, xhr, settings) {
                        $(document).find('li.wc_payment_method.payment_method_payoneer-checkout').remove();
                    });
                });
            </script>

        <?php endif;

        /**********************
         * RECAPTCHA ON SUBMIT
         **********************/
        if (isset($_POST['rcv2-payoneer-submit'])) :

            // setup recaptcha vars
            $rc_response_key = $_POST['g-recaptcha-response'];
            $rc_secret_key   = RC_SECRET_KEY;

            // setup recaptcha request url
            $rc_url = "https://www.google.com/recaptcha/api/siteverify?secret=$rc_secret_key&response=$rc_response_key&remoteip=$user_ip";

            // retrieve recaptcha response and decode to object
            $response     = file_get_contents($rc_url);
            $response_obj = json_decode($response);

            // if response is successful, decrement session checkout attempts count if present and above 1
            if ($response_obj->success == 1 && isset($_SESSION['payon_checkout_attempts']) && $_SESSION['payon_checkout_attempts'] > 1) :

                // decrement session count
                $_SESSION['payon_checkout_attempts'] -= 1;

                // register successful recaptcha submissions; if submission count gets to five, user id/ip will be banned for 24 hours
                if (isset($_SESSION['payon_rc_subs'])) :
                    $_SESSION['payon_rc_subs'] += 1;
                else :
                    $_SESSION['payon_rc_subs']  = 1;
                endif;

            endif;

            // if recaptcha response not successful, ban user straightaway
            if ($response_obj->success != 1) :

                // ban user ip for a day
                setcookie('payon_banned_ip', isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR'], time() + 86400, "/");

                // if user is logged in, ban user id for a day
                if (is_user_logged_in()) :
                    setcookie('payon_banned_id', get_current_user_id(), time() + 86400, "/");
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
         * AND REMOVE PAYONEER PAYMENT METHOD
         ************************************************************************/
        if (isset($_SESSION['payon_rc_subs']) && $_SESSION['payon_rc_subs'] === 5) :

            // ban user ip for a day
            setcookie('payon_banned_ip', isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR'], time() + 86400, "/");

            // if user is logged in, ban user id for a day
            if (is_user_logged_in()) :
                setcookie('payon_banned_id', get_current_user_id(), time() + 86400, "/");
            endif;

        endif;

        ?>

        <!-- recaptcha modal -->
        <div id="rcv2-payoneer-overlay" style="display: none;"></div>

        <div id="rcv2-payoneer-modal" style="display: none;">

            <script src="https://www.google.com/recaptcha/api.js" async defer></script>

            <div id="rcv2checknote" class="alert alert-info text-center"><?php echo __('Please prove that you are human:', 'woocommerce'); ?></div>

            <form id="rcv2-payon-af-form" method="post" action="">
                <div class="g-recaptcha" data-sitekey="<?php echo RC_SITE_KEY; ?>"></div>
                <input id="rcv2-payoneer-submit" class="button button-primary" name="rcv2-payoneer-submit" type="submit" value="<?php echo __('Submit', 'woocommerce'); ?>">
            </form>
        </div>

        <script id="payon-af-js">
            $ = jQuery;

            $(document).ajaxComplete(function(event, xhr, settings) {

                // get request url
                var request_url = settings.url;

                // get ajax url check for presence of checkout
                var search = 'wc-ajax=checkout';
                var url_found = request_url.indexOf(search);

                // if is checkout ajax
                if (url_found > 0) {

                    // get payment method and check for presence of payoneer
                    var data_string = settings.data;
                    var payon_found = data_string.indexOf('payoneer-checkout');

                    // transaction result
                    var response_json = xhr.responseJSON;

                    // if transaction result is failure and payoneer is active, update session
                    if (response_json.result === 'failure' && payon_found > 0) {

                        data = {
                            '_ajax_nonce': '<?php echo wp_create_nonce('payoneer anti fraud') ?>',
                            'action': 'payon_fraud_check_ajax',
                            'user_ip': '<?php echo isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR']; ?>',
                            'user_id': '<?php get_current_user_id() > 0 ? print get_current_user_id() : 'none'; ?>'
                        }

                        $.post('<?php echo admin_url('admin-ajax.php') ?>', data, function(response) {

                            console.log('payoneer recaptcha response: ' + response);

                            // if response is trigger recaptcha, show recaptcha modal and bail
                            if (response === 'payoneer trigger recaptcha') {
                                $('#rcv2-payoneer-overlay, #rcv2-payoneer-modal').show();
                                return
                            }

                            // if response is remove ccom method, remove payoneer from payment method list
                            if (response === 'remove payoneer method') {
                                $(document).find('li.wc_payment_method.payment_method_payoneer-checkout').remove();
                            }

                        })
                    }
                }
            });
        </script>

        <style>
            div#rcv2-payoneer-overlay {
                position: fixed;
                top: 0;
                left: 0;
                z-index: 1000;
                width: 100vw;
                height: 100vh;
                background: #0000009c;
            }

            div#rcv2-payoneer-modal {
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

            #rcv2-payon-af-form>.g-recaptcha {
                position: relative;
                left: 14px;
                margin-bottom: 20px;
            }

            input#rcv2-payoneer-submit {
                width: 100%;
                margin-bottom: 0;
                font-size: 18px;
                border-radius: 2px;
            }

            form#rcv2-payon-af-form {
                margin-bottom: 0;
            }
        </style>

<?php }
});

/**
 * AJAX to log checkout attempts to $_SESSION, set banned ip $_COOKIE
 * if checkout attempts === 5 and show recaptcha v2 challenge if 
 * checkout attempts >= 3
 */
add_action('wp_ajax_nopriv_payon_fraud_check_ajax', 'payon_fraud_check_ajax');
add_action('wp_ajax_payon_fraud_check_ajax', 'payon_fraud_check_ajax');

function payon_fraud_check_ajax() {

    check_ajax_referer('payoneer anti fraud');

    // set checkout attempt limit
    $limit = 5;

    // increment session checkout attempts if set, else set session checkout attempts
    if (isset($_SESSION['payon_checkout_attempts'])) :
        $_SESSION['payon_checkout_attempts'] += 1;
    else :
        $_SESSION['payon_checkout_attempts'] = 1;
    endif;

    // if checkout attempts >= 3, trigger recaptcha modal
    if ($_SESSION['payon_checkout_attempts'] > 3) :
        wp_send_json('payoneer trigger recaptcha');
        wp_die();
    endif;

    // if checkout attempts === 5, hide payment method and ban user id/user ip for a day
    if ($_SESSION['payon_checkout_attempts'] >= $limit) {

        // ban user ip for a day
        setcookie('payon_banned_ip', $_POST['user_ip'], time() + 86400, "/");

        // if user id reveived with request, ban user ID for a day
        if (isset($_POST['user_id']) && $_POST['user_id'] !== 'none') :
            setcookie('payon_banned_id', $_POST['user_id'], time() + 86400, "/");
        endif;

        // send message to remove payment method
        wp_send_json('remove payoneer method');

        wp_die();
    }

    wp_die();
}


?>