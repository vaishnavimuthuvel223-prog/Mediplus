# MediPlus — Token Booking, Travel Reminders & Walk-in Tokens

## What's new (all additive — nothing existing was changed in behavior)

1. **Reach-hospital-by scheduling** for every booked patient: patients-ahead,
   estimated call time, "reach by" time, and "leave home by" time — computed
   from live queue position + real road travel time.
2. **Automatic reminders** ("3/2/1 tokens ahead — get ready", "it's your
   turn", and a one-time "leave now to reach by HH:MM" travel nudge) sent to
   each patient's Notifications automatically as the queue moves. No cron
   job needed — it fires every time the queue recalculates.
3. **Doctor consultation timer**: the moment a doctor opens the next token,
   `consultation_started_at` is stamped and shown on their dashboard; it
   clears/moves on when they hit Complete.
4. **Walk-in tokens added by admin**: `Admin → Add Walk-in Token` lets staff
   register a patient who showed up without booking online. They're dropped
   straight into today's live queue, in the same priority order as online
   bookings — one unified sequential queue for the doctor either way.
5. **Admin → Hospital Settings**: edit the hospital's fixed address/
   coordinates, reminder thresholds, check-in buffer, avg consultation time,
   and fallback travel speed — no code changes needed to retune any of this.

## Hospital location

Pre-filled from the address you gave me:
**Government District Head Quarters Hospital, Kangayam, Tamil Nadu**
(lat `11.0056419`, lng `77.5602020`). Change it anytime under
Admin → Hospital Settings — either paste a new address and click "Look up
latitude / longitude", or enter coordinates directly.

## How travel time is calculated

- Patient's saved address is geocoded once via OpenStreetMap Nominatim and
  cached on their patient record (`patients.latitude/longitude`). No API key
  needed.
- Real driving time comes from the public OSRM router (also no API key).
- If either service is unreachable (no internet, rate-limited, etc.), the
  system automatically falls back to straight-line distance ×
  `avg_travel_speed_kmph` from settings, so the page never breaks — it just
  shows an estimate instead of a routed time.
- **Note:** both Nominatim and the public OSRM demo server are free
  community services with fair-use rate limits. Fine for development/small
  clinics; for a busier hospital in production, consider self-hosting OSRM
  or switching to a paid provider (Google Distance Matrix, Mapbox, etc.) —
  `includes/travel_functions.php` is the only file you'd need to touch.

## Deploying this

1. **Back up your database first.**
2. Run the migration once against your existing database:
   ```
   mysql -u root mediplus < database/migration_token_travel.sql
   ```
   (It's pure `ADD COLUMN` / `CREATE TABLE IF NOT EXISTS` / `INSERT ... ON
   DUPLICATE KEY UPDATE` — safe to run on a live DB, nothing existing is
   dropped or altered destructively.)
3. Deploy the updated files as usual. `database/schema.sql` was also
   updated so a **fresh** install already includes everything above.
4. Log in as admin → check **Hospital Settings** looks right → try
   **Add Walk-in Token** once to confirm it appears in Live Queue.
5. Log in as a patient with a saved address → Dashboard should show the
   travel estimate + reach-by schedule.

## Files touched

**New:**
- `includes/travel_functions.php`
- `includes/notification_engine.php`
- `admin/settings.php`
- `admin/add_walk_in.php`
- `admin/get_doctors.php`
- `database/migration_token_travel.sql`

**Modified:**
- `includes/priority_engine.php` — hooks in reminders, adds
  `get_patient_queue_summary()`
- `includes/appointment_functions.php` — `booking_type` support,
  consultation timer, `add_walk_in_token()`
- `patient/dashboard.php`, `patient/queue_status.php` — server-side travel
  card + proximity banner (replaces the old, unreliable client-side
  geocoding)
- `assets/js/queue.js` — polls and refreshes the new fields
- `doctor/dashboard.php` — consultation elapsed timer, booking-type column
- `queue/queue_data.php`, `queue/live_queue.php` — booking-type column
- `admin/dashboard.php`, `includes/header.php` — links to the new pages
- `database/schema.sql` — new columns/table/settings for fresh installs

`assets/js/location.js` is no longer used by the dashboard (its old
straight-line client-side calculation was unreliable) but was left in place
untouched rather than deleted, in case anything else references it later.

## Not verified

No PHP interpreter was available in the environment I built this in, so I
couldn't run `php -l` or an actual server. I did check every file for
balanced braces/parens/tags and confirmed all function calls match their
definitions with no circular `require_once` chains — but please smoke-test
on a real PHP/MySQL setup before relying on this in production.
