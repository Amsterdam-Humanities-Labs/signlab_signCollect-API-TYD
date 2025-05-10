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

    // Initialize services
    $logger = new ApiLogger($conn);
    
    // Get theme from query parameters
    $theme = isset($_GET['theme']) ? $_GET['theme'] : null;

    if (empty($theme)) {
        $response['success'] = false;
        $response['errors'][] = 'Theme parameter is required';
        
        // Set HTTP status code to 400 Bad Request
        http_response_code(400);
        
        // Log the error
        if (isset($logger)) {
            $logger->logRequest('getThemeVideos_validation_error', '', 'error', 'Theme parameter is required');
        }
        
        // Calculate response time
        $responseTime = microtime(true) - $startTime;
        $response['response_time'] = $responseTime;
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Log the request
    $logger->logRequest('getThemeVideos', $theme);

    // Get form IDs for this theme
    $formsSql = "SELECT f.id, f.senses FROM form_data f 
                 INNER JOIN matched_transcriptions m ON f.id = m.m_transcription 
                 WHERE f.theme = ? AND f.extern = '1' AND f.glosZichtbaar = '0'
                 AND (m.zOg LIKE 'glos' OR m.zOg LIKE 'extern' OR m.zOg LIKE '%nmm%')
                 GROUP BY f.id";
    $formsStmt = $conn->prepare($formsSql);
    
    if (!$formsStmt) {
        $response['debug']['forms_prepare_error'] = $conn->error;
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }
    
    $formsStmt->bind_param("s", $theme);
    
    if (!$formsStmt->execute()) {
        $response['debug']['forms_execute_error'] = $formsStmt->error;
        throw new Exception('Execute statement failed: ' . $formsStmt->error);
    }
    
    $formsResult = $formsStmt->get_result();
    $formsStmt->close();
    
    $forms = [];
    
    // Process each form
    while ($formRow = $formsResult->fetch_assoc()) {
        $formId = $formRow['id'];
        
        // Format senses
        $sensesArray = [];
        if (!empty($formRow['senses'])) {
            $decodedSenses = json_decode($formRow['senses'], true);
            if (is_array($decodedSenses)) {
                $sensesArray = $decodedSenses;
            } elseif (is_string($decodedSenses)) {
                $sensesArray = [$decodedSenses];
            }
        }
        
        // Add to forms array with form ID and senses
        $forms[] = [
            'id' => $formId,
            'senses' => $sensesArray,
            'type' => 'glos' // Add type so frontend knows which parameter to use with getVideos.php
        ];
    }
    
    // Set the response data
    $response['data'] = [
        'theme' => $theme,
        'count' => count($forms),
        'forms' => $forms
    ];
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    
    if (IS_PROD) {
        // Show user-friendly message in production
        $response['errors'][] = 'An error occurred while retrieving forms for this theme. Please try again later.';
    } else {
        // Show detailed error information in development
        $response['errors'][] = $e->getMessage();
        $response['debug']['exception'] = $e->getMessage();
        $response['debug']['exception_trace'] = $e->getTraceAsString();
    }
    
    // Log the error (always log full details regardless of environment)
    if (isset($logger)) {
        $logger->logRequest('getThemeVideos_error', $theme ?? '', 'error', $e->getMessage());
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