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

// Function to test video URLs for an entity
function testEntityVideos($type, $id, $name, &$failedUrls, &$testedUrls, &$stats, $videoService) {
    global $cameraAngles;
    
    echo "Testing $type ID: $id ($name)\n";
    
    // Get video URLs using VideoService
    $videos = $videoService->getVideosForEntity($id, $type);
    
    // Check if we have any videos
    $hasVideos = false;
    if (isset($videos['videoLeft']) && $videos['videoLeft']) $hasVideos = true;
    if (isset($videos['videoCenter']) && $videos['videoCenter']) $hasVideos = true;
    if (isset($videos['videoRight']) && $videos['videoRight']) $hasVideos = true;
    
    if (!$hasVideos) {
        echo "  No videos found for $type ID: $id\n";
        return;
    }
    
    // Map angle names to video keys
    $angleMap = [
        'links' => 'videoLeft',
        'midden' => 'videoCenter',
        'rechts' => 'videoRight'
    ];
    
    foreach ($cameraAngles as $angle) {
        $videoKey = $angleMap[$angle];
        if (isset($videos[$videoKey]) && !empty($videos[$videoKey])) {
            $url = $videos[$videoKey];
            
            // Skip if already tested
            if (isset($testedUrls[$url])) {
                continue;
            }
            
            $stats['total_tested']++;
            $stats[$type]['tested']++;
            
            echo "  Testing $angle: $url ... ";
            
            if (!testUrl($url)) {
                echo "FAILED (404)\n";
                $stats['total_failed']++;
                $stats[$type]['failed']++;
                
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
            
            $testedUrls[$url] = true;
        }
    }
}

echo "Starting video URL validation...\n";
echo "================================\n\n";

// Test all sentences with videos
echo "Fetching sentences with videos...\n";
$sentenceQuery = "SELECT DISTINCT s.ID as zinid, s.zinString as zin 
                  FROM sentences s 
                  INNER JOIN matched_transcriptions mt ON s.ID = mt.m_transcription 
                  WHERE mt.zOg = 'zin' 
                  AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')
                  ORDER BY s.ID";

$result = $conn->query($sentenceQuery);
if ($result) {
    $totalSentences = $result->num_rows;
    echo "Found $totalSentences sentences with videos\n\n";
    
    while ($row = $result->fetch_assoc()) {
        testEntityVideos('zin', $row['zinid'], $row['zin'], $failedUrls, $testedUrls, $stats, $videoService);
    }
} else {
    echo "Error fetching sentences: " . $conn->error . "\n";
}

echo "\n================================\n";
echo "Fetching NMM records with videos...\n";

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
    
    while ($row = $result->fetch_assoc()) {
        testEntityVideos('nmm', $row['nmm_id'], $row['nmm_desc'], $failedUrls, $testedUrls, $stats, $videoService);
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
echo "\nSentences:\n";
echo "  Tested: " . $stats['sentences']['tested'] . "\n";
echo "  Failed: " . $stats['sentences']['failed'] . "\n";
echo "\nNMM:\n";
echo "  Tested: " . $stats['nmm']['tested'] . "\n";
echo "  Failed: " . $stats['nmm']['failed'] . "\n";
echo "\nFailed URLs saved to: failed.json\n";

$conn->close();