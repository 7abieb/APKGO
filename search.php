<?php
// search.php - Handles search results and the search landing page

// Get search keyword with proper sanitization
$keyword = isset($_GET['keyword']) ? trim(substr(strip_tags($_GET['keyword']), 0, 40)) : '';

// Initialize variables for results
$apps = [];
$total = 0;
$error = null;
$relatedKeywords = [];

// Only perform the search (API call) if a keyword is provided
if (!empty($keyword)) {
    $pageTitle = "Search results for \"" . htmlspecialchars($keyword) . "\"";
    $result = extractApkData($keyword);
    $error = $result['error'] ?? null;
    $apps = $result['apps'] ?? [];
    $total = count($apps); // Ensure total reflects actual apps found
    $relatedKeywords = $result['related_keywords'] ?? []; // Get related keywords
} else {
    // If no keyword, it's the /search landing page
    $pageTitle = "Search";
}

// Include header.php which contains the global header and its search bar
include 'includes/header.php';

// --- PHP Helper Functions (These are specific to search.php's scraping logic) ---

function abbreviate_number($num) {
    static $cache = [];
    $key = (string)$num;
    if (isset($cache[$key])) return $cache[$key];
    if ($num >= 1000000000) $result = round($num / 1000000000, 1) . 'B';
    elseif ($num >= 1000000) $result = round($num / 1000000, 1) . 'M';
    elseif ($num >= 1000) $result = round($num / 1000, 1) . 'K';
    else $result = $num;
    $cache[$key] = $result;
    return $result;
}

function parseReviewCount($text) {
    $trimmed = trim($text);
    return preg_match('/[KMB]/i', $trimmed) ? $trimmed : abbreviate_number((float)$trimmed);
}

function reviewCountToNumber(string $str): float {
    $str = trim($str);
    if (preg_match('/^([\d.]+)\s*([KMB])$/i', $str, $m)) {
        $num = (float)$m[1];
        $unit = strtoupper($m[2]);
        switch ($unit) {
            case 'B': return $num * 1_000_000_000;
            case 'M': return $num * 1_000_000;
            case 'K': return $num * 1_000;
        }
    }
    return is_numeric($str) ? (float)$str : 0;
}

function extractApkData($keyword) {
    $searchUrl = 'https://apkfab.com/search?q=' . urlencode($keyword);
    $ch = curl_init();
    $curlOptions = [
        CURLOPT_URL => $searchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml', 'Accept-Language: en-US,en;q=0.9', 'Cache-Control: max-age=0', 'Connection: keep-alive', 'Referer: https://apkfab.com/']
    ];
    curl_setopt_array($ch, $curlOptions);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        return ['error' => 'Connection error: Unable to fetch search results'];
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        return ['error' => 'Server error: Unable to process search request'];
    }
    try {
        $apps = [];
        $relatedKeywords = [];
        $dom = new DOMDocument();
        @$dom->loadHTML($response, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $appNodes = $xpath->query("//div[contains(@class, 'list-template')]/div[contains(@class, 'list')]") ?: $xpath->query("//div[@class='list']");
        foreach ($appNodes as $appNode) {
            $app = extractAppData($xpath, $appNode);
            if ($app) $apps[] = $app;
        }
        if (empty($apps)) {
            $relatedNodes = $xpath->query("//div[contains(@class, 'related-searches')]//a");
            if ($relatedNodes && $relatedNodes->length > 0) {
                foreach ($relatedNodes as $node) {
                    $text = trim($node->textContent);
                    if (!empty($text)) $relatedKeywords[] = htmlspecialchars($text);
                }
            }
        }
        return ['apps' => $apps, 'total' => count($apps), 'related_keywords' => $relatedKeywords];
    } catch (Exception $e) {
        return ['error' => 'Failed to process search results: ' . $e->getMessage()];
    }
}

function extractAppData($xpath, $appNode) {
    $linkNode = $xpath->query(".//a", $appNode)->item(0);
    if (!$linkNode) return null;
    $appUrl = $linkNode->getAttribute('href');
    $appTitle = $linkNode->getAttribute('title');
    if (empty($appTitle)) {
        $titleNode = $xpath->query(".//div[contains(@class, 'title')]", $appNode)->item(0);
        $appTitle = $titleNode ? trim($titleNode->textContent) : '';
    }
    $iconNode = $xpath->query(".//div[contains(@class, 'icon')]/img", $appNode)->item(0);
    $appIcon = extractIconUrl($iconNode);
    $appRating = extractRating($xpath, $appNode);
    $appReviews = extractReviews($xpath, $appNode);
    $appId = extractAppId($appUrl);
    if (empty($appUrl) || empty($appTitle)) return null;
    return ['url' => $appUrl, 'title' => $appTitle, 'icon' => $appIcon, 'rating' => $appRating, 'reviews' => $appReviews, 'app_id' => $appId, 'category' => ''];
}

function extractIconUrl($iconNode) {
    if (!$iconNode) return '';
    $imgAttributes = ['data-src', 'src', 'data-original', 'data-lazy-src'];
    foreach ($imgAttributes as $attr) {
        if ($iconNode->hasAttribute($attr)) {
            $src = $iconNode->getAttribute($attr);
            if (!empty($src) && strpos($src, 'placeholder') === false) return $src;
        }
    }
    return $iconNode->hasAttribute('src') ? $iconNode->getAttribute('src') : '';
}

function extractRating($xpath, $appNode) {
    $ratingNode = $xpath->query(".//span[contains(@class, 'rating')]", $appNode)->item(0);
    if (!$ratingNode) return '';
    preg_match('/([0-9]*\.?[0-9]+)/', $ratingNode->textContent, $matches);
    return isset($matches[1]) ? $matches[1] : '';
}

function extractReviews($xpath, $appNode) {
    $reviewNode = $xpath->query(".//span[contains(@class, 'review')]", $appNode)->item(0);
    if (!$reviewNode) return '';
    preg_match('/([0-9]+[KkMm]?)\+?/', $reviewNode->textContent, $matches);
    return isset($matches[1]) ? $matches[1] : '';
}

function extractAppId($appUrl) {
    if (preg_match('#https://apkfab\.com/(.+)$#', $appUrl, $matches)) {
        return $matches[1];
    }
    return '';
}

$maxReview = 0;
if (!empty($apps)) {
    $reviewCounts = array_map(fn($app) => reviewCountToNumber($app['reviews']), $apps);
    $maxReview = !empty($reviewCounts) ? max($reviewCounts) : 0;
}
?>
<style>
    /* Keyframes for animations */
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes slideInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes float {
        0% { transform: translateY(0px) rotate(-5deg); }
        50% { transform: translateY(-20px) rotate(5deg); }
        100% { transform: translateY(0px) rotate(-5deg); }
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    body {
        background-color: #f8fafc; /* Tailwind gray-50 */
    }

    /* Search Header Section */
    .search-header {
        padding: 2rem 1rem;
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border-bottom: 1px solid #e5e7eb;
        text-align: center;
    }

    /* Prominent Search Bar */
    .prominent-search-bar {
        border-radius: 9999px;
        background-color: #ffffff;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
    }
    .prominent-search-bar:focus-within {
        box-shadow: 0 6px 20px rgba(0,0,0,0.12), 0 0 0 4px rgba(59, 130, 246, 0.4);
    }
    .prominent-search-bar input {
        padding-left: 3rem;
        padding-right: 6rem; /* Make space for icons */
        background-color: transparent;
    }
    .prominent-search-bar .search-icon {
        left: 1rem;
        color: #9ca3af; /* gray-400 */
    }
    .prominent-search-bar .right-icons {
        right: 0.5rem;
    }
    .prominent-search-bar .spinner {
        display: none;
        animation: spin 1s linear infinite;
        color: #9ca3af;
    }
    
    /* App Card & Lazy Loading */
    .app-card-placeholder {
        opacity: 0;
        animation: fadeIn 0.5s ease-out forwards;
        background-color: #e2e8f0; /* gray-200 */
        border-radius: 0.75rem; /* rounded-xl */
        min-height: 180px; /* Adjust to match real card height */
    }
    .app-card.loaded {
        opacity: 1;
    }

    /* Lazy Loading Styles */
    .lazy-img { opacity: 0; transition: opacity 0.4s ease-in-out; }
    .lazy-img.loaded { opacity: 1; }
    .img-container-placeholder { background-color: #e2e8f0; }

    /* Floating Icons on Search Home */
    .floating-icons-container {
        display: flex;
        justify-content: center;
        gap: 4rem;
        margin-top: 1rem;
        margin-bottom: 2rem;
        perspective: 1000px;
    }
    .floating-icon {
        font-size: 3rem;
        animation: float 6s ease-in-out infinite;
    }
    .floating-icon.icon-android { color: #3ddc84; }
    .floating-icon.icon-gamepad { color: #60a5fa; }
    .floating-icon.icon-file { color: #facc15; }
    .floating-icon:nth-child(2) { animation-delay: 1.5s; }
    .floating-icon:nth-child(3) { animation-delay: 3s; }
    
    /* Suggestions box */
    .suggestions-box {
        position: absolute;
        left: 0;
        width: 100%;
        top: calc(100% + 8px);
        background: #fff;
        border-radius: 0.75rem;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
        z-index: 1000;
        max-height: 280px;
        overflow-y: auto;
        display: none;
        border: 1px solid #e5e7eb; /* gray-200 */
    }
    .suggestions-box.active { display: block; animation: fadeIn 0.2s ease-out; }
    .suggestion-item { 
        padding: 0.75rem 1.25rem; 
        cursor: pointer; 
        transition: background-color 0.2s ease; 
        display: flex; 
        align-items: center;
        border-bottom: 1px solid #f3f4f6; /* gray-100 */
    }
    .suggestion-item:last-child { border-bottom: none; }
    .suggestion-item:hover { background: #f9fafb; } /* gray-50 */
    .suggestion-highlight { font-weight: 600; color: #2563eb; } /* blue-600 */
    .suggestion-item img { width: 40px; height: 40px; margin-right: 12px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background-color: #f3f4f6; }

    /* History section */
    .history-badge {
        background-color: #eef2ff; /* indigo-100 */
        color: #3730a3; /* indigo-800 */
        transition: all 0.2s ease;
    }
     .history-badge:hover {
        background-color: #e0e7ff; /* indigo-200 */
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    }
</style>

<?php if (empty($keyword)): // Show the search header ONLY on the landing page ?>
    <div class="search-header">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 tracking-tight animate-slideInUp">
            Find Your Next Favorite App
        </h1>
        <p class="mt-2 max-w-2xl mx-auto text-md text-gray-600 animate-slideInUp" style="animation-delay: 0.2s;">
            Safe, Fast, and Free Downloads
        </p>

        <div class="floating-icons-container animate-slideInUp" style="animation-delay: 0.3s;">
            <i class="fab fa-android floating-icon icon-android"></i>
            <i class="fas fa-gamepad floating-icon icon-gamepad"></i>
            <i class="fas fa-file-archive floating-icon icon-file"></i>
        </div>
        
        <form action="/search" method="GET" class="mt-4 mb-2 max-w-2xl mx-auto animate-slideInUp relative" style="animation-delay: 0.4s;">
            <div class="relative flex items-center prominent-search-bar">
                <i class="fas fa-search search-icon absolute top-1/2 transform -translate-y-1/2"></i>
                <input type="text" name="keyword" id="prominentSearchInput" placeholder="Search for apps, games..."
                       class="w-full py-4 px-5 focus:outline-none text-lg text-gray-800"
                       value="<?= htmlspecialchars($keyword) ?>" autocomplete="off" maxlength="40">
                <div class="right-icons absolute h-full flex items-center justify-center gap-4">
                    <i class="fas fa-spinner spinner" id="search-spinner"></i>
                    <button type="submit" class="submit-btn h-full w-14 flex items-center justify-center hover:text-blue-700">
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
             <div id="prominentSuggestions" class="suggestions-box text-left"></div>
        </form>
    </div>
<?php endif; ?>

<div class="container mx-auto px-4 <?php echo empty($keyword) ? 'py-8' : 'pt-px pb-8'; ?>">
    <div>
        <?php if (!empty($keyword)): // Display search results ?>
            <div class="mb-6">
                <h2 class="text-lg md:text-xl font-medium text-gray-500 flex items-center gap-2">
                    <i class="fas fa-poll-h"></i>
                    <span>Results for:</span> 
                    <span class="font-bold text-blue-600">"<?= htmlspecialchars($keyword) ?>"</span>
                </h2>
                <?php if ($total > 0): ?>
                <p class="text-gray-600 mt-2">
                    <span class="inline-flex items-center gap-2 bg-green-100 text-green-800 text-sm font-medium px-3 py-1 rounded-full">
                        <i class="fas fa-check-circle"></i>
                        Found <?= $total ?> matching apps.
                    </span>
                </p>
                <?php endif; ?>
                 <div id="results-history-container" class="mt-4" style="display: none;">
                    <div class="flex items-center gap-2 text-sm text-gray-600 mb-2">
                        <i class="fas fa-history"></i>
                        <span>Recent Searches:</span>
                    </div>
                    <div id="results-search-history" class="flex flex-wrap gap-2"></div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg" role="alert">
                    <p class="font-bold">Error</p>
                    <p><?= $error ?></p>
                </div>
            <?php elseif (empty($apps)): ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-r-lg text-center py-12" role="alert">
                     <div class="flex flex-col items-center">
                        <i class="fas fa-sad-tear text-yellow-500 text-5xl mb-4"></i>
                        <p class="font-bold text-lg">No Results Found</p>
                        <p>Try a different keyword or check your spelling.</p>
                    </div>
                </div>
                <?php if (!empty($relatedKeywords)): ?>
                    <div class="mt-8 p-6 bg-gray-100 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3">Related searches:</h3>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach ($relatedKeywords as $relatedKeyword): ?>
                                <a href="/search/<?= urlencode($relatedKeyword) ?>" 
                                   class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-full text-blue-800 bg-blue-100 hover:bg-blue-200 transition-colors">
                                   <i class="fas fa-search-plus mr-2"></i>
                                    <?= htmlspecialchars($relatedKeyword) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="flex items-center justify-end mb-4 gap-4">
                     <button id="sort-rating" class="text-sm px-4 py-2 rounded-lg bg-white shadow-sm hover:bg-gray-100 transition-colors flex items-center text-gray-600 font-medium">
                        <i class="fas fa-star mr-2 text-yellow-400"></i>
                        Sort by Rating
                    </button>
                    <button id="sort-reviews" class="text-sm px-4 py-2 rounded-lg bg-white shadow-sm hover:bg-gray-100 transition-colors flex items-center text-gray-600 font-medium">
                        <i class="fas fa-users mr-2 text-gray-400"></i>
                        Sort by Reviews
                    </button>
                </div>
                <div id="app-grid" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-7 gap-4">
                    <?php foreach ($apps as $app): 
                        // Encode app data for lazy loading
                        $app_data_json = htmlspecialchars(json_encode($app), ENT_QUOTES, 'UTF-8');
                    ?>
                        <div class="app-card-placeholder" data-app-data='<?= $app_data_json ?>'></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: // Display search landing page's history section ?>
             <div id="home-history-container" class="max-w-3xl mx-auto text-center" style="display: none;">
                <h3 class="text-lg font-semibold text-gray-700 mb-4">
                    <i class="fas fa-history mr-2 text-blue-500"></i>
                    Recent Searches
                </h3>
                <div id="home-search-history" class="flex flex-wrap justify-center gap-3"></div>
                <button id="home-clear-history-button" class="mt-4 text-sm text-gray-500 hover:text-red-600 transition-colors flex items-center gap-2 mx-auto">
                    <i class="fas fa-trash-alt"></i>
                    Clear History
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- App Sorting ---
    const grid = document.getElementById('app-grid');
    const sortRatingBtn = document.getElementById('sort-rating');
    const sortReviewsBtn = document.getElementById('sort-reviews');
    
    function sortApps(attribute, ascending = false) {
        if (!grid) return;
        const appCards = Array.from(grid.children);
        appCards.sort((a, b) => {
            const dataA = JSON.parse(a.dataset.appData || '{}');
            const dataB = JSON.parse(b.dataset.appData || '{}');
            const valA = parseFloat(dataA[attribute]) || 0;
            const valB = parseFloat(dataB[attribute]) || 0;
            return ascending ? valA - valB : valB - valA;
        });
        appCards.forEach(card => grid.appendChild(card));
    }

    if (sortRatingBtn) sortRatingBtn.addEventListener('click', () => sortApps('rating'));
    if (sortReviewsBtn) sortReviewsBtn.addEventListener('click', () => sortApps('reviews'));

    // --- Lazy Loading for App Results ---
    function createAppCardHTML(app) {
        const ratingValue = parseFloat(app.rating) || 0;
        const reviewsValue = (s => {
            s = String(s).trim().toUpperCase();
            const num = parseFloat(s);
            if (s.endsWith('K')) return num * 1000;
            if (s.endsWith('M')) return num * 1000000;
            if (s.endsWith('B')) return num * 1000000000;
            return num;
        })(app.reviews);

        const displayRating = app.rating ? app.rating : 'N/A';
        const displayReviews = app.reviews ? (s => {
            const num = parseFloat(s);
            if(num >= 1000000000) return (num / 1000000000).toFixed(1) + 'B';
            if(num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
            if(num >= 1000) return (num / 1000).toFixed(1) + 'K';
            return String(s);
        })(reviewsValue) : '0';
        
        return `
            <div class="app-card bg-white shadow-md hover:shadow-lg transition-all duration-300 rounded-xl overflow-hidden"
               data-rating="${ratingValue}" data-reviews="${reviewsValue}">
                <a href="/${app.app_id}" class="block p-3">
                    <div class="flex flex-col items-center text-center">
                        <div class="relative mb-3 w-20 h-20 img-container-placeholder rounded-2xl">
                            <img src="${app.icon}"
                                 alt="${app.title}"
                                 class="w-full h-full shadow-sm rounded-2xl object-cover"
                                 loading="lazy"
                                 onerror="this.onerror=null; this.src='https://placehold.co/80x80/e2e8f0/64748b?text=Icon';">
                        </div>
                        <h3 class="font-semibold text-gray-800 text-sm leading-tight line-clamp-2 h-10">${app.title}</h3>
                        <div class="flex items-center text-xs text-gray-500 mt-2 w-full justify-center gap-2">
                           <div class="flex items-center gap-1">
                               <i class="fas fa-star text-yellow-400"></i>
                               <span>${displayRating}</span>
                           </div>
                            <div class="flex items-center gap-1">
                               <i class="fas fa-users text-gray-400"></i>
                               <span>${displayReviews}</span>
                           </div>
                        </div>
                    </div>
                </a>
            </div>
        `;
    }

    const lazyAppCards = document.querySelectorAll('.app-card-placeholder');
    if ('IntersectionObserver' in window) {
        let appObserver = new IntersectionObserver(function(entries, observer) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    const placeholder = entry.target;
                    const appData = JSON.parse(placeholder.dataset.appData || '{}');
                    if(appData.title) {
                        const cardHTML = createAppCardHTML(appData);
                        placeholder.outerHTML = cardHTML;
                    }
                    observer.unobserve(placeholder);
                }
            });
        }, { rootMargin: "0px 0px 400px 0px" });
        lazyAppCards.forEach(card => appObserver.observe(card));
    } else {
        // Fallback for older browsers
        lazyAppCards.forEach(placeholder => {
            const appData = JSON.parse(placeholder.dataset.appData || '{}');
            if(appData.title) {
               placeholder.outerHTML = createAppCardHTML(appData);
            }
        });
    }

    // --- Search History Logic ---
    const homeHistoryContainer = document.getElementById('home-history-container');
    const homeHistoryList = document.getElementById('home-search-history');
    const homeClearButton = document.getElementById('home-clear-history-button');
    const resultsHistoryContainer = document.getElementById('results-history-container');
    const resultsHistoryList = document.getElementById('results-search-history');

    const SEARCH_HISTORY_KEY = 'search_history';
    const MAX_HISTORY = 8;
    
    const getFromStorage = (key) => JSON.parse(localStorage.getItem(key)) || [];
    const saveToStorage = (key, data) => localStorage.setItem(key, JSON.stringify(data));

    function saveSearchQuery(query) {
        if (!query.trim()) return;
        let history = getFromStorage(SEARCH_HISTORY_KEY);
        history = history.filter(item => item.toLowerCase() !== query.toLowerCase());
        history.unshift(query);
        saveToStorage(SEARCH_HISTORY_KEY, history.slice(0, MAX_HISTORY));
        displaySearchHistory();
    }
    
    function displaySearchHistory() {
        const history = getFromStorage(SEARCH_HISTORY_KEY);
        const hasHistory = history.length > 0;

        // Display on Home Page
        if (homeHistoryContainer) {
            if (hasHistory) {
                homeHistoryContainer.style.display = 'block';
                homeHistoryList.innerHTML = '';
                history.forEach(query => {
                    const item = document.createElement('a');
                    item.href = `/search/${encodeURIComponent(query)}`;
                    item.className = 'history-badge text-sm font-semibold px-4 py-2 rounded-full inline-block';
                    item.textContent = query;
                    homeHistoryList.appendChild(item);
                });
            } else {
                homeHistoryContainer.style.display = 'none';
            }
        }
        
        // Display on Results Page
        if (resultsHistoryContainer) {
             if (hasHistory) {
                resultsHistoryContainer.style.display = 'block';
                resultsHistoryList.innerHTML = '';
                history.forEach(query => {
                    const item = document.createElement('a');
                    item.href = `/search/${encodeURIComponent(query)}`;
                    item.className = 'history-badge text-xs font-semibold px-3 py-1 rounded-full inline-block';
                    item.textContent = query;
                    resultsHistoryList.appendChild(item);
                });
            } else {
                 resultsHistoryContainer.style.display = 'none';
            }
        }
    }
    
    function clearHistory() {
        localStorage.removeItem(SEARCH_HISTORY_KEY);
        displaySearchHistory();
    }

    if (homeClearButton) homeClearButton.addEventListener('click', clearHistory);
    
    const currentKeyword = "<?= addslashes(htmlspecialchars($keyword)) ?>";
    if (currentKeyword) {
        saveSearchQuery(currentKeyword);
    }
    
    displaySearchHistory();

    // --- Prominent Search Bar Suggestions Logic ---
    const prominentSearchInput = document.getElementById('prominentSearchInput');
    const prominentSuggestionsBox = document.getElementById('prominentSuggestions');
    const searchSpinner = document.getElementById('search-spinner');
    
    if (prominentSearchInput) {
        let debounceTimeout;
        prominentSearchInput.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(debounceTimeout);
            prominentSuggestionsBox.classList.remove('active');

            if (query.length < 2) {
                return;
            }
            
            if(searchSpinner) searchSpinner.style.display = 'inline-block';

            debounceTimeout = setTimeout(() => {
                fetch(`/suggest.php?query=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(suggestions => {
                        if (!suggestions || suggestions.length === 0) {
                            prominentSuggestionsBox.classList.remove('active');
                            return;
                        }
                        const highlight = (text, q) => text.replace(new RegExp(q.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&'), 'ig'), match => `<span class="suggestion-highlight">${match}</span>`);
                        prominentSuggestionsBox.innerHTML = suggestions.slice(0, 5).map(s => `
                            <div class="suggestion-item" data-url="${htmlspecialchars(s.url)}">
                                <img src="${htmlspecialchars(s.icon)}" alt="${htmlspecialchars(s.title)}" onerror="this.onerror=null; this.src='https://placehold.co/80x80/e2e8f0/64748b?text=Icon';">
                                <span>${highlight(s.title, query)}</span>
                            </div>
                        `).join('');
                        prominentSuggestionsBox.classList.add('active');
                        prominentSuggestionsBox.querySelectorAll('.suggestion-item').forEach(item => {
                            item.addEventListener('click', () => window.location.href = item.dataset.url);
                        });
                    })
                    .catch(() => prominentSuggestionsBox.classList.remove('active'))
                    .finally(() => {
                        if(searchSpinner) searchSpinner.style.display = 'none';
                    });
            }, 300);
        });

        document.addEventListener('click', (e) => {
            if (prominentSearchInput && prominentSuggestionsBox && !prominentSearchInput.contains(e.target) && !prominentSuggestionsBox.contains(e.target)) {
                 prominentSuggestionsBox.classList.remove('active');
            }
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>
