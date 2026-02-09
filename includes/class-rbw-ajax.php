<?php
if (!defined('ABSPATH')) exit;

class RBW_Ajax {
  public static function init(){
    add_action('wp_ajax_rbw_get_availability', [__CLASS__, 'get_availability']);
    add_action('wp_ajax_nopriv_rbw_get_availability', [__CLASS__, 'get_availability']);
    add_action('wp_ajax_rbw_create_booking', [__CLASS__, 'create_booking']);
    add_action('wp_ajax_nopriv_rbw_create_booking', [__CLASS__, 'create_booking']);
    add_action('wp_ajax_rbw_group_full_days', [__CLASS__, 'group_full_days']);
    add_action('wp_ajax_nopriv_rbw_group_full_days', [__CLASS__, 'group_full_days']);
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

    $group_filter = sanitize_text_field($_POST['group'] ?? '');
    $room_filter = sanitize_text_field($_POST['room'] ?? '');
    if ($room_filter !== '') {
      $group_filter = '';
    }
    $rooms = RBW_Availability::get_available($check_in, $check_out, $group_filter, $room_filter);
    error_log('[RBW] availability ok rooms='.count($rooms));
    wp_send_json_success(['rooms' => $rooms]);
  }

  public static function create_booking(){
    // Nonce check skipped to avoid cached/expired nonce issues on public pages
    if (!class_exists('WooCommerce')) {
      wp_send_json_error(['message'=>'WooCommerce is required for payment.']);
    }

    $rooms_raw = wp_unslash($_POST['rooms'] ?? '[]');
    $rooms_in = json_decode($rooms_raw, true);
    if (!is_array($rooms_in)) $rooms_in = [];
    $room_id = '';
    $room_name = '';
    $check_in = sanitize_text_field($_POST['check_in'] ?? '');
    $check_out = sanitize_text_field($_POST['check_out'] ?? '');
    $nights = (int)($_POST['nights'] ?? 0);
    $ppn = (float)($_POST['price_per_night'] ?? 0);
    $customer_name = sanitize_text_field($_POST['customer_name'] ?? '');
    $customer_phone = sanitize_text_field($_POST['customer_phone'] ?? '');
    $guests = (int)($_POST['guests'] ?? 0);
    $pay_mode = sanitize_text_field($_POST['pay_mode'] ?? 'deposit');
    if ($pay_mode !== 'full') $pay_mode = 'deposit';
    $guest_type = sanitize_text_field($_POST['guest_type'] ?? '');
    if (!in_array($guest_type, ['single','couple','group'], true)) {
      if ($guests <= 1) $guest_type = 'single';
      elseif ($guests === 2) $guest_type = 'couple';
      else $guest_type = 'group';
    }
    if ($guest_type === 'single') {
      $guests = max(1, (int)$guests);
    } elseif ($guest_type === 'couple') {
      $guests = max(2, (int)$guests);
      if ($guests % 2 !== 0) {
        $guests += 1;
      }
    } else {
      $guests = max(3, (int)$guests);
    }

    if (!$check_in || !$check_out || !$customer_name || !$customer_phone || $guests <= 0) {
      wp_send_json_error(['message'=>'Missing required data']);
    }

    $group_code = sanitize_title((string)($_POST['group'] ?? ''));
    $group_advance_extra = 0;
    if ($group_code !== '') {
      $groups = get_option(RBW_Admin::OPT_GROUPS, []);
      if (is_array($groups)) {
        foreach ($groups as $g) {
          if (!is_array($g)) continue;
          $gcode = sanitize_title((string)($g['code'] ?? ($g['name'] ?? '')));
          if ($gcode === '' || $gcode !== $group_code) continue;
          $group_advance_extra = max(0, (float)($g['advance_extra'] ?? 0));
          break;
        }
      }
    }

    // Hard availability check
    $available = RBW_Availability::get_available($check_in, $check_out, $group_code, '');
    $by_id = [];
    foreach ($available as $r){
      $by_id[(string)$r['room_id']] = $r;
    }

    $guests = max(1, $guests);
    $nights = (int)($available[0]['nights'] ?? 0);
    $total = 0;
    $discount = 0;
    $pay_now = 0;
    $balance = 0;
    $rooms_payload = [];
    $remaining = $guests;
    $has_deposit_setting = false;
    $room_deposit_sum = 0;

    foreach ($rooms_in as $rsel){
      $rid = sanitize_text_field($rsel['room_id'] ?? '');
      if (!$rid || empty($by_id[$rid])) continue;
      $r = $by_id[$rid];
      $allowed_types = $r['guest_types'] ?? [];
      if (!is_array($allowed_types)) {
        $allowed_types = array_map('trim', explode(',', (string)$allowed_types));
      }
      $allowed_types = array_values(array_intersect(['single','couple','group'], array_map('strval', $allowed_types)));
      if (!empty($allowed_types) && !in_array($guest_type, $allowed_types, true)) {
        wp_send_json_error(['message'=>'Selected room is not available for this guest type.']);
      }
      if ($guest_type === 'single') {
        $capacity = 1;
      } elseif ($guest_type === 'couple') {
        $capacity = 2;
      } else {
        $capacity = 4;
      }
      $assign = min($capacity, $remaining);
      if ($assign <= 0) continue;
      $remaining -= $assign;

      $ppn_single = (float)($r['price_single'] ?? 0);
      $ppn_couple = (float)($r['price_couple'] ?? 0);
      $ppn_group = (float)($r['price_group'] ?? 0);
      if ($guest_type === 'single') {
        $ppn = $ppn_single;
      } elseif ($guest_type === 'couple') {
        $ppn = $ppn_couple > 0 ? $ppn_couple : $ppn_single;
      } else {
        $ppn = $ppn_group > 0 ? $ppn_group : $ppn_single;
      }
      $deposit_setting = (float)($r['deposit'] ?? 0);
      if ($deposit_setting > 0) $has_deposit_setting = true;
      $room_deposit_sum += $deposit_setting;
      $booking_type = 'package';

      if ($guest_type === 'group') {
        $line_total = $ppn * $nights * $assign;
      } else {
        $line_total = $ppn * $nights;
      }
      $line_discount = ($pay_mode === 'full') ? ($line_total * 0.05) : 0;
      $line_pay_now = ($pay_mode === 'full') ? max(0, $line_total - $line_discount) : $deposit_setting;
      $line_balance = ($pay_mode === 'full') ? 0 : max(0, $line_total - $line_pay_now);

      $total += $line_total;
      $discount += $line_discount;
      $pay_now += $line_pay_now;
      $balance += $line_balance;

      $room_image = esc_url_raw($r['image'] ?? '');
      if ($room_image === '' && !empty($r['images']) && is_array($r['images'])) {
        $first_image = reset($r['images']);
        $room_image = esc_url_raw($first_image ?: '');
      }

      $rooms_payload[] = [
        'room_id' => $rid,
        'room_name' => sanitize_text_field($r['room_name'] ?? ''),
        'image' => $room_image,
        'guests' => $assign,
        'capacity' => $capacity,
        'booking_type' => $booking_type,
        'price_per_night' => $ppn,
        'guest_type' => $guest_type,
      ];
    }

    if ($remaining > 0) {
      wp_send_json_error(['message'=>'Not enough total capacity for this guest count']);
    }

    $room_name = $rooms_payload[0]['room_name'] ?? '';
    $rooms_count = count($rooms_payload);
    $group_extra_applied = $group_code !== '' && $rooms_count > 1;

    // Enforce advance payment policy
    if ($pay_mode === 'deposit') {
      if (!$has_deposit_setting) {
        if ($rooms_count === 1) {
          $pay_now = min($total, 1000);
        }
      }
      if ($group_extra_applied) {
        $pay_now = max(0, $pay_now - $room_deposit_sum);
        $has_deposit_setting = false;
      }
      if ($group_extra_applied) {
        $pay_now += ($group_advance_extra * ($rooms_count - 1));
      }
      if ($pay_now > $total) $pay_now = $total;
      $balance = max(0, $total - $pay_now);
    }

    // Handle required NID upload
    $nid_url = '';
    if (empty($_FILES['nid']) || !is_array($_FILES['nid'])) {
      wp_send_json_error(['message'=>'Please upload NID, Driving License, or Card.']);
    }
    if ($_FILES['nid']['error'] !== UPLOAD_ERR_OK) {
      wp_send_json_error(['message'=>'NID upload failed. Please try again.']);
    }
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
        '_rbw_room_id' => $rooms_payload[0]['room_id'] ?? '',
        '_rbw_room_name' => $room_name,
        '_rbw_room_image' => $rooms_payload[0]['image'] ?? '',
        '_rbw_check_in' => $check_in,
        '_rbw_check_out'=> $check_out,
        '_rbw_nights' => $nights,
        '_rbw_total' => $total,
        '_rbw_deposit' => $pay_now,
        '_rbw_balance' => $balance,
        '_rbw_price_per_night' => 0,
        '_rbw_capacity' => 0,
        '_rbw_rooms_needed' => count($rooms_payload),
        '_rbw_booking_type' => 'multi',
        '_rbw_rooms_json' => wp_json_encode($rooms_payload, JSON_UNESCAPED_UNICODE),
        '_rbw_customer_name' => $customer_name,
        '_rbw_customer_phone' => $customer_phone,
        '_rbw_guests' => $guests,
        '_rbw_guest_type' => $guest_type,
        '_rbw_invoice_token' => wp_generate_password(32, false, false),
        '_rbw_nid_url' => $nid_url,
        '_rbw_pay_mode' => $pay_mode,
        '_rbw_discount' => $discount,
        '_rbw_pay_now' => $pay_now,
        '_rbw_deposit_setting' => $deposit_setting,
        '_rbw_group_code' => $group_code,
        '_rbw_group_advance_extra' => $group_advance_extra,
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
        'room_id' => $rooms_payload[0]['room_id'] ?? '',
        'room_name' => $room_name,
        'room_image' => $rooms_payload[0]['image'] ?? '',
        'check_in' => $check_in,
        'check_out'=> $check_out,
        'nights' => $nights,
        'price_per_night' => 0,
        'total' => $total,
        'deposit' => $pay_now,
        'balance' => $balance,
        'capacity' => 0,
        'rooms_needed' => count($rooms_payload),
        'booking_type' => 'multi',
        'customer_name' => $customer_name,
        'customer_phone' => $customer_phone,
        'guests' => $guests,
        'guest_type' => $guest_type,
        'nid_url' => $nid_url,
        'pay_mode' => $pay_mode,
        'discount' => $discount,
        'pay_now' => $pay_now,
        'deposit_setting' => 0,
        'deposit_total' => $pay_now,
        'rooms_json' => $rooms_payload,
        'group_code' => $group_code,
        'group_advance_extra' => $group_advance_extra,
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

  public static function group_full_days(){
    $group = sanitize_text_field($_POST['group'] ?? '');
    if ($group === '') {
      wp_send_json_success(['dates' => []]);
    }
    $year = absint($_POST['year'] ?? 0) ?: (int)date('Y');
    $month = absint($_POST['month'] ?? 0);
    if ($month < 1) $month = (int)date('n');
    if ($month > 12) $month = 12;
    $tz = new DateTimeZone('UTC');
    $dates = [];
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $base = new DateTime("$year-$month-01", $tz);
    for ($day = 1; $day <= $daysInMonth; $day++) {
      $current = clone $base;
      $current->setDate($year, $month, $day);
      $checkIn = $current->format('Y-m-d');
      $checkOut = (clone $current)->modify('+1 day')->format('Y-m-d');
      $rooms = RBW_Availability::get_available($checkIn, $checkOut, $group, '');
      $hasAvailable = false;
      foreach ($rooms as $room) {
        if (($room['units_left'] ?? 0) > 0) {
          $hasAvailable = true;
          break;
        }
      }
      $dates[$checkIn] = !$hasAvailable;
    }
    wp_send_json_success(['dates' => $dates]);
  }
}


