<?php
require_once __DIR__ . '/mysql_config.php';

// Configuration
const API_BASE_URL = 'https://api.signcollect.nl';
const TIMEOUT_SECONDS = 10;

echo "🔍 Testing keyword: 'doen'\n";
echo "========================\n\n";

// First, let's check what exists in the database
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

echo "📊 Database Analysis for 'doen':\n";
echo "--------------------------------\n";

// Check form_data
echo "1. Checking form_data for 'doen':\n";
$formQuery = "SELECT id, glos, extern, glosZichtbaar FROM form_data WHERE glos LIKE 'doen%' ORDER BY glos";
$result = $conn->query($formQuery);
if ($result) {
    echo "   Found " . $result->num_rows . " records in form_data:\n";
    while ($row = $result->fetch_assoc()) {
        echo "     ID: {$row['id']}, glos: '{$row['glos']}', extern: {$row['extern']}, glosZichtbaar: {$row['glosZichtbaar']}\n";
        
        // Check matched_transcriptions for this ID
        $mtQuery = "SELECT zOg, app_ready, l_file, m_file, r_file FROM matched_transcriptions WHERE m_transcription = {$row['id']}";
        $mtResult = $conn->query($mtQuery);
        if ($mtResult && $mtResult->num_rows > 0) {
            while ($mtRow = $mtResult->fetch_assoc()) {
                $appReady = isset($mtRow['app_ready']) ? $mtRow['app_ready'] : 'NULL';
                echo "       → zOg: {$mtRow['zOg']}, app_ready: $appReady, files: L={$mtRow['l_file']}, M={$mtRow['m_file']}, R={$mtRow['r_file']}\n";
            }
        } else {
            echo "       → No matched_transcriptions found\n";
        }
    }
} else {
    echo "   Error: " . $conn->error . "\n";
}

echo "\n2. Checking nmm_data for 'doen':\n";
$nmmQuery = "SELECT id, glos FROM nmm_data WHERE glos LIKE 'doen%' ORDER BY glos";
$result = $conn->query($nmmQuery);
if ($result) {
    echo "   Found " . $result->num_rows . " records in nmm_data:\n";
    while ($row = $result->fetch_assoc()) {
        echo "     ID: {$row['id']}, glos: '{$row['glos']}'\n";
        
        // Check matched_transcriptions for this ID
        $mtQuery = "SELECT zOg, app_ready, l_file, m_file, r_file FROM matched_transcriptions WHERE m_transcription = {$row['id']} AND zOg = 'nmm'";
        $mtResult = $conn->query($mtQuery);
        if ($mtResult && $mtResult->num_rows > 0) {
            while ($mtRow = $mtResult->fetch_assoc()) {
                $appReady = isset($mtRow['app_ready']) ? $mtRow['app_ready'] : 'NULL';
                echo "       → zOg: {$mtRow['zOg']}, app_ready: $appReady, files: L={$mtRow['l_file']}, M={$mtRow['m_file']}, R={$mtRow['r_file']}\n";
            }
        } else {
            echo "       → No matched_transcriptions found\n";
        }
    }
} else {
    echo "   Error: " . $conn->error . "\n";
}

echo "\n3. Checking sentences containing 'doen':\n";
$sentenceQuery = "SELECT ID, zinString FROM sentences WHERE zinString LIKE '%doen%' LIMIT 5";
$result = $conn->query($sentenceQuery);
if ($result) {
    echo "   Found " . $result->num_rows . " sentences containing 'doen':\n";
    while ($row = $result->fetch_assoc()) {
        echo "     ID: {$row['ID']}, sentence: '{$row['zinString']}'\n";
        
        // Check matched_transcriptions for this sentence
        $mtQuery = "SELECT zOg, app_ready, l_file, m_file, r_file FROM matched_transcriptions WHERE m_transcription = {$row['ID']} AND zOg = 'zin'";
        $mtResult = $conn->query($mtQuery);
        if ($mtResult && $mtResult->num_rows > 0) {
            while ($mtRow = $mtResult->fetch_assoc()) {
                $appReady = isset($mtRow['app_ready']) ? $mtRow['app_ready'] : 'NULL';
                echo "       → zOg: {$mtRow['zOg']}, app_ready: $appReady, files: L={$mtRow['l_file']}, M={$mtRow['m_file']}, R={$mtRow['r_file']}\n";
            }
        } else {
            echo "       → No matched_transcriptions found\n";
        }
    }
} else {
    echo "   Error: " . $conn->error . "\n";
}

$conn->close();

echo "\n🌐 API Testing:\n";
echo "---------------\n";

// Function to make API request
function makeApiRequest($method, $endpoint, $data = null) {
    $url = API_BASE_URL . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT_SECONDS);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'DoesKeywordTest/1.0');
    
    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
    }
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($result === false || $httpCode !== 200) {
        return false;
    }
    
    return $result;
}

// Test the API search
echo "1. Testing API search for 'doen':\n";
$startTime = microtime(true);
$searchResult = makeApiRequest('POST', '/index.php', [
    'query' => 'doen',
    'resultType' => 'all'
]);
$responseTime = microtime(true) - $startTime;

if ($searchResult === false) {
    echo "   ❌ Search failed (network/curl error)\n";
} else {
    $searchData = json_decode($searchResult, true);
    
    if (!$searchData || !isset($searchData['success'])) {
        echo "   ❌ Invalid JSON response\n";
        echo "   Raw response: " . substr($searchResult, 0, 500) . "...\n";
    } else if (!$searchData['success']) {
        echo "   ❌ API error: " . implode(', ', $searchData['errors'] ?? ['Unknown error']) . "\n";
    } else {
        $sentences = $searchData['data']['sentences'] ?? [];
        $glosses = $searchData['data']['glosses'] ?? [];
        $words = $searchData['data']['words'] ?? [];
        $synonyms = $searchData['data']['synonyms'] ?? [];
        
        echo "   ✅ Search successful in " . round($responseTime, 3) . "s\n";
        echo "   Results:\n";
        echo "     - Words: " . count($words) . "\n";
        echo "     - Sentences: " . count($sentences) . "\n";
        echo "     - Glosses: " . count($glosses) . "\n";
        echo "     - Synonyms: " . count($synonyms) . "\n\n";
        
        if (!empty($words)) {
            echo "   📝 Words found:\n";
            foreach ($words as $word) {
                echo "     • ID: {$word['id']}, word: '{$word['word']}', lemma: '{$word['lemma']}'\n";
            }
            echo "\n";
        }
        
        if (!empty($sentences)) {
            echo "   📄 Sentences found:\n";
            foreach ($sentences as $sentence) {
                echo "     • ID: {$sentence['id']}, sentence: '{$sentence['zinstring']}', thema: {$sentence['thema']}\n";
            }
            echo "\n";
        }
        
        if (!empty($glosses)) {
            echo "   🤟 Glosses found:\n";
            foreach ($glosses as $gloss) {
                echo "     • ID: {$gloss['id']}, senses: " . json_encode($gloss['senses']) . ", source: {$gloss['source']}, thema: {$gloss['thema']}\n";
            }
            echo "\n";
        }
        
        if (!empty($synonyms)) {
            echo "   🔄 Synonyms found:\n";
            foreach ($synonyms as $synonym) {
                echo "     • " . json_encode($synonym) . "\n";
            }
            echo "\n";
        }
        
        // Save full response for detailed analysis
        echo "   💾 Full API response saved to: doen_api_response.json\n";
        file_put_contents('doen_api_response.json', json_encode($searchData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Test video endpoints for found results
        echo "\n2. Testing video endpoints:\n";
        
        $videoCount = 0;
        foreach ($sentences as $sentence) {
            if (isset($sentence['id'])) {
                $videoCount++;
                echo "   Testing sentence video: zin/{$sentence['id']}\n";
                $videoResult = makeApiRequest('GET', "/videos/zin/{$sentence['id']}");
                if ($videoResult) {
                    $videoData = json_decode($videoResult, true);
                    if ($videoData && $videoData['success']) {
                        $videos = $videoData['data']['videos'] ?? [];
                        $hasVideos = !empty($videos['videoLeft']) || !empty($videos['videoCenter']) || !empty($videos['videoRight']);
                        echo "     → " . ($hasVideos ? "✅ Videos available" : "❌ No videos") . "\n";
                        if ($hasVideos) {
                            echo "       Left: " . ($videos['videoLeft'] ? "✅" : "❌") . "\n";
                            echo "       Center: " . ($videos['videoCenter'] ? "✅" : "❌") . "\n";
                            echo "       Right: " . ($videos['videoRight'] ? "✅" : "❌") . "\n";
                        }
                    } else {
                        echo "     → ❌ API error\n";
                    }
                } else {
                    echo "     → ❌ Network error\n";
                }
            }
        }
        
        foreach ($glosses as $gloss) {
            if (isset($gloss['id'])) {
                $videoCount++;
                echo "   Testing gloss video: glos/{$gloss['id']}\n";
                $videoResult = makeApiRequest('GET', "/videos/glos/{$gloss['id']}");
                if ($videoResult) {
                    $videoData = json_decode($videoResult, true);
                    if ($videoData && $videoData['success']) {
                        $videos = $videoData['data']['videos'] ?? [];
                        $hasVideos = !empty($videos['videoLeft']) || !empty($videos['videoCenter']) || !empty($videos['videoRight']);
                        echo "     → " . ($hasVideos ? "✅ Videos available" : "❌ No videos") . "\n";
                        if ($hasVideos) {
                            echo "       Left: " . ($videos['videoLeft'] ? "✅" : "❌") . "\n";
                            echo "       Center: " . ($videos['videoCenter'] ? "✅" : "❌") . "\n";
                            echo "       Right: " . ($videos['videoRight'] ? "✅" : "❌") . "\n";
                        }
                    } else {
                        echo "     → ❌ API error\n";
                    }
                } else {
                    echo "     → ❌ Network error\n";
                }
            }
        }
        
        if ($videoCount === 0) {
            echo "   No videos to test (no results with IDs found)\n";
        }
    }
}

echo "\n🎉 'doen' keyword test completed!\n";
?>