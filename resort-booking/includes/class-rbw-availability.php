<?php
if (!defined('ABSPATH')) exit;

class RBW_Availability {
  public static function nights($in, $out){
    try {
      $d1 = new DateTime($in, new DateTimeZone('UTC'));
      $d2 = new DateTime($out, new DateTimeZone('UTC'));
      $d1->setTime(0,0,0);
      $d2->setTime(0,0,0);
      $diff = $d2->getTimestamp() - $d1->getTimestamp();
      return max(0, (int)round($diff / 86400));
    } catch (Exception $e){
      return 0;
    }
  }

  public static function get_available($check_in, $check_out, $only_room_id=''){
    $n = self::nights($check_in, $check_out);
    if ($n <= 0) return [];

    $rooms = get_option(RBW_Admin::OPT_ROOMS, []);
    if (empty($rooms) || !is_array($rooms)) return [];

    // Check existing bookings to calculate real availability
    $args = [
      'post_type' => 'rbw_booking',
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'meta_query' => [
        'relation' => 'AND',
        ['key' => '_rbw_check_in', 'value' => $check_out, 'compare' => '<'],
        ['key' => '_rbw_check_out', 'value' => $check_in, 'compare' => '>']
      ]
    ];
    $booked_ids = get_posts($args);
    $booked_counts = [];
    foreach ($booked_ids as $pid){
      $rid = (string)get_post_meta($pid, '_rbw_room_id', true);
      if ($rid) {
        if (!isset($booked_counts[$rid])) $booked_counts[$rid] = 0;
        $booked_counts[$rid]++;
      }
    }

    $out = [];
    foreach ($rooms as $room){
      $r_code = (string)($room['code'] ?? '');
      $r_id   = (string)($room['id'] ?? '');
      // allow filtering by code or internal id
      if ($only_room_id && $r_code !== (string)$only_room_id && $r_id !== (string)$only_room_id) continue;

      $stock = (int)($room['stock'] ?? 0);
      $booked = $booked_counts[$r_code] ?? $booked_counts[$r_id] ?? 0;
      $units_left = max(0, $stock - $booked);

      if ($units_left <= 0) continue;

      $ppn = (float)($room['price'] ?? 0);
      $deposit = (float)($room['deposit'] ?? 0);

      $total = $ppn * $n;
      $out[] = [
        'room_id' => $r_id ?: $r_code,
        'room_name' => $room['name'] ?? '',
        'units_left' => $units_left,
        'price_per_night' => $ppn,
        'deposit' => $deposit,
        'nights' => $n,
        'total' => $total,
        'balance' => max(0, $total - $deposit),
      ];
    }
    return $out;
  }
}
