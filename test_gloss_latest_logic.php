<?php
/**
 * Test the getLatestMatchedTranscriptionForGloss method
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

// Initialize services
$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$sentenceService = new SentenceService($conn, $response, $videoService);

// Create reflection to access private method
$reflection = new ReflectionClass($sentenceService);
$method = $reflection->getMethod('getLatestMatchedTranscriptionForGloss');
$method->setAccessible(true);

// Test glosses
$testGlosses = ['FRUIT-A', 'BES', 'DADEL-B', 'PT-1hand', 'HAND-OMHOOG'];

echo "Testing getLatestMatchedTranscriptionForGloss method:\n";
echo str_repeat('=', 50) . "\n\n";

foreach ($testGlosses as $gloss) {
    echo "Testing gloss: '$gloss'\n";
    
    $result = $method->invoke($sentenceService, $gloss);
    
    if ($result) {
        echo "  Found latest match:\n";
        echo "    - Source type: " . $result['source']['type'] . "\n";
        echo "    - Source ID: " . $result['source']['id'] . "\n";
        echo "    - Matched transcription ID: " . $result['transcription']['id'] . "\n";
        echo "    - zOg: " . $result['transcription']['zOg'] . "\n";
        
        // Show video files if available
        if (!empty($result['transcription']['l_file'])) {
            echo "    - Left file: " . $result['transcription']['l_file'] . "\n";
        }
        if (!empty($result['transcription']['m_file'])) {
            echo "    - Center file: " . $result['transcription']['m_file'] . "\n";
        }
        if (!empty($result['transcription']['r_file'])) {
            echo "    - Right file: " . $result['transcription']['r_file'] . "\n";
        }
    } else {
        echo "  No matched transcriptions found\n";
    }
    echo "\n";
}

$conn->close();
echo "Test completed!\n";