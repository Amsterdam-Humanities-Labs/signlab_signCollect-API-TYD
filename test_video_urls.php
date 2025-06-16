<?php
require_once __DIR__ . '/src/config/config.php';
require_once __DIR__ . '/src/services/VideoService.php';
require_once __DIR__ . '/src/services/ApiLogger.php';

// Initialize database connection
require_once __DIR__ . '/mysql_config.php';

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Initialize services
$response = []; // VideoService needs a response array
$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);

// Configuration
$baseMediaUrl = 'https://media.signcollect.nl/';
$cameraAngles = ['links', 'midden', 'rechts'];
$failedUrls = [];
$testedUrls = [];
$stats = [
    'total_tested' => 0,
    'total_failed' => 0,
    'sentences' => ['tested' => 0, 'failed' => 0],
    'nmm' => ['tested' => 0, 'failed' => 0],
    'zin' => ['tested' => 0, 'failed' => 0]  // Add zin stats
];

// Function to test URL
function testUrl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

// Function to update app_ready status in matched_transcriptions
function updateAppReady($conn, $type, $id, $isReady) {
    $appReady = $isReady ? 1 : 0;
    $zOg = $type; // type should be 'zin', 'glos', 'extern', or 'nmm'
    
    // For form_data, the zOg can be 'glos', 'extern', or 'labels'
    if ($type === 'glos') {
        // Update for 'glos', 'extern', and 'labels' types
        $sql = "UPDATE matched_transcriptions SET app_ready = ? WHERE m_transcription = ? AND zOg IN ('glos', 'extern', 'labels')";
    } else {
        $sql = "UPDATE matched_transcriptions SET app_ready = ? WHERE m_transcription = ? AND zOg = ?";
    }
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "  Error preparing update: " . $conn->error . "\n";
        return false;
    }
    
    if ($type === 'glos') {
        $stmt->bind_param("ii", $appReady, $id);
    } else {
        $stmt->bind_param("iis", $appReady, $id, $zOg);
    }
    
    if (!$stmt->execute()) {
        echo "  Error executing update: " . $stmt->error . "\n";
        $stmt->close();
        return false;
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    return $affectedRows;
}

// Function to test video URLs for an entity
function testEntityVideos($type, $id, $name, &$failedUrls, &$testedUrls, &$stats, $videoService, $conn) {
    global $cameraAngles, $baseMediaUrl;
    
    echo "Testing $type ID: $id ($name)\n";
    
    // For testing purposes, we need to bypass VideoService and query directly
    // because VideoService filters out app_ready=0 records
    $videos = [];
    
    if ($type === 'glos') {
        // Query directly for form_data videos including labels
        $sql = "SELECT l_file, m_file, r_file FROM matched_transcriptions 
                WHERE m_transcription = ? AND zOg IN ('glos', 'extern', 'labels')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
    } else {
        // Query for other types
        $sql = "SELECT l_file, m_file, r_file FROM matched_transcriptions 
                WHERE m_transcription = ? AND zOg = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $id, $type);
    }
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['l_file'])) {
                // Convert .wav to .mp4 for video URLs
                $filename = str_replace('.wav', '.mp4', $row['l_file']);
                $videos['videoLeft'] = $baseMediaUrl . $filename;
            }
            if (!empty($row['m_file'])) {
                // Convert .wav to .mp4 for video URLs
                $filename = str_replace('.wav', '.mp4', $row['m_file']);
                $videos['videoCenter'] = $baseMediaUrl . $filename;
            }
            if (!empty($row['r_file'])) {
                // Convert .wav to .mp4 for video URLs
                $filename = str_replace('.wav', '.mp4', $row['r_file']);
                $videos['videoRight'] = $baseMediaUrl . $filename;
            }
        }
        $stmt->close();
    }
    
    // Check if we have any videos
    $hasVideos = false;
    if (isset($videos['videoLeft']) && $videos['videoLeft']) $hasVideos = true;
    if (isset($videos['videoCenter']) && $videos['videoCenter']) $hasVideos = true;
    if (isset($videos['videoRight']) && $videos['videoRight']) $hasVideos = true;
    
    if (!$hasVideos) {
        echo "  No videos found for $type ID: $id\n";
        // Update app_ready to 0 since there are no videos
        $updated = updateAppReady($conn, $type, $id, false);
        echo "  Updated app_ready to 0 (affected rows: $updated)\n";
        return;
    }
    
    // Map angle names to video keys
    $angleMap = [
        'links' => 'videoLeft',
        'midden' => 'videoCenter',
        'rechts' => 'videoRight'
    ];
    
    $allVideosOk = true;
    $videosTested = 0;
    
    foreach ($cameraAngles as $angle) {
        $videoKey = $angleMap[$angle];
        if (isset($videos[$videoKey]) && !empty($videos[$videoKey])) {
            $url = $videos[$videoKey];
            
            // Skip if already tested
            if (isset($testedUrls[$url])) {
                // If we already tested this URL, use the cached result
                if (isset($testedUrls[$url]['status']) && !$testedUrls[$url]['status']) {
                    $allVideosOk = false;
                }
                continue;
            }
            
            $stats['total_tested']++;
            $stats[$type]['tested']++;
            $videosTested++;
            
            echo "  Testing $angle: $url ... ";
            
            $urlOk = testUrl($url);
            
            if (!$urlOk) {
                echo "FAILED (404)\n";
                $stats['total_failed']++;
                $stats[$type]['failed']++;
                $allVideosOk = false;
                
                $failedUrls[] = [
                    'type' => $type,
                    'id' => $id,
                    'name' => $name,
                    'angle' => $angle,
                    'url' => $url
                ];
            } else {
                echo "OK\n";
            }
            
            // Cache the test result
            $testedUrls[$url] = ['status' => $urlOk];
        }
    }
    
    // Update app_ready based on whether all videos are OK
    if ($videosTested > 0 || $hasVideos) {
        $updated = updateAppReady($conn, $type, $id, $allVideosOk);
        $status = $allVideosOk ? 1 : 0;
        echo "  Updated app_ready to $status (affected rows: $updated)\n";
    }
}

echo "Starting video URL validation...\n";
echo "================================\n\n";

// Test all sentences with videos where app_ready = 0
echo "Fetching sentences with videos where app_ready = 0...\n";
$sentenceQuery = "SELECT DISTINCT s.ID as zinid, s.zinString as zin 
                  FROM sentences s 
                  INNER JOIN matched_transcriptions mt ON s.ID = mt.m_transcription 
                  WHERE mt.zOg = 'zin' 
                  AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')
                  AND mt.app_ready = 0
                  ORDER BY s.ID";

$result = $conn->query($sentenceQuery);
if ($result) {
    $totalSentences = $result->num_rows;
    echo "Found $totalSentences sentences with videos\n\n";
    
    while ($row = $result->fetch_assoc()) {
        testEntityVideos('zin', $row['zinid'], $row['zin'], $failedUrls, $testedUrls, $stats, $videoService, $conn);
    }
} else {
    echo "Error fetching sentences: " . $conn->error . "\n";
}

echo "\n================================\n";
echo "Fetching form_data records with videos where app_ready = 0...\n";

// Test all form_data records with videos where app_ready = 0
$formQuery = "SELECT DISTINCT f.id as form_id, f.glos as form_glos 
              FROM form_data f 
              INNER JOIN matched_transcriptions mt ON f.id = mt.m_transcription 
              WHERE mt.zOg IN ('glos', 'extern', 'labels') 
              AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')
              AND f.extern = '1' 
              AND f.glosZichtbaar = '0'
              AND mt.app_ready = 0
              ORDER BY f.id";

$result = $conn->query($formQuery);
if ($result) {
    $totalForms = $result->num_rows;
    echo "Found $totalForms form_data records with videos\n\n";
    
    // Initialize form_data stats if not exists
    if (!isset($stats['glos'])) {
        $stats['glos'] = ['tested' => 0, 'failed' => 0];
    }
    
    while ($row = $result->fetch_assoc()) {
        testEntityVideos('glos', $row['form_id'], $row['form_glos'], $failedUrls, $testedUrls, $stats, $videoService, $conn);
    }
} else {
    echo "Error fetching form_data records: " . $conn->error . "\n";
}

echo "\n================================\n";
echo "Fetching NMM records with videos where app_ready = 0...\n";

// Test all NMM records with videos where app_ready = 0
$nmmQuery = "SELECT DISTINCT n.id as nmm_id, n.glos as nmm_desc 
             FROM nmm_data n 
             INNER JOIN matched_transcriptions mt ON n.id = mt.m_transcription 
             WHERE mt.zOg = 'nmm' 
             AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')
             AND mt.app_ready = 0
             ORDER BY n.id";

$result = $conn->query($nmmQuery);
if ($result) {
    $totalNmm = $result->num_rows;
    echo "Found $totalNmm NMM records with videos\n\n";
    
    while ($row = $result->fetch_assoc()) {
        testEntityVideos('nmm', $row['nmm_id'], $row['nmm_desc'], $failedUrls, $testedUrls, $stats, $videoService, $conn);
    }
} else {
    echo "Error fetching NMM records: " . $conn->error . "\n";
}

// Save failed URLs to JSON
$output = [
    'timestamp' => date('Y-m-d H:i:s'),
    'statistics' => $stats,
    'failed_urls' => $failedUrls
];

file_put_contents('failed.json', json_encode($output, JSON_PRETTY_PRINT));

// Print summary
echo "\n================================\n";
echo "VALIDATION SUMMARY\n";
echo "================================\n";
echo "Total URLs tested: " . $stats['total_tested'] . "\n";
echo "Total failures: " . $stats['total_failed'] . "\n";
echo "\nSentences (zin):\n";
echo "  Tested: " . ($stats['zin']['tested'] ?? 0) . "\n";
echo "  Failed: " . ($stats['zin']['failed'] ?? 0) . "\n";
echo "\nForm Data (glos):\n";
echo "  Tested: " . ($stats['glos']['tested'] ?? 0) . "\n";
echo "  Failed: " . ($stats['glos']['failed'] ?? 0) . "\n";
echo "\nNMM:\n";
echo "  Tested: " . $stats['nmm']['tested'] . "\n";
echo "  Failed: " . $stats['nmm']['failed'] . "\n";
echo "\nFailed URLs saved to: failed.json\n";

// Query and display app_ready statistics
echo "\n================================\n";
echo "APP_READY STATISTICS\n";
echo "================================\n";

$appReadyStats = [
    'zin' => $conn->query("SELECT app_ready, COUNT(*) as count FROM matched_transcriptions WHERE zOg = 'zin' GROUP BY app_ready"),
    'glos' => $conn->query("SELECT app_ready, COUNT(*) as count FROM matched_transcriptions WHERE zOg IN ('glos', 'extern', 'labels') GROUP BY app_ready"),
    'nmm' => $conn->query("SELECT app_ready, COUNT(*) as count FROM matched_transcriptions WHERE zOg = 'nmm' GROUP BY app_ready")
];

foreach ($appReadyStats as $type => $result) {
    echo "\n$type records:\n";
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $status = $row['app_ready'] == 1 ? 'ready' : 'not ready';
            echo "  app_ready=" . $row['app_ready'] . " ($status): " . $row['count'] . " records\n";
        }
    }
}

$conn->close();