# EventFlow architecture

EventFlow is a native WordPress plugin with progressively enhanced React
interfaces. PHP owns installation, persistence, permissions, rendering entry
points, REST endpoints, and email. React is compiled during development and
ships as static plugin assets; it is not a separately hosted application.

## Boundaries

- `eventflow.php`: plugin bootstrap and lifecycle hooks.
- `src/`: PHP domain modules, REST controllers, admin integration, public views.
- `assets/src/`: TypeScript and styles for interactive interfaces.
- `assets/build/`: generated release assets only.
- `templates/`: overridable server-rendered public templates.
- `tests/`: PHP and JavaScript tests.

## Initial domain model

- `ef_event` custom post type: public event content and lifecycle.
- `ef_venue` and `ef_organizer` custom post types.
- `ef_event_category` taxonomy.
- Ticket definitions stored as event metadata in the first free-ticket slice;
  bookings/attendees move to dedicated tables before paid transactions ship.

This model is intentionally original and may evolve through migrations. The
project has no dependency on, migration from, or compatibility promise to any
other event plugin.

## Visual system for milestone 1

- **Ink** `#101A46`: navigational and action surface.
- **Signal blue** `#3257FF`: future interactive state accent.
- **Citrus** `#D9F34A`: the event date stamp and primary action.
- **Paper** `#F7F8FF`: page ground.
- **Mist** `#DCE0EF`: structural rule and field border.

The oversized circular date stamp is the visual anchor. Editorial serif
headlines pair with system UI body text to keep the public page distinctive
without loading a remote font.
