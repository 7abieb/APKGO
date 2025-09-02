<?php
/**
 * log_visit.php
 *
 * This script serves as the endpoint for the visitor tracking javascript.
 * It receives data via POST, processes it, and logs it to the database.
 * It handles both initial page loads and updates for time spent on page.
 */

// --- CONFIGURATION ---
header('Access-Control-Allow-Origin: *'); // Allow requests from any origin
header('Content-Type: application/json');

// Database configuration (should match your track.php)
define('DB_HOST', 'localhost');
define('DB_NAME', 'traffic');
define('DB_USER', 'traffic');
define('DB_PASS', 'traffic9393');

// --- DATABASE CONNECTION ---
$pdo = null;
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // If the DB connection fails, we can't do anything.
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// --- DATA PROCESSING ---

// Get the raw POST data
$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

// Check if data is valid
if (is_null($data) || !isset($data['session_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing data.']);
    exit;
}

// Determine the action from the JS script
$action = $data['action'] ?? 'page_load';
$session_id = $data['session_id'];

if ($action === 'page_load') {
    // --- HANDLE INITIAL PAGE LOAD ---

    $sql = "INSERT INTO visits (
                session_id, ip_address, user_agent, visited_url, referrer,
                browser, os, device_type, screen_resolution, country, city, timezone, isp, org, asn
            ) VALUES (
                :session_id, :ip_address, :user_agent, :visited_url, :referrer,
                :browser, :os, :device_type, :screen_resolution, :country, :city, :timezone, :isp, :org, :asn
            )";

    $stmt = $pdo->prepare($sql);

    // Get IP and User Agent from server variables for accuracy
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // NOTE: For accurate GeoIP data (country, city, ISP, etc.), you would typically
    // use a service like MaxMind GeoIP2 or an API. For simplicity, we'll
    // use placeholders here, as it's beyond the scope of a simple script.
    // You can integrate a service here if you have one.
    $country = 'N/A'; // Example: lookup_country($ip_address);
    $city = 'N/A';
    $timezone = 'N/A';
    $isp = 'N/A';
    $org = 'N/A';
    $asn = 'N/A';

    $params = [
        ':session_id' => $session_id,
        ':ip_address' => $ip_address,
        ':user_agent' => $user_agent,
        ':visited_url' => $data['visited_url'] ?? '',
        ':referrer' => $data['referrer'] ?? null,
        ':browser' => $data['browser'] ?? 'Unknown',
        ':os' => $data['os'] ?? 'Unknown',
        ':device_type' => $data['device_type'] ?? 'Unknown',
        ':screen_resolution' => $data['screen_resolution'] ?? '',
        ':country' => $country,
        ':city' => $city,
        ':timezone' => $timezone,
        ':isp' => $isp,
        ':org' => $org,
        ':asn' => $asn,
    ];

    try {
        $stmt->execute($params);
        echo json_encode(['status' => 'success', 'message' => 'Visit logged.']);
    } catch (PDOException $e) {
        http_response_code(500);
        // Log the actual error to your server logs, not to the client
        error_log("DB insert failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Failed to log visit.']);
    }

} elseif ($action === 'page_unload') {
    // --- HANDLE TIME ON PAGE UPDATE ---

    $time_on_page = $data['time_on_page_seconds'] ?? 0;

    // We update the most recent entry for that session ID
    $sql = "UPDATE visits SET time_on_page_seconds = :time_on_page 
            WHERE session_id = :session_id 
            ORDER BY visit_time DESC 
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute([
            ':time_on_page' => $time_on_page,
            ':session_id' => $session_id
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Time updated.']);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log("DB update failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Failed to update time.']);
    }
}
?>
