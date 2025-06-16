<?php
require_once __DIR__ . '/mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

echo "Testing different queries on form_data table:\n\n";

// Test 1: Simple SELECT without thema
echo "Test 1: SELECT without thema column\n";
$sql1 = "SELECT id, glos FROM form_data LIMIT 1";
$result1 = $conn->query($sql1);
if ($result1) {
    echo "✅ Success\n";
    $row = $result1->fetch_assoc();
    print_r($row);
} else {
    echo "❌ Error: " . $conn->error . "\n";
}

echo "\nTest 2: SELECT with thema column\n";
$sql2 = "SELECT id, glos, thema FROM form_data LIMIT 1";
$result2 = $conn->query($sql2);
if ($result2) {
    echo "✅ Success\n";
    $row = $result2->fetch_assoc();
    print_r($row);
} else {
    echo "❌ Error: " . $conn->error . "\n";
}

echo "\nTest 3: Full query from FormService\n";
$sql3 = "SELECT id, CAST(IF(senses = '', '[]', senses) AS JSON) AS senses, signbank, 
               IFNULL(thema, 'Unknown') as thema, glos 
        FROM form_data 
        WHERE glos LIKE 'test%' AND extern = '1' AND glosZichtbaar = '0'
        LIMIT 1";
$result3 = $conn->query($sql3);
if ($result3) {
    echo "✅ Success\n";
    $row = $result3->fetch_assoc();
    print_r($row);
} else {
    echo "❌ Error: " . $conn->error . "\n";
}

$conn->close();
?>