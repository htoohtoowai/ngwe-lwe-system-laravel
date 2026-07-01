<?php

return [
    'auth' => [
        'token_ttl_seconds' => (int) env('NGWE_LWE_TOKEN_TTL_SECONDS', 86400),
        'secret' => env('NGWE_LWE_AUTH_SECRET', env('APP_KEY')),
        'dummy_password_hash' => env(
            'NGWE_LWE_DUMMY_PASSWORD_HASH',
            '$2y$12$BCSuJ5bKz6mRnNUvWUdQZ.4OCEqjsCEwNZk0b7hss3DNfTseZClnG',
        ),
    ],
];
