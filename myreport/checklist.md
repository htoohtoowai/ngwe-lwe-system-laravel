# Security Audit Checklist And Code Review Report

Role perspective: Senior Cyber Security Architect review covering Web Security, Pentesting, and enterprise infrastructure controls.

Reviewed project: `C:\laragon\www\test-TCP`  
Reference style context: `D:\laragon\www\tamron-forum`  
Review date: 2026-05-12  
Primary standard mapping: [OWASP Top 10:2025](https://owasp.org/Top10/2025/0x00_2025-Introduction/) and targeted review criteria requested by the user.

## Executive Summary

The project is cleaner and more modern than the legacy `tamron-forum` structure, and it has several enterprise-positive controls: Laravel config conventions, no committed `.env`, no obvious SQL injection path, no shell-command injection path, typed telemetry code, Dockerized services, and CI workflows.

However, it is **not enterprise/corporate security-ready yet**. The highest-risk issues are around **public WebSocket authorization**, **wildcard Reverb origins**, **plaintext WebSocket/TCP transport**, and **unvalidated outbound network targets**. These are especially important because the application is a real-time telemetry bridge that connects server-side infrastructure to external TCP and WebSocket clients.

## Summary Table

| ID | Finding | OWASP 2025 Mapping | Risk | Status |
| --- | --- | --- | --- | --- |
| SEC-01 | Public Reverb channels expose telemetry to unauthenticated clients | A01 Broken Access Control | High | Needs remediation |
| SEC-02 | Reverb allows wildcard origins | A01 Broken Access Control / A02 Security Misconfiguration | High | Needs remediation |
| SEC-03 | WebSocket defaults allow plaintext `ws://` transport | A02 Security Misconfiguration / A04 Cryptographic Failures | High | Needs remediation for production |
| SEC-04 | Telemetry TCP uses plaintext sockets | A04 Cryptographic Failures / A06 Insecure Design | Medium | Needs compensating control |
| SEC-05 | Outbound host is env-driven without DNS/IP allow-list guard | A01 Broken Access Control / SSRF / A06 Insecure Design | Medium | Needs remediation |
| SEC-06 | API endpoint has no authentication or rate limiting | A01 Broken Access Control / A06 Insecure Design | Medium | Needs remediation |
| SEC-07 | Session cookie security is environment-dependent and not enforced | A07 Authentication Failures / A04 Cryptographic Failures | Medium | Needs production hardening |
| SEC-08 | Inline browser config lacks CSP/nonced script strategy | A05 Injection / A02 Security Misconfiguration | Medium | Needs remediation |
| SEC-09 | Container runs as root and exposes broad runtime tooling | A02 Security Misconfiguration / A03 Supply Chain Failures | Medium | Needs hardening |
| SEC-10 | Telemetry connection reconnect loop can hammer upstream after close/read errors | A10 Mishandling Exceptional Conditions | Medium | Needs remediation |
| SEC-11 | Coroutine crash logs but does not restart the affected flight client | A10 Mishandling Exceptional Conditions | Medium | Needs remediation |
| SEC-12 | Operational telemetry identifiers can leak in logs and broadcasts | A04 Cryptographic Failures / A09 Logging & Alerting Failures | Low-Medium | Needs data classification |
| SEC-13 | CRC16 is correctly implemented but is not cryptographic integrity | A04 Cryptographic Failures | Low-Medium | Needs documentation/compensating control |
| SEC-14 | CI lint workflow uses auto-fix instead of check-only mode | A08 Software/Data Integrity Failures | Low-Medium | Needs remediation |

## Positive Security Observations

- No committed `.env` file was found in `git ls-files`.
- `.env.example` keeps sensitive values blank.
- Docker Compose now reads `APP_KEY` and Reverb credentials from environment variables instead of hardcoding them.
- `docker/ensure-env.sh` blocks service startup when critical secrets are missing.
- No SQL query builder raw SQL sinks were found in the current code.
- No direct command execution sinks such as `shell_exec`, `system`, `passthru`, or `proc_open` were found.
- Vue template interpolation is used instead of `v-html`, reducing frontend XSS exposure.
- Laravel session serialization remains JSON, which is safer than PHP object serialization if `APP_KEY` is compromised.
- `AppServiceProvider` prohibits destructive DB commands in production.
- Password defaults are strengthened in production.

---

## Detailed Findings

## SEC-01: Public Reverb Channels Expose Telemetry To Unauthenticated Clients

**Risk Level:** High

**Vulnerability Description:**  
`TelemetryUpdated` broadcasts on a public channel:

```php
new Channel('flight.' . $this->flightId)
```

The frontend subscribes directly to `flight.{id}`. Because the channel is public, any client that can reach the Reverb endpoint and knows or guesses the channel name can subscribe to live telemetry data. The Reverb app key is intentionally exposed to the browser, so it must not be treated as an authorization boundary.

**Evidence:**

- `app/Domains/Telemetry/Event/TelemetryUpdated.php`
- `resources/js/composables/useFlightSocket.ts`
- `resources/js/lib/echo.ts`

**OWASP Mapping:** A01 Broken Access Control

**Secure Code Example:**

Use private channels and authorize access server-side.

```php
// app/Events/TelemetryUpdated.php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class TelemetryUpdated implements ShouldBroadcast
{
    public function __construct(
        public readonly int $flightId,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('flight.' . $this->flightId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TelemetryUpdated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
```

```php
// routes/channels.php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('flight.{flightId}', function ($user, int $flightId): bool {
    return $user !== null
        && $user->can('viewFlightTelemetry', $flightId);
});
```

```ts
// resources/js/composables/useFlightSocket.ts

const channel = echo.private(`flight.${flightId}`);

channel.listen('.TelemetryUpdated', (payload: TelemetryPayload) => {
    status.value = payload.status || 'WAITING';
    data.value = payload.data || null;
    lastUpdate.value = payload.timestamp || Date.now();
});
```

---

## SEC-02: Reverb Allows Wildcard Origins

**Risk Level:** High

**Vulnerability Description:**  
`config/reverb.php` uses:

```php
'allowed_origins' => ['*'],
```

This permits browsers from any origin to connect to the WebSocket server. Combined with public channels, this becomes a practical data exposure issue.

**Evidence:**

- `config/reverb.php`

**OWASP Mapping:** A01 Broken Access Control, A02 Security Misconfiguration

**Secure Code Example:**

```php
// config/reverb.php

'allowed_origins' => array_filter(array_map(
    'trim',
    explode(',', env('REVERB_ALLOWED_ORIGINS', 'https://telemetry.example.com'))
)),
```

```env
REVERB_ALLOWED_ORIGINS=https://telemetry.example.com,https://ops.example.com
```

For Laravel Reverb production deployment, keep Reverb behind a reverse proxy that also validates `Origin` and terminates TLS.

---

## SEC-03: WebSocket Defaults Allow Plaintext `ws://` Transport

**Risk Level:** High for production, Medium for local-only review

**Vulnerability Description:**  
The browser config defaults to HTTP/WebSocket transport:

```php
'reverbScheme' => env('VITE_REVERB_SCHEME', 'http'),
```

The frontend enables both `ws` and `wss`. If deployed publicly with the default `http` value, telemetry can be intercepted or modified by a man-in-the-middle attacker.

**Evidence:**

- `config/browser.php`
- `resources/js/lib/echo.ts`
- `docker-compose.yml`

**OWASP Mapping:** A02 Security Misconfiguration, A04 Cryptographic Failures

**Secure Code Example:**

```php
// config/browser.php

return [
    'flightTelemetry' => [
        'reverbAppKey' => env('VITE_REVERB_APP_KEY', env('REVERB_APP_KEY')),
        'reverbHost' => env('VITE_REVERB_HOST', 'telemetry.example.com'),
        'reverbPort' => (int) env('VITE_REVERB_PORT', 443),
        'reverbScheme' => env('VITE_REVERB_SCHEME', 'https'),
    ],
];
```

```ts
// resources/js/lib/echo.ts

const isSecure = reverbScheme === 'https';

const echo = new Echo({
    broadcaster: 'reverb',
    key: reverbAppKey,
    wsHost: reverbHost,
    wsPort: isSecure ? undefined : reverbPort,
    wssPort: reverbPort,
    forceTLS: isSecure,
    enabledTransports: isSecure ? ['wss'] : ['ws'],
    client: new Pusher(reverbAppKey, {
        wsHost: reverbHost,
        wsPort: isSecure ? undefined : reverbPort,
        wssPort: reverbPort,
        forceTLS: isSecure,
        enabledTransports: isSecure ? ['wss'] : ['ws'],
        cluster: '',
    }),
});
```

Production `.env`:

```env
VITE_REVERB_SCHEME=https
VITE_REVERB_PORT=443
REVERB_SCHEME=https
```

---

## SEC-04: Telemetry TCP Uses Plaintext Sockets

**Risk Level:** Medium

**Vulnerability Description:**  
The telemetry client connects with raw TCP:

```php
$sock = new SwooleClient(SWOOLE_SOCK_TCP);
$sock->connect($this->host, $this->port, self::CONNECT_TIMEOUT);
```

The probe command also uses `stream_socket_client("tcp://...")`. This exposes telemetry packets to passive sniffing and active modification on the network path. CRC validates accidental corruption, not malicious tampering.

**Evidence:**

- `app/Services/Telemetry/Socket/Client.php`
- `app/Domains/Telemetry/Command/ProbeTelemetry.php`

**OWASP Mapping:** A04 Cryptographic Failures, A06 Insecure Design

**Secure Code Example:**

If the upstream supports TLS:

```php
use Swoole\Coroutine\Client as SwooleClient;

$sock = new SwooleClient(SWOOLE_SOCK_TCP | SWOOLE_SSL);
$sock->set([
    'ssl_verify_peer' => true,
    'ssl_allow_self_signed' => false,
    'ssl_host_name' => $this->host,
    'open_eof_check' => false,
    'package_max_length' => 8 * 1024,
    'socket_buffer_size' => 64 * 1024,
]);

if (! $sock->connect($this->host, $this->port, self::CONNECT_TIMEOUT)) {
    $this->onError("tls connect failed: errCode={$sock->errCode}");
    $this->backoffSleep();
    continue;
}
```

If upstream TLS is unavailable, use a network-level compensating control:

```text
Browser -> HTTPS/WSS -> Reverse Proxy -> Laravel/Reverb
Telemetry daemon -> VPN/IPSec/private link -> Telemetry TCP servers
```

---

## SEC-05: Outbound Host Is Env-Driven Without DNS/IP Allow-List Guard

**Risk Level:** Medium

**Vulnerability Description:**  
The backend builds outbound HTTP and TCP targets from environment config:

```php
config('telemetry.host')
config('telemetry.api_port')
```

This is not directly user-controlled, so it is not a classic request-based SSRF. However, from an enterprise infrastructure perspective, a compromised environment variable, bad deployment config, or DNS rebinding against the configured hostname could turn the service into an internal network pivot.

**Evidence:**

- `app/Http/Controllers/Api/FlightController.php`
- `app/Domains/Telemetry/Command/StartTelemetry.php`
- `app/Domains/Telemetry/Command/ProbeTelemetry.php`
- `config/telemetry.php`

**OWASP Mapping:** A01 Broken Access Control, SSRF, A06 Insecure Design

**Secure Code Example:**

Create a guarded resolver.

```php
// app/Support/Network/TelemetryEndpointGuard.php

namespace App\Support\Network;

use InvalidArgumentException;

final class TelemetryEndpointGuard
{
    private const ALLOWED_HOSTS = [
        'fts.onenex.dev',
    ];

    public static function assertAllowed(string $host, int $port): void
    {
        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new InvalidArgumentException('Telemetry host is not allow-listed.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Telemetry port is outside the valid TCP range.');
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA);
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip !== null && self::isPrivateOrLoopback($ip)) {
                throw new InvalidArgumentException('Telemetry host resolved to a private or loopback address.');
            }
        }
    }

    private static function isPrivateOrLoopback(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
```

Use it before all outbound calls:

```php
TelemetryEndpointGuard::assertAllowed(
    (string) config('telemetry.host'),
    (int) config('telemetry.api_port'),
);
```

---

## SEC-06: API Endpoint Has No Authentication Or Rate Limiting

**Risk Level:** Medium

**Vulnerability Description:**  
`GET /api/flights` is publicly reachable and has no throttle middleware. Even if the upstream data is non-sensitive, the endpoint can be abused for scraping, traffic amplification, or noisy upstream dependency usage.

**Evidence:**

- `routes/api.php`
- `app/Http/Controllers/Api/FlightController.php`

**OWASP Mapping:** A01 Broken Access Control, A06 Insecure Design

**Secure Code Example:**

```php
// routes/api.php

use App\Http\Controllers\Api\FlightController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:60,1'])
    ->get('/flights', [FlightController::class, 'index']);
```

If this must remain public for a demo, still rate limit:

```php
Route::middleware('throttle:30,1')
    ->get('/flights', [FlightController::class, 'index']);
```

---

## SEC-07: Session Cookie Security Is Environment-Dependent And Not Enforced

**Risk Level:** Medium

**Vulnerability Description:**  
Session cookie hardening depends on environment variables:

```php
'secure' => env('SESSION_SECURE_COOKIE'),
'same_site' => env('SESSION_SAME_SITE', 'lax'),
```

For production, `secure` should be true when HTTPS is used. If left unset, cookies may be sent over plaintext HTTP in misconfigured deployments.

**Evidence:**

- `config/session.php`

**OWASP Mapping:** A07 Authentication Failures, A04 Cryptographic Failures

**Secure Code Example:**

```php
// config/session.php

'secure' => env('SESSION_SECURE_COOKIE', app()->isProduction()),

'http_only' => env('SESSION_HTTP_ONLY', true),

'same_site' => env('SESSION_SAME_SITE', 'lax'),
```

Production `.env`:

```env
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
```

---

## SEC-08: Inline Browser Config Lacks CSP/Nonce Strategy

**Risk Level:** Medium

**Vulnerability Description:**  
The Blade layout includes an inline script:

```blade
<script>
    window.flightTelemetryConfig = @json(config('browser.flightTelemetry'));
</script>
```

`@json` is safe for JSON encoding, and Vue interpolation reduces XSS risk. However, enterprise deployments should use a Content Security Policy. Inline scripts require either a nonce or should be moved to a JSON script tag with a nonce-aware CSP.

**Evidence:**

- `resources/views/app.blade.php`

**OWASP Mapping:** A05 Injection, A02 Security Misconfiguration

**Secure Code Example:**

Create a security headers middleware.

```php
// app/Http/Middleware/SecurityHeaders.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        app()->instance('csp_nonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' 'nonce-{$nonce}'; ".
            "style-src 'self' 'unsafe-inline'; ".
            "connect-src 'self' wss: https:; ".
            "img-src 'self' data:; ".
            "frame-ancestors 'none'; ".
            "base-uri 'self';"
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }
}
```

Use the nonce in Blade:

```blade
<script nonce="{{ app('csp_nonce') }}">
    window.flightTelemetryConfig = @json(config('browser.flightTelemetry'));
</script>
```

Register middleware:

```php
// bootstrap/app.php

$middleware->web(append: [
    \App\Http\Middleware\SecurityHeaders::class,
    HandleInertiaRequests::class,
    AddLinkHeadersForPreloadedAssets::class,
]);
```

---

## SEC-09: Container Runs As Root And Keeps Broad Runtime Tooling

**Risk Level:** Medium

**Vulnerability Description:**  
The Docker image installs build/runtime tools and does not switch to a non-root user. If the application or Swoole process is compromised, the attacker lands as root inside the container.

**Evidence:**

- `Dockerfile`
- `docker-compose.yml`

**OWASP Mapping:** A02 Security Misconfiguration, A03 Software Supply Chain Failures

**Secure Code Example:**

Use a non-root user and restrict container capabilities.

```dockerfile
FROM phpswoole/swoole:php8.3-alpine

RUN apk add --no-cache bash curl unzip nodejs npm \
    && docker-php-ext-install pcntl \
    && addgroup -S app \
    && adduser -S app -G app

WORKDIR /app

# install dependencies and build assets...

RUN chown -R app:app /app/storage /app/bootstrap/cache

USER app

EXPOSE 8000

CMD ["sh", "-lc", "/app/docker/ensure-env.sh && exec php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000 --workers=2"]
```

Compose hardening:

```yaml
services:
  app:
    read_only: true
    cap_drop:
      - ALL
    security_opt:
      - no-new-privileges:true
    tmpfs:
      - /tmp
    volumes:
      - app-storage:/app/storage
```

---

## SEC-10: Reconnect Loop Can Hammer Upstream After Close Or Read Error

**Risk Level:** Medium

**Vulnerability Description:**  
The client backs off after connection and subscription failures, but after `recvLoop()` exits due to peer close or read error, the outer loop closes the socket and reconnects immediately.

**Evidence:**

- `app/Services/Telemetry/Socket/Client.php`

**OWASP Mapping:** A10 Mishandling Exceptional Conditions

**Secure Code Example:**

```php
private function recvLoop(SwooleClient $sock): string
{
    while ($this->shouldRun) {
        $chunk = $sock->recv(self::RECV_TIMEOUT);

        if ($chunk === false) {
            $this->onError("recv error: errCode={$sock->errCode}");

            return 'error';
        }

        if ($chunk === '') {
            $this->onClosed('peer closed');

            return 'closed';
        }

        $this->parser->feed($chunk);

        foreach ($this->parser->drain() as $result) {
            $this->handleResult($result);
        }
    }

    return 'stopped';
}
```

```php
$exitReason = $this->recvLoop($sock);
$sock->close();

if (in_array($exitReason, ['error', 'closed'], true)) {
    $this->backoffSleep();
}
```

---

## SEC-11: Coroutine Crash Logs But Does Not Restart Affected Flight Client

**Risk Level:** Medium

**Vulnerability Description:**  
If a per-flight coroutine throws, the runner logs the exception and lets that coroutine die. The main process remains alive, so Docker does not restart the service. One flight can silently stop receiving telemetry.

**Evidence:**

- `app/Services/Telemetry/Socket/CoroutineRunner.php`

**OWASP Mapping:** A10 Mishandling Exceptional Conditions

**Secure Code Example:**

```php
Coroutine::create(function () use ($client) {
    while (! $this->stopped) {
        try {
            $client->run();
        } catch (\Throwable $e) {
            Log::error('client coroutine crashed; restarting', [
                'flight' => $client->flightId,
                'error' => $e->getMessage(),
            ]);
        }

        Coroutine::sleep(5.0);
    }
});
```

Alternative enterprise pattern: fail the process intentionally and let Kubernetes/systemd/Docker restart the whole telemetry daemon.

---

## SEC-12: Operational Telemetry Identifiers Can Leak In Logs And Broadcasts

**Risk Level:** Low-Medium

**Vulnerability Description:**  
Logs include flight IDs, host, port, reconnect attempts, and subscription metadata. Broadcast payloads include flight number and live telemetry. This may not be PII, but in an enterprise environment it is operationally sensitive data.

**Evidence:**

- `app/Services/Telemetry/Socket/Client.php`
- `app/Domains/Telemetry/Command/StartTelemetry.php`
- `README.md`

**OWASP Mapping:** A04 Cryptographic Failures, A09 Security Logging & Alerting Failures

**Secure Code Example:**

```php
private function logConnectionEvent(string $message, array $context = []): void
{
    Log::info($message, [
        'flight_hash' => hash_hmac(
            'sha256',
            (string) $this->flightId,
            (string) config('app.key')
        ),
        'event' => $context['event'] ?? null,
    ]);
}
```

For broadcast privacy, only expose fields needed by the UI and require authorization via private channels.

---

## SEC-13: CRC16 Is Correct But Not Cryptographic Integrity

**Risk Level:** Low-Medium

**Vulnerability Description:**  
The project implements CRC-16/CCITT-FALSE for protocol validation. This catches accidental corruption but is not an authentication or tamper-proofing mechanism. An attacker with network position can modify the payload and recompute CRC.

**Evidence:**

- `app/Support/Telemetry/Crc16Ccitt.php`
- `app/Services/Telemetry/Parser/PacketParser.php`

**OWASP Mapping:** A04 Cryptographic Failures

**Secure Code Example:**

If protocol changes are allowed, add an HMAC field or signed envelope.

```php
$expected = hash_hmac(
    'sha256',
    $packetBytesWithoutSignature,
    config('telemetry.signing_key'),
    true
);

if (! hash_equals($expected, $receivedSignature)) {
    return ['outcome' => 'corrupted', 'data' => null];
}
```

If the protocol cannot change, use TLS, mTLS, VPN, or private networking to provide transport-level integrity.

---

## SEC-14: CI Lint Workflow Uses Auto-Fix Instead Of Check-Only Mode

**Risk Level:** Low-Medium

**Vulnerability Description:**  
The lint workflow runs:

```yaml
run: composer lint
run: npm run format
run: npm run lint
```

Those scripts can modify source files. Enterprise CI should normally fail on drift, not mutate code in the validation job.

**Evidence:**

- `.github/workflows/lint.yml`
- `composer.json`
- `package.json`

**OWASP Mapping:** A08 Software or Data Integrity Failures

**Secure Code Example:**

```yaml
name: linter

on:
  pull_request:
    branches: [develop, main, master]
  push:
    branches: [develop, main, master]

permissions:
  contents: read

jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Setup Node
        uses: actions/setup-node@v6
        with:
          node-version: '22'
          cache: npm

      - name: Install Dependencies
        run: |
          composer install --no-ansi --no-interaction --no-scripts --no-progress --prefer-dist
          npm ci

      - name: Check PHP Style
        run: composer lint:check

      - name: Check Frontend Format
        run: npm run format:check

      - name: Check Frontend Lint
        run: npm run lint:check

      - name: Type Check
        run: npm run types:check
```

---

## OWASP Top 10 Requested Mapping

| Category Requested | Result |
| --- | --- |
| SQL Injection | No raw SQL sinks were found in current project code. Risk currently low. |
| XSS | Vue interpolation is safe by default and no `v-html` was found. Missing CSP remains a medium hardening gap. |
| Command Injection | No direct command execution sinks were found in app code. Docker command strings are static. |
| Broken Access Control | High risk due public WebSocket channels, wildcard origins, and unauthenticated `/api/flights`. |
| SSRF | Medium risk from env-driven outbound HTTP/TCP targets without DNS/IP allow-list guard. Not request-direct SSRF. |
| Insecure Socket Handling | Medium to high risk from plaintext TCP and plaintext WebSocket defaults. |
| TLS/SSL | HTTPS is used for upstream REST by default, but WebSocket and TCP paths need production TLS/compensating controls. |
| DNS Rebinding | Medium risk for the telemetry host if deployment config or DNS is compromised. Add resolver guard and private IP rejection. |
| Race Conditions | No direct data race with financial/security impact found. Availability race exists around coroutine crash/restart lifecycle. |
| Session Management | Laravel defaults are reasonable, but production should enforce secure cookies and HTTPS. |
| JWT Validation | Not applicable. No JWT usage was found in current code. If JWT is introduced, validate issuer, audience, algorithm allow-list, expiry, and key rotation. |
| PII / Privacy | No obvious PII leak. Flight IDs, flight numbers, host/port, and telemetry are operationally sensitive and should be classified. |
| Hardcoded Credentials | No committed runtime secrets found. `.env.example` is blank. Runtime Reverb/app secrets are env-driven. |
| Weak Cryptography | CRC16 is protocol validation, not security crypto. Use TLS/mTLS/HMAC for trust boundaries. |

## Enterprise Security Checklist

### Access Control

- [ ] Convert telemetry channels from public `Channel` to `PrivateChannel`.
- [ ] Add `routes/channels.php` authorization logic.
- [ ] Add authentication or documented public-data approval for `/api/flights`.
- [ ] Add rate limiting to `/api/flights`.
- [ ] Restrict Reverb `allowed_origins`.

### Network And CCIE Infrastructure Controls

- [ ] Enforce WSS/HTTPS for browser-to-Reverb traffic in production.
- [ ] Put Reverb behind a TLS-terminating reverse proxy.
- [ ] Use TLS/mTLS for telemetry TCP if upstream supports it.
- [ ] If raw TCP must remain, require VPN/IPSec/private network path.
- [ ] Add outbound hostname allow-listing.
- [ ] Reject private, loopback, link-local, multicast, and reserved DNS resolutions.
- [ ] Validate telemetry port ranges.
- [ ] Add egress firewall rules so the telemetry service can only reach approved hosts and ports.

### Application Security

- [ ] Add CSP and security headers middleware.
- [ ] Remove or nonce inline scripts.
- [ ] Keep Vue templates free of `v-html`.
- [ ] Keep raw SQL and command execution out of request paths.
- [ ] Add structured error handling for upstream REST failures.

### Authentication And Session

- [ ] Enforce `SESSION_SECURE_COOKIE=true` in production.
- [ ] Keep `SESSION_HTTP_ONLY=true`.
- [ ] Keep `SESSION_SAME_SITE=lax` or stricter.
- [ ] Enable `SESSION_ENCRYPT=true` for sensitive enterprise deployments.
- [ ] If JWT is added later, validate `iss`, `aud`, `exp`, `nbf`, `alg`, `kid`, and key rotation.

### Data Privacy And Logging

- [ ] Classify telemetry data and flight numbers as operationally sensitive.
- [ ] Avoid logging full subscription payloads.
- [ ] Hash or tokenize flight identifiers in logs where possible.
- [ ] Define retention for telemetry logs.
- [ ] Ensure logs do not include Reverb secrets, app keys, bearer tokens, or session IDs.

### Container And Supply Chain

- [ ] Run containers as non-root.
- [ ] Drop Linux capabilities in Compose/Kubernetes.
- [ ] Use read-only root filesystem where possible.
- [ ] Use `npm ci` in CI instead of `npm i`.
- [ ] Add dependency vulnerability scanning.
- [ ] Use check-only CI lint commands.
- [ ] Pin production base image versions and monitor CVEs.
- [ ] Consider multi-stage Docker build to avoid build tools in runtime image.

### Resilience And Exceptional Conditions

- [ ] Add reconnect backoff after read errors and peer close.
- [ ] Restart crashed per-flight client coroutines.
- [ ] Add circuit breaker for repeated upstream REST failures.
- [ ] Add alerting for repeated `ERROR` or `CLOSED` statuses.
- [ ] Add parser tests for malformed frame structure producing `CORRUPTED`.

## Comparison Against `tamron-forum`

The `tamron-forum` project has a more traditional enterprise Laravel layering style with Controllers, Services, Repositories, Requests, Resources, Filters, and Events. That structure is familiar for corporate Laravel teams, but the reference project also contains legacy weaknesses: mixed route syntax, typoed class names, inconsistent namespaces, older framework generation, and large public asset surface.

The current telemetry project is better as a security baseline because it is smaller, more focused, uses newer Laravel/Vue tooling, has Dockerized runtime, and avoids obvious raw SQL or command execution sinks. For corporate standard, use the current project as the base and adopt only the useful Laravel-standard locations from `tamron-forum`:

```text
app/Console/Commands
app/Events
app/Http/Controllers
app/Services/Telemetry
app/Support/Telemetry
```

Avoid copying the entire legacy flat repository/service/filter style unless the organization already mandates it.

## Final Security Verdict

Current security maturity: **7/10**

Enterprise target after remediation: **9/10**

Priority order:

1. Lock down Reverb channels and allowed origins.
2. Enforce HTTPS/WSS and network-level protection for telemetry TCP.
3. Add outbound target allow-list and DNS/private-IP guard.
4. Add auth/rate limiting to API endpoints.
5. Add CSP/security headers.
6. Harden Docker runtime.
7. Improve reconnect/coroutine recovery.
8. Convert CI to check-only validation and add dependency scanning.
