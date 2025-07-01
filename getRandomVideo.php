<?php
// Load configuration and service files
require_once 'src/config/config.php';
require_once 'src/config/SecurityHeaders.php';
require_once 'src/config/ErrorReporting.php';

// Set security headers
SecurityHeaders::setHeaders();

// Configure error reporting
ErrorReporting::configure();

// Cache settings
$cacheFile = __DIR__ . '/cache/random_video.json';
$cacheTime = 24 * 60 * 60; // 24 hours in seconds

// Check if cache directory exists, if not create it
if (!file_exists(__DIR__ . '/cache')) {
    mkdir(__DIR__ . '/cache', 0755, true);
}

// Try to load from cache
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    // Serve from cache
    header('Content-Type: application/json; charset=utf-8');
    header('X-Cache-Hit: 1');
    if (!headers_sent()) {
        http_response_code(200);
    }
    // Read cache and output just the data part
    $cacheContent = json_decode(file_get_contents($cacheFile), true);
    echo json_encode($cacheContent['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Include the MySQL configuration file
include '../../mysql_config_test.php';

try {
    // Create a new MySQLi connection
    $conn = new mysqli($servername, $username, $password, $database);
    $conn->set_charset("utf8");

    // Check for a connection error
    if ($conn->connect_error) {
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }

    // Simple query to get a random video
    $themeColumn = IS_PROD ? 'thema' : 'theme';
    $sql = "SELECT DISTINCT f.id, f.senses, f.signbank, 
            IFNULL(f.$themeColumn, 'Unknown') as thema, f.glos
            FROM form_data f
            INNER JOIN matched_transcriptions m ON f.id = m.m_transcription
            WHERE f.extern = '1' 
            AND f.glosZichtbaar = '0'
            AND m.zOg IN ('glos', 'extern', 'labels')
            AND (m.l_file != '' OR m.m_file != '' OR m.r_file != '')
            ORDER BY RAND()
            LIMIT 1";
    
    $result = $conn->query($sql);
    
    if (!$result || $result->num_rows === 0) {
        throw new Exception('No videos found');
    }
    
    $videoData = $result->fetch_assoc();
    
    // Process senses
    $sensesValue = $videoData['senses'];
    if (empty($sensesValue) || $sensesValue === null) {
        $videoData['senses'] = [];
    } else {
        $decoded = json_decode($sensesValue, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $videoData['senses'] = [$sensesValue];
        } else {
            $videoData['senses'] = $decoded;
        }
    }
    
    // Get video URLs
    $videosSql = "SELECT m.l_file, m.m_file, m.r_file
                  FROM matched_transcriptions m
                  WHERE m.m_transcription = ?
                  AND m.zOg IN ('extern', 'labels')
                  AND (m.l_file != '' OR m.m_file != '' OR m.r_file != '')
                  ORDER BY FIELD(m.zOg, 'extern', 'labels')
                  LIMIT 1";
    
    $stmt = $conn->prepare($videosSql);
    $stmt->bind_param("i", $videoData['id']);
    $stmt->execute();
    $videosResult = $stmt->get_result();
    
    $videos = [
        'videoLeft' => null,
        'videoCenter' => null,
        'videoRight' => null
    ];
    
    if ($videosResult->num_rows > 0) {
        $videoRow = $videosResult->fetch_assoc();
        $videos['videoLeft'] = $videoRow['l_file'] ? MEDIA_BASE_URL . str_replace('.wav', '.mp4', $videoRow['l_file']) : null;
        $videos['videoCenter'] = $videoRow['m_file'] ? MEDIA_BASE_URL . str_replace('.wav', '.mp4', $videoRow['m_file']) : null;
        $videos['videoRight'] = $videoRow['r_file'] ? MEDIA_BASE_URL . str_replace('.wav', '.mp4', $videoRow['r_file']) : null;
    }
    
    $videoData['videos'] = $videos;
    
    // Get NMM data if signbank exists
    $videoData['nmm_data'] = [];
    if (!empty($videoData['signbank'])) {
        $nmmSql = "SELECT * FROM nmm_data WHERE signbank = ? LIMIT 10";
        $nmmStmt = $conn->prepare($nmmSql);
        $nmmStmt->bind_param("s", $videoData['signbank']);
        $nmmStmt->execute();
        $nmmResult = $nmmStmt->get_result();
        
        while ($nmmRow = $nmmResult->fetch_assoc()) {
            $videoData['nmm_data'][] = $nmmRow;
        }
    }
    
    // Cache the result
    $cacheData = ['data' => $videoData];
    file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Output just the video data
    header('Content-Type: application/json; charset=utf-8');
    header('X-Cache-Hit: 0');
    echo json_encode($videoData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Simple error output
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Close connection
if (isset($conn)) {
    $conn->close();
}
exit;