<?php

$servername = "localhost";
$username = "user";
require_once __DIR__ . '/mysql_config.php';  // sets $servername, $username, $password, $database
$database = "admin_gebarenoverleg";

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Connected successfully to database\n";

// Query to find all rows with _nme in zelfopname field
$sql = "SELECT id, zelfopname FROM form_data WHERE zelfopname LIKE '%_nme%'";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $updated_count = 0;
    
    while($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $zelfopname = $row['zelfopname'];
        
        // Extract the value before _nme using regex
        // Pattern: find a number that comes after a dash and before _nme
        if (preg_match('/-(\d+)_nme/', $zelfopname, $matches)) {
            $extracted_value = $matches[1];
            
            // Update the signbank field with the extracted value
            $update_sql = "UPDATE form_data SET signbank = ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("si", $extracted_value, $id);
            
            if ($stmt->execute()) {
                echo "Updated ID $id: zelfopname = '$zelfopname' -> signbank = '$extracted_value'\n";
                $updated_count++;
            } else {
                echo "Error updating ID $id: " . $stmt->error . "\n";
            }
            
            $stmt->close();
        } else {
            echo "No match found for ID $id: zelfopname = '$zelfopname'\n";
        }
    }
    
    echo "\nTotal rows updated: $updated_count\n";
} else {
    echo "No rows found with '_nme' in zelfopname field\n";
}

$conn->close();
echo "Connection closed\n";

?>