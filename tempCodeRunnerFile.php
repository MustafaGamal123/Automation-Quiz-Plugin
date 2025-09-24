<?php
require_once('../../../../config.php');
require_login();

$upload_dir = $CFG->dataroot . '/mod/quiz/accessrule/proctoring/uploads/warnings/';
$log_file = $upload_dir . 'upload_log.json';
$studentid = optional_param('studentid', 0, PARAM_INT);
$debug = optional_param('debug', 0, PARAM_INT);

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

function debug_database_connection() {
    global $DB, $CFG;
    
    $debug_info = [];
    
    try {
        // Test basic database connection
        $debug_info['db_connected'] = $DB->get_manager()->generator->get_name();
        $debug_info['db_type'] = $CFG->dbtype;
        $debug_info['db_host'] = $CFG->dbhost ?? 'Not set';
        
        // Test if table exists
        $dbman = $DB->get_manager();
        $table_exists = $dbman->table_exists('quizaccess_proctoring_suspicious_images');
        $debug_info['table_exists'] = $table_exists;
        
        if ($table_exists) {
            // Test basic query
            $count = $DB->count_records('quizaccess_proctoring_suspicious_images');
            $debug_info['record_count'] = $count;
            
            // Get table structure
            $columns = $DB->get_columns('quizaccess_proctoring_suspicious_images');
            $debug_info['table_columns'] = array_keys($columns);
        } else {
            $debug_info['error'] = 'Table does not exist';
        }
        
    } catch (Exception $e) {
        $debug_info['error'] = $e->getMessage();
        $debug_info['error_code'] = $e->getCode();
    }
    
    return $debug_info;
}

function create_table_if_not_exists() {
    global $DB;
    
    try {
        $dbman = $DB->get_manager();
        
        // Define table structure
        $table = new xmldb_table('quizaccess_proctoring_suspicious_images');
        
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('suspicion_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
        $table->add_field('image_data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('filepath', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('flagged', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('note', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('flag_timestamp', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('flag_user_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('ip_address', XMLDB_TYPE_CHAR, '45', null, XMLDB_NOTNULL, null, '');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_index('quizid_userid', XMLDB_INDEX_NOTUNIQUE, array('quizid', 'userid'));
        $table->add_index('timemodified', XMLDB_INDEX_NOTUNIQUE, array('timemodified'));
        
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            return 'Table created successfully';
        } else {
            return 'Table already exists';
        }
        
    } catch (Exception $e) {
        return 'Error creating table: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    header('Content-Type: application/json');
    
    try {
        global $DB;
        
        // Check if table exists first
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('quizaccess_proctoring_suspicious_images')) {
            throw new Exception('Table does not exist');
        }
        
        $DB->delete_records('quizaccess_proctoring_suspicious_images');
        
        if (is_dir($upload_dir)) {
            $items = glob($upload_dir . 'quiz_*', GLOB_ONLYDIR);
            foreach ($items as $item) {
                if (is_dir($item)) {
                    $files = glob($item . '/*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                    rmdir($item);
                }
            }
        }
        
        if (file_exists($log_file)) {
            unlink($log_file);
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => 'All data deleted successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        global $DB, $USER;
        
        // Check if table exists, create if not
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('quizaccess_proctoring_suspicious_images')) {
            $create_result = create_table_if_not_exists();
            error_log('Table creation result: ' . $create_result);
        }
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            throw new Exception('Invalid JSON data');
        }
        
        if (isset($data['action']) && $data['action'] === 'update_flag') {
            $filename = clean_param($data['filename'], PARAM_FILE);
            $quiz_id = clean_param($data['quiz_id'], PARAM_INT);
            $is_flagged = clean_param($data['is_flagged'], PARAM_BOOL);
            $note = clean_param($data['note'] ?? '', PARAM_TEXT);
            
            if (empty($filename) || $quiz_id <= 0) {
                throw new Exception('Invalid filename or quiz ID');
            }
            
            $params = [
                'filename' => $filename,
                'quizid' => $quiz_id
            ];
            
            $record = $DB->get_record('quizaccess_proctoring_suspicious_images', $params);
            if ($record) {
                $record->flagged = $is_flagged ? 1 : 0;
                $record->note = $note;
                $record->flag_timestamp = time();
                $record->flag_user_id = $USER->id ?? 0;
                
                $DB->update_record('quizaccess_proctoring_suspicious_images', $record);
            } else {
                throw new Exception('Record not found for update');
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Flag updated successfully'
            ]);
            exit;
        }
        
        if (!isset($data['cmid']) || !isset($data['image']) || !isset($data['type'])) {
            throw new Exception('Missing required fields: cmid, image, type');
        }
        
        $cmid = clean_param($data['cmid'], PARAM_INT);
        $reportid = isset($data['reportid']) ? clean_param($data['reportid'], PARAM_INT) : time();
        $type = clean_param($data['type'], PARAM_ALPHANUMEXT);
        $image_data = $data['image'];
        $courseid = isset($data['courseid']) ? clean_param($data['courseid'], PARAM_INT) : 0;
        $userid = isset($data['userid']) ? clean_param($data['userid'], PARAM_INT) : ($USER->id ?? 0);
        
        if ($cmid <= 0 || empty($type) || $userid <= 0) {
            throw new Exception('Invalid required parameters');
        }
        
        $quiz_dir = $upload_dir . 'quiz_' . $cmid . '/';
        if (!file_exists($quiz_dir)) {
            if (!mkdir($quiz_dir, 0777, true)) {
                throw new Exception('Failed to create quiz directory');
            }
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "warning_{$type}_{$reportid}_{$timestamp}.png";
        $filepath = $quiz_dir . $filename;
        
        $image_data = str_replace('data:image/png;base64,', '', $image_data);
        $image_binary = base64_decode($image_data);
        
        if (!$image_binary) {
            throw new Exception('Invalid image data');
        }
        
        if (!file_put_contents($filepath, $image_binary)) {
            throw new Exception('Failed to save image');
        }
        
        $db_record = new stdClass();
        $db_record->reportid = $reportid;
        $db_record->courseid = $courseid;
        $db_record->quizid = $cmid;
        $db_record->userid = $userid;
        $db_record->suspicion_type = $type;
        $db_record->image_data = $data['image'];
        $db_record->filename = $filename;
        $db_record->filepath = $filepath;
        $db_record->filesize = filesize($filepath);
        $db_record->flagged = 0;
        $db_record->note = '';
        $db_record->flag_timestamp = null;
        $db_record->flag_user_id = null;
        $db_record->ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $db_record->timemodified = time();
        
        $insert_id = $DB->insert_record('quizaccess_proctoring_suspicious_images', $db_record);
        
        if (!$insert_id) {
            throw new Exception('Failed to insert record into database');
        }
        
        $log_entry = [
            'id' => $insert_id,
            'timestamp' => date('Y-m-d H:i:s'),
            'cmid' => $cmid,
            'reportid' => $reportid,
            'type' => $type,
            'filename' => $filename,
            'filepath' => $filepath,
            'filesize' => filesize($filepath),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_id' => $userid
        ];
        
        $existing_log = [];
        if (file_exists($log_file)) {
            $existing_log = json_decode(file_get_contents($log_file), true) ?: [];
        }
        
        $existing_log[] = $log_entry;
        file_put_contents($log_file, json_encode($existing_log, JSON_PRETTY_PRINT));
        
        echo json_encode([
            'status' => 'success',
            'filename' => $filename,
            'url' => $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/uploadwarning.php?view=' . urlencode($filename) . '&quiz=' . $cmid,
            'message' => 'Image uploaded successfully',
            'id' => $insert_id
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => basename($e->getFile())
        ]);
    }
    exit;
}

$PAGE->set_url('/mod/quiz/accessrule/proctoring/uploadwarning.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Proctoring Warnings Viewer');
$PAGE->set_heading('Proctoring Warnings Viewer');

if (isset($_GET['view']) && isset($_GET['quiz'])) {
    $filename = clean_param($_GET['view'], PARAM_FILE);
    $quiz_id = clean_param($_GET['quiz'], PARAM_INT);
    
    if (empty($filename) || $quiz_id <= 0) {
        header('HTTP/1.0 400 Bad Request');
        exit('Invalid parameters');
    }
    
    $image_path = $upload_dir . 'quiz_' . $quiz_id . '/' . $filename;
    
    if (file_exists($image_path) && is_readable($image_path)) {
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($image_path));
        readfile($image_path);
        exit;
    } else {
        header('HTTP/1.0 404 Not Found');
        exit('Image not found');
    }
}

echo $OUTPUT->header();

// Debug mode
if ($debug) {
    $debug_info = debug_database_connection();
    echo '<div style="background: #f0f0f0; padding: 20px; margin: 20px; border-radius: 5px;">';
    echo '<h3>Database Debug Information</h3>';
    echo '<pre>' . print_r($debug_info, true) . '</pre>';
    
    // Try to create table if it doesn't exist
    if (!isset($debug_info['table_exists']) || !$debug_info['table_exists']) {
        echo '<h4>Attempting to create table...</h4>';
        $create_result = create_table_if_not_exists();
        echo '<p>' . $create_result . '</p>';
    }
    echo '</div>';
}
?>

<style>
.warning-viewer {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.quiz-section {
    background: #f9f9f9;
    border: 2px solid #ddd;
    border-radius: 8px;
    margin: 20px 0;
    padding: 15px;
}

.quiz-title {
    background: #007cba;
    color: white;
    padding: 10px 15px;
    margin: -15px -15px 15px -15px;
    border-radius: 6px 6px 0 0;
    font-weight: bold;
}

.warnings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.warning-card {
    background: white;
    border: 2px solid #dc3545;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
}

.warning-image {
    width: 100%;
    max-width: 250px;
    height: 180px;
    object-fit: cover;
    border-radius: 5px;
    margin-bottom: 15px;
}

.warning-type {
    background: #dc3545;
    color: white;
    padding: 8px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: bold;
    display: inline-block;
    margin-top: 10px;
    margin-bottom: 8px;
}

.warning-timestamp {
    font-size: 12px;
    color: #666;
    margin-top: 8px;
}

.stats-card {
    background: #e7f3ff;
    border: 2px solid #007cba;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
    text-align: center;
}

.stats-number {
    font-size: 28px;
    font-weight: bold;
    color: #007cba;
}

.delete-all-btn {
    background: #dc3545;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 16px;
    margin: 20px auto;
    display: block;
    font-weight: bold;
}

.delete-all-btn:hover {
    background: #c82333;
}

.no-data {
    text-align: center;
    padding: 40px;
    color: #666;
    font-size: 18px;
}

.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 15px;
    border-radius: 5px;
    margin: 20px 0;
    border: 1px solid #f5c6cb;
}

.success-message {
    background: #d4edda;
    color: #155724;
    padding: 15px;
    border-radius: 5px;
    margin: 20px 0;
    border: 1px solid #c3e6cb;
}

.debug-btn {
    background: #17a2b8;
    color: white;
    padding: 8px 16px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 14px;
    margin: 10px;
}

.create-table-btn {
    background: #28a745;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 16px;
    margin: 10px;
}
</style>

<div class="warning-viewer">

<button onclick="location.href='?debug=1'" class="debug-btn">🔍 Debug Database</button>
<button onclick="createTable()" class="create-table-btn">🔧 Create Table</button>
<button onclick="deleteAllData()" class="delete-all-btn">🗑️ Delete All Data</button>

<?php
$database_working = false;
$error_message = '';

try {
    global $DB;

    // Check if table exists
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('quizaccess_proctoring_suspicious_images')) {
        echo '<div class="error-message">';
        echo '<strong>Table Missing:</strong> The table "quizaccess_proctoring_suspicious_images" does not exist. ';
        echo 'Click the "Create Table" button above to create it, or run the SQL script manually in your database.';
        echo '</div>';
    } else {
        $current_user_id = $studentid ? $studentid : $USER->id;
        $total_warnings = 0;
        $quiz_stats = [];

        $params = [];
        $sql = "SELECT * FROM {quizaccess_proctoring_suspicious_images}";
        if ($studentid) {
            $sql .= " WHERE userid = ?";
            $params[] = $studentid;
        }
        $sql .= " ORDER BY timemodified DESC";

        $db_records = $DB->get_records_sql($sql, $params);
        $database_working = true;

        if (!empty($db_records)) {
            foreach ($db_records as $record) {
                $quiz_id = $record->quizid;
                if (!isset($quiz_stats[$quiz_id])) {
                    $quiz_stats[$quiz_id] = ['total' => 0];
                }
                if (!isset($quiz_stats[$quiz_id][$record->suspicion_type])) {
                    $quiz_stats[$quiz_id][$record->suspicion_type] = 0;
                }
                $quiz_stats[$quiz_id][$record->suspicion_type]++;
                $quiz_stats[$quiz_id]['total']++;
                $total_warnings++;
            }
            
            echo '<div class="success-message">Database connection successful! Found ' . count($db_records) . ' records.</div>';
            
            echo '<div class="stats-card">';
            echo '<div class="stats-number">' . $total_warnings . '</div>';
            echo '<div>Total Recorded Warnings</div>';
            echo '<div style="margin-top: 10px;">';
            echo '<span style="margin: 0 10px;">📊 ' . count($quiz_stats) . ' Quizzes</span>';
            echo '</div>';
            echo '</div>';
            
            foreach ($quiz_stats as $quiz_id => $stats) {
                echo '<div class="quiz-section" data-quiz="' . $quiz_id . '">';
                echo '<div class="quiz-title">';
                echo '📝 Quiz ID: ' . $quiz_id . ' - ' . $stats['total'] . ' Warnings';
                echo '</div>';
                
                echo '<div class="warnings-grid">';
                
                foreach ($db_records as $record) {
                    if ($record->quizid == $quiz_id && $record->userid == $current_user_id) {
                        $image_url = $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/uploadwarning.php?view=' . 
                                   urlencode($record->filename) . '&quiz=' . $quiz_id;
                        
                        echo '<div class="warning-card" data-type="' . s($record->suspicion_type) . '" data-date="' . date('Y-m-d', $record->timemodified) . '">';
                        
                        if (file_exists($record->filepath)) {
                            echo '<img src="' . $image_url . '" alt="Warning Image" class="warning-image">';
                        } else {
                            echo '<div style="background: #f0f0f0; height: 180px; display: flex; align-items: center; justify-content: center; border-radius: 5px; color: #999; margin-bottom: 15px;">Image Not Available</div>';
                        }
                        
                        echo '<div class="warning-type">' . s($record->suspicion_type) . '</div>';
                        echo '<div class="warning-timestamp">' . date('Y-m-d H:i:s', $record->timemodified) . '</div>';
                        
                        echo '</div>';
                    }
                }
                
                echo '</div>';
                echo '</div>';
            }
            
        } else {
            echo '<div class="success-message">Database connection successful! No records found yet.</div>';
            echo '<div class="no-data">📭 No warnings recorded yet in database</div>';
        }
    }
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log('Database error in warning viewer: ' . $error_message);
    
    echo '<div class="error-message">';
    echo '<strong>Database Error Details:</strong><br>';
    echo 'Error: ' . htmlspecialchars($error_message) . '<br>';
    echo 'File: ' . basename($e->getFile()) . '<br>';
    echo 'Line: ' . $e->getLine() . '<br><br>';
    echo 'Possible solutions:<br>';
    echo '1. Check if the table exists in your database<br>';
    echo '2. Verify database connection settings in config.php<br>';
    echo '3. Check database permissions<br>';
    echo '4. Try clicking "Create Table" button above';
    echo '</div>';
}

// Fallback to log file if database fails
if (!$database_working && file_exists($log_file)) {
    echo '<div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 20px 0;">';
    echo '<strong>Fallback:</strong> Reading from log file since database is not available.';
    echo '</div>';
    
    $log_data = json_decode(file_get_contents($log_file), true) ?: [];
    
    if (!empty($log_data)) {
        $quiz_stats = [];
        $total_warnings = 0;
        
        foreach ($log_data as $entry) {
            if ($studentid && (!isset($entry['user_id']) || $entry['user_id'] != $studentid)) {
                continue;
            }
            $quiz_id = $entry['cmid'] ?? 0;
            if (!isset($quiz_stats[$quiz_id])) {
                $quiz_stats[$quiz_id] = ['total' => 0];
            }
            $type = $entry['type'] ?? 'unknown';
            if (!isset($quiz_stats[$quiz_id][$type])) {
                $quiz_stats[$quiz_id][$type] = 0;
            }
            $quiz_stats[$quiz_id][$type]++;
            $quiz_stats[$quiz_id]['total']++;
            $total_warnings++;
        }
        
        echo '<div class="stats-card">';
        echo '<div class="stats-number">' . $total_warnings . '</div>';
        echo '<div>Total Warnings (From Log File)</div>';
        echo '</div>';
        
        foreach ($quiz_stats as $quiz_id => $stats) {
            echo '<div class="quiz-section">';
            echo '<div class="quiz-title">Quiz ID: ' . $quiz_id . ' - ' . $stats['total'] . ' Warnings</div>';
            echo '<div class="warnings-grid">';
            
            foreach ($log_data as $entry) {
                if (($entry['cmid'] ?? 0) == $quiz_id) {
                    $image_path = $upload_dir . 'quiz_' . $quiz_id . '/' . $entry['filename'];
                    if (file_exists($image_path)) {
                        $image_url = $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/uploadwarning.php?view=' . 
                                   urlencode($entry['filename']) . '&quiz=' . $quiz_id;
                        
                        echo '<div class="warning-card">';
                        echo '<img src="' . $image_url . '" alt="Warning" class="warning-image">';
                        echo '<div class="warning-type">' . s($entry['type'] ?? 'unknown') . '</div>';
                        echo '<div class="warning-timestamp">' . s($entry['timestamp'] ?? '') . '</div>';
                        echo '</div>';
                    }
                }
            }
            echo '</div></div>';
        }
    }
}
?>

</div>

<script>
function deleteAllData() {
    if (confirm('Are you sure you want to delete ALL warning data? This action cannot be undone.')) {
        if (confirm('This will permanently delete all images and logs. Are you absolutely sure?')) {
            fetch(window.location.href, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('All data has been deleted successfully.');
                    location.reload();
                } else {
                    alert('Error deleting data: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                alert('Error: ' + error.message);
            });
        }
    }
}

function createTable() {
    if (confirm('This will create the missing database table. Continue?')) {
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'create_table'
            })
        })
        .then(response => response.json())
        .then(data => {
            alert(data.message || data.error || 'Table creation attempted');
            location.reload();
        })
        .catch(error => {
            console.error('Create table error:', error);
            alert('Error: ' + error.message);
        });
    }
}
</script>

<?php
echo $OUTPUT->footer();
?>