<?php
// Set appropriate headers for API
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Include the MySQL configuration file
include '../../mysql_config.php';

// Start timer
$startTime = microtime(true);

// Configuration variables
$mediaBaseUrl = 'https://media.signcollect.nl/';

// Disable PHP warnings but keep errors
error_reporting(E_ERROR | E_PARSE);

// Initialize response array
$response = [
    'success' => false,
    'data' => [],
    'errors' => [],
    'debug' => [] // Add debug information section
];

// Create a function to log API activity
function logApiRequest($conn, $action, $query = '', $status = 'success', $errorMessage = '', $responseTime = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $requestData = json_encode($_REQUEST);
    
    try {
        $stmt = $conn->prepare("INSERT INTO zin_api_log (action, query, ip_address, user_agent, request_data, status, error_message, response_time) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", $action, $query, $ip, $userAgent, $requestData, $status, $errorMessage, $responseTime);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // If logging fails, write to error log as fallback
        error_log("Failed to log to zin_api_log: " . $e->getMessage());
    }
}

try {
    // Create a new MySQLi connection
    $conn = new mysqli($servername, $username, $password, $database);
    $conn->set_charset("utf8");

    // Check for a connection error
    if ($conn->connect_error) {
        $response['debug']['connection_error'] = $conn->connect_error;
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }

    // Check if this is a suggestions request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suggestions']) && $_POST['suggestions'] === 'true') {
        $searchQuery = $_POST['query'] ?? '';
        $response['debug']['received_query'] = $searchQuery;
        
        // Minimum 3 characters for suggestion
        if (strlen($searchQuery) < 3) {
            $response['success'] = true;
            $response['data']['suggestions'] = [
                'words' => [],
                'lemmas' => []
            ];
            echo json_encode($response);
            exit;
        }
        
        // Log the suggestion request
        logApiRequest($conn, 'suggestions', $searchQuery);
        
        // Find word suggestions
        $wordSuggestions = [];
        $lemmaSuggestions = [];
        
        // Get word suggestions that start with the query
        $stmt = $conn->prepare("SELECT DISTINCT word, lemma FROM hh_words 
                               WHERE word LIKE ? 
                               ORDER BY word ASC LIMIT 10");
        
        if (!$stmt) {
            $response['debug']['suggest_prepare_error'] = $conn->error;
            throw new Exception('Prepare statement failed: ' . $conn->error);
        }
        
        $searchParam = $searchQuery . '%';
        $stmt->bind_param("s", $searchParam);
        
        if (!$stmt->execute()) {
            $response['debug']['suggest_execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $wordResult = $stmt->get_result();
        $stmt->close();
        
        while ($word = $wordResult->fetch_assoc()) {
            $wordSuggestions[] = [
                'text' => $word['word'],
                'lemma' => $word['lemma']
            ];
            
            // Add lemma to lemma suggestions if not already there
            $lemmaExists = false;
            foreach ($lemmaSuggestions as $lemma) {
                if ($lemma['text'] === $word['lemma']) {
                    $lemmaExists = true;
                    break;
                }
            }
            
            if (!$lemmaExists && $word['lemma'] !== $word['word']) {
                $lemmaSuggestions[] = [
                    'text' => $word['lemma'],
                    'lemma' => $word['lemma']
                ];
            }
        }
        
        // Get additional lemma suggestions
        $stmt = $conn->prepare("SELECT DISTINCT lemma FROM hh_words 
                               WHERE lemma LIKE ? AND lemma NOT IN (SELECT word FROM hh_words WHERE word = lemma)
                               ORDER BY lemma ASC LIMIT 10");
        
        if ($stmt) {
            $stmt->bind_param("s", $searchParam);
            
            if ($stmt->execute()) {
                $lemmaResult = $stmt->get_result();
                
                while ($lemma = $lemmaResult->fetch_assoc()) {
                    // Check if lemma already exists in suggestions
                    $lemmaExists = false;
                    foreach ($lemmaSuggestions as $existingLemma) {
                        if ($existingLemma['text'] === $lemma['lemma']) {
                            $lemmaExists = true;
                            break;
                        }
                    }
                    
                    if (!$lemmaExists) {
                        $lemmaSuggestions[] = [
                            'text' => $lemma['lemma'],
                            'lemma' => $lemma['lemma']
                        ];
                    }
                }
            }
            $stmt->close();
        }
        
        // Get synonym suggestions
        $synonymSuggestions = [];
        $stmt = $conn->prepare("SELECT id, lemma, synonym FROM hh_synonyms 
                              WHERE synonym LIKE ? 
                              ORDER BY synonym ASC LIMIT 10");
        
        if ($stmt) {
            $stmt->bind_param("s", $searchParam);
            
            if ($stmt->execute()) {
                $synonymResult = $stmt->get_result();
                
                while ($synonym = $synonymResult->fetch_assoc()) {
                    $synonymSuggestions[] = [
                        'text' => $synonym['synonym'],
                        'lemma' => $synonym['lemma'],
                        'id' => $synonym['id']
                    ];
                }
            }
            $stmt->close();
        }
        
        // Limit results
        $wordSuggestions = array_slice($wordSuggestions, 0, 10);
        $lemmaSuggestions = array_slice($lemmaSuggestions, 0, 10);
        $synonymSuggestions = array_slice($synonymSuggestions, 0, 10);
        
        $response['success'] = true;
        $response['data']['suggestions'] = [
            'words' => $wordSuggestions,
            'lemmas' => $lemmaSuggestions,
            'synonyms' => $synonymSuggestions
        ];
        
        // Calculate response time
        $responseTime = microtime(true) - $startTime;
        $response['response_time'] = $responseTime;
        
        echo json_encode($response);
        exit;
    }

    // Determine if we're handling a search query
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Get search query from POST data
        $searchQuery = $_POST['query'] ?? '';
        $response['debug']['received_query'] = $searchQuery;

        // Add offset and limit for pagination (default from 0, limit 10)
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = 10;

        if (empty($searchQuery)) {
            throw new Exception('Search query is required');
        }
        
        // Log the search request
        logApiRequest($conn, 'search', $searchQuery);
        
        // Step 1: Find exact word match in hh_words
        $stmt = $conn->prepare("SELECT id, word, lemma FROM hh_words WHERE word = ? LIMIT 1");
        if (!$stmt) {
            $response['debug']['prepare_error'] = $conn->error;
            throw new Exception('Prepare statement failed: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $searchQuery);
        if (!$stmt->execute()) {
            $response['debug']['execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $wordResult = $stmt->get_result();
        $stmt->close();
        
        $wordMatches = [];
        $lemmas = [];
        
        $response['debug']['word_result_count'] = $wordResult->num_rows;
        
        if ($wordResult->num_rows > 0) {
            while ($word = $wordResult->fetch_assoc()) {
                $wordMatches[] = $word;
                $lemmas[] = $word['lemma'];
                
                // Check for senses matches
                if (!empty($word['senses'])) {
                    $response['debug']['senses_data'] = $word['senses'];
                    $senses = json_decode($word['senses'], true);
                    if (is_array($senses)) {
                        foreach ($senses as $sense) {
                            if (isset($sense['lemma']) && $sense['lemma'] === $word['lemma']) {
                                $response['data']['senses'][] = $sense;
                            }
                        }
                    } else {
                        $response['debug']['senses_json_error'] = json_last_error_msg();
                    }
                }
            }
        } else {
            // If no word matches are found, use the search query itself as a lemma
            $lemmas[] = $searchQuery;
            $response['debug']['using_query_as_lemma'] = true;
            
            // Add a synthetic word match using the search query
            $wordMatches[] = [
                'id' => null,
                'word' => $searchQuery,
                'lemma' => $searchQuery
            ];
        }
        
        $response['data']['words'] = $wordMatches;
        $response['debug']['lemmas_found'] = $lemmas;
        
        // Step 2: Find sentences with matching lemmas in lemmaList - Remove videos, only return IDs
        $sentenceMatches = [];
        
        if (!empty($lemmas)) {
            foreach ($lemmas as $lemma) {
                // Updated query with LIMIT for sentences pagination
                $sql = "SELECT ID FROM sentences WHERE JSON_CONTAINS(lemmaList, ?) OR JSON_CONTAINS(lemmaList, ?) LIMIT ?, ?";
                $response['debug']['sentences_query'] = $sql;
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    $response['debug']['sentences_prepare_error'] = $conn->error;
                    continue; // Continue with the next lemma instead of failing
                }
                
                // Prepare for both formats the lemma might be stored in JSON
                $lemmaJson = json_encode($lemma);
                $lemmaQuotedJson = json_encode("\"$lemma\"");
                $response['debug']['lemma_json'] = $lemmaJson;
                $response['debug']['lemma_quoted_json'] = $lemmaQuotedJson;
                
                // Bind two strings and two integers (offset, limit)
                $stmt->bind_param("ssii", $lemmaJson, $lemmaQuotedJson, $offset, $limit);
                
                if (!$stmt->execute()) {
                    $response['debug']['sentences_execute_error'] = $stmt->error;
                    $stmt->close();
                    continue;
                }
                
                $sentenceResult = $stmt->get_result();
                $stmt->close();
                
                $response['debug']['sentences_found_for_lemma_' . $lemma] = $sentenceResult->num_rows;
                
                while ($sentence = $sentenceResult->fetch_assoc()) {
                    // Check if we already have this sentence
                    $exists = false;
                    foreach ($sentenceMatches as $existingSentence) {
                        if ($existingSentence['ID'] == $sentence['ID']) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        // Just add the basic sentence info - no videos
                        $sentenceMatches[] = [
                            "ID" => $sentence['ID'] ?? null,
                            "zinString" => $sentence['zinString'] ?? "",
                            "type" => "zin" // Add type for frontend to know which endpoint to call
                        ];
                    }
                }
            }
        }
        
        $response['data']['sentences'] = array_slice($sentenceMatches, 0, 10);
        $response['debug']['sentence_matches_found'] = count($sentenceMatches);
        
        // Step 3: Find form_data entries with matching lemmas - Remove videos, only return IDs
        $formMatches = [];
        
        if (!empty($lemmas)) {
            foreach ($lemmas as $lemma) {
                // Updated query with LIMIT for form_data pagination
                $sql = "SELECT id, senses, signbank FROM form_data WHERE JSON_CONTAINS(CAST(IF(senses = '', '[]', senses) AS JSON), JSON_QUOTE(?)) LIMIT ?, ?";
                $response['debug']['form_data_query'] = $sql;
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    $response['debug']['form_data_prepare_error'] = $conn->error;
                    continue;
                }
                
                // Bind one string and two integers (offset, limit)
                $stmt->bind_param("sii", $lemma, $offset, $limit);
                
                if (!$stmt->execute()) {
                    $response['debug']['form_data_execute_error'] = $stmt->error;
                    $stmt->close();
                    continue;
                }
                
                $formResult = $stmt->get_result();
                $stmt->close();
                
                $response['debug']['forms_found_for_lemma_' . $lemma] = $formResult->num_rows;
                
                while ($form = $formResult->fetch_assoc()) {
                    // Check if we already have this form
                    $exists = false;
                    foreach ($formMatches as $existingForm) {
                        if ($existingForm['id'] == $form['id']) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        // Just add basic form info without videos
                        $formMatches[] = [
                            "id" => $form['id'],
                            "senses" => $form['senses'] ?? "",
                            "signbank" => $form['signbank'] ?? "",
                            "type" => "glos" // Add type for frontend to know which endpoint to call
                        ];
                    }
                }
            }
        }
        
        $response['data']['forms'] = array_slice($formMatches, 0, 10);
        $response['debug']['form_matches_found'] = count($formMatches);
        
        // Step 4: Find sb_records entries with matching lemmas in senses_dutch
        $sbRecordMatches = [];
        
        if (!empty($lemmas)) {
            foreach ($lemmas as $lemma) {
                // Search for lemma in senses_dutch field which is JSON formatted
                $sql = "SELECT id, senses_dutch FROM sb_records WHERE ";
                $searchTerms = [];
                
                // Split lemma into words for more flexible matching
                $lemmaWords = explode(' ', $lemma);
                foreach ($lemmaWords as $word) {
                    if (strlen($word) >= 3) { // Only search for words with 3+ characters
                        $searchTerms[] = "senses_dutch LIKE ?";
                    }
                }
                
                if (empty($searchTerms)) {
                    $searchTerms[] = "senses_dutch LIKE ?";
                    $params = ["%$lemma%"];
                } else {
                    $params = [];
                    foreach ($lemmaWords as $word) {
                        if (strlen($word) >= 3) {
                            $params[] = "%$word%";
                        }
                    }
                }
                
                $sql .= implode(' OR ', $searchTerms);
                $sql .= " LIMIT ?, ?";
                
                $response['debug']['sb_records_query'] = $sql;
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    $response['debug']['sb_records_prepare_error'] = $conn->error;
                    continue;
                }
                
                // Create the right number of bind parameters
                $types = str_repeat("s", count($params)) . "ii";
                $bindParams = array_merge($params, [$offset, $limit]);
                
                // Use reflection to bind parameters dynamically
                $bindMethod = new ReflectionMethod('mysqli_stmt', 'bind_param');
                $bindParams = array_merge([$types], $bindParams);
                $bindMethod->invokeArgs($stmt, $bindParams);
                
                if (!$stmt->execute()) {
                    $response['debug']['sb_records_execute_error'] = $stmt->error;
                    $stmt->close();
                    continue;
                }
                
                $sbResult = $stmt->get_result();
                $stmt->close();
                
                $response['debug']['sb_records_found_for_lemma_' . $lemma] = $sbResult->num_rows;
                
                while ($record = $sbResult->fetch_assoc()) {
                    // Check if we already have this record
                    $exists = false;
                    foreach ($sbRecordMatches as $existingRecord) {
                        if ($existingRecord['id'] == $record['id']) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        // Just add basic record info without videos
                        $sbRecordMatches[] = [
                            "id" => $record['id'],
                            "senses_dutch" => $record['senses_dutch'] ?? "",
                            "type" => "sb" // Add type for frontend to know which endpoint to call
                        ];
                    }
                }
            }
        }
        
        $response['data']['sb_records'] = array_slice($sbRecordMatches, 0, 10);
        $response['debug']['sb_record_matches_found'] = count($sbRecordMatches);
        
        // Step 5: Find synonyms matching the search query
        $synonymMatches = [];
        
        // Search directly using the search query in synonym field
        $sql = "SELECT id, lemma, synonym FROM hh_synonyms WHERE synonym LIKE ? LIMIT ?, ?";
        $searchParam = "%$searchQuery%";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sii", $searchParam, $offset, $limit);
            
            if ($stmt->execute()) {
                $synonymResult = $stmt->get_result();
                
                $response['debug']['synonyms_found'] = $synonymResult->num_rows;
                
                while ($synonym = $synonymResult->fetch_assoc()) {
                    // Just add basic synonym info
                    $synonymMatches[] = [
                        "id" => $synonym['id'],
                        "lemma" => $synonym['lemma'],
                        "synonym" => $synonym['synonym']
                    ];
                }
            } else {
                $response['debug']['synonyms_execute_error'] = $stmt->error;
            }
            $stmt->close();
        } else {
            $response['debug']['synonyms_prepare_error'] = $conn->error;
        }
        
        $response['data']['synonyms'] = array_slice($synonymMatches, 0, 10);
        $response['debug']['synonym_matches_found'] = count($synonymMatches);
        
        // No need to collect NMM data here, as it will be fetched by getVideos.php
        
        // Set success flag
        $response['success'] = true;
    } else {
        // Provide API info for GET requests
        $response['success'] = true;
        $response['message'] = 'API is running. Use POST method with "query" parameter to search, or call getVideos.php with ID to fetch video data.';
        logApiRequest($conn, 'info');
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['errors'][] = $e->getMessage();
    $response['debug']['exception'] = $e->getMessage();
    $response['debug']['exception_trace'] = $e->getTraceAsString();
    
    // Log the error
    logApiRequest($conn, 'error', $searchQuery ?? '', 'error', $e->getMessage());
}

// Calculate response time
$responseTime = microtime(true) - $startTime;
$response['response_time'] = $responseTime;

// Log response time BEFORE closing the connection
logApiRequest($conn, 'info', '', 'success', '', $responseTime);

// Close the database connection if it exists
if (isset($conn) && $conn) {
    $conn->close();
}

// Include PHP error log information if any errors occurred
if (count($response['errors']) > 0) {
    // Get the last few lines from the PHP error log
    $errorLogPath = ini_get('error_log');
    if (file_exists($errorLogPath)) {
        $errorLog = file($errorLogPath);
        $lastErrors = array_slice($errorLog, -10); // Get last 10 lines
        $response['debug']['php_error_log'] = $lastErrors;
    } else {
        $response['debug']['php_error_log_path'] = $errorLogPath;
        $response['debug']['php_error_log_status'] = 'Not found or not accessible';
    }
}

// Add PHP configuration information
$response['debug']['php_version'] = PHP_VERSION;
$response['debug']['mysql_version'] = $conn->server_info ?? 'Unknown';

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
