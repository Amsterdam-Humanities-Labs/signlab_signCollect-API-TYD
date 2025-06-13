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
$response = [];
$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);

// Check if failed.json already exists and load it
$existingData = [];
if (file_exists('failed.json')) {
    $existingData = json_decode(file_get_contents('failed.json'), true);
}

$failedUrls = isset($existingData['failed_urls']) ? $existingData['failed_urls'] : [];
$stats = isset($existingData['statistics']) ? $existingData['statistics'] : [
    'total_tested' => 0,
    'total_failed' => 0,
    'sentences' => ['tested' => 0, 'failed' => 0],
    'nmm' => ['tested' => 0, 'failed' => 0],
    'zin' => ['tested' => 0, 'failed' => 0]
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

echo "Testing NMM video URLs...\n";
echo "================================\n\n";

// Test all NMM records with videos
$nmmQuery = "SELECT DISTINCT n.id as nmm_id, n.glos as nmm_desc 
             FROM nmm_data n 
             INNER JOIN matched_transcriptions mt ON n.id = mt.m_transcription 
             WHERE mt.zOg = 'nmm' 
             AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')
             ORDER BY n.id";

$result = $conn->query($nmmQuery);
if ($result) {
    $totalNmm = $result->num_rows;
    echo "Found $totalNmm NMM records with videos\n\n";
    
    $testedUrls = [];
    $cameraAngles = ['links', 'midden', 'rechts'];
    $angleMap = [
        'links' => 'videoLeft',
        'midden' => 'videoCenter',
        'rechts' => 'videoRight'
    ];
    
    while ($row = $result->fetch_assoc()) {
        echo "Testing nmm ID: " . $row['nmm_id'] . " (" . $row['nmm_desc'] . ")\n";
        
        // Get video URLs using VideoService
        $videos = $videoService->getVideosForEntity($row['nmm_id'], 'nmm');
        
        // Check if we have any videos
        $hasVideos = false;
        if (isset($videos['videoLeft']) && $videos['videoLeft']) $hasVideos = true;
        if (isset($videos['videoCenter']) && $videos['videoCenter']) $hasVideos = true;
        if (isset($videos['videoRight']) && $videos['videoRight']) $hasVideos = true;
        
        if (!$hasVideos) {
            echo "  No videos found for nmm ID: " . $row['nmm_id'] . "\n";
            continue;
        }
        
        foreach ($cameraAngles as $angle) {
            $videoKey = $angleMap[$angle];
            if (isset($videos[$videoKey]) && !empty($videos[$videoKey])) {
                $url = $videos[$videoKey];
                
                // Skip if already tested
                if (isset($testedUrls[$url])) {
                    continue;
                }
                
                $stats['total_tested']++;
                $stats['nmm']['tested']++;
                
                echo "  Testing $angle: $url ... ";
                
                if (!testUrl($url)) {
                    echo "FAILED (404)\n";
                    $stats['total_failed']++;
                    $stats['nmm']['failed']++;
                    
                    $failedUrls[] = [
                        'type' => 'nmm',
                        'id' => $row['nmm_id'],
                        'name' => $row['nmm_desc'],
                        'angle' => $angle,
                        'url' => $url
                    ];
                } else {
                    echo "OK\n";
                }
                
                $testedUrls[$url] = true;
            }
        }
    }
} else {
    echo "Error fetching NMM records: " . $conn->error . "\n";
}

// Save updated failed URLs to JSON
$output = [
    'timestamp' => date('Y-m-d H:i:s'),
    'statistics' => $stats,
    'failed_urls' => $failedUrls
];

file_put_contents('failed.json', json_encode($output, JSON_PRETTY_PRINT));

// Print summary
echo "\n================================\n";
echo "NMM VALIDATION SUMMARY\n";
echo "================================\n";
echo "Total NMM URLs tested: " . $stats['nmm']['tested'] . "\n";
echo "Total NMM failures: " . $stats['nmm']['failed'] . "\n";
echo "\nFailed URLs saved to: failed.json\n";

$conn->close();