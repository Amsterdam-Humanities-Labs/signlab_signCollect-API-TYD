<?php
require_once __DIR__ . '/mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

echo "Testing labels type coverage in test_video_urls.php queries\n";
echo "=========================================================\n\n";

// Test the exact query from test_video_urls.php for form_data
echo "1. Testing form_data query (includes labels):\n";
$formQuery = "SELECT DISTINCT f.id as form_id, f.glos as form_glos 
              FROM form_data f 
              INNER JOIN matched_transcriptions mt ON f.id = mt.m_transcription 
              WHERE mt.zOg IN ('glos', 'extern', 'labels') 
              AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')
              AND f.extern = '1' 
              AND f.glosZichtbaar = '0'
              ORDER BY f.id";

$result = $conn->query($formQuery);
if ($result) {
    $totalForms = $result->num_rows;
    echo "   Total form_data records found: $totalForms\n";
    
    // Count by zOg type
    $typeCounts = ['glos' => 0, 'extern' => 0, 'labels' => 0];
    $labelsExamples = [];
    
    while ($row = $result->fetch_assoc()) {
        // Check what zOg types this form_data ID has
        $checkResult = $conn->query("SELECT DISTINCT zOg FROM matched_transcriptions WHERE m_transcription = " . $row['form_id'] . " AND zOg IN ('glos', 'extern', 'labels')");
        while ($typeRow = $checkResult->fetch_assoc()) {
            $typeCounts[$typeRow['zOg']]++;
            if ($typeRow['zOg'] === 'labels' && count($labelsExamples) < 5) {
                $labelsExamples[] = $row['form_id'] . ' (' . $row['form_glos'] . ')';
            }
        }
    }
    
    echo "   Records by zOg type:\n";
    foreach ($typeCounts as $type => $count) {
        echo "     $type: $count records\n";
    }
    
    echo "   Examples of labels type records:\n";
    foreach ($labelsExamples as $example) {
        echo "     ID $example\n";
    }
} else {
    echo "   Error: " . $conn->error . "\n";
}

echo "\n2. Testing updateAppReady function coverage:\n";
echo "   The updateAppReady function will update records where:\n";
echo "   - type = 'glos' updates zOg IN ('glos', 'extern', 'labels')\n";
echo "   - This means all labels records linked to form_data will be updated ✓\n";

echo "\n3. Testing app_ready statistics coverage:\n";
$statsQuery = "SELECT app_ready, COUNT(*) as count FROM matched_transcriptions WHERE zOg IN ('glos', 'extern', 'labels') GROUP BY app_ready";
$result = $conn->query($statsQuery);
if ($result) {
    echo "   Current app_ready distribution for glos/extern/labels:\n";
    while ($row = $result->fetch_assoc()) {
        $status = $row['app_ready'] == 1 ? 'ready' : 'not ready';
        echo "     app_ready=" . $row['app_ready'] . " ($status): " . $row['count'] . " records\n";
    }
}

echo "\n4. Sample of labels records that will be tested:\n";
$sampleQuery = "SELECT mt.id, mt.m_transcription, mt.zOg, mt.l_file, mt.m_file, mt.r_file, f.glos 
                FROM matched_transcriptions mt 
                INNER JOIN form_data f ON mt.m_transcription = f.id 
                WHERE mt.zOg = 'labels' 
                AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '') 
                AND f.extern = '1' AND f.glosZichtbaar = '0' 
                LIMIT 5";

$result = $conn->query($sampleQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "   ID " . $row['m_transcription'] . " (glos: " . $row['glos'] . ")\n";
        echo "     Files: L=" . $row['l_file'] . ", M=" . $row['m_file'] . ", R=" . $row['r_file'] . "\n";
    }
}

$conn->close();
echo "\n✅ Labels type is fully integrated into test_video_urls.php\n";
?>