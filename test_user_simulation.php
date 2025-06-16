<?php
/**
 * User Simulation Test Script
 * 
 * This script simulates real user behavior by:
 * 1. Collecting all glosses from form_data and nmm_data
 * 2. Searching for each glos via the API
 * 3. Testing video retrieval for found results
 * 4. Generating comprehensive reports
 */

require_once __DIR__ . '/mysql_config.php';

// Configuration
const API_BASE_URL = 'https://api.signcollect.nl';
const TEST_LIMIT = null; // Set to null for no limit, or number for testing subset
const TIMEOUT_SECONDS = 10;

// Statistics tracking
$stats = [
    'total_glosses' => 0,
    'searches_attempted' => 0,
    'searches_successful' => 0,
    'searches_failed' => 0,
    'searches_empty' => 0,
    'videos_tested' => 0,
    'videos_working' => 0,
    'videos_failed' => 0,
    'total_response_time' => 0
];

$detailed_results = [];
$failed_searches = [];
$failed_videos = [];
$working_examples = [];
$matched_transcriptions_404s = [];

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

echo "🚀 Starting User Simulation Test\n";
echo "================================\n";
echo "API Base URL: " . API_BASE_URL . "\n";
echo "Test Limit: " . (TEST_LIMIT ? TEST_LIMIT . " glosses" : "All glosses") . "\n";
echo "Timeout: " . TIMEOUT_SECONDS . " seconds per request\n\n";

// ===============================
// PHASE 1: DATA COLLECTION
// ===============================
echo "📊 PHASE 1: Collecting glosses from database\n";
echo "---------------------------------------------\n";

$glosses = [];

// Collect from form_data
echo "Collecting from form_data (extern='1')...\n";
$formQuery = "SELECT DISTINCT glos FROM form_data WHERE extern = '1' AND glosZichtbaar = '0' AND glos != '' ORDER BY glos";
$result = $conn->query($formQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $glosses[] = ['glos' => $row['glos'], 'source' => 'form_data'];
    }
    echo "  Found " . count($glosses) . " unique glosses from form_data\n";
}

// Collect from nmm_data
echo "Collecting from nmm_data...\n";
$nmmQuery = "SELECT DISTINCT glos FROM nmm_data WHERE glos != '' ORDER BY glos";
$result = $conn->query($nmmQuery);
$nmmCount = 0;
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Check if glos already exists
        $exists = false;
        foreach ($glosses as $existing) {
            if ($existing['glos'] === $row['glos']) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $glosses[] = ['glos' => $row['glos'], 'source' => 'nmm_data'];
            $nmmCount++;
        }
    }
    echo "  Found $nmmCount unique glosses from nmm_data (after deduplication)\n";
}

$stats['total_glosses'] = count($glosses);
echo "  Total unique glosses: " . $stats['total_glosses'] . "\n\n";

// Apply test limit if configured
if (TEST_LIMIT && count($glosses) > TEST_LIMIT) {
    $glosses = array_slice($glosses, 0, TEST_LIMIT);
    echo "  Limited to " . TEST_LIMIT . " glosses for testing\n\n";
}

$conn->close();

// ===============================
// PHASE 2: SEARCH SIMULATION
// ===============================
echo "🔍 PHASE 2: Simulating user searches\n";
echo "------------------------------------\n";

foreach ($glosses as $index => $glossData) {
    $glos = $glossData['glos'];
    $source = $glossData['source'];
    $progress = $index + 1;
    $total = count($glosses);
    
    echo "[$progress/$total] Testing: '$glos' (from $source)\n";
    
    $stats['searches_attempted']++;
    $startTime = microtime(true);
    
    // Make search request
    $searchResult = makeApiRequest('POST', '/index.php', [
        'query' => $glos,
        'resultType' => 'all'
    ]);
    
    $responseTime = microtime(true) - $startTime;
    $stats['total_response_time'] += $responseTime;
    
    if ($searchResult === false) {
        echo "  ❌ Search failed (network/curl error)\n";
        $stats['searches_failed']++;
        $failed_searches[] = [
            'glos' => $glos,
            'source' => $source,
            'error' => 'Network/cURL error'
        ];
        continue;
    }
    
    $searchData = json_decode($searchResult, true);
    
    if (!$searchData || !isset($searchData['success'])) {
        echo "  ❌ Invalid JSON response\n";
        $stats['searches_failed']++;
        $failed_searches[] = [
            'glos' => $glos,
            'source' => $source,
            'error' => 'Invalid JSON response'
        ];
        continue;
    }
    
    if (!$searchData['success']) {
        echo "  ❌ API error: " . implode(', ', $searchData['errors'] ?? ['Unknown error']) . "\n";
        $stats['searches_failed']++;
        $failed_searches[] = [
            'glos' => $glos,
            'source' => $source,
            'error' => implode(', ', $searchData['errors'] ?? ['Unknown error'])
        ];
        continue;
    }
    
    // Count results
    $sentences = $searchData['data']['sentences'] ?? [];
    $foundGlosses = $searchData['data']['glosses'] ?? [];
    $totalResults = count($sentences) + count($foundGlosses);
    
    if ($totalResults === 0) {
        // Check if this glos exists in matched_transcriptions and test video URLs
        $matchedTransCheck = checkGlossInMatchedTranscriptions($glos);
        
        if ($matchedTransCheck['exists']) {
            $videoStatus = $matchedTransCheck['video_status'];
            
            if ($videoStatus == 404) {
                echo "  ❌ No results found but glos exists in matched_transcriptions with 404 video (true 404 issue)\n";
                $stats['searches_failed']++;
                $failed_searches[] = [
                    'glos' => $glos,
                    'source' => $source,
                    'error' => 'No results but exists in matched_transcriptions with 404 video'
                ];
                $matched_transcriptions_404s[] = [
                    'glos' => $glos,
                    'source' => $source,
                    'video_status' => 404,
                    'tested_url' => $matchedTransCheck['tested_url']
                ];
            } else if ($videoStatus == 200) {
                echo "  ⚠️  No results found but glos exists in matched_transcriptions with 200 video (likely app_ready=0)\n";
                $stats['searches_empty']++;
                // This is a different issue - video exists but not showing in search
            } else {
                echo "  ⚠️  No results found but glos exists in matched_transcriptions (video status: $videoStatus)\n";
                $stats['searches_empty']++;
            }
        } else {
            echo "  ⚠️  No results found (glos not captured yet - OK)\n";
            $stats['searches_empty']++;
        }
        continue;
    }
    
    echo "  ✅ Found $totalResults results (" . count($sentences) . " sentences, " . count($foundGlosses) . " glosses) in " . round($responseTime, 3) . "s\n";
    $stats['searches_successful']++;
    
    // Store detailed result
    $detailed_results[] = [
        'glos' => $glos,
        'source' => $source,
        'sentences_count' => count($sentences),
        'glosses_count' => count($foundGlosses),
        'response_time' => $responseTime,
        'sentences' => $sentences,
        'glosses' => $foundGlosses
    ];
    
    // Test videos for found results
    testVideosForResults($sentences, $foundGlosses, $glos);
    
    // Add working example
    if (count($working_examples) < 5 && $totalResults > 0) {
        $working_examples[] = [
            'glos' => $glos,
            'source' => $source,
            'results' => $totalResults,
            'response_time' => $responseTime
        ];
    }
    
    echo "\n";
}

// ===============================
// PHASE 3: FINAL REPORTING
// ===============================
echo "📈 PHASE 3: Final Report\n";
echo "=======================\n\n";

echo "🔢 SEARCH STATISTICS:\n";
echo "  Total glosses tested: " . $stats['searches_attempted'] . "\n";
echo "  Successful searches: " . $stats['searches_successful'] . " (" . round(($stats['searches_successful'] / $stats['searches_attempted']) * 100, 1) . "%)\n";
echo "  Empty results: " . $stats['searches_empty'] . " (" . round(($stats['searches_empty'] / $stats['searches_attempted']) * 100, 1) . "%)\n";
echo "  Failed searches: " . $stats['searches_failed'] . " (" . round(($stats['searches_failed'] / $stats['searches_attempted']) * 100, 1) . "%)\n";
echo "  Average response time: " . round($stats['total_response_time'] / $stats['searches_attempted'], 3) . "s\n\n";

echo "🎥 VIDEO STATISTICS:\n";
echo "  Videos tested: " . $stats['videos_tested'] . "\n";
echo "  Working videos: " . $stats['videos_working'] . " (" . ($stats['videos_tested'] > 0 ? round(($stats['videos_working'] / $stats['videos_tested']) * 100, 1) : 0) . "%)\n";
echo "  Failed videos: " . $stats['videos_failed'] . " (" . ($stats['videos_tested'] > 0 ? round(($stats['videos_failed'] / $stats['videos_tested']) * 100, 1) : 0) . "%)\n\n";

if (!empty($working_examples)) {
    echo "✅ WORKING EXAMPLES:\n";
    foreach ($working_examples as $example) {
        echo "  • '{$example['glos']}' ({$example['source']}) → {$example['results']} results in {$example['response_time']}s\n";
    }
    echo "\n";
}

if (!empty($failed_searches)) {
    echo "❌ FAILED SEARCHES (first 10):\n";
    foreach (array_slice($failed_searches, 0, 10) as $failure) {
        echo "  • '{$failure['glos']}' ({$failure['source']}) → {$failure['error']}\n";
    }
    if (count($failed_searches) > 10) {
        echo "  ... and " . (count($failed_searches) - 10) . " more\n";
    }
    echo "\n";
}

if (!empty($failed_videos)) {
    echo "❌ FAILED VIDEOS (first 10):\n";
    foreach (array_slice($failed_videos, 0, 10) as $failure) {
        echo "  • {$failure['type']}/{$failure['id']} → {$failure['error']}\n";
    }
    if (count($failed_videos) > 10) {
        echo "  ... and " . (count($failed_videos) - 10) . " more\n";
    }
    echo "\n";
}

if (!empty($matched_transcriptions_404s)) {
    echo "🔴 GLOSSES WITH TRUE 404 VIDEO ISSUES:\n";
    echo "  Total: " . count($matched_transcriptions_404s) . " glosses\n";
    echo "  (These glosses exist in matched_transcriptions but their videos return 404)\n";
    foreach (array_slice($matched_transcriptions_404s, 0, 10) as $issue) {
        echo "  • '{$issue['glos']}' (from {$issue['source']}) - tested URL: {$issue['tested_url']}\n";
    }
    if (count($matched_transcriptions_404s) > 10) {
        echo "  ... and " . (count($matched_transcriptions_404s) - 10) . " more\n";
    }
    echo "\n";
}

echo "💾 Detailed results saved to: user_simulation_results.json\n";

// Save detailed results to JSON file
$fullReport = [
    'timestamp' => date('Y-m-d H:i:s'),
    'configuration' => [
        'api_base_url' => API_BASE_URL,
        'test_limit' => TEST_LIMIT,
        'timeout_seconds' => TIMEOUT_SECONDS
    ],
    'statistics' => $stats,
    'working_examples' => $working_examples,
    'failed_searches' => $failed_searches,
    'failed_videos' => $failed_videos,
    'matched_transcriptions_404s' => $matched_transcriptions_404s,
    'detailed_results' => $detailed_results
];

file_put_contents('user_simulation_results.json', json_encode($fullReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n🎉 User simulation test completed!\n";

// ===============================
// HELPER FUNCTIONS
// ===============================

/**
 * Check if a glos exists in matched_transcriptions table and test video URLs
 */
function checkGlossInMatchedTranscriptions($glos) {
    global $servername, $username, $password, $database;
    
    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        return ['exists' => false, 'video_status' => null];
    }
    $conn->set_charset("utf8mb4");
    
    $videoUrls = [];
    
    // Check form_data records and get video URLs
    $formQuery = "SELECT f.id, mt.l_file, mt.m_file, mt.r_file, mt.app_ready
                  FROM form_data f 
                  INNER JOIN matched_transcriptions mt ON f.id = mt.m_transcription 
                  WHERE f.glos = ? AND mt.zOg IN ('glos', 'extern', 'labels')
                  AND f.extern = '1' AND f.glosZichtbaar = '0'";
    
    $stmt = $conn->prepare($formQuery);
    $stmt->bind_param("s", $glos);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['l_file'])) $videoUrls[] = 'https://media.signcollect.nl/' . $row['l_file'];
        if (!empty($row['m_file'])) $videoUrls[] = 'https://media.signcollect.nl/' . $row['m_file'];
        if (!empty($row['r_file'])) $videoUrls[] = 'https://media.signcollect.nl/' . $row['r_file'];
    }
    $stmt->close();
    
    // Check nmm_data records and get video URLs
    $nmmQuery = "SELECT n.id, mt.l_file, mt.m_file, mt.r_file, mt.app_ready
                 FROM nmm_data n 
                 INNER JOIN matched_transcriptions mt ON n.id = mt.m_transcription 
                 WHERE n.glos = ? AND mt.zOg = 'nmm'";
    
    $stmt = $conn->prepare($nmmQuery);
    $stmt->bind_param("s", $glos);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['l_file'])) $videoUrls[] = 'https://media.signcollect.nl/' . $row['l_file'];
        if (!empty($row['m_file'])) $videoUrls[] = 'https://media.signcollect.nl/' . $row['m_file'];
        if (!empty($row['r_file'])) $videoUrls[] = 'https://media.signcollect.nl/' . $row['r_file'];
    }
    $stmt->close();
    
    $conn->close();
    
    if (empty($videoUrls)) {
        return ['exists' => false, 'video_status' => null];
    }
    
    // Test the first available video URL
    $videoStatus = testVideoUrl($videoUrls[0]);
    
    return ['exists' => true, 'video_status' => $videoStatus, 'tested_url' => $videoUrls[0]];
}

/**
 * Test if a video URL returns 200 or 404
 */
function testVideoUrl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode;
}

/**
 * Make API request using cURL
 */
function makeApiRequest($method, $endpoint, $data = null) {
    $url = API_BASE_URL . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_SECONDS);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'UserSimulationTest/1.0');
    
    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
    }
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($result === false || $httpCode !== 200) {
        return false;
    }
    
    return $result;
}

/**
 * Test videos for search results
 */
function testVideosForResults($sentences, $glosses, $searchTerm) {
    global $stats, $failed_videos;
    
    // Test sentence videos
    foreach ($sentences as $sentence) {
        if (isset($sentence['id'])) {
            testVideoEndpoint('zin', $sentence['id'], $searchTerm);
        }
    }
    
    // Test gloss videos
    foreach ($glosses as $gloss) {
        if (isset($gloss['id'])) {
            testVideoEndpoint('glos', $gloss['id'], $searchTerm);
        }
    }
}

/**
 * Test individual video endpoint
 */
function testVideoEndpoint($type, $id, $searchTerm) {
    global $stats, $failed_videos;
    
    $stats['videos_tested']++;
    
    $videoResult = makeApiRequest('GET', "/videos/$type/$id");
    
    if ($videoResult === false) {
        $stats['videos_failed']++;
        $failed_videos[] = [
            'type' => $type,
            'id' => $id,
            'search_term' => $searchTerm,
            'error' => 'Network/cURL error'
        ];
        return;
    }
    
    $videoData = json_decode($videoResult, true);
    
    if (!$videoData || !isset($videoData['success']) || !$videoData['success']) {
        $stats['videos_failed']++;
        $failed_videos[] = [
            'type' => $type,
            'id' => $id,
            'search_term' => $searchTerm,
            'error' => 'API error: ' . implode(', ', $videoData['errors'] ?? ['Unknown error'])
        ];
        return;
    }
    
    // Check if videos exist
    $videos = $videoData['data']['videos'] ?? [];
    $hasVideos = !empty($videos['videoLeft']) || !empty($videos['videoCenter']) || !empty($videos['videoRight']);
    
    if ($hasVideos) {
        $stats['videos_working']++;
    } else {
        $stats['videos_failed']++;
        $failed_videos[] = [
            'type' => $type,
            'id' => $id,
            'search_term' => $searchTerm,
            'error' => 'No video URLs returned'
        ];
    }
}

?>