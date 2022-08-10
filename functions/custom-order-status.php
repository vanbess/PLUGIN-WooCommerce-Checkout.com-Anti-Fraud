<?php

/**
 * Register follow-up status for WC for payment attempts which end up at the order-pay endpoint
 */
add_action('admin_head', function () {

    // retrieve current order statuses
    $curr_statuses = wc_get_order_statuses();

    // check if follow-up order status exists and bail if true
    if (key_exists('wc-follow-up', $curr_statuses)) :
        return;
    endif;

    // register custom status
    register_post_status('wc-follow-up', [
        'label'                     => __('Follow Up', 'woocommerce'),
        'public'                    => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list'    => true,
        'exclude_from_search'       => false,
        'label_count'               => _n_noop('Follow Up <span class="count">(%s)</span>', 'Follow Up <span class="count">(%s)</span>')
    ]);
});

// add custom status to order status list
add_filter('wc_order_statuses', function ($order_statuses) {
    $new_ord_statuses = [];

    foreach ($order_statuses as $key => $status) :
        $new_ord_statuses[$key] = $status;

        if ('wc-processing' === $key) :
            $new_ord_statuses['wc-follow-up'] = __('Follow Up', 'woocommerce');
        endif;
    endforeach;

    return $new_ord_statuses;
});
