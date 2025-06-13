<?php
require 'src/config/config.php';
include '../../mysql_config_test.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/VideoService.php';
require_once 'src/services/MocapService.php';
require_once 'src/services/NmmService.php';
require_once 'src/services/FormService.php';
require_once 'src/services/SearchService.php';

$response = ['debug' => []];
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Testing Priority System...\n";

// Find overlapping glos values
$sql = "SELECT f.glos 
        FROM form_data f 
        INNER JOIN nmm_data n ON f.glos = n.glos 
        WHERE f.extern = '1' AND f.glosZichtbaar = '0' 
        AND f.glos IS NOT NULL AND f.glos != ''
        AND n.glos IS NOT NULL AND n.glos != ''
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "No overlapping glos values found for priority test\n";
    exit;
}

$row = $result->fetch_assoc();
$overlappingGlos = $row['glos'];
$stmt->close();

echo "Found overlapping glos: '$overlappingGlos'\n";

// Initialize services
$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$mocapService = new MocapService($conn, $response, $logger);
$nmmService = new NmmService($conn, $response);
$formService = new FormService($conn, $response, $videoService, $nmmService);
$searchService = new SearchService($conn, $response, $videoService, $mocapService, $nmmService, $formService);

// Reset debug
$response['debug'] = [];

// Perform search
echo "\nSearching for '$overlappingGlos'...\n";
$searchResults = $searchService->search($overlappingGlos);

// Analyze results
$formServiceCount = 0;
$nmmCount = 0;
$foundFormServiceGlos = false;
$foundNmmGlos = false;

if (isset($searchResults['glosses'])) {
    foreach ($searchResults['glosses'] as $gloss) {
        if (isset($gloss['source'])) {
            if ($gloss['source'] === 'form_service') {
                $formServiceCount++;
                // Check if this is our overlapping glos
                if (isset($gloss['senses']) && is_array($gloss['senses'])) {
                    foreach ($gloss['senses'] as $sense) {
                        if (strcasecmp($sense, $overlappingGlos) === 0 || 
                            strcasecmp($sense, ucfirst(strtolower($overlappingGlos))) === 0) {
                            $foundFormServiceGlos = true;
                            break;
                        }
                    }
                }
            } elseif ($gloss['source'] === 'nmm_data') {
                $nmmCount++;
                // Check if this is our overlapping glos
                if (isset($gloss['senses']) && is_array($gloss['senses'])) {
                    foreach ($gloss['senses'] as $sense) {
                        if (strcasecmp($sense, $overlappingGlos) === 0 || 
                            strcasecmp($sense, ucfirst(strtolower($overlappingGlos))) === 0) {
                            $foundNmmGlos = true;
                            break;
                        }
                    }
                }
            }
        }
    }
}

echo "\nResults:\n";
echo "- FormService results: $formServiceCount\n";
echo "- NMM results: $nmmCount\n";
echo "- Found FormService entry for '$overlappingGlos': " . ($foundFormServiceGlos ? "YES" : "NO") . "\n";
echo "- Found NMM entry for '$overlappingGlos': " . ($foundNmmGlos ? "YES" : "NO") . "\n";

// Check priority system worked
if ($foundFormServiceGlos && !$foundNmmGlos) {
    echo "\n✅ PRIORITY SYSTEM WORKING: FormService took priority over NMM for '$overlappingGlos'\n";
} elseif ($foundFormServiceGlos && $foundNmmGlos) {
    echo "\n❌ PRIORITY SYSTEM FAILED: Both FormService and NMM results found for '$overlappingGlos'\n";
} elseif (!$foundFormServiceGlos && $foundNmmGlos) {
    echo "\n⚠️  Only NMM result found for '$overlappingGlos' - FormService may not have matched\n";
} else {
    echo "\n⚠️  No specific results found for '$overlappingGlos' - may be processed differently\n";
}

// Debug information
echo "\nDebug Information:\n";
if (isset($response['debug']['form_service_search_count'])) {
    echo "- FormService search count: " . $response['debug']['form_service_search_count'] . "\n";
}
if (isset($response['debug']['skipped_nmm_for_formservice_priority'])) {
    $skipped = $response['debug']['skipped_nmm_for_formservice_priority'];
    echo "- NMM entries skipped for priority: " . count($skipped) . "\n";
    foreach ($skipped as $skip) {
        echo "  * Skipped NMM ID " . $skip['nmm_id'] . " for glos '" . $skip['glos_value'] . "'\n";
    }
}
if (isset($response['debug']['added_formservice_glos'])) {
    echo "- FormService glos added: " . implode(', ', $response['debug']['added_formservice_glos']) . "\n";
}
if (isset($response['debug']['added_nmm_glos'])) {
    echo "- NMM glos added: " . implode(', ', $response['debug']['added_nmm_glos']) . "\n";
}

echo "\nTest completed!\n";
$conn->close();