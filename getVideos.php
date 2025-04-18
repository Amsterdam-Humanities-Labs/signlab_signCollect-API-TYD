<?php
// Set appropriate headers for API
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Include the MySQL configuration file
include '../../mysql_config.php';

// Start timer
$startTime = microtime(true);

// Configuration variables
$mediaBaseUrl = 'https://media.signcollect.nl/';
$subtitleBaseUrl = 'https://media.signcollect.nl/zin/eaf/zin/';

// Disable PHP warnings but keep errors
error_reporting(E_ERROR | E_PARSE);

// Initialize response array
$response = [
    'success' => false,
    'data' => [],
    'errors' => [],
    'debug' => [] // Add debug information section
];

// Create a function to log API activity
function logApiRequest($conn, $action, $id = '', $status = 'success', $errorMessage = '', $responseTime = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $requestData = json_encode($_REQUEST);
    
    try {
        $stmt = $conn->prepare("INSERT INTO zin_api_log (action, query, ip_address, user_agent, request_data, status, error_message, response_time) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", $action, $id, $ip, $userAgent, $requestData, $status, $errorMessage, $responseTime);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // If logging fails, write to error log as fallback
        error_log("Failed to log to zin_api_log: " . $e->getMessage());
    }
}

try {
    // Create a new MySQLi connection
    $conn = new mysqli($servername, $username, $password, $database);
    $conn->set_charset("utf8");

    // Check for a connection error
    if ($conn->connect_error) {
        $response['debug']['connection_error'] = $conn->connect_error;
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }

    // Get ID from query parameters
    $id = isset($_GET['id']) ? $_GET['id'] : null;
    $type = isset($_GET['type']) ? $_GET['type'] : null;

    if (empty($id)) {
        throw new Exception('ID parameter is required');
    }

    // Log the request
    logApiRequest($conn, 'getVideos', $id);

    // Based on the type (zin, glos, nmm, sb), fetch different data
    switch ($type) {
        case 'zin':
            // Fetch sentence data first to get complete information
            $stmt = $conn->prepare("SELECT * FROM sentences WHERE ID = ?");
            if (!$stmt) {
                $response['debug']['sentence_prepare_error'] = $conn->error;
                throw new Exception('Prepare statement failed: ' . $conn->error);
            }
            
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                $response['debug']['sentence_execute_error'] = $stmt->error;
                throw new Exception('Execute statement failed: ' . $stmt->error);
            }
            
            $sentenceResult = $stmt->get_result();
            $stmt->close();
            
            if ($sentenceResult->num_rows === 0) {
                throw new Exception('Sentence not found with ID: ' . $id);
            }
            
            $sentence = $sentenceResult->fetch_assoc();
            
            // Now fetch videos
            $sentence['videos'] = getVideosForEntity($conn, $id, 'zin');
            
            // Generate subtitle files
            $subtitleFiles = [];
            if (!empty($sentence['videos']['videoCenter'])) {
                $videoUrl = $sentence['videos']['videoCenter'];
                // Extract filename from URL (e.g., M20241216_0838.mp4)
                if (preg_match('/\/([^\/]+\.mp4)$/', $videoUrl, $matches)) {
                    $baseFilename = str_replace('.mp4', '', $matches[1]);
                    
                    // Create the SRT filenames
                    $subtitleFiles = [
                        'Nederlands' => $baseFilename . '_Nederlands.srt',
                        'Gebaar_voor_Gebaar' => $baseFilename . '_Gebaar-voor-gebaar.srt',
                        'Signbank_ID_glossen' => $baseFilename . '_Signbank_ID_glossen.srt'
                    ];
                }
            }
            
            // Add full URLs for subtitle files
            $subtitleFullUrls = [];
            foreach ($subtitleFiles as $type => $filename) {
                $subtitleFullUrls[$type] = $filename ? $subtitleBaseUrl . $filename : null;
            }
            
            // Prepare the final response
            $response['data'] = [
                "ID" => $sentence['ID'] ?? null,
                "zinString" => $sentence['zinString'] ?? "",
                "Nederlands" => $sentence['Nederlands'] ?? "",
                "Gebaar_voor_Gebaar" => $sentence['Gebaar_voor_Gebaar'] ?? "",
                "Signbank_ID_glossen" => $sentence['Signbank_ID_glossen'] ?? "",
                "videos" => $sentence['videos'],
                "subtitleFiles" => $subtitleFullUrls
            ];
            break;
            
        case 'glos':
            // Fetch form data
            $stmt = $conn->prepare("SELECT * FROM form_data WHERE id = ?");
            if (!$stmt) {
                $response['debug']['form_prepare_error'] = $conn->error;
                throw new Exception('Prepare statement failed: ' . $conn->error);
            }
            
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                $response['debug']['form_execute_error'] = $stmt->error;
                throw new Exception('Execute statement failed: ' . $stmt->error);
            }
            
            $formResult = $stmt->get_result();
            $stmt->close();
            
            if ($formResult->num_rows === 0) {
                throw new Exception('Form not found with ID: ' . $id);
            }
            
            $form = $formResult->fetch_assoc();
            
            // Fetch videos
            $form['videos'] = getVideosForEntity($conn, $id, 'glos');
            
            // Check if it has signbank_id, then fetch NMM data as well
            $nmm_data = [];
            if (!empty($form['signbank'])) {
                $nmm_data = getNmmDataForSignbankId($conn, $form['signbank']);
            }
            
            // Prepare the final response
            $response['data'] = [
                "id" => $form['id'] ?? null,
                "senses" => $form['senses'] ?? "",
                "signbank" => $form['signbank'] ?? "",
                "videos" => $form['videos'],
                "nmm_data" => $nmm_data
            ];
            break;
            
        case 'sb':
            // Fetch sb_records data
            $stmt = $conn->prepare("SELECT * FROM sb_records WHERE id = ?");
            if (!$stmt) {
                $response['debug']['sb_records_prepare_error'] = $conn->error;
                throw new Exception('Prepare statement failed: ' . $conn->error);
            }
            
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                $response['debug']['sb_records_execute_error'] = $stmt->error;
                throw new Exception('Execute statement failed: ' . $stmt->error);
            }
            
            $sbResult = $stmt->get_result();
            $stmt->close();
            
            if ($sbResult->num_rows === 0) {
                throw new Exception('SignBank record not found with ID: ' . $id);
            }
            
            $sbRecord = $sbResult->fetch_assoc();
            
            // Parse senses_dutch from JSON
            $sensesDutch = !empty($sbRecord['senses_dutch']) ? json_decode($sbRecord['senses_dutch'], true) : [];
            
            // Get video for this record (different format than other tables)
            $videos = [
                'videoLeft' => null,
                'videoCenter' => null,
                'videoRight' => null
            ];
            
            if (!empty($sbRecord['video'])) {
                // For sb_records, video is stored directly in the video field
                $videoPath = $sbRecord['video'];
                // Check if it has the full URL already
                if (strpos($videoPath, 'http') !== 0) {
                    $videos['videoCenter'] = $mediaBaseUrl . $videoPath;
                } else {
                    $videos['videoCenter'] = $videoPath;
                }
            }
            
            // Prepare the final response
            $response['data'] = [
                "id" => $sbRecord['id'] ?? null,
                "senses_dutch" => $sensesDutch,
                "videos" => $videos
            ];
            break;
            
        case 'nmm':
            // Fetch NMM data directly
            $stmt = $conn->prepare("SELECT * FROM nmm_data WHERE id = ?");
            if (!$stmt) {
                $response['debug']['nmm_prepare_error'] = $conn->error;
                throw new Exception('Prepare statement failed: ' . $conn->error);
            }
            
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                $response['debug']['nmm_execute_error'] = $stmt->error;
                throw new Exception('Execute statement failed: ' . $stmt->error);
            }
            
            $nmmResult = $stmt->get_result();
            $stmt->close();
            
            if ($nmmResult->num_rows === 0) {
                throw new Exception('NMM data not found with ID: ' . $id);
            }
            
            $nmm = $nmmResult->fetch_assoc();
            
            // Get videos for this NMM
            $videos = [
                'videoLeft' => null,
                'videoCenter' => null,
                'videoRight' => null
            ];
            
            // Get videos from matched_transcriptions with zOg LIKE '%nmm%'
            $videoSql = "SELECT * FROM matched_transcriptions 
                         WHERE m_transcription = ? AND zOg LIKE '%nmm%'";
            
            $videoStmt = $conn->prepare($videoSql);
            if (!$videoStmt) {
                $response['debug']['nmm_video_prepare_error'] = $conn->error;
                throw new Exception('Prepare statement failed: ' . $conn->error);
            }
            
            $videoStmt->bind_param("s", $id);
            
            if (!$videoStmt->execute()) {
                $response['debug']['nmm_video_execute_error'] = $videoStmt->error;
                throw new Exception('Execute statement failed: ' . $videoStmt->error);
            }
            
            $videoResult = $videoStmt->get_result();
            $videoStmt->close();
            
            if ($videoResult->num_rows > 0) {
                while ($videoRow = $videoResult->fetch_assoc()) {
                    // Convert .wav extensions to .mp4 for video paths and prepend base URL
                    $leftFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['l_file']);
                    $centerFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['m_file']);
                    $rightFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['r_file']);
                    
                    $videos['videoLeft'] = $leftFile ? $mediaBaseUrl . $leftFile : null;
                    $videos['videoCenter'] = $centerFile ? $mediaBaseUrl . $centerFile : null;
                    $videos['videoRight'] = $rightFile ? $mediaBaseUrl . $rightFile : null;
                }
            }
            
            $nmm['videos'] = $videos;
            
            // Prepare the final response
            $response['data'] = $nmm;
            break;
            
        default:
            throw new Exception('Invalid type parameter. Must be one of: zin, glos, nmm, sb');
    }
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['errors'][] = $e->getMessage();
    
    // Log the error
    logApiRequest($conn, 'getVideos_error', $id ?? '', 'error', $e->getMessage());
}

// Function to get videos for a given entity
function getVideosForEntity($conn, $entityId, $zOgValue) {
    global $response, $mediaBaseUrl;
    
    $videos = [
        'videoLeft' => null,
        'videoCenter' => null,
        'videoRight' => null
    ];
    
    if ($zOgValue === 'glos') {
        $sql = "SELECT l_file, m_file, r_file FROM matched_transcriptions WHERE m_transcription = ? AND (zOg LIKE 'glos' OR zOg LIKE 'extern')";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['debug']['video_prepare_error'] = $conn->error;
            return $videos;
        }
        $stmt->bind_param("i", $entityId);
    } else {
        $sql = "SELECT l_file, m_file, r_file FROM matched_transcriptions WHERE m_transcription = ? AND zOg = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $response['debug']['video_prepare_error'] = $conn->error;
            return $videos;
        }
        $stmt->bind_param("is", $entityId, $zOgValue);
    }
    
    if (!$stmt->execute()) {
        $response['debug']['video_execute_error'] = $stmt->error;
        $stmt->close();
        return $videos;
    }
    
    $result = $stmt->get_result();
    $stmt->close();
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Convert .wav extensions to .mp4 for video paths and prepend base URL
            $leftFile = preg_replace('/\.wav$/i', '.mp4', $row['l_file']);
            $centerFile = preg_replace('/\.wav$/i', '.mp4', $row['m_file']);
            $rightFile = preg_replace('/\.wav$/i', '.mp4', $row['r_file']);
            
            $videos['videoLeft'] = $leftFile ? $mediaBaseUrl . $leftFile : null;
            $videos['videoCenter'] = $centerFile ? $mediaBaseUrl . $centerFile : null;
            $videos['videoRight'] = $rightFile ? $mediaBaseUrl . $rightFile : null;
        }
    }
    
    return $videos;
}

// Function to get NMM data by signbank_id
function getNmmDataForSignbankId($conn, $signbankId) {
    global $response, $mediaBaseUrl;
    
    $nmmData = [];
    
    // Query nmm_data table for records with matching signbank_id
    $sql = "SELECT * FROM nmm_data WHERE signbank_id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $response['debug']['nmm_prepare_error'] = $conn->error;
        return $nmmData;
    }
    
    $stmt->bind_param("s", $signbankId);
    
    if (!$stmt->execute()) {
        $response['debug']['nmm_execute_error'] = $stmt->error;
        $stmt->close();
        return $nmmData;
    }
    
    $result = $stmt->get_result();
    $stmt->close();
    
    // Process each NMM record
    if ($result->num_rows > 0) {
        while ($nmmRow = $result->fetch_assoc()) {
            $nmmId = $nmmRow['id'];
            $nmmRecord = $nmmRow;
            
            // Get videos from matched_transcriptions with zOg LIKE '%nmm%'
            $videoSql = "SELECT * FROM matched_transcriptions 
                         WHERE m_transcription = ? AND zOg LIKE '%nmm%'";
            
            $videoStmt = $conn->prepare($videoSql);
            if (!$videoStmt) {
                continue;
            }
            
            $videoStmt->bind_param("s", $nmmId);
            
            if (!$videoStmt->execute()) {
                $videoStmt->close();
                continue;
            }
            
            $videoResult = $videoStmt->get_result();
            $videoStmt->close();
            
            // Process videos for this NMM record
            $videos = [
                'videoLeft' => null,
                'videoCenter' => null,
                'videoRight' => null
            ];
            
            if ($videoResult->num_rows > 0) {
                while ($videoRow = $videoResult->fetch_assoc()) {
                    // Convert .wav extensions to .mp4 for video paths and prepend base URL
                    $leftFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['l_file']);
                    $centerFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['m_file']);
                    $rightFile = preg_replace('/\.wav$/i', '.mp4', $videoRow['r_file']);
                    
                    $videos['videoLeft'] = $leftFile ? $mediaBaseUrl . $leftFile : null;
                    $videos['videoCenter'] = $centerFile ? $mediaBaseUrl . $centerFile : null;
                    $videos['videoRight'] = $rightFile ? $mediaBaseUrl . $rightFile : null;
                }
            }
            
            // Add videos to the NMM record
            $nmmRecord['videos'] = $videos;
            $nmmData[] = $nmmRecord;
        }
    }
    
    return $nmmData;
}

// Calculate response time
$responseTime = microtime(true) - $startTime;
$response['response_time'] = $responseTime;

// Log response time BEFORE closing the connection
if (isset($conn) && $conn) {
    logApiRequest($conn, 'getVideos', $id ?? '', 'success', '', $responseTime);
    $conn->close();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
