<?php
/**
 * track.php
 *
 * This is a revamped admin dashboard for the visitor tracking system.
 *
 * Features:
 * - Modern, responsive UI with Tailwind CSS.
 * - Light and Dark mode support with localStorage persistence.
 * - All data updates are handled via server-side rendering (no AJAX).
 * - A simple, secure login mechanism.
 * - Displays visitor logs from the 'visits' table with advanced details.
 * - Allows filtering by date range with quick filter buttons.
 * - Shows key analytics: total visits, unique visitors, and top country.
 */

// --- CONFIGURATION ---
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'traffic');
define('DB_USER', 'traffic');
define('DB_PASS', 'traffic9393');

// Admin Password (Change this for production environments)
$admin_password = 'track9393';

// --- DATABASE CONNECTION ---
$pdo = null;
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Gracefully handle database connection errors
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed. Please check your configuration and ensure the server is running.");
}

// --- AUTHENTICATION LOGIC ---
$login_error = '';

// Handle login POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (isset($_POST['password']) && $_POST['password'] === $admin_password) {
        $_SESSION['logged_in'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']); // Redirect to clear POST data
        exit;
    } else {
        $login_error = "Invalid password. Please try again.";
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// --- LOGIN PAGE ---
// If the user is not logged in, display the login form and terminate the script.
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Visitor Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex items-center justify-center min-h-screen">
        <div class="w-full max-w-md p-8 space-y-6 bg-white rounded-xl shadow-lg">
            <h1 class="text-3xl font-bold text-center text-gray-900">Tracker Login</h1>
            <p class="text-center text-gray-600">Enter your credentials to access the dashboard.</p>
            
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" class="space-y-6">
                <div>
                    <label for="password" class="text-sm font-medium text-gray-700">Password</label>
                    <div class="relative mt-1">
                        <input type="password" id="password" name="password" required
                               class="block w-full px-4 py-3 text-gray-900 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                               placeholder="••••••••">
                    </div>
                </div>
                
                <?php if (!empty($login_error)): ?>
                    <div class="p-3 text-sm text-red-700 bg-red-100 rounded-lg" role="alert">
                        <?php echo $login_error; ?>
                    </div>
                <?php endif; ?>
                
                <button type="submit" name="login"
                        class="w-full px-5 py-3 text-base font-medium text-center text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300">
                    Login
                </button>
            </form>
        </div>
    </div>
</body>
</html>
<?php
    exit; // Stop script execution for non-logged-in users
}

// --- DASHBOARD LOGIC (SERVER-SIDE DATA FETCHING) ---
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

// --- Safer Query Building ---
$params = [];
$where_conditions = [];

// Build date conditions
if (!empty($start_date)) {
    $where_conditions[] = "visit_time >= :start_date";
    $params[':start_date'] = $start_date . ' 00:00:00';
}
if (!empty($end_date)) {
    $where_conditions[] = "visit_time <= :end_date";
    $params[':end_date'] = $end_date . ' 23:59:59';
}

$where_sql = "";
if (!empty($where_conditions)) {
    $where_sql = "WHERE " . implode(' AND ', $where_conditions);
}

// Fetch main visitor data
$stmt = $pdo->prepare("SELECT *, DATE_FORMAT(visit_time, '%b %d, %Y %h:%i %p') as formatted_timestamp FROM visits {$where_sql} ORDER BY visit_time DESC");
$stmt->execute($params);
$visits = $stmt->fetchAll();

// --- Fetch analytics ---

// Total Visits in filtered range
$stmt = $pdo->prepare("SELECT COUNT(id) as total FROM visits {$where_sql}");
$stmt->execute($params);
$total_visits = $stmt->fetchColumn();

// Unique Visitors in filtered range
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT ip_address) as unique_total FROM visits {$where_sql}");
$stmt->execute($params);
$unique_visitors = $stmt->fetchColumn();

// Today's Visits
$today_start = date('Y-m-d') . ' 00:00:00';
$stmt = $pdo->prepare("SELECT COUNT(id) FROM visits WHERE visit_time >= :today_start");
$stmt->execute([':today_start' => $today_start]);
$today_visits = $stmt->fetchColumn();

// Yesterday's Visits
$yesterday_start = date('Y-m-d', strtotime('-1 day')) . ' 00:00:00';
$yesterday_end = date('Y-m-d', strtotime('-1 day')) . ' 23:59:59';
$stmt = $pdo->prepare("SELECT COUNT(id) FROM visits WHERE visit_time BETWEEN :start AND :end");
$stmt->execute([':start' => $yesterday_start, ':end' => $yesterday_end]);
$yesterday_visits = $stmt->fetchColumn();

// Top Country
$country_conditions = $where_conditions; // Start with date conditions
if (empty($country_conditions)) {
    $country_conditions[] = "1=1"; // Placeholder if no date filter
}
$country_conditions[] = "country IS NOT NULL";
$country_conditions[] = "country != ''";

$country_where_sql = "WHERE " . implode(' AND ', $country_conditions);

$top_country_query = "SELECT country, COUNT(*) as count FROM visits {$country_where_sql} GROUP BY country ORDER BY count DESC LIMIT 1";
$stmt = $pdo->prepare($top_country_query);
$stmt->execute($params);
$top_country_result = $stmt->fetch();
$top_country = $top_country_result ? $top_country_result['country'] : 'N/A';

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Tracker Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-light: #f3f4f6;
            --text-light: #1f2937;
            --card-light: #ffffff;
            --border-light: #e5e7eb;

            --bg-dark: #111827;
            --text-dark: #d1d5db;
            --card-dark: #1f2937;
            --border-dark: #374151;
        }
        html.light { background-color: var(--bg-light); color: var(--text-light); }
        html.dark { background-color: var(--bg-dark); color: var(--text-dark); }
        body { font-family: 'Inter', sans-serif; }
        .card { background-color: var(--card-light); border: 1px solid var(--border-light); }
        .dark .card { background-color: var(--card-dark); border-color: var(--border-dark); }
        .table-header { background-color: #f9fafb; }
        .dark .table-header { background-color: #374151; }
        .table-row-odd { background-color: #ffffff; }
        .dark .table-row-odd { background-color: #1f2937; }
        .table-row-even { background-color: #f9fafb; }
        .dark .table-row-even { background-color: #2c3542; }
    </style>
</head>
<body class="transition-colors duration-300">

    <div class="min-h-screen">
        <!-- Header -->
        <header class="card sticky top-0 z-30 shadow-sm">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center space-x-4">
                       <i class="fa-solid fa-chart-pie text-2xl text-blue-500"></i>
                        <h1 class="text-xl font-bold">Visitor Dashboard</h1>
                    </div>
                    <div class="flex items-center space-x-4">
                        <button id="theme-toggle" class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700">
                            <i id="theme-icon" class="fa-solid fa-moon text-lg"></i>
                        </button>
                        <a href="?logout" class="flex items-center space-x-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                            <i class="fa-solid fa-right-from-bracket"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main class="container mx-auto p-4 sm:p-6 lg:p-8">
            <!-- Analytics Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Today's Visits Card -->
                <div class="card p-6 rounded-xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Today's Visits</p>
                            <p class="text-3xl font-bold mt-1"><?php echo number_format($today_visits); ?></p>
                        </div>
                        <div class="p-3 bg-teal-100 dark:bg-teal-900/50 rounded-lg">
                            <i class="fa-solid fa-sun text-2xl text-teal-500"></i>
                        </div>
                    </div>
                </div>
                <!-- Yesterday's Visits Card -->
                <div class="card p-6 rounded-xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Yesterday's Visits</p>
                            <p class="text-3xl font-bold mt-1"><?php echo number_format($yesterday_visits); ?></p>
                        </div>
                        <div class="p-3 bg-orange-100 dark:bg-orange-900/50 rounded-lg">
                            <i class="fa-solid fa-calendar-day text-2xl text-orange-500"></i>
                        </div>
                    </div>
                </div>
                <!-- Total Visits Card -->
                <div class="card p-6 rounded-xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Visits (Filtered)</p>
                            <p class="text-3xl font-bold mt-1"><?php echo number_format($total_visits); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 dark:bg-blue-900/50 rounded-lg">
                            <i class="fa-solid fa-chart-line text-2xl text-blue-500"></i>
                        </div>
                    </div>
                </div>
                 <!-- Unique Visitors Card -->
                 <div class="card p-6 rounded-xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Unique (Filtered)</p>
                            <p class="text-3xl font-bold mt-1"><?php echo number_format($unique_visitors); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 dark:bg-green-900/50 rounded-lg">
                            <i class="fa-solid fa-users text-2xl text-green-500"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="card p-6 rounded-xl mb-8">
                <h3 class="text-lg font-semibold mb-4">Filter Logs</h3>
                <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                    <form id="filter-form" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="GET" class="flex flex-col sm:flex-row items-end gap-4 flex-grow">
                        <div class="w-full sm:w-auto">
                            <label for="start_date" class="block text-sm font-medium mb-1">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date ?? ''); ?>" class="w-full p-2.5 rounded-lg border dark:bg-gray-700 dark:border-gray-600">
                        </div>
                        <div class="w-full sm:w-auto">
                            <label for="end_date" class="block text-sm font-medium mb-1">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date ?? ''); ?>" class="w-full p-2.5 rounded-lg border dark:bg-gray-700 dark:border-gray-600">
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 font-medium rounded-lg text-sm px-5 py-2.5">Apply</button>
                            <?php if ($start_date || $end_date): ?>
                                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="w-full sm:w-auto text-gray-900 bg-white border border-gray-300 hover:bg-gray-100 font-medium rounded-lg text-sm px-5 py-2.5 dark:bg-gray-800 dark:text-white dark:border-gray-600 dark:hover:bg-gray-700">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <div class="flex items-center gap-2 pt-4 md:pt-0">
                        <a href="?start_date=<?php echo date('Y-m-d'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="w-full sm:w-auto text-center bg-gray-200 hover:bg-gray-300 font-medium rounded-lg text-sm px-5 py-2.5 dark:bg-gray-700 dark:hover:bg-gray-600">Today</a>
                        <a href="?start_date=<?php echo date('Y-m-d', strtotime('-1 day')); ?>&end_date=<?php echo date('Y-m-d', strtotime('-1 day')); ?>" class="w-full sm:w-auto text-center bg-gray-200 hover:bg-gray-300 font-medium rounded-lg text-sm px-5 py-2.5 dark:bg-gray-700 dark:hover:bg-gray-600">Yesterday</a>
                    </div>
                </div>
            </div>

            <!-- Visitor Log Table -->
            <div class="card rounded-xl overflow-hidden">
                 <div class="p-6 border-b dark:border-gray-700">
                     <h3 class="text-lg font-semibold">Visitor Logs</h3>
                     <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Displaying <?php echo count($visits); ?> results. <?php echo $start_date ? ' (Filtered)' : '(All Time)'; ?>
                     </p>
                 </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-left">
                        <thead class="table-header text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-6 py-3">Timestamp / IP</th>
                                <th scope="col" class="px-6 py-3">Location</th>
                                <th scope="col" class="px-6 py-3">Browser / OS</th>
                                <th scope="col" class="px-6 py-3">Device</th>
                                <th scope="col" class="px-6 py-3">Visited URL</th>
                                <th scope="col" class="px-6 py-3">Referrer</th>
                                <th scope="col" class="px-6 py-3">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($visits)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-10">
                                        <i class="fa-solid fa-inbox text-3xl text-gray-400"></i>
                                        <p class="mt-2 font-medium">No visitor data found</p>
                                        <p class="text-gray-500">Try adjusting the date filters.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($visits as $index => $visit): ?>
                                    <tr class="<?php echo $index % 2 == 0 ? 'table-row-even' : 'table-row-odd'; ?> border-b dark:border-gray-700">
                                        <td class="px-6 py-4 font-medium whitespace-nowrap">
                                            <?php echo htmlspecialchars($visit['formatted_timestamp']); ?>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($visit['ip_address']); ?></span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php echo htmlspecialchars($visit['country'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($visit['city'] ?? 'N/A'); ?>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($visit['timezone'] ?? ''); ?></span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php echo htmlspecialchars($visit['browser'] ?? 'N/A'); ?>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($visit['os'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php echo htmlspecialchars(ucfirst($visit['device_type'] ?? 'N/A')); ?>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($visit['screen_resolution'] ?? ''); ?></span>
                                        </td>
                                        <td class="px-6 py-4 max-w-xs truncate">
                                            <a href="<?php echo htmlspecialchars($visit['visited_url']); ?>" target="_blank" class="text-blue-500 hover:underline">
                                                <?php echo htmlspecialchars($visit['visited_url']); ?>
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 max-w-xs truncate">
                                            <?php echo htmlspecialchars($visit['referrer'] ?: 'Direct'); ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="font-semibold"><?php echo htmlspecialchars($visit['isp'] ?? 'N/A'); ?></span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-1">ASN: <?php echo htmlspecialchars($visit['asn'] ?? 'N/A'); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        // --- THEME TOGGLE SCRIPT ---
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = document.getElementById('theme-icon');
        const docElement = document.documentElement;

        // Apply theme on initial load
        const savedTheme = localStorage.getItem('theme') || 'dark'; // Default to dark
        docElement.classList.add(savedTheme);
        if (savedTheme === 'light') {
            themeIcon.classList.replace('fa-moon', 'fa-sun');
        }

        themeToggle.addEventListener('click', () => {
            if (docElement.classList.contains('dark')) {
                // Switch to light
                docElement.classList.replace('dark', 'light');
                themeIcon.classList.replace('fa-moon', 'fa-sun');
                localStorage.setItem('theme', 'light');
            } else {
                // Switch to dark
                docElement.classList.replace('light', 'dark');
                themeIcon.classList.replace('fa-sun', 'fa-moon');
                localStorage.setItem('theme', 'dark');
            }
        });
    </script>
</body>
</html>
