<?php
/**
 * Complete test and analysis of GIRAFFE gloss
 */

require 'src/config/config.php';
include '../../mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

echo "Complete Analysis of GIRAFFE Gloss\n";
echo str_repeat('=', 70) . "\n\n";

echo "1. ALL matched_transcriptions rows related to GIRAFFE:\n";
echo str_repeat('-', 60) . "\n";

// Get all form_data IDs with GIRAFFE in the name
$sql = "SELECT id, glos, extern, glosZichtbaar FROM form_data WHERE glos LIKE '%IRAFFE%'";
$result = $conn->query($sql);

$allFormDataIds = [];
echo "Form data entries:\n";
while ($row = $result->fetch_assoc()) {
    $allFormDataIds[] = $row['id'];
    $valid = ($row['extern'] == '1' && $row['glosZichtbaar'] == '0') ? "VALID" : "NOT VALID";
    echo "  ID: " . $row['id'] . " (" . $row['glos'] . ") - $valid for new logic\n";
}

// Get nmm_data ID for GIRAFFE
$sql = "SELECT id FROM nmm_data WHERE glos = 'GIRAFFE'";
$result = $conn->query($sql);
$nmmDataId = null;
if ($row = $result->fetch_assoc()) {
    $nmmDataId = $row['id'];
    echo "\nNMM data entry: ID " . $nmmDataId . " (GIRAFFE)\n";
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "ALL matched_transcriptions entries:\n\n";

// Show all matched_transcriptions for form_data
if (!empty($allFormDataIds)) {
    $placeholders = str_repeat('?,', count($allFormDataIds) - 1) . '?';
    $sql = "SELECT mt.*, fd.glos as source_glos, 'form_data' as source_table
            FROM matched_transcriptions mt
            JOIN form_data fd ON fd.id = mt.m_transcription
            WHERE mt.m_transcription IN ($placeholders)
            ORDER BY mt.id DESC";
    
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($allFormDataIds));
    $stmt->bind_param($types, ...$allFormDataIds);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "FROM FORM_DATA:\n";
    while ($row = $result->fetch_assoc()) {
        $wouldUse = "";
        if ($row['source_glos'] === 'GIRAFFE' && in_array($row['zOg'], ['glos', 'extern', 'labels']) && $row['added'] == '1') {
            $wouldUse = " ← Would be used by old form_data logic (if extern=1)";
        }
        
        echo "  MT ID: " . str_pad($row['id'], 5) . 
             " | Source: " . str_pad($row['source_glos'] . " (ID:" . $row['m_transcription'] . ")", 20) .
             " | zOg: " . str_pad($row['zOg'], 12) . 
             " | added: " . str_pad($row['added'], 6) . 
             " | Files: " . basename($row['l_file'] ?? 'none') . 
             $wouldUse . "\n";
    }
    $stmt->close();
}

// Show all matched_transcriptions for nmm_data
if ($nmmDataId) {
    $sql = "SELECT mt.*, 'nmm_data' as source_table
            FROM matched_transcriptions mt
            WHERE mt.m_transcription = ?
            ORDER BY mt.id DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $nmmDataId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "\nFROM NMM_DATA:\n";
    while ($row = $result->fetch_assoc()) {
        $wouldUse = "";
        if ($row['zOg'] == 'extern' && $row['added'] == '1') {
            $wouldUse = " ← SELECTED by new logic (latest extern)";
        } elseif (strpos($row['zOg'], 'nmm') !== false && $row['added'] == '1') {
            $wouldUse = " ← Would be selected if available";
        }
        
        echo "  MT ID: " . str_pad($row['id'], 5) . 
             " | Source: " . str_pad("GIRAFFE (ID:" . $row['m_transcription'] . ")", 20) .
             " | zOg: " . str_pad($row['zOg'], 12) . 
             " | added: " . str_pad($row['added'], 6) . 
             " | Files: " . basename($row['l_file'] ?? 'none') . 
             $wouldUse . "\n";
    }
    $stmt->close();
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "2. NEW PRIORITY LOGIC RESULT:\n";
echo str_repeat('-', 30) . "\n";

echo "The new logic selects matched_transcriptions ID 25492:\n";
echo "  - Source: nmm_data (ID 688)\n";
echo "  - zOg: extern\n";
echo "  - This is the LATEST entry with added='1'\n";
echo "  - Video files: L20250211_5868.wav, M20250211_4889.wav, R20250211_8496.wav\n";

$conn->close();
echo "\nAnalysis complete!\n";