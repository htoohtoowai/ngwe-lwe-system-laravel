# Pattern Reference

Date: 2026-07-01

Reference projects:

- GitHub canonical: `https://github.com/hninoo/Flight-Telemetry-System/`
- Local mirror: `C:\laragon\www\Flight-Telemetry-System`
- `C:\laragon\www\AsiaLandCompany`
- `C:\laragon\www\forextrading`

Target project:

- `C:\laragon\www\ngwe-lwe-system-laravel`

## Purpose

Use the two existing projects as implementation references while fixing and polishing the Laravel + Vue telemetry dashboard.

This is not a copy-paste plan. The target project has a different domain: real-time flight telemetry. Only proven patterns should be reused.

## Primary Reference: Flight-Telemetry-System

### What It Is

`Flight-Telemetry-System` is the closest reference because it is also a Laravel + Vue real-time flight telemetry dashboard. Use the GitHub repository as the canonical updated source:

```text
https://github.com/hninoo/Flight-Telemetry-System/
```

Local mirror status checked on 2026-07-01:

- Local HEAD: `77fec0d`
- GitHub `origin/main` HEAD: `35d488a`
- Latest GitHub change: `rename readable lables of FlightCard`
- Changed files: `README.md`, `resources/css/app.css`, `resources/js/components/FlightCard.vue`

The local mirror may be behind GitHub, so compare against `origin/main` or pull before copying reference code.

Observed stack:

- Laravel 13
- PHP `^8.3`
- Vue 3.5
- Inertia 3
- Tailwind CSS 4.1
- Vite 8
- Laravel Reverb / Echo
- Octane / Swoole TCP client
- Redis support
- PHPUnit parser and API tests

### Patterns To Reuse First

Use these patterns before borrowing from the other reference projects:

- Parser constants for every protocol offset.
- `PacketParser::extractNextResult()` style, where malformed end markers produce a `CORRUPTED` result and then resynchronize.
- `unpackFloat()` wrapper that returns `null` if unpacking fails.
- Larger and safer buffer compaction logic.
- TCP client socket lifecycle with a stored socket reference so `stop()` can close it.
- Swoole socket options for max packet length and socket buffer size.
- `TelemetryParserTest` coverage for bad end marker plus resynchronization.
- Dashboard layout using `FlightDashboard.vue`, `FlightCard.vue`, and a responsive CSS grid.
- Latest GitHub UI label pattern: use readable labels like `Altitude`, `Speed`, `Acceleration`, `Thrust`, `Temperature`, `Telemetry Port`, and `Last Update` instead of overly compressed labels like `ALT`, `SPD`, `ACC`, `THR`, `TEMP`, `PORT`, `UPDATED`.
- Latest GitHub CSS pattern: allow sub-header and footer rows to wrap so mobile/tablet layouts do not overflow.

### Exact Files To Compare

Reference files:

- `app\Services\PacketParser.php`
- `app\Services\Client.php`
- `tests\Unit\TelemetryParserTest.php`
- `resources\js\pages\FlightDashboard.vue`
- `resources\js\components\FlightCard.vue`
- `resources\css\app.css`

Target files:

- `C:\laragon\www\ngwe-lwe-system-laravel\app\Services\PacketParser.php`
- `C:\laragon\www\ngwe-lwe-system-laravel\app\Services\Client.php`
- `C:\laragon\www\ngwe-lwe-system-laravel\tests\Unit\TelemetryParserTest.php`
- `C:\laragon\www\ngwe-lwe-system-laravel\resources\js\pages\Welcome.vue`
- `C:\laragon\www\ngwe-lwe-system-laravel\resources\js\components\FlightCard.vue`
- `C:\laragon\www\ngwe-lwe-system-laravel\resources\css\app.css`

### What To Copy Conceptually

Copy the idea, not blindly the file:

- Named offset constants improve calculation readability.
- Bad structural packets should visibly emit `CORRUPTED`.
- Resync should continue after a bad packet and still parse the next valid packet.
- Frontend grid should stay stable from mobile to desktop.
- Tests should include real protocol edge cases.

### What To Check Before Applying

Before copying any code, confirm:

- `ngwe-lwe-system-laravel` has not already fixed the same behavior.
- Existing tests still pass.
- The requirement document still agrees with the reference implementation.
- `Welcome.vue` naming should remain unless we intentionally rename the page to `FlightDashboard.vue`.
- GitHub latest `origin/main` has been checked, because the local mirror can lag behind.

## Reference 1: AsiaLandCompany

### What It Is

`AsiaLandCompany` is a modern Laravel application with a large backend structure, service/repository layering, tests, Tailwind styling, assets, localization, and admin tooling.

Observed stack:

- Laravel 12
- PHP `^8.2`
- Tailwind CSS 4.x
- Vite
- Repository + service pattern
- Filament admin
- Multi-language support
- Feature and unit tests

### Patterns To Reuse

Use these ideas in `ngwe-lwe-system-laravel`:

- Keep backend logic outside controllers.
- Use service classes for business logic.
- Use repository classes only when database persistence becomes meaningful.
- Keep validation/range/calculation logic in small support classes.
- Keep tests close to risky logic.
- Use clear provider registration if interfaces become necessary.
- Use responsive card patterns with fixed image/metric areas and stable spacing.
- Use local font/assets strategy if Myanmar text is used in UI.

### Backend Pattern

Good reference files:

- `app\Services\*`
- `app\Repositories\*`
- `app\Repositories\Interfaces\*`
- `app\Providers\RepositoryServiceProvider.php`
- `tests\Unit\*`
- `tests\Feature\*`

Recommended adaptation for `ngwe-lwe-system-laravel`:

```text
app/
  Services/
    FlightDirectoryService.php
    Client.php
    PacketParser.php
    Monitor.php
  Support/
    Crc16Ccitt.php
    RangeValidator.php
    Enums/
  Http/
    Controllers/
      Api/
```

Do not add repositories unless MySQL persistence is added. Current telemetry is mostly stream processing, not CRUD.

### Frontend Pattern

Good reference files:

- `resources\views\components\property-card.blade.php`
- `public\assets\css\style.css`

Useful UI ideas:

- Card content hierarchy: title, key value, details row, footer row.
- Stable image/detail sizes.
- Small status/detail badges.
- Clear mobile media query.
- Local Myanmar font support.

For `ngwe-lwe-system-laravel`, apply the idea to:

- `resources\js\components\FlightCard.vue`
- `resources\js\pages\Welcome.vue`
- `resources\css\app.css`

## Reference 2: forextrading

### What It Is

`forextrading` is a legacy ThinkPHP project with a `v2` replacement build. The useful reference is not the old legacy code, but the `v2` migration method.

Observed `v2` stack:

- Backend: ThinkPHP 8.1 API
- Frontend: Vue 3 SPA
- Mobile-first frontend
- API client layer
- Stores
- Layouts
- Router
- Playwright tests for mobile/admin/API
- Module-by-module migration task plan

### Patterns To Reuse

Use these ideas in `ngwe-lwe-system-laravel`:

- Work module-by-module instead of rewriting everything at once.
- Lock scope before coding.
- Keep API contracts clear.
- Use frontend composables/stores for state.
- Use separate layout/component files.
- Use Playwright-style viewport verification for mobile.
- Keep a task plan with completed and pending checkboxes.
- Preserve working behavior while replacing risky parts.

### Frontend Folder Pattern

Good reference folder:

```text
v2/frontend/src/
  api/
  components/
  composables/
  config/
  layouts/
  router/
  services/
  stores/
  styles/
  views/
```

Recommended adaptation for `ngwe-lwe-system-laravel`:

```text
resources/js/
  components/
    FlightCard.vue
  composables/
    useFlights.ts
    useFlightSocket.ts
  lib/
    echo.ts
    utils.ts
  pages/
    Welcome.vue
  types/
    telemetry.ts
```

Current `ngwe-lwe-system-laravel` structure is already close enough. Do not over-expand unless the dashboard grows.

### Verification Pattern

Good reference files:

- `v2/frontend/playwright.config.mjs`
- `v2/frontend/tests/e2e/mobile-*.spec.mjs`
- `v2/TASK_PLAN.md`

Recommended adaptation:

- Add responsive checks for 360px, 390px, 768px, 1024px, 1280px, 1440px.
- Capture screenshot only on failure if Playwright is added.
- Use manual checks first if the project deadline is short.

## Pattern Decision For ngwe-lwe-system-laravel

Use this blend:

| Area | Reference | Decision |
| --- | --- | --- |
| Flight telemetry parser/client | Flight-Telemetry-System | Primary reference |
| Telemetry UI | Flight-Telemetry-System | Primary reference, then improve responsiveness |
| Parser tests | Flight-Telemetry-System | Reuse edge-case coverage ideas |
| Laravel service structure | AsiaLandCompany | Reuse service/support layering |
| Repository pattern | AsiaLandCompany | Skip for now unless MySQL telemetry/history tables are added |
| Responsive card UI | AsiaLandCompany | Reuse card hierarchy idea, not exact design |
| Step-by-step migration | forextrading v2 | Reuse module-by-module task plan |
| Vue app organization | forextrading v2 | Reuse composables/components/types separation |
| Mobile verification | forextrading v2 | Reuse viewport test/checklist idea |
| Legacy parity strategy | forextrading v2 | Preserve current behavior, then replace risky parts |

## Target Implementation Pattern

For `ngwe-lwe-system-laravel`, follow this order:

1. Parser/calculation logic stays in `app\Services\PacketParser.php`.
2. CRC/range helpers stay in `app\Support`.
3. TCP connection lifecycle stays in `app\Services\Client.php`.
4. Flight REST proxy stays in `app\Http\Controllers\Api\FlightController.php`.
5. Vue realtime state stays in `resources\js\composables\useFlightSocket.ts`.
6. Responsive card display stays in `resources\js\components\FlightCard.vue`.
7. Dashboard layout stays in `resources\js\pages\Welcome.vue`.
8. Shared styling stays in `resources\css\app.css`.

## What Not To Copy

Do not copy these patterns directly:

- Filament admin from `AsiaLandCompany`, because telemetry dashboard does not need admin CRUD right now.
- Full repository layer for telemetry stream processing.
- Legacy ThinkPHP structure from `forextrading`.
- Large mobile trading UI from `forextrading`, because flight telemetry needs a dashboard, not a finance app.
- Old jQuery/static asset patterns.
- Huge CSS files without component boundaries.

## Recommended Next Step

Continue with the existing `laravel-vue-responsive-step-by-step.md` plan, but apply this reference rule:

- Telemetry-specific backend/frontend: follow `Flight-Telemetry-System` first.
- Backend style: follow `AsiaLandCompany`.
- Migration discipline and responsive verification: follow `forextrading/v2`.
- Keep `ngwe-lwe-system-laravel` small and focused because the domain is real-time telemetry.
