<?php

/*
 * User activity monitoring.
 *
 * Everything here observes the panel from the outside: none of it changes what
 * a request does, only what is written down about it afterwards.
 */
return [

    // Master switch. Off means no sessions, visits, heartbeats or auth rows
    // are written, and the tracker script is not rendered.
    'enabled' => (bool) env('USER_ACTIVITY_ENABLED', true),

    // Page visits and session telemetry older than this are deleted by the
    // prune command. Rows in activity_log are never touched by it.
    'retention_days' => (int) env('USER_ACTIVITY_RETENTION_DAYS', 90),

    // How often a visible tab reports itself, in seconds.
    'heartbeat_seconds' => (int) env('USER_ACTIVITY_HEARTBEAT_SECONDS', 60),

    // A session seen within this many minutes counts as online.
    'online_within_minutes' => (int) env('USER_ACTIVITY_ONLINE_MINUTES', 5),

    // The most active time one heartbeat may add, whatever the browser claims.
    'max_heartbeat_increment_seconds' => 120,

    // How many events the per-user timeline shows at most.
    'timeline_limit' => 300,

];
