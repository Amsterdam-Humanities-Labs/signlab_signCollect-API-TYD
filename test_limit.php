<?php
// Test script to verify the limit parameter is working

// Test with default limit (should return 8 results)
echo "Test 1: Default limit (8 results)\n";
$ch = curl_init('http://localhost/index.php');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['query' => 'mo']));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if ($data && isset($data['data']['glosses'])) {
    echo "Glosses returned: " . count($data['data']['glosses']) . "\n";
    echo "Success: " . ($data['success'] ? 'true' : 'false') . "\n";
    if (isset($data['debug']['form_service_search_count'])) {
        echo "FormService search count: " . $data['debug']['form_service_search_count'] . "\n";
    }
} else {
    echo "Error or no glosses found\n";
}

echo "\n";

// Test with custom limit (should return 5 results)
echo "Test 2: Custom limit (5 results)\n";
$ch = curl_init('http://localhost/index.php');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['query' => 'mo', 'limit' => 5]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if ($data && isset($data['data']['glosses'])) {
    echo "Glosses returned: " . count($data['data']['glosses']) . "\n";
    echo "Success: " . ($data['success'] ? 'true' : 'false') . "\n";
} else {
    echo "Error or no glosses found\n";
}

echo "\n";

// Test with large limit (should return up to 20 results)
echo "Test 3: Large limit (20 results)\n";
$ch = curl_init('http://localhost/index.php');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['query' => 'mo', 'limit' => 20]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if ($data && isset($data['data']['glosses'])) {
    echo "Glosses returned: " . count($data['data']['glosses']) . "\n";
    echo "Success: " . ($data['success'] ? 'true' : 'false') . "\n";
} else {
    echo "Error or no glosses found\n";
}