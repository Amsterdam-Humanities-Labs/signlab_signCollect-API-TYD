<?php
/**
 * Final test of GIRAFFE with updated logic
 */

require 'src/config/config.php';
include '../../mysql_config.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/VideoService.php';
require_once 'src/services/SentenceService.php';

$response = ['debug' => []];
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

echo "Final Test of GIRAFFE with Updated Logic\n";
echo str_repeat('=', 50) . "\n\n";

// Initialize services
$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$sentenceService = new SentenceService($conn, $response, $videoService);

// Use reflection to test the private method
$reflection = new ReflectionClass($sentenceService);
$method = $reflection->getMethod('getLatestMatchedTranscriptionForGloss');
$method->setAccessible(true);

// Test GIRAFFE
$result = $method->invoke($sentenceService, 'GIRAFFE');

if ($result) {
    echo "Found latest match for GIRAFFE:\n";
    echo "  Source type: " . $result['source']['type'] . "\n";
    echo "  Source ID: " . $result['source']['id'] . "\n";
    echo "  Matched transcription ID: " . $result['transcription']['id'] . "\n";
    echo "  zOg: " . $result['transcription']['zOg'] . "\n";
    echo "  Files:\n";
    echo "    Left: " . $result['transcription']['l_file'] . "\n";
    echo "    Center: " . $result['transcription']['m_file'] . "\n";
    echo "    Right: " . $result['transcription']['r_file'] . "\n";
} else {
    echo "No match found for GIRAFFE\n";
}

// Test what would happen if we had a sentence with GIRAFFE
echo "\n\nTesting with a mock sentence containing GIRAFFE:\n";
echo str_repeat('-', 50) . "\n";

// Create a test sentence
$testGlosses = json_encode(['GIRAFFE']);
$conn->query("INSERT INTO sentences (zinString, glosses, thema) VALUES ('Test giraffe', '$testGlosses', 'Test')");
$testSentenceId = $conn->insert_id;

if ($testSentenceId) {
    $response['debug'] = []; // Clear debug
    $videoData = $sentenceService->getVideoDataForSentence($testSentenceId);
    
    if (isset($videoData['glossVideosData'])) {
        foreach ($videoData['glossVideosData'] as $glossData) {
            echo "Gloss: " . $glossData['gloss'] . "\n";
            echo "  Data source: " . ($glossData['dataSource'] ?? 'none') . "\n";
            
            if ($glossData['dataSource'] === 'nmm_data') {
                echo "  NMM ID: " . $glossData['nmmId'] . "\n";
                echo "  Has videos: " . (!empty($glossData['videos']) ? "Yes" : "No") . "\n";
                
                if (!empty($glossData['videos'])) {
                    foreach ($glossData['videos'] as $angle => $url) {
                        if ($url) {
                            echo "    $angle: " . basename($url) . "\n";
                        }
                    }
                }
            }
            
            if (isset($response['debug']['gloss_latest_match'][$glossData['gloss']])) {
                $matchInfo = $response['debug']['gloss_latest_match'][$glossData['gloss']];
                echo "  Used matched_transcription ID: " . $matchInfo['matched_transcription_id'] . "\n";
                echo "  zOg: " . $videoData['glossVideosData'][0]['videos'] . "\n";
            }
        }
    }
    
    // Clean up
    $conn->query("DELETE FROM sentences WHERE ID = $testSentenceId");
}

$conn->close();
echo "\nTest completed!\n";