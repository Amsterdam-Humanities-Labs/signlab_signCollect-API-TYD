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

// Cache settings
$cacheFile = __DIR__ . '/cache/themas.json'; // Define cache file path
$cacheTime = 24 * 60 * 60; // 24 hours in seconds

// Check if cache directory exists, if not create it
if (!file_exists(__DIR__ . '/cache')) {
    mkdir(__DIR__ . '/cache', 0755, true);
}

// Try to load from cache
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    // Serve from cache
    header('Content-Type: application/json; charset=utf-8');
    // Add a header to indicate the response is from cache
    header('X-Cache-Hit: 1');
    if (!headers_sent()) {
        http_response_code(200);
    }
    readfile($cacheFile);
    exit;
}

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

    // Prepare the statement for checking video existence
    // This query checks if a theme has any associated forms that are considered video candidates
    $videoCheckSql = "SELECT 1 FROM form_data f " .
                     "INNER JOIN matched_transcriptions m ON f.id = m.m_transcription " .
                     "WHERE f.theme = ? AND f.extern = '1' AND f.glosZichtbaar = '0' " .
                     "AND (m.zOg LIKE 'glos' OR m.zOg LIKE 'extern' OR m.zOg LIKE '%nmm%') " .
                     "LIMIT 1";
    $videoCheckStmt = $conn->prepare($videoCheckSql);
    
    if (!$videoCheckStmt) {
        if (!IS_PROD && isset($response['debug'])) {
            $response['debug']['video_check_prepare_error'] = $conn->error;
        }
        throw new Exception('Prepare statement for video check failed: ' . $conn->error);
    }
    
    // Process each theme
    $themes = [];
    
    while ($themaRow = $themasResult->fetch_assoc()) {
        $themeValueFromDb = $themaRow['theme'];

        $videoCheckStmt->bind_param("s", $themeValueFromDb);
        if (!$videoCheckStmt->execute()) {
            if (!IS_PROD && isset($response['debug'])) {
                $response['debug']['video_check_execute_error_theme_' . $themeValueFromDb] = $videoCheckStmt->error;
            }
            // Log this error or decide if script should halt or continue
            // For now, we'll continue to allow other themes to be processed
            continue; 
        }
        
        $videoCheckResult = $videoCheckStmt->get_result();
        
        // Only add theme if it has associated video candidates
        if ($videoCheckResult->num_rows > 0) {
            $normalizedThemeValue = strtolower($themeValueFromDb);
            $normalizedThemeValue = ucfirst($normalizedThemeValue);
            $themes[] = $normalizedThemeValue;
        }
        // $videoCheckResult->free(); // Optional: free result if memory becomes an issue with many themes
    }
    $videoCheckStmt->close(); // Close the prepared statement after the loop
    
    // Set the response data, ensuring uniqueness after normalization and re-index the array
    $response['data'] = array_values(array_unique($themes));
    $response['success'] = true;

    // Save to cache before outputting
    if ($response['success']) {
        file_put_contents($cacheFile, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
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
    if (!headers_sent()) {
        http_response_code(500);
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

if (!headers_sent()) {
    if ($response['success']) {
        http_response_code(200);
    } else {
        // If success is false, a specific error code (500 from catch) should have been set.
        // If for some reason it's still the default (200 or not set), set to 500.
        $php_response_code_val = http_response_code();
        if ($php_response_code_val === 200 || $php_response_code_val === false) {
            http_response_code(500);
        }
    }
}

// Output the response as JSON
// Add a header to indicate the response is not from cache (it's fresh)
header('X-Cache-Hit: 0');
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;