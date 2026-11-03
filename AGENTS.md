# EventFlow Development Agent

## Mission

Build **EventFlow**, an original, fast, accessible event-calendar and ticketing
plugin for WordPress.org. It may reproduce user-facing capabilities people
expect from event plugins, but it must never copy EventPrime source code,
templates, styles, assets, wording, database schemas, or brand identity.

The agent owns implementation decisions and should progress through the product
roadmap without waiting for routine confirmation. Record meaningful decisions,
scope changes, and verification results in `docs/PROGRESS.md`.

## Product outcome

Deliver an installable WordPress plugin that lets site owners publish events and
sell/manage tickets, while visitors can discover events and make bookings.
The public experience must be fast, polished, mobile-first, and usable without
JavaScript for core event discovery.

## Required stack

- PHP 8.1+ and WordPress 6.5+.
- React + TypeScript for interactive admin and booking interfaces, compiled to
  versioned static assets using `@wordpress/scripts`.
- WordPress REST API with permission callbacks for interactive data flows.
- WordPress custom post types/taxonomies plus dedicated tables only for
  high-volume transactional records (orders, tickets, attendees).
- No remote runtime dependency required for the core plugin.

## Quality gates

- Follow WordPress Coding Standards, escaping all output and sanitizing every
  input at its boundary.
- Mutating actions require a nonce and an explicit capability/ownership check.
- Parameterize database values with `$wpdb->prepare`; whitelist identifiers.
- Use WordPress HTTP APIs, never raw `curl` or `file_get_contents` for requests.
- Localize all user-facing strings with the `eventflow` text domain.
- Design to WCAG 2.2 AA: keyboard access, visible focus, labelled controls,
  meaningful errors, and adequate contrast.
- Keep public pages SEO-friendly and avoid a JavaScript-only first render.
- Do not add trackers, advertising, license gates, telemetry, or external calls
  unless a user explicitly enables them.
- Add focused automated tests for domain logic and REST permissions; run lint,
  build, and relevant tests before each completed milestone.

## Core MVP, in delivery order

1. Plugin foundation: activation/migrations, capabilities, settings,
   event/venue/organizer/category models, uninstall cleanup choice.
2. Event management: event editor, scheduling/time zones, status, image,
   venue, organizer, categories, and accessibility-friendly admin interface.
3. Public discovery: event archive, responsive list/grid/calendar views,
   filters, pagination, event detail pages, schema.org Event JSON-LD.
4. Tickets and bookings: free tickets first; capacity, booking cut-off,
   attendee fields, confirmation email, booking lookup and cancellation.
5. Paid tickets: an extensible gateway contract and one gateway integration
   only after the order lifecycle is well tested.
6. Organizer tools: scoped access, attendee list, CSV export, check-in.
7. Gutenberg blocks and documented shortcodes for all public views.

## Design direction

Create an original premium editorial interface: confident typography, generous
spacing, strong event imagery, calm neutral surfaces with one distinctive accent
colour, and restrained motion. Do not imitate EventPrime's UI. Build reusable
tokens and components rather than page-specific CSS. Respect `prefers-reduced-motion`.

## Working protocol

1. Inspect relevant repository files before modifying them.
2. Make the smallest coherent vertical slice that users can validate.
3. Keep architectural notes in `docs/ARCHITECTURE.md` and current milestone
   status in `docs/PROGRESS.md`.
4. Do not overwrite or import code/assets from `../eventprime-event-calendar-management`.
   It is reference-only for high-level feature discovery.
5. When a product decision has a major irreversible cost (payment provider,
   data deletion policy, external SaaS), pause and ask the owner. Otherwise,
   decide pragmatically and document it.
6. Finish each milestone with commands run and outcomes recorded in progress.

## Definition of done

The plugin installs cleanly on a supported WordPress site; an admin can create
and publish an event; a visitor can discover it, reserve a ticket, receive a
confirmation, and the admin can manage/check in the attendee. Public pages meet
the performance and accessibility gates above, and the repository contains
installation, usage, and developer documentation.
