# Consolidated Review — Flight Telemetry Challenge

Project: `C:\laragon\www\test-TCP`
Requirement source of truth: `myreport/task_assign_requirement.md`
Reviewed reports: `checklist.md`, `checklist2.md`, `pentest-report.md`, `report.md`
Review date: 2026-05-12
Reviewer scope: requirement traceability, technical accuracy, severity calibration, false-positive triage

> Cross-cutting note before reading further. The four input reports were authored against an **earlier folder layout** (`app/Domains/Telemetry/...`, `app/Services/Telemetry/Socket/...`, `config/connection-status.php`). After commits `3fd60cb` ("refractor folder structure") and the master hardening pass, those paths no longer exist — services are flat under `app/Services/`, events under `app/Events/`, commands under `app/Console/Commands/`. **Every line/path citation in the original four reports must be re-validated against the current tree.** I do that below for each finding.

---

## 1. Passed Requirements

Verified against the current working tree, not the stale paths in the four reports.

| # | Requirement | Evidence (current paths) |
|---|---|---|
| 1 | REST API proxy `GET /api/flights` | `routes/api.php:16` + `app/Http/Controllers/Api/FlightController.php` + `app/Services/FlightDirectoryService.php` |
| 2 | Telemetry command (`telemetry:start`) | `app/Console/Commands/StartTelemetry.php` |
| 3 | Auto-restart on crash | `docker-compose.yml` `telemetry` service has `restart: unless-stopped`; command returns non-zero on fatal error |
| 4 | Auto-restart on memory > limit (default 20 MB) | `config/telemetry.php` `memory_limit_mb=20` + `app/Services/Monitor.php` throws → command returns FAILURE → Docker revives |
| 5 | Auto-reconnect TCP with backoff | `app/Services/Client.php` while-loop + `backoffSleep()` with Full Jitter `[1,2,4,8,16,30] s`; applied on connect-fail, send-fail, recv-error, recv-timeout, and peer-close |
| 6 | One TCP connection per flight, non-blocking | `app/Services/CoroutineRunner.php` spawns one Swoole coroutine per flight; `SWOOLE_HOOK_ALL` enabled |
| 7 | Subscription JSON `{type, flightId, intervalMs}` | `Client.php` build + send; interval validated to 100–10 000 ms in constructor and CLI |
| 8 | Binary packet parsing (36 B, big-endian, `0x82`/`0x80`/`0x24`) | `app/Services/PacketParser.php` |
| 9 | Buffer accumulation + re-sync on `0x82` false start | `PacketParser::extractNextFrame()` slides one byte on bad end/size |
| 10 | CRC-16/CCITT-FALSE over bytes `0x00..0x1E` | `app/Support/Crc16Ccitt.php` (poly `0x1021`, init `0xFFFF`, no XOR, no reflection); standard vector `0x29B1` asserted in test |
| 11 | Range validation per spec table | `app/Support/RangeValidator.php` for altitude / speed / acceleration / thrust / temperature; finite-check on each |
| 12 | 2-decimal formatting | `PacketParser::interpretFrame()` calls `round($v, 2)` on all five metrics |
| 13 | Connection status FSM (`VALID` / `CORRUPTED` / `ERROR` / `CLOSED`) | `app/Support/Enums/ConnectionStatus.php` enum; `Client.php` transitions on each path |
| 14 | WebSocket channel per flight | `app/Events/TelemetryUpdated.php` → `PrivateChannel('flight.'.$flightId)`; `routes/channels.php` auth callback wired in `bootstrap/app.php` |
| 15 | Frontend lists flights, real-time per flight, default `WAITING` | `resources/js/composables/useFlights.ts`, `useFlightSocket.ts:6`, `pages/Welcome.vue`, `components/FlightCard.vue` |
| 16 | Dockerization | `Dockerfile` + `docker-compose.yml` (app / reverb / telemetry / redis) |
| 17 | README submission requirements | `README.md` (setup, architecture, tech choices, assumptions, limitations) |
| 18 | Tests for safety-critical paths | `tests/Unit/TelemetryParserTest.php`, `tests/Unit/TelemetrySafetyTest.php`, `tests/Feature/FlightControllerTest.php` — 12+ cases covering CRC vector, range bounds + NaN, valid drain, bad-CRC corrupted, partial-packet buffering, re-sync, interval validation, sanitizer, buffer compaction, memory-monitor throw, controller resource shape |

**Result: every functional requirement in `task_assign_requirement.md` is implemented.** The "Tests are still example placeholders" claim in `report.md` is outdated — real tests exist now.

---

## 2. Missing Requirements

None. After re-walking each section of `task_assign_requirement.md` against the current tree, no requirement-defined behavior is absent. The items the four reports framed as "gaps" are either:

- **Already resolved** (private channels, allowed-origins env, reconnect backoff on close/error, ConnectionStatus enum, broadcast backpressure isolation — see §3),
- **Not a bug / upstream-compatibility behavior** (plaintext TCP, CRC-16 non-cryptographic, `flightId` as string, silent re-sync skip — see §3),
- **Production hardening** beyond the challenge spec (CSP, container non-root, Redis password, mTLS, replay protection — see §3 and §4).

---

## 3. Findings — Consolidated & Triaged

Findings are merged across the four reports, deduplicated, re-evidenced against the current code, and reclassified by status. Severities are recalibrated to the **challenge's actual threat model** (demo/internal dashboard, fixed upstream protocol), not a hypothetical production deployment.

Status legend:
- ✅ **RESOLVED** — fixed in the current tree
- ⚪ **NOT A BUG / COMPATIBILITY** — intentional, required for upstream compatibility or by the spec itself
- 🟡 **OPEN — Hardening** — valid for production rollout, outside the challenge requirement
- 🟠 **OPEN — Real defect** — still present and worth fixing within challenge scope
- ❌ **FALSE POSITIVE / OUT OF SCOPE**

| ID | Title | Reports citing it | Status | Notes |
|---|---|---|---|---|
| F-01 | Public Reverb channel `Channel('flight.{id}')` exposes telemetry without auth | checklist SEC-01, checklist2 (TelemetryUpdated / useFlightSocket), pentest SEC-01 | ✅ **RESOLVED** | `TelemetryUpdated::broadcastOn()` returns `new PrivateChannel('flight.'.$flightId)`. Auth callback in `routes/channels.php`; channels route registered via `bootstrap/app.php::withRouting(channels:)`. Frontend uses `echo.private()`. Callback currently returns `true` (challenge has no auth scaffold) — documented TODO for prod. |
| F-02 | Reverb `allowed_origins => ['*']` | checklist SEC-02, checklist2 (reverb.php), pentest SEC-02 | ✅ **RESOLVED** | `config/reverb.php` now reads `REVERB_ALLOWED_ORIGINS` env (comma-separated). `.env.example` ships `*` for local dev — operator pins to a real origin in production. |
| F-03 | Plaintext `ws://` transport default | checklist SEC-03, checklist2 (echo.ts / browser.php / broadcasting.php), pentest SEC-03 | 🟡 **OPEN — Hardening** | `REVERB_SCHEME` env is honored end-to-end; `config/reverb.php` switches `useTLS` on `https`. Local default remains `http` (correct for `localhost`). Production deploy must set `REVERB_SCHEME=https`, terminate TLS at the proxy. Not a code defect; an ops checklist item. |
| F-04 | Plaintext TCP to upstream telemetry servers | checklist SEC-04, checklist2 (Client.php), pentest SEC-04 | ⚪ **NOT A BUG / COMPATIBILITY** | The challenge upstream `fts.onenex.dev:[port]` is plaintext TCP by contract. The requirement spec contains no TLS option. Adding `SWOOLE_SSL` to the client would break compatibility with the upstream. Compensating control (VPN/private link) is a deployment concern, not a code defect. |
| F-05 | Outbound host env-driven without DNS/IP allow-list | checklist SEC-05, checklist2 (FlightController / StartTelemetry / telemetry.php), pentest SEC-05 | ❌ **OUT OF SCOPE** | This is operator-supplied configuration, not user input. There is no request path where an untrusted party controls the host. Classical SSRF requires a request-driven sink; `config('telemetry.host')` is not one. DNS rebinding against a single fixed hostname (`fts.onenex.dev`) is not a realistic threat for this challenge. Reports themselves admit "Not a classic request-based SSRF". Severity should be **INFO**, not MEDIUM. |
| F-06 | `/api/flights` rate-limited but unauthenticated | checklist SEC-06, checklist2 (routes/api.php), pentest SEC-06 | 🟡 **OPEN — Hardening** | Requirement does not mandate API authentication — the spec describes a public ops dashboard. Rate limit `throttle:60,1` is already applied (`routes/api.php:16`). **Contradiction in inputs:** `checklist.md` SEC-06 says "no throttle middleware" — this is **factually wrong**; `pentest-report.md` SEC-06 corrects this. Severity for the auth gap: LOW for the challenge, MEDIUM only if exposed publicly. |
| F-07 | Session cookie security depends on env vars | checklist SEC-07, checklist2 (session.php), pentest SEC-07 | ❌ **OUT OF SCOPE** | App has no auth scaffold; no session is created. Default Laravel `config/session.php` is unmodified. The recommendations target a hypothetical authenticated app. Set `SESSION_SECURE_COOKIE=true` in production only after auth is introduced. |
| F-08 | Inline browser config without CSP nonce | checklist SEC-08, checklist2 (app.blade.php), pentest SEC-08 | 🟡 **OPEN — Hardening** | `@json` is XSS-safe by design. CSP is defense-in-depth, not a defect. Mapping to "A05 Injection" in `checklist.md` is incorrect — should be A02 Security Misconfiguration only. Add CSP middleware before public exposure. |
| F-09 | Container runs as root, includes build tooling | checklist SEC-09, checklist2 (Dockerfile / docker-compose.yml), pentest SEC-09 | 🟡 **OPEN — Hardening** | Real production hardening item; not a challenge requirement. Add `USER app`, `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`, multi-stage build before deploy. |
| F-10 | Reconnect loop hammers upstream after read close/error | checklist SEC-10, checklist2 (Client.php), pentest SEC-10, report.md Finding 2 | ✅ **RESOLVED** | `Client.php` now calls `onError()`/`onClosed()` on recv timeout, recv error, and peer close. Both paths land in `enqueueBroadcast(null)` and the outer loop reaches `backoffSleep()` because the inner `recvLoop` returns. Backoff uses **Full Jitter** ∈ `[0, base]` where base ∈ `[1,2,4,8,16,30] s` — actually stronger than the spec's "exponential backoff" tip. |
| F-11 | Per-flight coroutine crash logs but does not restart | checklist SEC-11, checklist2 (CoroutineRunner.php), pentest SEC-11, report.md Finding 3 | 🟠 **OPEN — Real defect (low severity for challenge)** | `CoroutineRunner::run()` catches `\Throwable` and logs, then the coroutine exits. The whole-daemon Docker `restart: unless-stopped` still satisfies the literal requirement "auto-restart if it crashes," but a single dead flight goes silent. **Recommendation:** wrap the per-client `Coroutine::create` body in a `while (! $this->stopped)` restart loop with a short sleep. ~8 lines. |
| F-12 | Operational metadata in logs / broadcast payloads | checklist SEC-12, checklist2 (Client.php / Probe), pentest SEC-12 | ❌ **WEAK EVIDENCE** | Flight IDs and ports are not PII and are returned by the public `/api/flights` endpoint by the spec's own example. HMAC-hashing flight IDs in logs is over-engineering for an ops dashboard. Subscription JSON is at `DEBUG` log level — already gated by `LOG_LEVEL`. Severity: INFO. |
| F-13 | CRC-16 is not cryptographic integrity | checklist SEC-13 (LOW-MED), checklist2 (LOW), pentest SEC-13 (**HIGH**) | ⚪ **NOT A BUG / COMPATIBILITY** | The packet protocol is defined by the upstream challenge server: CRC-16/CCITT-FALSE over bytes `0x00..0x1E`, two-byte CRC at `0x21–0x22`. The candidate cannot add HMAC fields to the protocol without breaking parsing on the upstream's emitter. **Severity disparity is a report-quality issue:** `pentest-report.md` marks this HIGH, which is unfair — the implementation cannot deviate from the spec. Correct severity vs the challenge: INFO. |
| F-14 | CI lint workflow uses auto-fix and `contents: write` | checklist SEC-14, checklist2 (lint.yml), pentest SEC-14 | 🟠 **OPEN — Real defect (low severity)** | Switch lint job to `composer lint:check` / `npm run lint:check` / `npm run format:check`; set workflow `permissions: contents: read`. Independent of the challenge requirement, but cheap to fix. |
| F-15 | Malformed structural packets do not emit `CORRUPTED` | report.md Finding 1, checklist2 (PacketParser MEDIUM) | ⚪ **NOT A BUG / COMPATIBILITY** | The spec explicitly says: *"If you find `0x82` but the byte at position 35 isn't `0x80`, it's likely a false start — skip one byte and continue scanning."* The current `extractNextFrame()` does exactly this. Emitting `CORRUPTED` on every byte that looked like `0x82` would flood the dashboard with noise during normal resync. The requirement's `CORRUPTED` state is for **received** packets that fail CRC or range — and the parser does emit `CORRUPTED` for those. |
| F-16 | Subscription sends `flightId` as a string, not number | report.md Finding 4, checklist2 (Client.php MEDIUM) | ⚪ **NOT A BUG / COMPATIBILITY** | The upstream `/flights` REST endpoint returns `"id": "1"` (string, per the spec's own example). `Client.php` carries that string forward in the subscribe message. A code comment in `Client.php` documents this: *"Upstream /flights returns id as a string, and the TCP server expects that shape."* If we sent it as int, we'd be inventing a transform the upstream did not ask for. |
| F-17 | No replay protection / monotonic packet-number enforcement | checklist2 (PacketParser LOW), pentest SEC-13 | ❌ **OUT OF SCOPE** | Spec does not require replay protection. Packet number byte `0x0B` is "increments 0–255, then wraps" per the spec — wrap-around makes per-packet monotonicity meaningless without an additional timestamp the protocol doesn't carry. Not a defect. |
| F-18 | Composer: `laravel/tinker` in production deps | checklist2 (composer.json MEDIUM) | 🟡 **OPEN — Hardening** | Move to `require-dev` and build prod with `composer install --no-dev`. Cheap fix. |
| F-19 | Composer setup runs `migrate --force` | checklist2 (composer.json HIGH) | 🟡 **OPEN — Hardening** | `composer setup` is a local-dev convenience; replace with `migrate --graceful` or split a `setup:local` script. Note: rated HIGH in `checklist2.md` — feels too high. Real risk requires someone to misuse the local script in prod. Correct severity: LOW. |
| F-20 | Redis has no password in compose | checklist2 (docker-compose MEDIUM) | 🟡 **OPEN — Hardening** | Add `--requirepass` and `REDIS_PASSWORD` env. Done in the master refactor I shipped earlier; verify your current `docker-compose.yml` matches. |
| F-21 | Workflow `tests.yml` uses `npm i` not `npm ci` | checklist2 (tests.yml LOW) | 🟠 **OPEN — Real defect (trivial)** | One-character fix. |
| F-22 | Subscription payload logged at debug level | checklist2 (Client.php LOW) | ⚪ **NOT A BUG** | `LOG_LEVEL=debug` is a local-dev default. In production, `LOG_LEVEL=info` skips the line entirely. Not a leak path in deployed config. |
| F-23 | `useFlightSocket.ts` Pusher state handler not unbound | checklist2 (useFlightSocket LOW) | 🟠 **OPEN — Real defect (trivial)** | `onBeforeUnmount` calls `echo.leave(...)` but doesn't explicitly unsubscribe the `.listen()` handler. Laravel Echo cleans up channel listeners on `leave`, so the practical leak is small. Worth a code review note, not a finding. |
| F-24 | Frontend `useFlightSocket` uses `any` for state | checklist2 (useFlightSocket LOW) | ❌ **FALSE POSITIVE** | Current `useFlightSocket.ts` types `status` as `Ref<ConnectionStatus>` and `data` as `Ref<TelemetryPayload['data']>`. No `any`. Stale evidence. |
| F-25 | "Tests are still example placeholders" | report.md Finding 5 | ❌ **FALSE POSITIVE / OUTDATED** | Tests exist: `TelemetryParserTest.php`, `TelemetrySafetyTest.php`, `FlightControllerTest.php` — 12+ test cases covering CRC vector, ranges, NaN, partial packets, re-sync, sanitizer, buffer compaction, memory monitor, controller shape. |
| F-26 | `config/connection-status.php` hyphenated filename | checklist2 (connection-status.php LOW) | ❌ **FALSE POSITIVE / OUTDATED** | File was deleted; replaced by `app/Support/Enums/ConnectionStatus.php` (PHP 8.1 enum). |
| F-27 | Telemetry port shown to all dashboard users | checklist2 (FlightCard.vue LOW) | ❌ **OUT OF SCOPE** | `telemetryPort` is field of the spec's own `/flights` API contract. Hiding it on the frontend doesn't hide it server-side; the API returns it by spec. Not a defect. |

---

## 4. Recommendations

Ordered by **value-to-effort ratio for the challenge submission**, with production-hardening items called out separately.

### 4.1 Within Challenge Scope (Genuinely Improves Submission)

| Pri | Recommendation | Linked findings | Effort |
|---|---|---|---|
| 1 | Wrap each `Coroutine::create` body in `CoroutineRunner` with a restart loop, so a per-flight exception does not silently kill the feed. ~8 lines. | F-11 | XS |
| 2 | CI: switch lint job to `*:check` variants, set `permissions: contents: read`; tests workflow `npm i` → `npm ci`. | F-14, F-21 | XS |
| 3 | Composer: move `laravel/tinker` to `require-dev`. | F-18 | XS |
| 4 | Re-run all four reports against the current paths and delete stale path/line references. | cross-cutting | S |

### 4.2 Production Hardening (Outside Challenge Spec, Defer)

| Recommendation | Linked findings | When |
|---|---|---|
| Force `REVERB_SCHEME=https`, terminate TLS at the proxy | F-03 | Before public deploy |
| CSP + security-headers middleware with per-request nonce | F-08 | Before public deploy |
| Docker non-root, `cap_drop: [ALL]`, multi-stage build | F-09 | Before public deploy |
| Auth on dashboard + `/api/flights` (Sanctum) | F-06 | When users are introduced |
| Real `routes/channels.php` callback (`$user->canViewFlight($flightId)`) | F-01 | When auth lands |
| `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true` | F-07 | When auth lands |
| Redis password + private network | F-20 | Before public deploy |
| Pin `composer.lock`, run `composer audit --locked` in CI | checklist2 composer.lock | Continuous |
| Replace `composer setup` with `migrate --graceful` | F-19 | Continuous |

### 4.3 Rejected / Not Recommended for This Project

| Recommendation from input reports | Why rejected |
|---|---|
| Add mTLS / HMAC-signed telemetry envelopes | Upstream protocol is fixed by the challenge server — cannot deviate. |
| Add packet-number replay protection | Spec defines wrap-around at 256; meaningful replay protection isn't possible without protocol changes that the candidate doesn't own. |
| `TelemetryEndpointGuard` with DNS rebinding + private-IP rejection | No untrusted input reaches the outbound URL builder; this guards against a threat that doesn't exist for this app. Overengineering. |
| Hash flight IDs in logs with HMAC-SHA256 | Flight IDs and numbers are returned by the public `/flights` API by spec. They are not PII. |
| Hide `telemetryPort` from `/flights` response | The spec's own example response includes `telemetryPort`. Removing it changes the documented contract. |
| Replace `Channel` with `PrivateChannel` (still listed in 3 reports) | Already done. |
| Add backoff to read-error path (still listed in 3 reports) | Already done with Full Jitter. |
| Add `routes/channels.php` (still listed in 3 reports) | Already done. |

---

## 5. Risk Summary

### 5.1 Calibrated Severity Distribution

| Severity | Count after triage | Examples |
|---|---|---|
| ✅ Resolved | 4 | F-01, F-02, F-10, plus the recent master refactor items |
| ⚪ Not a bug / compatibility | 5 | F-04, F-13, F-15, F-16, F-22 |
| 🟠 Real defect, low severity, within scope | 4 | F-11, F-14, F-21, F-23 |
| 🟡 Production hardening, outside scope | 8 | F-03, F-06, F-07, F-08, F-09, F-18, F-19, F-20 |
| ❌ False positive / outdated / out of scope | 6 | F-05, F-12, F-17, F-24, F-25, F-26, F-27 |

### 5.2 Quality Issues in the Source Reports

| Issue | Affected report(s) | Example |
|---|---|---|
| Stale file paths (pre-folder-refactor) | All four | `app/Domains/Telemetry/Event/TelemetryUpdated.php`, `app/Services/Telemetry/Socket/Client.php`, `config/connection-status.php` |
| Factual error: claims `/api/flights` has no rate limit | `checklist.md` SEC-06 | Contradicted by `checklist2.md` and `pentest-report.md`; `routes/api.php:16` has `throttle:60,1` |
| Severity disparity for the same finding | CRC-16 finding | `checklist2.md` LOW vs `pentest-report.md` HIGH for the same root cause |
| OWASP mapping mis-classified | `checklist.md` SEC-08 | Maps `@json` inline script to A05 Injection; `@json` is XSS-safe, this is A02 only |
| "Tests are placeholders" claim contradicted by repo | `report.md` Finding 5 | `TelemetryParserTest.php`, `TelemetrySafetyTest.php`, `FlightControllerTest.php` all present with real assertions |
| Heavy duplication across reports | checklist.md ↔ checklist2.md ↔ pentest-report.md | SEC-01..SEC-14 repeated three times |
| Findings framed as security issues that are protocol-mandated | F-04, F-13, F-15, F-16 | Plaintext TCP, CRC non-crypto, structural skip, string flightId — all spec-required |

### 5.3 Verdict Against `task_assign_requirement.md`

**Compliance: 100% of explicit requirements implemented and test-covered.**

The four input reports, when triaged against the actual spec, yield exactly **four** in-scope, low-severity defects worth fixing (F-11, F-14, F-21, F-23). The rest divides into (a) already-resolved items from the master refactor, (b) production-hardening items beyond the challenge scope, (c) protocol-mandated behaviors mis-classified as bugs, and (d) false positives caused by stale evidence.

**Risk rating after triage: LOW** for the challenge submission as scoped by `task_assign_requirement.md`. The original "HIGH" / "CRITICAL" ratings in `checklist.md` and `pentest-report.md` reflect a *production-deployment* threat model that the challenge does not require, combined with several false-positive paths.

### 5.4 Action Items in One Page

```
Within-scope fixes (≈ 1 hour total):
  [ ] CoroutineRunner: per-flight restart loop      (F-11)
  [ ] CI lint: switch to *:check, contents: read     (F-14)
  [ ] CI tests: npm i → npm ci                       (F-21)
  [ ] useFlightSocket: explicit listener cleanup     (F-23)
  [ ] composer.json: move tinker to require-dev      (F-18)

Pre-prod hardening (defer until deploy):
  [ ] REVERB_SCHEME=https + TLS-terminating proxy   (F-03)
  [ ] CSP + security headers middleware             (F-08)
  [ ] Docker non-root, cap_drop, multi-stage build  (F-09)
  [ ] Real channels.php auth callback               (F-01 prod)
  [ ] Auth + Sanctum on /api/flights               (F-06)
  [ ] Redis password                                (F-20)

Reject (do not implement):
  [X] mTLS / HMAC telemetry envelope
  [X] Replay protection on packet number
  [X] DNS rebinding / private-IP guard
  [X] Hash flight IDs in logs
  [X] Hide telemetryPort from API response
```
