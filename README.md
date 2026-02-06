# Resort Booking Plugin

This plugin provides a simple room booking flow with WooCommerce payment.

## Changelog

### 1.0.2
- Booking UI: improved colors, selection highlighting, and payment summary.
- Full payment: strong 5% OFF visual highlight when selected.
- NID upload required with updated label text.
- Advance payment rules enforced (multi-room 50% minimum; single room 1000).
- Fixed multi-room selection logic and capacity handling.
- Calendar behavior improved (no auto-scroll on room selection).
- Admin: added CSV export for booking details.
- Order meta display cleaned and more user-friendly.

Version: 1.0.2

## Structure

- `rbw-resort-booking.php` - main plugin bootstrap
- `includes/` - PHP classes (admin, availability, ajax, WooCommerce)
- `assets/` - CSS/JS for the booking UI

## Notes

- Room pricing is per guest per night.
- Room capacity is configured per room in the admin.
