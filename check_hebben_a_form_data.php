<?php
require_once __DIR__ . '/mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

echo "Investigating HEBBEN-A (ID 29485):\n";
echo "=========================================\n\n";

// First check if it exists in form_data
echo "1. Checking form_data table:\n";
$sql = "SELECT * FROM form_data WHERE id = 29485";
$result = $conn->query($sql);
if ($result && $row = $result->fetch_assoc()) {
    echo "   Found in form_data:\n";
    echo "   - id: " . $row['id'] . "\n";
    echo "   - glos: " . $row['glos'] . "\n";
    echo "   - extern: " . $row['extern'] . "\n";
    echo "   - glosZichtbaar: " . $row['glosZichtbaar'] . "\n";
} else {
    echo "   NOT found in form_data\n";
}

// Check if there are ANY matched_transcriptions records for this ID
echo "\n2. Checking matched_transcriptions for this ID:\n";
$sql = "SELECT * FROM matched_transcriptions WHERE m_transcription = 29485";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    echo "   Found " . $result->num_rows . " record(s)\n";
} else {
    echo "   NO records found in matched_transcriptions for ID 29485\n";
    echo "   This explains why it's not being processed!\n";
}

// Let's check a working example of a 'labels' record
echo "\n3. Checking a working example (ID 40473 with ZWART-WIT DENKEN):\n";
$sql = "SELECT mt.*, f.glos, f.extern, f.glosZichtbaar 
        FROM matched_transcriptions mt 
        LEFT JOIN form_data f ON mt.m_transcription = f.id 
        WHERE mt.m_transcription = 40473 
        AND mt.zOg = 'labels'
        LIMIT 1";

$result = $conn->query($sql);
if ($result && $row = $result->fetch_assoc()) {
    echo "   Found labels record:\n";
    echo "   - m_transcription: " . $row['m_transcription'] . "\n";
    echo "   - zOg: " . $row['zOg'] . "\n";
    echo "   - app_ready: " . $row['app_ready'] . "\n";
    echo "   - glos: " . $row['glos'] . "\n";
    echo "   - extern: " . $row['extern'] . "\n";
    echo "   - glosZichtbaar: " . $row['glosZichtbaar'] . "\n";
    echo "   - Has videos: " . (($row['l_file'] || $row['m_file'] || $row['r_file']) ? "Yes" : "No") . "\n";
    
    // Check if this one meets the criteria
    $hasVideos = ($row['l_file'] != '' || $row['m_file'] != '' || $row['r_file'] != '');
    $meetsFormCriteria = ($row['extern'] == '1' && $row['glosZichtbaar'] == '0');
    $needsUpdate = ($row['app_ready'] == 0);
    
    echo "\n   Meets test_video_urls.php criteria:\n";
    echo "   - Has videos: " . ($hasVideos ? "YES" : "NO") . "\n";
    echo "   - extern=1 & glosZichtbaar=0: " . ($meetsFormCriteria ? "YES" : "NO") . "\n";
    echo "   - app_ready=0: " . ($needsUpdate ? "YES" : "NO") . "\n";
    echo "   - Would be processed: " . ($hasVideos && $meetsFormCriteria && $needsUpdate ? "YES" : "NO") . "\n";
}

echo "\n\nCONCLUSION:\n";
echo "=========================================\n";
echo "The issue is that HEBBEN-A (ID 29485) exists in form_data but has NO corresponding\n";
echo "records in matched_transcriptions table. Without records in matched_transcriptions,\n";
echo "there's nothing for test_video_urls.php to update.\n";
echo "\nThe test_video_urls.php script only processes records that exist in BOTH:\n";
echo "- form_data table (with extern=1 and glosZichtbaar=0)\n";
echo "- matched_transcriptions table (with video files)\n";
echo "\nIf HEBBEN-A needs to be processed, it needs to have records in matched_transcriptions.\n";

$conn->close();
?>