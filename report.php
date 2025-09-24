<?php
require_once(__DIR__.'/../../../../config.php');
require_once($CFG->dirroot.'/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->libdir.'/tablelib.php');

$courseid = required_param('courseid', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);
$studentid = optional_param('studentid', null, PARAM_INT);
$searchkey = optional_param('searchKey', null, PARAM_TEXT);
$submittype = optional_param('submitType', null, PARAM_TEXT);
$reportid = optional_param('reportid', null, PARAM_INT);
$logaction = optional_param('logaction', null, PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$studentid = optional_param('studentid', 0, PARAM_INT);
$analyzebtn = get_string('analyzbtn', 'quizaccess_proctoring');
$analyzebtnconfirm = get_string('analyzbtnconfirm', 'quizaccess_proctoring');

$context = context_module::instance($cmid, MUST_EXIST);
require_capability('quizaccess/proctoring:viewreport', $context);

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'quiz');
require_login($course, true, $cm);

$coursedata = $DB->get_record('course', ['id' => $courseid]);
$quiz = $DB->get_record('quiz', ['id' => $cm->instance]);

$params = [
    'courseid' => $courseid,
    'userid' => $studentid,
    'cmid' => $cmid,
];

$perpage = 30;
$offset = $page * $perpage;
$totalrecords = 0;

if ($studentid) {
    $params['studentid'] = $studentid;
}
if ($reportid) {
    $params['reportid'] = $reportid;
}

$url = new moodle_url('/mod/quiz/accessrule/proctoring/report.php', ['courseid' => $courseid, 'cmid' => $cmid]);
$fcmethod = get_config('quizaccess_proctoring', 'fcmethod');

$PAGE->set_url($url);
$PAGE->set_pagelayout('course');
$PAGE->set_title($coursedata->shortname . ': ' . get_string('pluginname', 'quizaccess_proctoring'));
$PAGE->set_heading($coursedata->fullname . ': ' . get_string('pluginname', 'quizaccess_proctoring'));
$PAGE->navbar->add(get_string('quizaccess_proctoring', 'quizaccess_proctoring'), $url);
$PAGE->requires->js_call_amd('quizaccess_proctoring/lightbox2', 'init', [$fcmethod , [
    'analyzebtn' => $analyzebtn,
    'analyzebtnconfirm' => $analyzebtnconfirm,
]]);
$PAGE->requires->css('/mod/quiz/accessrule/proctoring/styles.css');

if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {
    $PAGE->navbar->add(get_string('studentreport', 'quizaccess_proctoring') . " - $studentid", $url);
}

$settingsbtn = has_capability('quizaccess/proctoring:viewreport', $context, $USER->id);
$showclearbutton = ($submittype === 'Search' && !empty($searchkey));

if (has_capability('quizaccess/proctoring:deletecamshots', $context, $USER->id) && $studentid != null
    && $cmid != null && $courseid != null && $reportid != null && !empty($logaction) && $logaction === 'delete') {

    $DB->delete_records('quizaccess_proctoring_logs', [
        'courseid' => $courseid,
        'quizid' => $cmid,
        'userid' => $studentid,
    ]);
    $DB->delete_records('quizaccess_proctoring_fm_warnings', [
        'courseid' => $courseid,
        'quizid' => $cmid,
        'userid' => $studentid,
    ]);

    $params = [
        'userid' => $studentid,
        'contextid' => $context->id,
        'component' => 'quizaccess_proctoring',
        'filearea' => 'picture',
    ];

    $usersfile = $DB->get_records('files', $params);
    $fs = get_file_storage();
    foreach ($usersfile as $file) {
        $fileinfo = [
            'component' => 'quizaccess_proctoring',
            'filearea' => 'picture',
            'itemid' => $file->itemid,
            'contextid' => $context->id,
            'filepath' => '/',
            'filename' => $file->filename,
        ];
        $storedfile = $fs->get_file(
            $fileinfo['contextid'], 
            $fileinfo['component'], 
            $fileinfo['filearea'],
            $fileinfo['itemid'], 
            $fileinfo['filepath'], 
            $fileinfo['filename']
        );
        if ($storedfile) {
            $storedfile->delete();
        }
    }

    redirect(new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
        'courseid' => $courseid,
        'cmid' => $cmid,
    ]), get_string('imagesdeleted', 'quizaccess_proctoring'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$proctoringprolink = new moodle_url(
    '/mod/quiz/accessrule/proctoring/proctoring_pro_promo.php',
    ['cmid' => $cmid, 'courseid' => $courseid]
);

echo $OUTPUT->header();

$backbutton = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);

if (has_capability('quizaccess/proctoring:viewreport', $context, $USER->id) && $cmid != null && $courseid != null) {
    if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {
        $backbutton = new moodle_url('/mod/quiz/accessrule/proctoring/report.php?', ['courseid' => $courseid, 'cmid' => $cmid]);
        $sql = "SELECT
                e.id AS reportid,
                e.userid AS studentid,
                e.webcampicture AS webcampicture,
                e.status AS status,
                e.timemodified AS timemodified,
                u.firstname AS firstname,
                u.lastname AS lastname,
                u.email AS email,
                pfw.reportid AS warningid
            FROM {quizaccess_proctoring_logs} e
            INNER JOIN {user} u ON u.id = e.userid
            LEFT JOIN {quizaccess_proctoring_fm_warnings} pfw
                ON e.courseid = pfw.courseid
                AND e.quizid = pfw.quizid
                AND e.userid = pfw.userid
            WHERE e.courseid = :courseid
                AND e.quizid = :cmid
                AND u.id = :studentid
                AND e.id = :reportid";
    } elseif ($studentid == null && $cmid != null && $courseid != null) {
        $sql = "SELECT DISTINCT
                e.userid AS studentid,
                u.firstname AS firstname,
                u.lastname AS lastname,
                u.email AS email,
                CASE WHEN fw.userid IS NOT NULL THEN 'Yes' ELSE 'No' END AS haswarning,
                MAX(e.webcampicture) AS webcampicture,
                MAX(e.id) AS reportid,
                MAX(e.status) AS status,
                MAX(e.timemodified) AS timemodified
            FROM {quizaccess_proctoring_logs} e
            INNER JOIN {user} u ON u.id = e.userid
            LEFT JOIN {quizaccess_proctoring_fm_warnings} fw 
                ON fw.courseid = e.courseid 
                AND fw.quizid = e.quizid 
                AND fw.userid = e.userid
            WHERE e.courseid = :courseid
                AND e.quizid = :cmid
            GROUP BY e.userid, u.firstname, u.lastname, u.email, fw.userid";
    }

    $params = [
        'courseid' => $courseid,
        'cmid' => $cmid,
        'studentid' => $studentid,
        'reportid' => $reportid,
    ];
    
    $totalrecordssql = "SELECT COUNT(DISTINCT e.userid) 
                    FROM {quizaccess_proctoring_logs} e
                    INNER JOIN {user} u ON u.id = e.userid
                    WHERE e.courseid = :courseid AND e.quizid = :cmid";
    $totalrecords = $DB->count_records_sql($totalrecordssql, ['courseid' => $courseid, 'cmid' => $cmid]);
    $sqlexecuted = $DB->get_records_sql($sql, $params, $offset, $perpage);

    $rows = [];
    foreach ($sqlexecuted as $info) {
        $row = [];
        $row['userlink'] = $CFG->wwwroot.'/user/view.php?id='.$info->studentid.'&course='.$courseid;
        $row['fullname'] = $info->firstname.' '.$info->lastname;
        $row['email'] = $info->email;
        $row['timemodified'] = date('Y/M/d H:i:s', $info->timemodified);

        $has_warning = 'No';

        $fmCount = $DB->count_records('quizaccess_proctoring_fm_warnings', [
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $info->studentid
        ]);

        $warningCount = 0;
        if ($DB->get_manager()->table_exists('quizaccess_proctoring_warnings')) {
            $warningCount = $DB->count_records('quizaccess_proctoring_warnings', [
                'courseid' => $courseid,
                'quizid' => $quiz->id,
                'userid' => $info->studentid
            ]);
        }

        $uploadWarningCount = 0;
        $upload_dir = $CFG->dataroot . '/mod/quiz/accessrule/proctoring/uploads/warnings/';
        $log_file = $upload_dir . 'upload_log.json';
        
        if (file_exists($log_file)) {
            $log_data = json_decode(file_get_contents($log_file), true) ?: [];
            foreach ($log_data as $entry) {
                if (isset($entry['cmid'], $entry['user_id']) && 
                    $entry['cmid'] == $cmid && $entry['user_id'] == $info->studentid) {
                    $uploadWarningCount++;
                    break;
                }
            }
        }

        if ($fmCount > 0 || $warningCount > 0 || $uploadWarningCount > 0) {
            $has_warning = 'Yes';
        }

        $row['haswarning'] = $has_warning;
        $row['haswarning_eq_Yes'] = ($has_warning === 'Yes');
        $row['haswarning_eq_No'] = ($has_warning === 'No');

        $actionmenu = new action_menu();
        $actionmenu->set_kebab_trigger(get_string('actions'));

        $viewurl = new moodle_url($PAGE->url, [
            'courseid' => $courseid,
            'quizid' => $quiz->id,
            'cmid' => $cmid,
            'studentid' => $info->studentid,
            'reportid' => $info->reportid,
        ]);

        $viewaction = new action_menu_link_secondary(
            $viewurl,
            new pix_icon('e/insert_edit_image', get_string('viewimages', 'quizaccess_proctoring'), 'moodle'),
            get_string('viewimages', 'quizaccess_proctoring')
        );
        $actionmenu->add($viewaction);

        if ($action === 'viewwarnings' && $studentid && $cmid && $courseid) {
            $warningurl = new moodle_url('/mod/quiz/accessrule/proctoring/uploadwarning.php', [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'studentid' => $studentid,
                'reportid' => $reportid
            ]);
            
            redirect($warningurl);
        }

        $warningaction = new action_menu_link_secondary(
            new moodle_url('/mod/quiz/accessrule/proctoring/uploadwarning.php', [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'studentid' => $info->studentid,
                'reportid' => $info->reportid,
                'action' => 'viewwarnings'
            ]),
            new pix_icon('i/warning', get_string('warning', 'moodle'), 'moodle'),
            get_string('viewwarnings', 'quizaccess_proctoring')
        );
        $actionmenu->add($warningaction);

        $deleteurl = new moodle_url($PAGE->url, [
            'courseid' => $courseid,
            'quizid' => $cmid,
            'cmid' => $cmid,
            'studentid' => $info->studentid,
            'reportid' => $info->reportid,
            'logaction' => 'delete',
            'sesskey' => sesskey(),
        ]);

        $deleteaction = new action_menu_link_secondary(
            $deleteurl,
            new pix_icon('t/delete', '', 'moodle'),
            get_string('delete'),
            [
                'data-confirmation' => 'modal',
                'data-confirmation-type' => 'delete',
                'data-confirmation-title-str' => json_encode(['delete', 'core']),
                'data-confirmation-content-str' => json_encode(['areyousure_delete_record', 'quizaccess_proctoring']),
                'data-confirmation-yes-button-str' => json_encode(['delete', 'core']),
                'data-confirmation-action-url' => $deleteurl->out(false),
                'data-confirmation-destination' => $deleteurl->out(false),
                'class' => 'text-danger',
            ]
        );
        $actionmenu->add($deleteaction);

        $row['actionmenu'] = $OUTPUT->render($actionmenu);
        $rows[] = $row;
    }

    $templatecontext = (object)[
        'quizname' => get_string('eprotroringreports', 'quizaccess_proctoring').$quiz->name,
        'settingsbtn' => $settingsbtn,
        'settingspageurl' => $CFG->wwwroot.'/mod/quiz/accessrule/proctoring/proctoringsummary.php?cmid='.$cmid,
        'proctoringsummary' => get_string('eprotroringreportsdesc', 'quizaccess_proctoring'),
        'url' => $CFG->wwwroot.'/mod/quiz/accessrule/proctoring/report.php',
        'courseid' => $courseid,
        'cmid' => $cmid,
        'searchkey' => ($submittype == "Clear") ? '' : $searchkey,
        'showclearbutton' => $showclearbutton,
        'checkrow' => (!empty($row)) ? true : false,
        'rows' => $rows,
        'backbutton' => preg_replace('/&amp;/', '&', $backbutton),
    ];

    echo $OUTPUT->render_from_template('quizaccess_proctoring/report', $templatecontext);

    $currenturl = new moodle_url(qualified_me());
    if (!empty($searchkey) && empty($submittype)) {
        $currenturl->param('searchKey', $searchkey);
        $currenturl->param('submitType', $submittype);
    }
    $currenturl->param('page', $page);
    $pagingbar = new paging_bar($totalrecords, $page, $perpage, $currenturl);
    echo $OUTPUT->render($pagingbar);

    if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {
        $featuresimageurl = $OUTPUT->image_url('proctoring_pro_report_overview', 'quizaccess_proctoring');
        $profileimageurl = quizaccess_proctoring_get_image_url($studentid);
        $redirecturl = new moodle_url('/mod/quiz/accessrule/proctoring/upload_image.php', ['id' => $studentid]);

        $sql = "SELECT e.id AS reportid,
            e.userid AS studentid,
            e.webcampicture AS webcampicture,
            e.status AS status,
            e.timemodified AS timemodified,
            u.firstname AS firstname,
            u.lastname AS lastname,
            u.email AS email,
            e.awsscore,
            e.awsflag
        FROM {quizaccess_proctoring_logs} e
        INNER JOIN {user} u ON u.id = e.userid
        WHERE e.courseid = :courseid
        AND e.quizid = :cmid
        AND u.id = :studentid
        AND e.deletionprogress = :deletionprogress";
        $params = [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'studentid' => $studentid,
            'deletionprogress' => 0,
        ];
        $sqlexecuted = $DB->get_recordset_sql($sql, $params);

        $user = core_user::get_user($studentid);
        $thresholdvalue = (int) quizaccess_proctoring_get_proctoring_settings('threshold');
        $studentdata = [];
        foreach ($sqlexecuted as $info) {
            $row = [];
            $row['firstname'] = $info->firstname;
            $row['lastname'] = $info->lastname;
            $row['image_url'] = $info->webcampicture;
            $row['border_color'] = $info->awsflag == 2 && $info->awsscore > $thresholdvalue ? 'green' :
                                    ($info->awsflag == 2 && $info->awsscore < $thresholdvalue ? 'red' :
                                    ($info->awsflag == 3 && $info->awsscore < $thresholdvalue ? 'yellow' : 'none'));
            $row['img_id'] = 'reportid-'.$info->reportid;
            $row['lightbox_data'] = basename($info->webcampicture, '.png');
            $studentdata[] = $row;
        }

        $analyzeparam = ['studentid' => $studentid, 'cmid' => $cmid, 'courseid' => $courseid, 'reportid' => $reportid];
        $analyzeurl = new moodle_url('/mod/quiz/accessrule/proctoring/analyzeimage.php', $analyzeparam);
        $analyzeurl = preg_replace('/&amp;/', '&', $analyzeurl);
        $userimageurl = quizaccess_proctoring_get_image_url($user->id);
        if (!$userimageurl) {
            $userimageurl = $OUTPUT->image_url('u/f2');
        }

        $studentreportcontext = (object)[
            'featuresimageurl' => $featuresimageurl,
            'proctoringprolink' => preg_replace('/&amp;/', '&', $proctoringprolink),
            'issiteadmin' => (is_siteadmin() && !$profileimageurl ? true : false),
            'redirecturl' => $redirecturl,
            'data' => $studentdata,
            'userimageurl' => $userimageurl,
            'firstname' => $info->firstname,
            'lastname' => $info->lastname,
            'email' => $info->email,
            'fcmethod' => ($fcmethod == 'BS') ? true : false,
            'analyzeurl' => $analyzeurl,
        ];
        echo $OUTPUT->render_from_template('quizaccess_proctoring/studentreport', $studentreportcontext);
    }
} else {
    echo $OUTPUT->notify(get_string('notpermissionreport', 'quizaccess_proctoring'), 'notifyproblem');
}

if ($action === 'viewwarning' && $studentid && $cmid && $courseid) {
    $featuresimageurl = $OUTPUT->image_url('proctoring_pro_report_overview', 'quizaccess_proctoring');
    $profileimageurl = quizaccess_proctoring_get_image_url($studentid);
    $redirecturl = new moodle_url('/mod/quiz/accessrule/proctoring/upload_image.php', ['id' => $studentid]);

    $warnings = $DB->get_records('quizaccess_proctoring_warnings', [
        'courseid' => $courseid,
        'quizid' => $quiz->id,
        'userid' => $studentid
    ]);
    $user = core_user::get_user($studentid);
    $studentdata = [];
    foreach ($warnings as $warning) {
        $row = [];
        $row['firstname'] = $user->firstname;
        $row['lastname'] = $user->lastname;
        $row['image_url'] = !empty($warning->imagefile) ? moodle_url::make_pluginfile_url(
            $context->id,
            'quizaccess_proctoring',
            'picture',
        $warning->id,
            '/',
            $warning->imagefile,
            false
        ) : '';
        $row['border_color'] = 'red';
        $row['img_id'] = 'warningid-'.$warning->id;
        $row['lightbox_data'] = basename($warning->imagefile, '.jpg');
        $row['warningtype'] = $warning->warningtype;
        $row['timestamp'] = userdate($warning->timestamp);
        $studentdata[] = $row;
    }
    $analyzeparam = ['studentid' => $studentid, 'cmid' => $cmid, 'courseid' => $courseid, 'reportid' => $reportid];
    $analyzeurl = new moodle_url('/mod/quiz/accessrule/proctoring/analyzeimage.php', $analyzeparam);
    $analyzeurl = preg_replace('/&amp;/', '&', $analyzeurl);
    $userimageurl = quizaccess_proctoring_get_image_url($user->id);
    if (!$userimageurl) {
        $userimageurl = $OUTPUT->image_url('u/f2');
    }
    $studentreportcontext = (object)[
        'featuresimageurl' => $featuresimageurl,
        'proctoringprolink' => preg_replace('/&amp;/', '&', $proctoringprolink),
        'issiteadmin' => (is_siteadmin() && !$profileimageurl ? true : false),
        'redirecturl' => $redirecturl,
        'data' => $studentdata,
        'userimageurl' => $userimageurl,
        'firstname' => $user->firstname,
        'lastname' => $user->lastname,
        'email' => $user->email,
        'fcmethod' => ($fcmethod == 'BS') ? true : false,
        'analyzeurl' => $analyzeurl,
    ];
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('quizaccess_proctoring/studentreport', $studentreportcontext);

    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->footer();