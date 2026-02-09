<?php
if (!defined('ABSPATH')) exit;

class RBW_WooCommerce {
  public static function init(){
    add_action('woocommerce_before_calculate_totals', [__CLASS__, 'set_deposit_price'], 20);
    add_filter('woocommerce_get_item_data', [__CLASS__, 'show_cart_meta'], 10, 2);
    add_filter('woocommerce_cart_item_thumbnail', [__CLASS__, 'filter_cart_item_thumbnail'], 10, 3);
    add_filter('woocommerce_order_item_get_formatted_meta_data', [__CLASS__, 'format_order_item_meta'], 10, 2);
    add_action('woocommerce_checkout_process', [__CLASS__, 'hard_check']);
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_meta'], 10, 4);
    add_action('woocommerce_thankyou', [__CLASS__, 'render_customer_invoice_cta'], 20);
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
    $group_extra = (float)($b['group_advance_extra'] ?? 0);

    $item_data[] = ['name'=>'Dates', 'value'=>esc_html($b['check_in'].' -> '.$b['check_out'])];
    $item_data[] = ['name'=>'Nights', 'value'=>esc_html($b['nights'])];
    $item_data[] = ['name'=>'Total', 'value'=>wc_price((float)$b['total'])];
    if ($discount > 0) {
      $item_data[] = ['name'=>'Discount', 'value'=>wc_price($discount)];
    }
    $item_data[] = ['name'=>'Pay Now', 'value'=>wc_price($pay_now)];
    $item_data[] = ['name'=>'Balance Due', 'value'=>wc_price((float)$b['balance'])];
    $item_data[] = ['name'=>'Payment Mode', 'value'=>esc_html($pay_mode === 'full' ? 'Full (5% off)' : 'Advance payment')];
    if ($group_extra > 0) {
      $item_data[] = ['name'=>'Group Extra Advance', 'value'=>wc_price($group_extra) . ' per additional room'];
    }
    $item_data[] = ['name'=>'Advance Rule', 'value'=>esc_html('1 room = pay 1000 now. 2+ rooms = pay at least 50% now.')];
    $item_data[] = ['name'=>'Guest Name', 'value'=>esc_html($b['customer_name'])];
    $item_data[] = ['name'=>'Phone', 'value'=>esc_html($b['customer_phone'])];
    $item_data[] = ['name'=>'Guests', 'value'=>esc_html($b['guests'])];
    if (!empty($b['guest_type'])) {
      $label = $b['guest_type'] === 'single' ? 'Single' : ($b['guest_type'] === 'couple' ? 'Couple' : 'Group');
      $item_data[] = ['name'=>'Guest Type', 'value'=>esc_html($label)];
    }
    if (!empty($b['rooms_json']) && is_array($b['rooms_json'])) {
      $list = array_map(function($r){
        $n = $r['room_name'] ?? '';
        $g = $r['guests'] ?? '';
        return $n && $g ? ($n.' ('.$g.' guests)') : $n;
      }, $b['rooms_json']);
      $item_data[] = ['name'=>'Rooms', 'value'=>esc_html(implode(', ', array_filter($list)))];
    }
    if (isset($b['capacity'])) {
      $item_data[] = ['name'=>'Room Capacity', 'value'=>esc_html($b['capacity'])];
    }
    if (isset($b['rooms_needed'])) {
      $item_data[] = ['name'=>'Rooms Needed', 'value'=>esc_html($b['rooms_needed'])];
    }
    return $item_data;
  }

  private static function get_room_image_url($cart_item){
    $b = is_array($cart_item['rbw'] ?? null) ? $cart_item['rbw'] : [];
    $image = esc_url_raw($b['room_image'] ?? '');
    if ($image !== '') return $image;

    $rooms_json = $b['rooms_json'] ?? [];
    if (is_string($rooms_json) && $rooms_json !== '') {
      $decoded = json_decode($rooms_json, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $rooms_json = $decoded;
      } else {
        $rooms_json = [];
      }
    }
    if (is_array($rooms_json) && !empty($rooms_json[0])) {
      $first = $rooms_json[0];
      $image = esc_url_raw($first['image'] ?? '');
      if ($image !== '') return $image;
    }

    $room_id = (string)($b['room_id'] ?? '');
    if ($room_id === '' && is_array($rooms_json) && !empty($rooms_json[0]['room_id'])) {
      $room_id = (string)$rooms_json[0]['room_id'];
    }
    if ($room_id === '') return '';

    $rooms = get_option(RBW_Admin::OPT_ROOMS, []);
    if (!is_array($rooms)) return '';
    foreach ($rooms as $room) {
      $id = (string)($room['id'] ?? ($room['code'] ?? ''));
      if ($id !== $room_id) continue;
      if (!empty($room['images']) && is_array($room['images'])) {
        foreach ($room['images'] as $img) {
          $u = esc_url_raw($img);
          if ($u !== '') return $u;
        }
      }
      $single = esc_url_raw($room['image'] ?? '');
      if ($single !== '') return $single;
    }
    return '';
  }

  public static function filter_cart_item_thumbnail($thumbnail, $cart_item, $cart_item_key){
    if (empty($cart_item['rbw'])) return $thumbnail;
    $room_image = self::get_room_image_url($cart_item);
    if ($room_image === '') return $thumbnail;

    $alt = esc_attr($cart_item['rbw']['room_name'] ?? __('Room', 'rbw'));
    return sprintf(
      '<img src="%s" alt="%s" width="72" height="54" style="object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;">',
      esc_url($room_image),
      $alt
    );
  }

  public static function hard_check(){
    foreach (WC()->cart->get_cart() as $item){
      if (empty($item['rbw'])) continue;
      $b = $item['rbw'];

      $rooms = RBW_Availability::get_available($b['check_in'], $b['check_out']);
      $units_left_by_room = [];
      foreach ($rooms as $r) {
        $rid = (string)($r['room_id'] ?? '');
        if ($rid === '') continue;
        $units_left_by_room[$rid] = (int)($r['units_left'] ?? 0);
      }

      $requested_by_room = [];
      $rooms_json = $b['rooms_json'] ?? [];
      if (is_string($rooms_json) && $rooms_json !== '') {
        $decoded = json_decode($rooms_json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
          $rooms_json = $decoded;
        } else {
          $rooms_json = [];
        }
      }
      if (is_array($rooms_json) && !empty($rooms_json)) {
        foreach ($rooms_json as $row) {
          if (!is_array($row)) continue;
          $rid = (string)($row['room_id'] ?? '');
          if ($rid === '') continue;
          if (!isset($requested_by_room[$rid])) $requested_by_room[$rid] = 0;
          $requested_by_room[$rid] += 1;
        }
      }

      // Backward compatibility for legacy single-room cart payload.
      if (empty($requested_by_room)) {
        $rid = (string)($b['room_id'] ?? '');
        if ($rid !== '') {
          $rooms_needed = (int)($b['rooms_needed'] ?? 1);
          if ($rooms_needed < 1) $rooms_needed = 1;
          $requested_by_room[$rid] = $rooms_needed;
        }
      }

      $ok = !empty($requested_by_room);
      if ($ok) {
        foreach ($requested_by_room as $rid => $need_units) {
          $available_units = (int)($units_left_by_room[$rid] ?? 0);
          if ($available_units < (int)$need_units) {
            $ok = false;
            break;
          }
        }
      }

      if (!$ok) {
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
    if (isset($b['room_image'])) $item->add_meta_data('_rbw_room_image', (string)$b['room_image'], true);
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
    if (isset($b['deposit_total'])) $item->add_meta_data('_rbw_deposit_total', (float)$b['deposit_total'], true);
    if (isset($b['group_code'])) $item->add_meta_data('_rbw_group_code', (string)$b['group_code'], true);
    if (isset($b['group_advance_extra'])) $item->add_meta_data('_rbw_group_advance_extra', (float)$b['group_advance_extra'], true);
    $item->add_meta_data('_rbw_customer_name', (string)$b['customer_name'], true);
    $item->add_meta_data('_rbw_customer_phone', (string)$b['customer_phone'], true);
    $item->add_meta_data('_rbw_guests', (int)$b['guests'], true);
    if (isset($b['guest_type'])) $item->add_meta_data('_rbw_guest_type', (string)$b['guest_type'], true);
    if (isset($b['booking_type'])) $item->add_meta_data('_rbw_booking_type', (string)$b['booking_type'], true);
    if (isset($b['capacity'])) $item->add_meta_data('_rbw_capacity', (int)$b['capacity'], true);
    if (isset($b['rooms_needed'])) $item->add_meta_data('_rbw_rooms_needed', (int)$b['rooms_needed'], true);
    if (!empty($b['rooms_json'])) $item->add_meta_data('_rbw_rooms_json', wp_json_encode($b['rooms_json']), true);
    $item->add_meta_data('_rbw_nid_url', (string)$b['nid_url'], true);
  }

  public static function format_order_item_meta($formatted_meta, $item){
    $room_name = $item->get_meta('_rbw_room_name', true);
    if (empty($room_name)) return $formatted_meta;

    $add = function(&$rows, $label, $value){
      if ($value === '' || $value === null) return;
      $rows[] = (object)[
        'key' => $label,
        'display_key' => $label,
        'value' => $value,
        'display_value' => $value,
      ];
    };

    $rows = [];
    $check_in = (string)$item->get_meta('_rbw_check_in', true);
    $check_out = (string)$item->get_meta('_rbw_check_out', true);
    $nights = (int)$item->get_meta('_rbw_nights', true);
    $total = (float)$item->get_meta('_rbw_total', true);
    $discount = (float)$item->get_meta('_rbw_discount', true);
    $pay_now = $item->get_meta('_rbw_pay_now', true);
    $deposit = (float)$item->get_meta('_rbw_deposit', true);
    $balance = (float)$item->get_meta('_rbw_balance', true);
    $pay_mode = (string)$item->get_meta('_rbw_pay_mode', true);
    $customer_name = (string)$item->get_meta('_rbw_customer_name', true);
    $customer_phone = (string)$item->get_meta('_rbw_customer_phone', true);
    $guests = (int)$item->get_meta('_rbw_guests', true);
    $guest_type = (string)$item->get_meta('_rbw_guest_type', true);
    $capacity = $item->get_meta('_rbw_capacity', true);
    $rooms_needed = $item->get_meta('_rbw_rooms_needed', true);
    $rooms_json = $item->get_meta('_rbw_rooms_json', true);
    $group_code = (string)$item->get_meta('_rbw_group_code', true);
    $group_extra = (float)$item->get_meta('_rbw_group_advance_extra', true);

    $add($rows, 'Room', esc_html($room_name));
    if ($check_in || $check_out) {
      $add($rows, 'Dates', esc_html(trim($check_in.' -> '.$check_out)));
    }
    if ($nights > 0) $add($rows, 'Nights', esc_html($nights));
    if ($total > 0) $add($rows, 'Total', wc_price($total));
    if ($discount > 0) $add($rows, 'Discount', wc_price($discount));
    $pay_now_amount = ($pay_now !== '' && $pay_now !== null) ? (float)$pay_now : $deposit;
    if ($pay_now_amount > 0) $add($rows, 'Pay Now', wc_price($pay_now_amount));
    if ($balance > 0) $add($rows, 'Balance Due', wc_price($balance));
    if (!empty($pay_mode)) {
      $add($rows, 'Payment Mode', esc_html($pay_mode === 'full' ? 'Full (5% off)' : 'Advance payment'));
    }
    if (!empty($group_code)) $add($rows, 'Group', esc_html($group_code));
    if ($group_extra > 0) $add($rows, 'Group Extra Advance', wc_price($group_extra) . ' per additional room');
    $add($rows, 'Advance Rule', esc_html('1 room = pay 1000 now. 2+ rooms = pay at least 50% now.'));
    if (!empty($customer_name)) $add($rows, 'Guest Name', esc_html($customer_name));
    if (!empty($customer_phone)) $add($rows, 'Phone', esc_html($customer_phone));
    if ($guests > 0) $add($rows, 'Guests', esc_html($guests));
    if (!empty($guest_type)) {
      $label = $guest_type === 'single' ? 'Single' : ($guest_type === 'couple' ? 'Couple' : 'Group');
      $add($rows, 'Guest Type', esc_html($label));
    }

    if (!empty($rooms_json)) {
      if (is_string($rooms_json)) {
        $decoded = json_decode($rooms_json, true);
        if (json_last_error() === JSON_ERROR_NONE) $rooms_json = $decoded;
      }
      if (is_array($rooms_json)) {
        $list = array_map(function($r){
          $n = $r['room_name'] ?? '';
          $g = $r['guests'] ?? '';
          return $n && $g ? ($n.' ('.$g.' guests)') : $n;
        }, $rooms_json);
        $add($rows, 'Rooms', esc_html(implode(', ', array_filter($list))));
      }
    }
    if ($capacity !== '' && $capacity !== null) {
      $add($rows, 'Room Capacity', esc_html($capacity));
    }
    if ($rooms_needed !== '' && $rooms_needed !== null) {
      $add($rows, 'Rooms Needed', esc_html($rooms_needed));
    }

    $filtered = [];
    foreach ($formatted_meta as $meta) {
      if (!empty($meta->key) && strpos($meta->key, '_rbw_') === 0) continue;
      $filtered[] = $meta;
    }

    return array_merge($filtered, $rows);
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
      if (class_exists('RBW_Admin')) {
        RBW_Admin::get_customer_invoice_url($booking_id, false);
      }

      wp_clear_scheduled_hook('rbw_maybe_cancel_booking', [$booking_id]);
    }
  }

  public static function render_customer_invoice_cta($order_id){
    if (!$order_id || !class_exists('RBW_Admin')) return;
    if (!(int)get_option(RBW_Admin::OPT_INVOICE_ENABLE_CUSTOMER, 1)) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $links = [];
    foreach ($order->get_items() as $item) {
      $booking_id = (int)$item->get_meta('_rbw_booking_id', true);
      if (!$booking_id) continue;
      $links[$booking_id] = RBW_Admin::get_customer_invoice_url(
        $booking_id,
        (bool)get_option(RBW_Admin::OPT_INVOICE_AUTO_DOWNLOAD, 0)
      );
    }
    $links = array_filter($links);
    if (empty($links)) return;

    echo '<section class="woocommerce-order-details" style="margin-top:20px">';
    echo '<h2>' . esc_html__('Booking Invoice', 'rbw') . '</h2>';
    echo '<p>' . esc_html__('Download or print your booking invoice (safe copy).', 'rbw') . '</p>';
    foreach ($links as $booking_id => $url) {
      echo '<p><a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener">';
      echo esc_html(sprintf(__('Invoice #%d', 'rbw'), (int)$booking_id));
      echo '</a></p>';
    }
    echo '</section>';

    if ((int)get_option(RBW_Admin::OPT_INVOICE_AUTO_DOWNLOAD, 0) === 1) {
      $first = reset($links);
      if ($first) {
        echo '<script>window.addEventListener("load",function(){window.open(' . wp_json_encode($first) . ',"_blank");});</script>';
      }
    }
  }
}

add_filter('woocommerce_cart_item_name', ['RBW_WooCommerce','filter_cart_item_name'], 10, 2);



