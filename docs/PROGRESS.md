# Progress

## Milestone 0 — Agent charter and architecture

Status: complete

- Established a clean-room development contract in `AGENTS.md`.
- Defined WordPress-native architecture and MVP delivery sequence.
- No EventPrime code, assets, strings, or schemas have been copied.

## Next milestone

## Milestone 1 — Event publishing vertical slice

Status: complete

- Added an installable plugin bootstrap with event, venue, organizer, and
  category content types.
- Added nonce- and capability-protected event schedule/location fields.
- Added an original server-rendered public event template and responsive public
  style system.
- Added a public read-only REST endpoint and React build toolchain foundation.

Verification still required in a running WordPress instance; CLI PHP lint is
the next local check.

## Next milestone

## Milestone 2 — Event discovery archive

Status: complete

- Added a server-rendered event archive with search and category filters.
- Added date-ordered event rows, accessible empty state, and pagination.
- Added a new public archive visual treatment responsive down to mobile.

## Next milestone

## Milestone 3 — Free-ticket reservation flow

Status: complete

- Added a dedicated booking table created on activation.
- Added capacity-backed, atomic ticket claims to prevent overbooking.
- Added a public, nonce-protected reservation form that works without
  JavaScript and records name, email, confirmation code, and status.
- Added confirmation emails and a confirmation state on the event page.

## Next milestone

## Milestone 4 — Attendee operations

Status: complete

- Added the `[eventflow_booking_lookup]` shortcode for secure attendee lookup
  and cancellation using booking reference plus email address.
- Added cancellation inventory restoration.
- Added an Events → Bookings administration screen and nonce-protected
  attendee check-in action.

## Next milestone

## Milestone 5 — Editor blocks, organizer scope, and WordPress privacy

Status: complete

- Added a dynamic Gutenberg event list block and classic-editor shortcode.
- Added the React block editor controls and the React-powered EventFlow overview.
- Added an Event Organizer role with scoped event, venue, and organizer
  capabilities; booking records are scoped to their event ownership.
- Signed attendee cancellation forms with a site-secret HMAC so an exposed
  public nonce and guessed email cannot authorize cancellation.
- Count checked-in attendees against ticket capacity and preserve inventory
  when event capacity is edited.
- Added WordPress personal data export, erasure, and privacy-policy content.
- Added timezone-aware event date parsing and schema.org Event JSON-LD.
- Added a WordPress.org readme draft.

## Next milestone

## Milestone 6 — Event setup and booking safeguards

Status: complete

- Linked venue and organizer records to events and display them on event pages.
- Added event organizer permissions for managing their own venues and profiles.
- Validated event-local start/end times and displayed actionable editor notices.
- Added WordPress timezone-aware date parsing and display throughout event
  details and discovery lists.
- Prevented duplicate active reservations for an event and let a cancelled
  attendee reserve again.
- Serialized booking, cancellation, and capacity edits per event to avoid
  inventory races.
- Added a dedicated WordPress.org readme and privacy export/erase support.

## Next milestone

## Milestone 7 — Stripe paid ticket checkout

Status: complete

- Added event ticket price and site currency settings (AUD, CAD, EUR, GBP, INR,
  USD) with a restricted server-side Stripe API configuration.
- Added hosted Checkout Session creation using the WordPress HTTP API and
  short-lived capacity reservation.
- Added signed Stripe webhook verification with timestamp tolerance and
  idempotent booking confirmation, expiration, and asynchronous-failure paths.
- Added payment amount/currency checks against the booking record before
  confirming paid attendance.
- Documented Stripe data sharing, service terms, privacy, webhook setup, and
  the optional setup behavior in the WordPress.org readme.
- Disabled self-service cancellation for paid bookings until refund handling
  is implemented.

## Milestone 8 — Organizer front-end

Status: complete

- Added `[eventflow_organizer_dashboard]` for signed-in organizers to create
  events, view their own events, and open the native event editor.
- Added nonce, capability, and post-author checks to the creation workflow.
- Kept organizer pages server rendered and free of JavaScript dependencies.

## Milestone 9 — Release packaging and static verification

Status: complete, pending runtime verification

- Added a repeatable ZIP packaging script that ships runtime PHP, built assets,
  and readable React source while leaving out local dependencies and agent/dev
  documentation.
- Confirmed the production ZIP contains the plugin folder and excludes
  `node_modules`, `dist`, and development-only files.
- PHP syntax checks passed for all plugin PHP files; JavaScript lint and the
  production asset build passed.
- Runtime activation, database migration, browser flows, and live/test Stripe
  webhook delivery remain unverified because this workspace has no WordPress
  installation or WP-CLI.

## Milestone 10 — Full paid-ticket refunds

Status: implemented, pending Stripe/WordPress runtime verification

- Persisted PaymentIntent and refund references through booking migrations and
  included them in attendee privacy exports.
- Added an owner/admin-only full-refund action with nonce and ownership checks,
  browser confirmation, a stable Stripe idempotency key, and a retry path for
  accepted-but-not-yet-recorded refunds.
- Released inventory only after Stripe accepts a refund request and handled
  `refund.updated` events for final succeeded/failed states.
- Retained bookings with in-flight refunds during privacy erasure and notified
  attendees when a full refund request is accepted.
- Verified PHP syntax, JavaScript lint, production build, and the install ZIP.
  Stripe test-mode payment/refund flows remain unverified without a WordPress
  runtime and Stripe test credentials.

## Milestone 11 — Attendee CSV export

Status: implemented, pending WordPress runtime verification

- Added a nonce-protected attendee export for administrators and organizers.
- Organizer exports are scoped to events owned by the current user; exports
  stream in batches to avoid loading every booking into memory.
- Escaped spreadsheet formula-leading cell values and documented the export.
- Re-ran PHP syntax checks, JavaScript lint/build, and ZIP integrity/package
  exclusions after the refund and export changes; all passed.

## Next milestone

Verify activation, database upgrades, attendee and organizer flows, and Stripe
test-mode payment/refund behavior in a disposable WordPress install; then fill
out broader ticket options and release readiness checks.

## Milestone 12 — Local WordPress integration smoke check

Status: activation and archive verified; booking lifecycle still pending

- Moved the development project into the Local WordPress site's plugin folder
  at `wp-content/plugins/eventflow`.
- Activated EventFlow successfully on WordPress 7.1.4. The version-5 booking
  schema, including payment and refund reference columns, was created.
- Confirmed the event post type and booking lookup shortcode register, and the
  public `/events/` archive returns HTTP 200 with the themed empty state.
- Single-event rendering with a real event record, reservation, organizer
  permissions, and Stripe test-mode payment/refund flows still need exercise.

## Next milestone

Validate the single-event page and free booking on this local install, then
exercise organizer permissions and Stripe test mode before release work.
