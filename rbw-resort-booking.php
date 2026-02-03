<?php
/*
  Plugin Name: Resort Booking
  Plugin URI: https://onvirtualworld.com
  author: Showrab Mojumdar
  author URI: https://github.com/ShowrabM/resort-booking
  Description: A plugin to manage resort bookings.
  Version: 1.0.0
    License: GPL2
    */
   
if (!defined('ABSPATH')) exit;

define('RBW_VERSION', '1.0.0');
define('RBW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RBW_PLUGIN_URL', plugin_dir_url(__FILE__));

// WooCommerce deposit product to charge during checkout
if (!defined('RBW_DEPOSIT_PRODUCT_ID')) define('RBW_DEPOSIT_PRODUCT_ID', 123);

// Helper: deposit product ID (option overrides constant)
if (!function_exists('rbw_get_deposit_product_id')) {
  function rbw_get_deposit_product_id(){
    $opt = (int) get_option('rbw_deposit_product_id', 0);
    return $opt > 0 ? $opt : (int) RBW_DEPOSIT_PRODUCT_ID;
  }
}

/**
 * Ensure a hidden virtual product exists for deposits.
 * Returns product ID.
 */
if (!function_exists('rbw_ensure_virtual_deposit_product')) {
  function rbw_ensure_virtual_deposit_product(){
    $pid = (int) get_option('rbw_virtual_deposit_pid', 0);
    if ($pid && get_post_status($pid)) {
      $existing = wc_get_product($pid);
      if ($existing && $existing->get_status() !== 'publish') {
        $existing->set_status('publish');
        $existing->set_catalog_visibility('hidden');
        $existing->save();
      }
      return $pid;
    }

    if (!class_exists('WC_Product_Simple')) return 0;

    $p = new WC_Product_Simple();
    $p->set_name('RBW Booking Deposit');
    // Must be publishable to allow add_to_cart for guests; keep hidden from catalog
    $p->set_status('publish');
    $p->set_catalog_visibility('hidden');
    $p->set_virtual(true);
    $p->set_sold_individually(true);
    $p->set_price(0);
    $p->set_regular_price(0);
    $pid = $p->save();
    if ($pid) update_option('rbw_virtual_deposit_pid', $pid);
    return (int)$pid;
  }
}

// Load files
require_once RBW_PLUGIN_DIR . 'includes/class-rbw-admin.php';
require_once RBW_PLUGIN_DIR . 'includes/class-rbw-availability.php';
require_once RBW_PLUGIN_DIR . 'includes/class-rbw-ajax.php';
require_once RBW_PLUGIN_DIR . 'includes/class-rbw-woocommerce.php';

// Boot
add_action('plugins_loaded', function(){
  RBW_Admin::init();
  RBW_Ajax::init();
  if (class_exists('WooCommerce')) {
    RBW_WooCommerce::init();
  }
});

// Cancel pending bookings after timeout (10 minutes)
add_action('rbw_maybe_cancel_booking', function($booking_id){
  $booking_id = absint($booking_id);
  if (!$booking_id) return;
  $post = get_post($booking_id);
  if (!$post || $post->post_type !== 'rbw_booking') return;
  if ($post->post_status !== 'pending') return;
  wp_trash_post($booking_id);
});


// Primary shortcode: [resort_booking room="ROOM_ID"]
add_shortcode('resort_booking', function($atts = []){
  $atts = shortcode_atts([
    'room' => '',
  ], $atts, 'resort_booking');
  $room_id = trim($atts['room']);

  wp_enqueue_style('rbw-booking');
  wp_enqueue_script('rbw-booking');

$id = uniqid('rbw_', true);

ob_start(); ?>
  <div class="rbw-wrap" data-rbw-widget="<?php echo esc_attr($id); ?>" <?php if($room_id!=='') echo 'data-rbw-room="'.esc_attr($room_id).'"'; ?>>
    <button type="button" class="rbw-btn" data-rbw-open>Book Now</button>

    <div class="rbw-backdrop" data-rbw-backdrop aria-hidden="true">
      <div class="rbw-modal" role="dialog" aria-modal="true">
        <div class="rbw-head">
          <div class="rbw-title">
            <h3>Select Dates</h3>
            <p>View available rooms by date.</p>
          </div>
          <button type="button" class="rbw-close" data-rbw-close>Close</button>
        </div>

        <div class="rbw-body">
          <div class="rbw-grid">
            <div class="rbw-field">
              <label>Check-in</label>
              <input data-rbw-in type="text" inputmode="none" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
            </div>
            <div class="rbw-field">
              <label>Check-out</label>
              <input data-rbw-out type="text" inputmode="none" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
            </div>
          </div>
          <div class="rbw-calendar" data-rbw-calendar></div>

          <div class="rbw-actions">
            <button type="button" class="rbw-search" data-rbw-search>View Available Rooms</button>
          </div>


          <div class="rbw-alert" data-rbw-alert></div>
          <div class="rbw-list" data-rbw-list></div>
        </div>
      </div>
    </div>
  </div>
<?php
return ob_get_clean();
});

// Enqueue assets
add_action('wp_enqueue_scripts', function(){
  wp_register_style(
    'rbw-booking',
    RBW_PLUGIN_URL . 'assets/css/rbw-booking.css',
    [],
    RBW_VERSION
  );

  wp_register_script(
    'rbw-booking',
    RBW_PLUGIN_URL . 'assets/js/rbw-booking.js',
    [],
    RBW_VERSION,
    true
  );

  wp_localize_script('rbw-booking', 'RBW', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('rbw_nonce'),
  ]);
});
