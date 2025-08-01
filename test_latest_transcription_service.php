<?php
/**
 * Test the new LatestTranscriptionService
 */

require 'src/config/config.php';
include '../../mysql_config.php';
require_once 'src/services/LatestTranscriptionService.php';

$response = ['debug' => []];
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

echo "Testing LatestTranscriptionService with GIRAFFE\n";
echo str_repeat('=', 50) . "\n\n";

// Initialize service
$latestService = new LatestTranscriptionService($conn, $response);

// Test getting latest matched_transcriptions
echo "1. Testing getLatestMatchedTranscriptionForGloss('GIRAFFE'):\n";
echo str_repeat('-', 50) . "\n";

$result = $latestService->getLatestMatchedTranscriptionForGloss('GIRAFFE');

if ($result) {
    echo "✅ Found latest match:\n";
    echo "    Source type: " . $result['source']['type'] . "\n";
    echo "    Source ID: " . $result['source']['id'] . "\n";
    echo "    Matched transcription ID: " . $result['transcription']['id'] . "\n";
    echo "    zOg: " . $result['transcription']['zOg'] . "\n";
    echo "    Files: " . $result['transcription']['l_file'] . ", " . 
         $result['transcription']['m_file'] . ", " . $result['transcription']['r_file'] . "\n";
} else {
    echo "❌ No match found\n";
}

// Test getting videos
echo "\n2. Testing getLatestVideosForGloss('GIRAFFE'):\n";
echo str_repeat('-', 50) . "\n";

$videoResult = $latestService->getLatestVideosForGloss('GIRAFFE');

echo "Source: " . ($videoResult['source']['type'] ?? 'none') . "\n";
echo "Source ID: " . ($videoResult['source']['id'] ?? 'none') . "\n";
echo "Matched transcription ID: " . ($videoResult['matched_transcription_id'] ?? 'none') . "\n";
echo "zOg: " . ($videoResult['zOg'] ?? 'none') . "\n";

echo "\nVideos:\n";
foreach ($videoResult['videos'] as $angle => $url) {
    if ($url) {
        echo "  $angle: " . basename($url) . "\n";
    } else {
        echo "  $angle: null\n";
    }
}

// Test with different variations
echo "\n3. Testing different gloss variations:\n";
echo str_repeat('-', 50) . "\n";

$testGlosses = ['GIRAFFE', 'giraffe', 'GIRAFFE-A', 'iraffe'];
foreach ($testGlosses as $testGloss) {
    echo "\nTesting '$testGloss':\n";
    $result = $latestService->getLatestMatchedTranscriptionForGloss($testGloss);
    
    if ($result) {
        echo "  Found: MT ID " . $result['transcription']['id'] . 
             " from " . $result['source']['type'] . 
             " ID " . $result['source']['id'] . 
             " (zOg: " . $result['transcription']['zOg'] . ")\n";
    } else {
        echo "  No match found\n";
    }
}

$conn->close();
echo "\nTest completed!\n";