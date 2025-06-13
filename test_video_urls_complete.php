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

// Configuration
$baseMediaUrl = 'https://media.signcollect.nl/';
$cameraAngles = ['links', 'midden', 'rechts'];
$failedUrls = [];
$testedUrls = [];
$stats = [
    'total_tested' => 0,
    'total_failed' => 0,
    'sentences' => ['tested' => 0, 'failed' => 0],
    'form_data' => ['tested' => 0, 'failed' => 0],
    'nmm' => ['tested' => 0, 'failed' => 0],
    'zin' => ['tested' => 0, 'failed' => 0],  // Add zin stats
    'glos' => ['tested' => 0, 'failed' => 0], // Add glos stats
    'deduplication' => [
        'nmm_records_skipped_for_form_data' => 0,
        'unique_glos_values_processed' => 0,
        'form_data_glos_values' => [],
        'nmm_glos_values' => []
    ]
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

echo "Starting comprehensive video URL validation...\n";
echo "===============================================\n\n";

// Step 1: Test all sentences with videos
echo "Phase 1: Testing sentences with videos...\n";
$sentenceQuery = "SELECT DISTINCT s.ID as id, s.zinString as name 
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
        testEntityVideos('zin', $row['id'], $row['name'], $failedUrls, $testedUrls, $stats, $videoService);
    }
} else {
    echo "Error fetching sentences: " . $conn->error . "\n";
}

echo "\n===============================================\n";
echo "Phase 2: Building glos deduplication map...\n";

// Step 2: Get all form_data records with videos and their glos values
$formDataQuery = "SELECT DISTINCT f.id, f.glos 
                  FROM form_data f 
                  INNER JOIN matched_transcriptions mt ON f.id = mt.m_transcription 
                  WHERE f.extern = '1' AND f.glosZichtbaar = '0'
                  AND mt.zOg IN ('glos', 'extern') 
                  AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')
                  ORDER BY f.id";

$formDataGlosMap = [];
$result = $conn->query($formDataQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['glos'])) {
            $formDataGlosMap[$row['glos']] = $row['id'];
            $stats['deduplication']['form_data_glos_values'][] = $row['glos'];
        }
    }
}

// Step 3: Get all nmm_data records with videos and their glos values
$nmmDataQuery = "SELECT DISTINCT n.id, n.glos 
                 FROM nmm_data n 
                 INNER JOIN matched_transcriptions mt ON n.id = mt.m_transcription 
                 WHERE mt.zOg = 'nmm' 
                 AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')
                 ORDER BY n.id";

$nmmGlosMap = [];
$result = $conn->query($nmmDataQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['glos'])) {
            $nmmGlosMap[$row['glos']] = $row['id'];
            $stats['deduplication']['nmm_glos_values'][] = $row['glos'];
        }
    }
}

// Step 4: Determine which NMM records to skip (those with glos values also in form_data)
$nmmRecordsToSkip = [];
foreach ($nmmGlosMap as $glos => $nmmId) {
    if (isset($formDataGlosMap[$glos])) {
        $nmmRecordsToSkip[] = $nmmId;
        $stats['deduplication']['nmm_records_skipped_for_form_data']++;
        echo "Skipping NMM ID $nmmId (glos: '$glos') - overridden by form_data ID " . $formDataGlosMap[$glos] . "\n";
    }
}

$uniqueGlosValues = array_unique(array_merge(
    array_keys($formDataGlosMap), 
    array_keys($nmmGlosMap)
));
$stats['deduplication']['unique_glos_values_processed'] = count($uniqueGlosValues);

echo "Found " . count($formDataGlosMap) . " form_data records with glos values\n";
echo "Found " . count($nmmGlosMap) . " nmm_data records with glos values\n";
echo "Unique glos values: " . count($uniqueGlosValues) . "\n";
echo "NMM records to skip: " . count($nmmRecordsToSkip) . "\n\n";

echo "===============================================\n";
echo "Phase 3: Testing form_data records with videos...\n";

// Step 5: Test form_data records
$result = $conn->query($formDataQuery);
if ($result) {
    $totalFormData = $result->num_rows;
    echo "Found $totalFormData form_data records with videos\n\n";
    
    while ($row = $result->fetch_assoc()) {
        testEntityVideos('glos', $row['id'], $row['glos'], $failedUrls, $testedUrls, $stats, $videoService);
    }
} else {
    echo "Error fetching form_data records: " . $conn->error . "\n";
}

echo "\n===============================================\n";
echo "Phase 4: Testing nmm_data records (excluding overridden ones)...\n";

// Step 6: Test nmm_data records (excluding those overridden by form_data)
$nmmTestQuery = "SELECT DISTINCT n.id, n.glos 
                 FROM nmm_data n 
                 INNER JOIN matched_transcriptions mt ON n.id = mt.m_transcription 
                 WHERE mt.zOg = 'nmm' 
                 AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')";

if (!empty($nmmRecordsToSkip)) {
    $placeholders = str_repeat('?,', count($nmmRecordsToSkip) - 1) . '?';
    $nmmTestQuery .= " AND n.id NOT IN ($placeholders)";
}

$nmmTestQuery .= " ORDER BY n.id";

$stmt = $conn->prepare($nmmTestQuery);
if ($stmt) {
    if (!empty($nmmRecordsToSkip)) {
        $types = str_repeat('i', count($nmmRecordsToSkip));
        $stmt->bind_param($types, ...$nmmRecordsToSkip);
    }
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $totalNmm = $result->num_rows;
        echo "Found $totalNmm nmm_data records to test (after deduplication)\n\n";
        
        while ($row = $result->fetch_assoc()) {
            testEntityVideos('nmm', $row['id'], $row['glos'], $failedUrls, $testedUrls, $stats, $videoService);
        }
    } else {
        echo "Error executing NMM query: " . $stmt->error . "\n";
    }
    $stmt->close();
} else {
    echo "Error preparing NMM query: " . $conn->error . "\n";
}

// Save results to JSON
$output = [
    'timestamp' => date('Y-m-d H:i:s'),
    'statistics' => $stats,
    'failed_urls' => $failedUrls
];

file_put_contents('failed.json', json_encode($output, JSON_PRETTY_PRINT));

// Print final summary
echo "\n===============================================\n";
echo "COMPREHENSIVE VALIDATION SUMMARY\n";
echo "===============================================\n";
echo "Total URLs tested: " . $stats['total_tested'] . "\n";
echo "Total failures: " . $stats['total_failed'] . "\n";
echo "\nBreakdown by source:\n";
echo "Sentences:\n";
echo "  Tested: " . $stats['sentences']['tested'] . "\n";
echo "  Failed: " . $stats['sentences']['failed'] . "\n";
echo "\nForm Data (glos):\n";
echo "  Tested: " . $stats['form_data']['tested'] . "\n";
echo "  Failed: " . $stats['form_data']['failed'] . "\n";
echo "\nNMM Data:\n";
echo "  Tested: " . $stats['nmm']['tested'] . "\n";
echo "  Failed: " . $stats['nmm']['failed'] . "\n";
echo "\nDeduplication:\n";
echo "  Unique glos values: " . $stats['deduplication']['unique_glos_values_processed'] . "\n";
echo "  NMM records skipped: " . $stats['deduplication']['nmm_records_skipped_for_form_data'] . "\n";
echo "\nResults saved to: failed.json\n";

$conn->close();