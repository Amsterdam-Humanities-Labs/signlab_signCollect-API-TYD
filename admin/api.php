<?php

// signcollect-lib's install-root resolver: sc_path(), sc_dir(), sc_root().
// Vendored shim - it finds /web/lib/paths.php, or falls back to /web.
require_once __DIR__ . '/../sc_paths.php';

// Load configuration
require_once '../src/config/config.php';
require_once '../src/config/SecurityHeaders.php';
require_once '../src/config/ErrorReporting.php';

// Set security headers
SecurityHeaders::setHeaders();

// Configure error reporting
ErrorReporting::configure();

// Include database configuration
include sc_path('mysql_config.php');

// Database-based authentication check
session_start();

// Check if user is authenticated
if (!isset($_SESSION['user_authenticated']) || !isset($_SESSION['user_id'])) {
    // Check for login request
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        // Handle login in main flow
        $action = 'login';
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required', 'login_required' => true]);
        exit;
    }
}

// Database connection (save DB credentials before they get overwritten)
$db_username = $username;
$db_password = $password;
$conn = new mysqli($servername, $db_username, $db_password, $database);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get action from request
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Initialize response
$response = ['success' => false, 'data' => null, 'error' => null];

try {
    switch ($action) {
        case 'login':
            $response = authenticateUser($conn);
            break;
            
        case 'logout':
            $response = logoutUser();
            break;
            
        case 'list':
            $response = getVideoList($conn);
            break;
            
        case 'update_status':
            $response = updateVideoStatus($conn);
            break;
            
        case 'bulk_update':
            $response = bulkUpdateStatus($conn);
            break;
            
        case 'themes':
            $response = getThemes($conn);
            break;
            
        case 'stats':
            $response = getStats($conn);
            break;
            
        case 'user_info':
            $response = getUserInfo($conn);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    http_response_code(500);
}

// Output JSON response
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

function authenticateUser($conn) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        return ['success' => false, 'error' => 'Username and password required'];
    }
    
    // Query users table
    $sql = "SELECT userId, user, pass FROM users WHERE user = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'error' => 'Invalid username or password'];
    }
    
    $user = $result->fetch_assoc();
    
    // Check password (currently plain text, but we'll add hashing support)
    $passwordValid = false;
    
    if (password_verify($password, $user['pass'])) {
        // New hashed password
        $passwordValid = true;
    } elseif ($user['pass'] === $password) {
        // Legacy plain text password - hash it for next time
        $passwordValid = true;
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $updateSql = "UPDATE users SET pass = ? WHERE userId = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("si", $hashedPassword, $user['userId']);
        $updateStmt->execute();
    }
    
    if (!$passwordValid) {
        return ['success' => false, 'error' => 'Invalid username or password'];
    }
    
    // Set session
    $_SESSION['user_authenticated'] = true;
    $_SESSION['user_id'] = $user['userId'];
    $_SESSION['username'] = $user['user'];
    
    return [
        'success' => true,
        'data' => [
            'username' => $user['user'],
            'userId' => $user['userId']
        ]
    ];
}

function logoutUser() {
    session_destroy();
    return [
        'success' => true,
        'data' => ['message' => 'Logged out successfully']
    ];
}

function getUserInfo($conn) {
    $userId = $_SESSION['user_id'];
    $sql = "SELECT userId, user, lang FROM users WHERE userId = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        return [
            'success' => true,
            'data' => [
                'userId' => $user['userId'],
                'username' => $user['user'],
                'language' => $user['lang']
            ]
        ];
    }
    
    return ['success' => false, 'error' => 'User not found'];
}

function getVideoList($conn) {
    // Check if tyd_app_ready column exists
    $hasColumn = checkTydAppReadyColumn($conn);
    
    if (!$hasColumn) {
        return [
            'success' => false,
            'error' => 'tyd_app_ready column not found. Please run database migration first.'
        ];
    }
    
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 50);
    $offset = ($page - 1) * $limit;
    
    // Filters
    $statusFilter = $_GET['status'] ?? 'all';
    $themeFilter = $_GET['theme'] ?? '';
    $searchFilter = $_GET['search'] ?? '';
    
    // Build WHERE clause
    $whereConditions = ["f.extern = '1'"];
    $params = [];
    $types = '';
    
    if ($statusFilter !== 'all') {
        $whereConditions[] = "f.tyd_app_ready = ?";
        $params[] = intval($statusFilter);
        $types .= 'i';
    }
    
    if (!empty($themeFilter)) {
        $whereConditions[] = "f.thema = ?";
        $params[] = $themeFilter;
        $types .= 's';
    }
    
    if (!empty($searchFilter)) {
        $whereConditions[] = "f.glos LIKE ?";
        $params[] = '%' . $searchFilter . '%';
        $types .= 's';
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Main query
    $sql = "SELECT DISTINCT f.id, f.glos, f.senses, f.signbank, 
            IFNULL(f.thema, 'Unknown') as theme, f.tyd_app_ready
            FROM form_data f
            INNER JOIN matched_transcriptions m ON f.id = m.m_transcription
            WHERE $whereClause
            AND m.zOg IN ('glos', 'extern', 'labels')
            AND (m.l_file != '' OR m.m_file != '' OR m.r_file != '')
            ORDER BY f.id DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    // Add limit and offset parameters
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $videos = [];
    while ($row = $result->fetch_assoc()) {
        // Process senses
        $senses = [];
        if (!empty($row['senses'])) {
            $decoded = json_decode($row['senses'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $senses = $decoded;
            } else {
                $senses = [$row['senses']];
            }
        }
        
        // Get video URLs
        $videoUrls = getVideoUrls($conn, $row['id']);
        
        $videos[] = [
            'id' => $row['id'],
            'glos' => $row['glos'],
            'senses' => $senses,
            'theme' => $row['theme'],
            'tyd_app_ready' => intval($row['tyd_app_ready']),
            'videos' => $videoUrls
        ];
    }
    
    // Get total count for pagination
    $countSql = "SELECT COUNT(DISTINCT f.id) as total
                 FROM form_data f
                 INNER JOIN matched_transcriptions m ON f.id = m.m_transcription
                 WHERE $whereClause
                 AND m.zOg IN ('glos', 'extern', 'labels')
                 AND (m.l_file != '' OR m.m_file != '' OR m.r_file != '')";
    
    $countStmt = $conn->prepare($countSql);
    if (!empty($params) && count($params) > 2) {
        // Remove limit and offset from params for count query
        $countParams = array_slice($params, 0, -2);
        $countTypes = substr($types, 0, -2);
        $countStmt->bind_param($countTypes, ...$countParams);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'];
    
    return [
        'success' => true,
        'data' => [
            'videos' => $videos,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => intval($totalCount),
                'total_pages' => ceil($totalCount / $limit),
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ]
    ];
}

function getVideoUrls($conn, $formId) {
    $sql = "SELECT m.l_file, m.m_file, m.r_file, m.zOg
            FROM matched_transcriptions m
            WHERE m.m_transcription = ?
            AND m.zOg IN ('extern', 'labels')
            AND (m.l_file != '' OR m.m_file != '' OR m.r_file != '')
            ORDER BY FIELD(m.zOg, 'extern', 'labels')
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $formId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $videos = [
        'videoLeft' => null,
        'videoCenter' => null,
        'videoRight' => null
    ];
    
    if ($row = $result->fetch_assoc()) {
        if (!empty($row['l_file'])) {
            $videos['videoLeft'] = MEDIA_BASE_URL . str_replace('.wav', '.mp4', $row['l_file']);
        }
        if (!empty($row['m_file'])) {
            $videos['videoCenter'] = MEDIA_BASE_URL . str_replace('.wav', '.mp4', $row['m_file']);
        }
        if (!empty($row['r_file'])) {
            $videos['videoRight'] = MEDIA_BASE_URL . str_replace('.wav', '.mp4', $row['r_file']);
        }
    }
    
    return $videos;
}

function updateVideoStatus($conn) {
    $id = intval($_POST['id'] ?? 0);
    $status = intval($_POST['status'] ?? 0);
    
    if (!$id) {
        throw new Exception('Invalid video ID');
    }
    
    $sql = "UPDATE form_data SET tyd_app_ready = ? WHERE id = ? AND extern = '1'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $status, $id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update status');
    }
    
    if ($stmt->affected_rows === 0) {
        throw new Exception('Video not found or not updated');
    }
    
    return [
        'success' => true,
        'data' => [
            'id' => $id,
            'status' => $status,
            'message' => 'Status updated successfully'
        ]
    ];
}

function bulkUpdateStatus($conn) {
    $ids = $_POST['ids'] ?? [];
    $status = intval($_POST['status'] ?? 0);
    
    if (empty($ids) || !is_array($ids)) {
        throw new Exception('Invalid video IDs');
    }
    
    // Sanitize IDs
    $cleanIds = array_map('intval', $ids);
    $cleanIds = array_filter($cleanIds);
    
    if (empty($cleanIds)) {
        throw new Exception('No valid IDs provided');
    }
    
    $placeholders = str_repeat('?,', count($cleanIds) - 1) . '?';
    $sql = "UPDATE form_data SET tyd_app_ready = ? WHERE id IN ($placeholders) AND extern = '1'";
    
    $stmt = $conn->prepare($sql);
    $params = array_merge([$status], $cleanIds);
    $types = 'i' . str_repeat('i', count($cleanIds));
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to bulk update status');
    }
    
    return [
        'success' => true,
        'data' => [
            'updated_count' => $stmt->affected_rows,
            'status' => $status,
            'message' => 'Bulk update completed successfully'
        ]
    ];
}

function getThemes($conn) {
    $sql = "SELECT DISTINCT thema as theme 
            FROM form_data 
            WHERE extern = '1' 
            AND thema IS NOT NULL 
            AND thema != '' 
            ORDER BY thema";
    
    $result = $conn->query($sql);
    $themes = [];
    
    while ($row = $result->fetch_assoc()) {
        $themes[] = $row['theme'];
    }
    
    return [
        'success' => true,
        'data' => $themes
    ];
}

function getStats($conn) {
    
    // Check if tyd_app_ready column exists
    $hasColumn = checkTydAppReadyColumn($conn);
    
    if (!$hasColumn) {
        return [
            'success' => true,
            'data' => [
                'total' => 0,
                'ready' => 0,
                'not_ready' => 0,
                'note' => 'tyd_app_ready column not found'
            ]
        ];
    }
    
    // Total counts
    $totalSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN tyd_app_ready = 1 THEN 1 ELSE 0 END) as ready,
                    SUM(CASE WHEN tyd_app_ready = 0 THEN 1 ELSE 0 END) as not_ready
                 FROM form_data f
                 INNER JOIN matched_transcriptions m ON f.id = m.m_transcription
                 WHERE f.extern = '1'
                 AND m.zOg IN ('glos', 'extern', 'labels')
                 AND (m.l_file != '' OR m.m_file != '' OR m.r_file != '')";
    
    $result = $conn->query($totalSql);
    $stats = $result->fetch_assoc();
    
    return [
        'success' => true,
        'data' => [
            'total' => intval($stats['total']),
            'ready' => intval($stats['ready']),
            'not_ready' => intval($stats['not_ready'])
        ]
    ];
}

function checkTydAppReadyColumn($conn) {
    $checkSql = "SHOW COLUMNS FROM form_data LIKE 'tyd_app_ready'";
    $result = $conn->query($checkSql);
    return $result && $result->num_rows > 0;
}

$conn->close();
?>