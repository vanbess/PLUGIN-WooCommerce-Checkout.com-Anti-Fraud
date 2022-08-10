<?php

/**
 * Checks WC payment gateways and returns active ones
 */

function af_active_gateways() {

    $enabled_gateways = [];

    $gateways = wc()->payment_gateways()->get_available_payment_gateways();

    if ($gateways) :
        foreach ($gateways as $gateway) :
            if ($gateway->enabled == 'yes') :
                $enabled_gateways[] = $gateway->id;
            endif;
        endforeach;
    endif;

    return $enabled_gateways;
}
