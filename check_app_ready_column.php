<?php
require_once __DIR__ . '/mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

echo "Checking matched_transcriptions table structure...\n\n";

// Check if app_ready column exists
$result = $conn->query("DESCRIBE matched_transcriptions");
$hasAppReady = false;

if ($result) {
    echo "Current columns in matched_transcriptions:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - " . $row["Field"] . " (" . $row["Type"] . ")\n";
        if ($row["Field"] === "app_ready") {
            $hasAppReady = true;
            echo "    >>> app_ready column EXISTS <<<\n";
        }
    }
} else {
    echo "Error describing table: " . $conn->error . "\n";
    exit(1);
}

echo "\n";

if (!$hasAppReady) {
    echo "app_ready column NOT FOUND. Adding column...\n";
    
    // Add app_ready column with default value of 0
    $alterQuery = "ALTER TABLE matched_transcriptions ADD COLUMN app_ready TINYINT(1) DEFAULT 0";
    
    if ($conn->query($alterQuery) === TRUE) {
        echo "✅ Successfully added app_ready column\n";
    } else {
        echo "❌ Error adding column: " . $conn->error . "\n";
        echo "\nYou can manually add the column with this SQL:\n";
        echo $alterQuery . ";\n";
    }
} else {
    echo "✅ app_ready column already exists\n";
}

// Show some statistics
echo "\nChecking current app_ready values...\n";
$statsQuery = "SELECT app_ready, COUNT(*) as count FROM matched_transcriptions GROUP BY app_ready";
$statsResult = $conn->query($statsQuery);

if ($statsResult) {
    echo "Current distribution of app_ready values:\n";
    while ($row = $statsResult->fetch_assoc()) {
        $status = $row['app_ready'] == 1 ? 'ready' : 'not ready';
        echo "  app_ready=" . $row['app_ready'] . " ($status): " . $row['count'] . " records\n";
    }
} else {
    echo "Error getting statistics: " . $conn->error . "\n";
}

$conn->close();
?>