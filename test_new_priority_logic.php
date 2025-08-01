<?php
/**
 * Test the new priority logic based on latest matched_transcriptions
 * This replaces the old FormService > NMM priority with timestamp-based priority
 */

require 'src/config/config.php';
include '../../mysql_config.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/VideoService.php';
require_once 'src/services/SentenceService.php';
require_once 'src/services/NmmService.php';

$response = ['debug' => []];
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Testing New Priority Logic Based on Latest matched_transcriptions...\n\n";

// Find glosses that have both form_data and nmm_data entries
$sql = "SELECT f.glos 
        FROM form_data f 
        INNER JOIN nmm_data n ON f.glos = n.glos 
        WHERE f.extern = '1' AND f.glosZichtbaar = '0' 
        AND f.glos IS NOT NULL AND f.glos != ''
        AND n.glos IS NOT NULL AND n.glos != ''
        LIMIT 5";

$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "No overlapping glos values found for testing\n";
    exit;
}

$overlappingGlosses = [];
while ($row = $result->fetch_assoc()) {
    $overlappingGlosses[] = $row['glos'];
}
$stmt->close();

echo "Found " . count($overlappingGlosses) . " overlapping glosses: " . implode(', ', $overlappingGlosses) . "\n\n";

// Initialize services
$logger = new ApiLogger($conn);
$videoService = new VideoService($conn, $response, $logger);
$sentenceService = new SentenceService($conn, $response, $videoService);

// Test the new logic for each overlapping gloss
foreach ($overlappingGlosses as $gloss) {
    echo "Testing gloss: '$gloss'\n";
    echo str_repeat('-', 50) . "\n";
    
    // Get form_data IDs
    $formDataIds = [];
    $sql = "SELECT id FROM form_data 
            WHERE glos = ? 
            AND extern = '1' 
            AND glosZichtbaar = '0'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $gloss);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $formDataIds[] = $row['id'];
    }
    $stmt->close();
    echo "  Form data IDs: " . implode(', ', $formDataIds) . "\n";
    
    // Get nmm_data IDs
    $nmmDataIds = [];
    $sql = "SELECT id FROM nmm_data WHERE glos = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $gloss);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $nmmDataIds[] = $row['id'];
    }
    $stmt->close();
    echo "  NMM data IDs: " . implode(', ', $nmmDataIds) . "\n";
    
    // Get latest matched_transcription for form_data
    $latestFormData = null;
    if (!empty($formDataIds)) {
        $placeholders = str_repeat('?,', count($formDataIds) - 1) . '?';
        $sql = "SELECT mt.id, mt.m_transcription, mt.added, mt.zOg 
                FROM matched_transcriptions mt
                WHERE mt.m_transcription IN ($placeholders)
                AND mt.zOg IN ('glos', 'extern', 'labels')
                AND mt.added = '1'
                ORDER BY mt.id DESC
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $types = str_repeat('i', count($formDataIds));
        $stmt->bind_param($types, ...$formDataIds);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $latestFormData = $row;
        }
        $stmt->close();
    }
    
    // Get latest matched_transcription for nmm_data
    $latestNmmData = null;
    if (!empty($nmmDataIds)) {
        $placeholders = str_repeat('?,', count($nmmDataIds) - 1) . '?';
        $sql = "SELECT mt.id, mt.m_transcription, mt.added, mt.zOg 
                FROM matched_transcriptions mt
                WHERE mt.m_transcription IN ($placeholders)
                AND mt.zOg = 'nmm'
                AND mt.added = '1'
                ORDER BY mt.id DESC
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $types = str_repeat('i', count($nmmDataIds));
        $stmt->bind_param($types, ...$nmmDataIds);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $latestNmmData = $row;
        }
        $stmt->close();
    }
    
    // Display results
    if ($latestFormData) {
        echo "  Latest form_data matched_transcription:\n";
        echo "    - ID: " . $latestFormData['id'] . "\n";
        echo "    - m_transcription: " . $latestFormData['m_transcription'] . "\n";
        echo "    - zOg: " . $latestFormData['zOg'] . "\n";
    }
    
    if ($latestNmmData) {
        echo "  Latest nmm_data matched_transcription:\n";
        echo "    - ID: " . $latestNmmData['id'] . "\n";
        echo "    - m_transcription: " . $latestNmmData['m_transcription'] . "\n";
        echo "    - zOg: " . $latestNmmData['zOg'] . "\n";
    }
    
    // Determine which should be used
    echo "\n  RESULT: ";
    if ($latestFormData && $latestNmmData) {
        if ($latestFormData['id'] > $latestNmmData['id']) {
            echo "form_data should be used (matched_transcriptions ID " . $latestFormData['id'] . " > " . $latestNmmData['id'] . ")\n";
        } else {
            echo "nmm_data should be used (matched_transcriptions ID " . $latestNmmData['id'] . " > " . $latestFormData['id'] . ")\n";
        }
    } elseif ($latestFormData) {
        echo "Only form_data has matched_transcriptions\n";
    } elseif ($latestNmmData) {
        echo "Only nmm_data has matched_transcriptions\n";
    } else {
        echo "No matched_transcriptions found for this gloss\n";
    }
    
    echo "\n";
}

// Test with a sentence that has glosses
echo "\nTesting with getZinnenVideos endpoint:\n";
echo str_repeat('=', 50) . "\n";

// Find a sentence with glosses
$sql = "SELECT ID, glosses FROM sentences 
        WHERE glosses IS NOT NULL 
        AND glosses != '[]' 
        AND glosses != ''
        LIMIT 1";
$result = $conn->query($sql);

if ($row = $result->fetch_assoc()) {
    $sentenceId = $row['ID'];
    $glosses = json_decode($row['glosses'], true);
    
    echo "Testing sentence ID: $sentenceId\n";
    echo "Glosses: " . implode(', ', $glosses) . "\n\n";
    
    // Use the getVideoDataForSentence method
    $videoData = $sentenceService->getVideoDataForSentence($sentenceId);
    
    if (isset($videoData['glossVideosData'])) {
        foreach ($videoData['glossVideosData'] as $glossData) {
            echo "Gloss: " . $glossData['gloss'] . "\n";
            echo "  Source: " . ($glossData['dataSource'] ?? 'none') . "\n";
            if ($glossData['formDataId']) {
                echo "  Form data ID: " . $glossData['formDataId'] . "\n";
            }
            if ($glossData['nmmId']) {
                echo "  NMM data ID: " . $glossData['nmmId'] . "\n";
            }
            if (isset($response['debug']['gloss_latest_match'][$glossData['gloss']])) {
                $matchInfo = $response['debug']['gloss_latest_match'][$glossData['gloss']];
                echo "  Matched transcription ID: " . $matchInfo['matched_transcription_id'] . "\n";
            }
            echo "\n";
        }
    }
}

echo "\nTest completed!\n";
$conn->close();