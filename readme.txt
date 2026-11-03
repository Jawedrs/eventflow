=== EventFlow ===
Contributors: eventflow
Tags: events, calendar, tickets, bookings, event-management
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fast, accessible event calendar with free ticket reservations and attendee management.

== Description ==

EventFlow helps you publish events, list them on your site, accept free ticket reservations, and manage attendee check-in.

= Features =

* Create events with a schedule, location, featured image, and event category.
* Publish a searchable, date-ordered event archive at /events/.
* Add the EventFlow events block to display events on any page.
* Use [eventflow_events count="6" category="workshops"] to display a list in a classic editor or widget.
* Set ticket capacity and accept free ticket reservations.
* Email booking references and let attendees find or cancel bookings with [eventflow_booking_lookup].
* Manage bookings, check attendees in, and export event-scoped attendee CSV files from Events → Bookings.
* Give an Event Organizer account access to create and manage their own events.
* Add [eventflow_organizer_dashboard] to a page for a signed-in organizer event dashboard.
* Export and erase booking personal data through WordPress privacy tools.

Paid tickets are optional and use Stripe Checkout. The site administrator must configure a Stripe secret key and webhook signing secret before paid bookings are available. Card details are entered on Stripe's hosted checkout and are not stored by EventFlow. Event owners and administrators can request full refunds from the Bookings screen; partial refunds are not supported.

EventFlow stores event content and booking records in your WordPress database. The core plugin does not send data to an EventFlow service or require a third-party account.

Paid bookings are optional. When a site administrator configures Stripe, EventFlow sends the attendee email and transaction details to Stripe to create hosted checkout, verify payments, and (when an event owner explicitly requests it) process full refunds. Card details are entered on Stripe-hosted checkout and are not stored by EventFlow. See the [Stripe Privacy Policy](https://stripe.com/privacy) and [Stripe Terms](https://stripe.com/legal).

= Configure Stripe =

1. Open Events → Settings and choose a currency (AUD, CAD, EUR, GBP, INR, or USD).
2. Enter the Stripe secret key. Test keys can be used for development; use a live key only when ready to accept real payments.
3. In Stripe, create a webhook endpoint using the URL shown on the settings page. Subscribe to checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, and refund.updated. Save its signing secret in EventFlow.
4. Set ticket capacity and a price on the event. A price of 0 creates a free ticket.

Stripe is contacted when a visitor starts a paid booking and when an authorized event owner or administrator explicitly requests a full refund. The plugin uses the WordPress HTTP API to create a hosted Checkout Session or refund and validates Stripe webhook signatures before it confirms or expires a booking or records the final refund status. See the [Stripe Checkout documentation](https://docs.stripe.com/checkout), [Stripe Refunds documentation](https://docs.stripe.com/refunds), and [Stripe Webhooks documentation](https://docs.stripe.com/webhooks).

== Installation ==

1. Upload the eventflow folder to /wp-content/plugins/ or install the plugin ZIP through the WordPress Plugins screen.
2. Activate EventFlow in the Plugins screen.
3. Open Events → Add event, add the event details, and publish.
4. Visit /events/ to see the event archive. If the URL returns a 404, open Settings → Permalinks and save once to refresh rewrite rules.
5. Add the EventFlow events block to a page to show upcoming events elsewhere.

== Frequently Asked Questions ==

= How do I let visitors reserve a ticket? =

Set Free ticket capacity in the event's When and where panel. A positive capacity opens the reservation form on the published event page.

= How do visitors find a booking? =

Create a page and add the [eventflow_booking_lookup] shortcode. Visitors need their booking reference and the email used for the reservation.

= Can an organizer manage their events? =

Assign the Event Organizer role to a trusted WordPress account. Organizers can create and manage their own events and see bookings for those events.

Add the [eventflow_organizer_dashboard] shortcode to a page to let signed-in organizers create events and open their event editor.

= What happens to event and booking data when I deactivate or uninstall the plugin? =

Deactivation and uninstall leave your content and booking records in place. This protects event history and attendee records from accidental removal. You can erase attendee personal data using the WordPress privacy tools.

= How do I build the editor assets? =

The plugin includes readable JavaScript source in assets/src and the compiled assets required to run the block and admin overview. To rebuild them, install Node.js and npm, run npm install, then npm run build in the plugin folder. Use scripts/package-plugin.sh to create an installable ZIP without local development dependencies.

== Privacy ==

EventFlow stores attendee name, email address, booking reference, event ID, booking status, payment/refund references, and booking time. These data are used to administer attendance, send booking confirmations, support booking lookup/cancellation, process refunds, and operate check-in. Booking data remains on the site and is visible to site administrators and organizers who own the associated event. EventFlow registers WordPress personal data export and erasure handlers.

When an administrator enables Stripe, attendee email and payment transaction details are sent to Stripe for checkout and payment verification. EventFlow does not store card details.

== Changelog ==

= 0.1.0 =
* Initial public preview: event publishing, public discovery, free and paid bookings, full Stripe refunds, attendee operations, event list block, organizer permissions, and privacy tools.
