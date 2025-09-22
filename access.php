<?php
defined('MOODLE_INTERNAL') || die();
$capabilities = [
    'local/admindash:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [ 'manager' => CAP_ALLOW ]
    ],
    'local/admindash:comment' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [ 'manager' => CAP_ALLOW ]
    ],
];
