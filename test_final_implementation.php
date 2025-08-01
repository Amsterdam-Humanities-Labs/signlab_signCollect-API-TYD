<?php
/**
 * Final test of the new priority implementation
 */

require 'src/config/config.php';
include '../../mysql_config.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/VideoService.php';
require_once 'src/services/SentenceService.php';
require_once 'src/services/NmmService.php';

$response = ['debug' => []];
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize services
$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$sentenceService = new SentenceService($conn, $response, $videoService);

echo "Final Test of New Priority Implementation\n";
echo str_repeat('=', 50) . "\n\n";

// Test 1: Direct test with known overlapping glosses
echo "Test 1: Testing glosses with both form_data and nmm_data entries\n";
echo str_repeat('-', 50) . "\n";

$testGlosses = ['FRUIT-A', 'BES', 'GESLACHT-A'];

foreach ($testGlosses as $gloss) {
    echo "\nGloss: '$gloss'\n";
    
    // Get form_data info
    $sql = "SELECT fd.id, mt.id as mt_id 
            FROM form_data fd
            LEFT JOIN matched_transcriptions mt ON mt.m_transcription = fd.id AND mt.zOg IN ('glos', 'extern', 'labels')
            WHERE fd.glos = ? AND fd.extern = '1' AND fd.glosZichtbaar = '0'
            ORDER BY mt.id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $gloss);
    $stmt->execute();
    $result = $stmt->get_result();
    $formData = $result->fetch_assoc();
    $stmt->close();
    
    // Get nmm_data info
    $sql = "SELECT nd.id, mt.id as mt_id 
            FROM nmm_data nd
            LEFT JOIN matched_transcriptions mt ON mt.m_transcription = nd.id AND mt.zOg = 'nmm'
            WHERE nd.glos = ?
            ORDER BY mt.id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $gloss);
    $stmt->execute();
    $result = $stmt->get_result();
    $nmmData = $result->fetch_assoc();
    $stmt->close();
    
    if ($formData && $formData['mt_id']) {
        echo "  form_data: ID=" . $formData['id'] . ", matched_transcriptions.id=" . $formData['mt_id'] . "\n";
    }
    if ($nmmData && $nmmData['mt_id']) {
        echo "  nmm_data: ID=" . $nmmData['id'] . ", matched_transcriptions.id=" . $nmmData['mt_id'] . "\n";
    }
    
    if ($formData && $nmmData && $formData['mt_id'] && $nmmData['mt_id']) {
        if ($formData['mt_id'] > $nmmData['mt_id']) {
            echo "  → Should use: form_data (newer)\n";
        } else {
            echo "  → Should use: nmm_data (newer)\n";
        }
    }
}

// Test 2: Test with a sentence
echo "\n\nTest 2: Testing getVideoDataForSentence with sentence ID 7\n";
echo str_repeat('-', 50) . "\n";

$videoData = $sentenceService->getVideoDataForSentence(7);

if (isset($videoData['glosses'])) {
    echo "Sentence glosses: " . implode(', ', $videoData['glosses']) . "\n\n";
}

if (isset($videoData['glossVideosData'])) {
    foreach ($videoData['glossVideosData'] as $glossData) {
        echo "Gloss: " . $glossData['gloss'] . "\n";
        echo "  Data source: " . ($glossData['dataSource'] ?? 'none') . "\n";
        
        if ($glossData['dataSource']) {
            if ($glossData['formDataId']) {
                echo "  Form data ID: " . $glossData['formDataId'] . "\n";
            }
            if ($glossData['nmmId']) {
                echo "  NMM data ID: " . $glossData['nmmId'] . "\n";
            }
            
            $hasVideos = !empty($glossData['videos']['left']) || 
                        !empty($glossData['videos']['center']) || 
                        !empty($glossData['videos']['right']);
            echo "  Has videos: " . ($hasVideos ? "Yes" : "No") . "\n";
        }
        
        if (isset($response['debug']['gloss_latest_match'][$glossData['gloss']])) {
            $matchInfo = $response['debug']['gloss_latest_match'][$glossData['gloss']];
            echo "  Debug - matched_transcription ID: " . $matchInfo['matched_transcription_id'] . "\n";
            echo "  Debug - source: " . $matchInfo['source'] . "\n";
        }
        echo "\n";
    }
}

// Test 3: Create a test sentence with known glosses
echo "\nTest 3: Testing with a sentence containing 'FRUIT-A' and 'BES'\n";
echo str_repeat('-', 50) . "\n";

// Insert test sentence temporarily
$testGlosses = json_encode(['FRUIT-A', 'BES']);
$conn->query("INSERT INTO sentences (zinString, glosses, thema) VALUES ('Test sentence for priority', '$testGlosses', 'Test')");
$testSentenceId = $conn->insert_id;

if ($testSentenceId) {
    $response['debug'] = []; // Clear debug for clean output
    $videoData = $sentenceService->getVideoDataForSentence($testSentenceId);
    
    if (isset($videoData['glossVideosData'])) {
        foreach ($videoData['glossVideosData'] as $glossData) {
            echo "Gloss: " . $glossData['gloss'] . "\n";
            echo "  Data source: " . ($glossData['dataSource'] ?? 'none') . "\n";
            
            if (isset($response['debug']['gloss_latest_match'][$glossData['gloss']])) {
                $matchInfo = $response['debug']['gloss_latest_match'][$glossData['gloss']];
                echo "  Used matched_transcription ID: " . $matchInfo['matched_transcription_id'] . "\n";
                echo "  From source: " . $matchInfo['source'] . "\n";
            }
            echo "\n";
        }
    }
    
    // Clean up test data
    $conn->query("DELETE FROM sentences WHERE ID = $testSentenceId");
}

echo "\nTest completed!\n";
$conn->close();