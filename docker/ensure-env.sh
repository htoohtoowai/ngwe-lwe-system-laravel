#!/bin/sh
set -eu

missing=""

require_env() {
    name="$1"
    value="$(printenv "$name" || true)"

    if [ -z "$value" ]; then
        missing="${missing}
- ${name}"
    fi
}

require_env APP_KEY
require_env REVERB_APP_ID
require_env REVERB_APP_KEY
require_env REVERB_APP_SECRET

if [ -n "$missing" ]; then
    printf '%s\n' "Missing required environment variables:${missing}"
    printf '%s\n' "Set them in .env before starting Docker Compose."
    exit 1
fi
