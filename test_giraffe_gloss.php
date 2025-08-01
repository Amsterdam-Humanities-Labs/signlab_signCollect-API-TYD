<?php
/**
 * Test the API with gloss "Giraffe" and show all matched_transcriptions
 */

require 'src/config/config.php';
include '../../mysql_config.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/VideoService.php';
require_once 'src/services/SentenceService.php';
require_once 'src/services/SearchService.php';
require_once 'src/services/NmmService.php';
require_once 'src/services/FormService.php';
require_once 'src/services/MocapService.php';

$response = ['debug' => []];
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

echo "Testing API with gloss 'Giraffe'\n";
echo str_repeat('=', 70) . "\n\n";

// Step 1: Check if Giraffe exists in form_data
echo "1. Checking form_data table:\n";
echo str_repeat('-', 50) . "\n";
$sql = "SELECT id, glos, senses, thema, extern, glosZichtbaar 
        FROM form_data 
        WHERE glos LIKE '%iraffe%' OR senses LIKE '%iraffe%'";
$result = $conn->query($sql);

$formDataIds = [];
while ($row = $result->fetch_assoc()) {
    echo "Form data ID: " . $row['id'] . "\n";
    echo "  Glos: " . $row['glos'] . "\n";
    echo "  Senses: " . $row['senses'] . "\n";
    echo "  Thema: " . $row['thema'] . "\n";
    echo "  Extern: " . $row['extern'] . ", GlosZichtbaar: " . $row['glosZichtbaar'] . "\n\n";
    $formDataIds[] = $row['id'];
}

// Step 2: Check if Giraffe exists in nmm_data
echo "\n2. Checking nmm_data table:\n";
echo str_repeat('-', 50) . "\n";
$sql = "SELECT id, glos, signbank_id, zelfopname, type, thema 
        FROM nmm_data 
        WHERE glos LIKE '%iraffe%'";
$result = $conn->query($sql);

$nmmDataIds = [];
while ($row = $result->fetch_assoc()) {
    echo "NMM data ID: " . $row['id'] . "\n";
    echo "  Glos: " . $row['glos'] . "\n";
    echo "  Signbank ID: " . $row['signbank_id'] . "\n";
    echo "  Type: " . $row['type'] . "\n";
    echo "  Thema: " . $row['thema'] . "\n\n";
    $nmmDataIds[] = $row['id'];
}

// Step 3: Show all matched_transcriptions rows
echo "\n3. All matched_transcriptions rows related to Giraffe:\n";
echo str_repeat('-', 50) . "\n";

// Check form_data matched_transcriptions
if (!empty($formDataIds)) {
    echo "From form_data sources:\n";
    $placeholders = str_repeat('?,', count($formDataIds) - 1) . '?';
    $sql = "SELECT mt.*, 'form_data' as source_type 
            FROM matched_transcriptions mt
            WHERE mt.m_transcription IN ($placeholders)
            ORDER BY mt.id DESC";
    
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($formDataIds));
    $stmt->bind_param($types, ...$formDataIds);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        echo "\n  Matched transcription ID: " . $row['id'] . "\n";
        echo "  m_transcription (form_data ID): " . $row['m_transcription'] . "\n";
        echo "  zOg: " . $row['zOg'] . "\n";
        echo "  added: " . $row['added'] . "\n";
        echo "  app_ready: " . ($row['app_ready'] ?? 'NULL') . "\n";
        echo "  Files: L=" . $row['l_file'] . ", M=" . $row['m_file'] . ", R=" . $row['r_file'] . "\n";
    }
    $stmt->close();
}

// Check nmm_data matched_transcriptions
if (!empty($nmmDataIds)) {
    echo "\nFrom nmm_data sources:\n";
    $placeholders = str_repeat('?,', count($nmmDataIds) - 1) . '?';
    $sql = "SELECT mt.*, 'nmm_data' as source_type 
            FROM matched_transcriptions mt
            WHERE mt.m_transcription IN ($placeholders) AND mt.zOg = 'nmm'
            ORDER BY mt.id DESC";
    
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($nmmDataIds));
    $stmt->bind_param($types, ...$nmmDataIds);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        echo "\n  Matched transcription ID: " . $row['id'] . "\n";
        echo "  m_transcription (nmm_data ID): " . $row['m_transcription'] . "\n";
        echo "  zOg: " . $row['zOg'] . "\n";
        echo "  added: " . $row['added'] . "\n";
        echo "  app_ready: " . ($row['app_ready'] ?? 'NULL') . "\n";
        echo "  Files: L=" . $row['l_file'] . ", M=" . $row['m_file'] . ", R=" . $row['r_file'] . "\n";
    }
    $stmt->close();
}

// Step 4: Test the new priority logic
echo "\n\n4. Testing new priority logic with SentenceService:\n";
echo str_repeat('-', 50) . "\n";

$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$sentenceService = new SentenceService($conn, $response, $videoService);

// Use reflection to test the private method
$reflection = new ReflectionClass($sentenceService);
$method = $reflection->getMethod('getLatestMatchedTranscriptionForGloss');
$method->setAccessible(true);

$glossesToTest = ['Giraffe', 'GIRAFFE', 'giraffe'];
foreach ($glossesToTest as $gloss) {
    echo "\nTesting with gloss variant: '$gloss'\n";
    $result = $method->invoke($sentenceService, $gloss);
    
    if ($result) {
        echo "  Found latest match:\n";
        echo "    Source type: " . $result['source']['type'] . "\n";
        echo "    Source ID: " . $result['source']['id'] . "\n";
        echo "    Matched transcription ID: " . $result['transcription']['id'] . "\n";
        echo "    zOg: " . $result['transcription']['zOg'] . "\n";
    } else {
        echo "  No match found\n";
    }
}

// Step 5: Test search API
echo "\n\n5. Testing Search API:\n";
echo str_repeat('-', 50) . "\n";

// Initialize search services
$mocapService = new MocapService($conn, $response, $logger);
$nmmService = new NmmService($conn, $response);
$formService = new FormService($conn, $response, $videoService, $nmmService);
$searchService = new SearchService($conn, $response, $videoService, $mocapService, $nmmService, $formService);

$searchResults = $searchService->search('Giraffe');

echo "Search results for 'Giraffe':\n";
if (!empty($searchResults['glosses'])) {
    echo "  Found " . count($searchResults['glosses']) . " gloss(es)\n";
    foreach ($searchResults['glosses'] as $gloss) {
        echo "\n  Gloss ID: " . $gloss['id'] . "\n";
        echo "  Source: " . ($gloss['source'] ?? 'unknown') . "\n";
        echo "  Senses: " . json_encode($gloss['senses']) . "\n";
        echo "  Has videos: " . (!empty($gloss['videos']) ? "Yes" : "No") . "\n";
    }
} else {
    echo "  No glosses found\n";
}

$conn->close();
echo "\n\nTest completed!\n";