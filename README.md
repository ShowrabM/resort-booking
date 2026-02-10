# Resort Booking Plugin

This plugin provides a simple room booking flow with WooCommerce payment.

## Changelog

### 1.0.5
- Added guest type controls (single/couple/group) and per-room stock + advance settings in admin.
- Group advance payments now apply when booking multiple rooms; removed 50% minimum advance rule.
- Improved availability handling (pending bookings counted) and group full-day calendar marking.
- Invoice PDF now supports Bangla using mPDF + SolaimanLipi; fixed Unicode booking data storage.
- UX refinements for room selection, multi-room flow, and clearer empty/full availability messages.

### 1.0.4
- Fixed settings data safety: room/group options are no longer cleared by unrelated saves.
- Added recovery tools for empty data states: recover from existing bookings and restore last backup.
- Improved multi-room data handling for admin/bookings and invoice sharing flows.
- Polished admin Rooms table layout/CSS to prevent collapsed columns and broken row alignment.

### 1.0.3
- Booking summary UI redesigned with sectioned cards and icons.
- Currency formatting added using WooCommerce settings.
- Summary includes room count, guest count, and nights.
- Responsive tweaks for summary layout on mobile.
- Improved room list ordering (available first) and visual state for booked rooms.
- Selection logic and payment display refinements for clarity.

### 1.0.2
- Booking UI: improved colors, selection highlighting, and payment summary.
- Full payment: strong 5% OFF visual highlight when selected.
- NID upload required with updated label text.
- Advance payment rules enforced (multi-room 50% minimum; single room 1000).
- Fixed multi-room selection logic and capacity handling.
- Calendar behavior improved (no auto-scroll on room selection).
- Admin: added CSV export for booking details.
- Order meta display cleaned and more user-friendly.

Version: 1.0.5

## Structure

- `rbw-resort-booking.php` - main plugin bootstrap
- `includes/` - PHP classes (admin, availability, ajax, WooCommerce)
- `assets/` - CSS/JS for the booking UI

## Notes

- Room pricing is per guest per night.
- Room capacity is configured per room in the admin.

## Release packaging

To keep the plugin zip small and avoid heavy server unzip load, use the release packer script:

```powershell
powershell -ExecutionPolicy Bypass -File tools\build-release.ps1
```

This excludes `.git` and the mPDF bundled fonts (`vendor/mpdf/mpdf/ttfonts`) from the release archive. The invoice PDF uses only `assets/fonts/SolaimanLipi.ttf`, so removing the bundled fonts is safe for this plugin.
