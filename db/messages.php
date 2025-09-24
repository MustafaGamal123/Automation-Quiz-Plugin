<?php
// D:\Quiz_Proctoring\server\moodle\mod\quiz\accessrule\proctoring\db\messages.php
// db/messages.php - Message providers for quiz proctoring
// يجب وضع هذا الملف في: /mod/quiz/db/messages.php
// أو في plugin منفصل: /local/proctoringalerts/db/messages.php

defined('MOODLE_INTERNAL') || die();

$providers = array(
    
    // التنبيه الأساسي للأنشطة المشبوهة
    'suspicious_activity' => array(
        'capability' => 'mod/quiz:manage',
        'defaults' => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN + MESSAGE_DEFAULT_LOGGEDOFF,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN,
            'airnotifier' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN,
        )
    ),
    
    // تنبيه خاص بعدم اكتشاف الوجه
    'face_not_detected' => array(
        'capability' => 'mod/quiz:manage',
        'defaults' => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN,
            'email' => MESSAGE_PERMITTED,
            'airnotifier' => MESSAGE_PERMITTED,
        )
    ),
    
    // تنبيه الحركة المفرطة
    'excessive_movement' => array(
        'capability' => 'mod/quiz:manage',
        'defaults' => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN,
            'email' => MESSAGE_PERMITTED,
            'airnotifier' => MESSAGE_PERMITTED,
        )
    ),
    
    // تنبيه الابتعاد عن الكاميرا
    'face_turned_away' => array(
        'capability' => 'mod/quiz:manage',
        'defaults' => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN,
            'email' => MESSAGE_PERMITTED,
            'airnotifier' => MESSAGE_PERMITTED,
        )
    ),
    
    // تقرير شامل للجلسة
    'proctoring_session_report' => array(
        'capability' => 'mod/quiz:manage',
        'defaults' => array(
            'popup' => MESSAGE_DISALLOWED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN,
            'airnotifier' => MESSAGE_DISALLOWED,
        )
    )
    
);

// إضافة language strings (اختيارية)
// يمكن إضافة هذا في lang/en/quiz.php بدلاً من هنا

/*
$string['messageprovider:suspicious_activity'] = 'Suspicious quiz activity alerts';
$string['messageprovider:face_not_detected'] = 'Face detection warnings';
$string['messageprovider:excessive_movement'] = 'Movement detection alerts';
$string['messageprovider:face_turned_away'] = 'Face orientation warnings';
$string['messageprovider:proctoring_session_report'] = 'Quiz proctoring session reports';
*/

?>