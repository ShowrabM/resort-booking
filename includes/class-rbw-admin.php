<?php
if (!defined('ABSPATH')) exit;

class RBW_Admin {
  const OPT_DEPOSIT_ID = 'rbw_deposit_product_id';
  const OPT_ROOMS  = 'rbw_rooms';
  const OPT_GROUPS = 'rbw_groups';

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'add_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
    add_action('init', [__CLASS__, 'register_booking_cpt']);
    add_action('admin_post_rbw_cancel_booking', [__CLASS__, 'cancel_booking']);
    add_action('admin_post_rbw_export_bookings', [__CLASS__, 'export_bookings']);
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
      $stock = isset($item['stock']) ? intval($item['stock']) : 1;
      $capacity = isset($item['capacity']) ? intval($item['capacity']) : 0;
      $deposit = isset($item['deposit']) ? floatval($item['deposit']) : 0;
      $code_raw = isset($item['code']) ? $item['code'] : '';
      $booking_type = isset($item['booking_type']) ? sanitize_text_field($item['booking_type']) : 'per_person';
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
        'capacity' => max(1, $capacity),
        'deposit' => max(0, $deposit),
        'booking_type' => ($booking_type === 'entire_room') ? 'entire_room' : 'per_person',
      ];
    }
    return $clean;
  }

  public static function sanitize_groups($value){
    $clean = [];
    if (!is_array($value)) return [];

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
          if ($rid !== '') $rooms[] = $rid;
        }
      }

      $clean[] = [
        'name' => $name,
        'code' => $code,
        'rooms' => array_values(array_unique($rooms)),
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
      <div style="margin: 10px 0 14px;">
        <?php
          $export_url = wp_nonce_url(
            add_query_arg(['action' => 'rbw_export_bookings'], admin_url('admin-post.php')),
            'rbw_export_bookings'
          );
        ?>
        <a class="button button-primary" href="<?php echo esc_url($export_url); ?>">
          <?php esc_html_e('Download Bookings CSV', 'rbw'); ?>
        </a>
      </div>
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
    $groups = get_option(self::OPT_GROUPS, []);
    $rooms_by_id = [];
    if (is_array($rooms)) {
      foreach ($rooms as $r){
        $rid = (string)($r['id'] ?? '');
        if ($rid !== '') {
          $rooms_by_id[$rid] = [
            'name' => (string)($r['name'] ?? ''),
            'capacity' => (int)($r['capacity'] ?? 1),
          ];
        }
      }
    }
    $room_count = is_array($rooms) ? count($rooms) : 0;
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
                $gcode = esc_attr($group['code'] ?? '');
                $grooms = is_array($group['rooms'] ?? null) ? $group['rooms'] : [];
                ?>
                <div class="rbw-group-card" data-group-index="<?php echo (int)$gi; ?>">
                  <div class="rbw-group-head">
                    <input type="text" name="<?php echo esc_attr(self::OPT_GROUPS); ?>[<?php echo $gi; ?>][name]" value="<?php echo $gname; ?>" required class="rbw-group-name" placeholder="<?php esc_attr_e('Group name', 'rbw'); ?>">
                    <input type="number" min="1" step="1" class="rbw-group-qty" placeholder="<?php esc_attr_e('Qty', 'rbw'); ?>" title="<?php esc_attr_e('Room Quantity', 'rbw'); ?>">
                    <span class="rbw-group-count" data-group-count>0 rooms</span>
                    <button type="button" class="button-link-delete rbw-group-remove"><?php esc_html_e('Remove', 'rbw'); ?></button>
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
                          ?>
                          <label class="rbw-room-pill" data-room-id="<?php echo esc_attr($rid); ?>">
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

            <table class="widefat striped" id="rbw-rooms-table">
              <thead>
                <tr>
                  <th><?php esc_html_e('Room Name', 'rbw'); ?></th>
                  <th><?php esc_html_e('Price / night', 'rbw'); ?></th>
                  <th><?php esc_html_e('Capacity', 'rbw'); ?></th>
                  <th><?php esc_html_e('Booking Type', 'rbw'); ?></th>
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

      const createRoomPill = (groupIndex, roomId, roomName, capacity, checked = false) => {
        const label = document.createElement('label');
        label.className = 'rbw-room-pill';
        label.setAttribute('data-room-id', roomId);
        label.innerHTML = `
          <input type="checkbox" name="${gOpt}[${groupIndex}][rooms][]" value="${roomId}" ${checked ? 'checked' : ''}>
          <span>${roomName}</span>
          <small>Cap ${capacity}</small>
        `;
        return label;
      };

      const addRoomPillToAllGroups = (roomId, roomName, capacity) => {
        document.querySelectorAll('.rbw-group-card').forEach(card => {
          const list = card.querySelector(roomBadgesSelector);
          if (!list) return;
          if (list.querySelector(`[data-room-id="${roomId}"]`)) return;
          const groupIndex = card.getAttribute('data-group-index');
          if (groupIndex === null) return;
          list.appendChild(createRoomPill(groupIndex, roomId, roomName, capacity, false));
        });
      };

      const ensureRoomPillInGroup = (groupCard, roomId, roomName, capacity) => {
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
        list.appendChild(createRoomPill(groupIndex, roomId, roomName, capacity, true));
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

      const createRoomRow = (opts = {}) => {
        const i = index;
        table.insertAdjacentHTML('beforeend', rowTemplate(i));
        const row = table.querySelectorAll('tr')[table.querySelectorAll('tr').length - 1];
        const idInput = row.querySelector(`input[name="${opt}[${i}][id]"]`);
        const nameInput = row.querySelector(`input[name="${opt}[${i}][name]"]`);
        const priceInput = row.querySelector(`input[name="${opt}[${i}][price]"]`);
        const capInput = row.querySelector(`input[name="${opt}[${i}][capacity]"]`);
        const rid = opts.id || createTempRoomId(i);
        if (idInput) idInput.value = rid;
        if (nameInput) nameInput.value = opts.name || '';
        if (priceInput && opts.price !== undefined) priceInput.value = opts.price;
        if (capInput && opts.capacity !== undefined) capInput.value = opts.capacity;
        if (!nameInput?.value) autoRoomName(row, i);
        rooms[rid] = {
          name: nameInput?.value || rid,
          capacity: Number(capInput?.value || 1)
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
            </td>
            <td><input type="number" step="0.01" min="0" name="${opt}[${i}][price]" value="0"></td>
            <td><input type="number" step="1" min="1" name="${opt}[${i}][capacity]" value="1" required></td>
            <td>
              <label style="margin-right:8px;">
                <input type="radio" name="${opt}[${i}][booking_type]" value="per_person" checked> <?php esc_html_e('Per Person', 'rbw'); ?>
              </label>
              <label>
                <input type="radio" name="${opt}[${i}][booking_type]" value="entire_room"> <?php esc_html_e('Entire Room', 'rbw'); ?>
              </label>
            </td>
            <td><button type="button" class="button-link-delete rbw-remove"><?php esc_html_e('Remove', 'rbw'); ?></button></td>
          </tr>`;
      }

      if (addBtn) {
        addBtn.addEventListener('click', () => {
          const created = createRoomRow();
          addRoomPillToAllGroups(created.id, created.row.querySelector(`input[name="${opt}[${index-1}][name]"]`)?.value || created.id, created.row.querySelector(`input[name="${opt}[${index-1}][capacity]"]`)?.value || 1);
          updateRoomPillNames();
        });
      }

      table.addEventListener('click', (e) => {
        if(e.target.classList.contains('rbw-remove')){
          const row = e.target.closest('tr');
          if(row) row.remove();
        }
      });

      table.addEventListener('input', (e) => {
        const row = e.target.closest('tr');
        if (!row) return;
        const idInput = row.querySelector(`input[name$="[id]"]`);
        const nameInput = row.querySelector(`input[name$="[name]"]`);
        const capInput = row.querySelector(`input[name$="[capacity]"]`);
        const rid = idInput?.value;
        if (rid) {
          rooms[rid] = {
            name: nameInput?.value || rid,
            capacity: Number(capInput?.value || 1)
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

      const groupRowTemplate = (i) => {
        const roomsHtml = Object.keys(rooms).length
          ? Object.entries(rooms).map(([rid, meta]) => {
              const name = meta.name || rid;
              const cap = Number(meta.capacity || 1);
              return `
                <label class="rbw-room-pill" data-room-id="${rid}">
                  <input type="checkbox" name="${gOpt}[${i}][rooms][]" value="${rid}">
                  <span>${name}</span>
                  <small>Cap ${cap}</small>
                </label>
              `;
            }).join('')
          : `<em><?php esc_html_e('No rooms available yet.', 'rbw'); ?></em>`;
        return `
          <div class="rbw-group-card" data-group-index="${i}">
            <div class="rbw-group-head">
              <input type="text" name="${gOpt}[${i}][name]" value="" required class="rbw-group-name" placeholder="<?php echo esc_attr__('Group name', 'rbw'); ?>">
              <input type="number" min="1" step="1" class="rbw-group-qty" placeholder="<?php echo esc_attr__('Qty', 'rbw'); ?>" title="<?php echo esc_attr__('Room Quantity', 'rbw'); ?>">
              <span class="rbw-group-count" data-group-count>0 rooms</span>
              <button type="button" class="button-link-delete rbw-group-remove"><?php esc_html_e('Remove', 'rbw'); ?></button>
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

      if (addGroupBtn && groupsList) {
        addGroupBtn.addEventListener('click', () => {
          groupsList.insertAdjacentHTML('beforeend', groupRowTemplate(gIndex++));
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
          const code = slugify(e.target.value) || 'group';
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
              name: `${groupName} ${startIndex + k}`
            });
            addRoomPillToAllGroups(
              created.id,
              created.row.querySelector(`input[name="${opt}[${index-1}][name]"]`)?.value || created.id,
              created.row.querySelector(`input[name="${opt}[${index-1}][capacity]"]`)?.value || 1
            );
            ensureRoomPillInGroup(
              card,
              created.id,
              created.row.querySelector(`input[name="${opt}[${index-1}][name]"]`)?.value || created.id,
              created.row.querySelector(`input[name="${opt}[${index-1}][capacity]"]`)?.value || 1
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
      .rbw-group-shortcode input{
        width: 100%;
      }
      .rbw-group-rooms{ margin-top: 8px; }
      .rbw-group-rooms summary{
        color:#1e3a8a;
        font-weight:600;
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
      }
      .rbw-room-pill small{
        color:var(--rbw-muted);
        font-size: 11px;
      }
      .rbw-save-bar{
        position: sticky;
        bottom: 0;
        background: var(--rbw-surface);
        border-top: 1px solid var(--rbw-border);
        padding: 10px 0 0;
      }
      #rbw-rooms-table input[type="text"],
      #rbw-rooms-table input[type="number"]{
        width: 100%;
      }
      @media (max-width: 1100px){
        .rbw-admin-grid{ grid-template-columns: 1fr; }
      }
    </style>
    <?php
  }

  private static function render_room_row($room, $i){
    $id = esc_attr($room['id'] ?? '');
    $name = esc_attr($room['name'] ?? '');
    $price = esc_attr($room['price'] ?? 0);
    $capacity = esc_attr($room['capacity'] ?? 1);
    $booking_type = ($room['booking_type'] ?? 'per_person') === 'entire_room' ? 'entire_room' : 'per_person';
    ?>
    <tr>
      <td>
        <input type="hidden" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][id]" value="<?php echo $id; ?>">
        <input type="text" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][name]" value="<?php echo $name; ?>" required>
      </td>
      <td><input type="number" step="0.01" min="0" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][price]" value="<?php echo $price; ?>"></td>
      <td><input type="number" step="1" min="1" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][capacity]" value="<?php echo $capacity; ?>" required></td>
      <td>
        <label style="margin-right:8px;">
          <input type="radio" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][booking_type]" value="per_person" <?php checked($booking_type, 'per_person'); ?>> <?php esc_html_e('Per Person', 'rbw'); ?>
        </label>
        <label>
          <input type="radio" name="<?php echo esc_attr(self::OPT_ROOMS); ?>[<?php echo $i; ?>][booking_type]" value="entire_room" <?php checked($booking_type, 'entire_room'); ?>> <?php esc_html_e('Entire Room', 'rbw'); ?>
        </label>
      </td>
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

  public static function export_bookings(){
    if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'rbw'));
    check_admin_referer('rbw_export_bookings');

    $query = new WP_Query([
      'post_type' => 'rbw_booking',
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    $filename = 'rbw-bookings-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
      'Booking ID',
      'Created',
      'Room',
      'Guest Name',
      'Phone',
      'Guests',
      'Check In',
      'Check Out',
      'Total',
      'Deposit',
      'Balance',
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
      $nid = get_post_meta($post->ID, '_rbw_nid_url', true);
      $order_id = get_post_meta($post->ID, '_rbw_order_id', true);

      fputcsv($out, [
        $post->ID,
        get_the_time(get_option('date_format') . ' ' . get_option('time_format'), $post),
        $room ?: $post->post_title,
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

