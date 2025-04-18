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
    $videoService = new VideoService($conn, $response);
    $nmmService = new NmmService($conn, $response);
    $sentenceService = new SentenceService($conn, $response, $videoService);
    $formService = new FormService($conn, $response, $videoService, $nmmService);
    $signbankService = new SignbankService($conn, $response);

    // Get ID from query parameters
    $id = isset($_GET['id']) ? $_GET['id'] : null;
    $type = isset($_GET['type']) ? $_GET['type'] : null;

    if (empty($id)) {
        throw new Exception('ID parameter is required');
    }

    // Log the request
    $logger->logRequest('getVideos', $id);

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
            throw new Exception('Invalid type parameter. Must be one of: zin, glos, nmm, sb');
    }
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['errors'][] = $e->getMessage();
    
    // Log the error
    if (isset($logger)) {
        $logger->logRequest('getVideos_error', $id ?? '', 'error', $e->getMessage());
    }
}

// Calculate response time
$responseTime = microtime(true) - $startTime;
$response['response_time'] = $responseTime;

// Log response time BEFORE closing the connection
if (isset($logger) && isset($conn) && $conn) {
    $logger->logRequest('getVideos', $id ?? '', 'success', '', $responseTime);
    $conn->close();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
