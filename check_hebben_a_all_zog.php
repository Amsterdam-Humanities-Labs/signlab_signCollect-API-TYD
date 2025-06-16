<?php
require_once __DIR__ . '/mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

echo "Checking ALL records for m_transcription = 29485:\n";
echo "=========================================\n\n";

// Check all matched_transcriptions records for this ID
$sql = "SELECT mt.*, f.glos, f.extern, f.glosZichtbaar 
        FROM matched_transcriptions mt 
        LEFT JOIN form_data f ON mt.m_transcription = f.id 
        WHERE mt.m_transcription = 29485
        ORDER BY mt.zOg";

$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    echo "Found " . $result->num_rows . " record(s):\n\n";
    while ($row = $result->fetch_assoc()) {
        echo "Record #" . $row['id'] . ":\n";
        echo "  m_transcription: " . $row['m_transcription'] . "\n";
        echo "  zOg: " . $row['zOg'] . "\n";
        echo "  app_ready: " . $row['app_ready'] . "\n";
        echo "  glos: " . $row['glos'] . "\n";
        echo "  extern: " . $row['extern'] . "\n";
        echo "  glosZichtbaar: " . $row['glosZichtbaar'] . "\n";
        echo "  Has videos: " . (($row['l_file'] || $row['m_file'] || $row['r_file']) ? "Yes" : "No") . "\n";
        echo "\n";
    }
} else {
    echo "No records found for ID 29485\n";
}

// Check if there's any 'labels' type in the entire matched_transcriptions table
echo "\nChecking for ANY 'labels' records in matched_transcriptions:\n";
echo "=========================================\n";

$sql = "SELECT COUNT(*) as cnt FROM matched_transcriptions WHERE zOg = 'labels'";
$result = $conn->query($sql);
$count = $result->fetch_assoc()['cnt'];
echo "Total records with zOg='labels': $count\n";

if ($count > 0) {
    // Show a few examples
    echo "\nFirst 5 examples of 'labels' records:\n";
    $sql = "SELECT mt.*, f.glos 
            FROM matched_transcriptions mt 
            LEFT JOIN form_data f ON mt.m_transcription = f.id 
            WHERE mt.zOg = 'labels' 
            LIMIT 5";
    
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        echo "  ID: " . $row['id'] . ", m_transcription: " . $row['m_transcription'] . 
             ", glos: " . $row['glos'] . ", app_ready: " . $row['app_ready'] . "\n";
    }
}

// Check if the problem is that 'labels' records don't exist for form_data items
echo "\n\nAnalyzing the issue:\n";
echo "=========================================\n";
echo "The user reports that HEBBEN-A (ID 29485) exists in form_data but its app_ready is 0\n";
echo "when zOg='labels'. However, we found NO record with zOg='labels' for this ID.\n";
echo "\nThis suggests one of these scenarios:\n";
echo "1. The 'labels' record doesn't exist in matched_transcriptions for this form_data entry\n";
echo "2. The 'labels' records are created/managed differently than other zOg types\n";
echo "3. The user might be looking at a different database or the data has changed\n";

$conn->close();
?>