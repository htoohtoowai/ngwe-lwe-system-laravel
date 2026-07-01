# Flight Telemetry Requirement Review Report

Date: 2026-05-12  
Reviewed requirement: `task_assign_requirement.md`  
Scope: Static review of the repository against the Onenex Flight Telemetry System challenge requirements.

## Overall Verdict

The implementation is largely aligned with the challenge requirements: it has a Laravel REST proxy, a long-running telemetry command, one TCP client per flight, binary packet parsing, CRC and range validation, Reverb broadcasting, and a Vue dashboard with default `WAITING` status.

However, I would mark it as **partially compliant rather than fully compliant** until the reliability and invalid-packet reporting gaps below are addressed. The biggest concerns are that structurally malformed packets are silently discarded without broadcasting `CORRUPTED`, TCP close/read failures reconnect immediately without the documented backoff path, and crashed per-flight coroutines are logged but not restarted.

## Requirement Traceability

| Requirement | Status | Evidence / Notes |
| --- | --- | --- |
| REST API proxy to upstream `/flights` | Pass | `routes/api.php` defines `GET /api/flights`; `app/Http/Controllers/Api/FlightController.php` proxies `https://{host}:4000/flights` with timeout and cache. |
| Telemetry processing service/command | Pass | `app/Domains/Telemetry/Command/StartTelemetry.php` registers `telemetry:start`, fetches flights, and starts one `Client` per flight. |
| Auto-restart on process crash | Pass in Docker / partial locally | `docker-compose.yml` uses `restart: always` for `telemetry`. The command itself does not supervise its own process outside Docker. |
| Auto-restart on configurable memory limit | Pass | `Monitor::check()` exits with code `1` when usage exceeds the configured MB limit; Docker then restarts the service. |
| Auto-reconnect to TCP servers | Partial | Connect and subscription failures use exponential backoff, but read errors and peer closes return to the outer loop without calling `backoffSleep()`. |
| Connect to each flight TCP telemetry server | Pass | `StartTelemetry` creates a `Client` for each flight using `telemetryPort`; `Client` connects to the configured host/port. |
| Send subscription message | Mostly pass | `Client` sends JSON with `type`, `flightId`, and `intervalMs`. Note: `flightId` is sent as a string, while the requirement example uses a number. |
| Parse binary packet protocol | Pass | `PacketParser` uses 36-byte frames, start marker `0x82`, end marker `0x80`, size field `0x24`, big-endian float unpacking, and buffering/resync logic. |
| Handle fragmented/concatenated/misaligned stream | Pass | `PacketParser::feed()` accumulates bytes; `extractNextFrame()` scans for the next start marker and validates the end marker before extracting. |
| CRC-16/CCITT-FALSE validation | Pass | `Crc16Ccitt` implements polynomial `0x1021`, initial `0xFFFF`; parser calculates over the first 31 bytes (`0x00` through `0x1E`). |
| Validate telemetry ranges | Pass | `RangeValidator` enforces altitude, speed, acceleration, thrust, and temperature ranges. |
| Format telemetry to 2 decimals | Pass | `PacketParser` rounds valid metric values to two decimal places before broadcasting. |
| Status `VALID` | Pass | Valid parser results set status to `VALID` and broadcast telemetry data. |
| Status `CORRUPTED` | Partial | CRC and range failures broadcast `CORRUPTED`, but malformed start/end/size framing is skipped silently. |
| Status `ERROR` | Pass | TCP connect/send/read errors call `onError()` and broadcast `ERROR`. |
| Status `CLOSED` | Pass | Peer close calls `onClosed()` and broadcasts `CLOSED`. |
| WebSocket channel per flight | Pass | `TelemetryUpdated` broadcasts on `flight.{id}` public channels; frontend subscribes to `flight.{id}`. |
| Frontend lists all flights | Pass | `useFlights()` fetches `/api/flights`; `Welcome.vue` renders one `FlightCard` per flight. |
| Frontend shows real-time telemetry | Pass | `FlightCard.vue` displays status, route, aircraft model, port, update time, and all telemetry metrics. |
| Frontend default status `WAITING` | Pass | `useFlightSocket()` initializes status as `WAITING` before any WebSocket message. |
| README submission requirements | Pass | README includes setup instructions, architecture, technology choices, assumptions, limitations, API, and commands. |
| Dockerization | Pass | `Dockerfile` and `docker-compose.yml` are present and define app, reverb, telemetry, and redis services. |
| Automated verification | Weak | Tests are still example placeholders; no committed tests cover parser, CRC vectors, range validation, reconnect, memory restart, or broadcasting. |

## Findings

### 1. Malformed structural packets do not emit `CORRUPTED`

Severity: Medium

The requirement says `CORRUPTED` should be used when an invalid packet or invalid data is received, including CRC, altitude, speed, acceleration, thrust, and temperature issues. The parser reports `CORRUPTED` for CRC and range failures, but structural failures are skipped during resynchronization:

- `app/Services/Telemetry/Parser/PacketParser.php:61-68` skips frames when byte 35 is not `0x80` or the size field is not `0x24`.
- Those skipped bytes do not produce a parser result, so `Client::handleResult()` is never called and no `CORRUPTED` status is broadcast.

Impact: during a stream of malformed sizes or bad end markers, the frontend may continue showing the previous status, or remain `WAITING`, instead of reflecting `CORRUPTED`.

Recommendation: when a candidate frame is long enough to validate and fails structural checks, emit a corrupted result before resynchronizing. Keep the current scan/skip behavior, but surface the invalid packet event to the socket client.

### 2. Reconnect backoff is not applied after read errors or peer closes

Severity: Medium

The client has an exponential backoff table, but it is only used for connection and subscription-send failures:

- `app/Services/Telemetry/Socket/Client.php:54-57` backs off after connect failure.
- `app/Services/Telemetry/Socket/Client.php:73-77` backs off after subscription send failure.
- `app/Services/Telemetry/Socket/Client.php:97-110` returns from `recvLoop()` on read error, timeout, or peer close.
- `app/Services/Telemetry/Socket/Client.php:86-89` closes the socket and immediately loops again.

Impact: if the upstream server repeatedly accepts and closes connections, the client can reconnect in a tight loop. That is less graceful than the challenge tip for exponential backoff and can produce noisy logs or extra load.

Recommendation: have `recvLoop()` communicate why it exited, then call `backoffSleep()` after `ERROR` and `CLOSED` outcomes before reconnecting.

### 3. A crashed per-flight coroutine is logged but not restarted

Severity: Medium

`CoroutineRunner` catches exceptions from each client coroutine and logs them:

- `app/Services/Telemetry/Socket/CoroutineRunner.php:33-41`

After logging, that flight's coroutine exits. The main telemetry process continues, so Docker will not restart the container. This can leave one flight permanently stale if an unexpected exception escapes the client loop.

Recommendation: wrap each client coroutine in a restart loop with a delay, or intentionally fail the whole telemetry process so the configured process supervisor restarts it.

### 4. Subscription sends `flightId` as a string

Severity: Low

The requirement example sends:

```json
{
  "type": "subscribe",
  "flightId": 1,
  "intervalMs": 5000
}
```

The implementation sends `(string) $this->flightId`:

- `app/Services/Telemetry/Socket/Client.php:67-71`
- `app/Domains/Telemetry/Command/ProbeTelemetry.php` does the same in its subscription payload.

The REST flight list shows IDs as strings, so this may work with the upstream server. If the telemetry server validates JSON types strictly, it may close the connection.

Recommendation: send `flightId` as an integer in the telemetry subscription unless manual probing confirms that strings are required.

### 5. Critical behavior lacks automated tests

Severity: Low to Medium

The repository currently has only placeholder tests:

- `tests/Feature/ExampleTest.php`
- `tests/Unit/ExampleTest.php`

The challenge specifically calls out packet corruption, partial packets, re-synchronization, CRC validation, range validation, connection failures, and memory restart behavior. These are exactly the areas most likely to regress.

Recommendation: add focused tests for:

- CRC known-good and known-bad vectors.
- Parser handling of fragmented packets.
- Parser handling of concatenated packets.
- Parser re-synchronization when `0x82` appears inside payload data.
- Invalid end marker and invalid size field producing `CORRUPTED`.
- Out-of-range telemetry values.
- Client behavior for peer close and read errors.
- Memory monitor exit behavior, ideally with an injectable exit strategy for testability.

## Verification Attempted

Runtime verification could not be completed in this local environment:

- `vendor/` is not present.
- `node_modules/` is not present.
- `php` is not available on PATH.
- `npm` is not available on PATH.

Commands attempted:

```text
php -v
npm run types:check
npm run build
```

All PHP/npm-based checks were blocked by missing local tooling. `node -v` is available through the bundled Codex runtime, but npm is not.

## Final Assessment

This is a strong challenge implementation and should be close to demo-ready in the intended Docker environment. The main production-readiness gap is not broad missing functionality; it is the behavior around bad streams and long-running reliability edge cases. Fixing malformed-packet status reporting, reconnect backoff after closures/read failures, and per-flight coroutine restarts would bring the implementation much closer to full compliance with the requirement document.
