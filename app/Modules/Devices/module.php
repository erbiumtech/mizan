<?php

/**
 * Biometric devices, live — docs/saas-launch-plan.md §8.7.
 *
 * The device connects to us; we do not connect to it. A ZKTeco in push mode
 * uploads every punch as it happens to /iclock/cdata and polls /iclock/getrequest
 * for commands. The serial in its query string is how the company is found,
 * which is why the device registry is a landlord table.
 *
 * Requires Attendance: a punch is only worth keeping because it becomes an
 * attendance day. Nothing else reaches in; the folding job hands first and last
 * punch to AttendanceRecorder, the same path the CSV import walks.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'devices',
    'label' => 'Devices',
    'description' => 'Biometric attendance devices pushing punches live, folded into attendance days.',
    'requires' => [
        'attendance',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Devices\DevicesPlugin::class,

    'models' => [
        'App\\Models\\AttendanceDevice' => \App\Modules\Devices\Models\AttendanceDevice::class,
        'App\\Models\\DevicePunch' => \App\Modules\Devices\Models\DevicePunch::class,
    ],

    'resources' => [],
    'pages' => [],
    'widgets' => [],

    'permission_groups' => [],
    'permissions' => [],
    'navigation' => [],
];
