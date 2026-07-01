# Full-Codebase Security Audit Report

Role perspective: Senior Security Engineer review covering OSCP/CEH/CCIE-style offensive security, enterprise web application security, Swoole/Octane worker safety, WebSocket security, Redis, TCP ingestion, and software supply chain risk.

System context:

- Laravel 13 + Octane + Swoole persistent PHP worker process
- Laravel Reverb WebSocket server
- Inertia.js frontend bridge
- Redis via Predis
- TCP socket telemetry ingestion through `ext-sockets`
- PHP 8.3 with process control through `ext-pcntl`
- Mission-critical live aircraft telemetry data

Audit scope: current files under `C:\laragon\www\test-TCP`, including PHP application code, frontend bridge code, Docker/runtime configuration, CI configuration, and Composer supply chain files.

Advisory data note: dependency advisory status changes over time. This report checked current public Packagist/Aikido advisory signals during review. Run `composer audit` and CI dependency scanning before every release.

## File Findings

### composer.json

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | `laravel/tinker` is installed in production dependencies. Tinker is a REPL and increases post-compromise blast radius if an attacker obtains shell/container access. | L24 | Interactive code execution tooling is present in production builds, making lateral movement and data extraction easier after initial access. | Move to `require-dev` or remove entirely from production: `"require-dev": { "laravel/tinker": "^3.0" }`; build production with `composer install --no-dev`. |
| MEDIUM | Broad version constraints allow silent minor/major-compatible upgrades across high-risk packages. | L20-L25, L28-L35 | A compromised or buggy future package version can enter builds if `composer update` is run without review. Reverb, Octane, Predis, and framework packages have large attack surfaces. | Keep `composer.lock` enforced in CI/deploy; consider narrower constraints for mission-critical releases, e.g. `"laravel/reverb": "~1.10.1"` and scheduled dependency update windows. |
| MEDIUM | Extension requirements use `*` for `ext-pcntl` and `ext-sockets`. | L18-L19 | Runtime capabilities are required but not version- or platform-constrained; container images may differ silently. `pcntl` and sockets expand process/network control if abused. | Keep these only in the telemetry worker image where needed, document runtime capability requirements, and harden container privileges. |
| HIGH | `setup` script runs migration with `--force`. | L50-L56 | If `composer setup` is run in the wrong environment, destructive schema changes can execute without confirmation. | Split local setup from deploy. Replace with `@php artisan migrate --graceful` for local or create `setup:local`; never run `--force` from general setup. |
| MEDIUM | Composer scripts mix Composer, PHP, Artisan, npm install, and npm build in one trust boundary. | L50-L60, L68-L79 | A compromised npm dependency or lifecycle hook can execute during Composer setup/CI, making PHP supply chain and JS supply chain failures cross-contaminate. | Use separate CI jobs for Composer and npm; use `npm ci --ignore-scripts` where possible, then explicitly allow required build steps. |
| MEDIUM | `Composer\Config::disableProcessTimeout` is used in `dev` and `ci:check`. | L59, L69 | Hung commands, compromised scripts, or dependency operations can run indefinitely and tie up CI agents or local dev processes. | Remove timeout disabling from CI; set explicit job-level timeouts in GitHub Actions. |
| LOW | Composer `allow-plugins` enables plugin code execution for `pestphp/pest-plugin` and `php-http/discovery`. | L108-L111 | Composer plugins execute PHP during install/update. If one plugin or its transitive path is compromised, install-time code execution occurs. | Keep plugin list minimal, pin lockfile, run `composer install --no-plugins` in restricted checks where feasible, and require review for plugin changes. |
| INFO | `composer.lock` is present and tracked. | composer.lock tracked | This is positive supply-chain hygiene and makes `composer install` reproducible. | Enforce `composer install --no-interaction --prefer-dist --no-dev --classmap-authoritative` for production. |

Short summary: Good modern dependency set, but production REPL tooling, broad constraints, install-time plugin execution, and risky setup scripts are not ideal for mission-critical telemetry.

### composer.lock

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| INFO | Locked versions include Laravel Framework `v13.8.0`, Octane `v2.17.3`, Reverb `v1.10.1`, Inertia Laravel `v3.1.0`, Predis `v2.4.1`, and PHPUnit `12.5.24`. | package entries | Locking is positive, but only if deploy uses `composer install` and CI runs `composer audit`. | Add `composer audit --locked --no-dev` and full `composer audit --locked` to CI. |
| MEDIUM | Public advisory sources show Reverb has historical advisories; Aikido lists vulnerable ranges below `1.10.1`, while Packagist shows Reverb advisories on older pages. | `laravel/reverb` package entry | Not currently confirmed vulnerable at locked `v1.10.1`, but WebSocket stack must remain aggressively patched. | Keep Reverb updated in controlled maintenance windows; monitor Packagist/GitHub advisories. |
| LOW | PHPUnit package family has historical advisories, but it is dev-only. | `packages-dev` | If dev dependencies leak into production image, test tooling can increase attack surface. | Ensure production Docker build uses `composer install --no-dev` and does not copy dev vendor output. |

Short summary: Lockfile presence is good. Dependency advisory hygiene is acceptable only if `composer audit` is automated.

### routes/api.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | `/api/flights` is public. It is rate-limited but not authenticated or authorization-gated. | L16 | Unauthenticated users can enumerate available flights and telemetry ports, which helps target WebSocket channels and operational telemetry. | Require auth for production: `Route::middleware(['auth:sanctum','throttle:60,1'])->get('/flights', ...)`; or formally classify this endpoint as public and reduce returned fields. |

Short summary: Rate limiting is present, which is good. Authentication/authorization is still missing for mission-critical telemetry metadata.

### routes/web.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| LOW | Root dashboard route is public. | L5 | Any user can load the telemetry dashboard shell. If WebSocket channels remain public, this becomes a direct unauthenticated telemetry viewer. | Require authenticated access for production: `Route::middleware('auth')->inertia('/', 'Welcome')->name('home');`. |

Short summary: Low risk by itself, high risk when combined with public WebSocket channels.

### app/Http/Controllers/Api/FlightController.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | Server-side HTTP request target is built from environment without DNS/IP allow-list validation. | L18-L25 | A compromised deployment config or DNS rebinding against the configured host could turn the service into an SSRF-like internal network pivot. | Add a telemetry endpoint guard that allow-lists hostname, port range, and rejects private/loopback/reserved DNS answers before outbound calls. |
| LOW | Response returns full upstream flight data including `telemetryPort`. | L31-L40 | Telemetry port disclosure assists targeted TCP/WebSocket reconnaissance. | Return only fields required by the UI, or require auth for the full object. |

Short summary: No raw SQL or command injection. Main risk is outbound network trust and metadata exposure.

### app/Domains/Telemetry/Event/TelemetryUpdated.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| HIGH | Broadcast uses public `Channel` instead of `PrivateChannel`. | L22-L26 | Any client able to reach Reverb and guess `flight.{id}` can subscribe to live telemetry. This is the primary broken access control issue. | Use `PrivateChannel('flight.' . $flightId)` and define `Broadcast::channel('flight.{flightId}', ...)` with user/gate checks. |

Short summary: This file is small but security-critical. Public channels are not acceptable for enterprise flight telemetry.

### resources/js/composables/useFlightSocket.ts

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| HIGH | Frontend subscribes to public channel with `echo.channel(channelName)`. | L10-L11 | Confirms telemetry can be consumed without a private-channel auth handshake. | Use `echo.private(channelName)` after backend private-channel authorization is implemented. |
| LOW | Pusher connection state handler is bound but never unbound. | L14-L19, L27-L29 | In long-lived SPA sessions, remounting cards can accumulate listeners and leak state in the browser. | Store handler reference and unbind it in `onBeforeUnmount`. |
| LOW | Uses `any` for connection state. | L14 | Weak typing can hide unsafe states and makes security-relevant connection handling less reliable. | Define a narrow type for allowed state transitions. |

Short summary: Major issue is public WebSocket subscription. Minor cleanup needed for SPA listener lifecycle.

### resources/js/lib/echo.ts

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| HIGH | WebSocket scheme defaults to `http` and allows `ws`. | L16-L19, L27-L35 | If deployed publicly with defaults, telemetry can be intercepted or modified by network attackers. | In production, default to HTTPS/WSS and restrict `enabledTransports` to `['wss']`. |
| MEDIUM | Browser receives Reverb host/port/scheme from runtime config and environment. | L15-L19 | Misconfiguration can point clients to an attacker-controlled WebSocket endpoint. | Validate emitted config server-side against an allow-list and add CSP `connect-src` restrictions. |

Short summary: Good separation of runtime config, but transport security defaults are not enterprise-safe.

### config/reverb.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| HIGH | Reverb `allowed_origins` is wildcard. | L68 | Any website can open browser WebSocket connections to the Reverb server. Combined with public channels, this enables cross-origin telemetry scraping. | Use `REVERB_ALLOWED_ORIGINS` and parse a strict origin allow-list. |
| MEDIUM | Reverb server binds to `0.0.0.0` by default. | L22 | Exposes WebSocket service on all interfaces inside container/network. | Bind behind a reverse proxy or internal interface where possible; enforce firewall/security group restrictions. |

Short summary: WebSocket origin control is the most urgent configuration issue in this file.

### config/browser.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| HIGH | Browser Reverb scheme defaults to `http`. | L8 | Production clients may use plaintext WebSockets unless overridden. | Default to `https` for production and set local overrides explicitly. |
| MEDIUM | Reverb app key is sent to browser. | L5 | This is expected for Pusher/Reverb public client auth, but it must not be treated as secret or authorization. | Pair it with private channels and signed auth endpoints; never rely on app key secrecy. |

Short summary: Safe only if private channels, origin restrictions, and TLS are also enforced.

### config/broadcasting.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | Broadcasting defaults to Reverb and HTTP scheme unless `REVERB_SCHEME=https` is set. | L11, L27-L30 | Internal broadcaster-to-Reverb traffic may be plaintext in production. | Set `REVERB_SCHEME=https` and use TLS between app and Reverb where network boundaries are crossed. |

Short summary: Environment-driven configuration is normal, but production values must be enforced by deployment policy.

### config/session.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | Session encryption defaults to false. | L50 | If session backend is compromised, session data is stored in clear application format. | For enterprise production, set `SESSION_ENCRYPT=true`. |
| MEDIUM | Secure cookie flag depends on unset env value. | L172 | Cookies may be transmitted over HTTP in misconfigured production environments. | Use `env('SESSION_SECURE_COOKIE', app()->isProduction())` and set `SESSION_SECURE_COOKIE=true`. |
| INFO | `http_only` and `same_site=lax` are sane defaults. | L185, L202 | Positive CSRF/XSS mitigation baseline. | Keep enabled; consider `strict` if UX allows. |

Short summary: Laravel defaults are reasonable, but mission-critical systems should force secure/encrypted production sessions.

### resources/views/app.blade.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | Inline script emits runtime config without CSP nonce strategy. | L13-L15 | If any XSS is introduced later, lack of CSP makes script execution easier. | Add CSP middleware with per-request nonce and use `<script nonce="{{ app('csp_nonce') }}">`. |
| INFO | `@json` is used for encoding config. | L14 | Positive: reduces script-breaking injection risk for emitted config. | Keep using framework JSON encoding. |

Short summary: No direct XSS found, but enterprise deployment needs CSP/security headers.

### app/Services/Telemetry/Socket/Client.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| HIGH | Telemetry TCP uses raw plaintext `SWOOLE_SOCK_TCP`. | L56-L63 | Network attacker on the path can observe or tamper with telemetry packets; CRC can be recomputed by an attacker. | Use TLS/mTLS if upstream supports it, or force VPN/IPSec/private link plus egress firewall rules. |
| MEDIUM | Reconnect backoff is not applied after `recvLoop()` exits on read error or peer close. | L94-L97, L102-L120 | Upstream that repeatedly closes connections can trigger aggressive reconnect loops and operational DoS. | Return an exit reason from `recvLoop()` and call `backoffSleep()` for `error` and `closed`. |
| MEDIUM | Subscription sends `flightId` as a string. | L76-L80 | If upstream validates JSON schema strictly, connection may close or behave unexpectedly; less a security issue than integrity/availability risk. | Send integer `flightId => $this->flightId` unless upstream contract requires string. |
| LOW | Debug log includes full subscription JSON. | L90 | Operational metadata can leak into logs. | Remove full subscription payload from logs or hash identifiers. |
| INFO | Broadcast payload sanitizer removes non-printable bytes and non-finite floats. | L144-L185 | Positive mitigation for WebSocket message injection/log encoding issues. | Keep and add tests for nested/edge payloads. |

Short summary: The client validates intervals and sanitizes broadcast data, but transport integrity and reconnection behavior need hardening.

### app/Services/Telemetry/Parser/PacketParser.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | Structurally malformed frames are skipped without producing a `CORRUPTED` event. | L64-L74 | UI may continue showing stale `VALID` state during malformed frame floods. Operators can be misled. | Emit a corrupted parser result when a candidate frame fails end marker or size checks, while still resynchronizing. |
| LOW | No replay protection or monotonic packet-number enforcement. | L94-L101 | Captured valid packets can be replayed to make telemetry appear current if attacker controls the TCP stream. | Track packet numbers/timestamps per flight and flag duplicate/out-of-order packets. |
| INFO | Parser length caps buffer and validates CRC/ranges. | L20-L27, L86-L104 | Positive protection against memory growth and malformed telemetry values. | Add fuzz tests and malformed frame tests. |

Short summary: Good protocol parsing baseline. Needs replay/structural-corruption signaling for safety-critical telemetry.

### app/Support/Telemetry/Crc16Ccitt.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| LOW | CRC16 is not cryptographic integrity. | Full file | CRC catches accidental corruption only. A malicious actor can modify packet data and recompute CRC. | Use transport security, mTLS, HMAC, or signed telemetry envelope if protocol can change. |

Short summary: Correct for protocol validation, insufficient as a security control.

### app/Support/Telemetry/RangeValidator.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| INFO | Telemetry values are range-checked and finite-checked. | Full file | Positive control against malformed telemetry values and NaN/Inf propagation. | Extend validation to packet identity, freshness, and route consistency. |

Short summary: Good low-level validation file.

### app/Services/Telemetry/Socket/CoroutineRunner.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | Crashed client coroutine is logged but not restarted. | L34-L42 | A single flight stream can silently stop while daemon remains alive; Docker will not restart. | Wrap each client coroutine in a supervised restart loop or fail the process for external restart. |
| LOW | Tick exception triggers shutdown. | L48-L59 | Safer than uncontrolled crash, but broad catch can hide root cause unless alerts exist. | Emit structured alert/metric before shutdown. |

Short summary: Needs real supervision semantics for mission-critical long-running workers.

### app/Services/Telemetry/Memory/Monitor.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| INFO | Memory limit check throws controlled exception instead of abrupt exit. | L22-L35 | Positive for graceful Swoole shutdown and Docker restart. | Add metric/alert on repeated memory-limit failures. |

Short summary: Good safety control for Octane/Swoole persistent workers.

### app/Domains/Telemetry/Command/StartTelemetry.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|--------|-----|
| MEDIUM | Fetches upstream flights from env-derived host without allow-list/DNS guard. | L96-L128 | SSRF-like pivot if config or DNS is compromised. | Add endpoint guard before HTTP request and TCP client construction. |
| MEDIUM | Enables Swoole coroutine hooks globally. | L61 | Global hook behavior can affect blocking I/O and long-running state assumptions. | Document hooks and add integration tests for HTTP, Redis, and logging under Octane/Swoole. |
| INFO | Interval validation uses shared client constants. | L33-L42 | Positive consistency with upstream subscription constraints. | Keep and add CLI tests. |

Short summary: Good command structure, but outbound target validation and Swoole lifecycle testing are needed.

### app/Domains/Telemetry/Command/ProbeTelemetry.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | Uses raw plaintext TCP probe connection. | L64-L72 | Probe traffic can leak or be modified on the network path. | Support TLS probe mode or restrict probe to private network/VPN. |
| LOW | Debug/probe command prints telemetry packet data to console. | L118-L130 | Sensitive operational data may be captured in shell history, CI logs, or shared terminals. | Mark command as local/debug only; redact or require `--show-data` flag. |

Short summary: Appropriate for diagnostics, but should be restricted in production containers.

### app/Http/Middleware/HandleInertiaRequests.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| LOW | Shares full authenticated user object if auth is later enabled. | L41-L43 | May expose more user attributes than intended to frontend if model grows. | Share only required fields: `['id' => $user->id, 'name' => $user->name]`. |

Short summary: Low current risk because app has minimal auth surface, but tighten before adding users/roles.

### app/Providers/AppServiceProvider.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| INFO | Production destructive DB commands are prohibited. | L36-L38 | Positive protection against accidental destructive commands. | Keep enabled. |
| INFO | Production password defaults are strong. | L40-L48 | Positive baseline for future auth features. | Keep and add auth tests if user login is introduced. |

Short summary: Positive enterprise hardening file.

### config/app.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| INFO | `APP_ENV` defaults to production and `APP_DEBUG` defaults false. | L29, L42 | Positive secure default. | Ensure deployments cannot override to debug in production. |

Short summary: Secure defaults are acceptable.

### config/telemetry.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | Telemetry host/port are environment-controlled and no allow-list is defined here. | L22-L24 | Misconfiguration can redirect backend to an attacker-controlled or internal endpoint. | Add `allowed_hosts` and `allowed_ports` configuration and enforce before outbound connections. |
| INFO | Memory limit is configurable. | L62 | Positive operational resilience control. | Add minimum/maximum guard to prevent unsafe values. |

Short summary: Configuration is clean but needs explicit trust boundaries.

### config/connection-status.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| LOW | Hyphenated config filename is less conventional for enterprise Laravel standards. | File name | Not a direct security issue; can increase config lookup mistakes. | Rename to `connection_status.php` and update all `config()` lookups. |

Short summary: Naming issue only.

### docker-compose.yml

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| HIGH | Reverb is exposed on host port and uses HTTP scheme in Compose defaults. | L56-L57, L73, L98 | Public or LAN exposure of plaintext WebSocket service can leak telemetry. | Put Reverb behind TLS reverse proxy; use `REVERB_SCHEME=https`; restrict published ports by environment. |
| MEDIUM | Redis has no password or network isolation beyond Compose network. | L1-L9, L27-L29, L91-L92 | A container breakout or network peer can access cache/session/pub-sub data. | Set `REDIS_PASSWORD`, enable protected mode/private network, avoid exposing Redis host ports, and use ACLs where supported. |
| MEDIUM | Services run without Compose hardening options. | Full file | If application compromise occurs, container privileges are broader than needed. | Add `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`, read-only root filesystem where feasible, and non-root user. |
| INFO | `APP_ENV` defaults production and `APP_DEBUG` defaults false. | L19-L20, L60-L61, L84-L85 | Positive secure default. | Keep. |

Short summary: Good secret injection pattern, but network exposure and container hardening need work.

### Dockerfile

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | Runtime image installs build/debug tools and runs as root. | L1-L8, L41-L48 | If compromised, attacker has root in container plus shell tools. | Use multi-stage build, remove build tools from runtime, create non-root user, and `USER app`. |
| LOW | `php artisan key:generate` runs during image build but `.env` is removed later. | L29-L37 | Not directly leaking key, but image build performs app commands with temporary env; can confuse secret lifecycle. | Generate keys only at deployment via secrets; avoid creating `.env` in image builds. |

Short summary: Functional but not hardened for enterprise production.

### docker/ensure-env.sh

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| INFO | Blocks startup if key Reverb/app secrets are missing. | Full file | Positive deployment safety check. | Extend to require `SESSION_SECURE_COOKIE=true` and TLS-related vars for production profiles. |

Short summary: Good guardrail.

### .github/workflows/lint.yml

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| MEDIUM | CI lint workflow runs auto-fix commands instead of check-only commands. | L36-L43 | Validation jobs can mutate files, hide drift, or be abused if write permissions are enabled later. | Use `composer lint:check`, `npm run format:check`, `npm run lint:check`, `npm run types:check`. Set `permissions: contents: read`. |
| LOW | Workflow grants `contents: write`. | L17-L18 | Unneeded write token increases blast radius of compromised workflow. | Change to `contents: read`. |

Short summary: CI should validate, not mutate.

### .github/workflows/tests.yml

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| LOW | Uses `npm i` instead of `npm ci`. | L40-L41 | CI install may drift from lockfile behavior. | Replace with `npm ci`. |
| MEDIUM | No dependency audit steps. | L40-L56 | Known vulnerable packages may pass CI. | Add `composer audit --locked`, `npm audit --omit=dev` or approved SCA tooling. |
| LOW | No explicit job timeout. | Full file | Hung build/test can consume CI resources. | Add `timeout-minutes`. |

Short summary: Basic CI exists but supply-chain gates are missing.

### resources/js/components/FlightCard.vue

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| INFO | Uses Vue interpolation, not raw HTML rendering. | L54-L120 | Positive XSS mitigation for telemetry values. | Keep avoiding `v-html`. |
| LOW | Displays telemetry port to all dashboard users. | L113-L118 | Port disclosure helps reconnaissance if dashboard is public. | Hide port unless user is authorized for diagnostics. |

Short summary: Frontend rendering is safe by default; data exposure depends on auth model.

### resources/js/composables/useFlights.ts

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| LOW | Fetches public `/api/flights` without auth context or CSRF concern because it is GET. | L13-L25 | Metadata exposure if route remains public. | Secure the backend route; frontend will inherit auth/session. |

Short summary: No injection issue; backend access control is the concern.

### app/Models/User.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| INFO | Password and remember token are hidden and password is hashed. | Full file | Positive baseline for future auth. | Keep. |

Short summary: No major issue found.

### tests/Unit/TelemetrySafetyTest.php

| Severity | Finding | Line(s) | Impact | Fix |
|----------|---------|---------|--------|-----|
| INFO | Tests validate interval rejection, broadcast sanitizer, and parser buffer compaction. | Full file | Positive security regression coverage. | Add tests for private channels, malformed structural packets, replay detection, and reconnect backoff. |

Short summary: Useful start, but not enough for mission-critical telemetry.

## Dependency And Supply Chain Notes

| Package | Locked Version | Advisory Signal Checked | Risk Notes |
|---------|----------------|-------------------------|------------|
| `laravel/framework` | `v13.8.0` | Packagist lists historical advisories for the package family; no specific locked-version CVE confirmed in this audit. | Large framework attack surface; keep patched and run `composer audit`. |
| `laravel/reverb` | `v1.10.1` | Packagist/Aikido indicate historical Reverb advisories; Aikido-listed vulnerable ranges are older than the locked version. | WebSocket service is high exposure even without active CVE. |
| `laravel/octane` | `v2.17.3` | Packagist shows no advisories. | Swoole/worker state risks are architectural, not only package-CVE risks. |
| `predis/predis` | `v2.4.1` | Packagist shows no advisories. Latest package line is newer than locked. | Redis channel/cache access must be network- and credential-controlled. |
| `inertiajs/inertia-laravel` | `v3.1.0` | Packagist shows zero advisories. | XSS/CSP remains app responsibility. |
| `laravel/tinker` | `v3.0.2` | Packagist shows zero advisories. | Should not be production dependency in mission-critical runtime. |
| `phpunit/phpunit` | `12.5.24` | Packagist lists historical advisories for package family. | Dev-only; ensure never installed in production. |

Sources checked:

- Packagist Security Advisory Feed: `https://packagist.org/security-advisories/`
- Packagist package pages for Laravel Framework, Reverb, Octane, Tinker, Predis, Inertia Laravel, and PHPUnit
- Aikido package intelligence pages for Reverb and Inertia Laravel

## Final Report

### 1. Top 5 Most Critical Findings

1. **Unauthenticated public WebSocket telemetry channels**  
   `TelemetryUpdated` broadcasts to `Channel('flight.{id}')`, and the frontend uses `echo.channel()`. Any reachable client can subscribe if it knows or guesses a flight ID.

2. **Wildcard Reverb origins**  
   `allowed_origins => ['*']` lets any website initiate browser WebSocket connections to the Reverb server.

3. **Plaintext real-time transport defaults**  
   Browser Reverb defaults to `http/ws`, Compose sets `REVERB_SCHEME=http`, and telemetry TCP uses raw sockets. This exposes telemetry to interception and tampering on untrusted networks.

4. **No outbound DNS/IP allow-list guard for telemetry host**  
   Server-side HTTP/TCP targets are environment-driven. Misconfiguration, compromised env, or DNS rebinding could pivot the telemetry service toward internal networks.

5. **Telemetry integrity lacks replay/tamper resistance**  
   CRC16 and range validation detect accidental corruption and bad values, but there is no cryptographic authenticity, freshness, or replay protection.

### 2. Attack Narrative

An attacker first visits the public dashboard route and observes that flight IDs are predictable from `/api/flights`. Even with rate limiting, the endpoint is unauthenticated and returns enough metadata to identify flight numbers and telemetry ports. The attacker then opens a browser or script from any domain and connects to the Reverb endpoint because `allowed_origins` is wildcard. Since channels are public, they subscribe directly to `flight.1`, `flight.2`, and other guessed channels without a private-channel auth handshake.

From there, the attacker passively collects live telemetry. If the deployment uses default `ws://` transport, a network-positioned attacker can also intercept browser WebSocket traffic. If the attacker has access to the telemetry network path or can influence DNS/configuration, they can target the raw TCP ingestion path. Because the TCP stream uses plaintext and CRC16 is not cryptographic, malicious packets can be modified and CRC recomputed. Without replay protection, previously captured valid packets may also be replayed to make stale telemetry appear current.

Operationally, the attacker can combine malformed TCP behavior with the reconnect/coroutine weaknesses. Repeated peer closes can cause aggressive reconnect loops, and an unhandled client coroutine crash can silently stop one flight stream while the daemon remains alive. The result is a credible telemetry data compromise: unauthorized read access to live data, possible data integrity manipulation on the transport path, and degraded operator confidence in connection status.

### 3. Overall Risk Rating

**HIGH**

Justification: No direct unauthenticated remote code execution was found, and no raw SQL/command injection sink was identified. However, this is mission-critical flight telemetry. Public WebSocket channels, wildcard origins, plaintext real-time transport, and weak telemetry authenticity combine into a high-risk confidentiality and integrity exposure. If this system is used for operational flight-safety decisions over untrusted networks, the effective risk approaches **CRITICAL** until WebSocket auth, TLS/WSS, and telemetry authenticity controls are implemented.

### 4. Prioritized Remediation Roadmap

#### Patch Now

1. Convert `Channel` to `PrivateChannel` and add `routes/channels.php` authorization.
2. Change frontend `echo.channel()` to `echo.private()`.
3. Replace Reverb `allowed_origins => ['*']` with an environment-driven strict allow-list.
4. Enforce HTTPS/WSS in production and set `VITE_REVERB_SCHEME=https`, `REVERB_SCHEME=https`.
5. Require authentication for dashboard and `/api/flights`, or formally classify and minimize public fields.
6. Remove `laravel/tinker` from production dependencies.
7. Stop logging full subscription JSON payloads.

#### Fix This Sprint

1. Add outbound telemetry endpoint guard: host allow-list, port allow-list, DNS private-IP rejection.
2. Add reconnect backoff after TCP read errors and peer closes.
3. Supervise or restart crashed per-flight coroutines.
4. Add parser results for malformed structural packets so UI shows `CORRUPTED`.
5. Add CSP/security headers middleware and nonce inline runtime config.
6. Add CI `composer audit --locked`, `npm audit`, `npm ci`, and check-only lint jobs.
7. Set production session hardening: `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`.

#### Address This Quarter

1. Add cryptographic telemetry authenticity: mTLS, HMAC/signed envelope, or private network with formal threat model.
2. Add replay protection using packet number monotonicity, timestamp freshness, and per-flight stream state.
3. Harden containers: non-root user, dropped capabilities, read-only root filesystem, multi-stage build.
4. Add Redis ACL/password/TLS/private-network controls.
5. Add security monitoring: repeated `ERROR/CLOSED`, parser corruption rates, unexpected origin attempts, and abnormal subscription volume.
6. Add fuzz testing for packet parser and chaos tests for Swoole worker lifecycle.

### 5. Architectural Recommendations For Swoole + WebSocket Systems

1. **Treat Swoole workers as long-lived security contexts**  
   Do not store user, request, or flight-specific state in static properties or singleton services unless it is explicitly reset. Add Octane lifecycle tests for state bleed, DB connection reset, Redis reconnects, and exception paths.

2. **Make WebSocket authorization explicit and short-lived**  
   Use private/presence channels, signed channel auth, strict origin allow-lists, WSS-only production transport, rate limits on auth endpoints, and telemetry-specific gates/policies. The public Reverb app key is not a security boundary.

3. **Separate ingestion trust from presentation trust**  
   TCP ingestion should run in a restricted worker network segment with egress allow-lists, mTLS/VPN where possible, packet authenticity/freshness checks, and no direct browser exposure. The WebSocket presentation tier should only receive sanitized, authorized, minimal telemetry events.
