<?php
// student_warnings_report.php
require_once('../../../../config.php');
require_login();

$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$quizid = optional_param('quizid', 0, PARAM_INT);
$quizname = optional_param('quizname', 'Unknown Quiz', PARAM_TEXT);
$studentid = optional_param('studentid', 0, PARAM_INT);
$format = optional_param('format', 'html', PARAM_ALPHA);

$context = context_system::instance();
require_capability('mod/quiz:viewreports', $context);

$upload_dir = $CFG->dataroot . '/mod/quiz/accessrule/proctoring/uploads/warnings/';
$log_file = $upload_dir . 'upload_log.json';

if ($format === 'text') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_warnings_report.txt"');
    
    $report_data = generate_warnings_report($log_file, $cmid, $studentid);
    
    foreach ($report_data as $line) {
        echo $line . "\n";
    }
    exit;
}

$PAGE->set_url('/mod/quiz/accessrule/proctoring/student_warnings_report.php');
$PAGE->set_context($context);


echo $OUTPUT->header();

display_warnings_report($log_file, $cmid, $studentid, $quizname);

echo $OUTPUT->footer();

function generate_warnings_report($log_file, $cmid, $studentid) {
    global $DB;
    
    $report_lines = [];
    
    if (!file_exists($log_file)) {
        $report_lines[] = "No warnings data found.";
        return $report_lines;
    }
    
    $log_data = json_decode(file_get_contents($log_file), true) ?: [];
    
    if (empty($log_data)) {
        $report_lines[] = "No warnings recorded yet.";
        return $report_lines;
    }
    
    foreach ($log_data as $entry) {
        if ($cmid && ($entry['cmid'] ?? 0) != $cmid) {
            continue;
        }
        
        if ($studentid && ($entry['user_id'] ?? 0) != $studentid) {
            continue;
        }
        
        $userid = $entry['user_id'] ?? 0;
        $warning_type = $entry['type'] ?? 'Unknown Warning';
        $timestamp = $entry['timestamp'] ?? 'Unknown Time';
        
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            continue;
        }
        
        $username = fullname($user);
        $useremail = $user->email;
        
        $quiz_name = 'Unknown Quiz';
        if (isset($entry['quizname'])) {
            $quiz_name = $entry['quizname'];
        }
        
        $report_lines[] = "-The student named {$username}, with the email {$useremail}, received the warning {$warning_type} in the quiz titled {$quiz_name}.";
    }
    
    if (empty($report_lines)) {
        $report_lines[] = "No warnings found for the selected criteria.";
    }
    
    return $report_lines;
}

function display_warnings_report($log_file, $cmid, $studentid, $quizname) {
    global $DB, $OUTPUT, $CFG;
    
    echo '<div class="student-warnings-report">';
    echo '<div class="report-header">';
    echo '<h2>Student Warnings Report</h2>';
    
    echo '<div class="report-controls">';
    
    $export_text_url = new moodle_url('/mod/quiz/accessrule/proctoring/student_warnings_report.php', [
        'cmid' => $cmid,
        'studentid' => $studentid,
        'format' => 'text'
    ]);
    
    echo '<a href="' . $export_text_url . '" class="btn btn-primary">';
    echo '<i class="fa fa-download"></i> Export as Text File';
    echo '</a>';
    
    echo '</a>';
    
    echo '</div>';
    echo '</div>'; 
    
    if (!file_exists($log_file)) {
        echo '<div class="alert alert-info">No warnings data found.</div>';
        return;
    }
    
    $log_data = json_decode(file_get_contents($log_file), true) ?: [];
    
    if (empty($log_data)) {
        echo '<div class="alert alert-info">No warnings recorded yet.</div>';
        return;
    }
    
    $total_warnings = 0;
    $unique_students = [];
    $warning_types = [];
    
    foreach ($log_data as $entry) {
        if ($cmid && ($entry['cmid'] ?? 0) != $cmid) {
            continue;
        }
        
        if ($studentid && ($entry['user_id'] ?? 0) != $studentid) {
            continue;
        }
        
        $total_warnings++;
        $userid = $entry['user_id'] ?? 0;
        $warning_type = $entry['type'] ?? 'Unknown';
        
        if (!in_array($userid, $unique_students)) {
            $unique_students[] = $userid;
        }
        
        if (!isset($warning_types[$warning_type])) {
            $warning_types[$warning_type] = 0;
        }
        $warning_types[$warning_type]++;
    }
    
    echo '<div class="report-stats">';
    echo '<div class="stat-card">';
    echo '<div class="stat-number">' . $total_warnings . '</div>';
    echo '<div class="stat-label">Total Warnings</div>';
    echo '</div>';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-number">' . count($unique_students) . '</div>';
    echo '<div class="stat-label">Affected Students</div>';
    echo '</div>';
    

    
    echo '<div class="warnings-list">';
    
    $has_data = false;
    foreach ($log_data as $entry) {
        if ($cmid && ($entry['cmid'] ?? 0) != $cmid) {
            continue;
        }
        
        if ($studentid && ($entry['user_id'] ?? 0) != $studentid) {
            continue;
        }
        
        $has_data = true;
        $userid = $entry['user_id'] ?? 0;
        $warning_type = $entry['type'] ?? 'Unknown Warning';
        $timestamp = $entry['timestamp'] ?? 'Unknown Time';
        $flagged = $entry['flagged'] ?? false;
        $note = $entry['note'] ?? '';
        
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            continue;
        }
        
        $username = fullname($user);
        $useremail = $user->email;
        
        $quiz_name = $quizname;
        if (isset($entry['quizname'])) {
            $quiz_name = $entry['quizname'];
        }
        
        echo '<div class="warning-item ' . ($flagged ? 'flagged' : '') . '">';
        echo '<div class="warning-content">';
        echo '<p class="warning-text">';
        echo '-The student named <strong>' . $username . '</strong>, ';
        echo 'with the email <strong>' . $useremail . '</strong>, ';
        echo 'received the warning <span class="warning-type">' . $warning_type . '</span> ';
        echo 'in the quiz titled <strong>"' . $quiz_name . '"</strong>.';
        echo '</p>';
        
        echo '<div class="warning-meta">';
        echo '<span class="timestamp"><i class="fa fa-clock-o"></i> ' . $timestamp . '</span>';
        
        if ($flagged) {
            echo '<span class="flag-badge"><i class="fa fa-flag"></i> Flagged</span>';
        }
        
        if (!empty($note)) {
            echo '<span class="note-indicator" title="' . s($note) . '"><i class="fa fa-sticky-note"></i> Has Note</span>';
        }
        
        if (isset($entry['filename'])) {
            $image_url = new moodle_url('/mod/quiz/accessrule/proctoring/uploadwarning.php', [
                'view' => $entry['filename'],
                'quiz' => $entry['cmid']
            ]);
            echo '<a href="' . $image_url . '" target="_blank" class="image-link"><i class="fa fa-image"></i> View Image</a>';
        }
        
        echo '</div>'; 
        echo '</div>'; 
        echo '</div>'; 
    }
    
    if (!$has_data) {
        echo '<div class="alert alert-warning">No warnings found for the selected criteria.</div>';
    }
    
    echo '</div>'; 
    echo '</div>'; 
}

echo '
<style>
.student-warnings-report {
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #eee;
}

.report-controls {
    display: flex;
    gap: 10px;
}

.report-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    min-width: 120px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.stat-number {
    font-size: 2em;
    font-weight: bold;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.9em;
    opacity: 0.9;
}

.warnings-list {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
}

.warning-item {
    background: white;
    border-left: 4px solid #007bff;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 15px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    transition: transform 0.2s, box-shadow 0.2s;
}

.warning-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.warning-item.flagged {
    border-left-color: #dc3545;
    background: #fff5f5;
}

.warning-text {
    margin: 0 0 10px 0;
    font-size: 1.1em;
    line-height: 1.5;
}

.warning-type {
    background: #ffc107;
    color: #212529;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.9em;
    font-weight: bold;
}

.warning-meta {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
    font-size: 0.9em;
    color: #6c757d;
}

.timestamp, .flag-badge, .note-indicator, .image-link {
    display: flex;
    align-items: center;
    gap: 5px;
}

.flag-badge {
    background: #dc3545;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.8em;
}

.note-indicator {
    background: #17a2b8;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.8em;
    cursor: help;
}

.image-link {
    color: #007bff;
    text-decoration: none;
}

.image-link:hover {
    text-decoration: underline;
}

@media (max-width: 768px) {
    .report-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .report-stats {
        justify-content: center;
    }
    
    .warning-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
}
</style>
';