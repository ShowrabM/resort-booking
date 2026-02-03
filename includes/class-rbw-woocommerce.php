<?php
if (!defined('ABSPATH')) exit;

class RBW_WooCommerce {
  public static function init(){
    add_action('woocommerce_before_calculate_totals', [__CLASS__, 'set_deposit_price'], 20);
    add_filter('woocommerce_get_item_data', [__CLASS__, 'show_cart_meta'], 10, 2);
    add_action('woocommerce_checkout_process', [__CLASS__, 'hard_check']);
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_meta'], 10, 4);
    add_action('woocommerce_payment_complete', [__CLASS__, 'confirm_booking']);
    add_action('woocommerce_order_status_processing', [__CLASS__, 'confirm_booking']);
    add_action('woocommerce_order_status_completed', [__CLASS__, 'confirm_booking']);
  }

  public static function set_deposit_price($cart){
    if (is_admin() && !defined('DOING_AJAX')) return;
    foreach ($cart->get_cart() as $item){
      if (!empty($item['rbw']['deposit'])) {
        $item['data']->set_price(max(0, (float)$item['rbw']['deposit']));
        // Set line item name to room name
        if (!empty($item['rbw']['room_name'])) {
          $item['data']->set_name($item['rbw']['room_name']);
        }
      }
    }
  }

  public static function show_cart_meta($item_data, $cart_item){
    if (empty($cart_item['rbw'])) return $item_data;
    $b = $cart_item['rbw'];
    $pay_mode = $b['pay_mode'] ?? 'deposit';
    $discount = (float)($b['discount'] ?? 0);
    $pay_now = isset($b['pay_now']) ? (float)$b['pay_now'] : (float)($b['deposit'] ?? 0);

    $item_data[] = ['name'=>'Room', 'value'=>esc_html($b['room_name'])];
    $item_data[] = ['name'=>'Dates', 'value'=>esc_html($b['check_in'].' -> '.$b['check_out'])];
    $item_data[] = ['name'=>'Nights', 'value'=>esc_html($b['nights'])];
    $item_data[] = ['name'=>'Total', 'value'=>wc_price((float)$b['total'])];
    if ($discount > 0) {
      $item_data[] = ['name'=>'Discount', 'value'=>wc_price($discount)];
    }
    $item_data[] = ['name'=>'Pay Now', 'value'=>wc_price($pay_now)];
    $item_data[] = ['name'=>'Balance Due', 'value'=>wc_price((float)$b['balance'])];
    $item_data[] = ['name'=>'Payment Mode', 'value'=>esc_html($pay_mode === 'full' ? 'Full (5% off)' : 'Advance payment')];
    $item_data[] = ['name'=>'Guest Name', 'value'=>esc_html($b['customer_name'])];
    $item_data[] = ['name'=>'Phone', 'value'=>esc_html($b['customer_phone'])];
    $item_data[] = ['name'=>'Guests', 'value'=>esc_html($b['guests'])];
    return $item_data;
  }

  public static function hard_check(){
    foreach (WC()->cart->get_cart() as $item){
      if (empty($item['rbw'])) continue;
      $b = $item['rbw'];

      $rooms = RBW_Availability::get_available($b['check_in'], $b['check_out']);
      $ok = false;
      foreach ($rooms as $r) {
        if ((string)$r['room_id'] === (string)$b['room_id']) { $ok = true; break; }
      }
      if (!$ok){
        wc_add_notice('Sorry! The selected room is no longer available for those dates.', 'error');
        return;
      }
    }
  }

  public static function save_meta($item, $cart_item_key, $values, $order){
    if (empty($values['rbw'])) return;
    $b = $values['rbw'];

    if (!empty($b['booking_id'])) {
      $item->add_meta_data('_rbw_booking_id', (int)$b['booking_id'], true);
    }
    $item->add_meta_data('_rbw_room_id', (string)$b['room_id'], true);
    $item->add_meta_data('_rbw_room_name', (string)$b['room_name'], true);
    $item->add_meta_data('_rbw_check_in', (string)$b['check_in'], true);
    $item->add_meta_data('_rbw_check_out', (string)$b['check_out'], true);
    $item->add_meta_data('_rbw_nights', (int)$b['nights'], true);
    $item->add_meta_data('_rbw_price_per_night', (float)$b['price_per_night'], true);
    $item->add_meta_data('_rbw_total', (float)$b['total'], true);
    $item->add_meta_data('_rbw_deposit', (float)$b['deposit'], true);
    $item->add_meta_data('_rbw_balance', (float)$b['balance'], true);
    if (isset($b['pay_mode'])) $item->add_meta_data('_rbw_pay_mode', (string)$b['pay_mode'], true);
    if (isset($b['discount'])) $item->add_meta_data('_rbw_discount', (float)$b['discount'], true);
    if (isset($b['pay_now'])) $item->add_meta_data('_rbw_pay_now', (float)$b['pay_now'], true);
    if (isset($b['deposit_setting'])) $item->add_meta_data('_rbw_deposit_setting', (float)$b['deposit_setting'], true);
    $item->add_meta_data('_rbw_customer_name', (string)$b['customer_name'], true);
    $item->add_meta_data('_rbw_customer_phone', (string)$b['customer_phone'], true);
    $item->add_meta_data('_rbw_guests', (int)$b['guests'], true);
    $item->add_meta_data('_rbw_nid_url', (string)$b['nid_url'], true);
  }

  // Display product name as room name in cart/checkout
  public static function filter_cart_item_name($name, $cart_item){
    if (!empty($cart_item['rbw']['room_name'])) {
      return esc_html($cart_item['rbw']['room_name']);
    }
    return $name;
  }

  // On successful payment, mark booking as confirmed and stop auto-cancel.
  public static function confirm_booking($order_id){
    $order = wc_get_order($order_id);
    if (!$order) return;

    foreach ($order->get_items() as $item){
      $booking_id = (int) $item->get_meta('_rbw_booking_id', true);
      if (!$booking_id) continue;

      $post = get_post($booking_id);
      if (!$post || $post->post_type !== 'rbw_booking') continue;

      if ($post->post_status !== 'publish'){
        wp_update_post([
          'ID' => $booking_id,
          'post_status' => 'publish',
        ]);
      }
      update_post_meta($booking_id, '_rbw_status', 'paid');
      update_post_meta($booking_id, '_rbw_order_id', $order_id);

      wp_clear_scheduled_hook('rbw_maybe_cancel_booking', [$booking_id]);
    }
  }
}

add_filter('woocommerce_cart_item_name', ['RBW_WooCommerce','filter_cart_item_name'], 10, 2);



