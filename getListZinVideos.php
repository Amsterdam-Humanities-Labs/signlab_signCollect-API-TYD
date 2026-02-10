<?php
/**
 * API endpoint to list all sentence videos (ID + mp4 filenames)
 *
 * GET /list/zin/videos?page=1
 *
 * Returns all sentence videos paginated at 200 per page.
 * Returns all sentences that have a linked video.
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
    $perPage = 200;
    $page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;

    // Log the request
    $logger->logRequest('getListZinVideos', "page=$page");

    // Get total count
    $countSql = "SELECT COUNT(DISTINCT s.ID) as total
                 FROM sentences s
                 INNER JOIN matched_transcriptions mt ON mt.m_transcription = s.ID AND mt.zOg = 'zin' AND mt.added = '1'";
    $countResult = $conn->query($countSql);
    $totalCount = $countResult->fetch_assoc()['total'];

    // Fetch sentence IDs with their video filenames
    $sql = "SELECT s.ID,
                   REPLACE(mt.l_file, '.wav', '.mp4') AS l_file,
                   REPLACE(mt.m_file, '.wav', '.mp4') AS m_file,
                   REPLACE(mt.r_file, '.wav', '.mp4') AS r_file
            FROM sentences s
            INNER JOIN matched_transcriptions mt ON mt.m_transcription = s.ID AND mt.zOg = 'zin' AND mt.added = '1'
            ORDER BY s.ID ASC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $perPage, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $sentences = [];
    while ($row = $result->fetch_assoc()) {
        $sentences[] = [
            'id' => (int)$row['ID'],
            'videos' => [
                'left' => $row['l_file'] ? MEDIA_BASE_URL . $row['l_file'] : null,
                'center' => $row['m_file'] ? MEDIA_BASE_URL . $row['m_file'] : null,
                'right' => $row['r_file'] ? MEDIA_BASE_URL . $row['r_file'] : null
            ]
        ];
    }
    $stmt->close();

    $totalPages = (int)ceil($totalCount / $perPage);

    $response['success'] = true;
    $response['data'] = $sentences;
    $response['meta'] = [
        'total' => (int)$totalCount,
        'count' => count($sentences),
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages
    ];

} catch (Exception $e) {
    $response['success'] = false;

    if (IS_PROD) {
        $response['errors'][] = 'An error occurred while fetching sentence videos. Please try again later.';
    } else {
        $response['errors'][] = $e->getMessage();
        $response['debug']['exception'] = $e->getMessage();
        $response['debug']['exception_trace'] = $e->getTraceAsString();
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (isset($logger)) {
        $logger->logRequest('error', '', 'error', $e->getMessage());
    }
}

// Calculate response time
$responseTime = microtime(true) - $startTime;
$response['response_time'] = $responseTime;

if (isset($conn) && $conn) {
    $response['debug']['php_version'] = PHP_VERSION;
    $response['debug']['mysql_version'] = $conn->server_info ?? 'Unknown';

    if (isset($logger)) {
        $logger->logRequest('info', '', 'success', '', $responseTime);
    }

    $conn->close();
}

if (!headers_sent()) {
    if ($response['success']) {
        http_response_code(200);
    } else {
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
