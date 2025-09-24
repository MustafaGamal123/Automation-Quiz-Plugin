<?php
require_once('../../../../config.php');
require_login();

$upload_dir = $CFG->dataroot . '/mod/quiz/accessrule/proctoring/uploads/warnings/';
$log_file = $upload_dir . 'upload_log.json';
$studentid = optional_param('studentid', 0, PARAM_INT);

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    header('Content-Type: application/json');
    
    try {
        if (is_dir($upload_dir)) {
            $quiz_dirs = glob($upload_dir . 'quiz_*', GLOB_ONLYDIR);
            foreach ($quiz_dirs as $dir) {
                $files = glob($dir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) unlink($file);
                }
                rmdir($dir);
            }
        }

        if (file_exists($log_file)) {
            file_put_contents($log_file, json_encode([])); // خلي الـ log فاضي
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
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            throw new Exception('Invalid JSON data');
        }
        
        if (isset($data['action']) && $data['action'] === 'update_flag') {
            $filename = basename($data['filename']);
            $quiz_id = intval($data['quiz_id']);
            $is_flagged = $data['is_flagged'];
            $note = $data['note'] ?? '';
            
            $log_file = $upload_dir . 'upload_log.json';
            if (file_exists($log_file)) {
                $log_data = json_decode(file_get_contents($log_file), true) ?: [];
                
                foreach ($log_data as &$entry) {
                    if ($entry['filename'] === $filename && $entry['cmid'] == $quiz_id) {
                        $entry['flagged'] = $is_flagged;
                        $entry['note'] = $note;
                        $entry['flag_timestamp'] = date('Y-m-d H:i:s');
                        $entry['flag_user_id'] = $USER->id ?? 0;
                        break;
                    }
                }
                
                file_put_contents($log_file, json_encode($log_data, JSON_PRETTY_PRINT));
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Flag updated successfully'
            ]);
            exit;
        }
        
        if (isset($data['action']) && $data['action'] === 'delete_image') {
            $filename = basename($data['filename']);
            $quiz_id = intval($data['quiz_id']);
            
            $image_path = $upload_dir . 'quiz_' . $quiz_id . '/' . $filename;
            if (file_exists($image_path)) {
                unlink($image_path);
            }
            
            if (file_exists($log_file)) {
                $log_data = json_decode(file_get_contents($log_file), true) ?: [];
                $log_data = array_filter($log_data, function($entry) use ($filename, $quiz_id) {
                    return !($entry['filename'] === $filename && $entry['cmid'] == $quiz_id);
                });
                file_put_contents($log_file, json_encode(array_values($log_data), JSON_PRETTY_PRINT));
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Image deleted successfully'
            ]);
            exit;
        }
        
        if (!isset($data['cmid']) || !isset($data['image']) || !isset($data['type'])) {
            throw new Exception('Missing required fields: cmid, image, type');
        }
        
        $cmid = intval($data['cmid']);
        $reportid = isset($data['reportid']) ? intval($data['reportid']) : time();
        $type = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['type']);
        $image_data = $data['image'];
        
        $quiz_dir = $upload_dir . 'quiz_' . $cmid . '/';
        if (!file_exists($quiz_dir)) {
            mkdir($quiz_dir, 0777, true);
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
        
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'cmid' => $cmid,
            'reportid' => $reportid,
            'type' => $type,
            'filename' => $filename,
            'filepath' => $filepath,
            'filesize' => filesize($filepath),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_id' => $USER->id ?? 0,
            'flagged' => false,
            'note' => ''
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
            'message' => 'Image uploaded successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

$PAGE->set_url('/mod/quiz/accessrule/proctoring/uploadwarning.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Proctoring Warnings Viewer');
$PAGE->set_heading('Proctoring Warnings Viewer');

if (isset($_GET['view']) && isset($_GET['quiz'])) {
    $filename = basename($_GET['view']);
    $quiz_id = intval($_GET['quiz']);
    $image_path = $upload_dir . 'quiz_' . $quiz_id . '/' . $filename;
    
    if (file_exists($image_path)) {
        header('Content-Type: image/png');
        readfile($image_path);
        exit;
    } else {
        header('HTTP/1.0 404 Not Found');
        exit('Image not found');
    }
}

echo $OUTPUT->header();
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
    position: relative;
}

.quiz-title {
    background: #007cba;
    color: white;
    padding: 10px 15px;
    margin: -15px -15px 15px -15px;
    border-radius: 6px 6px 0 0;
    font-weight: bold;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.warnings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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
    position: relative;
    transition: transform 0.2s, box-shadow 0.2s;
}

.warning-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.warning-card.flagged {
    border-color: #ffc107;
    background: #fff9e6;
}

.warning-image {
    width: 100%;
    max-width: 250px;
    height: 180px;
    object-fit: cover;
    border-radius: 5px;
    margin-bottom: 15px;
    cursor: pointer;
    transition: opacity 0.2s;
}

.warning-image:hover {
    opacity: 0.8;
}

.warning-timestamp {
    font-size: 12px;
    color: #666;
    margin: 8px 0;
}

.warning-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: bold;
    transition: background-color 0.2s;
}

.flag-btn {
    background: #ffc107;
    color: #000;
}

.flag-btn:hover {
    background: #e0a800;
}

.flag-btn.flagged {
    background: #28a745;
    color: white;
}

.delete-btn {
    background: #dc3545;
    color: white;
}

.delete-btn:hover {
    background: #c82333;
}

.fullscreen-btn {
    background: #6c757d;
    color: white;
}

.fullscreen-btn:hover {
    background: #545b62;
}

.stats-card {
    background: linear-gradient(135deg, #e7f3ff, #f0f8ff);
    border: 2px solid #007cba;
    border-radius: 12px;
    padding: 20px;
    margin: 20px 0;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,124,186,0.1);
}

.stats-number {
    font-size: 28px;
    font-weight: bold;
    color: #007cba;
}

.stats-breakdown {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-top: 15px;
    flex-wrap: wrap;
}

.stat-item {
    background: white;
    padding: 10px 15px;
    border-radius: 8px;
    border: 1px solid #ddd;
    min-width: 80px;
}

.refresh-btn {
    background: #28a745;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 16px;
    margin: 10px;
    transition: background-color 0.2s;
}

.refresh-btn:hover {
    background: #218838;
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
    transition: background-color 0.2s;
}

.delete-all-btn:hover {
    background: #c82333;
}

.export-btn {
    background: #17a2b8;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    margin: 10px;
    transition: background-color 0.2s;
}

.export-btn:hover {
    background: #138496;
}

.no-data {
    text-align: center;
    padding: 40px;
    color: #666;
    font-size: 18px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 2px dashed #dee2e6;
}

.filter-controls {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.filter-row {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.filter-controls select,
.filter-controls input {
    padding: 8px 12px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.filter-controls select:focus,
.filter-controls input:focus {
    border-color: #007cba;
    outline: none;
}

.search-box {
    flex: 1;
    min-width: 200px;
}

.image-placeholder {
    background: linear-gradient(135deg, #f0f0f0, #e0e0e0);
    height: 180px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 5px;
    color: #999;
    margin-bottom: 15px;
    font-size: 14px;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.95);
    z-index: 1000;
    cursor: pointer;
}

.modal img {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    max-width: 100vw;
    max-height: 100vh;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 8px;
}

.modal-close {
    position: absolute;
    top: 20px;
    right: 30px;
    color: white;
    font-size: 40px;
    cursor: pointer;
    z-index: 1001;
    background: rgba(0,0,0,0.5);
    border-radius: 50%;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close:hover {
    background: rgba(0,0,0,0.8);
}

.note-input {
    width: 100%;
    margin-top: 10px;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
    resize: vertical;
    min-height: 60px;
}

.flag-indicator {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #ffc107;
    color: #000;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: bold;
}

.auto-refresh-indicator {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #28a745;
    color: white;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

@media (max-width: 768px) {
    .warnings-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .warning-actions {
        flex-direction: column;
    }
    
    .modal img {
        max-width: 95vw;
        max-height: 95vh;
    }
}
</style>

<div class="warning-viewer">

<?php
$current_user_id = $studentid !== null ? $studentid : ($USER->id ?? 0);

$total_warnings = 0;
$quiz_stats = [];
$warning_types = [];
$warning_dates = [];

if (file_exists($log_file)) {
    $log_data = json_decode(file_get_contents($log_file), true) ?: [];
    
    if (!empty($log_data)) {
        foreach ($log_data as $entry) {
            if ($studentid && (!isset($entry['user_id']) || $entry['user_id'] != $studentid)) {
                continue;
            }
            
            $quiz_id = $entry['cmid'] ?? 0;
            $entry_type = $entry['type'] ?? 'unknown';
            $entry_date = date('Y-m-d', strtotime($entry['timestamp'] ?? 'now'));
            
            if (!in_array($entry_type, $warning_types)) {
                $warning_types[] = $entry_type;
            }
            if (!in_array($entry_date, $warning_dates)) {
                $warning_dates[] = $entry_date;
            }
            
            if (!isset($quiz_stats[$quiz_id])) {
                $quiz_stats[$quiz_id] = ['total' => 0, 'flagged' => 0];
            }
            
            if (!isset($quiz_stats[$quiz_id][$entry_type])) {
                $quiz_stats[$quiz_id][$entry_type] = 0;
            }
            
            $quiz_stats[$quiz_id][$entry_type]++;
            $quiz_stats[$quiz_id]['total']++;
            if (isset($entry['flagged']) && $entry['flagged']) {
                $quiz_stats[$quiz_id]['flagged']++;
            }
            $total_warnings++;
        }
        
        sort($warning_dates);
        sort($warning_types);
        
        $total_flagged = array_sum(array_column($quiz_stats, 'flagged'));
        echo '<div class="stats-card">';
        echo '<div class="stats-number">' . $total_warnings . '</div>';
        echo '<div>Total Recorded Warnings</div>';
        echo '<div class="stats-breakdown">';
        echo '<div class="stat-item"><strong>' . count($quiz_stats) . '</strong><br>Quizzes</div>';
        echo '<div class="stat-item"><strong>' . $total_flagged . '</strong><br>Flagged</div>';
        echo '<div class="stat-item"><strong>' . ($total_warnings - $total_flagged) . '</strong><br>Unflagged</div>';
        echo '</div>';
        echo '</div>';
        
        foreach ($quiz_stats as $quiz_id => $stats) {
            echo '<div class="quiz-section" data-quiz="' . $quiz_id . '">';
            echo '<div class="quiz-title">';
            echo '<span>📝 Quiz ID: ' . $quiz_id . ' - ' . ($stats['total'] ?? 0) . ' Warnings</span>';
            echo '<span>⚠️ ' . ($stats['flagged'] ?? 0) . ' Flagged</span>';
            echo '</div>';
            
            echo '<div class="warnings-grid">';
            
            foreach ($log_data as $entry) {
                if (($entry['cmid'] ?? 0) == $quiz_id && ($entry['user_id'] ?? 0) == $current_user_id) {
                    $image_url = $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/uploadwarning.php?view=' . 
                               urlencode($entry['filename'] ?? '') . '&quiz=' . $quiz_id;
                    
                    $is_flagged = isset($entry['flagged']) && $entry['flagged'];
                    $note = $entry['note'] ?? '';
                    
                    echo '<div class="warning-card' . ($is_flagged ? ' flagged' : '') . '" 
                            data-type="' . ($entry['type'] ?? 'unknown') . '" 
                            data-date="' . date('Y-m-d', strtotime($entry['timestamp'] ?? 'now')) . '"
                            data-flagged="' . ($is_flagged ? 'true' : 'false') . '"
                            data-filename="' . ($entry['filename'] ?? '') . '"
                            data-note="' . htmlspecialchars($note) . '">';
                    
                    if ($is_flagged) {
                        echo '<div class="flag-indicator">🚩 FLAGGED</div>';
                    }
                    
                    $filename = $entry['filename'] ?? '';
                    if ($filename && file_exists($upload_dir . 'quiz_' . $quiz_id . '/' . $filename)) {
                        echo '<img src="' . $image_url . '" alt="Warning Image" class="warning-image" onclick="showFullscreen(this.src)">';
                    } else {
                        echo '<div class="image-placeholder">Image Not Available</div>';
                    }
                    
                    if ($note) {
                        echo '<div style="font-size: 12px; color: #666; margin: 8px 0; font-style: italic;">📝 ' . htmlspecialchars($note) . '</div>';
                    }
                    
                    echo '<div class="warning-actions">';
                    echo '<button class="action-btn flag-btn' . ($is_flagged ? ' flagged' : '') . '" onclick="toggleFlag(\'' . $filename . '\', ' . $quiz_id . ', ' . ($is_flagged ? 'false' : 'true') . ')">' . ($is_flagged ? '✓ Flagged' : '🚩 Flag') . '</button>';
                    echo '<button class="action-btn fullscreen-btn" onclick="showFullscreen(\'' . $image_url . '\')">🔍 View</button>';
                    echo '<button class="action-btn delete-btn" onclick="deleteImage(\'' . $filename . '\', ' . $quiz_id . ')">🗑️ Delete</button>';
                    echo '</div>';
                    
                    echo '<textarea class="note-input" placeholder="Add a note..." onchange="updateNote(\'' . $filename . '\', ' . $quiz_id . ', this.value)">' . htmlspecialchars($note) . '</textarea>';
                    
                    echo '</div>';
                }
            }
            
            echo '</div>';
            echo '</div>';
        }
        
    } else {
        echo '<div class="no-data">🔭 No warnings recorded yet<br><small>Start a quiz with proctoring enabled to see warnings here</small></div>';
    }
} else {
    echo '<div class="no-data">📄 Log file does not exist<br><small>No data has been recorded yet</small></div>';
}
?>

</div>

<div id="imageModal" class="modal" onclick="closeModal()">
    <span class="modal-close" onclick="closeModal()">&times;</span>
    <img id="modalImage" src="" alt="Full Size Warning">
</div>

<div id="autoRefreshIndicator" class="auto-refresh-indicator" style="display: none;">
    Auto refresh: <span id="refreshCountdown">30</span>s
</div>

<script>
let autoRefreshEnabled = false;
let refreshTimer = null;
let countdownTimer = null;
let refreshCountdown = 30;

<?php
echo "const warningTypes = " . json_encode($warning_types) . ";\n";
echo "const warningDates = " . json_encode($warning_dates) . ";\n";
?>



function deleteImage(filename, quizId) {
    if (confirm('Delete this warning image? This action cannot be undone.')) {
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'delete_image',
                filename: filename,
                quiz_id: quizId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                alert('✅ Image deleted successfully.');
                location.reload();
            } else {
                alert('❌ Error deleting image: ' + data.error);
            }
        })
        .catch(error => {
            alert('❌ Error: ' + error);
        });
    }
}

function toggleFlag(filename, quizId, flagStatus) {
    const note = document.querySelector(`[data-filename="${filename}"] .note-input`).value || '';
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'update_flag',
            filename: filename,
            quiz_id: quizId,
            is_flagged: flagStatus,
            note: note
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            location.reload();
        } else {
            alert('❌ Error updating flag: ' + data.error);
        }
    })
    .catch(error => {
        alert('❌ Error: ' + error);
    });
}

function updateNote(filename, quizId, note) {
    const card = document.querySelector(`[data-filename="${filename}"]`);
    const isFlagged = card.getAttribute('data-flagged') === 'true';
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'update_flag',
            filename: filename,
            quiz_id: quizId,
            is_flagged: isFlagged,
            note: note
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            card.setAttribute('data-note', note);
            const noteInput = card.querySelector('.note-input');
            noteInput.style.borderColor = '#28a745';
            setTimeout(() => {
                noteInput.style.borderColor = '#ddd';
            }, 1000);
        } else {
            alert('❌ Error updating note: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error updating note:', error);
    });
}

function showFullscreen(imageSrc) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    modal.style.display = 'block';
    modalImg.src = imageSrc;
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    const modal = document.getElementById('imageModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

function filterWarnings() {
    const searchTerm = document.getElementById('searchBox')?.value.toLowerCase() || '';
    const typeFilter = document.getElementById('typeFilter')?.value || '';
    const dateFilter = document.getElementById('dateFilter')?.value || '';
    const flagFilter = document.getElementById('flagFilter')?.value || '';
    const cards = document.querySelectorAll('.warning-card');
    
    let visibleCount = 0;
    
    cards.forEach(card => {
        const cardType = card.getAttribute('data-type');
        const cardDate = card.getAttribute('data-date');
        const cardFlagged = card.getAttribute('data-flagged') === 'true';
        const cardNote = card.getAttribute('data-note').toLowerCase();
        const cardFilename = card.getAttribute('data-filename').toLowerCase();
        
        let showCard = true;
        
        if (searchTerm && !cardType.toLowerCase().includes(searchTerm) && 
            !cardNote.includes(searchTerm) && !cardFilename.includes(searchTerm)) {
            showCard = false;
        }
        
        if (typeFilter && cardType !== typeFilter) {
            showCard = false;
        }
        
        if (dateFilter && cardDate !== dateFilter) {
            showCard = false;
        }
        
        if (flagFilter === 'flagged' && !cardFlagged) {
            showCard = false;
        } else if (flagFilter === 'unflagged' && cardFlagged) {
            showCard = false;
        }
        
        card.style.display = showCard ? 'block' : 'none';
        if (showCard) visibleCount++;
    });
    
    document.querySelectorAll('.quiz-section').forEach(section => {
        const visibleCards = section.querySelectorAll('.warning-card[style*="block"], .warning-card:not([style*="none"])');
        section.style.display = visibleCards.length > 0 ? 'block' : 'none';
    });
    
    updateSearchResults(visibleCount);
}

function updateSearchResults(count) {
    let indicator = document.getElementById('searchResults');
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'searchResults';
        indicator.style.cssText = 'text-align: center; padding: 10px; color: #666; font-size: 14px;';
        const filterControls = document.querySelector('.filter-controls');
        if (filterControls) {
            filterControls.appendChild(indicator);
        }
    }
    
    if (count === document.querySelectorAll('.warning-card').length) {
        indicator.style.display = 'none';
    } else {
        indicator.textContent = `📊 Showing ${count} of ${document.querySelectorAll('.warning-card').length} warnings`;
        indicator.style.display = 'block';
    }
}

function exportData() {
    const cards = document.querySelectorAll('.warning-card');
    let csvContent = 'Quiz ID,Type,Timestamp,Filename,Flagged,Note,Status\n';
    
    cards.forEach(card => {
        if (card.style.display !== 'none') {
            const quizId = card.closest('.quiz-section').getAttribute('data-quiz');
            const type = card.getAttribute('data-type');
            const filename = card.getAttribute('data-filename');
            const flagged = card.getAttribute('data-flagged');
            const note = card.getAttribute('data-note').replace(/"/g, '""');
            const timestamp = card.querySelector('.warning-timestamp')?.textContent.replace('📅 ', '') || '';
            
            csvContent += `"${quizId}","${type}","${timestamp}","${filename}","${flagged}","${note}","Active"\n`;
        }
    });
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `proctoring_warnings_${new Date().toISOString().split('T')[0]}.csv`;
    link.click();
}

function toggleAutoRefresh() {
    autoRefreshEnabled = !autoRefreshEnabled;
    const btn = document.getElementById('autoRefreshBtn');
    const indicator = document.getElementById('autoRefreshIndicator');
    
    if (autoRefreshEnabled) {
        if (btn) {
            btn.textContent = '⏰ Auto Refresh: ON';
            btn.style.background = '#dc3545';
        }
        indicator.style.display = 'block';
        startAutoRefresh();
    } else {
        if (btn) {
            btn.textContent = '⏰ Auto Refresh: OFF';
            btn.style.background = '#28a745';
        }
        indicator.style.display = 'none';
        stopAutoRefresh();
    }
}

function startAutoRefresh() {
    refreshCountdown = 30;
    updateCountdown();
    
    countdownTimer = setInterval(() => {
        refreshCountdown--;
        updateCountdown();
        
        if (refreshCountdown <= 0) {
            location.reload();
        }
    }, 1000);
}

function stopAutoRefresh() {
    if (countdownTimer) {
        clearInterval(countdownTimer);
        countdownTimer = null;
    }
}

function updateCountdown() {
    const countdownElement = document.getElementById('refreshCountdown');
    if (countdownElement) {
        countdownElement.textContent = refreshCountdown;
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
    
    if (e.key === 'F5' || (e.ctrlKey && e.key === 'r')) {
        e.preventDefault();
        location.reload();
    }
    
    if (e.ctrlKey && e.key === 'e') {
        e.preventDefault();
        exportData();
    }
    
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        const searchBox = document.getElementById('searchBox');
        if (searchBox) {
            searchBox.focus();
        }
    }
});

const searchBox = document.getElementById('searchBox');
if (searchBox) {
    searchBox.addEventListener('input', filterWarnings);
}

document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.warning-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        setTimeout(() => {
            card.style.transition = 'opacity 0.3s, transform 0.3s';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 50);
    });
    
    setTimeout(() => {
        if (document.querySelectorAll('.warning-card').length > 5) {
            console.log('💡 Keyboard Shortcuts:\n- ESC: Close fullscreen\n- Ctrl+F: Focus search\n- Ctrl+E: Export data\n- F5: Refresh page');
        }
    }, 3000);
});

window.addEventListener('beforeunload', function(e) {
    const hasUnsavedNotes = Array.from(document.querySelectorAll('.note-input')).some(input => {
        const card = input.closest('.warning-card');
        return input.value !== card.getAttribute('data-note');
    });
    
    if (hasUnsavedNotes) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.addEventListener('click', function(e) {
    const modal = document.getElementById('imageModal');
    if (e.target === modal) {
        closeModal();
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>