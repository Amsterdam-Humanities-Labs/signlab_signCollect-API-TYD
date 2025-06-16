<?php
require_once __DIR__ . '/mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Check HEBBEN-A specifically
echo "Checking HEBBEN-A (ID 29485) in matched_transcriptions:\n";
echo "=========================================\n\n";

// First, check the matched_transcriptions record
$sql = "SELECT mt.*, f.glos, f.extern, f.glosZichtbaar 
        FROM matched_transcriptions mt 
        LEFT JOIN form_data f ON mt.m_transcription = f.id 
        WHERE mt.m_transcription = 29485 
        AND mt.zOg = 'labels'";

$result = $conn->query($sql);
if ($result && $row = $result->fetch_assoc()) {
    echo "Found record in matched_transcriptions:\n";
    echo "  ID: " . $row['id'] . "\n";
    echo "  m_transcription: " . $row['m_transcription'] . "\n";
    echo "  zOg: " . $row['zOg'] . "\n";
    echo "  app_ready: " . $row['app_ready'] . "\n";
    echo "  glos: " . $row['glos'] . "\n";
    echo "  extern: " . $row['extern'] . "\n";
    echo "  glosZichtbaar: " . $row['glosZichtbaar'] . "\n";
    echo "  l_file: " . $row['l_file'] . "\n";
    echo "  m_file: " . $row['m_file'] . "\n";
    echo "  r_file: " . $row['r_file'] . "\n";
    echo "  Has videos: " . (($row['l_file'] || $row['m_file'] || $row['r_file']) ? "Yes" : "No") . "\n";
} else {
    echo "No record found with zOg='labels' for ID 29485\n";
}

// Check if this record would be selected by the test_video_urls.php query
echo "\n\nChecking if this record meets test_video_urls.php criteria:\n";
echo "=========================================\n";

$testQuery = "SELECT DISTINCT f.id as form_id, f.glos as form_glos 
              FROM form_data f 
              INNER JOIN matched_transcriptions mt ON f.id = mt.m_transcription 
              WHERE mt.zOg IN ('glos', 'extern', 'labels') 
              AND (mt.l_file != '' OR mt.m_file != '' OR mt.r_file != '')
              AND f.extern = '1' 
              AND f.glosZichtbaar = '0'
              AND mt.app_ready = 0
              AND f.id = 29485";
              
$result = $conn->query($testQuery);
if ($result && $result->num_rows > 0) {
    echo "✓ YES - This record SHOULD be processed by test_video_urls.php\n";
    $row = $result->fetch_assoc();
    echo "  form_id: " . $row['form_id'] . "\n";
    echo "  form_glos: " . $row['form_glos'] . "\n";
} else {
    echo "✗ NO - This record does NOT meet the criteria\n";
    
    // Check each condition separately
    echo "\nChecking individual conditions:\n";
    
    // Check zOg condition
    $r = $conn->query("SELECT COUNT(*) as cnt FROM matched_transcriptions WHERE m_transcription = 29485 AND zOg = 'labels'");
    $cnt = $r->fetch_assoc()['cnt'];
    echo "  Has zOg='labels': " . ($cnt > 0 ? "YES ($cnt records)" : "NO") . "\n";
    
    // Check video files
    $r = $conn->query("SELECT l_file, m_file, r_file FROM matched_transcriptions WHERE m_transcription = 29485 AND zOg = 'labels'");
    if ($row = $r->fetch_assoc()) {
        $hasVideo = ($row['l_file'] != '' || $row['m_file'] != '' || $row['r_file'] != '');
        echo "  Has video files: " . ($hasVideo ? "YES" : "NO") . "\n";
        if (!$hasVideo) {
            echo "    l_file: '" . $row['l_file'] . "'\n";
            echo "    m_file: '" . $row['m_file'] . "'\n";
            echo "    r_file: '" . $row['r_file'] . "'\n";
        }
    }
    
    // Check form_data conditions
    $r = $conn->query("SELECT extern, glosZichtbaar FROM form_data WHERE id = 29485");
    if ($row = $r->fetch_assoc()) {
        echo "  extern = 1: " . ($row['extern'] == '1' ? "YES" : "NO") . " (actual: '" . $row['extern'] . "')\n";
        echo "  glosZichtbaar = 0: " . ($row['glosZichtbaar'] == '0' ? "YES" : "NO") . " (actual: '" . $row['glosZichtbaar'] . "')\n";
    }
    
    // Check app_ready
    $r = $conn->query("SELECT app_ready FROM matched_transcriptions WHERE m_transcription = 29485 AND zOg = 'labels'");
    if ($row = $r->fetch_assoc()) {
        echo "  app_ready = 0: " . ($row['app_ready'] == 0 ? "YES" : "NO") . " (actual: " . $row['app_ready'] . ")\n";
    }
}

// Now let's check what happens when we simulate the update
echo "\n\nChecking updateAppReady behavior:\n";
echo "=========================================\n";

// The updateAppReady function in test_video_urls.php uses this logic for 'glos' type:
// UPDATE matched_transcriptions SET app_ready = ? WHERE m_transcription = ? AND zOg IN ('glos', 'extern', 'labels')

echo "When test_video_urls.php processes a form_data record (type='glos'):\n";
echo "  It updates ALL records where m_transcription = ID AND zOg IN ('glos', 'extern', 'labels')\n";
echo "  This SHOULD include the 'labels' record\n";

// Let's check what records would be updated
$checkQuery = "SELECT id, m_transcription, zOg, app_ready 
               FROM matched_transcriptions 
               WHERE m_transcription = 29485 
               AND zOg IN ('glos', 'extern', 'labels')";

$result = $conn->query($checkQuery);
echo "\nRecords that would be updated for ID 29485:\n";
while ($row = $result->fetch_assoc()) {
    echo "  Record ID: " . $row['id'] . ", zOg: " . $row['zOg'] . ", current app_ready: " . $row['app_ready'] . "\n";
}

$conn->close();
?>