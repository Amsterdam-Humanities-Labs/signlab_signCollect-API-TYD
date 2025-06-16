<?php
// Test script to verify API filtering by app_ready status

// Set up environment to test API directly
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['q'] = 'test'; // Search query
$_POST['resultType'] = 'all';

// Include the API index file
ob_start();
require_once __DIR__ . '/index.php';
$output = ob_get_clean();

$response = json_decode($output, true);

echo "API Filtering Test Results\n";
echo "=========================\n\n";

if (isset($response['success']) && $response['success']) {
    echo "✅ API call successful\n\n";
    
    // Check sentences
    echo "Sentences found: " . count($response['data']['sentences'] ?? []) . "\n";
    if (!empty($response['data']['sentences'])) {
        echo "First sentence ID: " . $response['data']['sentences'][0]['id'] . "\n";
    }
    
    // Check glosses
    echo "\nGlosses found: " . count($response['data']['glosses'] ?? []) . "\n";
    if (!empty($response['data']['glosses'])) {
        echo "First gloss: " . json_encode($response['data']['glosses'][0]) . "\n";
    }
    
    // Check words
    echo "\nWords found: " . count($response['data']['words'] ?? []) . "\n";
    
    // Check synonyms
    echo "\nSynonyms found: " . count($response['data']['synonyms'] ?? []) . "\n";
    
} else {
    echo "❌ API call failed\n";
    echo "Error: " . ($response['error'] ?? 'Unknown error') . "\n";
}

// Now let's check the database directly to compare
require_once __DIR__ . '/mysql_config.php';
$conn = new mysqli($servername, $username, $password, $database);
if (!$conn->connect_error) {
    $conn->set_charset("utf8mb4");
    
    echo "\n\nDatabase Verification\n";
    echo "====================\n";
    
    // Check how many sentences have app_ready = 1
    $result = $conn->query("SELECT COUNT(DISTINCT s.ID) as count 
                           FROM sentences s 
                           INNER JOIN matched_transcriptions mt ON s.ID = mt.m_transcription 
                           WHERE mt.zOg = 'zin' AND mt.app_ready = 1");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "Total sentences with app_ready=1: " . $row['count'] . "\n";
    }
    
    // Check how many form_data have app_ready = 1
    $result = $conn->query("SELECT COUNT(DISTINCT f.id) as count 
                           FROM form_data f 
                           INNER JOIN matched_transcriptions mt ON f.id = mt.m_transcription 
                           WHERE mt.zOg IN ('glos', 'extern') AND mt.app_ready = 1 
                           AND f.extern = '1' AND f.glosZichtbaar = '0'");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "Total form_data with app_ready=1: " . $row['count'] . "\n";
    }
    
    // Check how many nmm_data have app_ready = 1
    $result = $conn->query("SELECT COUNT(DISTINCT n.id) as count 
                           FROM nmm_data n 
                           INNER JOIN matched_transcriptions mt ON n.id = mt.m_transcription 
                           WHERE mt.zOg = 'nmm' AND mt.app_ready = 1");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "Total nmm_data with app_ready=1: " . $row['count'] . "\n";
    }
    
    $conn->close();
}

echo "\n\nNote: The API should only return entries where app_ready = 1\n";
?>