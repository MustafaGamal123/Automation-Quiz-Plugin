<?php
// /mod/quiz/accessrule/proctoring/db/messages.php
defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'proctoring_warning' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN,
            'email' => MESSAGE_PERMITTED,
        ],
        'capability'  => 'mod/quiz:attempt',
    ],
];