<?php
/**
 * API endpoint to fetch glosses by label (e.g., TYDapp)
 *
 * GET /list/{label}/glos?limit=100&start=0
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
include '../../mysql_config.php';

// Start timer
$startTime = microtime(true);

// Initialize response array
$response = [
    'success' => false,
    'data' => [],
    'meta' => [],
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

    // Initialize logger
    $logger = new ApiLogger($conn);

    // Get parameters
    $label = $_GET['label'] ?? $_POST['label'] ?? 'TYDapp';
    $limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 100;
    $start = isset($_REQUEST['start']) ? intval($_REQUEST['start']) : 0;

    // Validate limit (max 1000)
    $limit = max(1, min(1000, $limit));

    // Validate start (offset)
    $start = max(0, $start);

    // Sanitize label for LIKE query
    $labelPattern = '%' . $conn->real_escape_string($label) . '%';

    // Log the request
    $logger->logRequest('getListGlos', $label);

    // First, get total count for pagination metadata
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM form_data WHERE labels LIKE ? AND extern='1' AND glosZichtbaar='0'");
    $countStmt->bind_param("s", $labelPattern);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch glosses with pagination
    $stmt = $conn->prepare("
        SELECT id, glos, senses, signbank, thema
        FROM form_data
        WHERE labels LIKE ? AND extern='1' AND glosZichtbaar='0'
        ORDER BY glos ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("sii", $labelPattern, $limit, $start);
    $stmt->execute();
    $result = $stmt->get_result();

    $glosses = [];
    while ($row = $result->fetch_assoc()) {
        // Parse senses if it's a JSON string
        $senses = $row['senses'];
        if (is_string($senses) && !empty($senses)) {
            $decoded = json_decode($senses, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $senses = $decoded;
            }
        }

        $glosses[] = [
            'id' => (int)$row['id'],
            'glos' => $row['glos'],
            'senses' => $senses,
            'signbank' => $row['signbank'],
            'thema' => $row['thema'],
            'type' => 'glos'
        ];
    }
    $stmt->close();

    $response['success'] = true;
    $response['data'] = $glosses;
    $response['meta'] = [
        'total' => (int)$totalCount,
        'limit' => $limit,
        'start' => $start,
        'label' => $label
    ];

} catch (Exception $e) {
    $response['success'] = false;

    if (IS_PROD) {
        // Show user-friendly message in production
        $response['errors'][] = 'An error occurred while fetching glosses. Please try again later.';
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
        $logger->logRequest('error', $label ?? '', 'error', $e->getMessage());
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
