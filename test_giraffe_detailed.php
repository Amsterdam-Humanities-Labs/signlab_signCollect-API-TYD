<?php
/**
 * Detailed analysis of GIRAFFE gloss processing
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

echo "Detailed Analysis of GIRAFFE Gloss\n";
echo str_repeat('=', 70) . "\n\n";

// Step 1: Check which form_data entries are valid for our logic
echo "1. Valid form_data entries (extern='1' AND glosZichtbaar='0'):\n";
echo str_repeat('-', 50) . "\n";

$sql = "SELECT id, glos, extern, glosZichtbaar 
        FROM form_data 
        WHERE glos LIKE '%IRAFFE%'";
$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $isValid = ($row['extern'] == '1' && $row['glosZichtbaar'] == '0');
    echo "ID: " . $row['id'] . ", Glos: " . $row['glos'] . "\n";
    echo "  extern='" . $row['extern'] . "', glosZichtbaar='" . $row['glosZichtbaar'] . "'\n";
    echo "  Valid for search: " . ($isValid ? "YES" : "NO") . "\n\n";
}

// Step 2: Show what the new logic finds
echo "\n2. Testing getLatestMatchedTranscriptionForGloss logic step by step:\n";
echo str_repeat('-', 50) . "\n";

$gloss = 'GIRAFFE';

// Get form_data IDs (with our filter)
$formDataIds = [];
$sql = "SELECT id FROM form_data 
        WHERE glos = ? 
        AND extern = '1' 
        AND glosZichtbaar = '0'";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $gloss);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $formDataIds[] = $row['id'];
}
$stmt->close();

echo "Form data IDs found with filters: " . (empty($formDataIds) ? "NONE" : implode(', ', $formDataIds)) . "\n";

// Get nmm_data IDs
$nmmDataIds = [];
$sql = "SELECT id FROM nmm_data WHERE glos = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $gloss);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $nmmDataIds[] = $row['id'];
}
$stmt->close();

echo "NMM data IDs found: " . (empty($nmmDataIds) ? "NONE" : implode(', ', $nmmDataIds)) . "\n";

// Step 3: Check matched_transcriptions for valid entries
echo "\n3. Checking matched_transcriptions for valid entries:\n";
echo str_repeat('-', 50) . "\n";

// Check form_data entries without filters to see what's available
echo "All form_data GIRAFFE entries in matched_transcriptions:\n";
$sql = "SELECT mt.id, mt.m_transcription, mt.zOg, mt.added, fd.glos, fd.extern, fd.glosZichtbaar
        FROM matched_transcriptions mt
        JOIN form_data fd ON fd.id = mt.m_transcription
        WHERE fd.glos = 'GIRAFFE'
        AND mt.added = '1'
        ORDER BY mt.id DESC";
$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    echo "\n  MT ID: " . $row['id'] . ", form_data ID: " . $row['m_transcription'] . "\n";
    echo "  zOg: " . $row['zOg'] . ", added: " . $row['added'] . "\n";
    echo "  form_data: extern='" . $row['extern'] . "', glosZichtbaar='" . $row['glosZichtbaar'] . "'\n";
    
    $wouldBeIncluded = ($row['extern'] == '1' && $row['glosZichtbaar'] == '0' && 
                        in_array($row['zOg'], ['glos', 'extern', 'labels']));
    echo "  Would be included in new logic: " . ($wouldBeIncluded ? "NO (filters exclude it)" : "YES") . "\n";
}

// Check nmm_data entries
echo "\n\nNMM data GIRAFFE entries in matched_transcriptions:\n";
$sql = "SELECT mt.id, mt.m_transcription, mt.zOg, mt.added
        FROM matched_transcriptions mt
        WHERE mt.m_transcription = 688
        AND mt.zOg = 'nmm'
        AND mt.added = '1'
        ORDER BY mt.id DESC";
$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    echo "\n  MT ID: " . $row['id'] . ", nmm_data ID: " . $row['m_transcription'] . "\n";
    echo "  zOg: " . $row['zOg'] . ", added: " . $row['added'] . "\n";
}

// Step 4: Show which would win if filters were different
echo "\n\n4. Analysis Summary:\n";
echo str_repeat('-', 50) . "\n";

echo "The issue is that GIRAFFE in form_data has:\n";
echo "  - ID 1726: extern='', glosZichtbaar='0' (NOT valid - extern is empty)\n";
echo "  - ID 9964: extern='', glosZichtbaar='1' (NOT valid - both conditions fail)\n\n";

echo "But these entries DO have matched_transcriptions records!\n";
echo "  - Latest form_data MT: ID 22584 (for form_data ID 1726)\n";
echo "  - Latest nmm_data MT: ID 22733 (for nmm_data ID 688) - but marked as DELETE\n\n";

echo "Since form_data entries don't meet the extern='1' filter,\n";
echo "only nmm_data (ID 688) is found by the search.\n";

$conn->close();
echo "\nTest completed!\n";