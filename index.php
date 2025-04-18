<?php
// Load configuration and service files
require_once 'src/config/config.php';
require_once 'src/config/SecurityHeaders.php';
require_once 'src/config/ErrorReporting.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/SearchService.php';
require_once 'src/services/SuggestionService.php';

// Set security headers
SecurityHeaders::setHeaders();

// Configure error reporting
ErrorReporting::configure();

// Include the MySQL configuration file
include '../../mysql_config_test.php';

// Start timer
$startTime = microtime(true);

// Initialize response array
$response = [
    'success' => false,
    'data' => [],
    'errors' => [],
    'debug' => [] // Add debug information section
];

try {
    // Create a new MySQLi connection
    $conn = new mysqli($servername, $username, $password, $database);
    $conn->set_charset("utf8");

    // Check for a connection error
    if ($conn->connect_error) {
        $response['debug']['connection_error'] = $conn->connect_error;
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }

    // Initialize services
    $logger = new ApiLogger($conn);
    $searchService = new SearchService($conn, $response);
    $suggestionService = new SuggestionService($conn, $response);

    // Check if this is a suggestions request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suggestions']) && $_POST['suggestions'] === 'true') {
        $searchQuery = $_POST['query'] ?? '';
        
        // Minimum 3 characters for suggestion
        if (strlen($searchQuery) < 3) {
            $response['success'] = true;
            $response['data']['suggestions'] = [
                'words' => [],
                'lemmas' => [],
                'synonyms' => []
            ];
            echo json_encode($response);
            exit;
        }
        
        // Log the suggestion request
        $logger->logRequest('suggestions', $searchQuery);
        
        // Get suggestions
        $response['data']['suggestions'] = $suggestionService->getSuggestions($searchQuery);
        $response['success'] = true;
        
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

        // Add offset for pagination
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        
        // Add resultType filter parameter (all, sentences, forms)
        $resultType = isset($_POST['resultType']) ? strtolower($_POST['resultType']) : 'all';
        
        // Add option to group results by theme
        $groupByThema = isset($_POST['groupByThema']) && $_POST['groupByThema'] === 'true';

        if (empty($searchQuery)) {
            throw new Exception('Search query is required');
        }
        
        // Log the search request
        $logger->logRequest('search', $searchQuery);
        
        // Perform the search and get all results
        $results = $searchService->search($searchQuery, $offset);
        
        // Filter results by type if requested
        if ($resultType !== 'all') {
            $filteredResults = [];
            
            // Always include words and synonyms
            $filteredResults['words'] = $results['words'] ?? [];
            $filteredResults['synonyms'] = $results['synonyms'] ?? [];
            
            // Filter by specific result type
            switch ($resultType) {
                case 'sentences':
                    $filteredResults['sentences'] = $results['sentences'] ?? [];
                    break;
                    
                case 'glosses': // Updated type name (replacing forms and sb_records)
                    $filteredResults['glosses'] = $results['glosses'] ?? [];
                    break;
                    
                case 'forms': // For backward compatibility
                    $filteredResults['glosses'] = array_filter($results['glosses'] ?? [], function($item) {
                        return isset($item['source']) && $item['source'] === 'signcollect';
                    });
                    break;
                    
                case 'sb_records': // For backward compatibility
                    $filteredResults['glosses'] = array_filter($results['glosses'] ?? [], function($item) {
                        return isset($item['source']) && $item['source'] === 'signbank';
                    });
                    break;
                    
                default:
                    // If an invalid type is specified, return all results
                    $filteredResults = $results;
            }
            
            $response['data'] = $filteredResults;
        } else {
            // Return all results (default)
            $response['data'] = $results;
        }
        
        // Group results by thema if requested
        if ($groupByThema && !empty($response['data'])) {
            $grouped = ['words' => $response['data']['words'] ?? [], 'synonyms' => $response['data']['synonyms'] ?? []];
            
            // Group sentences by thema
            if (!empty($response['data']['sentences'])) {
                $grouped['sentences_by_thema'] = [];
                foreach ($response['data']['sentences'] as $sentence) {
                    $thema = $sentence['thema'] ?? 'Unknown';
                    if (!isset($grouped['sentences_by_thema'][$thema])) {
                        $grouped['sentences_by_thema'][$thema] = [];
                    }
                    $grouped['sentences_by_thema'][$thema][] = $sentence;
                }
            }
            
            // Group glosses by thema
            if (!empty($response['data']['glosses'])) {
                $grouped['glosses_by_thema'] = [];
                foreach ($response['data']['glosses'] as $gloss) {
                    $thema = $gloss['thema'] ?? 'Unknown';
                    if (!isset($grouped['glosses_by_thema'][$thema])) {
                        $grouped['glosses_by_thema'][$thema] = [];
                    }
                    $grouped['glosses_by_thema'][$thema][] = $gloss;
                }
            }
            
            $response['data'] = $grouped;
        }
        
        $response['success'] = true;
    } else {
        // Provide API info for GET requests
        $response['success'] = true;
        $response['message'] = 'API is running. Use POST method with "query" parameter to search, or call getVideos.php with ID to fetch video data. '
                             . 'Optional parameters: resultType (all, sentences, forms, sb_records) to filter results, '
                             . 'groupByThema=true to group results by theme.';
        $logger->logRequest('info');
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['errors'][] = $e->getMessage();
    $response['debug']['exception'] = $e->getMessage();
    $response['debug']['exception_trace'] = $e->getTraceAsString();
    
    // Log the error
    if (isset($logger)) {
        $logger->logRequest('error', $searchQuery ?? '', 'error', $e->getMessage());
    }
}

// Calculate response time
$responseTime = microtime(true) - $startTime;
$response['response_time'] = $responseTime;

// Log response time BEFORE closing the connection
if (isset($logger) && isset($conn) && $conn) {
    $logger->logRequest('info', '', 'success', '', $responseTime);
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
