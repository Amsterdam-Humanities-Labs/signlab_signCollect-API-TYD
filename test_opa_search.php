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

echo "Testing search for 'opa'\n";
echo "========================\n\n";

// Check matched_transcriptions for ID 43546
echo "Checking matched_transcriptions for form_data ID 43546:\n";
$result = $conn->query("SELECT id, m_transcription, zOg, app_ready, l_file, m_file, r_file FROM matched_transcriptions WHERE m_transcription = 43546");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . ", zOg: " . $row['zOg'] . ", app_ready: " . ($row['app_ready'] ?? 'NULL') . "\n";
        echo "Files: L=" . $row['l_file'] . ", M=" . $row['m_file'] . ", R=" . $row['r_file'] . "\n\n";
    }
} else {
    echo "No records found\n\n";
}

// Perform search
$searchQuery = 'opa';
$results = $searchService->search($searchQuery);

echo "Total glosses found: " . count($results['glosses']) . "\n\n";

$found43546 = false;
foreach ($results['glosses'] as $index => $gloss) {
    echo "Gloss #" . ($index + 1) . ":\n";
    echo "  ID: " . $gloss['id'] . "\n";
    echo "  Source: " . ($gloss['source'] ?? 'unknown') . "\n";
    echo "  Senses: " . json_encode($gloss['senses']) . "\n";
    echo "  Thema: " . $gloss['thema'] . "\n";
    
    if ($gloss['id'] == 43546) {
        $found43546 = true;
        echo "  *** FOUND ID 43546 (opa with labels type) ***\n";
    }
    echo "\n";
}

echo ($found43546 ? "✅" : "❌") . " ID 43546 " . ($found43546 ? "found" : "not found") . " in search results\n\n";

// Test the specific video endpoint
echo "Testing video endpoint for glos/43546:\n";
try {
    $formData = $formService->getFormById(43546);
    echo "Videos found:\n";
    echo "  Left: " . ($formData['videos']['videoLeft'] ?? 'null') . "\n";
    echo "  Center: " . ($formData['videos']['videoCenter'] ?? 'null') . "\n";
    echo "  Right: " . ($formData['videos']['videoRight'] ?? 'null') . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

$conn->close();
?>