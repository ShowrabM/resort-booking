<?php
if (!defined('ABSPATH')) exit;

class RBW_SMS {
  const OPT_PROVIDER = 'rbw_sms_provider';
  const OPT_ENABLE = 'rbw_sms_enable';
  const OPT_ENABLE_CANCEL = 'rbw_sms_enable_cancel';
  const OPT_API_URL = 'rbw_sms_api_url';
  const OPT_API_KEY = 'rbw_sms_api_key';
  const OPT_API_KEY_HEADER = 'rbw_sms_api_key_header';
  const OPT_API_KEY_PREFIX = 'rbw_sms_api_key_prefix';
  const OPT_SENDER_ID = 'rbw_sms_sender_id';
  const OPT_TEMPLATE = 'rbw_sms_template';
  const OPT_TEMPLATE_CANCEL = 'rbw_sms_template_cancel';

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'add_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
  }

  public static function add_menu(){
    add_submenu_page(
      'rbw-settings',
      __('SMS Settings', 'rbw'),
      __('SMS Settings', 'rbw'),
      'manage_options',
      'rbw-sms-settings',
      [__CLASS__, 'render_settings_page']
    );
  }

  public static function register_settings(){
    register_setting('rbw_sms_settings', self::OPT_PROVIDER, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_provider'],
      'default' => 'generic',
    ]);
    register_setting('rbw_sms_settings', self::OPT_ENABLE, [
      'type' => 'boolean',
      'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
      'default' => 0,
    ]);
    register_setting('rbw_sms_settings', self::OPT_ENABLE_CANCEL, [
      'type' => 'boolean',
      'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
      'default' => 0,
    ]);
    register_setting('rbw_sms_settings', self::OPT_API_URL, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_api_url'],
      'default' => '',
    ]);
    register_setting('rbw_sms_settings', self::OPT_API_KEY, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_text'],
      'default' => '',
    ]);
    register_setting('rbw_sms_settings', self::OPT_API_KEY_HEADER, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_header_name'],
      'default' => 'Authorization',
    ]);
    register_setting('rbw_sms_settings', self::OPT_API_KEY_PREFIX, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_text'],
      'default' => 'Bearer ',
    ]);
    register_setting('rbw_sms_settings', self::OPT_SENDER_ID, [
      'type' => 'string',
      'sanitize_callback' => [__CLASS__, 'sanitize_text'],
      'default' => '',
    ]);
    register_setting('rbw_sms_settings', self::OPT_TEMPLATE, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_textarea_field',
      'default' => self::default_template(),
    ]);
    register_setting('rbw_sms_settings', self::OPT_TEMPLATE_CANCEL, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_textarea_field',
      'default' => self::default_cancel_template(),
    ]);
  }

  public static function sanitize_checkbox($value){
    return empty($value) ? 0 : 1;
  }

  public static function sanitize_text($value){
    return trim((string)$value);
  }

  public static function sanitize_provider($value){
    $v = strtolower(trim((string)$value));
    return in_array($v, ['generic', 'bulksmsbd'], true) ? $v : 'generic';
  }

  public static function sanitize_header_name($value){
    $name = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$value);
    return $name !== '' ? $name : 'Authorization';
  }

  public static function sanitize_api_url($value){
    $url = trim((string)$value);
    if ($url === '') return '';

    // Remove hidden spaces/newlines that often break HTTP URL parsing.
    $url = preg_replace('/\s+/', '', $url);
    if ($url === '') return '';

    if (!preg_match('#^https?://#i', $url)) {
      $url = 'http://' . ltrim($url, '/');
    }

    return esc_url_raw($url);
  }

  private static function default_template(){
    return 'Hi {guest_name}, your booking #{booking_id} is confirmed. '
      . 'Check-in: {check_in}, Check-out: {check_out}. '
      . 'Paid: {deposit}, Due: {due}.';
  }

  private static function default_cancel_template(){
    return 'Hi {guest_name}, your booking #{booking_id} has been cancelled. '
      . 'Check-in: {check_in}, Check-out: {check_out}.';
  }

  private static function is_enabled(){
    return (int)get_option(self::OPT_ENABLE, 0) === 1;
  }

  public static function is_cancel_enabled(){
    return (int)get_option(self::OPT_ENABLE_CANCEL, 0) === 1;
  }

  private static function is_bulksmsbd_mode(){
    $provider = self::sanitize_provider(get_option(self::OPT_PROVIDER, 'generic'));
    if ($provider === 'bulksmsbd') return true;
    $api_url = strtolower(trim((string)get_option(self::OPT_API_URL, '')));
    return strpos($api_url, 'bulksmsbd.net/api/smsapi') !== false;
  }

  private static function normalize_phone($phone){
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') return '';
    if (strpos($digits, '880') === 0) return $digits;
    if (strpos($digits, '0') === 0) return '88' . $digits;
    if (strpos($digits, '1') === 0 && strlen($digits) === 10) return '880' . $digits;
    return $digits;
  }

  private static function normalize_bulksmsbd_url($url){
    $url = trim((string)$url);
    if ($url === '') return $url;
    if (stripos($url, 'getbalanceapi') !== false) {
      $url = preg_replace('/getbalanceapi/i', 'smsapi', $url);
    }
    if (strpos($url, '?') !== false) {
      $url = (string)strtok($url, '?');
    }
    return rtrim($url, '?&');
  }

  private static function normalize_bulksmsbd_message($message){
    $message = (string)$message;
    // Bulk gateways often truncate on hard line breaks; keep one-line text.
    $message = preg_replace("/\r\n|\r|\n/u", ' ', $message);
    $message = preg_replace('/\s{2,}/u', ' ', $message);
    return trim($message);
  }

  private static function get_validated_api_url($bulksms_mode = false){
    $api_url = self::sanitize_api_url(get_option(self::OPT_API_URL, ''));
    if ($bulksms_mode) {
      $api_url = self::normalize_bulksmsbd_url($api_url);
      if ($api_url === '' || !wp_http_validate_url($api_url)) {
        $api_url = 'http://bulksmsbd.net/api/smsapi';
      }
    }

    if ($api_url === '') {
      return new WP_Error('rbw_sms_missing_api', 'SMS API URL is not configured.');
    }
    if (!wp_http_validate_url($api_url)) {
      return new WP_Error(
        'rbw_sms_invalid_api_url',
        'Invalid SMS API URL. Example: http://bulksmsbd.net/api/smsapi'
      );
    }

    return $api_url;
  }

  private static function bulksmsbd_error_message($code){
    $map = [
      '202' => 'SMS Submitted Successfully',
      '1001' => 'Invalid Number',
      '1002' => 'Sender ID not correct or disabled',
      '1003' => 'Required field missing',
      '1005' => 'Internal Error',
      '1006' => 'Balance validity not available',
      '1007' => 'Balance insufficient',
      '1011' => 'User ID not found',
      '1012' => 'Masking SMS must be sent in Bengali',
      '1013' => 'Sender ID gateway not found by API key',
      '1014' => 'Sender type name not found using this sender by API key',
      '1015' => 'Sender ID has no valid gateway by API key',
      '1016' => 'Active price info not found by sender ID',
      '1017' => 'Price info not found by sender ID',
      '1018' => 'Account owner is disabled',
      '1019' => 'Sender type price is disabled',
      '1020' => 'Parent account not found',
      '1021' => 'Parent active sender type price not found',
      '1031' => 'Account not verified',
      '1032' => 'IP not whitelisted',
    ];
    return $map[(string)$code] ?? 'Unknown SMS API error';
  }

  private static function format_amount($value){
    $n = (float)$value;
    $is_int = abs($n - round($n)) < 0.00001;
    $formatted = $is_int ? number_format_i18n($n, 0) : number_format_i18n($n, 2);
    return $formatted . ' Tk';
  }

  private static function decode_rooms($rooms_meta){
    if (is_string($rooms_meta) && $rooms_meta !== '') {
      $decoded = json_decode($rooms_meta, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
    }
    return is_array($rooms_meta) ? $rooms_meta : [];
  }

  private static function format_rooms($rooms_meta){
    $rooms = self::decode_rooms($rooms_meta);
    if (empty($rooms)) return '';
    $out = [];
    foreach ($rooms as $room) {
      if (!is_array($room)) continue;
      $name = sanitize_text_field($room['room_name'] ?? '');
      if ($name === '') continue;
      $g = isset($room['guests']) ? max(0, (int)$room['guests']) : 0;
      $out[] = $g > 0 ? ($name . ' (' . $g . ' guests)') : $name;
    }
    return implode(', ', $out);
  }

  private static function message_context($booking_id){
    $booking_id = absint($booking_id);
    if (!$booking_id) return null;
    $post = get_post($booking_id);
    if (!$post || $post->post_type !== 'rbw_booking') return null;

    $guest_name = sanitize_text_field((string)get_post_meta($booking_id, '_rbw_customer_name', true));
    $guest_phone = sanitize_text_field((string)get_post_meta($booking_id, '_rbw_customer_phone', true));
    $check_in = sanitize_text_field((string)get_post_meta($booking_id, '_rbw_check_in', true));
    $check_out = sanitize_text_field((string)get_post_meta($booking_id, '_rbw_check_out', true));
    $total = (float)get_post_meta($booking_id, '_rbw_total', true);
    $deposit = (float)get_post_meta($booking_id, '_rbw_deposit', true);
    $due = (float)get_post_meta($booking_id, '_rbw_balance', true);
    $rooms = self::format_rooms(get_post_meta($booking_id, '_rbw_rooms_json', true));
    if ($rooms === '') {
      $rooms = sanitize_text_field((string)get_post_meta($booking_id, '_rbw_room_name', true));
    }
    $invoice_url = class_exists('RBW_Admin') ? RBW_Admin::get_customer_invoice_url($booking_id, false) : '';

    return [
      '{booking_id}' => (string)$booking_id,
      '{guest_name}' => $guest_name,
      '{guest_phone}' => $guest_phone,
      '{check_in}' => $check_in,
      '{check_out}' => $check_out,
      '{total}' => self::format_amount($total),
      '{deposit}' => self::format_amount($deposit),
      '{due}' => self::format_amount($due),
      '{rooms}' => $rooms,
      '{invoice_url}' => $invoice_url,
      '_guest_phone' => $guest_phone,
    ];
  }

  private static function send_message($booking_id, $to, $message, $sent_meta_key, $error_meta_key, $response_meta_key, $force = false){
    $booking_id = absint($booking_id);
    if (!$booking_id) return new WP_Error('rbw_sms_invalid_booking', 'Invalid booking ID.');
    if (!$force && (string)get_post_meta($booking_id, $sent_meta_key, true) !== '') return true;

    if ($to === '') return new WP_Error('rbw_sms_missing_phone', 'Guest phone is missing.');

    $bulksms_mode = self::is_bulksmsbd_mode();
    $api_url = self::get_validated_api_url($bulksms_mode);
    if (is_wp_error($api_url)) return $api_url;

    if ($bulksms_mode) {
      $api_key = trim((string)get_option(self::OPT_API_KEY, ''));
      $sender_id = trim((string)get_option(self::OPT_SENDER_ID, ''));
      $number = self::normalize_phone($to);
      $message = self::normalize_bulksmsbd_message($message);
      if ($api_key === '') return new WP_Error('rbw_sms_missing_api_key', 'SMS API key is not configured.');
      if ($sender_id === '') return new WP_Error('rbw_sms_missing_sender', 'SMS sender ID is not configured.');
      if ($number === '') return new WP_Error('rbw_sms_missing_phone', 'Guest phone is invalid.');
      if ($message === '') return new WP_Error('rbw_sms_empty_message', 'SMS message is empty.');

      $payload = [
        'api_key' => $api_key,
        'type' => 'text',
        'number' => $number,
        'senderid' => $sender_id,
        'message' => $message,
      ];

      $res = wp_remote_post($api_url, [
        'timeout' => 25,
        'body' => $payload,
      ]);
    } else {
      $payload = [
        'to' => $to,
        'message' => $message,
        'booking_id' => $booking_id,
      ];

      $sender_id = trim((string)get_option(self::OPT_SENDER_ID, ''));
      if ($sender_id !== '') $payload['sender_id'] = $sender_id;

      $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Accept' => 'application/json',
      ];

      $api_key = trim((string)get_option(self::OPT_API_KEY, ''));
      if ($api_key !== '') {
        $header_name = self::sanitize_header_name(get_option(self::OPT_API_KEY_HEADER, 'Authorization'));
        $prefix = (string)get_option(self::OPT_API_KEY_PREFIX, 'Bearer ');
        $headers[$header_name] = $prefix . $api_key;
      }

      $res = wp_remote_post($api_url, [
        'timeout' => 25,
        'headers' => $headers,
        'body' => wp_json_encode($payload),
      ]);
    }

    if (is_wp_error($res)) {
      $msg = $res->get_error_message();
      if (stripos($msg, 'Cannot parse supplied IRI') !== false) {
        $msg = 'Invalid SMS API URL format. Please set: http://bulksmsbd.net/api/smsapi';
      }
      update_post_meta($booking_id, $error_meta_key, $msg);
      return new WP_Error('rbw_sms_request_failed', $msg);
    }

    $status = (int)wp_remote_retrieve_response_code($res);
    $body = (string)wp_remote_retrieve_body($res);
    $body_short = mb_substr($body, 0, 1500);
    update_post_meta($booking_id, $response_meta_key, $body_short);

    if (self::is_bulksmsbd_mode()) {
      $code = '';
      if (preg_match('/\b(\d{3,4})\b/', $body_short, $m)) {
        $code = (string)$m[1];
      }
      if ($status < 200 || $status >= 300 || $code !== '202') {
        $friendly = self::bulksmsbd_error_message($code);
        $msg = sprintf('BulkSMSBD error (%s): %s', ($code !== '' ? $code : 'no-code'), $friendly);
        update_post_meta($booking_id, $error_meta_key, $msg);
        return new WP_Error('rbw_sms_api_error', $msg);
      }
      update_post_meta($booking_id, $sent_meta_key, current_time('mysql'));
      delete_post_meta($booking_id, $error_meta_key);
      return true;
    }

    if ($status < 200 || $status >= 300) {
      $msg = sprintf('SMS API error (%d): %s', $status, $body_short);
      update_post_meta($booking_id, $error_meta_key, $msg);
      return new WP_Error('rbw_sms_api_error', $msg);
    }

    update_post_meta($booking_id, $sent_meta_key, current_time('mysql'));
    delete_post_meta($booking_id, $error_meta_key);
    return true;
  }

  public static function send_booking_confirmation($booking_id, $force = false){
    $booking_id = absint($booking_id);
    if (!$booking_id) return new WP_Error('rbw_sms_invalid_booking', 'Invalid booking ID.');
    if (!self::is_enabled()) return new WP_Error('rbw_sms_disabled', 'SMS sending is disabled.');

    $ctx = self::message_context($booking_id);
    if (!$ctx) return new WP_Error('rbw_sms_context_failed', 'Unable to build SMS context.');

    $template = trim((string)get_option(self::OPT_TEMPLATE, self::default_template()));
    if ($template === '') $template = self::default_template();
    $message = strtr($template, $ctx);
    $to = trim((string)($ctx['_guest_phone'] ?? ''));
    return self::send_message(
      $booking_id,
      $to,
      $message,
      '_rbw_sms_sent_at',
      '_rbw_sms_last_error',
      '_rbw_sms_last_response',
      $force
    );
  }

  public static function send_booking_cancelled($booking_id, $force = false){
    $booking_id = absint($booking_id);
    if (!$booking_id) return new WP_Error('rbw_sms_invalid_booking', 'Invalid booking ID.');
    if (!self::is_cancel_enabled()) return new WP_Error('rbw_sms_cancel_disabled', 'Cancel SMS is disabled.');

    $ctx = self::message_context($booking_id);
    if (!$ctx) return new WP_Error('rbw_sms_context_failed', 'Unable to build SMS context.');

    $template = trim((string)get_option(self::OPT_TEMPLATE_CANCEL, self::default_cancel_template()));
    if ($template === '') $template = self::default_cancel_template();
    $message = strtr($template, $ctx);
    $to = trim((string)($ctx['_guest_phone'] ?? ''));
    return self::send_message(
      $booking_id,
      $to,
      $message,
      '_rbw_sms_cancel_sent_at',
      '_rbw_sms_cancel_last_error',
      '_rbw_sms_cancel_last_response',
      $force
    );
  }

  public static function render_settings_page(){
    if (!current_user_can('manage_options')) return;

    $provider = self::sanitize_provider(get_option(self::OPT_PROVIDER, 'generic'));
    $enabled = (int)get_option(self::OPT_ENABLE, 0);
    $enable_cancel = (int)get_option(self::OPT_ENABLE_CANCEL, 0);
    $api_url = (string)get_option(self::OPT_API_URL, '');
    $api_key = (string)get_option(self::OPT_API_KEY, '');
    $header = (string)get_option(self::OPT_API_KEY_HEADER, 'Authorization');
    $prefix = (string)get_option(self::OPT_API_KEY_PREFIX, 'Bearer ');
    $sender = (string)get_option(self::OPT_SENDER_ID, '');
    $template = (string)get_option(self::OPT_TEMPLATE, self::default_template());
    $template_cancel = (string)get_option(self::OPT_TEMPLATE_CANCEL, self::default_cancel_template());
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('SMS Settings', 'rbw'); ?></h1>
      <p><?php esc_html_e('Send SMS automatically after payment confirmation and optionally on cancellation.', 'rbw'); ?></p>

      <form method="post" action="options.php">
        <?php settings_fields('rbw_sms_settings'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('Provider', 'rbw'); ?></th>
            <td>
              <select name="<?php echo esc_attr(self::OPT_PROVIDER); ?>">
                <option value="bulksmsbd" <?php selected($provider, 'bulksmsbd'); ?>>BulkSMSBD (Recommended)</option>
                <option value="generic" <?php selected($provider, 'generic'); ?>>Generic JSON API</option>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Enable SMS', 'rbw'); ?></th>
            <td>
              <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPT_ENABLE); ?>" value="1" <?php checked($enabled, 1); ?>>
                <?php esc_html_e('Send SMS when a booking payment is confirmed', 'rbw'); ?>
              </label>
              <br>
              <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPT_ENABLE_CANCEL); ?>" value="1" <?php checked($enable_cancel, 1); ?>>
                <?php esc_html_e('Send SMS when a booking is cancelled', 'rbw'); ?>
              </label>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('SMS API URL', 'rbw'); ?></th>
            <td>
              <input type="url" class="regular-text" name="<?php echo esc_attr(self::OPT_API_URL); ?>" value="<?php echo esc_attr($api_url); ?>" placeholder="http://bulksmsbd.net/api/smsapi">
              <p class="description"><?php esc_html_e('For BulkSMSBD use: http://bulksmsbd.net/api/smsapi', 'rbw'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('API Key', 'rbw'); ?></th>
            <td>
              <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_API_KEY); ?>" value="<?php echo esc_attr($api_key); ?>">
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('API Key Header', 'rbw'); ?></th>
            <td>
              <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_API_KEY_HEADER); ?>" value="<?php echo esc_attr($header); ?>">
              <p class="description"><?php esc_html_e('Example: Authorization or X-API-KEY', 'rbw'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('API Key Prefix', 'rbw'); ?></th>
            <td>
              <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_API_KEY_PREFIX); ?>" value="<?php echo esc_attr($prefix); ?>">
              <p class="description"><?php esc_html_e('Example: Bearer (with a trailing space) or leave empty.', 'rbw'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Sender ID (optional)', 'rbw'); ?></th>
            <td>
              <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_SENDER_ID); ?>" value="<?php echo esc_attr($sender); ?>">
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('SMS Template', 'rbw'); ?></th>
            <td>
              <textarea class="large-text" rows="6" name="<?php echo esc_attr(self::OPT_TEMPLATE); ?>"><?php echo esc_textarea($template); ?></textarea>
              <p class="description">
                <?php esc_html_e('Available placeholders:', 'rbw'); ?>
                <code>{booking_id}</code>, <code>{guest_name}</code>, <code>{guest_phone}</code>, <code>{check_in}</code>, <code>{check_out}</code>, <code>{total}</code>, <code>{deposit}</code>, <code>{due}</code>, <code>{rooms}</code>, <code>{invoice_url}</code>
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Cancel SMS Template', 'rbw'); ?></th>
            <td>
              <textarea class="large-text" rows="4" name="<?php echo esc_attr(self::OPT_TEMPLATE_CANCEL); ?>"><?php echo esc_textarea($template_cancel); ?></textarea>
              <p class="description">
                <?php esc_html_e('Used when booking is cancelled from admin. Supports same placeholders as above.', 'rbw'); ?>
              </p>
            </td>
          </tr>
        </table>
        <?php submit_button(__('Save SMS Settings', 'rbw')); ?>
      </form>
    </div>
    <?php
  }
}
