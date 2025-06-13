<?php
require 'src/config/config.php';
include '../../mysql_config_test.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/VideoService.php';
require_once 'src/services/NmmService.php';
require_once 'src/services/FormService.php';
require_once 'src/services/SearchService.php';
require_once 'src/services/MocapService.php';

$response = ['debug' => []];
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Testing FormService integration...\n";

// Initialize services
$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$mocapService = new MocapService($conn, $response, $logger);
$nmmService = new NmmService($conn, $response);
$formService = new FormService($conn, $response, $videoService, $nmmService);
$searchService = new SearchService($conn, $response, $videoService, $mocapService, $nmmService, $formService);

// Test FormService search
echo "\n1. Testing FormService searchFormsByGlos...\n";
$formResults = $formService->searchFormsByGlos('AAP');
echo "Found " . count($formResults) . " form results\n";
if (!empty($formResults)) {
    echo "First result: " . json_encode($formResults[0], JSON_PRETTY_PRINT) . "\n";
}

// Test SearchService integration
echo "\n2. Testing SearchService with FormService integration...\n";
$searchResults = $searchService->search('AAP');
echo "Search structure keys: " . implode(', ', array_keys($searchResults)) . "\n";

if (isset($searchResults['glosses'])) {
    echo "Found " . count($searchResults['glosses']) . " glosses\n";
    
    $formServiceCount = 0;
    $nmmCount = 0;
    foreach ($searchResults['glosses'] as $gloss) {
        if (isset($gloss['source'])) {
            if ($gloss['source'] === 'form_service') {
                $formServiceCount++;
            } elseif ($gloss['source'] === 'nmm_data') {
                $nmmCount++;
            }
        }
    }
    
    echo "FormService results: $formServiceCount\n";
    echo "NMM results: $nmmCount\n";
}

// Check debug info
echo "\n3. Debug information:\n";
if (isset($response['debug']['form_service_search_count'])) {
    echo "FormService search count: " . $response['debug']['form_service_search_count'] . "\n";
}
if (isset($response['debug']['skipped_nmm_for_formservice_priority'])) {
    echo "NMM entries skipped for priority: " . count($response['debug']['skipped_nmm_for_formservice_priority']) . "\n";
}

echo "\nTest completed!\n";
$conn->close();