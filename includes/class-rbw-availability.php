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

  public static function get_available($check_in, $check_out, $only_group='', $only_room=''){
    $n = self::nights($check_in, $check_out);
    if ($n <= 0) return [];

    $rooms = get_option(RBW_Admin::OPT_ROOMS, []);
    if (empty($rooms) || !is_array($rooms)) return [];
    $room_filter_raw = sanitize_text_field((string)$only_room);
    $room_filter_key = strtolower($room_filter_raw);
    $room_filter_slug = sanitize_title($room_filter_raw);
    $normalize = function($value){
      $value = strtolower(trim((string)$value));
      if ($value === '') return '';
      return preg_replace('/[^a-z0-9]+/', '', $value);
    };
    $room_filter_norm = $normalize($room_filter_raw);

    $allowed_room_ids = null;
    $active_group_code = '';
    $group_filter_mode = 'none'; // none | group_ids | legacy
    $allowed_room_ids_raw = [];
    $allowed_room_ids_slug = [];
    $allowed_room_ids_norm = [];
    $room_group_codes = [];
    $group_code_aliases = [];
    if ($only_group) {
      $groups = get_option(RBW_Admin::OPT_GROUPS, []);
      $only_group = sanitize_title($only_group);
      foreach ($groups as $g) {
        if (!is_array($g)) continue;
        $gcode = sanitize_title($g['code'] ?? ($g['name'] ?? ''));
        if ($gcode === '') continue;
        $group_code_aliases[$gcode] = $gcode;
        $gname_alias = sanitize_title((string)($g['name'] ?? ''));
        if ($gname_alias !== '') $group_code_aliases[$gname_alias] = $gcode;
        $grooms = is_array($g['rooms'] ?? null) ? $g['rooms'] : [];
        foreach ($grooms as $rid) {
          $rid = sanitize_text_field((string)$rid);
          if ($rid === '') continue;
          $raw = strtolower(trim((string)$rid));
          $slug = sanitize_title((string)$rid);
          $norm = $normalize($rid);
          $keys = array_values(array_unique(array_filter([$raw, $slug, $norm], function($v){
            return $v !== '';
          })));
          foreach ($keys as $key) {
            if (!isset($room_group_codes[$key])) $room_group_codes[$key] = [];
            $room_group_codes[$key][$gcode] = true;
          }
        }
      }
      foreach ($groups as $g){
        $code = sanitize_title($g['code'] ?? ($g['name'] ?? ''));
        if ($code === $only_group) {
          $active_group_code = $code;
          $allowed_room_ids = is_array($g['rooms'] ?? null) ? $g['rooms'] : [];
          $allowed_room_ids = array_values(array_filter(array_map('strval', $allowed_room_ids), function($v){
            return $v !== '';
          }));
          foreach ($allowed_room_ids as $rid) {
            $raw = strtolower(trim((string)$rid));
            $slug = sanitize_title((string)$rid);
            $norm = $normalize($rid);
            if ($raw !== '') $allowed_room_ids_raw[] = $raw;
            if ($slug !== '') $allowed_room_ids_slug[] = $slug;
            if ($norm !== '') $allowed_room_ids_norm[] = $norm;
          }
          $allowed_room_ids_raw = array_values(array_unique($allowed_room_ids_raw));
          $allowed_room_ids_slug = array_values(array_unique($allowed_room_ids_slug));
          $allowed_room_ids_norm = array_values(array_unique($allowed_room_ids_norm));
          $group_filter_mode = 'group_ids';
          break;
        }
      }
      if ($group_filter_mode === 'none') {
        // Fallback for legacy per-room group assignments only when such records exist.
        foreach ($rooms as $room) {
          $legacy_group = sanitize_title((string)($room['group'] ?? ''));
          if ($legacy_group !== '' && $legacy_group === $only_group) {
            $group_filter_mode = 'legacy';
            break;
          }
        }
      }
      // Strict isolation: if a group filter was requested but no matching group exists,
      // return no rooms instead of leaking all rooms.
      if ($group_filter_mode === 'none') {
        return [];
      }
    }

    // Check existing bookings to calculate real availability
    $args = [
      'post_type' => 'rbw_booking',
      'post_status' => ['publish', 'pending'],
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
      $room_name = (string)($room['name'] ?? '');
      $effective_room_id = $r_id !== '' ? $r_id : ($r_code !== '' ? $r_code : sanitize_title((string)($room['name'] ?? '')));
      $r_group = (string)($room['group'] ?? '');
      $r_owner = sanitize_title((string)($room['group_owner'] ?? ''));
      if ($group_filter_mode === 'group_ids') {
        // Strong isolation: owned room can appear only in its owner group.
        $resolved_owner = $r_owner;
        $assigned_codes = [];
        $owner_lookup_keys = [];
        foreach ([$effective_room_id, $r_id, $r_code, $room_name] as $candidate) {
          $raw = strtolower(trim((string)$candidate));
          $slug = sanitize_title((string)$candidate);
          $norm = $normalize($candidate);
          if ($raw !== '') $owner_lookup_keys[$raw] = true;
          if ($slug !== '') $owner_lookup_keys[$slug] = true;
          if ($norm !== '') $owner_lookup_keys[$norm] = true;
        }
        foreach ($owner_lookup_keys as $lookup_key => $_flag_key) {
          if (!isset($room_group_codes[$lookup_key]) || !is_array($room_group_codes[$lookup_key])) continue;
          foreach ($room_group_codes[$lookup_key] as $code => $_flag) {
            $code = sanitize_title((string)$code);
            if ($code !== '') $assigned_codes[$code] = true;
          }
        }
        if ($resolved_owner === '' && count($assigned_codes) === 1) {
          foreach ($assigned_codes as $code => $_flag) {
            $resolved_owner = $code;
            break;
          }
        }
        // Legacy fallback: infer owner from room name prefix when possible.
        if ($resolved_owner === '' && !empty($assigned_codes)) {
          $room_name_slug = sanitize_title($room_name);
          $name_matches = [];
          foreach ($assigned_codes as $code => $_flag) {
            if ($room_name_slug === $code || strpos($room_name_slug, $code . '-') === 0) {
              $name_matches[$code] = true;
            }
            if (isset($group_code_aliases[$code])) {
              $canonical = sanitize_title((string)$group_code_aliases[$code]);
              if ($canonical !== '' && ($room_name_slug === $canonical || strpos($room_name_slug, $canonical . '-') === 0)) {
                $name_matches[$canonical] = true;
              }
            }
          }
          if (count($name_matches) === 1) {
            foreach ($name_matches as $code => $_flag) {
              $resolved_owner = $code;
              break;
            }
          }
        }
        // If still ambiguous across multiple groups, do not leak it into group shortcode results.
        if ($resolved_owner === '' && count($assigned_codes) > 1) continue;
        if ($active_group_code !== '' && $resolved_owner !== '' && $resolved_owner !== $active_group_code) continue;
        $room_raw_candidates = [];
        foreach ([$effective_room_id, $r_id, $r_code, $room_name] as $candidate) {
          $candidate = strtolower(trim((string)$candidate));
          if ($candidate !== '') $room_raw_candidates[] = $candidate;
        }
        $room_slug_candidates = [];
        foreach ([$effective_room_id, $r_id, $r_code, $room_name] as $candidate) {
          $candidate = sanitize_title((string)$candidate);
          if ($candidate !== '') $room_slug_candidates[] = $candidate;
        }
        $room_norm_candidates = [];
        foreach ([$effective_room_id, $r_id, $r_code, $room_name] as $candidate) {
          $candidate = $normalize($candidate);
          if ($candidate !== '') $room_norm_candidates[] = $candidate;
        }
        $group_match = false;
        foreach ($room_raw_candidates as $candidate) {
          if (in_array($candidate, $allowed_room_ids_raw, true)) { $group_match = true; break; }
        }
        if (!$group_match) {
          foreach ($room_slug_candidates as $candidate) {
            if (in_array($candidate, $allowed_room_ids_slug, true)) { $group_match = true; break; }
          }
        }
        if (!$group_match) {
          foreach ($room_norm_candidates as $candidate) {
            if (in_array($candidate, $allowed_room_ids_norm, true)) { $group_match = true; break; }
          }
        }
        if (!$group_match) continue;
      }
      if ($group_filter_mode === 'legacy' && sanitize_title($r_group) !== $only_group) continue;
      if ($room_filter_raw !== '') {
        $raw_candidates = [];
        foreach ([$effective_room_id, $r_id, $r_code, $room_name] as $candidate) {
          $candidate = strtolower(trim((string)$candidate));
          if ($candidate !== '') $raw_candidates[] = $candidate;
        }
        $slug_candidates = [];
        foreach ([$effective_room_id, $r_id, $r_code, $room_name] as $candidate) {
          $candidate = sanitize_title((string)$candidate);
          if ($candidate !== '') $slug_candidates[] = $candidate;
        }
        $norm_candidates = [];
        foreach ([$effective_room_id, $r_id, $r_code, $room_name] as $candidate) {
          $candidate = $normalize($candidate);
          if ($candidate !== '') $norm_candidates[] = $candidate;
        }
        $raw_match = in_array($room_filter_key, $raw_candidates, true);
        $slug_match = ($room_filter_slug !== '' && in_array($room_filter_slug, $slug_candidates, true));
        $norm_match = ($room_filter_norm !== '' && in_array($room_filter_norm, $norm_candidates, true));
        if (!$raw_match && !$slug_match && !$norm_match) continue;
      }

      // Legacy DB compatibility: treat missing/invalid stock as 1 room unit.
      if (!isset($room['stock']) || $room['stock'] === '' || !is_numeric($room['stock'])) {
        $stock = 1;
      } else {
        $stock = (int)$room['stock'];
      }
      if ($stock < 0) $stock = 0;
      $capacity = (int)($room['capacity'] ?? 1);
      if ($capacity <= 0) $capacity = 1;
      $booked = $booked_counts[$effective_room_id] ?? $booked_counts[$r_code] ?? $booked_counts[$r_id] ?? 0;
      $units_left = max(0, $stock - $booked);

      $ppn_single = (float)($room['price_single'] ?? 0);
      if ($ppn_single <= 0 && !empty($room['price'])) {
        $ppn_single = (float)$room['price'];
      }
      $ppn = $ppn_single;
      $ppn_couple = (float)($room['price_couple'] ?? 0);
      $ppn_group = (float)($room['price_group'] ?? 0);
      $deposit = (float)($room['deposit'] ?? 0);
      $guest_types = $room['guest_types'] ?? ['single','couple','group'];
      if (!is_array($guest_types)) {
        $guest_types = array_map('trim', explode(',', (string)$guest_types));
      }
      $guest_types = array_values(array_intersect(['single','couple','group'], array_map('strval', $guest_types)));
      if (empty($guest_types)) $guest_types = ['single','couple','group'];
      $booking_type = (($room['booking_type'] ?? 'per_person') === 'entire_room') ? 'entire_room' : 'per_person';
      $images = [];
      if (!empty($room['images']) && is_array($room['images'])) {
        foreach ($room['images'] as $img) {
          $u = esc_url_raw($img);
          if ($u !== '') $images[] = $u;
        }
      }
      if (empty($images) && !empty($room['image'])) {
        $single = esc_url_raw($room['image']);
        if ($single !== '') $images[] = $single;
      }
      $image = $images[0] ?? '';

      $total = $ppn * $n;
      $out[] = [
        'room_id' => $effective_room_id,
        'room_name' => $room_name,
        'image' => $image,
        'images' => $images,
        'units_left' => $units_left,
        'is_available' => $units_left > 0,
        'capacity' => $capacity,
        'booking_type' => $booking_type,
        'price_per_night' => $ppn,
        'price_single' => $ppn_single,
        'price_couple' => $ppn_couple,
        'price_group' => $ppn_group,
        'deposit' => $deposit,
        'guest_types' => $guest_types,
        'nights' => $n,
        'total' => $total,
        'balance' => max(0, $total - $deposit),
      ];
    }
    return $out;
  }
}
