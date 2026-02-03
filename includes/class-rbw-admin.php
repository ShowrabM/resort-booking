<?php
if (!defined('ABSPATH')) exit;

class RBW_Admin {
  const OPT_DEPOSIT_ID = 'rbw_deposit_product_id';
  const OPT_ROOMS  = 'rbw_rooms';

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'add_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
    add_action('init', [__CLASS__, 'register_booking_cpt']);
    add_action('admin_post_rbw_cancel_booking', [__CLASS__, 'cancel_booking']);
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
  }

  public static function sanitize_rooms($value){
    $clean = [];
    if (!is_array($value)) return [];

    $used_codes = [];
    $used_ids = [];

    foreach ($value as $idx => $item){
      $id = !empty($item['id']) ? sanitize_text_field($item['id']) : uniqid('room_', true);
      $name = sanitize_text_field($item['name'] ?? '');
      $price = isset($item['price']) ? floatval($item['price']) : 0;
      $stock = isset($item['stock']) ? intval($item['stock']) : 0;
      $deposit = isset($item['deposit']) ? floatval($item['deposit']) : 0;
      $code_raw = isset($item['code']) ? $item['code'] : '';

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

      $clean[] = [
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'price' => max(0, $price),
        'stock' => max(0, $stock),
        'deposit' => max(0, $deposit),
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
  }

  public static function render_bookings_page(){
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 25;
    $query = new WP_Query([
      'post_type' => 'rbw_booking',
      'post_status' => 'publish',
      'posts_per_page' => $per_page,
      'paged' => $paged,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);
    $total = $query->found_posts;
    $bookings = $query->posts;
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Bookings', 'rbw'); ?> <span class="subtitle">(<?php echo intval($total); ?>)</span></h1>
      <?php if (empty($bookings)): ?>
        <p><?php esc_html_e('No bookings found.', 'rbw'); ?></p>
      <?php else: ?>
        <div class="rbw-admin-table-wrap" style="overflow-x:auto;">
        <table class="widefat striped rbw-admin-table">
          <thead>
            <tr>
              <th><?php esc_html_e('Created', 'rbw'); ?></th>
              <th><?php esc_html_e('Room', 'rbw'); ?></th>
              <th><?php esc_html_e('Guest', 'rbw'); ?></th>
              <th><?php esc_html_e('Phone', 'rbw'); ?></th>
              <th><?php esc_html_e('Guests', 'rbw'); ?></th>
              <th><?php esc_html_e('Dates', 'rbw'); ?></th>
          <th><?php esc_html_e('Deposit', 'rbw'); ?></th>
              <th><?php esc_html_e('Balance', 'rbw'); ?></th>
              <th><?php esc_html_e('NID', 'rbw'); ?></th>
              <th><?php esc_html_e('Actions', 'rbw'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bookings as $post):
              $room = get_post_meta($post->ID, '_rbw_room_name', true);
              $check_in = get_post_meta($post->ID, '_rbw_check_in', true);
              $check_out = get_post_meta($post->ID, '_rbw_check_out', true);
              $deposit = get_post_meta($post->ID, '_rbw_deposit', true);
              $balance = get_post_meta($post->ID, '_rbw_balance', true);
              $guest = get_post_meta($post->ID, '_rbw_customer_name', true);
              $phone = get_post_meta($post->ID, '_rbw_customer_phone', true);
              $guests = get_post_meta($post->ID, '_rbw_guests', true);
              $nid = get_post_meta($post->ID, '_rbw_nid_url', true);
              $cancel_url = wp_nonce_url(
                add_query_arg([
                  'action' => 'rbw_cancel_booking',
                  'booking_id' => $post->ID,
                  'redirect_to' => urlencode( admin_url('admin.php?page=rbw-bookings') ),
                ], admin_url('admin-post.php')),
                'rbw_cancel_booking_'.$post->ID
              );
              ?>
              <tr>
                <td><?php echo esc_html(get_the_time(get_option('date_format') . ' ' . get_option('time_format'), $post)); ?></td>
                <td><?php echo esc_html($room ?: $post->post_title); ?></td>
                <td><?php echo esc_html($guest); ?></td>
                <td><a href="tel:<?php echo esc_attr($phone); ?>"><?php echo esc_html($phone); ?></a></td>
                <td><?php echo esc_html($guests); ?></td>
                <td><?php echo esc_html($check_in . ' -> ' . $check_out); ?></td>
                <td><?php echo wc_price((float)$deposit); ?></td>
                <td><?php echo wc_price((float)$balance); ?></td>
                <td>
                  <?php if ($nid): ?>
                    <a href="<?php echo esc_url($nid); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'rbw'); ?></a>
                  <?php else: ?>
                    &mdash;
                  <?php endif; ?>
                </td>
                <td>
                  <a class="button-link-delete" href="<?php echo esc_url($cancel_url); ?>" onclick="return confirm('<?php echo esc_js(__('Cancel this booking? This will free the room availability.', 'rbw')); ?>');">
                    <?php esc_html_e('Cancel', 'rbw'); ?>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <style>
          .rbw-admin-table{ min-width: 960px; }
          @media (max-width: 782px){
            .rbw-admin-table th, .rbw-admin-table td{ white-space: nowrap; }
          }
        </style>

        <?php
        $total_pages = max(1, ceil($total / $per_page));
        if ($total_pages > 1){
          $base = add_query_arg(['page'=>'rbw-bookings','paged'=>'%#%'], admin_url('admin.php'));
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
    $rooms = get_option(self::OPT_ROOMS, []);
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Resort Booking', 'rbw'); ?></h1>

      <form method="post" action="options.php">
        <?php settings_fields('rbw_settings'); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('WooCommerce Deposit Product ID', 'rbw'); ?></th>
            <td>
              <input type="number" name="<?php echo esc_attr(self::OPT_DEPOSIT_ID); ?>" value="<?php echo esc_attr( get_option(self::OPT_DEPOSIT_ID, RBW_DEPOSIT_PRODUCT_ID) ); ?>" min="1" class="small-text">
              <p class="description"><?php esc_html_e('This product will be added to cart for the deposit payment.', 'rbw'); ?></p>
            </td>
          </tr>
        </table>

        <h2><?php esc_html_e('Rooms', 'rbw'); ?></h2>
        <p><?php esc_html_e('Add or edit rooms below. No WooCommerce needed.', 'rbw'); ?></p>

        <table class="widefat striped" id="rbw-rooms-table">
          <thead>
            <tr>
              <th><?php esc_html_e('Room Name', 'rbw'); ?></th>
              <th><?php esc_html_e('Price / night', 'rbw'); ?></th>
              <th><?php esc_html_e('Stock', 'rbw'); ?></th>
            <th><?php esc_html_e('Deposit (optional)', 'rbw'); ?></th>
            <th><?php esc_html_e('Code', 'rbw'); ?></th>
            <th><?php esc_html_e('Shortcode', 'rbw'); ?></th>
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
        <p><button type="button" class="button" id="rbw-add-row"><?php esc_html_e('Add Room', 'rbw'); ?></button></p>
        <?php submit_button(__('Save Settings', 'rbw')); ?>
      </form>
    </div>
    <script>
    (function(){
      const table = document.querySelector('#rbw-rooms-table tbody');
      const addBtn = document.getElementById('rbw-add-row');
      let index = <?php echo (int)$i; ?>;
      const opt = '<?php echo esc_js(self::OPT_ROOMS); ?>';

      function rowTemplate(i){
        return `
          <tr>
            <td>
              <input type="hidden" name="${opt}[${i}][id]" value="">
              <input type="text" name="${opt}[${i}][name]" required>
            </td>
            <td><input type="number" step="0.01" min="0" name="${opt}[${i}][price]" value="0"></td>
            <td><input type="number" step="1" min="0" name="${opt}[${i}][stock]" value="0" required></td>
            <td><input type="number" step="0.01" min="0" name="${opt}[${i}][deposit]" value="0"></td>
            <td><input type="text" name="${opt}[${i}][code]" value="" placeholder="room1"></td>
            <td><code>[resort_booking room="room1"]</code></td>
            <td><button type="button" class="button-link-delete rbw-remove"><?php esc_html_e('Remove', 'rbw'); ?></button></td>
          </tr>`;
      }

      addBtn.addEventListener('click', () => {
        table.insertAdjacentHTML('beforeend', rowTemplate(index++));
      });

      table.addEventListener('click', (e) => {
        if(e.target.classList.contains('rbw-remove')){
          const row = e.target.closest('tr');
          if(row) row.remove();
        }
      });
    })();
    </script>
    <?php
  }

  private static function render_room_row($room, $i){
    $id = esc_attr($room['id'] ?? '');
    $name = esc_attr($room['name'] ?? '');
    $price = esc_attr($room['price'] ?? 0);
    $stock = esc_attr($room['stock'] ?? 0);
    $deposit = esc_attr($room['deposit'] ?? 0);
    $code = esc_attr($room['code'] ?? '');
    ?>
    <tr>
      <td>
        <input type="hidden" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][id]" value="<?php echo $id; ?>">
        <input type="text" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][name]" value="<?php echo $name; ?>" required>
      </td>
      <td><input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][price]" value="<?php echo $price; ?>"></td>
      <td><input type="number" step="1" min="0" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][stock]" value="<?php echo $stock; ?>" required></td>
      <td><input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][deposit]" value="<?php echo $deposit; ?>"></td>
      <td><input type="text" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][code]" value="<?php echo $code; ?>" placeholder="room<?php echo $i+1; ?>"></td>
      <td><code>[resort_booking room="<?php echo ($code?: 'room'.($i+1)); ?>"]</code></td>
      <td><button type="button" class="button-link-delete rbw-remove"><?php esc_html_e('Remove', 'rbw'); ?></button></td>
    </tr>
    <?php
  }

  public static function cancel_booking(){
    if (empty($_GET['booking_id'])) wp_die(__('Missing booking ID', 'rbw'));

    $booking_id = absint($_GET['booking_id']);
    if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'rbw'));

    check_admin_referer('rbw_cancel_booking_'.$booking_id);

    // Move to trash so availability frees up
    wp_trash_post($booking_id);

    $redirect = !empty($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : admin_url('admin.php?page=rbw-bookings');
    wp_safe_redirect($redirect);
    exit;
  }
}

