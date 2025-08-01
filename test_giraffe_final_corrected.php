<?php
/**
 * Test GIRAFFE with final corrected logic
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

echo "Testing GIRAFFE with Final Corrected Logic\n";
echo str_repeat('=', 50) . "\n\n";

// Step 1: Show what should be found
echo "1. Expected results based on corrected logic:\n";
echo str_repeat('-', 40) . "\n";

echo "Form data with extern='1' AND glosZichtbaar='0':\n";
$sql = "SELECT fd.id, fd.glos, 
               (SELECT mt.id FROM matched_transcriptions mt 
                WHERE mt.m_transcription = fd.id 
                AND mt.zOg IN ('glos', 'extern', 'labels') 
                AND mt.added = '1' 
                ORDER BY mt.id DESC LIMIT 1) as latest_mt_id
        FROM form_data fd 
        WHERE fd.glos IN ('GIRAFFE', 'GIRAFFE-A', 'GIRAFFE-B') 
        AND fd.extern = '1' 
        AND fd.glosZichtbaar = '0'";

$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    echo "  Form data ID: " . $row['id'] . " (" . $row['glos'] . ")\n";
    echo "    Latest MT ID: " . ($row['latest_mt_id'] ?? 'none') . "\n";
}

echo "\nNMM data:\n";
$sql = "SELECT nd.id, nd.glos,
               (SELECT mt.id FROM matched_transcriptions mt 
                WHERE mt.m_transcription = nd.id 
                AND mt.zOg = 'nmm' 
                AND mt.added = '1' 
                ORDER BY mt.id DESC LIMIT 1) as latest_mt_id
        FROM nmm_data nd 
        WHERE nd.glos = 'GIRAFFE'";

$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    echo "  NMM data ID: " . $row['id'] . " (" . $row['glos'] . ")\n";
    echo "    Latest MT ID: " . ($row['latest_mt_id'] ?? 'none') . "\n";
}

// Step 2: Test the actual method
echo "\n2. Testing getLatestMatchedTranscriptionForGloss('GIRAFFE'):\n";
echo str_repeat('-', 50) . "\n";

$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$sentenceService = new SentenceService($conn, $response, $videoService);

$reflection = new ReflectionClass($sentenceService);
$method = $reflection->getMethod('getLatestMatchedTranscriptionForGloss');
$method->setAccessible(true);

$result = $method->invoke($sentenceService, 'GIRAFFE');

if ($result) {
    echo "✅ Found latest match:\n";
    echo "    Source type: " . $result['source']['type'] . "\n";
    echo "    Source ID: " . $result['source']['id'] . "\n";
    echo "    Matched transcription ID: " . $result['transcription']['id'] . "\n";
    echo "    zOg: " . $result['transcription']['zOg'] . "\n";
    echo "    Video files:\n";
    echo "      Left: " . $result['transcription']['l_file'] . "\n";
    echo "      Center: " . $result['transcription']['m_file'] . "\n";
    echo "      Right: " . $result['transcription']['r_file'] . "\n";
    
    // Verify this is the expected result
    if ($result['transcription']['id'] == 32191) {
        echo "\n✅ CORRECT: Selected MT ID 32191 from form_data ID 40719 (GIRAFFE-A)\n";
    } else {
        echo "\n❌ UNEXPECTED: Expected MT ID 32191, got " . $result['transcription']['id'] . "\n";
    }
} else {
    echo "❌ No match found\n";
}

// Step 3: Test with different gloss variants
echo "\n3. Testing different gloss variants:\n";
echo str_repeat('-', 40) . "\n";

$testGlosses = ['GIRAFFE', 'GIRAFFE-A', 'giraffe'];
foreach ($testGlosses as $testGloss) {
    echo "\nTesting '$testGloss':\n";
    $result = $method->invoke($sentenceService, $testGloss);
    
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