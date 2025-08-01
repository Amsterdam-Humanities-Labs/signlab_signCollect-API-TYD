<?php
require '../../mysql_config.php';
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8mb4");

// Find sentences with glosses that exist in form_data or nmm_data
$sql = "SELECT s.ID, s.glosses 
        FROM sentences s 
        WHERE s.glosses IS NOT NULL 
        AND s.glosses != '[]' 
        AND s.glosses != ''
        LIMIT 20";
$result = $conn->query($sql);

echo "Checking sentences with glosses:\n\n";
$foundValidSentence = false;
while ($row = $result->fetch_assoc()) {
    $glosses = json_decode($row['glosses'], true);
    if ($glosses && count($glosses) > 0) {
        $hasValidGloss = false;
        $validGlosses = [];
        
        // Check if any gloss exists
        foreach ($glosses as $gloss) {
            $escaped = $conn->real_escape_string($gloss);
            $check = $conn->query("SELECT 1 FROM form_data WHERE glos = '$escaped' AND extern = '1' AND glosZichtbaar = '0' UNION SELECT 1 FROM nmm_data WHERE glos = '$escaped' LIMIT 1");
            if ($check->num_rows > 0) {
                $hasValidGloss = true;
                $validGlosses[] = $gloss;
            }
        }
        
        if ($hasValidGloss && !$foundValidSentence) {
            echo "✓ Good test sentence found:\n";
            echo "  Sentence ID: " . $row['ID'] . "\n";
            echo "  All glosses: " . implode(', ', $glosses) . "\n";
            echo "  Valid glosses (exist in DB): " . implode(', ', $validGlosses) . "\n\n";
            $foundValidSentence = true;
        }
    }
}

if (!$foundValidSentence) {
    echo "No sentences found with valid glosses\n";
} else {
    // Test with sentence ID 549 which has "FRUIT-A"
    echo "Testing with a sentence that has 'FRUIT-A' gloss:\n";
    $result = $conn->query("SELECT ID, glosses FROM sentences WHERE glosses LIKE '%FRUIT-A%' LIMIT 1");
    if ($row = $result->fetch_assoc()) {
        echo "  Sentence ID: " . $row['ID'] . "\n";
        echo "  Glosses: " . $row['glosses'] . "\n";
    }
}

$conn->close();