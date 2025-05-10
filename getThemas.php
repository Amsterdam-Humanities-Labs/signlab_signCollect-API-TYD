<?php
// Load configuration and service files
require_once 'src/config/config.php';
require_once 'src/config/SecurityHeaders.php';
require_once 'src/config/ErrorReporting.php';
require_once 'src/services/ApiLogger.php';

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

    // Initialize logger
    $logger = new ApiLogger($conn);
    
    // Log the request
    $logger->logRequest('getThemas');

    // Get distinct themes from form_data table
    $themasSql = "SELECT DISTINCT theme FROM form_data WHERE theme IS NOT NULL AND theme != '' AND extern='1' ORDER BY theme";
    $themasStmt = $conn->prepare($themasSql);
    
    if (!$themasStmt) {
        $response['debug']['themes_prepare_error'] = $conn->error;
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }
    
    if (!$themasStmt->execute()) {
        $response['debug']['themes_execute_error'] = $themasStmt->error;
        throw new Exception('Execute statement failed: ' . $themasStmt->error);
    }
    
    $themasResult = $themasStmt->get_result();
    $themasStmt->close();
    
    // Process each theme
    $themes = [];
    
    while ($themaRow = $themasResult->fetch_assoc()) {
        $themes[] = $themaRow['theme'];
    }
    
    // Set the response data
    $response['data'] = $themes;
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    
    if (IS_PROD) {
        // Show user-friendly message in production
        $response['errors'][] = 'An error occurred while retrieving themes. Please try again later.';
    } else {
        // Show detailed error information in development
        $response['errors'][] = $e->getMessage();
        $response['debug']['exception'] = $e->getMessage();
        $response['debug']['exception_trace'] = $e->getTraceAsString();
    }
    
    // Log the error (always log full details regardless of environment)
    if (isset($logger)) {
        $logger->logRequest('getThemas_error', '', 'error', $e->getMessage());
    }
}

// Calculate response time
$responseTime = microtime(true) - $startTime;
$response['response_time'] = $responseTime;

// Close the connection
if (isset($conn) && $conn) {
    $conn->close();
}

// Output the response as JSON
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;