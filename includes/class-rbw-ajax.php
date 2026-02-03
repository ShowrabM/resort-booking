<?php
if (!defined('ABSPATH')) exit;

class RBW_Ajax {
  public static function init(){
    add_action('wp_ajax_rbw_get_availability', [__CLASS__, 'get_availability']);
    add_action('wp_ajax_nopriv_rbw_get_availability', [__CLASS__, 'get_availability']);
    add_action('wp_ajax_rbw_create_booking', [__CLASS__, 'create_booking']);
    add_action('wp_ajax_nopriv_rbw_create_booking', [__CLASS__, 'create_booking']);
  }

  public static function get_availability(){
    // Nonce check skipped to avoid cached/expired nonce issues on public pages

    $check_in  = sanitize_text_field($_POST['check_in'] ?? '');
    $check_out = sanitize_text_field($_POST['check_out'] ?? '');

    if (!$check_in || !$check_out) {
      error_log('[RBW] availability missing dates');
      wp_send_json_error(['message'=>'Please select dates.']);
    }
    $n = RBW_Availability::nights($check_in, $check_out);
    if ($n <= 0){
      error_log("[RBW] availability nights<=0 in={$check_in} out={$check_out}");
      wp_send_json_error(['message'=>'Check-out must be after check-in.']);
    }

    $room_filter = sanitize_text_field($_POST['room_id'] ?? '');
    $rooms = RBW_Availability::get_available($check_in, $check_out, $room_filter);
    error_log('[RBW] availability ok rooms='.count($rooms));
    wp_send_json_success(['rooms' => $rooms]);
  }

  public static function create_booking(){
    // Nonce check skipped to avoid cached/expired nonce issues on public pages
    if (!class_exists('WooCommerce')) {
      wp_send_json_error(['message'=>'WooCommerce is required for payment.']);
    }

    $room_id = sanitize_text_field($_POST['room_id'] ?? '');
    $room_name = sanitize_text_field($_POST['room_name'] ?? '');
    $check_in = sanitize_text_field($_POST['check_in'] ?? '');
    $check_out = sanitize_text_field($_POST['check_out'] ?? '');
    $nights = (int)($_POST['nights'] ?? 0);
    $ppn = (float)($_POST['price_per_night'] ?? 0);
    $customer_name = sanitize_text_field($_POST['customer_name'] ?? '');
    $customer_phone = sanitize_text_field($_POST['customer_phone'] ?? '');
    $guests = (int)($_POST['guests'] ?? 0);
    $pay_mode = sanitize_text_field($_POST['pay_mode'] ?? 'deposit');
    if ($pay_mode !== 'full') $pay_mode = 'deposit';

    if (!$room_id || !$check_in || !$check_out || !$customer_name || !$customer_phone || $guests <= 0) {
      wp_send_json_error(['message'=>'Missing required data']);
    }

    // Hard availability check
    $rooms = RBW_Availability::get_available($check_in, $check_out);
    $match = null;
    foreach ($rooms as $r){
      if ((string)$r['room_id'] === (string)$room_id){ $match = $r; break; }
    }
    if (!$match){
      wp_send_json_error(['message'=>'Room no longer available for those dates']);
    }
    $guests = max(1, $guests);
    $ppn = (float)($match['price_per_night'] ?? 0);
    $nights = (int)($match['nights'] ?? 0);
    $deposit_setting = (float)($match['deposit'] ?? 0);
    $total = $ppn * $nights * $guests;
    $discount = ($pay_mode === 'full') ? ($total * 0.05) : 0;
    $pay_now = ($pay_mode === 'full') ? max(0, $total - $discount) : $deposit_setting;
    $balance = ($pay_mode === 'full') ? 0 : max(0, $total - $pay_now);
    if (!empty($match['room_name'])) {
      $room_name = sanitize_text_field($match['room_name']);
    }

    // Handle optional NID upload
    $nid_url = '';
    if (!empty($_FILES['nid']) && is_array($_FILES['nid']) && $_FILES['nid']['error'] === UPLOAD_ERR_OK){
      require_once ABSPATH . 'wp-admin/includes/file.php';
      $upload = wp_handle_upload($_FILES['nid'], ['test_form' => false]);
      if (!isset($upload['error']) && isset($upload['url'])){
        $nid_url = esc_url_raw($upload['url']);
      } else {
        wp_send_json_error(['message'=>'NID upload failed: '.($upload['error'] ?? 'unknown error')]);
      }
    }

    // Create booking post (pending until payment completed)
    $booking_id = wp_insert_post([
      'post_type' => 'rbw_booking',
      'post_status' => 'pending',
      'post_title' => sanitize_text_field($customer_name).' - '.sanitize_text_field($room_name),
      'meta_input' => [
        '_rbw_room_id' => $room_id,
        '_rbw_room_name' => $room_name,
        '_rbw_check_in' => $check_in,
        '_rbw_check_out'=> $check_out,
        '_rbw_nights' => $nights,
        '_rbw_total' => $total,
        '_rbw_deposit' => $pay_now,
        '_rbw_balance' => $balance,
        '_rbw_price_per_night' => $ppn,
        '_rbw_customer_name' => $customer_name,
        '_rbw_customer_phone' => $customer_phone,
        '_rbw_guests' => $guests,
        '_rbw_nid_url' => $nid_url,
        '_rbw_pay_mode' => $pay_mode,
        '_rbw_discount' => $discount,
        '_rbw_pay_now' => $pay_now,
        '_rbw_deposit_setting' => $deposit_setting,
        '_rbw_status' => 'pending_payment',
      ]
    ]);

    if (!$booking_id){
      wp_send_json_error(['message'=>'Could not create booking record']);
    }

    // Add virtual payment product to cart with overridden price = pay now
    if (!class_exists('WooCommerce')) {
      wp_delete_post($booking_id, true);
      wp_send_json_error(['message'=>'WooCommerce not active']);
    }

    // Ensure WC session + cart are available on AJAX
    if (function_exists('wc_load_cart')) { wc_load_cart(); }
    if (WC()->session && !WC()->session->has_session()) {
      WC()->session->set_customer_session_cookie(true);
    }
    if (!WC()->cart) {
      wp_delete_post($booking_id, true);
      wp_send_json_error(['message'=>'Cart session not initialized']);
    }

    $deposit_product_id = rbw_ensure_virtual_deposit_product();
    if (!$deposit_product_id){
      wp_delete_post($booking_id, true);
      wp_send_json_error(['message'=>'Could not prepare deposit product']);
    }

    WC()->cart->empty_cart();
    $cart_item_data = [
      'rbw' => [
        'booking_id' => $booking_id,
        'room_id' => $room_id,
        'room_name' => $room_name,
        'check_in' => $check_in,
        'check_out'=> $check_out,
        'nights' => $nights,
        'price_per_night' => $ppn,
        'total' => $total,
        'deposit' => $pay_now,
        'balance' => $balance,
        'customer_name' => $customer_name,
        'customer_phone' => $customer_phone,
        'guests' => $guests,
        'nid_url' => $nid_url,
        'pay_mode' => $pay_mode,
        'discount' => $discount,
        'pay_now' => $pay_now,
        'deposit_setting' => $deposit_setting,
      ]
    ];

    $added = WC()->cart->add_to_cart($deposit_product_id, 1, 0, [], $cart_item_data);
    if (!$added){
      wp_delete_post($booking_id, true);
      wp_send_json_error(['message'=>'Could not add deposit to cart']);
    }

    // Ensure totals reflect deposit price before redirect
    WC()->cart->calculate_totals();

    // Override price to amount paid now
    foreach (WC()->cart->get_cart() as $key => $item){
      if (!empty($item['rbw']['booking_id']) && (int)$item['rbw']['booking_id'] === (int)$booking_id){
        $item['data']->set_price(max(0, $pay_now));
      }
    }

    // Auto-cancel if not paid within 10 minutes
    if (!wp_next_scheduled('rbw_maybe_cancel_booking', [$booking_id])){
      wp_schedule_single_event(time() + 600, 'rbw_maybe_cancel_booking', [$booking_id]);
    }

    $checkout = wc_get_checkout_url();
    wp_send_json_success(['checkout_url' => $checkout]);
  }
}


