<?php
// Error reporting for debugging (disable or lower in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Configuration ---
define('USER_DOMAIN', 'Yandux.Biz');
define('SOURCE_DOMAIN', 'apkfab.com');

// --- Helper Functions ---
function process_link(string $url): string {
    if (empty($url)) return '';
    if (strpos($url, '//') === 0) return 'https:' . $url;
    if (strpos($url, 'http') === 0) return $url;
    return 'https://' . SOURCE_DOMAIN . '/' . ltrim($url, '/');
}

function create_slug(string $string): string {
    $slug = strtolower(trim($string));
    $slug = preg_replace('/[^a-z0-9\p{L}]+/u', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = rawurlencode($slug);
    return empty($slug) ? 'n-a' : $slug;
}

function abbreviate_number($num): string {
    $num = (float)$num;
    if ($num >= 1000000000) return round($num / 1000000000, 1) . 'B';
    if ($num >= 1000000)    return round($num / 1000000, 1) . 'M';
    if ($num >= 1000)       return round($num / 1000, 1) . 'K';
    return (string)$num;
}

function parse_review_count(string $text): string {
    $trimmed = trim(str_replace([',', '.'], '', $text));
    if (preg_match('/[KMB]$/i', trim($text))) {
        return trim($text);
    } elseif (ctype_digit($trimmed)) {
        return abbreviate_number((float)$trimmed);
    }
    return trim($text);
}

function reviewCountToNumber(string $str): float {
    $str = trim(strtoupper($str));
    if (preg_match('/^([\d.]+)\s*([KMB])$/', $str, $m)) {
        $num = (float)$m[1];
        $unit = $m[2];
        switch ($unit) {
            case 'B': return $num * 1_000_000_000;
            case 'M': return $num * 1_000_000;
            case 'K': return $num * 1_000;
        }
    }
    return is_numeric(str_replace(',', '', $str)) ? (float)str_replace(',', '', $str) : 0;
}


function get_category_icon(string $slug): string {
    $map = [
        'art-and-design' => 'fas fa-palette', 'auto-and-vehicles' => 'fas fa-car', 'beauty' => 'fas fa-spa',
        'books-and-reference' => 'fas fa-book-open', 'business' => 'fas fa-briefcase', 'comics' => 'fas fa-mask',
        'communication' => 'fas fa-comments', 'dating' => 'fas fa-heart', 'education' => 'fas fa-graduation-cap',
        'entertainment' => 'fas fa-film', 'events' => 'fas fa-calendar-alt', // FIXED ICON
        'finance' => 'fas fa-wallet', 'food-and-drink' => 'fas fa-utensils', 'health-and-fitness' => 'fas fa-heartbeat',
        'house-and-home' => 'fas fa-home', 'libraries-and-demo' => 'fas fa-book-reader', 'lifestyle' => 'fas fa-tshirt',
        'maps-and-navigation' => 'fas fa-map-marked-alt', 'medical' => 'fas fa-briefcase-medical', 'music-and-audio' => 'fas fa-music',
        'news-and-magazines' => 'fas fa-newspaper', 'parenting' => 'fas fa-baby', 'personalization' => 'fas fa-magic',
        'photography' => 'fas fa-camera-retro', 'productivity' => 'fas fa-file-alt', 'shopping' => 'fas fa-shopping-cart',
        'social' => 'fas fa-users', 'sports' => 'fas fa-futbol', 'tools' => 'fas fa-tools',
        'travel-and-local' => 'fas fa-plane-departure', 'video-players' => 'fas fa-video', 'weather' => 'fas fa-cloud-sun',
        'game-action' => 'fas fa-bomb', 'game-adventure' => 'fas fa-compass', 'game-arcade' => 'fas fa-ghost',
        'game-board' => 'fas fa-chess-board', 'game-card' => 'fas fa-heart', 'game-casino' => 'fas fa-dice',
        'game-casual' => 'fas fa-gamepad', 'game-educational' => 'fas fa-shapes', 'game-music' => 'fas fa-guitar',
        'game-puzzle' => 'fas fa-puzzle-piece', 'game-racing' => 'fas fa-flag-checkered', 'game-role-playing' => 'fas fa-dragon',
        'game-simulation' => 'fas fa-user-astronaut', 'game-sports' => 'fas fa-basketball-ball', 'game-strategy' => 'fas fa-chess',
        'game-trivia' => 'fas fa-question-circle', 'game-word' => 'fas fa-font', 'apps' => 'fas fa-th', 'games' => 'fas fa-gamepad'
    ];
    return $map[$slug] ?? 'fas fa-folder';
}

function fetch_all_categories() {
    $url = 'https://' . SOURCE_DOMAIN . '/category';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_SSL_VERIFYPEER => false]);
    $html = curl_exec($ch);
    if(curl_errno($ch)){ return ['error' => 'Failed to fetch categories.']; }
    curl_close($ch);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $data = ['apps' => [], 'games' => []];
    $sections = $xpath->query("//div[@class='category-page']");

    if($sections->length >= 1) { // Apps
        $app_nodes = $xpath->query(".//div[@class='category-tag']/ul/li/a", $sections->item(0));
        foreach($app_nodes as $node){
            $path = parse_url($node->getAttribute('href'), PHP_URL_PATH);
            $parts = explode('/', trim($path, '/'));
            $data['apps'][] = [
                'name' => trim($node->textContent),
                'link' => '/category/' . ($parts[1] ?? '') . '/' . ($parts[2] ?? ''),
                'slug' => ($parts[2] ?? ''),
            ];
        }
    }
     if($sections->length >= 2) { // Games
        $game_nodes = $xpath->query(".//div[@class='category-tag']/ul/li/a", $sections->item(1));
        foreach($game_nodes as $node){
             $path = parse_url($node->getAttribute('href'), PHP_URL_PATH);
             $parts = explode('/', trim($path, '/'));
            $data['games'][] = [
                'name' => trim($node->textContent),
                'link' => '/category/' . ($parts[1] ?? '') . '/' . ($parts[2] ?? ''),
                'slug' => ($parts[2] ?? ''),
            ];
        }
    }
    return $data;
}

function extractSlugAndPackageName(string $input): array {
    $decodedInput = urldecode($input);
    $path = parse_url($decodedInput, PHP_URL_PATH) ?? $decodedInput;
    $parts = array_filter(explode('/', trim($path, '/')));
    $packageName = ''; $slug = '';
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


function fetchCategoryApps(string $mainCategorySlug, string $subCategorySlug, int $page = 1) {
    if (empty($mainCategorySlug)) {
        return ['apps' => [], 'error' => 'Invalid main category specified.'];
    }
    
    $isAppsOrGamesPage = empty($subCategorySlug) && in_array($mainCategorySlug, ['apps', 'games']);
    
    if ($isAppsOrGamesPage) {
        $sourceUrl = 'https://' . SOURCE_DOMAIN . '/' . rawurlencode($mainCategorySlug);
    } else {
        $sourceUrl = 'https://' . SOURCE_DOMAIN . '/category/' . rawurlencode($mainCategorySlug);
        if (!empty($subCategorySlug)) { $sourceUrl .= '/' . rawurlencode($subCategorySlug); }
    }
    
    $requestUrl = ($page > 1) ? $sourceUrl . '/?page=' . $page : $sourceUrl;
    error_log("Fetching from source: " . $requestUrl);

    $ch = curl_init();
    $commonHeaders = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Accept-Language: en-US,en;q=0.9', 'Cache-Control: no-cache', 'Pragma: no-cache', 'Referer: ' . $sourceUrl . '/'];
    $headers = ($page > 1) ? array_merge($commonHeaders, ['Accept: application/json, text/javascript, */*; q=0.01', 'X-Requested-With: XMLHttpRequest']) : array_merge($commonHeaders, ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8', 'Upgrade-Insecure-Requests: 1']);
    
    curl_setopt_array($ch, [CURLOPT_URL => $requestUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => 'gzip']);
    
    $response = curl_exec($ch); 
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
    $curlError = curl_error($ch); 
    curl_close($ch);

    if ($curlError || $httpCode !== 200 || empty($response)) { return ['apps' => [], 'error' => "Error fetching category page: " . ($curlError ?: "HTTP " . $httpCode)]; }
    
    $htmlToParse = ($page > 1) ? (json_decode($response, true)['html'] ?? null) : $response;
    if (empty($htmlToParse)) { return ['apps' => [], 'error' => ($page > 1 && $httpCode === 200 ? null : "No HTML content to parse.")]; }

    $apps = [];
    try {
        $dom = new DOMDocument(); 
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $htmlToParse, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors(); 
        $xpath = new DOMXPath($dom);
        
        $appNodes = $xpath->query("//div[contains(@class, 'list-item')] | //div[contains(@class, 'category_top_list')] | //div[@class='list' and .//a/div[@class='icon']]");

        if ($appNodes && $appNodes->length > 0) {
            $sourceBaseUrlForLinks = 'https://' . SOURCE_DOMAIN;
            foreach ($appNodes as $appNode) { 
                if ($app = extractAppDataFromNode($xpath, $appNode, $sourceBaseUrlForLinks)) { 
                    $apps[] = $app; 
                } 
            }
        }
    } catch (Exception $e) { return ['apps' => [], 'error' => "Exception during HTML parsing: " . $e->getMessage()]; }
    
    return ['apps' => $apps, 'error' => null];
}

function extractAppDataFromNode(DOMXPath $xpath, DOMElement $appNode, string $sourceBaseUrl): ?array {
    $linkNode = $xpath->query(".//a[1]", $appNode)->item(0);
    if (!$linkNode) return null;

    $sourceAppUrl = $linkNode->getAttribute('href');
    if (empty($sourceAppUrl) || $sourceAppUrl === '#') return null;

    $extractedInfo = extractSlugAndPackageName($sourceAppUrl);
    $packageName = $extractedInfo['package_name'];
    if (empty($packageName)) return null;

    $iconNode = $xpath->query(".//div[contains(@class, 'icon')]//img", $appNode)->item(0);
    $appIconSrc = $iconNode ? ($iconNode->getAttribute('data-src') ?: $iconNode->getAttribute('src')) : '';
    $appIcon = !empty($appIconSrc) ? process_link($appIconSrc, $sourceBaseUrl) : '';

    $appTitle = trim($xpath->query(".//div[contains(@class, 'title')] | .//p[contains(@class, 'title')]", $appNode)->item(0)->textContent ?? '');
    $ratingNodeText = trim($xpath->query(".//div[contains(@class, 'rating')] | .//span[contains(@class, 'rating')]", $appNode)->item(0)->textContent ?? '0');
    $reviewNodeText = trim($xpath->query(".//div[contains(@class, 'review')] | .//span[contains(@class, 'review')]", $appNode)->item(0)->textContent ?? '0');

    $appDescription = trim($xpath->query(".//p[contains(@class, 'short_description')]", $appNode)->item(0)->textContent ?? '');
    $appDeveloper = trim($xpath->query(".//p[contains(@class, 'developer')]", $appNode)->item(0)->textContent ?? '');
    
    if (empty($appTitle)) $appTitle = trim($linkNode->getAttribute('title'));
    if (empty($appTitle)) $appTitle = $packageName;

    $userSlug = !empty($extractedInfo['slug']) ? create_slug($extractedInfo['slug']) : create_slug($appTitle);
    $appLink = '/' . $userSlug . '/' . rawurlencode($packageName);

    preg_match('/[\d\.]+/', $ratingNodeText, $ratingMatches);
    $appRating = $ratingMatches[0] ?? '0';

    preg_match('/([\d\.,]+[KMBT]?\+?)/', $reviewNodeText, $reviewMatches);
    $appReviews = $reviewMatches[0] ?? '0';

    return [
        'link' => $appLink, 'title' => $appTitle, 'icon' => $appIcon, 'rating' => $appRating,
        'reviews_numeric' => reviewCountToNumber($appReviews), 'reviews_formatted' => parse_review_count($appReviews),
        'package_name' => $packageName, 'slug' => $userSlug,
        'description' => $appDescription, 'developer' => $appDeveloper
    ];
}

// --- Main Logic ---
$mainCategorySlug = $_GET['main_category'] ?? '';
$subCategorySlug = $_GET['sub_category'] ?? '';

// If no category is specified, show the main category menu
if (empty($mainCategorySlug)) {
    $pageTitle = "Categories Menu - " . USER_DOMAIN;
    $allCategories = fetch_all_categories();
    include 'includes/header.php';
    ?>
    <style>
        .category-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out, border-color 0.2s ease-in-out;
            border: 1px solid #e5e7eb;
        }
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border-color: currentColor;
        }
    </style>
    <div class="max-w-screen-xl mx-auto px-4 pt-px pb-8">
        <h1 class="text-3xl font-bold text-gray-800 my-8 text-center flex items-center justify-center gap-x-3">
             <i class="fas fa-compass text-blue-500"></i>
            Categories Menu
        </h1>
        <?php if (!empty($allCategories['error'])): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                <p><strong class="font-bold">Error:</strong> <?= htmlspecialchars($allCategories['error']) ?></p>
            </div>
        <?php else: 
            $colors = ['text-red-500', 'text-orange-500', 'text-amber-500', 'text-yellow-500', 'text-lime-500', 'text-green-500', 'text-emerald-500', 'text-teal-500', 'text-cyan-500', 'text-sky-500', 'text-blue-500', 'text-indigo-500', 'text-violet-500', 'text-purple-500', 'text-fuchsia-500', 'text-pink-500', 'text-rose-500'];
            $color_index = 0;
        ?>
            <?php foreach ($allCategories as $type => $categories): if (empty($categories)) continue; ?>
                <div class="mb-12">
                    <h2 class="text-3xl font-bold text-gray-800 mb-6 border-b pb-3 flex items-center">
                        <i class="<?= get_category_icon($type) ?> mr-4 text-blue-500"></i>
                        <?= ucfirst($type) ?>
                    </h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-5">
                        <?php foreach ($categories as $category): 
                            $currentColor = $colors[$color_index % count($colors)];
                            $color_index++;
                        ?>
                            <a href="<?= htmlspecialchars($category['link']) ?>" class="category-card bg-white rounded-lg p-4 flex flex-col items-center justify-center text-center <?= $currentColor ?> hover:bg-gray-50">
                                <i class="<?= get_category_icon($category['slug']) ?> text-3xl mb-3"></i>
                                <span class="font-semibold text-gray-700 group-hover:text-current"><?= htmlspecialchars($category['name']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    include 'includes/footer.php';
    exit;
}

// --- Logic to display apps within a category ---
$isAppsOrGamesPage = empty($subCategorySlug) && in_array($mainCategorySlug, ['apps', 'games']);
$categoryName = !empty($subCategorySlug) ? ucwords(str_replace('-', ' ', $subCategorySlug)) : ucwords(str_replace('-', ' ', $mainCategorySlug));
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$result = fetchCategoryApps($mainCategorySlug, $subCategorySlug, $currentPage);
$apps = $result['apps'] ?? [];
$error = $result['error'] ?? null;

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['apps' => $apps]);
    exit;
}

$pageTitle = ($categoryName) . " - " . USER_DOMAIN;
include 'includes/header.php';
?>
<style>
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .app-list-item.animate-in { animation: fadeIn 0.5s ease-out forwards; }
    img.lazy-image { opacity: 0; transition: opacity 0.4s ease-in-out; }
    img.lazy-image.loaded { opacity: 1; }
    #share-popup-overlay { transition: opacity 0.3s ease-in-out; }
    #share-popup { transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; }
    .app-card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .app-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); }
</style>

<div class="max-w-screen-xl mx-auto px-2 sm:px-4 pt-px pb-8">
    <div class="bg-gray-50 rounded-lg shadow-sm p-4 md:p-6">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 border-b pb-4 flex items-center">
            <i class="<?= get_category_icon($subCategorySlug ?: $mainCategorySlug) ?> text-blue-500 mr-4 text-2xl"></i>
            <?= htmlspecialchars($categoryName) ?>
        </h1>

        <?php if ($error): ?>
             <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert"><p><strong class="font-bold">Error:</strong> <?php echo htmlspecialchars($error); ?></p></div>
        <?php elseif (empty($apps) && $currentPage === 1): ?>
            <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4" role="alert"><p>No apps were found in this category.</p></div>
        <?php else:
            $gridClasses = $isAppsOrGamesPage
                ? 'grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-7 gap-4'
                : 'grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5';
        ?>
            <div id="app-list" class="grid <?= $gridClasses ?>" data-layout="<?= $isAppsOrGamesPage ? 'compact' : 'detailed' ?>">
                 <?php foreach ($apps as $app): ?>
                    <?php if ($isAppsOrGamesPage): ?>
                        <div class="app-list-item bg-white shadow-md hover:shadow-lg transition-all duration-300 rounded-xl overflow-hidden flex flex-col h-full">
                            <a href="<?= htmlspecialchars($app['link']) ?>" class="block p-3 text-center group">
                                <div class="relative mb-3 w-20 h-20 mx-auto bg-gray-200 rounded-2xl">
                                    <img data-src="<?= htmlspecialchars($app['icon']) ?>" alt="<?= htmlspecialchars($app['title']) ?> Icon" class="w-full h-full shadow-sm rounded-2xl object-cover lazy-image" onerror="this.onerror=null; this.src='https://placehold.co/80x80/e2e8f0/64748b?text=Icon';">
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
                                       <span><?= htmlspecialchars($app['reviews_formatted']) ?></span>
                                   </div>
                                </div>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="app-list-item bg-white rounded-xl hover:shadow-lg transition-shadow duration-300 flex flex-col h-full">
                            <a href="<?= htmlspecialchars($app['link']) ?>" class="group p-4 flex-grow">
                                <div class="flex items-start mb-3">
                                    <img data-src="<?= htmlspecialchars($app['icon']) ?>" src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" alt="<?= htmlspecialchars($app['title']) ?> Icon" class="lazy-image w-16 h-16 rounded-2xl shadow-md object-cover mr-4" onerror="this.onerror=null; this.src='https://placehold.co/64x64/e2e8f0/64748b?text=Icon';">
                                    <div class="flex-1 min-w-0">
                                        <p class="font-bold text-gray-800 text-base line-clamp-2 leading-tight group-hover:text-blue-600" title="<?= htmlspecialchars($app['title']) ?>"><?= htmlspecialchars($app['title']) ?></p>
                                        <?php if (!empty($app['developer'])): ?><p class="text-sm text-blue-500 line-clamp-1" title="<?= htmlspecialchars($app['developer']) ?>"><?= htmlspecialchars($app['developer']) ?></p><?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($app['description'])): ?><p class="text-sm text-gray-500 mt-1 line-clamp-3"><?= htmlspecialchars($app['description']) ?></p><?php endif; ?>
                            </a>
                            <div class="flex items-center text-xs text-gray-500 px-4 pb-3 pt-3 border-t border-gray-100">
                                <i class="fas fa-star text-yellow-400 mr-1"></i><span><?= htmlspecialchars($app['rating']) ?></span>
                                <span class="mx-2">|</span>
                                <i class="fas fa-users text-gray-400 mr-1"></i><span><?= htmlspecialchars($app['reviews_formatted']) ?></span>
                                <button class="share-button ml-auto text-gray-500 hover:text-blue-600 p-1 rounded-full" data-link="<?= htmlspecialchars('https://' . USER_DOMAIN . $app['link']) ?>" data-title="<?= htmlspecialchars($app['title']) ?>"><i class="fas fa-share-alt"></i></button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="flex justify-center mt-8">
                <button id="load-more-button" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-8 rounded-full shadow-lg transition-transform transform hover:scale-105 flex items-center">
                    <i class="fas fa-sync-alt mr-2"></i> Load More
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>
<!-- Share Popup Modal -->
<div id="share-popup-overlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 opacity-0 pointer-events-none z-50">
    <div id="share-popup" class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md transform scale-95 opacity-0">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Share App</h3>
            <button id="close-popup" class="text-gray-400 hover:text-gray-600 transition-colors"><i class="fas fa-times fa-lg"></i></button>
        </div>
        <div>
            <p class="text-sm text-gray-600 mb-2">Share this link via</p>
            <div class="flex items-center border rounded-lg p-2 bg-gray-100">
                <input id="share-link-input" type="text" readonly class="flex-grow bg-transparent border-none text-gray-700 text-sm focus:outline-none">
                <button id="copy-link-button" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-1 px-3 rounded-md text-xs transition-colors">Copy</button>
            </div>
            <p id="copy-feedback" class="text-green-500 text-xs mt-1 h-4"></p>
        </div>
        <div class="mt-4 pt-4 border-t">
             <p class="text-sm text-gray-600 mb-3 text-center">Or share on social media</p>
             <div class="flex justify-center space-x-4">
                <a id="share-telegram" href="#" target="_blank" class="text-blue-500 hover:text-blue-600 transition-colors"><i class="fab fa-telegram-plane fa-2x"></i></a>
                <a id="share-whatsapp" href="#" target="_blank" class="text-green-500 hover:text-green-600 transition-colors"><i class="fab fa-whatsapp fa-2x"></i></a>
                <a id="share-sms" href="#" class="text-indigo-500 hover:text-indigo-600 transition-colors"><i class="fas fa-comment-dots fa-2x"></i></a>
                <a id="share-email" href="#" class="text-red-500 hover:text-red-600 transition-colors"><i class="fas fa-envelope fa-2x"></i></a>
                <a id="share-facebook" href="#" target="_blank" class="text-blue-800 hover:text-blue-900 transition-colors"><i class="fab fa-facebook-f fa-2x"></i></a>
             </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
    const list = document.getElementById('app-list');
    const loadMoreButton = document.getElementById('load-more-button');
    if (!loadMoreButton || !list) return;
    
    const popupOverlay = document.getElementById('share-popup-overlay');
    const closePopupButton = document.getElementById('close-popup');
    const shareLinkInput = document.getElementById('share-link-input');
    const copyLinkButton = document.getElementById('copy-link-button');
    const copyFeedback = document.getElementById('copy-feedback');

    let currentPage = 1;
    let isLoading = false;
    
    function setupEventListeners(container) {
        container.querySelectorAll('.share-button:not([data-listener-attached])').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault(); e.stopPropagation();
                const appLink = this.dataset.link;
                const appTitle = this.dataset.title;
                const encodedLink = encodeURIComponent(appLink);
                const encodedTitle = encodeURIComponent(`Check out this app: ${appTitle}`);
                shareLinkInput.value = appLink;
                document.getElementById('share-telegram').href = `https://t.me/share/url?url=${encodedLink}&text=${encodedTitle}`;
                document.getElementById('share-whatsapp').href = `https://api.whatsapp.com/send?text=${encodedTitle}%20${encodedLink}`;
                document.getElementById('share-sms').href = `sms:?&body=${encodedTitle}%20${encodedLink}`;
                document.getElementById('share-email').href = `mailto:?subject=${encodedTitle}&body=I found this cool app, check it out here: ${encodedLink}`;
                document.getElementById('share-facebook').href = `https://www.facebook.com/sharer/sharer.php?u=${encodedLink}`;
                popupOverlay.classList.remove('opacity-0', 'pointer-events-none');
                document.getElementById('share-popup').classList.remove('scale-95', 'opacity-0');
            });
            button.dataset.listenerAttached = 'true';
        });

        container.querySelectorAll('img.lazy-image:not(.loaded):not(.observing)').forEach(img => {
            img.classList.add('observing');
            lazyLoadObserver.observe(img);
        });
    }

    closePopupButton.addEventListener('click', () => {
        popupOverlay.classList.add('opacity-0', 'pointer-events-none');
        document.getElementById('share-popup').classList.add('scale-95', 'opacity-0');
        copyFeedback.textContent = ''; copyLinkButton.textContent = 'Copy';
    });
    
    popupOverlay.addEventListener('click', (e) => {
        if (e.target === popupOverlay) closePopupButton.click();
    });

    copyLinkButton.addEventListener('click', () => {
        shareLinkInput.select();
        try {
            document.execCommand('copy');
            copyFeedback.textContent = 'Copied!'; copyLinkButton.textContent = 'Copied';
            setTimeout(() => { copyFeedback.textContent = ''; copyLinkButton.textContent = 'Copy'; }, 2000);
        } catch (err) { copyFeedback.textContent = 'Failed to copy!'; }
    });

    const lazyLoadObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.classList.remove('observing'); img.classList.add('loaded');
                observer.unobserve(img);
            }
        });
    }, { rootMargin: "0px 0px 200px 0px" });

    setupEventListeners(document.body);

    function createDetailedCardHTML(app) {
        const escape = s => String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]);
        const fullLink = escape('https://' + '<?php echo USER_DOMAIN ?>' + app.link);
        return `<div class="bg-white rounded-xl hover:shadow-lg transition-shadow duration-300 flex flex-col h-full"><a href="${escape(app.link)}" class="group p-4 flex-grow"><div class="flex items-start mb-3"><img data-src="${escape(app.icon)}" src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" alt="${escape(app.title)} Icon" class="lazy-image w-16 h-16 rounded-2xl shadow-md object-cover mr-4"><div class="flex-1 min-w-0"><p class="font-bold text-gray-800 text-base line-clamp-2 leading-tight group-hover:text-blue-600">${escape(app.title)}</p>${app.developer ? `<p class="text-sm text-blue-500 line-clamp-1">${escape(app.developer)}</p>` : ''}</div></div>${app.description ? `<p class="text-sm text-gray-500 mt-1 line-clamp-3">${escape(app.description)}</p>` : ''}</a><div class="flex items-center text-xs text-gray-500 px-4 pb-3 pt-3 border-t border-gray-100"><i class="fas fa-star text-yellow-400 mr-1"></i><span>${escape(app.rating)}</span><span class="mx-2">|</span><i class="fas fa-users text-gray-400 mr-1"></i><span>${escape(app.reviews_formatted)}</span><button class="share-button ml-auto text-gray-500 hover:text-blue-600 p-1 rounded-full" data-link="${fullLink}" data-title="${escape(app.title)}"><i class="fas fa-share-alt"></i></button></div></div>`;
    }

    function createCompactCardHTML(app) {
        const escape = s => String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]);
        return `<div class="bg-white shadow-md hover:shadow-lg transition-all duration-300 rounded-xl overflow-hidden flex flex-col h-full"><a href="${escape(app.link)}" class="block p-3 text-center group"><div class="relative mb-3 w-20 h-20 mx-auto bg-gray-200 rounded-2xl"><img data-src="${escape(app.icon)}" alt="${escape(app.title)}" class="w-full h-full shadow-sm rounded-2xl object-cover lazy-image" onerror="this.onerror=null; this.src='https://placehold.co/80x80/e2e8f0/64748b?text=Icon';"></div><h3 class="font-semibold text-gray-800 text-sm leading-tight line-clamp-2 h-10 group-hover:text-blue-600">${escape(app.title)}</h3><div class="flex items-center text-xs text-gray-500 mt-2 w-full justify-center gap-2"><div class="flex items-center gap-1"><svg class="w-3 h-3 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg><span>${escape(app.rating)}</span></div><div class="flex items-center gap-1"><svg class="w-3 h-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a1 1 0 011-1h14a1 1 0 110 2H3a1 1 0 01-1-1zM1 15a1 1 0 100 2h8a1 1 0 100-2H1z"></path></svg><span>${escape(app.reviews_formatted)}</span></div></div></a></div>`;
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
            const response = await fetch(url.toString(), { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
            const contentType = response.headers.get("content-type");
            if (!contentType || !contentType.includes("application/json")) { throw new Error("Received non-JSON response from server. Expected JSON."); }
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            if (data.apps && data.apps.length > 0) {
                const fragment = document.createDocumentFragment();
                const layout = list.dataset.layout || 'detailed';
                
                data.apps.forEach(app => {
                    const gridItem = document.createElement('div');
                    gridItem.className = 'app-list-item animate-in';
                    if (layout === 'compact') {
                        gridItem.classList.add('bg-white', 'shadow-md', 'hover:shadow-lg', 'transition-all', 'duration-300', 'rounded-xl', 'overflow-hidden', 'flex', 'flex-col', 'h-full');
                        gridItem.innerHTML = createCompactCardHTML(app);
                    } else {
                        gridItem.classList.add('bg-white', 'rounded-xl', 'hover:shadow-lg', 'transition-shadow', 'duration-300', 'flex', 'flex-col', 'h-full');
                        gridItem.innerHTML = createDetailedCardHTML(app);
                    }
                    fragment.appendChild(gridItem);
                });
                list.appendChild(fragment);
                
                setupEventListeners(list);
                
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
