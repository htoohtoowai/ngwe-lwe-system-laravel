# Flight Telemetry System Challenge - Fullstack Developer (Onenex)

## 🎯 Challenge Overview

Build a real-time flight monitoring system that connects to our Flight Telemetry System, processes binary data packets, and displays live flight information in a dashboard.

- **Host:** fts.onenex.dev
- **Visual Demo:** [https://fts-demo.onenex.dev](https://fts-demo.onenex.dev/)

---

## 🏗️ Flight Telemetry System

### REST API

`GET https://[host]:4000/flights`

```json
[
  {
    "id": "1",
    "model": "Boeing 737",
    "flightNumber": "ONX101",
    "origin": "SIN",
    "destination": "BKK",
    "telemetryPort": 3001
  }
]
```

### Telemetry Servers

Each flight streams binary telemetry data through its dedicated TCP port (`telemetryPort` from the Flights List Endpoint).

> ⚠️ **Important:** These servers simulate real-world conditions:
> - They occasionally send corrupted packets (wrong CRC, out-of-range values, or malformed sizes).
> - They may close connections unexpectedly.
> - Data streams can contain partial packets or misaligned bytes requiring re-synchronization.
> - Your code must handle these scenarios gracefully.

### Subscribing to a Flight's Data

1. Connect to the flight's telemetry port via TCP (`[host]:[telemetryPort]`).
2. Send this JSON subscription message:

```json
{
  "type": "subscribe",
  "flightId": 1,
  "intervalMs": 5000
}
```

| Field | Description |
|-------|-------------|
| `flightId` | The flight ID from the REST API |
| `intervalMs` | How often you want data (Min: 100ms, Max: 10000ms). Invalid interval closes the connection immediately. |

---

## 📋 Requirements

### Backend

- Create a REST API Proxy to the Flights List Endpoint.
- Build a Telemetry Processing Service/Command that:

#### 1. Runs Reliably 24/7
- Auto-restart if it crashes.
- Auto-restart if memory exceeds a configurable limit (e.g., 20MB).
- Auto-reconnect to TCP servers if they are shut down or connection is closed.

#### 2. Processes Telemetry Data
- Connect to each flight's TCP telemetry server.
- Subscribe to receive data packets.
- Parse the binary packets (see Binary Packet Protocol below).
- Validate data and format to 2 decimal places.

| Field | Valid Range | Unit |
|-------|-------------|------|
| Altitude | 9000 – 12000 | m |
| Speed | 220 – 260 | m/s |
| Acceleration | -2 to +2 | m/s² |
| Thrust | 0 – 200000 | N |
| Temperature | -50 to +50 | °C |

- Define Connection Status between your Backend and the Telemetry Server for each flight.

| Status | Condition |
|--------|-----------|
| `VALID` | A valid packet was received |
| `CORRUPTED` | Invalid packet or out-of-range data (CRC, Altitude, Speed, Acceleration, Thrust, or Temperature) |
| `ERROR` | TCP process encounters any other error (e.g., `tcpClient.on('error')`) or couldn't connect to the TCP server |
| `CLOSED` | TCP connection is closed and your program is retrying to reconnect |

- Send flight data to your frontend via a WebSocket channel created for each flight.

### Frontend

Create a dashboard that:
- Lists all available flights.
- Shows real-time telemetry data for each flight.
- Updates via WebSocket connections (one per flight).
- **Design is up to you** — make it clear and usable!

> ⚠️ The default status of each flight should be `WAITING` once the flight list is loaded. This status updates according to the service-determined status once a WebSocket message is received.

---

## 📦 Binary Packet Protocol

After subscribing, you'll receive binary packets at your requested interval.

> ⚠️ **Key Facts:**
> - Packet Size: **36 bytes**
> - Byte Order: **Big-endian** (most significant byte first)
> - Must start with: `0x82`
> - Must end with: `0x80`
> - **No framing:** Packets arrive as a continuous stream — you must implement buffering and re-synchronization.

> ⚠️ **Discard packets that:**
> - Don't start with `0x82`
> - Don't end with `0x80`
> - Aren't exactly 36 bytes

> ⚠️ **Important Implementation Notes:**
> - Implement buffer accumulation — packets may arrive fragmented or concatenated.
> - Implement re-synchronization logic — if you lose alignment, scan for the next `0x82` start byte.
> - The start byte (`0x82`) can appear coincidentally in payload data — always verify the end marker (`0x80`) at position 35.
> - If you find `0x82` but the byte at position 35 isn't `0x80`, it's likely a false start — skip one byte and continue scanning.

### Packet Layout

| Offset | Size | Type | Field | Description |
|--------|------|------|-------|-------------|
| `0x00` | 1 | Byte | Start Marker | Always `0x82` |
| `0x01` | 10 | String | Flight Number | ASCII (e.g., `"ONX101"`), zero-padded |
| `0x0B` | 1 | Byte | Packet Number | Increments 0–255, then wraps |
| `0x0C` | 1 | Byte | Packet Size | Always `0x24` (36 bytes) |
| `0x0D` | 4 | Float | Altitude | m (big-endian IEEE 754) |
| `0x11` | 4 | Float | Speed | m/s (big-endian IEEE 754) |
| `0x15` | 4 | Float | Acceleration | m/s² (big-endian IEEE 754) |
| `0x19` | 4 | Float | Thrust | Newtons (big-endian IEEE 754) |
| `0x1D` | 4 | Float | Temperature | °C (big-endian IEEE 754) |
| `0x21` | 2 | Short | CRC Checksum | CRC-16/CCITT-FALSE |
| `0x23` | 1 | Byte | End Marker | Always `0x80` |

### CRC Checksum

**Algorithm:** CRC-16/CCITT-FALSE

| Parameter | Value |
|-----------|-------|
| Polynomial | `0x1021` |
| Initial Value | `0xFFFF` |
| XOR Output | `0x0000` |
| Reflect Input | False |
| Reflect Output | False |

**Calculation Range:** Bytes `0x00` to `0x1E` (first 31 bytes)

- **Includes:** Start byte, flight ID, packet number, packet size, altitude, speed, acceleration, thrust, and first 2 bytes of temperature (`0x1D`, `0x1E`)
- **Excludes:** Last 2 bytes of temperature (`0x1F`, `0x20`), CRC itself (`0x21`–`0x22`), and delimiter (`0x23`)

**Validation Steps:**
1. Read the CRC value from bytes `0x21` to `0x22`.
2. Calculate CRC over bytes `0x00` to `0x1E` (31 bytes).
3. Compare the calculated CRC against the received CRC.

---

## 🚀 Submission Guidelines

1. **README.md** — Must include:
   - Setup instructions (how to run locally)
   - Architecture overview
   - Technology choices and why
   - Assumptions made
   - Any known limitations
2. **Your Code** — Backend and Frontend (Public GitHub Repo URL)
3. **Dockerization** (Optional but recommended)

---

## 💡 Tips for Success

### Handling TCP Connections
- Each flight needs its own TCP connection.
- Implement reconnection logic with exponential backoff.
- Don't let one connection block others.

### Memory Management
- Monitor your process memory usage.
- Implement the memory limit restart feature.
- Clean up connections properly.

### Error Scenarios to Handle
- Invalid CRC checksums
- Out-of-range telemetry values
- Unexpected connection closures
- Server downtime/unavailability
- Malformed packets

### Testing Approach
- Test with multiple simultaneous flights.
- Simulate connection failures.
- Verify packet validation logic.
- Test memory limit restart behavior.
- Test with lower `intervalMs` to handle load.
