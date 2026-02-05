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

  public static function get_available($check_in, $check_out, $only_group=''){
    $n = self::nights($check_in, $check_out);
    if ($n <= 0) return [];

    $rooms = get_option(RBW_Admin::OPT_ROOMS, []);
    if (empty($rooms) || !is_array($rooms)) return [];

    $allowed_room_ids = null;
    $use_legacy_group = false;
    if ($only_group) {
      $groups = get_option(RBW_Admin::OPT_GROUPS, []);
      $only_group = sanitize_title($only_group);
      foreach ($groups as $g){
        $code = sanitize_title($g['code'] ?? ($g['name'] ?? ''));
        if ($code === $only_group) {
          $allowed_room_ids = is_array($g['rooms'] ?? null) ? $g['rooms'] : [];
          break;
        }
      }
      if ($allowed_room_ids === null) {
        // Fallback for legacy per-room group assignments
        $use_legacy_group = true;
      }
    }

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
      $rooms_json = get_post_meta($pid, '_rbw_rooms_json', true);
      if ($rooms_json) {
        $list = json_decode($rooms_json, true);
        if (is_array($list)) {
          foreach ($list as $r){
            $rid = (string)($r['room_id'] ?? '');
            if ($rid === '') continue;
            if (!isset($booked_counts[$rid])) $booked_counts[$rid] = 0;
            $booked_counts[$rid] += 1;
          }
          continue;
        }
      }

      $rid = (string)get_post_meta($pid, '_rbw_room_id', true);
      $rooms_needed = (int)get_post_meta($pid, '_rbw_rooms_needed', true);
      if ($rooms_needed <= 0) $rooms_needed = 1;
      if ($rid) {
        if (!isset($booked_counts[$rid])) $booked_counts[$rid] = 0;
        $booked_counts[$rid] += $rooms_needed;
      }
    }

    $out = [];
    foreach ($rooms as $room){
      $r_code = (string)($room['code'] ?? '');
      $r_id   = (string)($room['id'] ?? '');
      $r_group = (string)($room['group'] ?? '');
      if (is_array($allowed_room_ids) && !in_array($r_id, $allowed_room_ids, true)) continue;
      if ($use_legacy_group && sanitize_title($r_group) !== $only_group) continue;

      $stock = (int)($room['stock'] ?? 0);
      $capacity = (int)($room['capacity'] ?? 1);
      if ($capacity <= 0) $capacity = 1;
      $booked = $booked_counts[$r_code] ?? $booked_counts[$r_id] ?? 0;
      $units_left = max(0, $stock - $booked);

      $ppn = (float)($room['price'] ?? 0);
      $deposit = (float)($room['deposit'] ?? 0);
      $booking_type = (($room['booking_type'] ?? 'per_person') === 'entire_room') ? 'entire_room' : 'per_person';

      $total = $ppn * $n;
      $out[] = [
        'room_id' => $r_id ?: $r_code,
        'room_name' => $room['name'] ?? '',
        'units_left' => $units_left,
        'is_available' => $units_left > 0,
        'capacity' => $capacity,
        'booking_type' => $booking_type,
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
