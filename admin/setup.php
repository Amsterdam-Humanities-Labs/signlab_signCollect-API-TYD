<?php
/**
 * Setup script for admin interface
 * Checks and creates necessary database columns
 */

// Over HTTP this runs ALTER TABLE, so only an admin may call it. The session
// is the portal's (login_sc.php): signCollect-v2's verifier, which reads the
// role from the users table. From the CLI (php setup.php) no session applies.
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../sc_paths.php';
    $sc_session_lib = sc_path('menu_beta/php_api/session.php');
    if (is_readable($sc_session_lib)) {
        // db.php first, at top level: its mysql_config globals must be global.
        require_once dirname($sc_session_lib) . '/db.php';
        require_once $sc_session_lib;
    } else {
        error_log('sCAPI admin/setup.php: ' . $sc_session_lib . ' missing - refusing');
    }
    unset($sc_session_lib);
    if (!function_exists('session_is_admin') || !session_is_admin()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Admin session required.\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// Include database configuration
include '../../mysql_config_test.php';

// Database connection
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Setting up admin interface...\n\n";

// Check if tyd_app_ready column exists
$checkColumn = "SHOW COLUMNS FROM form_data LIKE 'tyd_app_ready'";
$result = $conn->query($checkColumn);

if ($result->num_rows == 0) {
    echo "Adding tyd_app_ready column to form_data table...\n";
    
    $addColumn = "ALTER TABLE form_data ADD COLUMN tyd_app_ready INT DEFAULT 0";
    if ($conn->query($addColumn)) {
        echo "✓ tyd_app_ready column added successfully\n";
    } else {
        echo "✗ Error adding column: " . $conn->error . "\n";
        exit(1);
    }
} else {
    echo "✓ tyd_app_ready column already exists\n";
}

// Check column type
$describeTable = "DESCRIBE form_data tyd_app_ready";
$result = $conn->query($describeTable);
if ($result && $row = $result->fetch_assoc()) {
    echo "✓ Column type: " . $row['Type'] . "\n";
    echo "✓ Default value: " . ($row['Default'] ?? 'NULL') . "\n";
}

// Get some statistics
$countQuery = "SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN tyd_app_ready = 1 THEN 1 END) as ready,
    COUNT(CASE WHEN tyd_app_ready = 0 THEN 1 END) as not_ready
FROM form_data WHERE extern = '1'";

$result = $conn->query($countQuery);
if ($result && $row = $result->fetch_assoc()) {
    echo "\nCurrent statistics:\n";
    echo "- Total videos: " . $row['total'] . "\n";
    echo "- Ready for app: " . $row['ready'] . "\n";
    echo "- Not ready: " . $row['not_ready'] . "\n";
}

// Test sample data retrieval
echo "\nTesting data retrieval...\n";
$sampleQuery = "SELECT f.id, f.glos, f.tyd_app_ready, 
                CASE WHEN IS_PROD THEN f.thema ELSE f.theme END as theme
                FROM form_data f 
                WHERE f.extern = '1' 
                LIMIT 5";

$result = $conn->query($sampleQuery);
if ($result) {
    echo "✓ Sample data retrieved successfully\n";
    echo "Sample records:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  ID: {$row['id']}, Glos: {$row['glos']}, Ready: {$row['tyd_app_ready']}, Theme: {$row['theme']}\n";
    }
} else {
    echo "✗ Error retrieving sample data: " . $conn->error . "\n";
}

echo "\nSetup completed!\n";
echo "You can now access the admin interface at: https://api.signcollect.nl/admin/\n";
echo "Login credentials: admin / signcollect2024\n";

$conn->close();
?>