# Laravel + Vue Responsive Conversion Plan

Date: 2026-07-01
Project: `C:\laragon\www\ngwe-lwe-system-laravel`

## Goal

Python မသုံးဘဲ Laravel + Vue/Inertia နဲ့ပဲ project ကို ဆက်ပြင်မယ်။

Main goals:

- Calculation / telemetry parsing မှားနေတဲ့နေရာကို စစ်ပြီးပြင်မယ်။
- Desktop, Tablet, Mobile responsive UI ဖြစ်အောင် ပြင်မယ်။
- Step by step ပြောင်းပြီး တစ်ဆင့်ပြီးမှ တစ်ဆင့် verify လုပ်မယ်။
- Test/build pass ဖြစ်တဲ့အခြေအနေကို ထိန်းထားမယ်။

## Current Situation

Project က Laravel + Vue/Inertia ဖြစ်ပြီးသားပါ။

Existing stack:

- Backend: Laravel
- Frontend: Vue 3 + Inertia
- Build tool: Vite
- Realtime: Laravel Reverb / WebSocket
- TCP telemetry parser: PHP service classes

ဒါကြောင့် full rewrite မလုပ်ခင် calculation bug နဲ့ responsive UI ကို phase ခွဲပြီးပြင်တာ ပိုသင့်တော်ပါတယ်။

Pattern reference:

- `pattern-reference.md`
- Telemetry-specific parser, client, tests, dashboard layout ကို `https://github.com/hninoo/Flight-Telemetry-System/` က primary reference အဖြစ်ယူမယ်။
- Local mirror `C:\laragon\www\Flight-Telemetry-System` ကိုသုံးမယ်ဆိုရင် GitHub latest နဲ့ sync ဖြစ်/မဖြစ် အရင်စစ်မယ်။
- Backend style ကို `C:\laragon\www\AsiaLandCompany` က service/support layering အတိုင်းယူမယ်။
- Step-by-step migration နဲ့ responsive verification ကို `C:\laragon\www\forextrading\v2` က task-plan pattern အတိုင်းယူမယ်။
- Legacy ThinkPHP code, Filament admin, large unrelated UI patterns တွေကို copy မလုပ်ဘူး။

## Version Plan

ဒီ project အတွက်သုံးမယ့် recommended versions:

| Technology | Version |
| --- | --- |
| PHP | 8.4.x recommended, minimum 8.3 |
| Laravel | 13.x, current constraint `^13.8` |
| Vue | 3.5.x, current constraint `^3.5.13` |
| Inertia Vue | 3.x |
| Tailwind CSS | 4.1.x, current constraint `^4.1.1` |
| Vite | 8.x |
| Node.js | 22.x recommended |
| MySQL | 8.0.x or 8.4 LTS |
| Redis | 7.x Alpine for cache/WebSocket support |

Notes:

- Current `composer.json` requires `php: ^8.3`, so PHP 8.3 and above is acceptable.
- Local verification was run successfully with Laragon PHP 8.4.1 and Node 22.14.0.
- Current Docker setup uses Redis but does not define a MySQL container yet.
- If database persistence is needed, add MySQL 8.0 or 8.4 LTS and configure `.env` with `DB_CONNECTION=mysql`.
- For lowest risk on Laragon, MySQL 8.0.x is a safe choice.

## Final Tech Stack

Use this stack when starting `ngwe-lwe-system` conversion:

| Layer | Technology |
| --- | --- |
| Backend | Laravel 13.x |
| PHP | PHP 8.4.x recommended, minimum PHP 8.3 |
| Frontend | Vue 3.5.x |
| Bridge | Inertia 3.x |
| Styling | Tailwind CSS 4.1.x |
| Build Tool | Vite 8.x |
| Database | MySQL 8.0.x or MySQL 8.4 LTS |
| Realtime | Laravel Reverb + Laravel Echo |
| TCP / Background Process | Laravel Console Command + Swoole/Octane |
| Cache / Queue | Redis 7.x |
| Testing | PHPUnit, vue-tsc, Vite production build |
| Optional Responsive Test | Playwright |

Primary reference:

```text
https://github.com/hninoo/Flight-Telemetry-System/
```

Secondary references:

```text
C:\laragon\www\AsiaLandCompany
C:\laragon\www\forextrading\v2
```

## Final Architecture Pattern

Use Repository pattern for database-backed features:

```text
Route -> Controller -> Service -> Repository -> Model
```

Use Service/Support pattern for pure telemetry logic:

```text
Service -> Support Helper
```

Backend structure:

```text
app/
  Http/
    Controllers/
      Api/
        FlightController.php

  Services/
    FlightService.php
    TelemetryService.php
    FlightDirectoryService.php
    Client.php
    PacketParser.php
    Monitor.php
    CoroutineRunner.php

  Repositories/
    FlightRepository.php
    TelemetryRepository.php
    TelemetryLogRepository.php

  Models/
    Flight.php
    TelemetryLog.php

  Support/
    Crc16Ccitt.php
    RangeValidator.php
    Enums/
      ConnectionStatus.php

  Events/
    TelemetryUpdated.php
```

Frontend structure:

```text
resources/js/
  pages/
    FlightDashboard.vue

  components/
    FlightCard.vue

  composables/
    useFlights.ts
    useFlightSocket.ts

  types/
    telemetry.ts

  lib/
    echo.ts
    utils.ts
```

Repository usage rule:

- Use Repository for MySQL persistence: flights, telemetry logs, history, reports, dashboard settings, user settings, filtering, pagination.
- Do not use Repository for binary parsing, CRC calculation, range validation, TCP connection lifecycle, WebSocket broadcasting, or in-memory stream state.

## Project Start Checklist

Do these before implementation starts:

- [ ] Confirm current project path: `C:\laragon\www\ngwe-lwe-system-laravel`.
- [ ] Confirm target name/scope: `ngwe-lwe-system`.
- [ ] Confirm primary reference is GitHub latest: `hninoo/Flight-Telemetry-System`.
- [ ] Run baseline checks.
- [ ] Create/confirm MySQL database only if persistence is required.
- [ ] Decide whether telemetry history must be stored in MySQL.
- [ ] Start with parser/calculation fixes before UI changes.
- [ ] Keep each phase testable before moving to the next phase.

Baseline commands:

```bash
php artisan test
npm run types:check
npm run build
```

If Laragon PATH does not expose `php` or `npm`, use full Laragon binary paths.

## Phase 1: Baseline Check

Estimate: 0.5 day

Tasks:

- [ ] Requirement file ကိုပြန်ဖတ်မယ်။
- [ ] Current parser logic ကို requirement နဲ့တိုက်စစ်မယ်။
- [ ] Current frontend layout ကို Desktop / Tablet / Mobile viewport တွေမှာစစ်မယ်။
- [ ] Current tests/build ကို run မယ်။
- [ ] Bug မပြင်ခင် current behavior ကို screenshot/report နဲ့မှတ်ထားမယ်။

Commands:

```bash
php artisan test
npm run types:check
npm run build
```

Laragon terminal မှာ PATH issue ရှိရင် PHP/Node full path နဲ့ run မယ်။

## Phase 2: Calculation Bug Investigation

Estimate: 0.5 to 1 day

Tasks:

- [ ] Real telemetry packet sample ရှာမယ်။
- [ ] Packet raw bytes ကို log ထုတ်ပြီး သိမ်းမယ်။
- [ ] Byte offset တွေကို requirement table နဲ့တိုက်စစ်မယ်။
- [ ] Float decoding ကိုစစ်မယ်။
- [ ] CRC range ကိုစစ်မယ်။
- [ ] Unit mismatch ရှိ/မရှိစစ်မယ်။
- [ ] Frontend မှာ stale value ပြနေသလားစစ်မယ်။

Files to review:

- `app\Services\PacketParser.php`
- `app\Support\Crc16Ccitt.php`
- `app\Support\RangeValidator.php`
- `app\Services\Client.php`
- `resources\js\components\FlightCard.vue`
- `resources\js\composables\useFlightSocket.ts`

Expected result:

- Calculation မှားနေတဲ့ exact cause ကိုသိရမယ်။
- Example packet တစ်ခုနဲ့ test case တစ်ခုရေးနိုင်ရမယ်။

## Phase 3: Backend Fix

Estimate: 0.5 to 1 day

Tasks:

- [ ] Parser offset မှားရင်ပြင်မယ်။
- [ ] CRC range mismatch ရှိရင်ပြင်မယ်။
- [ ] Range validation မှားရင်ပြင်မယ်။
- [ ] Bad packet / malformed frame ကို `CORRUPTED` status ပို့အောင်ပြင်မယ်။
- [ ] `flightId` ကို telemetry subscription မှာ number အဖြစ်ပို့ဖို့စစ်မယ်။
- [ ] Reconnect/backoff behavior ကိုလိုရင်ပြင်မယ်။

Backend acceptance checks:

- [ ] Valid packet ဆို `VALID` ဖြစ်ရမယ်။
- [ ] CRC မှားရင် `CORRUPTED` ဖြစ်ရမယ်။
- [ ] Range ကျော်ရင် `CORRUPTED` ဖြစ်ရမယ်။
- [ ] TCP error ဖြစ်ရင် `ERROR` ဖြစ်ရမယ်။
- [ ] Connection closed ဖြစ်ရင် `CLOSED` ဖြစ်ရမယ်။

## Phase 4: Backend Tests

Estimate: 0.5 day

Tasks:

- [ ] Known good packet test ထည့်မယ်။
- [ ] Known bad CRC test ထည့်မယ်။
- [ ] Out-of-range value test ထည့်မယ်။
- [ ] Partial packet test ထည့်မယ်။
- [ ] Concatenated packet test ထည့်မယ်။
- [ ] Misaligned/noise packet test ထည့်မယ်။
- [ ] Bad end marker test ထည့်မယ်။
- [ ] Bad packet size test ထည့်မယ်။

Command:

```bash
php artisan test
```

Acceptance:

- [ ] All tests pass.
- [ ] Calculation bug ကို regression test ကဖမ်းနိုင်ရမယ်။

## Phase 5: Responsive UI Planning

Estimate: 0.5 day

Target viewports:

- Mobile: 360px to 480px
- Tablet: 768px to 1024px
- Desktop: 1280px and above

Responsive rules:

- Mobile မှာ flight card တစ်ကတ်စီ vertical layout ဖြစ်ရမယ်။
- Tablet မှာ 2-column layout ဖြစ်နိုင်ရမယ်။
- Desktop မှာ 3-column or auto-fit grid ဖြစ်ရမယ်။
- Text overflow မဖြစ်ရဘူး။
- Metric values တွေ card ထဲကနေထွက်မသွားရဘူး။
- Buttons / retry / status badges touch-friendly ဖြစ်ရမယ်။
- Loading, error, empty state တွေ screen size အားလုံးမှာသေသပ်ရမယ်။

Files to update:

- `resources\css\app.css`
- `resources\js\pages\Welcome.vue`
- `resources\js\components\FlightCard.vue`

## Phase 6: Responsive UI Implementation

Estimate: 1 to 2 days

Tasks:

- [ ] Main dashboard container spacing ပြင်မယ်။
- [ ] Flight card grid ကို responsive auto-fit/minmax နဲ့ပြင်မယ်။
- [ ] Metric panel layout ကို mobile မှာ 1-column, tablet/desktop မှာ 2-column or compact grid လုပ်မယ်။
- [ ] Metric labels ကို `Altitude`, `Speed`, `Acceleration`, `Thrust`, `Temperature` လို readable labels သုံးမယ်။
- [ ] Footer labels ကို `Telemetry Port`, `Last Update` လို readable labels သုံးမယ်။
- [ ] Header metadata ကို mobile မှာ wrap ဖြစ်အောင်ပြင်မယ်။
- [ ] Card sub-header/footer rows ကို wrap ဖြစ်အောင်ပြင်ပြီး mobile overflow မဖြစ်အောင်လုပ်မယ်။
- [ ] Status badge width/text overflow စစ်မယ်။
- [ ] Last update / port footer ကို mobile မှာမပြတ်အောင်ပြင်မယ်။
- [ ] Error and loading state ကို mobile-friendly လုပ်မယ်။

Acceptance:

- [ ] 360px mobile viewport မှာ horizontal scroll မရှိရဘူး။
- [ ] 768px tablet viewport မှာ cards မကျပ်ရဘူး။
- [ ] 1280px desktop viewport မှာ layout လွတ်လပ်ပြီး scan လုပ်ရလွယ်ရမယ်။
- [ ] Telemetry numbers ရှည်လာရင် layout မပျက်ရဘူး။

## Phase 7: Frontend State Fix

Estimate: 0.5 to 1 day

Tasks:

- [ ] `WAITING` default state မှန်/မမှန်စစ်မယ်။
- [ ] `VALID` ရောက်ရင် latest telemetry data ပြမယ်။
- [ ] `CORRUPTED` ရောက်ရင် stale data ကိုရှင်းမလား, dim လုပ်မလားဆုံးဖြတ်မယ်။
- [ ] `ERROR` / `CLOSED` ရောက်ရင် old value ကို misleading မဖြစ်အောင်ပြမယ်။
- [ ] Last update timestamp ကို status update တိုင်းပြောင်းမယ်။

Recommended behavior:

- `VALID`: telemetry values normal display.
- `CORRUPTED`: value area ကို warning state ပြပြီး old data မမှားအောင်ရှင်းပြမယ်။
- `ERROR`: connection issue state ပြမယ်။
- `CLOSED`: reconnecting state ပြမယ်။
- `WAITING`: no telemetry yet.

## Phase 8: Responsive Verification

Estimate: 0.5 day

Manual viewport checklist:

- [ ] 360 x 800 mobile
- [ ] 390 x 844 mobile
- [ ] 768 x 1024 tablet
- [ ] 1024 x 768 tablet landscape
- [ ] 1280 x 720 desktop
- [ ] 1440 x 900 desktop

Commands:

```bash
npm run types:check
npm run build
```

Browser checks:

- [ ] No horizontal overflow.
- [ ] No overlapping text.
- [ ] Flight cards remain readable.
- [ ] Status color/state is clear.
- [ ] Realtime updates do not resize cards badly.

## Phase 9: Final Verification

Estimate: 0.5 day

Tasks:

- [ ] PHP tests run.
- [ ] TypeScript check run.
- [ ] Production build run.
- [ ] README update လိုရင် update လုပ်မယ်။
- [ ] Final report ရေးမယ်။
- [ ] Known limitations ရှိရင်ရေးမယ်။

Commands:

```bash
php artisan test
npm run types:check
npm run build
```

## Total Estimate

If only calculation fix:

- 1 to 2 working days

If calculation fix + responsive UI:

- 3 to 5 working days

If clean rebuild with Laravel + Vue:

- 5 to 8 working days

Recommended path:

1. Calculation bug ကိုအရင်ရှာပြီး fix လုပ်မယ်။
2. Backend tests နဲ့ lock လုပ်မယ်။
3. Responsive UI ကို Desktop / Tablet / Mobile အတွက်ပြင်မယ်။
4. Final test/build run မယ်။

## Working Order

Use this order when implementation starts:

1. `PacketParser` and calculation tests.
2. `Client` status/reconnect behavior.
3. `useFlightSocket` frontend state behavior.
4. `FlightCard.vue` responsive component layout.
5. `Welcome.vue` dashboard layout.
6. `app.css` responsive styling.
7. Final tests and report.
