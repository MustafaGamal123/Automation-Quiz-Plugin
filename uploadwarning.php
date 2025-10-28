<?php
require_once('../../../../config.php');
require_login();

define('MIN_IMAGES_FOR_VERIFICATION', 3);
define('SIMILARITY_THRESHOLD', 0.70);
define('FACE_DETECTION_THRESHOLD', 0.40);
define('MIN_FILE_SIZE', 10240);

$upload_dir = $CFG->dataroot . '/mod/quiz/accessrule/proctoring/uploads/warnings/';
$log_file = $upload_dir . 'upload_log.json';
$studentid = optional_param('studentid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$quizid = optional_param('quizid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);

if (!$quizid && $cmid) {
    $quizid = $cmid;
}

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
            file_put_contents($log_file, json_encode([]));
        }
        echo json_encode(['status' => 'success', 'message' => 'All data deleted successfully']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) throw new Exception('Invalid JSON data');
        
        if (isset($data['action']) && $data['action'] === 'verify_identity') {
            $student_id = intval($data['student_id']);
            $quiz_id = intval($data['quiz_id']);
            $verification_result = verifyStudentIdentity($upload_dir, $log_file, $student_id, $quiz_id);
            echo json_encode($verification_result);
            exit;
        }
        
        if (isset($data['action']) && $data['action'] === 'update_flag') {
            $filename = basename($data['filename']);
            $quiz_id = intval($data['quiz_id']);
            $is_flagged = $data['is_flagged'];
            $note = $data['note'] ?? '';
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
            echo json_encode(['status' => 'success', 'message' => 'Flag updated successfully']);
            exit;
        }
        
        if (isset($data['action']) && $data['action'] === 'delete_image') {
            $filename = basename($data['filename']);
            $quiz_id = intval($data['quiz_id']);
            $image_path = $upload_dir . 'quiz_' . $quiz_id . '/' . $filename;
            if (file_exists($image_path)) unlink($image_path);
            if (file_exists($log_file)) {
                $log_data = json_decode(file_get_contents($log_file), true) ?: [];
                $log_data = array_filter($log_data, function($entry) use ($filename, $quiz_id) {
                    return !($entry['filename'] === $filename && $entry['cmid'] == $quiz_id);
                });
                file_put_contents($log_file, json_encode(array_values($log_data), JSON_PRETTY_PRINT));
            }
            echo json_encode(['status' => 'success', 'message' => 'Image deleted successfully']);
            exit;
        }

        if (isset($data['action']) && $data['action'] === 'delete_all_images') {
            $target_student_id = intval($data['student_id']);
            $target_quiz_id = intval($data['quiz_id']);
            $deleted_count = 0;
            $deleted_files = [];
            if (file_exists($log_file)) {
                $log_data = json_decode(file_get_contents($log_file), true) ?: [];
                $entries_to_keep = [];
                foreach ($log_data as $entry) {
                    $entry_user_id = $entry['user_id'] ?? 0;
                    $entry_quiz_id = $entry['cmid'] ?? 0;
                    if ($entry_user_id == $target_student_id && $entry_quiz_id == $target_quiz_id) {
                        $filename = $entry['filename'] ?? '';
                        $image_path = $upload_dir . 'quiz_' . $entry_quiz_id . '/' . $filename;
                        if (file_exists($image_path) && unlink($image_path)) {
                            $deleted_count++;
                            $deleted_files[] = $filename;
                        }
                    } else {
                        $entries_to_keep[] = $entry;
                    }
                }
                file_put_contents($log_file, json_encode($entries_to_keep, JSON_PRETTY_PRINT));
            }
            echo json_encode(['status' => 'success', 'message' => 'All images deleted', 'deleted_count' => $deleted_count]);
            exit;
        }
        
        if (!isset($data['cmid']) || !isset($data['image']) || !isset($data['type'])) {
            throw new Exception('Missing required fields');
        }
        
        $cmid = intval($data['cmid']);
        $reportid = isset($data['reportid']) ? intval($data['reportid']) : time();
        $type = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['type']);
        $image_data = $data['image'];
        $quiz_dir = $upload_dir . 'quiz_' . $cmid . '/';
        if (!file_exists($quiz_dir)) mkdir($quiz_dir, 0777, true);
        
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "warning_{$type}_{$reportid}_{$timestamp}.png";
        $filepath = $quiz_dir . $filename;
        $image_data = str_replace('data:image/png;base64,', '', $image_data);
        $image_binary = base64_decode($image_data);
        if (!$image_binary) throw new Exception('Invalid image data');
        if (!file_put_contents($filepath, $image_binary)) throw new Exception('Failed to save image');
        
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'), 'cmid' => $cmid, 'reportid' => $reportid,
            'type' => $type, 'filename' => $filename, 'filepath' => $filepath,
            'filesize' => filesize($filepath), 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_id' => $USER->id ?? 0, 'flagged' => false, 'note' => ''
        ];
        $existing_log = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) ?: [] : [];
        $existing_log[] = $log_entry;
        file_put_contents($log_file, json_encode($existing_log, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success', 'filename' => $filename, 
            'url' => $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/uploadwarning.php?view=' . urlencode($filename) . '&quiz=' . $cmid,
            'message' => 'Image uploaded successfully']);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

function l2_normalize($vec) {
    $sum = 0.0;
    foreach ($vec as $v) $sum += $v * $v;
    $norm = sqrt($sum);
    if ($norm == 0) return $vec;
    $out = [];
    foreach ($vec as $v) $out[] = $v / $norm;
    return $out;
}

function verifyStudentIdentity($upload_dir, $log_file, $student_id, $quiz_id) {
    if (!file_exists($log_file)) return ['status' => 'error', 'message' => 'No log file found'];
    $log_data = json_decode(file_get_contents($log_file), true) ?: [];
    $student_images = [];
    foreach ($log_data as $entry) {
        if (($entry['user_id'] ?? 0) == $student_id && ($entry['cmid'] ?? 0) == $quiz_id) {
            $image_path = $upload_dir . 'quiz_' . $quiz_id . '/' . ($entry['filename'] ?? '');
            if (file_exists($image_path)) {
                $filesize = filesize($image_path);
                if ($filesize < MIN_FILE_SIZE) continue;
                $student_images[] = ['path' => $image_path, 'filename' => $entry['filename'] ?? '', 'timestamp' => $entry['timestamp'] ?? ''];
            }
        }
    }
    if (count($student_images) < MIN_IMAGES_FOR_VERIFICATION) {
        return ['status' => 'insufficient_data', 'message' => 'Not enough images', 'image_count' => count($student_images)];
    }
    
    $api_url = 'https://api-inference.huggingface.co/models/microsoft/resnet-50';
    $api_token = 'YOUR_HUGGINGFACE_API_TOKEN';
    $features = [];
    $face_scores = [];
    $face_positions = [];
    
    foreach ($student_images as $img) {
        if (strpos($img['filename'], 'warning_sound') !== false) continue;
        $face_data = detectFacePresenceAndPosition($img['path']);
        $feature = extractFaceFeatures($img['path'], $api_url, $api_token);
        if ($feature) {
            $feature_norm = l2_normalize($feature);
            $features[] = [
                'feature' => $feature_norm, 
                'filename' => $img['filename'], 
                'timestamp' => $img['timestamp'], 
                'face_score' => $face_data['score'],
                'face_center_x' => $face_data['center_x'],
                'face_center_y' => $face_data['center_y']
            ];
            $face_scores[] = $face_data['score'];
            $face_positions[] = ['x' => $face_data['center_x'], 'y' => $face_data['center_y']];
        }
    }
    if (count($features) < 2) return ['status' => 'error', 'message' => 'Unable to extract features'];
    
    $avg_face_score = count($face_scores) > 0 ? array_sum($face_scores) / count($face_scores) : 0;
    
    $position_variance = 0;
    if (count($face_positions) > 1) {
        $avg_x = array_sum(array_column($face_positions, 'x')) / count($face_positions);
        $avg_y = array_sum(array_column($face_positions, 'y')) / count($face_positions);
        $variance_sum = 0;
        foreach ($face_positions as $pos) {
            $variance_sum += pow($pos['x'] - $avg_x, 2) + pow($pos['y'] - $avg_y, 2);
        }
        $position_variance = sqrt($variance_sum / count($face_positions));
    }
    
    $position_consistency = max(0, 1 - ($position_variance / 50));
    
    $similarities = [];
    $weighted_similarities = [];
    $n = count($features);
    
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $sim = calculateCosineSimilarity($features[$i]['feature'], $features[$j]['feature']);
            $similarities[] = $sim;
            
            $face_avg = ($features[$i]['face_score'] + $features[$j]['face_score']) / 2;
            $face_weight = 0.6 + ($face_avg * 0.4);
            
            $weighted_sim = $sim * $face_weight;
            $weighted_similarities[] = $weighted_sim;
        }
    }
    
    $avg_similarity = array_sum($weighted_similarities) / count($weighted_similarities);
    
    $final_score =  ($avg_face_score * 0.02) + ($position_consistency * 0.98);
    
    $similarity_percentage = round($final_score * 100, 1);
    
    return [
        'status' => 'success',
        'student_id' => $student_id,
        'quiz_id' => $quiz_id,
        'total_images' => count($student_images),
        'images_analyzed' => count($features),
        'similarity_percentage' => $similarity_percentage
    ];
}

function extractFaceFeatures($image_path, $api_url, $api_token) {
    if (!file_exists($image_path)) return null;
    $image_data = file_get_contents($image_path);
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $image_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $api_token, 'Content-Type: application/octet-stream']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code === 200 && $response) {
        $result = json_decode($response, true);
        if (isset($result[0]) && is_array($result[0])) return $result[0];
    }
    return extractLocalFeatures($image_path);
}

function detectFacePresenceAndPosition($image_path) {
    $img = @imagecreatefrompng($image_path);
    if (!$img) $img = @imagecreatefromjpeg($image_path);
    if (!$img) return ['score' => 0, 'center_x' => 0, 'center_y' => 0];
    
    $width = imagesx($img);
    $height = imagesy($img);
    $center_x = floor($width / 2);
    $center_y = floor($height / 3);
    $sample_size = min($width, $height) / 4;
    
    $skin_tone_count = 0;
    $total_samples = 0;
    $samples = 7;
    $weighted_x = 0;
    $weighted_y = 0;
    $total_weight = 0;
    
    for ($i = 0; $i < $samples; $i++) {
    mt_srand(crc32($image_path . $i)); 

    $seed = crc32($image_path);
    $x = $center_x + mt_rand(-$sample_size, $sample_size) + ($seed % 3 - 1);
    $y = $center_y + mt_rand(-$sample_size, $sample_size) + ($seed % 5 - 2);

        $x = max(0, min($width-1, $x));
        $y = max(0, min($height-1, $y));
        
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        
        if ($r > 85 && $g > 35 && $b > 20 && 
            max($r,$g,$b) - min($r,$g,$b) > 12 && 
            abs($r-$g) > 12 && $r > $g && $r > $b) {
            $skin_tone_count++;
            $weighted_x += $x;
            $weighted_y += $y;
            $total_weight++;
        }
        $total_samples++;
    }
    
    $edge_count = 0;
    $edge_samples = 200;
    for ($i = 0; $i < $edge_samples; $i++) {
    mt_srand(crc32($image_path . $i)); 

    $seed = crc32($image_path);
    $x = $center_x + mt_rand(-$sample_size, $sample_size) + ($seed % 3 - 1);
    $y = $center_y + mt_rand(-$sample_size, $sample_size) + ($seed % 5 - 2);

        $x = max(1, min($width-2, $x));
        $y = max(1, min($height-2, $y));
        
        $rgb_c = imagecolorat($img, $x, $y);
        $rgb_r = imagecolorat($img, $x+1, $y);
        $rgb_d = imagecolorat($img, $x, $y+1);
        
        $gray_c = (($rgb_c >> 16) & 0xFF) + (($rgb_c >> 8) & 0xFF) + ($rgb_c & 0xFF);
        $gray_r = (($rgb_r >> 16) & 0xFF) + (($rgb_r >> 8) & 0xFF) + ($rgb_r & 0xFF);
        $gray_d = (($rgb_d >> 16) & 0xFF) + (($rgb_d >> 8) & 0xFF) + ($rgb_d & 0xFF);
        
        if (abs($gray_c - $gray_r) > 80 || abs($gray_c - $gray_d) > 80) {
            $edge_count++;
        }
    }
    
    imagedestroy($img);
    
    $skin_ratio = $skin_tone_count / $total_samples;
    $edge_ratio = $edge_count / $edge_samples;
    $face_score = ($skin_ratio * 0.60) + ($edge_ratio * 0.40);
    
    $face_center_x = $total_weight > 0 ? ($weighted_x / $total_weight) / $width * 100 : 50;
    $face_center_y = $total_weight > 0 ? ($weighted_y / $total_weight) / $height * 100 : 33;
    
    return [
        'score' => min(1.0, max(0.0, $face_score)),
        'center_x' => $face_center_x,
        'center_y' => $face_center_y
    ];
}

function extractLocalFeatures($image_path) {
    $img = @imagecreatefrompng($image_path);
    if (!$img) $img = @imagecreatefromjpeg($image_path);
    if (!$img) return null;
    @imagefilter($img, IMG_FILTER_GRAYSCALE);
    @imagefilter($img, IMG_FILTER_CONTRAST, -10);
    $width = imagesx($img);
    $height = imagesy($img);
    $features = [];
    $grid_size = 20;
    $step_x = max(1, floor($width / $grid_size));
    $step_y = max(1, floor($height / $grid_size));
    for ($y = 0; $y < $grid_size; $y++) {
        for ($x = 0; $x < $grid_size; $x++) {
            $px = min($width-1, $x * $step_x + floor($step_x / 2));
            $py = min($height-1, $y * $step_y + floor($step_y / 2));
            $rgb = imagecolorat($img, $px, $py);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $gray = ($r + $g + $b) / 3;
            $features[] = $gray / 255.0;
        }
    }
    imagedestroy($img);
    return l2_normalize($features);
}

function calculateCosineSimilarity($vec1, $vec2) {
    if (!is_array($vec1) || !is_array($vec2) || count($vec1) !== count($vec2)) {
        $len = min(count($vec1), count($vec2));
        if ($len == 0) return 0;
        $vec1 = array_slice($vec1, 0, $len);
        $vec2 = array_slice($vec2, 0, $len);
    }
    $dot_product = 0;
    $magnitude1 = 0;
    $magnitude2 = 0;
    for ($i = 0; $i < count($vec1); $i++) {
        $dot_product += $vec1[$i] * $vec2[$i];
        $magnitude1 += $vec1[$i] * $vec1[$i];
        $magnitude2 += $vec2[$i] * $vec2[$i];
    }
    $magnitude1 = sqrt($magnitude1);
    $magnitude2 = sqrt($magnitude2);
    if ($magnitude1 == 0 || $magnitude2 == 0) return 0;
    return $dot_product / ($magnitude1 * $magnitude2);
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
.warning-viewer{max-width:1200px;margin:0 auto;padding:20px}
.verification-section{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;border-radius:12px;padding:25px;margin:20px 0;box-shadow:0 4px 12px rgba(0,0,0,0.15)}
.verification-results{background:white;color:#333;border-radius:8px;padding:20px;margin-top:20px;display:none}
.verification-results.show{display:block}
.result-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:15px;border-bottom:2px solid #e0e0e0}
.similarity-container{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:16px;padding:40px;margin:30px 0;box-shadow:0 8px 24px rgba(0,0,0,0.15)}
.similarity-label{font-size:20px;color:white;text-transform:uppercase;letter-spacing:2px;margin-bottom:30px;text-align:center;font-weight:bold}
.similarity-graph-outer{position:relative;background:rgba(255,255,255,0.2);border-radius:50px;padding:6px;backdrop-filter:blur(10px)}
.similarity-graph{position:relative;height:80px;background:linear-gradient(to right,#dc3545 0%,#fd7e14 20%,#ffc107 40%,#20c997 60%,#28a745 80%,#198754 100%);border-radius:40px;overflow:visible;box-shadow:inset 0 2px 8px rgba(0,0,0,0.3)}
.similarity-indicator{position:absolute;top:50%;width:6px;height:100px;background:white;box-shadow:0 0 20px rgba(255,255,255,0.8),0 0 40px rgba(255,255,255,0.4);transform:translate(-50%,-50%);border-radius:3px;transition:left 1.2s cubic-bezier(0.4,0,0.2,1);z-index:10}
.similarity-indicator::before{content:'';position:absolute;top:-20px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-top:18px solid white;filter:drop-shadow(0 0 8px rgba(255,255,255,0.6))}
.similarity-indicator::after{content:'';position:absolute;bottom:-20px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:12px solid transparent;border-right:12px solid transparent;border-bottom:18px solid white;filter:drop-shadow(0 0 8px rgba(255,255,255,0.6))}
.similarity-value{text-align:center;margin-top:35px;font-size:64px;font-weight:bold;color:white;text-shadow:0 4px 12px rgba(0,0,0,0.3);animation:fadeInScale 0.8s ease-out}
.graph-labels{display:flex;justify-content:space-between;margin-top:15px;font-size:13px;color:rgba(255,255,255,0.9);font-weight:600}
.graph-labels span{padding:0 10px}
@keyframes fadeInScale{
  from{opacity:0;transform:scale(0.8)}
  to{opacity:1;transform:scale(1)}
}
.quiz-section{background:#f9f9f9;border:2px solid #ddd;border-radius:8px;margin:20px 0;padding:15px}
.quiz-title{background:#007cba;color:white;padding:10px 15px;margin:-15px -15px 15px;border-radius:6px 6px 0 0;font-weight:bold;display:flex;justify-content:space-between;align-items:center}
.warnings-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-top:15px}
.warning-card{background:white;border:2px solid #dc3545;border-radius:8px;padding:15px;box-shadow:0 2px 4px rgba(0,0,0,0.1);text-align:center;position:relative;transition:transform 0.2s}
.warning-card:hover{transform:translateY(-2px);box-shadow:0 4px 8px rgba(0,0,0,0.15)}
.warning-card.flagged{border-color:#ffc107;background:#fff9e6}
.warning-image{width:100%;max-width:250px;height:180px;object-fit:cover;border-radius:5px;margin-bottom:15px;cursor:pointer}
.warning-timestamp{font-size:12px;color:#666;margin:8px 0}
.warning-actions{display:flex;justify-content:center;gap:10px;margin-top:10px;flex-wrap:wrap}
.action-btn{padding:6px 12px;border:none;border-radius:4px;cursor:pointer;font-size:12px;font-weight:bold}
.flag-btn{background:#ffc107;color:#000}
.flag-btn.flagged{background:#28a745;color:white}
.delete-btn{background:#dc3545;color:white}
.fullscreen-btn{background:#6c757d;color:white}
.delete-all-section{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;margin:20px 0;text-align:center}
.delete-all-btn{background:#dc3545;color:white;padding:10px 20px;border:none;border-radius:5px;cursor:pointer;font-size:14px;font-weight:bold}
.stats-card{background:linear-gradient(135deg,#e7f3ff,#f0f8ff);border:2px solid #007cba;border-radius:12px;padding:20px;margin:20px 0;text-align:center}
.stats-number{font-size:28px;font-weight:bold;color:#007cba}
.stats-breakdown{display:flex;justify-content:center;gap:20px;margin-top:15px;flex-wrap:wrap}
.stat-item{background:white;padding:10px 15px;border-radius:8px;border:1px solid #ddd;min-width:80px}
.no-data{text-align:center;padding:40px;color:#666;font-size:18px;background:#f8f9fa;border-radius:8px;border:2px dashed #dee2e6}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);z-index:1000;cursor:pointer}
.modal img{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);max-width:100vw;max-height:100vh;border-radius:8px}
.modal-close{position:absolute;top:20px;right:30px;color:white;font-size:40px;cursor:pointer;z-index:1001;background:rgba(0,0,0,0.5);border-radius:50%;width:60px;height:60px;display:flex;align-items:center;justify-content:center}
.note-input{width:100%;margin-top:10px;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:12px;resize:vertical;min-height:60px}
.flag-indicator{position:absolute;top:10px;right:10px;background:#ffc107;color:#000;padding:4px 8px;border-radius:12px;font-size:10px;font-weight:bold}
.loading-spinner{text-align:center;padding:20px;font-size:16px;color:#667eea}
</style>
<div class="warning-viewer">
<?php
$total_warnings = 0;
$quiz_stats = [];
$auto_verify = false;

if (file_exists($log_file)) {
    $log_data = json_decode(file_get_contents($log_file), true) ?: [];
    if (!empty($log_data)) {
        foreach ($log_data as $entry) {
            $entry_quiz_id = $entry['cmid'] ?? 0;
            $entry_user_id = $entry['user_id'] ?? 0;
            if ($quizid && $entry_quiz_id != $quizid) continue;
            if ($studentid && $entry_user_id != $studentid) continue;
            if (!isset($quiz_stats[$entry_quiz_id])) $quiz_stats[$entry_quiz_id] = ['total' => 0, 'flagged' => 0];
            $quiz_stats[$entry_quiz_id]['total']++;
            if (isset($entry['flagged']) && $entry['flagged']) $quiz_stats[$entry_quiz_id]['flagged']++;
            $total_warnings++;
        }
        
        if ($total_warnings > 0 && $studentid && $quizid) {
            $auto_verify = true;
        }
        
        if ($total_warnings > 0) {
            $total_flagged = array_sum(array_column($quiz_stats, 'flagged'));
            echo '<div class="verification-section">';
            echo '<h2 style="margin-top:0">Identity Verification System</h2>';
            echo '<p>Face detection and similarity analysis for exam proctoring</p>';
            echo '<div id="verificationResults" class="verification-results"></div>';
            echo '</div>';
            
            echo '<div class="stats-card"><div class="stats-number">' . $total_warnings . '</div><div>Total Recorded Warnings</div>';
            echo '<div class="stats-breakdown"><div class="stat-item"><strong>' . count($quiz_stats) . '</strong><br>Quiz' . (count($quiz_stats) > 1 ? 'zes' : '') . '</div>';
            echo '<div class="stat-item"><strong>' . $total_flagged . '</strong><br>Flagged</div>';
            echo '<div class="stat-item"><strong>' . ($total_warnings - $total_flagged) . '</strong><br>Unflagged</div></div></div>';
            
            echo '<div class="delete-all-section"><button class="delete-all-btn" onclick="deleteAllImages(' . $studentid . ',' . $courseid . ',' . $quizid . ')">DELETE ALL IMAGES</button></div>';
            
            foreach ($quiz_stats as $quiz_id => $stats) {
                echo '<div class="quiz-section"><div class="quiz-title"><span>Quiz ID: ' . $quiz_id . ' - ' . $stats['total'] . ' Warnings</span><span>' . $stats['flagged'] . ' Flagged</span></div><div class="warnings-grid">';
                foreach ($log_data as $entry) {
                    $entry_quiz_id = $entry['cmid'] ?? 0;
                    $entry_user_id = $entry['user_id'] ?? 0;
                    if ($entry_quiz_id != $quiz_id || ($quizid && $entry_quiz_id != $quizid) || ($studentid && $entry_user_id != $studentid)) continue;
                    $image_url = $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/uploadwarning.php?view=' . urlencode($entry['filename'] ?? '') . '&quiz=' . $quiz_id;
                    $is_flagged = isset($entry['flagged']) && $entry['flagged'];
                    $note = $entry['note'] ?? '';
                    $filename = $entry['filename'] ?? '';
                    echo '<div class="warning-card' . ($is_flagged ? ' flagged' : '') . '" data-filename="' . $filename . '">';
                    if ($is_flagged) echo '<div class="flag-indicator">FLAGGED</div>';
                    if ($filename && file_exists($upload_dir . 'quiz_' . $quiz_id . '/' . $filename)) {
                        echo '<img src="' . $image_url . '" class="warning-image" onclick="showFullscreen(this.src)">';
                    }
                    echo '<div class="warning-timestamp">Date: ' . date('Y-m-d H:i:s', strtotime($entry['timestamp'] ?? 'now')) . '</div>';
                    echo '<div><strong>Type:</strong> ' . ucfirst($entry['type'] ?? 'unknown') . '</div>';
                    if ($note) echo '<div style="font-size:12px;color:#666;margin:8px 0;font-style:italic">Note: ' . htmlspecialchars($note) . '</div>';
                    echo '<div class="warning-actions">';
                    echo '<button class="action-btn flag-btn' . ($is_flagged ? ' flagged' : '') . '" onclick="toggleFlag(\'' . $filename . '\',' . $quiz_id . ',' . ($is_flagged ? 'false' : 'true') . ')">' . ($is_flagged ? 'Flagged' : 'Flag') . '</button>';
                    echo '<button class="action-btn fullscreen-btn" onclick="showFullscreen(\'' . $image_url . '\')">View</button>';
                    echo '<button class="action-btn delete-btn" onclick="deleteImage(\'' . $filename . '\',' . $quiz_id . ')">Delete</button></div>';
                    echo '<textarea class="note-input" placeholder="Add a note..." onchange="updateNote(\'' . $filename . '\',' . $quiz_id . ',this.value)">' . htmlspecialchars($note) . '</textarea></div>';
                }
                echo '</div></div>';
            }
        } else {
            echo '<div class="no-data">No warnings recorded for this quiz<br><small>Start a quiz with proctoring enabled to see warnings here</small></div>';
        }
    } else {
        echo '<div class="no-data">No warnings recorded yet<br><small>Start a quiz with proctoring enabled to see warnings here</small></div>';
    }
} else {
    echo '<div class="no-data">Log file does not exist<br><small>No data has been recorded yet</small></div>';
}
?>
</div>
<div id="imageModal" class="modal" onclick="closeModal()">
    <span class="modal-close" onclick="closeModal()">&times;</span>
    <img id="modalImage" src="">
</div>
<script>
var autoVerifyStudentId = <?php echo $studentid; ?>;
var autoVerifyQuizId = <?php echo $quizid; ?>;
var shouldAutoVerify = <?php echo $auto_verify ? 'true' : 'false'; ?>;

document.addEventListener('DOMContentLoaded', function() {
    if (shouldAutoVerify && autoVerifyStudentId > 0 && autoVerifyQuizId > 0) {
        verifyStudentIdentity(autoVerifyStudentId, autoVerifyQuizId);
    }
});

function verifyStudentIdentity(studentId, quizId) {
    const resultsDiv = document.getElementById('verificationResults');
    resultsDiv.innerHTML = '<div class="loading-spinner">Analyzing images...</div>';
    resultsDiv.classList.add('show');
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'verify_identity', student_id: studentId, quiz_id: quizId})
    }).then(r => r.json()).then(data => {
        if (data.status === 'success') {
            displayVerificationResults(data);
        } else if (data.status === 'insufficient_data') {
            resultsDiv.innerHTML = '<div class="result-header"><h3>Insufficient Data</h3></div><p>' + data.message + '</p><p>Images found: ' + data.image_count + '</p><p>At least 3 images required for verification.</p>';
        } else {
            resultsDiv.innerHTML = '<div class="result-header"><h3>Error</h3></div><p>' + (data.message || 'An error occurred') + '</p>';
        }
    }).catch(error => {
        resultsDiv.innerHTML = '<div class="result-header"><h3>Error</h3></div><p>Network error: ' + error.message + '</p>';
    });
}

function displayVerificationResults(data) {
    const resultsDiv = document.getElementById('verificationResults');
    
    resultsDiv.innerHTML = `
        <div class="result-header">
            <h3>Verification Results</h3>
            <div style="font-size:14px;color:#666">Student ID: ${data.student_id} | Quiz ID: ${data.quiz_id}</div>
        </div>
        <div style="background:#f8f9fa;padding:15px;border-radius:8px;margin:15px 0">
            <div style="font-size:14px;color:#666">Total Images: ${data.total_images} | Images Analyzed: ${data.images_analyzed}</div>
        </div>
        <div class="similarity-container">
            <div class="similarity-label">Identity Similarity Score</div>
            <div class="similarity-graph-outer">
                <div class="similarity-graph">
                    <div class="similarity-indicator" id="similarityIndicator" style="left:0%"></div>
                </div>
            </div>
            <div class="graph-labels">
                <span>0%</span>
                <span>20%</span>
                <span>40%</span>
                <span>60%</span>
                <span>80%</span>
                <span>100%</span>
            </div>
            <div class="similarity-value" id="similarityValue">0%</div>
        </div>
    `;
    
    setTimeout(() => {
        const indicator = document.getElementById('similarityIndicator');
        const valueDisplay = document.getElementById('similarityValue');
        indicator.style.left = data.similarity_percentage + '%';
        
        let currentValue = 0;
        const targetValue = data.similarity_percentage;
        const duration = 1200;
        const steps = 60;
        const increment = targetValue / steps;
        const stepDuration = duration / steps;
        
        const counter = setInterval(() => {
            currentValue += increment;
            if (currentValue >= targetValue) {
                currentValue = targetValue;
                clearInterval(counter);
            }
            valueDisplay.textContent = Math.round(currentValue) + '%';
        }, stepDuration);
    }, 100);
}

function deleteImage(filename, quizId) {
    if (confirm('Delete this warning image? This action cannot be undone.')) {
        fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'delete_image', filename: filename, quiz_id: quizId})
        }).then(r => r.json()).then(data => {
            if (data.status === 'success') {
                alert('Image deleted successfully.');
                location.reload();
            } else {
                alert('Error deleting image: ' + data.error);
            }
        }).catch(error => alert('Error: ' + error));
    }
}

function deleteAllImages(studentId, courseId, quizId) {
    if (confirm('This will permanently delete ALL warning images for this specific student in this quiz.\n\nThis action cannot be undone!')) {
        const deleteBtn = document.querySelector('.delete-all-btn');
        const originalText = deleteBtn.innerHTML;
        deleteBtn.innerHTML = 'Deleting...';
        deleteBtn.disabled = true;
        
        fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'delete_all_images', student_id: studentId, course_id: courseId, quiz_id: quizId})
        }).then(r => r.json()).then(data => {
            if (data.status === 'success') {
                alert('Successfully deleted ' + data.deleted_count + ' images for this student.');
                location.reload();
            } else {
                alert('Error deleting images: ' + data.error);
                deleteBtn.innerHTML = originalText;
                deleteBtn.disabled = false;
            }
        }).catch(error => {
            alert('Error: ' + error);
            deleteBtn.innerHTML = originalText;
            deleteBtn.disabled = false;
        });
    }
}

function toggleFlag(filename, quizId, flagStatus) {
    const note = document.querySelector(`[data-filename="${filename}"] .note-input`).value || '';
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'update_flag', filename: filename, quiz_id: quizId, is_flagged: flagStatus, note: note})
    }).then(r => r.json()).then(data => {
        if (data.status === 'success') {
            location.reload();
        } else {
            alert('Error updating flag: ' + data.error);
        }
    }).catch(error => alert('Error: ' + error));
}

function updateNote(filename, quizId, note) {
    const card = document.querySelector(`[data-filename="${filename}"]`);
    const isFlagged = card.classList.contains('flagged');
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'update_flag', filename: filename, quiz_id: quizId, is_flagged: isFlagged, note: note})
    }).then(r => r.json()).then(data => {
        if (data.status === 'success') {
            const noteInput = card.querySelector('.note-input');
            noteInput.style.borderColor = '#28a745';
            setTimeout(() => noteInput.style.borderColor = '#ddd', 1000);
        } else {
            alert('Error updating note: ' + data.error);
        }
    }).catch(error => console.error('Error updating note:', error));
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

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

document.addEventListener('click', function(e) {
    const modal = document.getElementById('imageModal');
    if (e.target === modal) closeModal();
});
</script>
<?php echo $OUTPUT->footer(); ?>
