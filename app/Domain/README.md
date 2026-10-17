# Domain boundaries

The reserved module folders introduce no financial functionality. Keep the full
starter's standard authentication/controllers/models in their existing locations.
Domain ownership policies and restricted administration remain M0-P3 work.

New application requests use Laravel FormRequest validation and policy checks.
Keep Inertia's normal validation/redirect behavior; JSON requests use Laravel's
422 response with message and errors. Unexpected exceptions use Laravel's error
handler; production responses must not include debug details. No new public API
or custom error envelope is introduced by bootstrap.

Factories use synthetic data through the retained UserFactory. Tests use Laravel's
travelTo/freezeTime helpers with UTC instants and restore the clock afterward.
Future financial rules must use the framework Date abstraction or an explicitly
injected clock rather than reading uncontrolled system time.

See the accepted architecture and invariants in docs/halal-wealth-os. These folders
do not establish the proposed detailed module import graph as an accepted design.
