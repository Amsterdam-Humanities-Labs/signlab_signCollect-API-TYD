<?php
require_once __DIR__ . '/src/config/config.php';
require_once __DIR__ . '/src/config/ErrorReporting.php';
require_once __DIR__ . '/src/config/SecurityHeaders.php';
require_once __DIR__ . '/mysql_config.php';

// Include all service classes
require_once __DIR__ . '/src/services/SearchService.php';
require_once __DIR__ . '/src/services/VideoService.php';
require_once __DIR__ . '/src/services/SentenceService.php';
require_once __DIR__ . '/src/services/SignbankService.php';
require_once __DIR__ . '/src/services/FormService.php';
require_once __DIR__ . '/src/services/MocapService.php';
require_once __DIR__ . '/src/services/NmmService.php';
require_once __DIR__ . '/src/services/SuggestionService.php';
require_once __DIR__ . '/src/services/ApiLogger.php';

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Initialize response array
$response = [
    'success' => false,
    'data' => [],
    'errors' => [],
    'debug' => []
];

// Initialize services
$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$sentenceService = new SentenceService($conn, $response, $videoService);
$signbankService = new SignbankService($conn, $response);
$nmmService = new NmmService($conn, $response);
$formService = new FormService($conn, $response, $videoService, $nmmService);
$mocapService = new MocapService($conn, $response);
$searchService = new SearchService($conn, $response, $videoService, $mocapService, $nmmService, $formService);

// Perform search
$searchQuery = 'mama';
$results = $searchService->search($searchQuery);

echo "Testing search for 'mama'\n";
echo "=========================\n\n";

echo "Total glosses found: " . count($results['glosses']) . "\n\n";

foreach ($results['glosses'] as $index => $gloss) {
    echo "Gloss #" . ($index + 1) . ":\n";
    echo "  ID: " . $gloss['id'] . "\n";
    echo "  Source: " . ($gloss['source'] ?? 'unknown') . "\n";
    echo "  Senses: " . json_encode($gloss['senses']) . "\n";
    echo "  Thema: " . $gloss['thema'] . "\n";
    echo "\n";
}

// Check debug info for skipped entries
if (isset($response['debug']['skipped_form_for_formservice_id_priority'])) {
    echo "Skipped form_data entries due to FormService priority:\n";
    foreach ($response['debug']['skipped_form_for_formservice_id_priority'] as $skipped) {
        echo "  - ID: " . $skipped['form_id'] . " (" . $skipped['reason'] . ")\n";
    }
    echo "\n";
}

$conn->close();
?>