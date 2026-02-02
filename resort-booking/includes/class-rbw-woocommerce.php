<?php
if (!defined('ABSPATH')) exit;

class RBW_WooCommerce {
  public static function init(){
    add_action('woocommerce_before_calculate_totals', [__CLASS__, 'set_deposit_price'], 20);
    add_filter('woocommerce_get_item_data', [__CLASS__, 'show_cart_meta'], 10, 2);
    add_action('woocommerce_checkout_process', [__CLASS__, 'hard_check']);
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_meta'], 10, 4);
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

    $item_data[] = ['name'=>'Room', 'value'=>esc_html($b['room_name'])];
    $item_data[] = ['name'=>'Dates', 'value'=>esc_html($b['check_in'].' → '.$b['check_out'])];
    $item_data[] = ['name'=>'Nights', 'value'=>esc_html($b['nights'])];
    $item_data[] = ['name'=>'Total', 'value'=>wc_price((float)$b['total'])];
    $item_data[] = ['name'=>'Deposit Paid Now', 'value'=>wc_price((float)$b['deposit'])];
    $item_data[] = ['name'=>'Balance Due', 'value'=>wc_price((float)$b['balance'])];
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
        wc_add_notice('দুঃখিত! আপনার সিলেক্ট করা রুমটি এই তারিখে আর ফাঁকা নেই।', 'error');
        return;
      }
    }
  }

  public static function save_meta($item, $cart_item_key, $values, $order){
    if (empty($values['rbw'])) return;
    $b = $values['rbw'];

    $item->add_meta_data('_rbw_room_id', (string)$b['room_id'], true);
    $item->add_meta_data('_rbw_room_name', (string)$b['room_name'], true);
    $item->add_meta_data('_rbw_check_in', (string)$b['check_in'], true);
    $item->add_meta_data('_rbw_check_out', (string)$b['check_out'], true);
    $item->add_meta_data('_rbw_nights', (int)$b['nights'], true);
    $item->add_meta_data('_rbw_price_per_night', (float)$b['price_per_night'], true);
    $item->add_meta_data('_rbw_total', (float)$b['total'], true);
    $item->add_meta_data('_rbw_deposit', (float)$b['deposit'], true);
    $item->add_meta_data('_rbw_balance', (float)$b['balance'], true);
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
}

add_filter('woocommerce_cart_item_name', ['RBW_WooCommerce','filter_cart_item_name'], 10, 2);
