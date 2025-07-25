<?php
/**
 * API endpoint to fetch all unique themas from sentences table
 */

// Load configuration and service files
require_once 'src/config/config.php';
require_once 'src/config/SecurityHeaders.php';
require_once 'src/config/ErrorReporting.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/SentenceService.php';

// Set security headers
SecurityHeaders::setHeaders();

// Configure error reporting
ErrorReporting::configure();

// Include the MySQL configuration file
include '../../mysql_config.php';

// Start timer
$startTime = microtime(true);

// Initialize response array
$response = [
    'success' => false,
    'data' => [],
    'errors' => [],
    'debug' => IS_PROD ? null : []
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
    $sentenceService = new SentenceService($conn, $response, null); // No video service needed for this endpoint
    
    // Log the request
    $logger->logRequest('getZinnenThemas');
    
    // Get all unique themas
    $themas = $sentenceService->getAllThemas();
    
    $response['success'] = true;
    $response['data']['themas'] = $themas;
    
} catch (Exception $e) {
    $response['success'] = false;
    
    if (IS_PROD) {
        // Show user-friendly message in production
        $response['errors'][] = 'An error occurred while fetching themas. Please try again later.';
    } else {
        // Show detailed error information in development
        $response['errors'][] = $e->getMessage();
        $response['debug']['exception'] = $e->getMessage();
        $response['debug']['exception_trace'] = $e->getTraceAsString();
    }
    
    if (!headers_sent()) {
        http_response_code(500);
    }
    
    // Log the error
    if (isset($logger)) {
        $logger->logRequest('error', '', 'error', $e->getMessage());
    }
}

// Calculate response time
$responseTime = microtime(true) - $startTime;
$response['response_time'] = $responseTime;

// Capture MySQL version before closing the connection
if (isset($conn) && $conn) {
    $response['debug']['php_version'] = PHP_VERSION;
    $response['debug']['mysql_version'] = $conn->server_info ?? 'Unknown';
    
    // Log response time and close the connection
    if (isset($logger)) {
        $logger->logRequest('info', '', 'success', '', $responseTime);
    }
    
    $conn->close();
}

if (!headers_sent()) {
    if ($response['success']) {
        http_response_code(200);
    } else {
        // If success is false, a specific error code should have been set
        $php_response_code_val = http_response_code();
        if ($php_response_code_val === 200 || $php_response_code_val === false) {
            http_response_code(500);
        }
    }
}

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;