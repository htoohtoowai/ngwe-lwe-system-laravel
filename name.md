# Naming Guide

This guide follows the naming style used in `vendor/laravel/framework/src/Illuminate`
and the Laravel Pint `laravel` preset used by this project.

## PHP Classes

Use `StudlyCase` for classes, interfaces, traits, enums, and exceptions.

Examples from Illuminate:

- `ServiceProvider`
- `DatabaseManager`
- `ConnectionResolverInterface`
- `InteractsWithInput`
- `QueryException`

Project examples:

- `FlightController`
- `FlightResource`
- `StartTelemetry`
- `TelemetryUpdated`
- `PacketParser`

## PHP Methods And Functions

Use `camelCase` for normal PHP methods and functions.

Examples from Illuminate:

- `handle()`
- `getOptions()`
- `setDefaultConnection()`
- `expectsJson()`
- `broadcastOn()`

Project examples:

- `fetchFlights()`
- `installSignalHandlers()`
- `sanitizeForBroadcast()`
- `bufferLength()`
- `toArray()`

## PHPUnit Test Methods

Use `snake_case` for PHPUnit test method names.

The Laravel Pint `laravel` preset enforces this through `php_unit_method_casing`.
This applies to both `tests/Unit` and `tests/Feature`.

Examples:

- `test_flights_are_returned_through_resource_collection()`
- `test_crc16_ccitt_matches_standard_check_vector()`
- `test_parser_drains_valid_packet()`

## PHP Variables And Properties

Use `camelCase` for local variables, parameters, and class properties.

Examples from Illuminate:

- `$default`
- `$connection`
- `$connectionName`
- `$requestLifecycleDurationHandlers`
- `$commandLifecycleDurationHandlers`

Project examples:

- `$cacheKey`
- `$cacheTtl`
- `$flightId`
- `$flightNumber`
- `$intervalMs`
- `$memoryExceeded`

## PHP Constants

Use `UPPER_SNAKE_CASE` for constants.

Examples from Illuminate:

- `CREATED_AT`
- `UPDATED_AT`
- `DEFAULT_CLASS_NAME`
- `INVISIBLE_CHARACTERS`

Project examples:

- `MIN_INTERVAL_MS`
- `MAX_INTERVAL_MS`
- `PACKET_SIZE`
- `START_MARKER`
- `END_MARKER`

## Namespaces And Directories

Use Laravel's default application structure where possible.

Current project structure:

- Commands: `App\Console\Commands`
- Events: `App\Events`
- Controllers: `App\Http\Controllers`
- Resources: `App\Http\Resources`
- Services: `App\Services`
- Support helpers: `App\Support`

Namespace segments use `StudlyCase` and should match directory names.

## Artisan Commands

Use lowercase command names with colon-separated namespaces and kebab-case words.

Examples:

- `telemetry:start`
- `telemetry:probe`
- `queue:work`
- `migrate:fresh`

Command options should be kebab-case when multiple words are needed:

- `--interval`
- `--memory`
- `--force`
- `--class`

## Config And Array Keys

Use `snake_case` for Laravel config keys and server-side internal array keys.

Examples from Illuminate:

- `use_path_style_endpoint`
- `reserved_at`
- `available_at`
- `created_at`
- `foreign_keys`
- `open_connections`

Project examples:

- `default_interval_ms`
- `memory_limit_mb`
- `cache_ttl_seconds`
- `api_scheme`
- `api_port`

Use lower-case strings for simple config driver names:

- `redis`
- `reverb`
- `stderr`
- `sync`

## JSON API Keys

Preserve external API names when proxying or broadcasting challenge data.

The upstream flight API and frontend currently use camelCase JSON fields:

- `flightNumber`
- `telemetryPort`
- `flightId`
- `intervalMs`
- `packetNumber`

Do not convert those fields to snake_case unless the API contract is changed in
both backend and frontend.

## Environment Variables

Use `UPPER_SNAKE_CASE` for environment variables.

Examples:

- `APP_KEY`
- `APP_DEBUG`
- `REVERB_APP_KEY`
- `TELEMETRY_INTERVAL_MS`
- `TELEMETRY_MEMORY_LIMIT_MB`
- `VITE_REVERB_PORT`

## Database Names

Use Laravel's normal database naming style:

- Table names: plural `snake_case`
- Column names: `snake_case`
- Foreign keys: singular model name plus `_id`
- Timestamps: `created_at`, `updated_at`

Examples:

- `users`
- `flight_logs`
- `flight_id`
- `created_at`

## Frontend TypeScript

Use TypeScript conventions while preserving API field names:

- Types/interfaces: `PascalCase`
- Variables/functions: `camelCase`
- API fields: keep contract names such as `flightNumber`, `telemetryPort`

Examples:

- `Flight`
- `TelemetryPayload`
- `useFlights()`
- `useFlightSocket()`
- `reverbAppKey`
- `lastUpdate`

## Summary

Use these defaults:

- PHP class/interface/trait/enum: `StudlyCase`
- PHP method/function/variable/property: `camelCase`
- PHPUnit test method: `snake_case`
- PHP constant/env variable: `UPPER_SNAKE_CASE`
- Laravel config key/database column: `snake_case`
- Artisan command name: `namespace:kebab-command`
- JSON API key: preserve external contract, currently mostly `camelCase`
