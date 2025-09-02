<?php
// Error reporting for debugging (disable or lower in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Configuration ---
define('USER_DOMAIN', 'Yandux.Biz');
define('SOURCE_DOMAIN', 'apkfab.com');
// --- Helper Functions ---

function create_slug(string $string): string {
    $slug = strtolower(trim($string));
    $slug = preg_replace('/[^a-z0-9\p{L}]+/u', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = rawurlencode($slug);
    return empty($slug) ? 'n-a' : $slug;
}

function extractSlugAndPackageName(string $input): array {
    $decodedInput = urldecode($input);
    $path = parse_url($decodedInput, PHP_URL_PATH) ?? $decodedInput;
    $parts = array_filter(explode('/', trim($path, '/')));
    $packageName = '';
    $slug = '';
    foreach ($parts as $index => $part) {
        if (strpos($part, '.') !== false && !in_array(strtolower($part), ['versions', 'download'])) {
            $packageName = $part;
            if ($index > 0 && !in_array(strtolower($parts[$index - 1]), ['app', 'category', 'developer', 'games'])) {
                $slug = $parts[$index - 1];
            }
            break;
        }
    }
    return ['slug' => $slug, 'package_name' => $packageName];
}

function fetchLatestApps(int $page = 1): array {
    $sourceUrl = 'https://' . SOURCE_DOMAIN . '/new-apps';
    $requestUrl = ($page > 1) ? $sourceUrl . '?page=' . $page : $sourceUrl;
    error_log("Fetching from source: " . $requestUrl);

    $ch = curl_init();
    $commonHeaders = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Accept-Language: en-US,en;q=0.9', 'Cache-Control: no-cache', 'Pragma: no-cache', 'Referer: ' . $sourceUrl];
    $headers = ($page > 1) ? array_merge($commonHeaders, ['Accept: application/json, text/javascript, */*; q=0.01', 'X-Requested-With: XMLHttpRequest']) : array_merge($commonHeaders, ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8', 'Upgrade-Insecure-Requests: 1']);
    
    curl_setopt_array($ch, [CURLOPT_URL => $requestUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => 'gzip']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200 || empty($response)) {
        return ['apps' => [], 'error' => "Error fetching latest apps: " . ($curlError ?: "HTTP " . $httpCode)];
    }

    $htmlToParse = ($page > 1) ? (json_decode($response, true)['html'] ?? null) : $response;
    if (empty($htmlToParse)) {
        return ['apps' => [], 'error' => null]; // Return empty array, not an error, if no more apps
    }

    $apps = [];
    $added_packages = []; // Array to track package names and prevent duplicates
    try {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // It's safer to wrap the HTML fragment for the parser
        $parsableHtml = '<?xml encoding="utf-8" ?>' . "<div>{$htmlToParse}</div>";
        @$dom->loadHTML($parsableHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        $appNodes = $xpath->query("//div[contains(@class, 'list') and .//a/div[@class='icon']]");

        if ($appNodes && $appNodes->length > 0) {
            foreach ($appNodes as $appNode) {
                $linkNode = $xpath->query(".//a[1]", $appNode)->item(0);
                if (!$linkNode) continue;

                $sourceAppUrl = $linkNode->getAttribute('href');
                $appTitle = $linkNode->getAttribute('title');
                
                $extractedInfo = extractSlugAndPackageName($sourceAppUrl);
                $packageName = $extractedInfo['package_name'];
                
                // --- BUG FIX: Check for duplicates ---
                if (empty($packageName) || in_array($packageName, $added_packages)) {
                    continue; // Skip if no package name or if already added
                }
                $added_packages[] = $packageName; // Add package to tracker
                // --- END BUG FIX ---

                $userSlug = create_slug($appTitle);
                $appLink = '/' . $userSlug . '/' . rawurlencode($packageName);

                $iconNode = $xpath->query(".//div[contains(@class, 'icon')]//img", $appNode)->item(0);
                $appIconSrc = $iconNode ? ($iconNode->getAttribute('data-src') ?: $iconNode->getAttribute('src')) : '';

                $ratingNode = $xpath->query(".//span[contains(@class, 'rating')]", $appNode)->item(0);
                $appRating = $ratingNode ? trim($ratingNode->textContent) : '0';
                preg_match('/[\d\.]+/', $appRating, $ratingMatches);
                $appRating = $ratingMatches[0] ?? '0';

                $reviewNode = $xpath->query(".//span[contains(@class, 'review')]", $appNode)->item(0);
                $appReviews = $reviewNode ? trim($reviewNode->textContent) : '0';

                $apps[] = [
                    'link' => $appLink,
                    'title' => $appTitle,
                    'icon' => $appIconSrc,
                    'rating' => $appRating,
                    'reviews' => $appReviews,
                ];
            }
        }
    } catch (Exception $e) {
        return ['apps' => [], 'error' => "Exception during HTML parsing: " . $e->getMessage()];
    }
    
    return ['apps' => $apps, 'error' => null];
}

// --- Main Logic ---
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$result = fetchLatestApps($currentPage);
$apps = $result['apps'] ?? [];
$error = $result['error'] ?? null;

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['apps' => $apps, 'error' => $error]);
    exit;
}

$pageTitle = "Latest Updates - YanduX"; 
include 'includes/header.php';
?>
<style>
    /* Keyframes for animations */
    @keyframes slideInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    .app-card {
        opacity: 0;
        animation: slideInUp 0.5s ease-out forwards;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .app-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
    }
    img.lazy-image { opacity: 0; transition: opacity 0.4s ease-in-out; }
    img.lazy-image.loaded { opacity: 1; }
</style>

<div class="max-w-screen-xl mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 flex items-center gap-3">
            <i class="fas fa-history text-blue-500"></i>
            Latest Update Apps
        </h1>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert">
            <p><strong class="font-bold">Error:</strong> <?= htmlspecialchars($error) ?></p>
        </div>
    <?php elseif (empty($apps) && $currentPage === 1): ?>
        <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4" role="alert">
            <p>No recently updated apps were found.</p>
        </div>
    <?php else: ?>
        <div id="app-list" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-7 gap-4">
            <?php foreach ($apps as $index => $app): ?>
                <div class="app-card bg-white shadow-md hover:shadow-lg transition-all duration-300 rounded-xl overflow-hidden" style="animation-delay: <?= $index * 0.05 ?>s;">
                     <a href="<?= htmlspecialchars($app['link']) ?>" class="block p-3 text-center group">
                        <div class="relative mb-3 w-20 h-20 mx-auto bg-gray-200 rounded-2xl">
                            <img data-src="<?= htmlspecialchars($app['icon']) ?>" alt="<?= htmlspecialchars($app['title']) ?> Icon" class="lazy-image w-full h-full shadow-sm rounded-2xl object-cover" onerror="this.onerror=null; this.src='https://placehold.co/80x80/e2e8f0/64748b?text=Icon';">
                        </div>
                        <h3 class="font-semibold text-gray-800 text-sm leading-tight line-clamp-2 h-10 group-hover:text-blue-600">
                            <?= htmlspecialchars($app['title']) ?>
                        </h3>
                        <div class="flex items-center text-xs text-gray-500 mt-2 w-full justify-center gap-2">
                            <div class="flex items-center gap-1">
                               <svg class="w-3 h-3 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                               <span><?= htmlspecialchars($app['rating']) ?></span>
                           </div>
                            <div class="flex items-center gap-1">
                               <svg class="w-3 h-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a1 1 0 011-1h14a1 1 0 110 2H3a1 1 0 01-1-1zM1 15a1 1 0 100 2h8a1 1 0 100-2H1z"></path></svg>
                               <span><?= htmlspecialchars($app['reviews']) ?></span>
                           </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="flex justify-center mt-8">
            <button id="load-more-button" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-8 rounded-full shadow-lg transition-transform transform hover:scale-105 flex items-center">
                <i class="fas fa-sync-alt mr-2"></i> Load More
            </button>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const list = document.getElementById('app-list');
    const loadMoreButton = document.getElementById('load-more-button');
    if (!loadMoreButton || !list) return;

    let currentPage = 1;
    let isLoading = false;

    const lazyLoadObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.classList.add('loaded');
                observer.unobserve(img);
            }
        });
    }, { rootMargin: "0px 0px 200px 0px" });

    document.querySelectorAll('img.lazy-image').forEach(img => lazyLoadObserver.observe(img));

    function createAppCardHTML(app) {
        const escapeHTML = str => {
            if (typeof str !== 'string') return '';
            return str.replace(/[&<>"']/g, match => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'})[match]);
        };

        const title = escapeHTML(app.title);
        const link = escapeHTML(app.link);
        const icon = escapeHTML(app.icon);
        const rating = escapeHTML(app.rating);
        const reviews = escapeHTML(app.reviews);

        return `
            <a href="${link}" class="block p-3 text-center group">
                <div class="relative mb-3 w-20 h-20 mx-auto bg-gray-200 rounded-2xl">
                    <img data-src="${icon}" alt="${title} Icon" class="lazy-image w-full h-full shadow-sm rounded-2xl object-cover" onerror="this.onerror=null; this.src='https://placehold.co/80x80/e2e8f0/64748b?text=Icon';">
                </div>
                <h3 class="font-semibold text-gray-800 text-sm leading-tight line-clamp-2 h-10 group-hover:text-blue-600">${title}</h3>
                <div class="flex items-center text-xs text-gray-500 mt-2 w-full justify-center gap-2">
                    <div class="flex items-center gap-1">
                       <svg class="w-3 h-3 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                       <span>${rating}</span>
                   </div>
                    <div class="flex items-center gap-1">
                       <svg class="w-3 h-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a1 1 0 011-1h14a1 1 0 110 2H3a1 1 0 01-1-1zM1 15a1 1 0 100 2h8a1 1 0 100-2H1z"></path></svg>
                       <span>${reviews}</span>
                   </div>
                </div>
            </a>
        `;
    }

    async function loadMoreApps() {
        if (isLoading) return;
        isLoading = true;
        loadMoreButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Loading...';
        loadMoreButton.disabled = true;

        currentPage++;
        const url = new URL(window.location.href);
        url.searchParams.set('page', currentPage);

        try {
            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });

            if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
            
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            if (data.apps && data.apps.length > 0) {
                const fragment = document.createDocumentFragment();
                data.apps.forEach(app => {
                    const listItem = document.createElement('div');
                    listItem.className = 'app-card bg-white shadow-md hover:shadow-lg transition-all duration-300 rounded-xl overflow-hidden flex flex-col h-full animate-in';
                    listItem.innerHTML = createAppCardHTML(app);
                    fragment.appendChild(listItem);
                    const newImg = listItem.querySelector('img.lazy-image');
                    if (newImg) lazyLoadObserver.observe(newImg);
                });
                list.appendChild(fragment);
                
                loadMoreButton.innerHTML = '<i class="fas fa-sync-alt mr-2"></i> Load More';
                loadMoreButton.disabled = false;
            } else {
                loadMoreButton.innerHTML = 'No More Apps Found';
                loadMoreButton.style.display = 'none';
            }
        } catch (error) {
            console.error('Error loading more apps:', error);
            loadMoreButton.innerHTML = 'Error - Try Again';
            loadMoreButton.disabled = false;
        } finally {
            isLoading = false;
        }
    }

    loadMoreButton.addEventListener('click', loadMoreApps);
});
</script>

<?php include 'includes/footer.php'; ?>
