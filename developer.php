<?php
// Error reporting (disable or lower in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Configuration ---
define('USER_DOMAIN', 'Yandux.Biz');
define('SOURCE_DOMAIN', 'apkfab.com');
define('APPS_PER_PAGE', 21); // Number of apps expected per page from the source

// --- Helper Functions ---
function processLink(string $url): string {
    if (empty($url)) return '';
    if (strpos($url, '//') === 0) return 'https:' . $url;
    if (strpos($url, 'http') === 0) return $url;
    return 'https://' . SOURCE_DOMAIN . '/' . ltrim($url, '/');
}

function createSlug(string $string): string {
    $slug = strtolower(trim($string));
    $slug = preg_replace('/[^a-z0-9\p{L}]+/u', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = rawurlencode($slug);
    return empty($slug) ? 'n-a' : $slug;
}

/**
 * Generates an SVG avatar with the first letter of a name as a fallback for missing icons.
 */
function generateSvgAvatar(string $name): string {
    $firstLetter = strtoupper(substr(trim($name), 0, 1));
    if (empty($firstLetter)) {
        $firstLetter = '?';
    }
    // Generate a consistent color based on the name's hash
    $colors = ['#ef4444', '#f97316', '#eab308', '#84cc16', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6', '#8b5cf6', '#d946ef', '#ec4899'];
    $hash = crc32($name);
    $color = $colors[abs($hash) % count($colors)];

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100"><rect width="100" height="100" fill="' . htmlspecialchars($color) . '" /><text x="50" y="50" font-family="Arial, sans-serif" font-size="50" font-weight="bold" fill="#ffffff" text-anchor="middle" dy=".3em">' . htmlspecialchars($firstLetter) . '</text></svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function fetchDeveloperData(string $developerSlug, int $page = 1) {
    $sourceDevUrl = "https://" . SOURCE_DOMAIN . "/developer/" . $developerSlug;
    $requestUrl = ($page > 1) ? $sourceDevUrl . '?page=' . $page : $sourceDevUrl;
    
    error_log("Fetching from developer source: " . $requestUrl);

    $ch = curl_init();
    $commonHeaders = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Accept-Language: en-US,en;q=0.9', 'Cache-Control: no-cache', 'Pragma: no-cache', 'Referer: ' . $sourceDevUrl . '/'];
    $headers = ($page > 1) ? array_merge($commonHeaders, ['Accept: application/json, text/javascript, */*; q=0.01', 'X-Requested-With: XMLHttpRequest']) : array_merge($commonHeaders, ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8', 'Upgrade-Insecure-Requests: 1']);

    curl_setopt_array($ch, [CURLOPT_URL => $requestUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => 'gzip']);
    
    $response = curl_exec($ch);
    $curlError = curl_errno($ch) ? curl_error($ch) : null;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError || $httpCode !== 200 || empty($response)) {
        return ['error' => "Could not fetch developer from source site. " . ($curlError ?: "HTTP $httpCode"), 'devInfo' => null, 'apps' => [], 'apps_count' => 0];
    }
    
    $htmlToParse = ($page > 1) ? (json_decode($response, true)['html'] ?? null) : $response;
    if (empty($htmlToParse)) {
        return ['error' => ($page > 1 && $httpCode === 200 ? null : "No content to parse."), 'devInfo' => null, 'apps' => [], 'apps_count' => 0];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $htmlToParse, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $result = ['apps' => [], 'devInfo' => null, 'error' => null];
    $added_packages = []; // Array to track package names and prevent duplicates

    if ($page === 1) {
        $fallbackDevName = urldecode($developerSlug);
        $bannerNode = $xpath->query("//div[contains(@class,'developer_banner')]//img")->item(0);
        $iconNode = $xpath->query("//div[contains(@class,'developer_introduce')]//div[contains(@class,'icon')]/img")->item(0);
        $nameNode = $xpath->query("//div[contains(@class,'developer_introduce')]//h1")->item(0);
        $descNode = $xpath->query("//div[contains(@class,'developer_introduce')]//p")->item(0);
        
        $result['devInfo'] = [
            'banner' => $bannerNode ? processLink($bannerNode->getAttribute('src')) : '',
            'icon'   => $iconNode ? processLink($iconNode->getAttribute('src')) : '',
            'name'   => $nameNode ? trim($nameNode->textContent) : $fallbackDevName,
            'desc'   => $descNode ? trim($descNode->textContent) : '',
        ];
    }

    $listNodes = $xpath->query("//div[contains(@class, 'list') and .//a/div[@class='icon']]");
    foreach ($listNodes as $node) {
        $a = $xpath->query(".//a", $node)->item(0);
        if (!$a) continue;

        $href = $a->getAttribute('href');
        $title = $a->getAttribute('title');
        
        $iconNode = $xpath->query(".//div[contains(@class,'icon')]/img", $a)->item(0);
        $icon = $iconNode ? ($iconNode->getAttribute('data-src') ?: $iconNode->getAttribute('src')) : '';
        
        $titleDiv = $xpath->query(".//div[contains(@class,'title')]", $a)->item(0);
        $appTitle = $titleDiv ? trim($titleDiv->textContent) : trim($title);

        $ratingSpan = $xpath->query(".//span[contains(@class,'rating')]", $a)->item(0);
        $reviewSpan = $xpath->query(".//span[contains(@class,'review')]", $a)->item(0);
        
        $parsedUrl = parse_url($href);
        $pathParts = explode('/', trim($parsedUrl['path'] ?? '', '/'));
        $packageName = end($pathParts);
        
        if (empty($packageName) || in_array($packageName, $added_packages)) {
            continue; 
        }
        $added_packages[] = $packageName;
        
        $appSlug = createSlug($appTitle);
        $internalLink = "/$appSlug/$packageName";

        $result['apps'][] = [
            'icon'    => processLink($icon),
            'name'    => $appTitle,
            'link'    => $internalLink,
            'rating'  => $ratingSpan ? preg_replace('/[^0-9.]/', '', $ratingSpan->textContent) : '0',
            'reviews' => $reviewSpan ? trim($reviewSpan->textContent) : '0',
        ];
    }
    $result['apps_count'] = count($result['apps']);
    return $result;
}

// --- Main Logic ---
$rawDev = $_GET['developer'] ?? '';
if (!$rawDev) {
    header("Location: /");
    exit;
}
$developerSlug = rawurlencode($rawDev);
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$result = fetchDeveloperData($developerSlug, $currentPage);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['apps' => $result['apps']]);
    exit;
}

$devInfo = $result['devInfo'];
$devApps = $result['apps'];
$appsCount = $result['apps_count'];
$error = $result['error'];
$pageTitle = ($devInfo['name'] ?? 'Developer') . " - Apps & Games - " . USER_DOMAIN;

// --- Generate Fallbacks for View ---
if ($devInfo) {
    if (empty($devInfo['icon'])) {
        $devInfo['icon'] = generateSvgAvatar($devInfo['name']);
    }
}

include 'includes/header.php';
?>

<style>
    .app-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .app-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
    }
    .lazy-image { opacity: 0; transition: opacity 0.4s ease-in-out; }
    .lazy-image.loaded { opacity: 1; }
    .animate-in { animation: fadeIn 0.5s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .developer-header-content {
        position: relative;
        z-index: 10;
    }
    .developer-icon-container {
        position: relative;
        z-index: 20;
    }
    .modal-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background-color: rgba(0, 0, 0, 0.6);
        display: flex; justify-content: center; align-items: center;
        z-index: 1000; opacity: 0; visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }
    .modal-overlay.open { opacity: 1; visibility: visible; }
    .modal-content {
        background-color: white; padding: 1.5rem; border-radius: 0.75rem;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        width: 90%; max-width: 500px; transform: translateY(-20px);
        transition: transform 0.3s ease;
    }
    .modal-overlay.open .modal-content { transform: translateY(0); }
    .close-button {
        position: absolute; top: 1rem; right: 1rem;
        background: none; border: none; font-size: 1.5rem;
        cursor: pointer; color: #6B7280;
    }
    .share-option-button {
        width: 100%; display: flex; align-items: center; justify-content: center;
        gap: 0.75rem; padding: 0.75rem 1rem; border-radius: 0.5rem;
        font-weight: 600; transition: background-color 0.2s ease;
    }
    .share-option-button i {
        font-size: 1.25rem;
    }
</style>

<div class="max-w-screen-xl mx-auto px-2 sm:px-4 pt-px pb-10">
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded max-w-lg w-full mx-auto">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php elseif ($devInfo): ?>
        <div class="bg-white rounded-xl shadow-lg border border-gray-100 mb-8">
            <?php if (!empty($devInfo['banner'])): ?>
            <div class="relative h-40 md:h-56 w-full bg-gray-200 rounded-t-xl overflow-hidden">
                <img src="<?php echo htmlspecialchars($devInfo['banner']); ?>" alt="<?php echo htmlspecialchars($devInfo['name']); ?> banner" class="object-cover w-full h-full">
            </div>
            <?php endif; ?>
            <div class="flex flex-col sm:flex-row items-center p-6 developer-header-content <?php if (!empty($devInfo['banner'])): ?> -mt-16 sm:-mt-12 <?php endif; ?>">
                 <div class="flex-shrink-0 developer-icon-container">
                    <img src="<?php echo htmlspecialchars($devInfo['icon']); ?>" class="w-28 h-28 sm:w-32 sm:h-32 rounded-xl border-2 border-white bg-white object-cover shadow-lg" alt="<?php echo htmlspecialchars($devInfo['name']); ?>">
                </div>
                <div class="mt-4 sm:mt-12 sm:ml-6 text-center sm:text-left">
                     <h1 class="text-2xl md:text-3xl font-extrabold text-gray-800"><?php echo htmlspecialchars($devInfo['name']); ?></h1>
                     <?php if ($devInfo['desc']): ?>
                        <p class="text-gray-600 text-base mt-2"><?php echo htmlspecialchars($devInfo['desc']); ?></p>
                     <?php endif; ?>
                     <div class="mt-4 flex flex-col sm:flex-row justify-center sm:justify-start gap-3">
                         <a href="https://play.google.com/store/apps/developer?id=<?php echo urlencode($devInfo['name']); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-slate-700 hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500 transition-transform transform hover:scale-105">
                             <i class="fab fa-google-play mr-2"></i>
                             View on Google Play
                         </a>
                         <button id="share-button" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-transform transform hover:scale-105">
                             <i class="fas fa-share-alt mr-2"></i>
                             Share
                         </button>
                     </div>
                </div>
            </div>
        </div>

        <?php if (!empty($devApps)): ?>
            <div class="pt-1">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <svg class="w-6 h-6 text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a5 5 0 00-4.546 2.916A5.986 5.986 0 0010 16a5.986 5.986 0 004.546-2.084A5 5 0 0010 11z" clip-rule="evenodd" />
                    </svg>
                    <span>Apps by <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($devInfo['name']); ?></span></span>
                </h2>
                <div id="app-list" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-7 gap-4">
                    <?php foreach ($devApps as $app): ?>
                        <div class="app-card bg-white shadow-md hover:shadow-lg transition-all duration-300 rounded-xl overflow-hidden flex flex-col h-full">
                            <a href="<?= htmlspecialchars($app['link']) ?>" class="block p-3 text-center group">
                                <div class="relative mb-3 w-20 h-20 mx-auto bg-gray-200 rounded-2xl">
                                    <img data-src="<?= htmlspecialchars($app['icon']) ?>" alt="<?= htmlspecialchars($app['name']) ?> Icon" class="w-full h-full shadow-sm rounded-2xl object-cover lazy-image" onerror="this.onerror=null; this.src='https://placehold.co/80x80/e2e8f0/64748b?text=Icon';">
                                </div>
                                <h3 class="font-semibold text-gray-800 text-sm leading-tight line-clamp-2 h-10 group-hover:text-blue-600">
                                    <?= htmlspecialchars($app['name']) ?>
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
                <?php if ($appsCount >= APPS_PER_PAGE): ?>
                <div class="flex justify-center mt-8">
                    <button id="load-more-button" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-8 rounded-full shadow-lg transition-transform transform hover:scale-105 flex items-center">
                        <i class="fas fa-sync-alt mr-2"></i> Load More
                    </button>
                </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="text-gray-500 text-center mt-8">No apps found for this developer.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Share Modal -->
<div id="share-modal-overlay" class="modal-overlay">
    <div class="modal-content relative">
        <button id="close-share-modal" class="close-button">&times;</button>
        <h3 class="text-lg font-bold text-gray-800 mb-4">Share This Page</h3>
        <p id="share-url-display" class="bg-gray-100 text-gray-700 p-3 rounded-md text-sm break-all mb-4"></p>
        <div class="flex flex-col space-y-3">
            <button id="copy-url-button" class="share-option-button bg-gray-500 text-white hover:bg-gray-600">
                <i class="fas fa-copy"></i> Copy URL
            </button>
            <a id="whatsapp-share-button" class="share-option-button bg-green-500 text-white hover:bg-green-600">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </a>
             <a id="facebook-share-button" class="share-option-button bg-blue-700 text-white hover:bg-blue-800">
                <i class="fab fa-facebook-f"></i> Facebook
            </a>
            <a id="telegram-share-button" class="share-option-button bg-sky-500 text-white hover:bg-sky-600">
                <i class="fab fa-telegram-plane"></i> Telegram
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const appList = document.getElementById('app-list');
    const loadMoreButton = document.getElementById('load-more-button');
    const shareButton = document.getElementById('share-button');
    const shareModalOverlay = document.getElementById('share-modal-overlay');
    const closeShareModalButton = document.getElementById('close-share-modal');
    const shareUrlDisplay = document.getElementById('share-url-display');
    const copyUrlButton = document.getElementById('copy-url-button');
    const whatsappShareButton = document.getElementById('whatsapp-share-button');
    const facebookShareButton = document.getElementById('facebook-share-button');
    const telegramShareButton = document.getElementById('telegram-share-button');
    
    const originalCopyButtonHTML = copyUrlButton ? copyUrlButton.innerHTML : '';

    if (loadMoreButton && !appList) {
      console.error("Load more button exists, but app list container not found.");
      return;
    }

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
        const escape = s => String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]);
        return `
            <div class="app-card bg-white shadow-md hover:shadow-lg transition-all duration-300 rounded-xl overflow-hidden flex flex-col h-full">
                <a href="${escape(app.link)}" class="block p-3 text-center group">
                    <div class="relative mb-3 w-20 h-20 mx-auto bg-gray-200 rounded-2xl">
                        <img data-src="${escape(app.icon)}" alt="${escape(app.name)} Icon" class="w-full h-full shadow-sm rounded-2xl object-cover lazy-image" onerror="this.onerror=null; this.src='https://placehold.co/80x80/e2e8f0/64748b?text=Icon';">
                    </div>
                    <h3 class="font-semibold text-gray-800 text-sm leading-tight line-clamp-2 h-10 group-hover:text-blue-600">${escape(app.name)}</h3>
                    <div class="flex items-center text-xs text-gray-500 mt-2 w-full justify-center gap-2">
                        <div class="flex items-center gap-1">
                            <svg class="w-3 h-3 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                            <span>${escape(app.rating)}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <svg class="w-3 h-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a1 1 0 011-1h14a1 1 0 110 2H3a1 1 0 01-1-1zM1 15a1 1 0 100 2h8a1 1 0 100-2H1z"></path></svg>
                            <span>${escape(app.reviews)}</span>
                        </div>
                    </div>
                </a>
            </div>
        `;
    }

    async function loadMoreDeveloperApps() {
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
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = createAppCardHTML(app).trim();
                    const cardElement = tempDiv.firstChild;
                    fragment.appendChild(cardElement);

                    const newImg = cardElement.querySelector('img.lazy-image');
                    if(newImg) lazyLoadObserver.observe(newImg);
                });
                appList.appendChild(fragment);
                
                if (data.apps.length < <?= APPS_PER_PAGE ?>) {
                    loadMoreButton.innerHTML = 'No More Apps';
                    loadMoreButton.style.display = 'none';
                } else {
                    loadMoreButton.innerHTML = '<i class="fas fa-sync-alt mr-2"></i> Load More';
                    loadMoreButton.disabled = false;
                }
            } else {
                loadMoreButton.innerHTML = 'No More Apps';
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

    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', loadMoreDeveloperApps);
    }
    
    if (shareButton) {
        shareButton.addEventListener('click', function() {
            const urlToShare = window.location.href.split('?')[0]; // Clean URL
            shareUrlDisplay.textContent = urlToShare;
            
            const text = encodeURIComponent("Check out this developer on " + window.location.hostname + "!");
            whatsappShareButton.href = `https://wa.me/?text=${text}%20${encodeURIComponent(urlToShare)}`;
            facebookShareButton.href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(urlToShare)}`;
            telegramShareButton.href = `https://t.me/share/url?url=${encodeURIComponent(urlToShare)}&text=${text}`;

            shareModalOverlay.classList.add('open');
        });
    }

    if (closeShareModalButton) {
        closeShareModalButton.addEventListener('click', () => shareModalOverlay.classList.remove('open'));
    }

    if (shareModalOverlay) {
        shareModalOverlay.addEventListener('click', (event) => {
            if (event.target === shareModalOverlay) {
                shareModalOverlay.classList.remove('open');
            }
        });
    }

    if (copyUrlButton) {
        copyUrlButton.addEventListener('click', function() {
            const url = shareUrlDisplay.textContent;
            navigator.clipboard.writeText(url).then(() => {
                copyUrlButton.innerHTML = '<i class="fas fa-check mr-2"></i> Copied!';
                setTimeout(() => {
                    copyUrlButton.innerHTML = originalCopyButtonHTML;
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy URL to clipboard', err);
            });
        });
    }

    [whatsappShareButton, facebookShareButton, telegramShareButton].forEach(button => {
        if(button){
            button.addEventListener('click', function(e){
                e.preventDefault();
                window.open(this.href, '_blank', 'width=600,height=400');
                shareModalOverlay.classList.remove('open');
            });
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
