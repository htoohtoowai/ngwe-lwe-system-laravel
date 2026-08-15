#!/bin/sh
set -eu

missing=""
errors=""

require_env() {
    name="$1"
    value="$(printenv "$name" || true)"

    if [ -z "$value" ]; then
        missing="${missing}
- ${name}"
    fi
}

add_error() {
    errors="${errors}
- $1"
}

require_env APP_KEY
require_env DB_CONNECTION
require_env DB_HOST
require_env DB_DATABASE
require_env DB_USERNAME
require_env REVERB_APP_ID
require_env REVERB_APP_KEY
require_env REVERB_APP_SECRET
require_env VITE_REVERB_APP_KEY

if [ -n "$missing" ]; then
    printf '%s\n' "Missing required environment variables:${missing}"
    printf '%s\n' "Set them in .env before starting Docker Compose."
    exit 1
fi

case "$APP_KEY" in
    base64:replace-with-*|replace-with-*|changeme|CHANGE_ME)
        add_error "APP_KEY is still a placeholder."
        ;;
esac

if [ "${APP_ENV:-production}" = "production" ] && [ "${APP_DEBUG:-false}" = "true" ]; then
    add_error "APP_DEBUG must be false when APP_ENV=production."
fi


if [ "$VITE_REVERB_APP_KEY" != "$REVERB_APP_KEY" ]; then
    add_error "VITE_REVERB_APP_KEY must match REVERB_APP_KEY for browser realtime auth."
fi

if [ -n "$errors" ]; then
    printf '%s\n' "Invalid environment configuration:${errors}"
    exit 1
fi
