<?php

return [
    /** Drivers a company can choose in Administration → SMS settings. */
    'drivers' => ['log', 'afyabox'],

    /** AfyaBox: messages are queued as rows in its `messaging` table; AfyaBox delivers them. */
    'afyabox' => [
        'connection' => 'afyabox',
        'table' => env('AFYABOX_SMS_TABLE', 'messaging'),
    ],

    /** Longest message sent (the gateway limit HRMS used). */
    'max_length' => 120,
];
