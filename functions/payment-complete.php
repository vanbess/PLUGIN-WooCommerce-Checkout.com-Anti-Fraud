<?php

/**
 * PAYONEER ON PAYMENT COMPLETE FRAUD CHECK
 */

add_action('woocommerce_thankyou', 'af_checkout_payon_payment_complete');

function af_checkout_payon_payment_complete() {

    // retrieve order id
    $order_id = wc_get_order_id_by_order_key($_GET['key']);

    // retrieve payoneer charge id
    $payoneer_charge_id = get_post_meta($order_id, '_payoneer_payment_charge_id', true);

    // long id used for testing - uncomment to test
    // $payoneer_charge_id = '62ea23f39be4f61492d001acc';

    // retrieve order object
    $order = wc_get_order($order_id);

    // if $payoneer_charge_id, retrieve payoneer settings
    if ($payoneer_charge_id) :

        $py_meta        = get_option('woocommerce_payoneer-checkout_settings');
        $is_sandbox     = $py_meta['is_sandbox'];
        $merchant_code  = $py_meta['merchant_code'];
        $merchant_token = $py_meta['merchant_token'];

        // if gateway in sandbox mode, add order note stating as much since we cannot get any valid data from Payoneer in Sandbox mode (yeah, I know)
        if ($is_sandbox == 'yes') :
            $order->add_order_note(__('Could not retrieve data for Payoneer charge ID ' . $payoneer_charge_id . ' because Payoneer payment gateway is in sandbox mode. Detailed Payoneer transaction data to follow.', 'woocommerce'), 0, false);
        endif;

        // setup request url, sandbox or live
        if ($is_sandbox == 'no') :

            // basic auth string base64 encoded
            $auth_string = 'Basic ' . base64_encode($merchant_code . ':' . $merchant_token);

            // init curl
            $curl = curl_init();

            // setup request params
            curl_setopt_array($curl, array(
                CURLOPT_URL            => "https://api.live.oscato.com/api/charges/$payoneer_charge_id",
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

            // resultInfo used for testing - uncomment to test
            // $result_info = 'Errors: customer.addresses[0].country is invalid, must be 2 uppercase letters according to ISO 3166-1 alpha-2 standard';

            // retrieve masked and account info
            $masked_info = $response['maskedAccount'];
            $acc_info    = $response['accountInfo'];

            // if result info is not approved, add order note and set order status to follow up
            if ($result_info !== 'Approved') :

                // update order status
                $order->update_status('follow-up');

                // grab info which is to be added to order note
                $bank_name     = $acc_info['bankName'];
                $country       = $acc_info['country'];
                $card_type     = $acc_info['type'];
                $card_currency = $acc_info['cardCurrency'];
                $holder_name   = $masked_info['holderName'];

                // build order note
                $note = __("Payoneer payment not approved. Status returned by Payoneer:<br> <b>$result_info</b><br>", "woocommerce");
                $note .= __("Issuing Bank: <b>$bank_name</b><br>", "woocommerce");
                $note .= __("Issuing Country: <b>$country</b><br>", "woocommerce");
                $note .= __("Card Type: <b>$card_type</b><br>", "woocommerce");
                $note .= __("Card Currency: <b>$card_currency</b><br>", "woocommerce");
                $note .= __("Holder Name: <b>$holder_name</b><br>", "woocommerce");

                // add order note
                $order->add_order_note($note, 0, false);

            endif;
        endif;
    endif;
};
