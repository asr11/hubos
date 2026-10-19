<?php

declare(strict_types=1);

/*
 * Configuration from environment variables only. No secrets live in code.
 */

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};

return [
    'app_name'             => 'OS HUB',
    'env'                  => $env('HUBOS_ENV', 'production'),
    'db_path'              => $env('HUBOS_DB_PATH', HUBOS_ROOT . '/storage/hubos.sqlite'),
    'session_path'         => $env('HUBOS_SESSION_PATH', HUBOS_ROOT . '/storage/sessions'),
    'log_path'             => $env('HUBOS_LOG_PATH', HUBOS_ROOT . '/storage/logs'),
    // auto = Secure flag only when the request is HTTPS. 1 = always, 0 = never (local dev).
    'cookie_secure'        => $env('HUBOS_COOKIE_SECURE', 'auto'),
    'session_idle_seconds' => (int) $env('HUBOS_SESSION_IDLE', '7200'),
    'display_timezone'     => 'Asia/Jerusalem',
    // Company registry (data.gov.il). Override only for local testing.
    'registry_url'         => $env('HUBOS_REGISTRY_URL', 'https://data.gov.il/api/3/action/datastore_search?resource_id=f004176c-b85f-4542-8901-7b3176f9a054'),
    // Digital-CEO Agent API Bearer token (never hardcoded; set via environment).
    'agent_token'          => $env('HUBOS_AGENT_TOKEN', ''),
    'agent_tenant'         => $env('HUBOS_AGENT_TENANT', 'hub'),
];
