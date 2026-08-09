<?php

return [

    'health' => [
        // Master switch for scheduled checking. On-demand "Check now" still works.
        'enabled' => env('PROJECT_HEALTH_CHECKS', true),

        // Minutes between checks when an environment has no interval of its own.
        'default_interval' => (int) env('PROJECT_HEALTH_INTERVAL', 5),

        'timeout' => (int) env('PROJECT_HEALTH_TIMEOUT', 5),
        'connect_timeout' => (int) env('PROJECT_HEALTH_CONNECT_TIMEOUT', 3),
        'user_agent' => 'MPR-HealthCheck/1.0',

        // Reachable-but-unauthenticated still means the server is answering.
        'healthy_codes' => [401, 403],

        // Cap on the body scanned for an expected_content assertion.
        'max_body_bytes' => 256 * 1024,

        // History retention for ProjectEnvironmentCheck (model:prune).
        'retention_days' => (int) env('PROJECT_HEALTH_RETENTION_DAYS', 30),
    ],

    'alerts' => [
        'enabled' => env('PROJECT_ALERTS', true),

        // Consecutive results needed before a state change is believed. Three
        // failures at a 5-minute interval = ~10 minutes to confirm an outage,
        // which is the price of never paging on a single dropped request.
        'failure_threshold' => (int) env('PROJECT_ALERT_FAILURE_THRESHOLD', 3),
        'recovery_threshold' => (int) env('PROJECT_ALERT_RECOVERY_THRESHOLD', 2),

        // Still-down reminders: cadence and how many before going quiet.
        'reminder_minutes' => (int) env('PROJECT_ALERT_REMINDER_MINUTES', 60),
        'max_reminders' => (int) env('PROJECT_ALERT_MAX_REMINDERS', 3),

        // Channels. 'slack' additionally needs laravel/slack-notification-channel.
        'channels' => ['mail', 'database', 'broadcast'],

        // Used when a project has no primary or secondary manager, so an alert
        // is never silently dropped.
        'fallback_role' => env('PROJECT_ALERT_FALLBACK_ROLE', 'Manager'),
    ],

    'ssl' => [
        'enabled' => env('PROJECT_SSL_CHECKS', true),

        // Days remaining at which to alert; each threshold fires once.
        'thresholds' => [30, 14, 7, 3, 1],

        'timeout' => 5,
    ],

    'status_page' => [
        // Off unless a company turns it on in Company Settings.
        'enabled' => false,
        'token' => null,
        'cache_seconds' => 60,
        'uptime_days' => 30,
    ],

];
