<?php
/**
 * Test GIRAFFE with corrected logic
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

echo "Testing GIRAFFE with Corrected Logic\n";
echo str_repeat('=', 50) . "\n\n";

// Step 1: Show what form_data entries exist for GIRAFFE
echo "1. Form data entries for GIRAFFE with extern='1' AND glosZichtbaar='0':\n";
$sql = "SELECT id, glos, extern, glosZichtbaar FROM form_data WHERE glos LIKE '%IRAFFE%' AND extern = '1' AND glosZichtbaar = '0'";
$result = $conn->query($sql);

$validFormDataIds = [];
while ($row = $result->fetch_assoc()) {
    echo "  ID: " . $row['id'] . ", Glos: " . $row['glos'] . "\n";
    if ($row['glos'] === 'GIRAFFE') {
        $validFormDataIds[] = $row['id'];
    }
}

echo "\n2. NMM data entries for GIRAFFE:\n";
$sql = "SELECT id, glos FROM nmm_data WHERE glos = 'GIRAFFE'";
$result = $conn->query($sql);

$validNmmDataIds = [];
while ($row = $result->fetch_assoc()) {
    echo "  ID: " . $row['id'] . ", Glos: " . $row['glos'] . "\n";
    $validNmmDataIds[] = $row['id'];
}

// Step 2: Check matched_transcriptions for both sources
echo "\n3. Latest matched_transcriptions for valid sources:\n";

if (!empty($validFormDataIds)) {
    echo "From form_data (looking for zOg IN ('glos', 'extern', 'labels')):\n";
    foreach ($validFormDataIds as $formId) {
        $sql = "SELECT id, zOg, added FROM matched_transcriptions 
                WHERE m_transcription = $formId 
                AND zOg IN ('glos', 'extern', 'labels') 
                AND added = '1' 
                ORDER BY id DESC LIMIT 1";
        $result = $conn->query($sql);
        if ($row = $result->fetch_assoc()) {
            echo "  Form ID $formId -> MT ID: " . $row['id'] . ", zOg: " . $row['zOg'] . "\n";
        }
    }
}

if (!empty($validNmmDataIds)) {
    echo "From nmm_data (looking for zOg = 'nmm'):\n";
    foreach ($validNmmDataIds as $nmmId) {
        $sql = "SELECT id, zOg, added FROM matched_transcriptions 
                WHERE m_transcription = $nmmId 
                AND zOg = 'nmm' 
                AND added = '1' 
                ORDER BY id DESC LIMIT 1";
        $result = $conn->query($sql);
        if ($row = $result->fetch_assoc()) {
            echo "  NMM ID $nmmId -> MT ID: " . $row['id'] . ", zOg: " . $row['zOg'] . "\n";
        } else {
            echo "  NMM ID $nmmId -> No valid matched_transcriptions with zOg='nmm' and added='1'\n";
        }
    }
}

// Step 3: Test the method
echo "\n4. Testing getLatestMatchedTranscriptionForGloss('GIRAFFE'):\n";

$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$sentenceService = new SentenceService($conn, $response, $videoService);

$reflection = new ReflectionClass($sentenceService);
$method = $reflection->getMethod('getLatestMatchedTranscriptionForGloss');
$method->setAccessible(true);

$result = $method->invoke($sentenceService, 'GIRAFFE');

if ($result) {
    echo "  Found latest match:\n";
    echo "    Source type: " . $result['source']['type'] . "\n";
    echo "    Source ID: " . $result['source']['id'] . "\n";
    echo "    Matched transcription ID: " . $result['transcription']['id'] . "\n";
    echo "    zOg: " . $result['transcription']['zOg'] . "\n";
    echo "    Video files:\n";
    echo "      Left: " . $result['transcription']['l_file'] . "\n";
    echo "      Center: " . $result['transcription']['m_file'] . "\n";
    echo "      Right: " . $result['transcription']['r_file'] . "\n";
} else {
    echo "  No match found\n";
}

$conn->close();
echo "\nTest completed!\n";