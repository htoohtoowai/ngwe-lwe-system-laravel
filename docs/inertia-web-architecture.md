# Laravel + Vue + Inertia Web Architecture

The customer demo runs as a Laravel web monolith. Vue pages are delivered by Inertia and do not depend on `/api/*` endpoints.

## Request flow

```text
Browser
  -> Laravel web route
  -> auth session + role middleware
  -> FormRequest validation
  -> Controller / Service / Repository
  -> MySQL
  -> redirect or Inertia response
  -> Vue page props refresh
```

## Authentication

- Laravel `web` session guard.
- Login uses `Auth::login()` and regenerates the session.
- Logout invalidates the session and regenerates the CSRF token.
- No bearer token, localStorage API token, or custom API auth cookie is required.

## Writes

Vue uses Inertia `router.post`, `router.patch`, `router.put`, and `router.delete` against `routes/web.php`. Validation errors return through the Inertia error bag.

## Reads

Page data is prepared on the server and passed as Inertia props. Admin data is assembled by `AdminOperationsDataService`; teller/cashier pages use their Laravel controllers and services directly.

## Realtime

Laravel Reverb remains enabled. Private-channel authorization uses the normal Laravel web session through `/broadcasting/auth`; no bearer token is attached.

## API

`routes/api.php` and API controllers are not registered in the demo application. Obsolete API integration tests were removed; the active PHPUnit suite targets session-authenticated Inertia web flows.
