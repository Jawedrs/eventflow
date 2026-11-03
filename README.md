# EventFlow

An original, WordPress-native event calendar and ticketing plugin.

## Development

Requires PHP 8.1+, WordPress 6.5+, Node 20+, and npm.

```sh
npm install
npm run build
```

Activate `eventflow.php` in a local WordPress installation. Create an event in
**Events**, add its schedule and location, then publish it. The first public
event-detail page is server rendered for a fast initial load.

Use `[eventflow_booking_lookup]` on a normal WordPress page to let attendees
find and cancel their own free-ticket reservation. Administrators can check in
attendees from **Events → Bookings**.

The WordPress.org listing draft is in [readme.txt](readme.txt). This is an early
preview; paid payment gateways are not implemented yet.
