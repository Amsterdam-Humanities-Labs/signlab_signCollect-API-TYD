<?php
/**
 * API endpoint to resolve IDs between form_data and nmm_data
 * Priority: nmm_data > form_data when signbank_id exists
 */

// Load configuration and service files
require_once 'src/config/config.php';
require_once 'src/config/SecurityHeaders.php';
require_once 'src/config/ErrorReporting.php';
require_once 'src/services/ApiLogger.php';
require_once 'src/services/IdResolverService.php';

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
    $idResolver = new IdResolverService($conn, $response);
    
    // Get parameters
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    if (!$id && isset($_POST['id'])) {
        $id = intval($_POST['id']);
    }
    
    $type = isset($_GET['type']) ? $_GET['type'] : null;
    if (!$type && isset($_POST['type'])) {
        $type = $_POST['type'];
    }
    
    // Validate parameters
    if (empty($id) || $id <= 0) {
        $response['success'] = false;
        $response['errors'][] = 'Valid id parameter is required';
        http_response_code(400);
    } elseif (empty($type) || !in_array($type, ['glos', 'nmm'])) {
        $response['success'] = false;
        $response['errors'][] = 'Valid type parameter is required (glos or nmm)';
        http_response_code(400);
    } else {
        // Log the request
        $logger->logRequest('getResolvedId', "$id:$type");
        
        // Get resolved ID with metadata
        $resolvedData = $idResolver->getResolvedIdWithMetadata($id, $type);
        
        $response['success'] = true;
        $response['data'] = $resolvedData;
        
        // Add usage example in debug mode
        if (!IS_PROD) {
            $response['debug']['usage_example'] = [
                'description' => 'This endpoint resolves IDs with nmm_data priority',
                'example_request' => '/getResolvedId.php?id=40879&type=glos',
                'example_response' => [
                    'originalId' => 40879,
                    'originalType' => 'glos',
                    'resolvedId' => 12345,
                    'resolvedType' => 'nmm',
                    'signbankId' => '1234',
                    'hasVideos' => true,
                    'source' => 'nmm_data'
                ]
            ];
        }
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    
    if (IS_PROD) {
        // Show user-friendly message in production
        $response['errors'][] = 'An error occurred while resolving the ID. Please try again later.';
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
        $logger->logRequest('error', "$id:$type", 'error', $e->getMessage());
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