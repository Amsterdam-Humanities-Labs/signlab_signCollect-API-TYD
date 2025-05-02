<?php
// Load configuration and service files
require_once 'src/config/config.php';
require_once 'src/config/SecurityHeaders.php';
require_once 'src/config/ErrorReporting.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/VideoService.php';
require_once 'src/services/SentenceService.php';
require_once 'src/services/FormService.php';
require_once 'src/services/SignbankService.php';
require_once 'src/services/NmmService.php';

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
    'debug' => IS_PROD ? null : [] // Only include debug info in non-production environments
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
    $videoService = new VideoService($conn, $response, $logger); // Pass logger to VideoService
    $nmmService = new NmmService($conn, $response);
    $sentenceService = new SentenceService($conn, $response, $videoService);
    $formService = new FormService($conn, $response, $videoService, $nmmService);
    $signbankService = new SignbankService($conn, $response);

    // Get ID from query parameters
    $id = isset($_GET['id']) ? $_GET['id'] : null;
    $type = isset($_GET['type']) ? $_GET['type'] : null;

    if (empty($id)) {
        $response['success'] = false;
        $response['errors'][] = 'ID parameter is required';
        
        // Set HTTP status code to 400 Bad Request
        http_response_code(400);
        
        // Log the error
        if (isset($logger)) {
            $logger->logRequest('getVideos_validation_error', '', 'error', 'ID parameter is required');
        }
        
        // Calculate response time
        $responseTime = microtime(true) - $startTime;
        $response['response_time'] = $responseTime;
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Log the request - No need to log info requests here anymore as VideoService will log actual queries
    if (!empty($id) && !empty($type)) {
        $logger->logRequest('getVideos', "$id:$type");
    }

    // Based on the type (zin, glos, nmm, sb), fetch different data
    switch ($type) {
        case 'zin':
            $response['data'] = $sentenceService->getSentenceById($id);
            break;
            
        case 'glos':
            $response['data'] = $formService->getFormById($id);
            break;
            
        case 'sb':
            $response['data'] = $signbankService->getSignbankById($id);
            break;
            
        case 'nmm':
            $response['data'] = $nmmService->getNmmById($id);
            break;
            
        default:
            $response['success'] = false;
            $response['errors'][] = 'Invalid type parameter. Must be one of: zin, glos, nmm, sb';
            
            // Set HTTP status code to 400 Bad Request
            http_response_code(400);
            
            // Log the error
            if (isset($logger)) {
                $logger->logRequest('getVideos_validation_error', $id, 'error', 'Invalid type parameter');
            }
            
            // Calculate response time
            $responseTime = microtime(true) - $startTime;
            $response['response_time'] = $responseTime;
            
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
    }
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    
    if (IS_PROD) {
        // Show user-friendly message in production
        $response['errors'][] = 'An error occurred while retrieving the video. Please try again later.';
    } else {
        // Show detailed error information in development
        $response['errors'][] = $e->getMessage();
        $response['debug']['exception'] = $e->getMessage();
        $response['debug']['exception_trace'] = $e->getTraceAsString();
    }
    
    // Log the error (always log full details regardless of environment)
    if (isset($logger)) {
        $logger->logRequest('getVideos_error', $id ?? '', 'error', $e->getMessage());
    }
}

// Calculate response time
$responseTime = microtime(true) - $startTime;
$response['response_time'] = $responseTime;

// Just close the connection
if (isset($conn) && $conn) {
    $conn->close();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
