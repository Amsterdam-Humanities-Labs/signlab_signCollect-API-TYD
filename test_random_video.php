<?php
/**
 * Test script for the random video API endpoint
 * Tests caching, video URLs, and data format
 */

require 'src/config/config.php';
include '../../mysql_config_test.php';
require_once 'src/services/RandomVideoService.php';
require_once 'src/services/VideoService.php';
require_once 'src/services/NmmService.php';

echo "====================================\n";
echo "Testing Random Video Endpoint\n";
echo "====================================\n";

// Test 1: Direct service test
echo "\n1. Testing RandomVideoService directly...\n";
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$randomVideoService = new RandomVideoService($conn);
$randomVideo = $randomVideoService->getRandomVideo();

if ($randomVideo) {
    echo "✓ Successfully retrieved random video\n";
    echo "  ID: " . $randomVideo['id'] . "\n";
    echo "  Glos: " . $randomVideo['glos'] . "\n";
    echo "  Theme: " . $randomVideo['thema'] . "\n";
    echo "  Has videos: " . (isset($randomVideo['videos']) ? 'Yes' : 'No') . "\n";
} else {
    echo "✗ Failed to retrieve random video\n";
}

// Test 2: API endpoint test
echo "\n2. Testing API endpoint (getRandomVideo.php)...\n";

// First call - should generate cache
$output = shell_exec('php getRandomVideo.php 2>&1');
$data = json_decode($output, true);

if ($data && $data['success']) {
    echo "✓ API response is valid JSON\n";
    echo "  Video ID: " . $data['data']['id'] . "\n";
    echo "  Cache expires: " . $data['cache_expires'] . "\n";
    
    // Check if this was from cache by looking at cache file age
    $cacheFile = __DIR__ . '/cache/random_video.json';
    $wasFromCache = file_exists($cacheFile) && (time() - filemtime($cacheFile)) > 2;
    
    if (!$wasFromCache) {
        echo "✓ First call: Cache miss (generated new cache)\n";
    } else {
        echo "✓ First call: Cache hit (served from existing cache)\n";
    }
} else {
    echo "✗ API response invalid or unsuccessful\n";
}

// Second call - should be from cache
sleep(1); // Small delay
$output2 = shell_exec('php getRandomVideo.php 2>&1');
$data2 = json_decode($output2, true);

if ($data2 && $data2['success']) {
    if ($data2['data']['id'] === $data['data']['id']) {
        echo "✓ Second call: Cache hit (same video ID returned)\n";
    } else {
        echo "✗ Second call: Different video returned (cache not working)\n";
    }
} else {
    echo "✗ Second call: API response invalid\n";
}

// Test 3: Validate video URLs
echo "\n3. Testing video URL validity...\n";
if (isset($data['data']['videos'])) {
    $videos = $data['data']['videos'];
    $angles = ['videoLeft', 'videoCenter', 'videoRight'];
    $validCount = 0;
    
    foreach ($angles as $angle) {
        if (isset($videos[$angle]) && $videos[$angle]) {
            $ch = curl_init($videos[$angle]);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 200) {
                echo "  ✓ $angle: Valid (HTTP 200)\n";
                $validCount++;
            } else {
                echo "  ✗ $angle: Invalid (HTTP $httpCode)\n";
            }
        } else {
            echo "  - $angle: No URL\n";
        }
    }
    
    if ($validCount > 0) {
        echo "✓ At least one video URL is valid\n";
    } else {
        echo "✗ No valid video URLs found\n";
    }
}

// Test 4: Data format validation
echo "\n4. Validating data format...\n";
$requiredFields = ['id', 'glos', 'senses', 'thema', 'videos'];
$missingFields = [];

foreach ($requiredFields as $field) {
    if (!isset($data['data'][$field])) {
        $missingFields[] = $field;
    }
}

if (empty($missingFields)) {
    echo "✓ All required fields present\n";
} else {
    echo "✗ Missing fields: " . implode(', ', $missingFields) . "\n";
}

// Test 5: Cache file
echo "\n5. Checking cache file...\n";
$cacheFile = __DIR__ . '/cache/random_video.json';
if (file_exists($cacheFile)) {
    echo "✓ Cache file exists\n";
    $cacheAge = time() - filemtime($cacheFile);
    echo "  Cache age: " . $cacheAge . " seconds\n";
    
    $cacheContent = json_decode(file_get_contents($cacheFile), true);
    if ($cacheContent && isset($cacheContent['data']['id'])) {
        echo "✓ Cache file contains valid data\n";
    } else {
        echo "✗ Cache file contains invalid data\n";
    }
} else {
    echo "✗ Cache file not found\n";
}

$conn->close();

echo "\n====================================\n";
echo "Test completed!\n";
echo "====================================\n";