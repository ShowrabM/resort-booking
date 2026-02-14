<?php
if (!defined('ABSPATH')) exit;

class RBW_Admin {
  const OPT_DEPOSIT_ID = 'rbw_deposit_product_id';
  const OPT_ROOMS  = 'rbw_rooms';
  const OPT_GROUPS = 'rbw_groups';
  const OPT_ROOMS_BACKUP = 'rbw_rooms_backup_last';
  const OPT_GROUPS_BACKUP = 'rbw_groups_backup_last';
  const POST_ROOMS_SUBMITTED = 'rbw_rooms_submitted';
  const POST_GROUPS_SUBMITTED = 'rbw_groups_submitted';
  const OPT_INVOICE_LOGO = 'rbw_invoice_logo';
  const OPT_INVOICE_BUSINESS_NAME = 'rbw_invoice_business_name';
  const OPT_INVOICE_ADDRESS = 'rbw_invoice_address';
  const OPT_INVOICE_PHONE = 'rbw_invoice_phone';
  const OPT_INVOICE_EMAIL = 'rbw_invoice_email';
  const OPT_INVOICE_ACCENT = 'rbw_invoice_accent';
  const OPT_INVOICE_WATERMARK_OPACITY = 'rbw_invoice_watermark_opacity';
  const OPT_INVOICE_ENABLE_CUSTOMER = 'rbw_invoice_enable_customer';
  const OPT_INVOICE_AUTO_DOWNLOAD = 'rbw_invoice_auto_download';
  private static $allow_programmatic_settings_write = false;

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'add_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
    add_action('init', [__CLASS__, 'register_booking_cpt']);
    add_action('admin_post_rbw_cancel_booking', [__CLASS__, 'cancel_booking']);
    add_action('admin_post_rbw_retry_sms', [__CLASS__, 'retry_sms']);
    add_action('admin_post_rbw_invoice_booking', [__CLASS__, 'render_booking_invoice']);
    add_action('admin_post_rbw_customer_invoice', [__CLASS__, 'render_customer_booking_invoice']);
    add_action('admin_post_nopriv_rbw_customer_invoice', [__CLASS__, 'render_customer_booking_invoice']);
    add_action('admin_post_rbw_export_bookings', [__CLASS__, 'export_bookings']);
    add_action('admin_post_rbw_recover_settings', [__CLASS__, 'recover_settings_from_bookings']);
    add_action('admin_post_rbw_restore_settings_backup', [__CLASS__, 'restore_settings_backup']);
  }

  public static function register_booking_cpt(){
    register_post_type('rbw_booking', [
      'label' => __('Bookings', 'rbw'),
      'public' => false,
      'show_ui' => false,
      'menu_icon' => 'dashicons-clipboard',
      'supports' => ['title'],
    ]);
  }

  public static function register_settings(){
    register_setting('rbw_settings', self::OPT_DEPOSIT_ID, [
      'type' => 'integer',
      'sanitize_callback' => 'absint',
      'default' => RBW_DEPOSIT_PRODUCT_ID,
    ]);
    register_setting('rbw_settings', self::OPT_ROOMS, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize_rooms'],
      'default' => [],
    ]);
    register_setting('rbw_settings', self::OPT_GROUPS, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize_groups'],
      'default' => [],
    ]);
    register_setting('rbw_invoice_settings', self::OPT_INVOICE_LOGO, [
      'type' => 'string',
      'sanitize_callback' => 'esc_url_raw',
      'default' => '',
    ]);
    register_setting('rbw_invoice_settings', self::OPT_INVOICE_BUSINESS_NAME, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_text_field',
      'default' => '',
    ]);
    register_setting('rbw_invoice_settings', self::OPT_INVOICE_ADDRESS, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_textarea_field',
      'default' => '',
    ]);
    register_setting('rbw_invoice_settings', self::OPT_INVOICE_PHONE, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_text_field',
      'default' => '',
    ]);
    register_setting('rbw_invoice_settings', self::OPT_INVOICE_EMAIL, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_email',
      'default' => '',
    ]);
    register_setting('rbw_invoice_settings', self::OPT_INVOICE_ACCENT, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_invoice_color'],
      'default' => '#f07a22',
    ]);
    register_setting('rbw_invoice_settings', self::OPT_INVOICE_WATERMARK_OPACITY, [
      'type' => 'number',
      'sanitize_callback' => [__CLASS__, 'sanitize_invoice_opacity'],
      'default' => 0.06,
    ]);
    register_setting('rbw_invoice_settings', self::OPT_INVOICE_ENABLE_CUSTOMER, [
      'type' => 'boolean',
      'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
      'default' => 1,
    ]);
    register_setting('rbw_invoice_settings', self::OPT_INVOICE_AUTO_DOWNLOAD, [
      'type' => 'boolean',
      'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
      'default' => 0,
    ]);
  }

  public static function sanitize_invoice_color($value){
    $color = sanitize_hex_color((string)$value);
    return $color ? $color : '#f07a22';
  }

  public static function sanitize_invoice_opacity($value){
    $num = (float)$value;
    if ($num < 0) $num = 0;
    if ($num > 0.30) $num = 0.30;
    return $num;
  }

  public static function sanitize_checkbox($value){
    return empty($value) ? 0 : 1;
  }

  private static function sanitize_history_date($value){
    $value = trim((string)$value);
    if ($value === '') return '';
    $ts = strtotime($value);
    if ($ts === false) return '';
    return gmdate('Y-m-d', $ts);
  }

  private static function shorten_text($text, $limit = 90){
    $text = trim((string)$text);
    if ($text === '') return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($text) <= $limit) return $text;
      return mb_substr($text, 0, $limit) . '...';
    }
    if (strlen($text) <= $limit) return $text;
    return substr($text, 0, $limit) . '...';
  }

  private static function apply_history_status_filter(array $query_args, $status){
    $status = sanitize_key((string)$status);
    $today = current_time('Y-m-d');

    if ($status === 'completed') {
      $query_args['post_status'] = ['publish'];
      $query_args['meta_query'] = [[
        'key' => '_rbw_check_out',
        'value' => $today,
        'compare' => '<',
        'type' => 'DATE',
      ]];
      return $query_args;
    }

    if ($status === 'publish') {
      $query_args['post_status'] = ['publish'];
      $query_args['meta_query'] = [
        'relation' => 'OR',
        [
          'key' => '_rbw_check_out',
          'value' => $today,
          'compare' => '>=',
          'type' => 'DATE',
        ],
        [
          'key' => '_rbw_check_out',
          'compare' => 'NOT EXISTS',
        ],
        [
          'key' => '_rbw_check_out',
          'value' => '',
          'compare' => '=',
        ],
      ];
      return $query_args;
    }

    $query_args['post_status'] = $status === 'all' ? ['publish', 'pending', 'trash'] : [$status];
    return $query_args;
  }

  private static function get_booking_status_parts($post){
    $post_status = get_post_status($post);
    if ($post_status === 'pending') {
      return ['key' => 'pending', 'label' => __('Pending', 'rbw')];
    }
    if ($post_status === 'trash') {
      return ['key' => 'trash', 'label' => __('Cancelled', 'rbw')];
    }
    if ($post_status === 'publish') {
      $check_out = self::sanitize_history_date(get_post_meta($post->ID, '_rbw_check_out', true));
      $today = current_time('Y-m-d');
      if ($check_out !== '' && $check_out < $today) {
        return ['key' => 'completed', 'label' => __('Completed', 'rbw')];
      }
      return ['key' => 'publish', 'label' => __('Confirmed', 'rbw')];
    }

    return ['key' => 'unknown', 'label' => __('Unknown', 'rbw')];
  }

  private static function get_sms_status_parts($post){
    $booking_id = (int)$post->ID;
    if (!class_exists('RBW_SMS')) {
      return ['key' => 'disabled', 'label' => __('Disabled', 'rbw'), 'detail' => '', 'type' => 'none'];
    }

    $post_status = get_post_status($post);
    $confirm_enabled = (int)get_option(RBW_SMS::OPT_ENABLE, 0) === 1;
    $cancel_enabled = method_exists('RBW_SMS', 'is_cancel_enabled') ? RBW_SMS::is_cancel_enabled() : false;

    if ($post_status === 'trash') {
      if (!$cancel_enabled) {
        return ['key' => 'not_required', 'label' => __('Not Required', 'rbw'), 'detail' => '', 'type' => 'none'];
      }
      $sent_at = trim((string)get_post_meta($booking_id, '_rbw_sms_cancel_sent_at', true));
      if ($sent_at !== '') {
        return ['key' => 'sent', 'label' => __('Sent', 'rbw'), 'detail' => $sent_at, 'type' => 'cancel'];
      }
      $last_error = trim((string)get_post_meta($booking_id, '_rbw_sms_cancel_last_error', true));
      if ($last_error !== '') {
        return ['key' => 'failed', 'label' => __('Failed', 'rbw'), 'detail' => $last_error, 'type' => 'cancel'];
      }
      return ['key' => 'pending', 'label' => __('Not Sent', 'rbw'), 'detail' => '', 'type' => 'cancel'];
    }

    if ($post_status !== 'publish') {
      return ['key' => 'waiting', 'label' => __('Waiting Payment', 'rbw'), 'detail' => '', 'type' => 'none'];
    }
    if (!$confirm_enabled) {
      return ['key' => 'disabled', 'label' => __('Disabled', 'rbw'), 'detail' => '', 'type' => 'none'];
    }

    $sent_at = trim((string)get_post_meta($booking_id, '_rbw_sms_sent_at', true));
    if ($sent_at !== '') {
      return ['key' => 'sent', 'label' => __('Sent', 'rbw'), 'detail' => $sent_at, 'type' => 'booking'];
    }

    $last_error = trim((string)get_post_meta($booking_id, '_rbw_sms_last_error', true));
    if ($last_error !== '') {
      return ['key' => 'failed', 'label' => __('Failed', 'rbw'), 'detail' => $last_error, 'type' => 'booking'];
    }

    return ['key' => 'pending', 'label' => __('Not Sent', 'rbw'), 'detail' => '', 'type' => 'booking'];
  }

  private static function decode_rooms_meta($rooms_meta){
    if (is_string($rooms_meta) && $rooms_meta !== '') {
      $decoded = json_decode($rooms_meta, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $rooms_meta = $decoded;
      }
    }
    return is_array($rooms_meta) ? $rooms_meta : [];
  }

  private static function parse_rooms_meta($rooms_meta){
    $rooms_meta = self::decode_rooms_meta($rooms_meta);
    if (empty($rooms_meta)) return [];

    $out = [];
    foreach ($rooms_meta as $room) {
      if (!is_array($room)) continue;
      $name = sanitize_text_field($room['room_name'] ?? '');
      if ($name === '') continue;
      $guest_count = isset($room['guests']) ? max(0, (int)$room['guests']) : 0;
      $out[] = $guest_count > 0 ? sprintf('%s (%d guests)', $name, $guest_count) : $name;
    }
    return $out;
  }

  private static function get_or_create_invoice_token($booking_id){
    $booking_id = absint($booking_id);
    if (!$booking_id) return '';
    $token = (string)get_post_meta($booking_id, '_rbw_invoice_token', true);
    if ($token !== '') return $token;
    $token = wp_generate_password(32, false, false);
    update_post_meta($booking_id, '_rbw_invoice_token', $token);
    return $token;
  }

  public static function get_customer_invoice_url($booking_id, $download = false){
    $booking_id = absint($booking_id);
    if (!$booking_id) return '';
    $token = self::get_or_create_invoice_token($booking_id);
    if ($token === '') return '';
    $args = [
      'action' => 'rbw_customer_invoice',
      'booking_id' => $booking_id,
      'token' => $token,
    ];
    if ($download) $args['download'] = 1;
    return add_query_arg($args, admin_url('admin-post.php'));
  }

  private static function update_option_programmatic($option, $value){
    self::$allow_programmatic_settings_write = true;
    try {
      return update_option($option, $value, false);
    } finally {
      self::$allow_programmatic_settings_write = false;
    }
  }

  private static function build_recovered_data_from_bookings(){
    $booking_ids = get_posts([
      'post_type' => 'rbw_booking',
      'post_status' => 'any',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'orderby' => 'ID',
      'order' => 'ASC',
    ]);

    $map = [];
    foreach ($booking_ids as $booking_id){
      $rooms_meta = get_post_meta($booking_id, '_rbw_rooms_json', true);
      $rows = self::decode_rooms_meta($rooms_meta);

      if (empty($rows)) {
        $fallback_name = sanitize_text_field((string)get_post_meta($booking_id, '_rbw_room_name', true));
        $fallback_id = sanitize_text_field((string)get_post_meta($booking_id, '_rbw_room_id', true));
        $fallback_image = esc_url_raw((string)get_post_meta($booking_id, '_rbw_room_image', true));
        $fallback_ppn = (float)get_post_meta($booking_id, '_rbw_price_per_night', true);
        $fallback_guest_type = sanitize_text_field((string)get_post_meta($booking_id, '_rbw_guest_type', true));
        if ($fallback_name !== '') {
          $rows[] = [
            'room_id' => $fallback_id,
            'room_name' => $fallback_name,
            'image' => $fallback_image,
            'price_per_night' => $fallback_ppn,
            'guest_type' => $fallback_guest_type,
          ];
        }
      }

      $usage_in_booking = [];
      foreach ($rows as $room){
        if (!is_array($room)) continue;
        $name = sanitize_text_field((string)($room['room_name'] ?? ''));
        if ($name === '') continue;

        $id = sanitize_text_field((string)($room['room_id'] ?? ''));
        $slug_name = sanitize_title($name);
        $key = $id !== '' ? 'id:'.$id : 'name:'.$slug_name;
        if ($key === 'name:') continue;

        if (!isset($map[$key])) {
          $map[$key] = [
            'id' => $id,
            'name' => $name,
            'code' => $slug_name,
            'images' => [],
            'price_single' => 0,
            'price_couple' => 0,
            'price_group' => 0,
            'stock' => 0,
          ];
        }

        if ($map[$key]['id'] === '' && $id !== '') {
          $map[$key]['id'] = $id;
        }

        $img = esc_url_raw((string)($room['image'] ?? ''));
        if ($img !== '') {
          $map[$key]['images'][$img] = true;
        }

        $guest_type = sanitize_text_field((string)($room['guest_type'] ?? ''));
        $ppn = (float)($room['price_per_night'] ?? 0);
        if ($ppn > 0) {
          if ($guest_type === 'couple') {
            $map[$key]['price_couple'] = max((float)$map[$key]['price_couple'], $ppn);
          } elseif ($guest_type === 'group') {
            $map[$key]['price_group'] = max((float)$map[$key]['price_group'], $ppn);
          } else {
            $map[$key]['price_single'] = max((float)$map[$key]['price_single'], $ppn);
          }
        }

        if (!isset($usage_in_booking[$key])) $usage_in_booking[$key] = 0;
        $usage_in_booking[$key]++;
      }

      foreach ($usage_in_booking as $key => $qty) {
        if (!isset($map[$key])) continue;
        $map[$key]['stock'] = max((int)$map[$key]['stock'], (int)$qty);
      }
    }

    $rooms = [];
    $used_codes = [];
    $used_ids = [];
    $idx = 1;

    foreach ($map as $room) {
      $name = sanitize_text_field((string)($room['name'] ?? ''));
      if ($name === '') continue;

      $code = sanitize_title((string)($room['code'] ?? ''));
      if ($code === '') $code = 'room'.$idx;
      $base_code = $code;
      $c = 1;
      while (isset($used_codes[$code])) {
        $code = $base_code.'-'.$c;
        $c++;
      }
      $used_codes[$code] = true;

      $id = sanitize_text_field((string)($room['id'] ?? ''));
      if ($id === '') $id = $code;
      $base_id = $id;
      $x = 1;
      while (isset($used_ids[$id])) {
        $id = $base_id.'_'.$x;
        $x++;
      }
      $used_ids[$id] = true;

      $price_single = max(0, (float)($room['price_single'] ?? 0));
      $price_couple = max(0, (float)($room['price_couple'] ?? 0));
      $price_group = max(0, (float)($room['price_group'] ?? 0));
      $fallback_price = 0;
      if ($price_single > 0) $fallback_price = $price_single;
      if ($fallback_price <= 0 && $price_couple > 0) $fallback_price = $price_couple;
      if ($fallback_price <= 0 && $price_group > 0) $fallback_price = $price_group;
      if ($price_single <= 0) $price_single = $fallback_price;
      if ($price_couple <= 0) $price_couple = $fallback_price;
      if ($price_group <= 0) $price_group = $fallback_price;

      $images = [];
      if (!empty($room['images']) && is_array($room['images'])) {
        foreach (array_keys($room['images']) as $img) {
          $u = esc_url_raw((string)$img);
          if ($u !== '') $images[] = $u;
        }
      }
      $images = array_values(array_unique($images));

      $rooms[] = [
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'price' => $price_single,
        'price_single' => $price_single,
        'price_couple' => $price_couple,
        'price_group' => $price_group,
        'image' => $images[0] ?? '',
        'images' => $images,
        'stock' => max(0, (int)($room['stock'] ?? 0)),
        'capacity' => 4,
        'deposit' => 0,
        'guest_types' => ['single','couple','group'],
        'booking_type' => 'entire_room',
      ];
      $idx++;
    }

    usort($rooms, function($a, $b){
      return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $groups = [];
    if (!empty($rooms)) {
      $groups[] = [
        'name' => __('Recovered Group', 'rbw'),
        'code' => 'recovered-group',
        'rooms' => array_values(array_filter(array_map(function($room){
          return sanitize_text_field((string)($room['id'] ?? ''));
        }, $rooms))),
      ];
    }

    return [
      'rooms' => $rooms,
      'groups' => $groups,
    ];
  }

  public static function restore_settings_backup(){
    if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'rbw'));
    check_admin_referer('rbw_restore_settings_backup');

    $restored_rooms = 0;
    $restored_groups = 0;

    $rooms_backup = get_option(self::OPT_ROOMS_BACKUP, []);
    if (is_array($rooms_backup) && !empty($rooms_backup)) {
      self::update_option_programmatic(self::OPT_ROOMS, $rooms_backup);
      $restored_rooms = count($rooms_backup);
    }

    $groups_backup = get_option(self::OPT_GROUPS_BACKUP, []);
    if (is_array($groups_backup) && !empty($groups_backup)) {
      self::update_option_programmatic(self::OPT_GROUPS, $groups_backup);
      $restored_groups = count($groups_backup);
    }

    $url = add_query_arg([
      'page' => 'rbw-settings',
      'rbw_restored' => $restored_rooms,
      'rbw_restored_groups' => $restored_groups,
    ], admin_url('admin.php'));
    wp_safe_redirect($url);
    exit;
  }

  public static function recover_settings_from_bookings(){
    if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'rbw'));
    check_admin_referer('rbw_recover_settings');

    $existing_rooms = get_option(self::OPT_ROOMS, []);
    if (!is_array($existing_rooms)) $existing_rooms = [];
    $existing_groups = get_option(self::OPT_GROUPS, []);
    if (!is_array($existing_groups)) $existing_groups = [];

    $recovered = self::build_recovered_data_from_bookings();
    $recovered_rooms = is_array($recovered['rooms'] ?? null) ? $recovered['rooms'] : [];
    $recovered_groups = is_array($recovered['groups'] ?? null) ? $recovered['groups'] : [];

    $seen = [];
    foreach ($existing_rooms as $room) {
      if (!is_array($room)) continue;
      $rid = sanitize_text_field((string)($room['id'] ?? ''));
      $rname = sanitize_title((string)($room['name'] ?? ''));
      if ($rid !== '') $seen['id:'.$rid] = true;
      if ($rname !== '') $seen['name:'.$rname] = true;
    }

    $added = 0;
    foreach ($recovered_rooms as $room) {
      if (!is_array($room)) continue;
      $rid = sanitize_text_field((string)($room['id'] ?? ''));
      $rname = sanitize_title((string)($room['name'] ?? ''));
      $keys = [];
      if ($rid !== '') $keys[] = 'id:'.$rid;
      if ($rname !== '') $keys[] = 'name:'.$rname;
      $exists = false;
      foreach ($keys as $k) {
        if (isset($seen[$k])) {
          $exists = true;
          break;
        }
      }
      if ($exists) continue;

      $existing_rooms[] = $room;
      foreach ($keys as $k) $seen[$k] = true;
      $added++;
    }

    if ($added > 0) {
      self::update_option_programmatic(self::OPT_ROOMS, $existing_rooms);
    }

    $groups_added = 0;
    if (empty($existing_groups) && !empty($recovered_groups)) {
      self::update_option_programmatic(self::OPT_GROUPS, $recovered_groups);
      $groups_added = count($recovered_groups);
    }

    $url = add_query_arg([
      'page' => 'rbw-settings',
      'rbw_recovered' => $added,
      'rbw_recovered_groups' => $groups_added,
    ], admin_url('admin.php'));
    wp_safe_redirect($url);
    exit;
  }

  public static function sanitize_rooms($value){
    if (!self::$allow_programmatic_settings_write && !isset($_POST[self::POST_ROOMS_SUBMITTED])) {
      $existing = get_option(self::OPT_ROOMS, []);
      return is_array($existing) ? $existing : [];
    }

    $existing = get_option(self::OPT_ROOMS, []);
    if (is_array($existing) && !empty($existing)) {
      update_option(self::OPT_ROOMS_BACKUP, $existing, false);
    }

    $clean = [];
    if (!is_array($value)) return [];

    $used_codes = [];
    $used_ids = [];

    foreach ($value as $idx => $item){
      $id = !empty($item['id']) ? sanitize_text_field($item['id']) : uniqid('room_', true);
      $name = sanitize_text_field($item['name'] ?? '');
      $price = isset($item['price']) ? floatval($item['price']) : 0;
      $price_single = isset($item['price_single']) ? floatval($item['price_single']) : 0;
      if ($price_single <= 0 && $price > 0) {
        $price_single = $price;
      }
      $price_couple = isset($item['price_couple']) ? floatval($item['price_couple']) : 0;
      $price_group = isset($item['price_group']) ? floatval($item['price_group']) : 0;
      $image = isset($item['image']) ? esc_url_raw($item['image']) : '';
      $images = [];
      if (isset($item['images'])) {
        $raw_images = $item['images'];
        if (is_array($raw_images)) {
          foreach ($raw_images as $img) {
            $u = esc_url_raw($img);
            if ($u !== '') $images[] = $u;
          }
        } elseif (is_string($raw_images) && $raw_images !== '') {
          $parts = array_map('trim', explode(',', $raw_images));
          foreach ($parts as $img) {
            $u = esc_url_raw($img);
            if ($u !== '') $images[] = $u;
          }
        }
      }
      if (empty($images) && $image !== '') {
        $images[] = $image;
      }
      $images = array_values(array_unique($images));
      $image = $images[0] ?? '';
      $stock = isset($item['stock']) ? intval($item['stock']) : 0;
      $capacity = isset($item['capacity']) ? intval($item['capacity']) : 0;
      $deposit = isset($item['deposit']) ? floatval($item['deposit']) : 0;
      $guest_types = [];
      if (isset($item['guest_types'])) {
        $raw_types = $item['guest_types'];
        if (!is_array($raw_types)) {
          $raw_types = array_map('trim', explode(',', (string)$raw_types));
        }
        foreach ($raw_types as $t) {
          $t = sanitize_text_field((string)$t);
          if (in_array($t, ['single','couple','group'], true)) $guest_types[] = $t;
        }
      }
      $guest_types = array_values(array_unique($guest_types));
      if (empty($guest_types)) {
        $guest_types = ['single','couple','group'];
      }
      $code_raw = isset($item['code']) ? $item['code'] : '';
      $booking_type = isset($item['booking_type']) ? sanitize_text_field($item['booking_type']) : 'per_person';
      $group_owner = sanitize_title((string)($item['group_owner'] ?? ''));
      if ($name === '') continue;

      // Build short, human-friendly code
      $code = sanitize_title($code_raw);
      if ($code === '') {
        $code = 'room'.($idx+1);
      }
      // Ensure uniqueness
      $base = $code; $c = 1;
      while (isset($used_codes[$code])) {
        $code = $base.'-'.$c;
        $c++;
      }
      $used_codes[$code] = true;
      $used_ids[$id] = true;

      // Base price is deprecated; always mirror single price to avoid old data usage
      $price = $price_single;
      $clean[] = [
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'price' => max(0, $price),
        'price_single' => max(0, $price_single),
        'price_couple' => max(0, $price_couple),
        'price_group' => max(0, $price_group),
        'image' => $image,
        'images' => $images,
        'stock' => max(0, $stock),
        'capacity' => 4,
        'deposit' => max(0, $deposit),
        'guest_types' => $guest_types,
        'booking_type' => 'entire_room',
        'group_owner' => $group_owner,
      ];
    }
    return $clean;
  }

  public static function sanitize_groups($value){
    if (!self::$allow_programmatic_settings_write && !isset($_POST[self::POST_GROUPS_SUBMITTED])) {
      $existing = get_option(self::OPT_GROUPS, []);
      return is_array($existing) ? $existing : [];
    }

    $existing = get_option(self::OPT_GROUPS, []);
    if (is_array($existing) && !empty($existing)) {
      update_option(self::OPT_GROUPS_BACKUP, $existing, false);
    }

    $clean = [];
    if (!is_array($value)) return [];

    $room_owner_by_id = [];
    $rooms_source = isset($_POST[self::OPT_ROOMS]) ? wp_unslash($_POST[self::OPT_ROOMS]) : get_option(self::OPT_ROOMS, []);
    if (is_array($rooms_source)) {
      foreach ($rooms_source as $room_item) {
        if (!is_array($room_item)) continue;
        $rid = sanitize_text_field((string)($room_item['id'] ?? ''));
        if ($rid === '') continue;
        $room_owner_by_id[$rid] = sanitize_title((string)($room_item['group_owner'] ?? ''));
      }
    }

    $used_codes = [];
    foreach ($value as $idx => $item){
      $name = sanitize_text_field($item['name'] ?? '');
      if ($name === '') continue;
      $code = sanitize_title($name);
      if ($code === '') $code = 'group'.($idx+1);
      $base = $code; $c = 1;
      while (isset($used_codes[$code])) {
        $code = $base.'-'.$c;
        $c++;
      }
      $used_codes[$code] = true;

      $rooms = [];
      if (!empty($item['rooms']) && is_array($item['rooms'])) {
        foreach ($item['rooms'] as $rid){
          $rid = sanitize_text_field($rid);
          if ($rid === '') continue;
          $owner = sanitize_title((string)($room_owner_by_id[$rid] ?? ''));
          if ($owner !== '' && $owner !== $code) continue;
          $rooms[] = $rid;
        }
      }
      $advance_extra = isset($item['advance_extra']) ? floatval($item['advance_extra']) : 0;

      $clean[] = [
        'name' => $name,
        'code' => $code,
        'rooms' => array_values(array_unique($rooms)),
        'advance_extra' => max(0, $advance_extra),
      ];
    }
    return $clean;
  }

  public static function add_menu(){
    add_menu_page(
      __('Resort Booking', 'rbw'),
      __('Resort Booking', 'rbw'),
      'manage_options',
      'rbw-settings',
      [__CLASS__, 'render_settings_page'],
      'dashicons-building'
    );

    add_submenu_page(
      'rbw-settings',
      __('Bookings', 'rbw'),
      __('Bookings', 'rbw'),
      'manage_options',
      'rbw-bookings',
      [__CLASS__, 'render_bookings_page']
    );

    add_submenu_page(
      'rbw-settings',
      __('Invoice Settings', 'rbw'),
      __('Invoice Settings', 'rbw'),
      'manage_options',
      'rbw-invoice-settings',
      [__CLASS__, 'render_invoice_settings_page']
    );
  }

  public static function render_bookings_page(){
    $from_date = self::sanitize_history_date($_GET['from_date'] ?? '');
    $to_date = self::sanitize_history_date($_GET['to_date'] ?? '');
    $status = sanitize_key((string)($_GET['status'] ?? 'all'));
    if (!in_array($status, ['all', 'publish', 'completed', 'pending', 'trash'], true)) $status = 'all';
    $sort = strtolower(sanitize_text_field((string)($_GET['sort'] ?? 'desc')));
    if (!in_array($sort, ['asc', 'desc'], true)) $sort = 'desc';

    $paged = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 25;
    $query_args = [
      'post_type' => 'rbw_booking',
      'posts_per_page' => $per_page,
      'paged' => $paged,
      'orderby' => 'date',
      'order' => strtoupper($sort),
    ];
    $query_args = self::apply_history_status_filter($query_args, $status);
    $date_query = [];
    if ($from_date !== '') $date_query['after'] = $from_date;
    if ($to_date !== '') $date_query['before'] = $to_date;
    if (!empty($date_query)) {
      $date_query['inclusive'] = true;
      $date_query['column'] = 'post_date';
      $query_args['date_query'] = [$date_query];
    }

    $query = new WP_Query($query_args);
    $total = $query->found_posts;
    $bookings = $query->posts;

    $filter_args = ['page' => 'rbw-bookings'];
    if ($from_date !== '') $filter_args['from_date'] = $from_date;
    if ($to_date !== '') $filter_args['to_date'] = $to_date;
    if ($status !== 'all') $filter_args['status'] = $status;
    if ($sort !== 'desc') $filter_args['sort'] = $sort;

    $current_url = add_query_arg(array_merge($filter_args, ['paged' => $paged]), admin_url('admin.php'));
    $export_args = ['action' => 'rbw_export_bookings'];
    if ($from_date !== '') $export_args['from_date'] = $from_date;
    if ($to_date !== '') $export_args['to_date'] = $to_date;
    if ($status !== 'all') $export_args['status'] = $status;
    if ($sort !== 'desc') $export_args['sort'] = $sort;

    $sms_notice = sanitize_key((string)($_GET['rbw_sms'] ?? ''));
    $sms_notice_msg = sanitize_text_field((string)($_GET['rbw_sms_msg'] ?? ''));
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Booking History', 'rbw'); ?> <span class="subtitle">(<?php echo intval($total); ?>)</span></h1>
      <?php if ($sms_notice === 'sent'): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('SMS sent successfully.', 'rbw'); ?></p></div>
      <?php elseif ($sms_notice === 'error'): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($sms_notice_msg !== '' ? $sms_notice_msg : __('SMS send failed.', 'rbw')); ?></p></div>
      <?php elseif ($sms_notice === 'missing'): ?>
        <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('SMS module is not available.', 'rbw'); ?></p></div>
      <?php endif; ?>

      <div class="rbw-history-filters">
        <form method="get" class="rbw-history-form">
          <input type="hidden" name="page" value="rbw-bookings">
          <label>
            <span><?php esc_html_e('From', 'rbw'); ?></span>
            <input type="date" name="from_date" value="<?php echo esc_attr($from_date); ?>">
          </label>
          <label>
            <span><?php esc_html_e('To', 'rbw'); ?></span>
            <input type="date" name="to_date" value="<?php echo esc_attr($to_date); ?>">
          </label>
          <label>
            <span><?php esc_html_e('Status', 'rbw'); ?></span>
            <select name="status">
              <option value="all" <?php selected($status, 'all'); ?>><?php esc_html_e('All', 'rbw'); ?></option>
              <option value="publish" <?php selected($status, 'publish'); ?>><?php esc_html_e('Confirmed', 'rbw'); ?></option>
              <option value="completed" <?php selected($status, 'completed'); ?>><?php esc_html_e('Completed', 'rbw'); ?></option>
              <option value="pending" <?php selected($status, 'pending'); ?>><?php esc_html_e('Pending', 'rbw'); ?></option>
              <option value="trash" <?php selected($status, 'trash'); ?>><?php esc_html_e('Cancelled', 'rbw'); ?></option>
            </select>
          </label>
          <label>
            <span><?php esc_html_e('Sort', 'rbw'); ?></span>
            <select name="sort">
              <option value="desc" <?php selected($sort, 'desc'); ?>><?php esc_html_e('Newest First', 'rbw'); ?></option>
              <option value="asc" <?php selected($sort, 'asc'); ?>><?php esc_html_e('Oldest First', 'rbw'); ?></option>
            </select>
          </label>
          <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'rbw'); ?></button>
          <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'rbw-bookings'], admin_url('admin.php'))); ?>"><?php esc_html_e('Reset', 'rbw'); ?></a>
        </form>
        <div class="rbw-history-actions">
          <?php
            $export_url = wp_nonce_url(
              add_query_arg($export_args, admin_url('admin-post.php')),
              'rbw_export_bookings'
            );
          ?>
          <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">
            <?php esc_html_e('Download Filtered CSV', 'rbw'); ?>
          </a>
        </div>
      </div>

      <?php if (empty($bookings)): ?>
        <p class="rbw-history-empty"><?php esc_html_e('No booking history found for the selected filters.', 'rbw'); ?></p>
      <?php else: ?>
        <div class="rbw-history-summary">
          <?php echo esc_html(sprintf(__('Showing %d booking(s)', 'rbw'), (int)$total)); ?>
        </div>
        <div class="rbw-admin-table-wrap">
        <table class="widefat striped rbw-admin-table">
          <thead>
            <tr>
              <th><?php esc_html_e('Booking ID', 'rbw'); ?></th>
              <th><?php esc_html_e('Created', 'rbw'); ?></th>
              <th><?php esc_html_e('Status', 'rbw'); ?></th>
              <th><?php esc_html_e('Rooms', 'rbw'); ?></th>
              <th><?php esc_html_e('Guest', 'rbw'); ?></th>
              <th><?php esc_html_e('Phone', 'rbw'); ?></th>
              <th><?php esc_html_e('Guests', 'rbw'); ?></th>
              <th><?php esc_html_e('Dates', 'rbw'); ?></th>
              <th><?php esc_html_e('Deposit', 'rbw'); ?></th>
              <th><?php esc_html_e('Due', 'rbw'); ?></th>
              <th><?php esc_html_e('SMS', 'rbw'); ?></th>
              <th><?php esc_html_e('NID', 'rbw'); ?></th>
              <th><?php esc_html_e('Actions', 'rbw'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bookings as $post):
              $room = get_post_meta($post->ID, '_rbw_room_name', true);
              $rooms_json = get_post_meta($post->ID, '_rbw_rooms_json', true);
              $room_parts = self::parse_rooms_meta($rooms_json);
              $room_display = !empty($room_parts) ? implode(', ', $room_parts) : ($room ?: $post->post_title);
              $check_in = get_post_meta($post->ID, '_rbw_check_in', true);
              $check_out = get_post_meta($post->ID, '_rbw_check_out', true);
              $deposit = get_post_meta($post->ID, '_rbw_deposit', true);
              $balance = get_post_meta($post->ID, '_rbw_balance', true);
              $guest = get_post_meta($post->ID, '_rbw_customer_name', true);
              $phone = get_post_meta($post->ID, '_rbw_customer_phone', true);
              $guests = get_post_meta($post->ID, '_rbw_guests', true);
              $nid = get_post_meta($post->ID, '_rbw_nid_url', true);
              $status_parts = self::get_booking_status_parts($post);
              $status_key = $status_parts['key'];
              $status_label = $status_parts['label'];
              $sms_parts = self::get_sms_status_parts($post);
              $sms_key = $sms_parts['key'];
              $sms_label = $sms_parts['label'];
              $sms_detail = (string)$sms_parts['detail'];
              $sms_type = (string)($sms_parts['type'] ?? 'none');
              $sms_detail_short = self::shorten_text($sms_detail, 90);
              $cancel_url = wp_nonce_url(
                add_query_arg([
                  'action' => 'rbw_cancel_booking',
                  'booking_id' => $post->ID,
                  'redirect_to' => urlencode($current_url),
                ], admin_url('admin-post.php')),
                'rbw_cancel_booking_'.$post->ID
              );
              $invoice_url = wp_nonce_url(
                add_query_arg([
                  'action' => 'rbw_invoice_booking',
                  'booking_id' => $post->ID,
                ], admin_url('admin-post.php')),
                'rbw_invoice_booking_'.$post->ID
              );
              $retry_sms_url = wp_nonce_url(
                add_query_arg([
                  'action' => 'rbw_retry_sms',
                  'booking_id' => $post->ID,
                  'sms_type' => $sms_type,
                  'redirect_to' => urlencode($current_url),
                ], admin_url('admin-post.php')),
                'rbw_retry_sms_'.$post->ID
              );
              $can_retry_sms = class_exists('RBW_SMS')
                && in_array($sms_type, ['booking', 'cancel'], true)
                && in_array($sms_key, ['failed', 'pending'], true);
              ?>
              <tr>
                <td class="rbw-booking-id">#<?php echo esc_html($post->ID); ?></td>
                <td><?php echo esc_html(get_the_time(get_option('date_format') . ' ' . get_option('time_format'), $post)); ?></td>
                <td><span class="rbw-status rbw-status-<?php echo esc_attr($status_key); ?>"><?php echo esc_html($status_label); ?></span></td>
                <td class="rbw-room-cell" title="<?php echo esc_attr($room_display); ?>"><?php echo esc_html($room_display); ?></td>
                <td><?php echo esc_html($guest); ?></td>
                <td>
                  <a class="rbw-phone-link" href="tel:<?php echo esc_attr($phone); ?>"><?php echo esc_html($phone); ?></a>
                </td>
                <td><?php echo esc_html($guests); ?></td>
                <td><?php echo esc_html($check_in . ' -> ' . $check_out); ?></td>
                <td><?php echo wc_price((float)$deposit); ?></td>
                <td><?php echo wc_price((float)$balance); ?></td>
                <td class="rbw-sms-cell">
                  <span class="rbw-sms-status rbw-sms-status-<?php echo esc_attr($sms_key); ?>"><?php echo esc_html($sms_label); ?></span>
                  <?php if ($sms_detail_short !== ''): ?>
                    <div class="rbw-sms-meta" title="<?php echo esc_attr($sms_detail); ?>"><?php echo esc_html($sms_detail_short); ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($nid): ?>
                    <a class="rbw-link-btn rbw-link-btn-neutral" href="<?php echo esc_url($nid); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'rbw'); ?></a>
                  <?php else: ?>
                    &mdash;
                  <?php endif; ?>
                </td>
                <td class="rbw-actions-cell">
                  <div class="rbw-action-links">
                    <a class="rbw-link-btn rbw-link-btn-primary" href="<?php echo esc_url($invoice_url); ?>" target="_blank" rel="noopener">
                      <?php esc_html_e('Invoice', 'rbw'); ?>
                    </a>
                    <?php if ($can_retry_sms): ?>
                      <a class="rbw-link-btn rbw-link-btn-neutral" href="<?php echo esc_url($retry_sms_url); ?>">
                        <?php esc_html_e('Retry SMS', 'rbw'); ?>
                      </a>
                    <?php endif; ?>
                    <?php if (!in_array($status_key, ['trash', 'completed'], true)): ?>
                      <a class="rbw-link-btn rbw-link-btn-danger" href="<?php echo esc_url($cancel_url); ?>" onclick="return confirm('<?php echo esc_js(__('Cancel this booking? This will free the room availability.', 'rbw')); ?>');">
                        <?php esc_html_e('Cancel', 'rbw'); ?>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <style>
          .rbw-history-filters{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:12px;
            flex-wrap:wrap;
            background:#ffffff;
            border:1px solid #d8dde6;
            border-radius:8px;
            padding:12px;
            margin:10px 0 14px;
          }
          .rbw-history-form{
            display:flex;
            align-items:flex-end;
            gap:10px;
            flex-wrap:wrap;
          }
          .rbw-history-form label{
            display:grid;
            gap:4px;
            font-size:12px;
            color:#4b5563;
          }
          .rbw-history-actions{
            display:flex;
            gap:8px;
          }
          .rbw-history-summary{
            margin:8px 0 10px;
            font-weight:600;
            color:#1f2937;
          }
          .rbw-history-empty{
            background:#fff;
            border:1px solid #d8dde6;
            border-radius:8px;
            padding:14px;
          }
          .rbw-admin-table-wrap{
            overflow-x:auto;
            background:#f8fafc;
            border:1px solid #d8dde6;
            border-radius:8px;
            padding:10px;
          }
          .rbw-admin-table{
            min-width:1100px;
            width:100%;
            margin:0;
            border-collapse:separate;
            border-spacing:0;
            border:1px solid #dfe3ea;
            border-radius:6px;
            overflow:hidden;
            box-shadow:0 1px 3px rgba(15, 23, 42, 0.06);
          }
          .rbw-admin-table thead th{
            background:#eef2f7;
            color:#475569;
            font-size:12px;
            font-weight:700;
            border-bottom:1px solid #dfe3ea;
            padding:10px 12px;
            white-space:nowrap;
          }
          .rbw-admin-table tbody td{
            padding:10px 12px;
            border-bottom:1px solid #edf1f6;
            color:#1f2937;
            vertical-align:middle;
            background:#ffffff;
          }
          .rbw-admin-table tbody tr:nth-child(even) td{
            background:#fbfcfe;
          }
          .rbw-admin-table tbody tr:hover td{
            background:#f3f7fc;
          }
          .rbw-admin-table tbody tr:last-child td{
            border-bottom:none;
          }
          .rbw-booking-id{
            font-weight:700;
            color:#0f172a;
            white-space:nowrap;
          }
          .rbw-room-cell{
            max-width:380px;
            white-space:normal;
            line-height:1.35;
          }
          .rbw-phone-link{
            color:#2563eb;
            text-decoration:none;
            font-weight:600;
          }
          .rbw-phone-link:hover{
            text-decoration:underline;
          }
          .rbw-status{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:11px;
            font-weight:700;
            line-height:1;
            border-radius:4px;
            padding:5px 10px;
            text-transform:none;
            letter-spacing:0;
            border:1px solid transparent;
          }
          .rbw-status-publish{
            background:#dcfce7;
            border-color:#86efac;
            color:#166534;
          }
          .rbw-status-pending{
            background:#ffedd5;
            border-color:#fdba74;
            color:#9a3412;
          }
          .rbw-status-trash{
            background:#fee2e2;
            border-color:#fecaca;
            color:#991b1b;
          }
          .rbw-status-completed{
            background:#dbeafe;
            border-color:#93c5fd;
            color:#1d4ed8;
          }
          .rbw-sms-cell{
            min-width: 170px;
          }
          .rbw-sms-status{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:11px;
            font-weight:700;
            line-height:1;
            border-radius:4px;
            padding:5px 10px;
            border:1px solid transparent;
          }
          .rbw-sms-status-sent{
            background:#dcfce7;
            border-color:#86efac;
            color:#166534;
          }
          .rbw-sms-status-failed{
            background:#fee2e2;
            border-color:#fecaca;
            color:#991b1b;
          }
          .rbw-sms-status-pending{
            background:#ffedd5;
            border-color:#fdba74;
            color:#9a3412;
          }
          .rbw-sms-status-waiting{
            background:#e2e8f0;
            border-color:#cbd5e1;
            color:#334155;
          }
          .rbw-sms-status-disabled{
            background:#e5e7eb;
            border-color:#d1d5db;
            color:#4b5563;
          }
          .rbw-sms-status-not_required{
            background:#f1f5f9;
            border-color:#cbd5e1;
            color:#475569;
          }
          .rbw-sms-meta{
            margin-top:4px;
            font-size:11px;
            color:#64748b;
            line-height:1.3;
          }
          .rbw-actions-cell{
            white-space:nowrap;
          }
          .rbw-action-links{
            display:flex;
            align-items:center;
            gap:6px;
            flex-wrap:wrap;
          }
          .rbw-link-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:28px;
            padding:0 10px;
            border:1px solid #cbd5e1;
            border-radius:4px;
            background:#ffffff;
            color:#334155;
            text-decoration:none;
            font-size:12px;
            font-weight:600;
            box-sizing:border-box;
          }
          .rbw-link-btn:hover{
            border-color:#94a3b8;
            background:#f8fafc;
            color:#0f172a;
          }
          .rbw-link-btn-primary{
            border-color:#bfdbfe;
            color:#1d4ed8;
            background:#eff6ff;
          }
          .rbw-link-btn-primary:hover{
            border-color:#93c5fd;
            background:#dbeafe;
            color:#1e3a8a;
          }
          .rbw-link-btn-danger{
            border-color:#fecaca;
            color:#b91c1c;
            background:#fef2f2;
          }
          .rbw-link-btn-danger:hover{
            border-color:#fca5a5;
            background:#fee2e2;
            color:#991b1b;
          }
          .rbw-link-btn-neutral{
            border-color:#cbd5e1;
            color:#475569;
            background:#f8fafc;
          }
          @media (max-width: 782px){
            .rbw-history-form{
              width:100%;
            }
            .rbw-admin-table th,
            .rbw-admin-table td{
              white-space:nowrap;
            }
            .rbw-admin-table .rbw-room-cell{
              white-space:normal !important;
              min-width:220px;
            }
          }
        </style>

        <?php
        $total_pages = max(1, ceil($total / $per_page));
        if ($total_pages > 1){
          $base = add_query_arg(array_merge($filter_args, ['paged'=>'%#%']), admin_url('admin.php'));
          echo '<div class="tablenav"><div class="tablenav-pages">';
          echo paginate_links([
            'base' => $base,
            'format' => '',
            'current' => $paged,
            'total' => $total_pages,
          ]);
          echo '</div></div>';
        }
        ?>
      <?php endif; ?>
    </div>
    <?php
  }

  public static function render_settings_page(){
    if (function_exists('wp_enqueue_media')) {
      wp_enqueue_media();
    }
    $rooms = get_option(self::OPT_ROOMS, []);
    $groups = get_option(self::OPT_GROUPS, []);
    if (!is_array($rooms)) $rooms = [];
    if (!is_array($groups)) $groups = [];

    // Infer owner for legacy rows that were assigned to exactly one group.
    $room_group_usage = [];
    foreach ($groups as $g) {
      if (!is_array($g)) continue;
      $gcode = sanitize_title((string)($g['code'] ?? ($g['name'] ?? '')));
      if ($gcode === '') continue;
      $grooms = is_array($g['rooms'] ?? null) ? $g['rooms'] : [];
      foreach ($grooms as $rid) {
        $rid = sanitize_text_field((string)$rid);
        if ($rid === '') continue;
        if (!isset($room_group_usage[$rid])) $room_group_usage[$rid] = [];
        $room_group_usage[$rid][$gcode] = true;
      }
    }
    foreach ($rooms as $idx => $r) {
      if (!is_array($r)) continue;
      $rid = sanitize_text_field((string)($r['id'] ?? ''));
      if ($rid === '') continue;
      $owner = sanitize_title((string)($r['group_owner'] ?? ''));
      if ($owner !== '') continue;
      if (!isset($room_group_usage[$rid])) continue;
      if (count($room_group_usage[$rid]) !== 1) continue;
      $first_code = '';
      foreach ($room_group_usage[$rid] as $code => $_flag) {
        $first_code = (string)$code;
        break;
      }
      if ($first_code !== '') {
        $rooms[$idx]['group_owner'] = $first_code;
      }
    }

    $group_count = count($groups);
    $has_backup = !empty(get_option(self::OPT_ROOMS_BACKUP, [])) || !empty(get_option(self::OPT_GROUPS_BACKUP, []));
    $recover_url = wp_nonce_url(
      add_query_arg(['action' => 'rbw_recover_settings'], admin_url('admin-post.php')),
      'rbw_recover_settings'
    );
    $restore_backup_url = wp_nonce_url(
      add_query_arg(['action' => 'rbw_restore_settings_backup'], admin_url('admin-post.php')),
      'rbw_restore_settings_backup'
    );
    $rooms_by_id = [];
    foreach ($rooms as $r){
      $rid = (string)($r['id'] ?? '');
      if ($rid !== '') {
        $rooms_by_id[$rid] = [
          'name' => (string)($r['name'] ?? ''),
          'capacity' => 4,
          'group_owner' => sanitize_title((string)($r['group_owner'] ?? '')),
        ];
      }
    }
    $room_count = count($rooms);
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Resort Booking', 'rbw'); ?></h1>
      <?php if (isset($_GET['rbw_recovered'])): ?>
        <div class="notice notice-success is-dismissible">
          <p>
            <?php
              $rr = max(0, absint($_GET['rbw_recovered']));
              $rg = max(0, absint($_GET['rbw_recovered_groups'] ?? 0));
              printf(
                esc_html__('Recovery completed: %1$d room(s), %2$d group(s).', 'rbw'),
                $rr,
                $rg
              );
            ?>
          </p>
        </div>
      <?php endif; ?>
      <?php if (isset($_GET['rbw_restored'])): ?>
        <div class="notice notice-success is-dismissible">
          <p>
            <?php
              $sr = max(0, absint($_GET['rbw_restored']));
              $sg = max(0, absint($_GET['rbw_restored_groups'] ?? 0));
              printf(
                esc_html__('Backup restored: %1$d room(s), %2$d group(s).', 'rbw'),
                $sr,
                $sg
              );
            ?>
          </p>
        </div>
      <?php endif; ?>
      <?php if ($room_count === 0 || $group_count === 0): ?>
        <div class="notice notice-warning">
          <p><?php esc_html_e('Some booking settings are empty. You can recover room/group data from existing bookings.', 'rbw'); ?></p>
          <p>
            <a class="button button-secondary" href="<?php echo esc_url($recover_url); ?>">
              <?php esc_html_e('Recover From Existing Bookings', 'rbw'); ?>
            </a>
            <?php if ($has_backup): ?>
              <a class="button" href="<?php echo esc_url($restore_backup_url); ?>">
                <?php esc_html_e('Restore Last Backup', 'rbw'); ?>
              </a>
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>

      <form method="post" action="options.php">
        <?php settings_fields('rbw_settings'); ?>
        <input type="hidden" name="<?php echo esc_attr(self::POST_ROOMS_SUBMITTED); ?>" value="1">
        <input type="hidden" name="<?php echo esc_attr(self::POST_GROUPS_SUBMITTED); ?>" value="1">

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('WooCommerce Deposit Product ID', 'rbw'); ?></th>
            <td>
              <input type="number" name="<?php echo esc_attr(self::OPT_DEPOSIT_ID); ?>" value="<?php echo esc_attr( get_option(self::OPT_DEPOSIT_ID, RBW_DEPOSIT_PRODUCT_ID) ); ?>" min="1" class="small-text">
              <p class="description"><?php esc_html_e('This product will be added to cart for the deposit payment.', 'rbw'); ?></p>
              <p class="description">
                <a href="<?php echo esc_url(admin_url('admin.php?page=rbw-invoice-settings')); ?>">
                  <?php esc_html_e('Open Invoice Settings for logo, address, color, and watermark.', 'rbw'); ?>
                </a>
              </p>
            </td>
          </tr>
        </table>

        <div class="rbw-admin-grid">
          <section class="rbw-card">
            <div class="rbw-card-head">
              <div>
                <h2><?php esc_html_e('Room Group Management', 'rbw'); ?></h2>
                <p class="description"><?php esc_html_e('Create groups and assign rooms. Use the shortcode to show a specific group on a page.', 'rbw'); ?></p>
              </div>
              <button type="button" class="button" id="rbw-add-group"><?php esc_html_e('Add New Group', 'rbw'); ?></button>
            </div>

            <div id="rbw-groups-list">
              <?php
              if (empty($groups)) $groups = [];
              $gi = 0;
              foreach ($groups as $group){
                $gname = esc_attr($group['name'] ?? '');
                $gcode_raw = sanitize_title((string)($group['code'] ?? ($group['name'] ?? '')));
                $gcode = esc_attr($gcode_raw);
                $grooms = is_array($group['rooms'] ?? null) ? $group['rooms'] : [];
                $gadvance = esc_attr($group['advance_extra'] ?? 0);
                ?>
                <div class="rbw-group-card" data-group-index="<?php echo (int)$gi; ?>" data-group-code="<?php echo esc_attr($gcode_raw); ?>">
                  <div class="rbw-group-head">
                    <input type="text" name="<?php echo esc_attr(self::OPT_GROUPS); ?>[<?php echo $gi; ?>][name]" value="<?php echo $gname; ?>" required class="rbw-group-name" placeholder="<?php esc_attr_e('Group name', 'rbw'); ?>">
                    <input type="number" min="1" step="1" class="rbw-group-qty" placeholder="<?php esc_attr_e('Qty', 'rbw'); ?>" title="<?php esc_attr_e('Room Quantity', 'rbw'); ?>">
                    <span class="rbw-group-count" data-group-count>0 rooms</span>
                    <button type="button" class="button-link-delete rbw-group-remove"><?php esc_html_e('Remove', 'rbw'); ?></button>
                  </div>
                  <div class="rbw-group-advance">
                    <label><?php esc_html_e('Extra Advance / Additional Room', 'rbw'); ?></label>
                    <input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_GROUPS); ?>[<?php echo $gi; ?>][advance_extra]" value="<?php echo $gadvance; ?>" class="rbw-group-advance-input">
                  </div>
                  <div class="rbw-group-shortcode">
                    <label><?php esc_html_e('Shortcode', 'rbw'); ?></label>
                    <input type="text" class="regular-text rbw-group-shortcode-input" readonly value="<?php echo esc_attr('[resort_booking group="'.$gcode.'"]'); ?>">
                  </div>
                  <details class="rbw-group-rooms" open>
                    <summary><?php esc_html_e('Rooms Included', 'rbw'); ?></summary>
                    <div class="rbw-room-badges" data-room-badges>
                      <?php if (empty($rooms_by_id)): ?>
                        <em><?php esc_html_e('No rooms available yet.', 'rbw'); ?></em>
                      <?php else: ?>
                        <?php foreach ($rooms_by_id as $rid => $meta): ?>
                          <?php
                            $rname = $meta['name'] ?? $rid;
                            $rcap = (int)($meta['capacity'] ?? 1);
                            $rowner = sanitize_title((string)($meta['group_owner'] ?? ''));
                            if ($rowner !== '' && $rowner !== $gcode_raw) continue;
                          ?>
                          <label class="rbw-room-pill" data-room-id="<?php echo esc_attr($rid); ?>" data-room-owner="<?php echo esc_attr($rowner); ?>">
                            <input type="checkbox" name="<?php echo esc_attr(self::OPT_GROUPS); ?>[<?php echo $gi; ?>][rooms][]" value="<?php echo esc_attr($rid); ?>" <?php checked(in_array($rid, $grooms, true)); ?>>
                            <span><?php echo esc_html($rname ?: $rid); ?></span>
                            <small><?php echo esc_html(sprintf(__('Cap %d', 'rbw'), $rcap)); ?></small>
                          </label>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </details>
                </div>
                <?php
                $gi++;
              }
              ?>
            </div>
          </section>

          <section class="rbw-card">
            <div class="rbw-card-head">
              <div>
                <h2><?php esc_html_e('Rooms', 'rbw'); ?></h2>
                <p class="description"><?php esc_html_e('Add or edit rooms below. No WooCommerce needed.', 'rbw'); ?></p>
              </div>
              <div class="rbw-inline-actions">
                <button type="button" class="button" id="rbw-add-row"><?php esc_html_e('Add Room', 'rbw'); ?></button>
              </div>
            </div>

            <div class="rbw-rooms-table-wrap">
              <table class="widefat striped" id="rbw-rooms-table">
                <thead>
                  <tr>
                    <th><?php esc_html_e('Room Name', 'rbw'); ?></th>
                    <th><?php esc_html_e('Single Price / night (1 guest)', 'rbw'); ?></th>
                    <th><?php esc_html_e('Couple Price / night (2 guests)', 'rbw'); ?></th>
                    <th><?php esc_html_e('Group Price / night (3-4 guests)', 'rbw'); ?></th>
                    <th><?php esc_html_e('Guest Types', 'rbw'); ?></th>
                    <th><?php esc_html_e('Advance', 'rbw'); ?></th>
                    <th><?php esc_html_e('Room Qty', 'rbw'); ?></th>
                    <th><?php esc_html_e('Image', 'rbw'); ?></th>
                    <th><?php esc_html_e('Actions', 'rbw'); ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  if (empty($rooms)) $rooms = [];
                  $i = 0;
                  foreach ($rooms as $room){
                    self::render_room_row($room, $i++);
                  }
                  ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <div class="rbw-save-bar">
          <?php submit_button(__('Save Settings', 'rbw'), 'primary', 'submit', false); ?>
        </div>
      </form>
    </div>
    <script>
    (function(){
      const table = document.querySelector('#rbw-rooms-table tbody');
      const addBtn = document.getElementById('rbw-add-row');
      let index = <?php echo (int)$i; ?>;
      const opt = '<?php echo esc_js(self::OPT_ROOMS); ?>';
      const roomBadgesSelector = '[data-room-badges]';

      const alpha = (n) => {
        const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if (n < 26) return letters[n];
        const first = Math.floor(n / 26) - 1;
        const second = n % 26;
        return letters[first] + letters[second];
      };

      const createTempRoomId = (i) => `tmp_${Date.now()}_${i}`;

      const autoRoomName = (row, idx) => {
        const input = row.querySelector(`input[name="${opt}[${idx}][name]"]`);
        if (input && !input.value) {
          const label = alpha(idx);
          input.value = `Room ${label}`;
        }
      };

      const normalizeCode = (value) => {
        return String(value || '')
          .toLowerCase()
          .trim()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '');
      };

      const getGroupCode = (groupCard) => {
        if (!groupCard) return '';
        const direct = normalizeCode(groupCard.getAttribute('data-group-code') || '');
        if (direct) return direct;
        const sc = groupCard.querySelector('.rbw-group-shortcode-input')?.value || '';
        const m = sc.match(/group="([^"]+)"/);
        if (m && m[1]) return normalizeCode(m[1]);
        const name = groupCard.querySelector('.rbw-group-name')?.value || '';
        return normalizeCode(name);
      };

      const roomVisibleInGroup = (roomOwner, groupCode) => {
        const owner = normalizeCode(roomOwner);
        if (!owner) return true;
        return owner === normalizeCode(groupCode);
      };

      const createRoomPill = (groupIndex, roomId, roomName, capacity, checked = false, roomOwner = '') => {
        const label = document.createElement('label');
        label.className = 'rbw-room-pill';
        label.setAttribute('data-room-id', roomId);
        label.setAttribute('data-room-owner', normalizeCode(roomOwner));
        label.innerHTML = `
          <input type="checkbox" name="${gOpt}[${groupIndex}][rooms][]" value="${roomId}" ${checked ? 'checked' : ''}>
          <span>${roomName}</span>
          <small>Cap ${capacity}</small>
        `;
        return label;
      };

      const addRoomPillToAllGroups = (roomId, roomName, capacity, roomOwner = '') => {
        document.querySelectorAll('.rbw-group-card').forEach(card => {
          const groupCode = getGroupCode(card);
          if (!roomVisibleInGroup(roomOwner, groupCode)) return;
          const list = card.querySelector(roomBadgesSelector);
          if (!list) return;
          if (list.querySelector(`[data-room-id="${roomId}"]`)) return;
          const groupIndex = card.getAttribute('data-group-index');
          if (groupIndex === null) return;
          list.appendChild(createRoomPill(groupIndex, roomId, roomName, capacity, false, roomOwner));
        });
      };

      const ensureRoomPillInGroup = (groupCard, roomId, roomName, capacity, roomOwner = '') => {
        if (!groupCard) return;
        const list = groupCard.querySelector(roomBadgesSelector);
        if (!list) return;
        const pill = list.querySelector(`[data-room-id="${roomId}"]`);
        if (pill) {
          const cb = pill.querySelector('input[type="checkbox"]');
          if (cb) cb.checked = true;
          return;
        }
        const groupIndex = groupCard.getAttribute('data-group-index');
        if (groupIndex === null) return;
        list.appendChild(createRoomPill(groupIndex, roomId, roomName, capacity, true, roomOwner));
      };

      const updateRoomPillNames = () => {
        document.querySelectorAll('.rbw-room-pill').forEach(pill => {
          const rid = pill.getAttribute('data-room-id');
          if (!rid) return;
          const row = document.querySelector(`input[name$="[id]"][value="${rid}"]`)?.closest('tr');
          if (!row) return;
          const nameInput = row.querySelector(`input[name$="[name]"]`);
          const capInput = row.querySelector(`input[name$="[capacity]"]`);
          const name = nameInput?.value || rid;
          const cap = capInput?.value || 1;
          const span = pill.querySelector('span');
          const small = pill.querySelector('small');
          if (span) span.textContent = name;
          if (small) small.textContent = `Cap ${cap}`;
        });
      };

      const escapeHtml = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

      const getRowIndex = (row) => {
        const sample = row?.querySelector(`input[name^="${opt}["][name$="[name]"]`);
        if (!sample) return -1;
        const match = String(sample.name).match(/\[(\d+)\]\[name\]$/);
        if (!match) return -1;
        return parseInt(match[1], 10);
      };

      const normalizeImageUrls = (urls) => {
        const out = [];
        const src = Array.isArray(urls) ? urls : [urls];
        src.forEach((u) => {
          const val = String(u || '').trim();
          if (val && !out.includes(val)) out.push(val);
        });
        return out;
      };

      const getImageUrlsFromRow = (row) => {
        const wrap = row?.querySelector('[data-rbw-image-hidden-list]');
        if (!wrap) return [];
        return Array.from(wrap.querySelectorAll('input[name$="[images][]"]'))
          .map(inp => String(inp.value || '').trim())
          .filter(Boolean);
      };

      const setImageUrlsForRow = (row, urls, forcedIndex = null) => {
        const idx = forcedIndex !== null ? forcedIndex : getRowIndex(row);
        if (idx < 0 || !row) return;

        const clean = normalizeImageUrls(urls);
        const hiddenSingle = row.querySelector(`input[name="${opt}[${idx}][image]"]`) || row.querySelector('input[name$="[image]"]');
        if (hiddenSingle) hiddenSingle.value = clean[0] || '';

        const hiddenWrap = row.querySelector('[data-rbw-image-hidden-list]');
        if (hiddenWrap) {
          hiddenWrap.innerHTML = clean.map((url) =>
            `<input type="hidden" name="${opt}[${idx}][images][]" value="${escapeHtml(url)}">`
          ).join('');
        }

        const previewWrap = row.querySelector('[data-rbw-image-preview-list]');
        if (previewWrap) {
          if (!clean.length) {
            previewWrap.innerHTML = '<div class="rbw-img-empty"><?php echo esc_js(__('No image selected', 'rbw')); ?></div>';
          } else {
            previewWrap.innerHTML = clean.map((url, pos) => `
              <div class="rbw-img-thumb">
                <img src="${escapeHtml(url)}" alt="">
                <button type="button" class="rbw-img-remove" data-img-remove="${pos}" aria-label="<?php echo esc_attr__('Remove image', 'rbw'); ?>">&times;</button>
              </div>
            `).join('');
          }
        }
      };

      const createRoomRow = (opts = {}) => {
        const i = index;
        table.insertAdjacentHTML('beforeend', rowTemplate(i));
        const row = table.querySelectorAll('tr')[table.querySelectorAll('tr').length - 1];
        const idInput = row.querySelector(`input[name="${opt}[${i}][id]"]`);
        const ownerInput = row.querySelector(`input[name="${opt}[${i}][group_owner]"]`);
        const nameInput = row.querySelector(`input[name="${opt}[${i}][name]"]`);
        const priceInput = row.querySelector(`input[name="${opt}[${i}][price]"]`);
        const priceSingleInput = row.querySelector(`input[name="${opt}[${i}][price_single]"]`);
        const priceCoupleInput = row.querySelector(`input[name="${opt}[${i}][price_couple]"]`);
        const priceGroupInput = row.querySelector(`input[name="${opt}[${i}][price_group]"]`);
        const depositInput = row.querySelector(`input[name="${opt}[${i}][deposit]"]`);
        const stockInput = row.querySelector(`input[name="${opt}[${i}][stock]"]`);
        const guestTypeInputs = row.querySelectorAll(`input[name="${opt}[${i}][guest_types][]"]`);
        const capInput = row.querySelector(`input[name="${opt}[${i}][capacity]"]`);
        const rid = opts.id || createTempRoomId(i);
        const rowOwner = normalizeCode(opts.group_owner || '');
        if (idInput) idInput.value = rid;
        if (ownerInput) ownerInput.value = rowOwner;
        if (nameInput) nameInput.value = opts.name || '';
        if (priceInput && opts.price !== undefined) priceInput.value = opts.price;
        if (priceSingleInput && opts.price_single !== undefined) priceSingleInput.value = opts.price_single;
        if (priceCoupleInput && opts.price_couple !== undefined) priceCoupleInput.value = opts.price_couple;
        if (priceGroupInput && opts.price_group !== undefined) priceGroupInput.value = opts.price_group;
        if (depositInput && opts.deposit !== undefined) depositInput.value = opts.deposit;
        if (stockInput && opts.stock !== undefined) stockInput.value = opts.stock;
        if (guestTypeInputs.length && Array.isArray(opts.guest_types)) {
          const allow = new Set(opts.guest_types.map(v => String(v)));
          guestTypeInputs.forEach(inp => { inp.checked = allow.has(inp.value); });
        }
        const initialImages = Array.isArray(opts.images)
          ? opts.images
          : (opts.image ? [opts.image] : []);
        setImageUrlsForRow(row, initialImages, i);
        if (capInput && opts.capacity !== undefined) capInput.value = opts.capacity;
        if (!nameInput?.value) autoRoomName(row, i);
        rooms[rid] = {
          name: nameInput?.value || rid,
          capacity: Number(capInput?.value || 1),
          group_owner: rowOwner
        };
        index++;
        return { row, id: rid };
      };

      function rowTemplate(i){
        return `
          <tr>
            <td>
              <input type="hidden" name="${opt}[${i}][id]" value="">
              <input type="text" name="${opt}[${i}][name]" required>
              <input type="hidden" name="${opt}[${i}][price]" value="0">
              <input type="hidden" name="${opt}[${i}][capacity]" value="4">
              <input type="hidden" name="${opt}[${i}][booking_type]" value="entire_room">
              <input type="hidden" name="${opt}[${i}][group_owner]" value="">
            </td>
            <td><input type="number" step="0.01" min="0" name="${opt}[${i}][price_single]" value="5000"></td>
            <td><input type="number" step="0.01" min="0" name="${opt}[${i}][price_couple]" value="0"></td>
            <td><input type="number" step="0.01" min="0" name="${opt}[${i}][price_group]" value="0"></td>
            <td class="rbw-guest-types">
              <label><input type="checkbox" name="${opt}[${i}][guest_types][]" value="single" checked> <?php echo esc_html__('Single', 'rbw'); ?></label>
              <label><input type="checkbox" name="${opt}[${i}][guest_types][]" value="couple" checked> <?php echo esc_html__('Couple', 'rbw'); ?></label>
              <label><input type="checkbox" name="${opt}[${i}][guest_types][]" value="group" checked> <?php echo esc_html__('Group', 'rbw'); ?></label>
            </td>
            <td><input type="number" step="0.01" min="0" name="${opt}[${i}][deposit]" value="0"></td>
            <td><input type="number" step="1" min="0" name="${opt}[${i}][stock]" value="0"></td>
            <td class="rbw-img-cell">
              <div class="rbw-img-preview-list" data-rbw-image-preview-list>
                <div class="rbw-img-empty"><?php esc_html_e('No image selected', 'rbw'); ?></div>
              </div>
              <input type="hidden" name="${opt}[${i}][image]" value="">
              <div data-rbw-image-hidden-list></div>
              <div class="rbw-img-actions">
                <button type="button" class="button rbw-upload"><?php esc_html_e('Upload Images', 'rbw'); ?></button>
                <button type="button" class="button rbw-clear-images"><?php esc_html_e('Clear', 'rbw'); ?></button>
              </div>
            </td>
            <td><button type="button" class="button-link-delete rbw-remove"><?php esc_html_e('Remove', 'rbw'); ?></button></td>
          </tr>`;
      }

      if (addBtn) {
        addBtn.addEventListener('click', () => {
          const created = createRoomRow();
          const rowOwner = created.row.querySelector(`input[name="${opt}[${index-1}][group_owner]"]`)?.value || '';
          addRoomPillToAllGroups(
            created.id,
            created.row.querySelector(`input[name="${opt}[${index-1}][name]"]`)?.value || created.id,
            created.row.querySelector(`input[name="${opt}[${index-1}][capacity]"]`)?.value || 1,
            rowOwner
          );
          updateRoomPillNames();
        });
      }

      table.addEventListener('click', (e) => {
        if (e.target.classList.contains('rbw-upload')) {
          e.preventDefault();
          const row = e.target.closest('tr');
          if (!row || !window.wp || !wp.media) return;
          const existing = getImageUrlsFromRow(row);
          const frame = wp.media({
            title: '<?php echo esc_js(__('Select Room Images', 'rbw')); ?>',
            button: { text: '<?php echo esc_js(__('Use Images', 'rbw')); ?>' },
            multiple: true
          });
          frame.on('select', function(){
            const selected = frame.state().get('selection').toArray().map(item => {
              const a = item.toJSON();
              return a.url || '';
            }).filter(Boolean);
            setImageUrlsForRow(row, existing.concat(selected));
          });
          frame.open();
          return;
        }
        if (e.target.classList.contains('rbw-clear-images')) {
          e.preventDefault();
          const row = e.target.closest('tr');
          if (row) setImageUrlsForRow(row, []);
          return;
        }
        if (e.target.classList.contains('rbw-img-remove')) {
          e.preventDefault();
          const row = e.target.closest('tr');
          if (!row) return;
          const pos = parseInt(e.target.getAttribute('data-img-remove') || '-1', 10);
          const list = getImageUrlsFromRow(row);
          if (pos >= 0 && pos < list.length) {
            list.splice(pos, 1);
            setImageUrlsForRow(row, list);
          }
          return;
        }
        if(e.target.classList.contains('rbw-remove')){
          const row = e.target.closest('tr');
          if(row) row.remove();
        }
      });

      table.addEventListener('input', (e) => {
        const row = e.target.closest('tr');
        if (!row) return;
        if (e.target.matches('input[type="checkbox"][name$="[guest_types][]"]')) {
          const checks = row.querySelectorAll('input[name$="[guest_types][]"]');
          const anyChecked = Array.from(checks).some(c => c.checked);
          if (!anyChecked) {
            e.target.checked = true;
          }
        }
        const idInput = row.querySelector(`input[name$="[id]"]`);
        const nameInput = row.querySelector(`input[name$="[name]"]`);
        const capInput = row.querySelector(`input[name$="[capacity]"]`);
        const ownerInput = row.querySelector(`input[name$="[group_owner]"]`);
        const rid = idInput?.value;
        if (rid) {
          rooms[rid] = {
            name: nameInput?.value || rid,
            capacity: Number(capInput?.value || 1),
            group_owner: normalizeCode(ownerInput?.value || '')
          };
        }
        updateRoomPillNames();
      });

      // Groups UI
      const groupsList = document.querySelector('#rbw-groups-list');
      const addGroupBtn = document.getElementById('rbw-add-group');
      let gIndex = <?php echo (int)$gi; ?>;
      const gOpt = '<?php echo esc_js(self::OPT_GROUPS); ?>';
      const rooms = <?php echo wp_json_encode($rooms_by_id); ?>;

      const slugify = (str) => {
        return (str || '')
          .toString()
          .toLowerCase()
          .trim()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '');
      };

      const buildRoomsHtmlForGroup = (groupCode, groupIndex) => {
        const roomsHtml = Object.keys(rooms).length
          ? Object.entries(rooms).map(([rid, meta]) => {
              const name = meta.name || rid;
              const cap = Number(meta.capacity || 1);
              const owner = normalizeCode(meta.group_owner || '');
              if (!roomVisibleInGroup(owner, groupCode)) return '';
              return `
                <label class="rbw-room-pill" data-room-id="${rid}" data-room-owner="${owner}">
                  <input type="checkbox" name="${gOpt}[${groupIndex}][rooms][]" value="${rid}">
                  <span>${name}</span>
                  <small>Cap ${cap}</small>
                </label>
              `;
            }).join('')
          : `<em><?php esc_html_e('No rooms available yet.', 'rbw'); ?></em>`;
        return roomsHtml || `<em><?php esc_html_e('No rooms available yet.', 'rbw'); ?></em>`;
      };

      const groupRowTemplate = (i, groupCode = '') => {
        const safeCode = normalizeCode(groupCode);
        const roomsHtml = buildRoomsHtmlForGroup(safeCode, i);
        return `
          <div class="rbw-group-card" data-group-index="${i}" data-group-code="${safeCode}">
            <div class="rbw-group-head">
              <input type="text" name="${gOpt}[${i}][name]" value="" required class="rbw-group-name" placeholder="<?php echo esc_attr__('Group name', 'rbw'); ?>">
              <input type="number" min="1" step="1" class="rbw-group-qty" placeholder="<?php echo esc_attr__('Qty', 'rbw'); ?>" title="<?php echo esc_attr__('Room Quantity', 'rbw'); ?>">
              <span class="rbw-group-count" data-group-count>0 rooms</span>
              <button type="button" class="button-link-delete rbw-group-remove"><?php esc_html_e('Remove', 'rbw'); ?></button>
            </div>
            <div class="rbw-group-advance">
              <label><?php esc_html_e('Extra Advance / Additional Room', 'rbw'); ?></label>
              <input type="number" step="0.01" min="0" name="${gOpt}[${i}][advance_extra]" value="0" class="rbw-group-advance-input">
            </div>
            <div class="rbw-group-shortcode">
              <label><?php esc_html_e('Shortcode', 'rbw'); ?></label>
              <input type="text" class="regular-text rbw-group-shortcode-input" readonly value="[resort_booking group=&quot;&quot;]">
            </div>
            <details class="rbw-group-rooms" open>
              <summary><?php esc_html_e('Rooms Included', 'rbw'); ?></summary>
              <div class="rbw-room-badges" data-room-badges>
                ${roomsHtml}
              </div>
            </details>
          </div>`;
      };

      const updateGroupCounts = (container) => {
        if (!container) return;
        container.querySelectorAll('.rbw-group-card').forEach(card => {
          const count = card.querySelectorAll('input[type="checkbox"]:checked').length;
          const el = card.querySelector('[data-group-count]');
          if (el) el.textContent = `${count} room${count === 1 ? '' : 's'}`;
        });
      };

      const getCheckedCount = (card) => {
        if (!card) return 0;
        return card.querySelectorAll('input[type="checkbox"]:checked').length;
      };

      const countRoomsByPrefix = (prefix) => {
        if (!prefix) return 0;
        const inputs = table.querySelectorAll(`input[name$="[name]"]`);
        let count = 0;
        inputs.forEach(input => {
          const val = (input.value || '').trim().toLowerCase();
          if (val.startsWith(prefix.toLowerCase() + ' ')) count++;
        });
        return count;
      };

      const remapOwnedRooms = (fromCode, toCode) => {
        const prev = normalizeCode(fromCode);
        const next = normalizeCode(toCode);
        if (!prev || !next || prev === next) return;
        Object.keys(rooms).forEach((rid) => {
          if (normalizeCode(rooms[rid]?.group_owner || '') === prev) {
            rooms[rid].group_owner = next;
          }
        });
        table.querySelectorAll(`input[name$="[group_owner]"]`).forEach((inp) => {
          if (normalizeCode(inp.value) === prev) {
            inp.value = next;
          }
        });
        document.querySelectorAll('.rbw-room-pill[data-room-owner]').forEach((pill) => {
          if (normalizeCode(pill.getAttribute('data-room-owner') || '') === prev) {
            pill.setAttribute('data-room-owner', next);
          }
        });
      };

      if (addGroupBtn && groupsList) {
        addGroupBtn.addEventListener('click', () => {
          groupsList.insertAdjacentHTML('beforeend', groupRowTemplate(gIndex++, ''));
          updateGroupCounts(groupsList);
        });
      }

      if (groupsList) {
        groupsList.addEventListener('click', (e) => {
          if (e.target.classList.contains('rbw-group-remove')) {
            const card = e.target.closest('.rbw-group-card');
            if (card) card.remove();
          }
        });

        groupsList.addEventListener('input', (e) => {
          if (!e.target.classList.contains('rbw-group-name')) return;
          const card = e.target.closest('.rbw-group-card');
          if (!card) return;
          const prevCode = getGroupCode(card);
          const code = slugify(e.target.value) || 'group';
          if (prevCode && prevCode !== code) {
            remapOwnedRooms(prevCode, code);
          }
          card.setAttribute('data-group-code', code);
          const sc = card.querySelector('.rbw-group-shortcode-input');
          if (sc) sc.value = `[resort_booking group="${code}"]`;
        });

        groupsList.addEventListener('change', (e) => {
          if (e.target && e.target.type === 'checkbox') {
            updateGroupCounts(groupsList);
          }
        });

        groupsList.addEventListener('click', (e) => {
          const qtyInput = e.target.closest('.rbw-group-card')?.querySelector('.rbw-group-qty');
          if (!qtyInput) return;
        });

        groupsList.addEventListener('input', (e) => {
          if (!e.target.classList.contains('rbw-group-qty')) return;
          const card = e.target.closest('.rbw-group-card');
          if (!card) return;
          const qty = parseInt(e.target.value, 10);
          if (!Number.isFinite(qty) || qty < 1) return;
          const groupName = card.querySelector('.rbw-group-name')?.value?.trim() || 'Room';
          const groupCode = getGroupCode(card) || slugify(groupName) || 'group';
          card.setAttribute('data-group-code', groupCode);
          const currentChecked = getCheckedCount(card);

          if (qty < currentChecked) {
            const checked = Array.from(card.querySelectorAll('input[type="checkbox"]:checked'));
            const toUncheck = currentChecked - qty;
            checked.slice(-toUncheck).forEach(cb => { cb.checked = false; });
            updateGroupCounts(groupsList);
            return;
          }

          const toCreate = Math.max(0, qty - currentChecked);
          if (toCreate === 0) return;

          let startIndex = countRoomsByPrefix(groupName) + 1;
          for (let k = 0; k < toCreate; k++) {
            const created = createRoomRow({
              name: `${groupName} ${startIndex + k}`,
              group_owner: groupCode
            });
            const rowOwner = created.row.querySelector(`input[name="${opt}[${index-1}][group_owner]"]`)?.value || groupCode;
            addRoomPillToAllGroups(
              created.id,
              created.row.querySelector(`input[name="${opt}[${index-1}][name]"]`)?.value || created.id,
              created.row.querySelector(`input[name="${opt}[${index-1}][capacity]"]`)?.value || 1,
              rowOwner
            );
            ensureRoomPillInGroup(
              card,
              created.id,
              created.row.querySelector(`input[name="${opt}[${index-1}][name]"]`)?.value || created.id,
              created.row.querySelector(`input[name="${opt}[${index-1}][capacity]"]`)?.value || 1,
              rowOwner
            );
          }

          updateRoomPillNames();
          updateGroupCounts(groupsList);
        });

        updateGroupCounts(groupsList);
      }
    })();
    </script>
    <style>
      .rbw-admin-grid{
        --rbw-accent: #2563eb;
        --rbw-accent-soft: #e0e7ff;
        --rbw-muted: #6b7280;
        --rbw-border: #e5e7eb;
        --rbw-surface: #ffffff;
        --rbw-surface-alt: #f8fafc;
      }
      .rbw-admin-grid,
      .rbw-admin-grid *{
        box-sizing: border-box;
      }
      .rbw-admin-grid{
        display:grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin: 16px 0;
      }
      .rbw-card{
        background:var(--rbw-surface);
        border:1px solid var(--rbw-border);
        border-radius: 8px;
        padding: 14px;
      }
      .rbw-card-head{
        display:flex;
        gap: 12px;
        align-items:flex-start;
        justify-content:space-between;
        margin-bottom: 10px;
      }
      .rbw-card-head h2{
        color:#1e3a8a;
      }
      .rbw-card-head h2{ margin: 0 0 4px; }
      .rbw-inline-actions{
        display:flex;
        gap:8px;
        align-items:center;
        flex-wrap:wrap;
      }
      .rbw-group-card{
        border:1px solid var(--rbw-border);
        border-radius: 8px;
        padding: 10px;
        margin-bottom: 10px;
        background:var(--rbw-surface-alt);
      }
      .rbw-group-head{
        display:flex;
        gap:8px;
        align-items:center;
        flex-wrap: wrap;
      }
      .rbw-group-head input{
        flex:1;
        min-width: 140px;
      }
      .rbw-group-qty{
        width: 70px;
      }
      .rbw-group-count{
        background:var(--rbw-accent-soft);
        color:#1e3a8a;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
      }
      .rbw-group-shortcode{
        margin-top: 8px;
        display:grid;
        gap:4px;
      }
      .rbw-group-advance{
        margin-top: 8px;
        display:grid;
        gap:4px;
      }
      .rbw-group-advance input{
        max-width: 220px;
      }
      .rbw-group-shortcode input{
        width: 100%;
      }
      .rbw-group-rooms{ margin-top: 8px; }
      .rbw-group-rooms summary{
        color:#1e3a8a;
        font-weight:600;
      }
      #rbw-rooms-table .rbw-guest-types{
        display:flex;
        flex-direction:column;
        gap:4px;
        min-width: 140px;
      }
      #rbw-rooms-table .rbw-guest-types label{
        display:flex;
        align-items:center;
        gap:6px;
        font-size:12px;
        line-height:1.2;
      }
      .rbw-room-badges{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top: 8px;
      }
      .rbw-room-pill{
        display:inline-flex;
        gap:6px;
        align-items:center;
        border:1px solid var(--rbw-border);
        background:var(--rbw-surface);
        padding: 4px 8px;
        border-radius: 999px;
        max-width: 100%;
      }
      .rbw-room-pill span{
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .rbw-room-pill small{
        color:var(--rbw-muted);
        font-size: 11px;
        white-space: nowrap;
      }
      .rbw-save-bar{
        position: sticky;
        bottom: 0;
        background: var(--rbw-surface);
        border-top: 1px solid var(--rbw-border);
        padding: 10px 0 0;
      }
      .rbw-rooms-table-wrap{
        overflow-x: auto;
      }
      #rbw-rooms-table{
        min-width: 1080px;
      }
      #rbw-rooms-table th,
      #rbw-rooms-table td{
        vertical-align: top;
      }
      #rbw-rooms-table th:nth-child(1),
      #rbw-rooms-table td:nth-child(1){
        min-width: 180px;
      }
      #rbw-rooms-table th:nth-child(2),
      #rbw-rooms-table th:nth-child(3),
      #rbw-rooms-table th:nth-child(4),
      #rbw-rooms-table td:nth-child(2),
      #rbw-rooms-table td:nth-child(3),
      #rbw-rooms-table td:nth-child(4){
        min-width: 140px;
      }
      #rbw-rooms-table th:nth-child(5),
      #rbw-rooms-table td:nth-child(5){
        min-width: 300px;
      }
      #rbw-rooms-table th:nth-child(6),
      #rbw-rooms-table td:nth-child(6){
        min-width: 90px;
        white-space: nowrap;
      }
      #rbw-rooms-table input[type="text"],
      #rbw-rooms-table input[type="number"]{
        width: 100%;
      }
      #rbw-rooms-table input[type="number"]{
        min-width: 96px;
      }
      .rbw-img-cell{
        min-width: 300px;
      }
      .rbw-img-preview-list{
        display:grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 6px;
        min-height: 72px;
        border:1px dashed var(--rbw-border);
        border-radius: 8px;
        background: #f8fafc;
        padding: 6px;
        margin-bottom: 6px;
      }
      .rbw-img-thumb{
        position: relative;
        border-radius: 6px;
        overflow: hidden;
        background: #e2e8f0;
        min-height: 0;
        aspect-ratio: 1 / 1;
        border: 1px solid #dbe2ea;
      }
      .rbw-img-thumb img{
        width:100%;
        height:100%;
        object-fit: cover;
        display:block;
      }
      .rbw-img-remove{
        position:absolute;
        top:4px;
        right:4px;
        width:18px;
        height:18px;
        border:0;
        border-radius:999px;
        background: rgba(17,24,39,.82);
        color:#fff;
        font-weight:700;
        line-height:1;
        cursor:pointer;
        padding:0;
      }
      .rbw-img-empty{
        grid-column: 1 / -1;
        display:flex;
        align-items:center;
        justify-content:center;
        color: var(--rbw-muted);
        font-size: 12px;
      }
      .rbw-img-actions{
        display:flex;
        gap:6px;
        flex-wrap: wrap;
      }
      @media (max-width: 1100px){
        .rbw-admin-grid{ grid-template-columns: 1fr; }
      }
      @media (max-width: 782px){
        .rbw-card{
          padding: 12px;
        }
        .rbw-card-head{
          flex-wrap: wrap;
        }
        .rbw-card-head > div{
          width: 100%;
        }
        .rbw-group-head input{
          min-width: 100%;
        }
        .rbw-group-qty{
          width: 100%;
          max-width: 120px;
        }
        .rbw-group-remove{
          margin-left: auto;
        }
        .rbw-room-pill{
          max-width: 100%;
        }
        .rbw-img-preview-list{
          grid-template-columns: repeat(2, 1fr);
        }
      }
    </style>
    <?php
  }

  public static function render_invoice_settings_page(){
    if (function_exists('wp_enqueue_media')) {
      wp_enqueue_media();
    }

    $invoice_logo = (string)get_option(self::OPT_INVOICE_LOGO, '');
    $invoice_business_name = (string)get_option(self::OPT_INVOICE_BUSINESS_NAME, get_bloginfo('name'));
    $invoice_address = (string)get_option(self::OPT_INVOICE_ADDRESS, '');
    $invoice_phone = (string)get_option(self::OPT_INVOICE_PHONE, '');
    $invoice_email = (string)get_option(self::OPT_INVOICE_EMAIL, get_option('admin_email'));
    $invoice_accent = (string)get_option(self::OPT_INVOICE_ACCENT, '#f07a22');
    if (!$invoice_accent) $invoice_accent = '#f07a22';
    $invoice_watermark_opacity = (float)get_option(self::OPT_INVOICE_WATERMARK_OPACITY, 0.06);
    if ($invoice_watermark_opacity < 0) $invoice_watermark_opacity = 0;
    if ($invoice_watermark_opacity > 0.30) $invoice_watermark_opacity = 0.30;
    $invoice_enable_customer = (int)get_option(self::OPT_INVOICE_ENABLE_CUSTOMER, 1);
    $invoice_auto_download = (int)get_option(self::OPT_INVOICE_AUTO_DOWNLOAD, 0);
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Invoice Settings', 'rbw'); ?></h1>
      <p class="description"><?php esc_html_e('Configure invoice/PDF branding for logo, business details, accent color, and watermark.', 'rbw'); ?></p>

      <form method="post" action="options.php">
        <?php settings_fields('rbw_invoice_settings'); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('Invoice Business Name', 'rbw'); ?></th>
            <td>
              <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_INVOICE_BUSINESS_NAME); ?>" value="<?php echo esc_attr($invoice_business_name); ?>">
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Invoice Address', 'rbw'); ?></th>
            <td>
              <textarea class="large-text" rows="3" name="<?php echo esc_attr(self::OPT_INVOICE_ADDRESS); ?>"><?php echo esc_textarea($invoice_address); ?></textarea>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Invoice Phone', 'rbw'); ?></th>
            <td>
              <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_INVOICE_PHONE); ?>" value="<?php echo esc_attr($invoice_phone); ?>">
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Invoice Email', 'rbw'); ?></th>
            <td>
              <input type="email" class="regular-text" name="<?php echo esc_attr(self::OPT_INVOICE_EMAIL); ?>" value="<?php echo esc_attr($invoice_email); ?>">
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Invoice Accent Color', 'rbw'); ?></th>
            <td>
              <input type="color" name="<?php echo esc_attr(self::OPT_INVOICE_ACCENT); ?>" value="<?php echo esc_attr($invoice_accent); ?>">
              <code style="margin-left:8px;"><?php echo esc_html($invoice_accent); ?></code>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Invoice Logo', 'rbw'); ?></th>
            <td>
              <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <img id="rbw-invoice-logo-preview" src="<?php echo esc_url($invoice_logo); ?>" alt="" style="width:88px;height:56px;object-fit:contain;border:1px solid #d1d5db;border-radius:8px;background:#fff;<?php echo $invoice_logo ? '' : 'display:none;'; ?>">
                <input type="text" class="regular-text" id="rbw-invoice-logo" name="<?php echo esc_attr(self::OPT_INVOICE_LOGO); ?>" value="<?php echo esc_attr($invoice_logo); ?>" placeholder="https://...">
                <button type="button" class="button" id="rbw-invoice-logo-upload"><?php esc_html_e('Upload Logo', 'rbw'); ?></button>
                <button type="button" class="button" id="rbw-invoice-logo-clear"><?php esc_html_e('Clear', 'rbw'); ?></button>
              </div>
              <p class="description"><?php esc_html_e('Used in invoice header and as PDF watermark.', 'rbw'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Watermark Opacity', 'rbw'); ?></th>
            <td>
              <input type="number" step="0.01" min="0" max="0.30" class="small-text" name="<?php echo esc_attr(self::OPT_INVOICE_WATERMARK_OPACITY); ?>" value="<?php echo esc_attr(number_format((float)$invoice_watermark_opacity, 2, '.', '')); ?>">
              <p class="description"><?php esc_html_e('Range 0.00 to 0.30. Set 0 to disable watermark.', 'rbw'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Customer Invoice Link', 'rbw'); ?></th>
            <td>
              <input type="hidden" name="<?php echo esc_attr(self::OPT_INVOICE_ENABLE_CUSTOMER); ?>" value="0">
              <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPT_INVOICE_ENABLE_CUSTOMER); ?>" value="1" <?php checked($invoice_enable_customer, 1); ?>>
                <?php esc_html_e('Enable secure customer invoice link after payment', 'rbw'); ?>
              </label>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Auto Print Dialog', 'rbw'); ?></th>
            <td>
              <input type="hidden" name="<?php echo esc_attr(self::OPT_INVOICE_AUTO_DOWNLOAD); ?>" value="0">
              <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPT_INVOICE_AUTO_DOWNLOAD); ?>" value="1" <?php checked($invoice_auto_download, 1); ?>>
                <?php esc_html_e('Auto-open print/PDF dialog when customer opens invoice link', 'rbw'); ?>
              </label>
            </td>
          </tr>
        </table>

        <?php submit_button(__('Save Invoice Settings', 'rbw')); ?>
      </form>
    </div>
    <script>
    (function(){
      const invoiceLogoInput = document.getElementById('rbw-invoice-logo');
      const invoiceLogoPreview = document.getElementById('rbw-invoice-logo-preview');
      const invoiceLogoUploadBtn = document.getElementById('rbw-invoice-logo-upload');
      const invoiceLogoClearBtn = document.getElementById('rbw-invoice-logo-clear');

      const setInvoiceLogo = (url) => {
        if (!invoiceLogoInput) return;
        const val = String(url || '').trim();
        invoiceLogoInput.value = val;
        if (!invoiceLogoPreview) return;
        if (val) {
          invoiceLogoPreview.src = val;
          invoiceLogoPreview.style.display = '';
        } else {
          invoiceLogoPreview.removeAttribute('src');
          invoiceLogoPreview.style.display = 'none';
        }
      };

      if (invoiceLogoUploadBtn) {
        invoiceLogoUploadBtn.addEventListener('click', (e) => {
          e.preventDefault();
          if (!window.wp || !wp.media) return;
          const frame = wp.media({
            title: '<?php echo esc_js(__('Select Invoice Logo', 'rbw')); ?>',
            button: { text: '<?php echo esc_js(__('Use Logo', 'rbw')); ?>' },
            multiple: false
          });
          frame.on('select', function(){
            const selected = frame.state().get('selection').first();
            const data = selected ? selected.toJSON() : null;
            setInvoiceLogo(data && data.url ? data.url : '');
          });
          frame.open();
        });
      }

      if (invoiceLogoClearBtn) {
        invoiceLogoClearBtn.addEventListener('click', (e) => {
          e.preventDefault();
          setInvoiceLogo('');
        });
      }

      if (invoiceLogoInput) {
        invoiceLogoInput.addEventListener('input', () => setInvoiceLogo(invoiceLogoInput.value));
      }
    })();
    </script>
    <?php
  }

  private static function render_room_row($room, $i){
    $id = esc_attr($room['id'] ?? '');
    $name = esc_attr($room['name'] ?? '');
    $price = esc_attr($room['price'] ?? 0);
    $price_single = esc_attr($room['price_single'] ?? 0);
    $price_couple = esc_attr($room['price_couple'] ?? 0);
    $price_group = esc_attr($room['price_group'] ?? 0);
    $stock = esc_attr($room['stock'] ?? 0);
    $deposit = esc_attr($room['deposit'] ?? 0);
    $guest_types = $room['guest_types'] ?? ['single','couple','group'];
    if (!is_array($guest_types)) {
      $guest_types = array_map('trim', explode(',', (string)$guest_types));
    }
    $guest_types = array_values(array_intersect(['single','couple','group'], array_map('strval', $guest_types)));
    if (empty($guest_types)) $guest_types = ['single','couple','group'];
    $image = esc_url($room['image'] ?? '');
    $images = [];
    if (!empty($room['images']) && is_array($room['images'])) {
      foreach ($room['images'] as $img) {
        $u = esc_url($img);
        if ($u !== '') $images[] = $u;
      }
    }
    if (empty($images) && $image !== '') {
      $images[] = $image;
    }
    $capacity = esc_attr($room['capacity'] ?? 4);
    $booking_type = 'entire_room';
    $group_owner = esc_attr(sanitize_title((string)($room['group_owner'] ?? '')));
    ?>
    <tr>
      <td>
        <input type="hidden" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][id]" value="<?php echo $id; ?>">
        <input type="text" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][name]" value="<?php echo $name; ?>" required>
        <input type="hidden" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][price]" value="<?php echo $price_single ?: $price; ?>">
        <input type="hidden" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][capacity]" value="4">
        <input type="hidden" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][booking_type]" value="<?php echo esc_attr($booking_type); ?>">
        <input type="hidden" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][group_owner]" value="<?php echo $group_owner; ?>">
      </td>
      <td><input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][price_single]" value="<?php echo $price_single ?: $price; ?>"></td>
      <td><input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][price_couple]" value="<?php echo $price_couple; ?>"></td>
      <td><input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][price_group]" value="<?php echo $price_group; ?>"></td>
      <td class="rbw-guest-types">
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][guest_types][]" value="single" <?php checked(in_array('single', $guest_types, true)); ?>> <?php esc_html_e('Single', 'rbw'); ?></label>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][guest_types][]" value="couple" <?php checked(in_array('couple', $guest_types, true)); ?>> <?php esc_html_e('Couple', 'rbw'); ?></label>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][guest_types][]" value="group" <?php checked(in_array('group', $guest_types, true)); ?>> <?php esc_html_e('Group', 'rbw'); ?></label>
      </td>
      <td><input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][deposit]" value="<?php echo $deposit; ?>"></td>
      <td><input type="number" step="1" min="0" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][stock]" value="<?php echo $stock; ?>"></td>
      <td class="rbw-img-cell">
        <div class="rbw-img-preview-list" data-rbw-image-preview-list>
          <?php if (!empty($images)): ?>
            <?php foreach ($images as $pos => $img): ?>
              <div class="rbw-img-thumb">
                <img src="<?php echo esc_url($img); ?>" alt="">
                <button type="button" class="rbw-img-remove" data-img-remove="<?php echo (int)$pos; ?>" aria-label="<?php esc_attr_e('Remove image', 'rbw'); ?>">&times;</button>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="rbw-img-empty"><?php esc_html_e('No image selected', 'rbw'); ?></div>
          <?php endif; ?>
        </div>
        <input type="hidden" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][image]" value="<?php echo esc_attr($image); ?>">
        <div data-rbw-image-hidden-list>
          <?php foreach ($images as $img): ?>
            <input type="hidden" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][images][]" value="<?php echo esc_attr($img); ?>">
          <?php endforeach; ?>
        </div>
        <div class="rbw-img-actions">
          <button type="button" class="button rbw-upload"><?php esc_html_e('Upload Images', 'rbw'); ?></button>
          <button type="button" class="button rbw-clear-images"><?php esc_html_e('Clear', 'rbw'); ?></button>
        </div>
      </td>
      <td><button type="button" class="button-link-delete rbw-remove"><?php esc_html_e('Remove', 'rbw'); ?></button></td>
    </tr>
    <?php
  }

  public static function render_booking_invoice(){
    if (empty($_GET['booking_id'])) wp_die(__('Missing booking ID', 'rbw'));

    $booking_id = absint($_GET['booking_id']);
    if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'rbw'));
    check_admin_referer('rbw_invoice_booking_'.$booking_id);

    $download_pdf = !empty($_GET['download']);
    self::output_invoice($booking_id, false, $download_pdf, $download_pdf);
  }

  public static function render_customer_booking_invoice(){
    if (!(int)get_option(self::OPT_INVOICE_ENABLE_CUSTOMER, 1)) {
      wp_die(__('Invoice sharing is disabled.', 'rbw'));
    }
    if (empty($_GET['booking_id']) || empty($_GET['token'])) {
      wp_die(__('Missing invoice data.', 'rbw'));
    }

    $booking_id = absint($_GET['booking_id']);
    $token = sanitize_text_field((string)$_GET['token']);
    if (!$booking_id || $token === '') {
      wp_die(__('Invalid invoice request.', 'rbw'));
    }

    $post = get_post($booking_id);
    if (!$post || $post->post_type !== 'rbw_booking') {
      wp_die(__('Booking not found.', 'rbw'));
    }

    $saved = (string)get_post_meta($booking_id, '_rbw_invoice_token', true);
    if ($saved === '' || !hash_equals($saved, $token)) {
      wp_die(__('Invalid invoice link.', 'rbw'));
    }

    if ($post->post_status !== 'publish') {
      wp_die(__('Invoice is not available until payment is completed.', 'rbw'));
    }

    $download_pdf = !empty($_GET['download']);
    self::output_invoice($booking_id, true, $download_pdf, $download_pdf);
  }

  private static function output_invoice($booking_id, $is_public = false, $auto_print = false, $download_pdf = false){
    $booking_id = absint($booking_id);
    if (!$booking_id) wp_die(__('Invalid booking.', 'rbw'));

    $post = get_post($booking_id);
    if (!$post || $post->post_type !== 'rbw_booking') {
      wp_die(__('Booking not found', 'rbw'));
    }

    $room = (string)get_post_meta($booking_id, '_rbw_room_name', true);
    $rooms_json = get_post_meta($booking_id, '_rbw_rooms_json', true);
    $rooms = self::decode_rooms_meta($rooms_json);
    $room_parts = self::parse_rooms_meta($rooms_json);
    $rooms_display = !empty($room_parts) ? implode(', ', $room_parts) : ($room ?: $post->post_title);
    $check_in = (string)get_post_meta($booking_id, '_rbw_check_in', true);
    $check_out = (string)get_post_meta($booking_id, '_rbw_check_out', true);
    $nights = (int)get_post_meta($booking_id, '_rbw_nights', true);
    $total = (float)get_post_meta($booking_id, '_rbw_total', true);
    $deposit = (float)get_post_meta($booking_id, '_rbw_deposit', true);
    $balance = (float)get_post_meta($booking_id, '_rbw_balance', true);
    $discount = (float)get_post_meta($booking_id, '_rbw_discount', true);
    $pay_mode = (string)get_post_meta($booking_id, '_rbw_pay_mode', true);
    $guest = (string)get_post_meta($booking_id, '_rbw_customer_name', true);
    $phone = (string)get_post_meta($booking_id, '_rbw_customer_phone', true);
    $guests = (int)get_post_meta($booking_id, '_rbw_guests', true);
    $guest_type = (string)get_post_meta($booking_id, '_rbw_guest_type', true);
    $nid = (string)get_post_meta($booking_id, '_rbw_nid_url', true);
    $order_id = (string)get_post_meta($booking_id, '_rbw_order_id', true);
    $created = get_the_time(get_option('date_format') . ' ' . get_option('time_format'), $post);

    $invoice_logo = esc_url((string)get_option(self::OPT_INVOICE_LOGO, ''));
    $invoice_business_name = sanitize_text_field((string)get_option(self::OPT_INVOICE_BUSINESS_NAME, get_bloginfo('name')));
    if ($invoice_business_name === '') $invoice_business_name = get_bloginfo('name');
    $invoice_address = sanitize_textarea_field((string)get_option(self::OPT_INVOICE_ADDRESS, ''));
    $invoice_phone = sanitize_text_field((string)get_option(self::OPT_INVOICE_PHONE, ''));
    $invoice_email = sanitize_email((string)get_option(self::OPT_INVOICE_EMAIL, get_option('admin_email')));
    $invoice_accent = self::sanitize_invoice_color((string)get_option(self::OPT_INVOICE_ACCENT, '#f07a22'));
    $invoice_watermark_opacity = self::sanitize_invoice_opacity(get_option(self::OPT_INVOICE_WATERMARK_OPACITY, 0.06));
    $customer_invoice_url = '';
    if (!$is_public && (int)get_option(self::OPT_INVOICE_ENABLE_CUSTOMER, 1)) {
      $customer_invoice_url = self::get_customer_invoice_url(
        $booking_id,
        (bool)get_option(self::OPT_INVOICE_AUTO_DOWNLOAD, 0)
      );
    }

    $guest_type_label = $guest_type === 'single'
      ? 'Single'
      : ($guest_type === 'couple' ? 'Couple' : ($guest_type === 'group' ? 'Group' : '-'));
    $pay_mode_label = $pay_mode === 'full' ? 'Full Payment' : 'Advance Payment';

    $money = function($amount){
      $amount = (float)$amount;
      if (function_exists('wc_price')) return wc_price($amount);
      return '$' . number_format($amount, 2);
    };

    $rows = [];
    foreach ($rooms as $idx => $r) {
      if (!is_array($r)) continue;
      $name = sanitize_text_field($r['room_name'] ?? '');
      if ($name === '') continue;
      $line_guests = max(0, (int)($r['guests'] ?? 0));
      $line_ppn = (float)($r['price_per_night'] ?? 0);
      $line_nights = $nights > 0 ? $nights : 1;
      $line_type = sanitize_text_field($r['guest_type'] ?? $guest_type);
      $line_total = $line_type === 'group'
        ? ($line_ppn * $line_nights * max(1, $line_guests))
        : ($line_ppn * $line_nights);
      $rows[] = [
        'no' => $idx + 1,
        'name' => $name,
        'guests' => $line_guests,
        'ppn' => $line_ppn,
        'nights' => $line_nights,
        'total' => $line_total,
      ];
    }

    if (empty($rows)) {
      $rows[] = [
        'no' => 1,
        'name' => $rooms_display,
        'guests' => max(0, $guests),
        'ppn' => 0,
        'nights' => max(0, $nights),
        'total' => $total,
      ];
    }

    ob_start();
    ?>
    <!doctype html>
    <html lang="bn">
    <head>
      <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?php echo esc_html(sprintf(__('Invoice #%d', 'rbw'), $booking_id)); ?></title>
      <style>
        @font-face{
          font-family:'SolaimanLipi';
          src:url('<?php echo esc_url(RBW_PLUGIN_URL . 'assets/fonts/SolaimanLipi.ttf'); ?>') format('truetype');
          font-weight:400;
          font-style:normal;
        }
        :root{
          --rbw-invoice-accent: <?php echo esc_html($invoice_accent); ?>;
          --rbw-invoice-watermark-opacity: <?php echo esc_html(number_format((float)$invoice_watermark_opacity, 2, '.', '')); ?>;
        }
        body{ margin:0; background:#f3f4f6; font-family: 'SolaimanLipi'; color:#111827; }
        .wrap{ max-width: 900px; margin: 18px auto; padding: 0 14px; }
        .bar{ margin-bottom: 10px; display:flex; gap:10px; justify-content:flex-end; }
        .bar a, .bar button{
          border:1px solid #d1d5db; background:#fff; color:#111827; border-radius:8px; padding:8px 12px; cursor:pointer; text-decoration:none; font-size:13px;
        }
        .invoice{ background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; position:relative; isolation:isolate; }
        .invoice > *{ position:relative; z-index:1; }
        <?php if ($invoice_logo && $invoice_watermark_opacity > 0): ?>
        .invoice::before{
          content:'';
          position:absolute;
          inset:90px 40px 28px 40px;
          background: url('<?php echo esc_url($invoice_logo); ?>') center 50% / 58% auto no-repeat;
          opacity: var(--rbw-invoice-watermark-opacity);
          pointer-events:none;
          z-index:0;
        }
        <?php endif; ?>
        .head{ padding:18px; border-bottom:1px solid #e5e7eb; border-top:4px solid var(--rbw-invoice-accent); display:flex; justify-content:space-between; gap:16px; }
        .head h1{ margin:0; font-size:24px; }
        .brand{ display:flex; align-items:flex-start; gap:12px; }
        .brand-logo{
          width:72px;
          height:72px;
          object-fit:contain;
          border:1px solid #e5e7eb;
          border-radius:8px;
          background:#fff;
          padding:4px;
          flex: 0 0 auto;
        }
        .brand-name{ margin-top:6px;color:#6b7280;font-size:13px; }
        .meta{ font-size:13px; color:#374151; line-height:1.6; text-align:right; }
        .meta strong{ color: var(--rbw-invoice-accent); }
        a{ color: var(--rbw-invoice-accent); }
        .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:14px; padding: 16px 18px; border-bottom:1px solid #e5e7eb; }
        .box{ border:1px solid #e5e7eb; border-radius:10px; padding:12px; }
        .box h3{ margin:0 0 8px 0; font-size:13px; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; }
        .box p{ margin:4px 0; font-size:14px; }
        table{ width:100%; border-collapse:collapse; border:1px solid #111827; }
        th, td{ border:1px solid #111827; padding:10px 12px; text-align:left; font-size:13px; vertical-align:top; }
        th{ background:#111827; color:#fff; font-weight:700; }
        td.right, th.right{ text-align:right; }
        .totals{ display:flex; justify-content:flex-end; padding:14px 18px 18px; }
        .totals table{ width:320px; }
        .totals td{ border-bottom:0; padding:6px 0; }
        .totals .grand td{ border-top:1px solid #e5e7eb; padding-top:10px; font-weight:800; font-size:16px; color: var(--rbw-invoice-accent); }
        .foot{ padding: 0 18px 18px; color:#6b7280; font-size:12px; }
        @media print{
          body{ background:#fff; }
          .bar{ display:none !important; }
          .wrap{ margin:0; padding:0; max-width:none; }
          .invoice{ border:0; border-radius:0; }
        }
      </style>
      <?php if ($auto_print && !$download_pdf): ?>
      <script>
        window.addEventListener('load', function(){ window.print(); });
      </script>
      <?php endif; ?>
    </head>
    <body>
      <div class="wrap">
        <div class="bar">
          <?php if (!$is_public): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=rbw-bookings')); ?>"><?php esc_html_e('Back to Bookings', 'rbw'); ?></a>
            <?php if ($customer_invoice_url !== ''): ?>
              <a href="<?php echo esc_url($customer_invoice_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Customer Invoice Link', 'rbw'); ?></a>
            <?php endif; ?>
          <?php endif; ?>
          <button type="button" onclick="window.print();"><?php esc_html_e('Print / Save PDF', 'rbw'); ?></button>
        </div>
        <section class="invoice">
          <header class="head">
            <div class="brand">
              <?php if ($invoice_logo): ?>
                <img class="brand-logo" src="<?php echo esc_url($invoice_logo); ?>" alt="<?php echo esc_attr($invoice_business_name); ?>">
              <?php endif; ?>
              <div>
                <h1><?php esc_html_e('Booking Invoice', 'rbw'); ?></h1>
                <div class="brand-name"><?php echo esc_html($invoice_business_name); ?></div>
                <?php if ($invoice_address !== ''): ?>
                  <div class="brand-name"><?php echo nl2br(esc_html($invoice_address)); ?></div>
                <?php endif; ?>
                <?php if ($invoice_phone !== '' || $invoice_email !== ''): ?>
                  <div class="brand-name">
                    <?php
                      $parts = [];
                      if ($invoice_phone !== '') $parts[] = $invoice_phone;
                      if ($invoice_email !== '') $parts[] = $invoice_email;
                      echo esc_html(implode(' | ', $parts));
                    ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="meta">
              <div><strong><?php esc_html_e('Invoice #', 'rbw'); ?></strong> <?php echo esc_html('RBW-' . $booking_id); ?></div>
              <div><strong><?php esc_html_e('Booking ID', 'rbw'); ?></strong> <?php echo esc_html($booking_id); ?></div>
              <div><strong><?php esc_html_e('Created', 'rbw'); ?></strong> <?php echo esc_html($created); ?></div>
              <?php if ($order_id !== ''): ?>
                <div><strong><?php esc_html_e('Order ID', 'rbw'); ?></strong> <?php echo esc_html($order_id); ?></div>
              <?php endif; ?>
            </div>
          </header>

          <div class="grid">
            <div class="box">
              <h3><?php esc_html_e('Guest', 'rbw'); ?></h3>
              <p><strong><?php echo esc_html($guest ?: '-'); ?></strong></p>
              <p><?php esc_html_e('Phone:', 'rbw'); ?> <?php echo esc_html($phone ?: '-'); ?></p>
              <p><?php esc_html_e('Guests:', 'rbw'); ?> <?php echo esc_html($guests); ?></p>
              <p><?php esc_html_e('Guest Type:', 'rbw'); ?> <?php echo esc_html($guest_type_label); ?></p>
            </div>
            <div class="box">
              <h3><?php esc_html_e('Stay Details', 'rbw'); ?></h3>
              <p><?php esc_html_e('Check In:', 'rbw'); ?> <?php echo esc_html($check_in ?: '-'); ?></p>
              <p><?php esc_html_e('Check Out:', 'rbw'); ?> <?php echo esc_html($check_out ?: '-'); ?></p>
              <p><?php esc_html_e('Nights:', 'rbw'); ?> <?php echo esc_html($nights); ?></p>
              <p><?php esc_html_e('Payment Mode:', 'rbw'); ?> <?php echo esc_html($pay_mode_label); ?></p>
              <?php if (!$is_public && $nid !== ''): ?>
                <p><?php esc_html_e('ID Document:', 'rbw'); ?> <a href="<?php echo esc_url($nid); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'rbw'); ?></a></p>
              <?php endif; ?>
            </div>
          </div>

          <table>
            <thead>
              <tr>
                <th>#</th>
                <th><?php esc_html_e('Room', 'rbw'); ?></th>
                <th class="right"><?php esc_html_e('Guests', 'rbw'); ?></th>
                <th class="right"><?php esc_html_e('Rate / Night', 'rbw'); ?></th>
                <th class="right"><?php esc_html_e('Nights', 'rbw'); ?></th>
                <th class="right"><?php esc_html_e('Line Total', 'rbw'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $line): ?>
                <tr>
                  <td><?php echo esc_html($line['no']); ?></td>
                  <td><?php echo esc_html($line['name']); ?></td>
                  <td class="right"><?php echo esc_html($line['guests']); ?></td>
                  <td class="right"><?php echo wp_kses_post($money($line['ppn'])); ?></td>
                  <td class="right"><?php echo esc_html($line['nights']); ?></td>
                  <td class="right"><?php echo wp_kses_post($money($line['total'])); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="totals">
            <table>
              <tr>
                <td><?php esc_html_e('Total', 'rbw'); ?></td>
                <td class="right"><?php echo wp_kses_post($money($total)); ?></td>
              </tr>
              <?php if ($discount > 0): ?>
                <tr>
                  <td><?php esc_html_e('Discount', 'rbw'); ?></td>
                  <td class="right">-<?php echo wp_kses_post($money($discount)); ?></td>
                </tr>
              <?php endif; ?>
              <tr>
                <td><?php esc_html_e('Paid Now', 'rbw'); ?></td>
                <td class="right"><?php echo wp_kses_post($money($deposit)); ?></td>
              </tr>
              <tr class="grand">
                <td><?php esc_html_e('Due', 'rbw'); ?></td>
                <td class="right"><?php echo wp_kses_post($money($balance)); ?></td>
              </tr>
            </table>
          </div>

          <div class="foot">
            <?php esc_html_e('Rooms:', 'rbw'); ?> <?php echo esc_html($rooms_display); ?>
            <?php if ($is_public): ?>
              <br><?php esc_html_e('Customer copy. Sensitive document links are hidden.', 'rbw'); ?>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </body>
    </html>
    <?php
    $invoice_html = ob_get_clean();
    if ($download_pdf) {
      self::stream_invoice_pdf($booking_id, $invoice_html);
      exit;
    }
    echo $invoice_html;
    exit;
  }

  private static function stream_invoice_pdf($booking_id, $html){
    $mpdf = self::create_mpdf_instance();
    if (!$mpdf) {
      header('Content-Type: text/html; charset=utf-8');
      echo $html;
      return;
    }
    $title = sprintf(__('Invoice #%d', 'rbw'), $booking_id);
    $filename = 'rbw-invoice-' . $booking_id . '.pdf';
    try {
      $mpdf->SetTitle($title);
      $mpdf->WriteHTML($html);
      $mpdf->Output($filename, 'D');
    } catch (\Throwable $e) {
      error_log('[RBW] mpdf output failed: ' . $e->getMessage());
      header('Content-Type: text/html; charset=utf-8');
      echo $html;
    }
  }

  private static function create_mpdf_instance(){
    static $cached;
    if ($cached !== null) return $cached;
    $autoload = RBW_PLUGIN_DIR . 'vendor/autoload.php';
    if (!is_file($autoload)) {
      $cached = false;
      return $cached;
    }
    $font_file = RBW_PLUGIN_DIR . 'assets/fonts/SolaimanLipi.ttf';
    if (!is_file($font_file)) {
      $cached = false;
      return $cached;
    }
    require_once $autoload;
    try {
      $fontDirs = [RBW_PLUGIN_DIR . 'assets/fonts'];
      $fontData = [
        'solaimanlipi' => [
          'R' => 'SolaimanLipi.ttf',
          'B' => 'SolaimanLipi.ttf',
          'I' => 'SolaimanLipi.ttf',
          'BI' => 'SolaimanLipi.ttf',
          'useOTL' => 0xFF,
        ],
      ];
      $cached = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => 'solaimanlipi',
        'autoScriptToLang' => false,
        'autoLangToFont' => false,
        'useSubstitutions' => false,
        'fontDir' => $fontDirs,
        'fontdata' => $fontData,
      ]);
      $cached->SetFont('solaimanlipi', '', 0, true);
    } catch (\Throwable $e) {
      error_log('[RBW] mpdf init failed: ' . $e->getMessage());
      $cached = false;
    }
    return $cached;
  }

  public static function cancel_booking(){
    if (empty($_GET['booking_id'])) wp_die(__('Missing booking ID', 'rbw'));

    $booking_id = absint($_GET['booking_id']);
    if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'rbw'));

    check_admin_referer('rbw_cancel_booking_'.$booking_id);

    // Move to trash so availability frees up
    wp_trash_post($booking_id);

    $redirect = !empty($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : admin_url('admin.php?page=rbw-bookings');
    if (class_exists('RBW_SMS') && method_exists('RBW_SMS', 'is_cancel_enabled') && RBW_SMS::is_cancel_enabled()) {
      $sms_result = RBW_SMS::send_booking_cancelled($booking_id, true);
      if (is_wp_error($sms_result)) {
        $redirect = add_query_arg([
          'rbw_sms' => 'error',
          'rbw_sms_msg' => $sms_result->get_error_message(),
        ], $redirect);
      } else {
        $redirect = add_query_arg(['rbw_sms' => 'sent'], $redirect);
      }
    }
    wp_safe_redirect($redirect);
    exit;
  }

  public static function retry_sms(){
    if (empty($_GET['booking_id'])) wp_die(__('Missing booking ID', 'rbw'));
    $booking_id = absint($_GET['booking_id']);
    if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'rbw'));

    check_admin_referer('rbw_retry_sms_'.$booking_id);

    $redirect = !empty($_GET['redirect_to']) ? esc_url_raw((string)$_GET['redirect_to']) : admin_url('admin.php?page=rbw-bookings');
    $sms_type = sanitize_key((string)($_GET['sms_type'] ?? 'booking'));
    if (!class_exists('RBW_SMS')) {
      wp_safe_redirect(add_query_arg(['rbw_sms' => 'missing'], $redirect));
      exit;
    }

    if ($sms_type === 'cancel') {
      if (!method_exists('RBW_SMS', 'send_booking_cancelled')) {
        wp_safe_redirect(add_query_arg(['rbw_sms' => 'missing'], $redirect));
        exit;
      }
      $result = RBW_SMS::send_booking_cancelled($booking_id, true);
    } else {
      $result = RBW_SMS::send_booking_confirmation($booking_id, true);
    }
    if (is_wp_error($result)) {
      $msg = $result->get_error_message();
      wp_safe_redirect(add_query_arg([
        'rbw_sms' => 'error',
        'rbw_sms_msg' => $msg,
      ], $redirect));
      exit;
    }

    wp_safe_redirect(add_query_arg(['rbw_sms' => 'sent'], $redirect));
    exit;
  }

  public static function export_bookings(){
    if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'rbw'));
    check_admin_referer('rbw_export_bookings');

    $from_date = self::sanitize_history_date($_GET['from_date'] ?? '');
    $to_date = self::sanitize_history_date($_GET['to_date'] ?? '');
    $status = sanitize_key((string)($_GET['status'] ?? 'all'));
    if (!in_array($status, ['all', 'publish', 'completed', 'pending', 'trash'], true)) $status = 'all';
    $sort = strtolower(sanitize_text_field((string)($_GET['sort'] ?? 'desc')));
    if (!in_array($sort, ['asc', 'desc'], true)) $sort = 'desc';

    $query_args = [
      'post_type' => 'rbw_booking',
      'posts_per_page' => -1,
      'orderby' => 'date',
      'order' => strtoupper($sort),
    ];
    $query_args = self::apply_history_status_filter($query_args, $status);
    $date_query = [];
    if ($from_date !== '') $date_query['after'] = $from_date;
    if ($to_date !== '') $date_query['before'] = $to_date;
    if (!empty($date_query)) {
      $date_query['inclusive'] = true;
      $date_query['column'] = 'post_date';
      $query_args['date_query'] = [$date_query];
    }
    $query = new WP_Query($query_args);

    $filename = 'rbw-booking-history-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
      'Booking ID',
      'Created',
      'Status',
      'Rooms',
      'Guest Name',
      'Phone',
      'Guests',
      'Check In',
      'Check Out',
      'Total',
      'Deposit',
      'Due',
      'Payment Mode',
      'Discount',
      'Rooms Needed',
      'Rooms JSON',
      'NID URL',
      'Order ID',
    ]);

    foreach ($query->posts as $post){
      $room = get_post_meta($post->ID, '_rbw_room_name', true);
      $check_in = get_post_meta($post->ID, '_rbw_check_in', true);
      $check_out = get_post_meta($post->ID, '_rbw_check_out', true);
      $total = get_post_meta($post->ID, '_rbw_total', true);
      $deposit = get_post_meta($post->ID, '_rbw_deposit', true);
      $balance = get_post_meta($post->ID, '_rbw_balance', true);
      $guest = get_post_meta($post->ID, '_rbw_customer_name', true);
      $phone = get_post_meta($post->ID, '_rbw_customer_phone', true);
      $guests = get_post_meta($post->ID, '_rbw_guests', true);
      $pay_mode = get_post_meta($post->ID, '_rbw_pay_mode', true);
      $discount = get_post_meta($post->ID, '_rbw_discount', true);
      $rooms_needed = get_post_meta($post->ID, '_rbw_rooms_needed', true);
      $rooms_json = get_post_meta($post->ID, '_rbw_rooms_json', true);
      $room_parts = self::parse_rooms_meta($rooms_json);
      $room_display = !empty($room_parts) ? implode(', ', $room_parts) : ($room ?: $post->post_title);
      $status_parts = self::get_booking_status_parts($post);
      $status_label = $status_parts['label'];
      $nid = get_post_meta($post->ID, '_rbw_nid_url', true);
      $order_id = get_post_meta($post->ID, '_rbw_order_id', true);

      fputcsv($out, [
        $post->ID,
        get_the_time(get_option('date_format') . ' ' . get_option('time_format'), $post),
        $status_label,
        $room_display,
        $guest,
        $phone,
        $guests,
        $check_in,
        $check_out,
        $total,
        $deposit,
        $balance,
        $pay_mode,
        $discount,
        $rooms_needed,
        $rooms_json,
        $nid,
        $order_id,
      ]);
    }

    fclose($out);
    exit;
  }
}

