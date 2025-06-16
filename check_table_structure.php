<?php
require_once __DIR__ . '/mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Check form_data table structure
$result = $conn->query("DESCRIBE form_data");
echo "form_data columns:\n";
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "  - " . $row["Field"] . " (" . $row["Type"] . ")\n";
        if (strpos(strtolower($row["Field"]), "them") !== false) {
            echo "    >>> FOUND THEME-RELATED COLUMN <<<\n";
        }
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

$conn->close();
?>