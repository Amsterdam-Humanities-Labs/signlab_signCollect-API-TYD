<?php
/**
 * Form submission handler for client app
 */

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

// Set response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Or set to specific domain
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'errors' => [],
    'debug' => IS_PROD ? null : [] // Only include debug info in non-production environments
];

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['errors'][] = 'Only POST method is allowed';
    http_response_code(405); // Method Not Allowed
    echo json_encode($response);
    exit;
}

try {
    // Get the raw POST data
    $raw_data = file_get_contents('php://input');
    $data = json_decode($raw_data, true);

    // Validate the incoming data
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON format: ' . json_last_error_msg());
    }

    // Check required fields
    $required_fields = ['query', 'email', 'timestamp'];
    $missing_fields = [];

    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $missing_fields[] = $field;
        }
    }

    if (!empty($missing_fields)) {
        throw new Exception('Missing required fields: ' . implode(', ', $missing_fields));
    }

    // Validate email
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    // Create a new MySQLi connection
    $conn = new mysqli($servername, $username, $password, $database);
    $conn->set_charset("utf8");

    // Check for a connection error
    if ($conn->connect_error) {
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }

    // Initialize logger
    $logger = new ApiLogger($conn);

    // Extract and sanitize data
    $query = trim($data['query']);
    $email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
    $timestamp = $data['timestamp'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Prepare SQL statement
    $stmt = $conn->prepare("INSERT INTO form_submissions (query, email, timestamp, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }

    // Bind parameters and execute
    $stmt->bind_param("sssss", $query, $email, $timestamp, $ip_address, $user_agent);

    if (!$stmt->execute()) {
        throw new Exception('Execute statement failed: ' . $stmt->error);
    }

    // Log the submission
    $logger->logRequest('form_submission', $query, 'success', "Email: $email");

    // Close statement and connection
    $stmt->close();
    $conn->close();

    // Return success response
    $response['success'] = true;
    $response['message'] = 'Form submission received successfully';
    echo json_encode($response);

} catch (Exception $e) {
    $response['success'] = false;

    if (IS_PROD) {
        // Show user-friendly message in production
        $response['message'] = 'An error occurred while processing your submission. Please try again later.';
    } else {
        // Show detailed error information in development
        $response['message'] = $e->getMessage();
        $response['debug']['exception'] = $e->getMessage();
        $response['debug']['exception_trace'] = $e->getTraceAsString();
    }

    // Log the error if logger is available
    if (isset($logger)) {
        $logger->logRequest('form_submission_error', $data['query'] ?? '', 'error', $e->getMessage());
    }

    // Set appropriate HTTP status code
    http_response_code(400);
    echo json_encode($response);
}