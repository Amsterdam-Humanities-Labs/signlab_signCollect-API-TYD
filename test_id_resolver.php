<?php
/**
 * Test script for ID Resolver Service
 */

// Load configuration and service files
require_once 'src/config/config.php';
require_once 'src/config/ErrorReporting.php';
require_once 'src/services/IdResolverService.php';

// Configure error reporting
ErrorReporting::configure();

// Include the MySQL configuration file
include '../../mysql_config.php';

// Initialize response array for debug info
$response = ['debug' => []];

try {
    // Create a new MySQLi connection
    $conn = new mysqli($servername, $username, $password, $database);
    $conn->set_charset("utf8");

    // Check for a connection error
    if ($conn->connect_error) {
        die('Connection failed: ' . $conn->connect_error);
    }

    // Initialize ID Resolver Service
    $idResolver = new IdResolverService($conn, $response);
    
    echo "=== ID Resolver Service Test ===\n\n";
    
    // Test Case 1: Test with a form_data ID that has signbank_id
    echo "Test Case 1: Resolving form_data ID 4 (KNOP-OMDRAAIEN with signbank 47369)\n";
    echo "--------------------------------------------------------------------------\n";
    
    $result = $idResolver->getResolvedIdWithMetadata(4, 'glos');
    
    echo "Original ID: " . $result['originalId'] . "\n";
    echo "Original Type: " . $result['originalType'] . "\n";
    echo "Resolved ID: " . $result['resolvedId'] . "\n";
    echo "Resolved Type: " . $result['resolvedType'] . "\n";
    echo "Signbank ID: " . ($result['signbankId'] ?? 'null') . "\n";
    echo "Has Videos: " . ($result['hasVideos'] ? 'Yes' : 'No') . "\n";
    echo "Source: " . $result['source'] . "\n";
    
    if (isset($result['metadata'])) {
        echo "\nMetadata:\n";
        foreach ($result['metadata'] as $key => $value) {
            echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
    }
    
    // Also test with the original example ID
    echo "\n\nTest Case 1b: Resolving form_data ID 40879 (CAPPUCINO-A)\n";
    echo "-------------------------------------------------------\n";
    
    $result2 = $idResolver->getResolvedIdWithMetadata(40879, 'glos');
    
    echo "Original ID: " . $result2['originalId'] . "\n";
    echo "Original Type: " . $result2['originalType'] . "\n";
    echo "Resolved ID: " . $result2['resolvedId'] . "\n";
    echo "Resolved Type: " . $result2['resolvedType'] . "\n";
    echo "Signbank ID: " . ($result2['signbankId'] ?? 'null') . "\n";
    echo "Has Videos: " . ($result2['hasVideos'] ? 'Yes' : 'No') . "\n";
    echo "Source: " . $result2['source'] . "\n";
    
    // Test Case 2: Test with a few more form_data IDs to see behavior
    echo "\nTest Case 2: Testing multiple form_data IDs\n";
    echo "-------------------------------------------\n";
    
    // Get some form_data records with signbank IDs
    $sql = "SELECT id, glos, signbank FROM form_data 
            WHERE signbank IS NOT NULL AND signbank != '' 
            AND extern = '1' AND glosZichtbaar = '0' 
            LIMIT 5";
    
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "\nTesting form_data ID: " . $row['id'] . " (glos: " . $row['glos'] . ")\n";
            
            $resolved = $idResolver->resolveId($row['id'], 'glos');
            
            echo "  → Resolved to: " . $resolved['resolvedType'] . " ID " . $resolved['resolvedId'];
            
            if ($resolved['resolvedId'] != $row['id']) {
                echo " (Changed from form_data to nmm_data!)";
            }
            
            echo "\n";
        }
    }
    
    // Test Case 3: Test with nmm_data IDs
    echo "\nTest Case 3: Testing nmm_data IDs\n";
    echo "----------------------------------\n";
    
    $sql = "SELECT id, glos, signbank_id FROM nmm_data LIMIT 3";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "\nTesting nmm_data ID: " . $row['id'] . " (glos: " . $row['glos'] . ")\n";
            
            $resolved = $idResolver->resolveId($row['id'], 'nmm');
            
            echo "  → Resolved to: " . $resolved['resolvedType'] . " ID " . $resolved['resolvedId'];
            echo " (Has videos: " . ($resolved['hasVideos'] ? 'Yes' : 'No') . ")\n";
        }
    }
    
    // Debug information
    if (!empty($response['debug'])) {
        echo "\n\nDebug Information:\n";
        echo "------------------\n";
        print_r($response['debug']);
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";