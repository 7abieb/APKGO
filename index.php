<?php
// FILE: index.php

// Enable output buffering and gzip compression for faster page load
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
} else {
    ob_start();
}
header('Content-Type: text/html; charset=utf-8');

// --- Configuration ---
if (!defined('USER_DOMAIN')) define('USER_DOMAIN', 'Yandux.Biz');
if (!defined('SOURCE_DOMAIN')) define('SOURCE_DOMAIN', 'apkfab.com');

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
    foreach ($parts as $index => $part) {
        if (strpos($part, '.') !== false && !in_array(strtolower($part), ['versions', 'download'])) {
            $packageName = $part;
            break;
        }
    }
    return ['package_name' => $packageName];
}

function get_category_icon(string $slug): string {
    $map = [
        'tools' => 'fas fa-tools', 'communication' => 'fas fa-comments', 'social' => 'fas fa-users',
        'productivity' => 'fas fa-file-alt', 'education' => 'fas fa-graduation-cap', 'entertainment' => 'fas fa-film',
        'photography' => 'fas fa-camera-retro', 'finance' => 'fas fa-wallet', 'shopping' => 'fas fa-shopping-cart',
        'game-action' => 'fas fa-bomb', 'game-adventure' => 'fas fa-compass', 'game-arcade' => 'fas fa-ghost',
        'game-board' => 'fas fa-chess-board', 'game-card' => 'fas fa-heart', 'game-casino' => 'fas fa-dice',
        'game-casual' => 'fas fa-gamepad', 'game-educational' => 'fas fa-shapes', 'game-music' => 'fas fa-guitar',
        'game-puzzle' => 'fas fa-puzzle-piece', 'game-racing' => 'fas fa-flag-checkered', 'game-role-playing' => 'fas fa-dragon',
        'game-simulation' => 'fas fa-user-astronaut', 'game-sports' => 'fas fa-basketball-ball', 'game-strategy' => 'fas fa-chess',
        'game-trivia' => 'fas fa-question-circle', 'game-word' => 'fas fa-font'
    ];
    return $map[$slug] ?? 'fas fa-folder';
}


// --- Web Scraping Function for Hot Apps/Games ---
function scrape_hot_items(string $type = 'apps', int $limit = 24) {
    $url = 'https://' . SOURCE_DOMAIN . '/' . $type;
    $data = [];
    $added_packages = [];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT => 15
    ]);
    $html_content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200 || !$html_content) {
        error_log("Failed to fetch content from $url. HTTP Code: $http_code");
        return [];
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html_content);
    $xpath = new DOMXPath($dom);
    
    $nodes = $xpath->query('//div[contains(@class, "list-template")]/div[contains(@class, "list")]');
    if ($nodes->length === 0) {
        error_log("Could not find item nodes with the specified XPath on $url.");
        return [];
    }
    
    $count = 0;
    foreach ($nodes as $node) {
        if ($count >= $limit) break;

        $link_node = $xpath->query('.//a', $node)->item(0);
        if (!$link_node) continue;

        $path = $link_node->getAttribute('href');
        $title = $xpath->query('.//div[@class="title"]', $link_node)->item(0)->nodeValue ?? 'N/A';
        
        $icon_node = $xpath->query('.//div[@class="icon"]/img', $link_node)->item(0);
        $icon = $icon_node ? ($icon_node->getAttribute('data-src') ?: $icon_node->getAttribute('src')) : 'https://placehold.co/56x56/e0e0e0/757575?text=Icon';
        
        $rating_node = $xpath->query('.//div[@class="other"]/span[@class="rating"]', $link_node)->item(0);
        $rating = $rating_node ? trim(str_replace('Rating', '', $rating_node->nodeValue)) : 'N/A';

        $review_node = $xpath->query('.//div[@class="other"]/span[@class="review"]', $link_node)->item(0);
        $reviews = $review_node ? trim($review_node->nodeValue) : 'N/A';
        
        if ($title !== 'N/A') {
            $extractedInfo = extractSlugAndPackageName($path);
            $packageName = $extractedInfo['package_name'];
            
            if (empty($packageName) || in_array($packageName, $added_packages)) {
                continue;
            }
            $added_packages[] = $packageName;

            $appSlug = create_slug($title);
            $internalLink = "/{$appSlug}/{$packageName}";

            $data[] = [
                'title'   => trim($title),
                'rating'  => trim($rating),
                'reviews' => trim($reviews),
                'icon'    => trim($icon),
                'path'    => $internalLink
            ];
            $count++;
        }
    }
    return $data;
}


// --- Web Scraping Function for Latest Apps/Games ---
function scrape_latest_items(string $type = 'apps', int $limit = 24) {
    $source_path = ($type === 'apps') ? '/new-apps' : '/new-games';
    $url = 'https://' . SOURCE_DOMAIN . $source_path;
    $data = [];
    $added_packages = [];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT => 15
    ]);
    $html_content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200 || !$html_content) {
        error_log("Failed to fetch latest $type from $url. HTTP Code: $http_code");
        return [];
    }
    
    $dom = new DOMDocument();
    @$dom->loadHTML($html_content);
    $xpath = new DOMXPath($dom);

    $nodes = $xpath->query("//div[contains(@class, 'list') and .//a/div[@class='icon']]");
    if ($nodes->length === 0) {
        error_log("Could not find latest $type nodes on $url.");
        return [];
    }

    $count = 0;
    foreach ($nodes as $node) {
        if ($count >= $limit) break;

        $linkNode = $xpath->query(".//a[1]", $node)->item(0);
        if (!$linkNode) continue;

        $sourceAppUrl = $linkNode->getAttribute('href');
        $title = $linkNode->getAttribute('title');
        
        $extractedInfo = extractSlugAndPackageName($sourceAppUrl);
        $packageName = $extractedInfo['package_name'];
        
        if (empty($packageName) || in_array($packageName, $added_packages)) {
            continue;
        }
        $added_packages[] = $packageName;
        
        $userSlug = create_slug($title);
        $internalLink = "/{$userSlug}/{$packageName}";

        $iconNode = $xpath->query(".//div[contains(@class, 'icon')]//img", $node)->item(0);
        $icon = $iconNode ? ($iconNode->getAttribute('data-src') ?: $iconNode->getAttribute('src')) : 'https://placehold.co/56x56/e0e0e0/757575?text=Icon';

        $ratingNode = $xpath->query(".//span[contains(@class, 'rating')]", $node)->item(0);
        $rating = 'N/A';
        if ($ratingNode) {
            preg_match('/[\d\.]+/', trim($ratingNode->textContent), $ratingMatches);
            $rating = $ratingMatches[0] ?? 'N/A';
        }
        
        $reviewNode = $xpath->query(".//span[contains(@class, 'review')]", $node)->item(0);
        $reviews = $reviewNode ? trim($reviewNode->textContent) : 'N/A';

        $data[] = [
            'title'   => trim($title),
            'rating'  => trim($rating),
            'reviews' => trim($reviews),
            'icon'    => trim($icon),
            'path'    => $internalLink
        ];
        $count++;
    }
    return $data;
}

// Fetch data before including the header
$hot_apps = scrape_hot_items('apps', 24);
$hot_games = scrape_hot_items('games', 24);
$latest_apps = scrape_latest_items('apps', 24);
$latest_games = scrape_latest_items('games', 24);

// Now include the header, which can use the data if needed for SEO
include 'includes/header.php';
?>

<main class="container mx-auto px-4 py-4">
    <div class="flex flex-col lg:flex-row gap-4">
        <div class="flex-1">
            <section class="bg-white rounded-lg shadow-sm p-4">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold flex items-center gap-2 text-gray-800">
                        <i class="fas fa-fire text-orange-500"></i> Hot Apps
                    </h2>
                    <a href="/apps" class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-semibold py-1 px-3 rounded-full transition-colors">
                        More &rarr;
                    </a>
                </div>
                <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-8 gap-4">
                    <?php if (!empty($hot_apps)): ?>
                        <?php foreach ($hot_apps as $app): 
                            $title = htmlspecialchars($app["title"]);
                            $rating = htmlspecialchars($app["rating"]);
                            $reviews = htmlspecialchars($app["reviews"]);
                            $icon_url = htmlspecialchars($app["icon"]);
                            $path = htmlspecialchars($app["path"]);
                        ?>
                            <a href="<?= $path ?>" title="<?= $title ?>" class="p-3 transition rounded-xl flex flex-col items-center text-center transform hover:-translate-y-1 duration-200 ease-in-out">
                                <div class="w-14 h-14 mb-2 flex items-center justify-center">
                                    <img src="<?= $icon_url ?>" loading="lazy" width="56" height="56" class="w-full h-full object-contain rounded-xl shadow-sm" alt="<?= $title ?> logo" decoding="async" onerror="this.onerror=null;this.src='https://placehold.co/56x56/e0e0e0/757575?text=Error';">
                                </div>
                                <h3 class="font-semibold text-gray-900 text-sm truncate w-full"><?= $title ?></h3>
                                <div class="flex items-center text-xs text-gray-600 mt-1 w-full justify-center gap-1">
                                   <i class="fas fa-star text-orange-500 text-xs"></i> <span><?= $rating ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class='col-span-full text-center text-gray-500'>Could not load app data.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="bg-white rounded-lg shadow-sm p-4 mt-4">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold flex items-center gap-2 text-gray-800">
                        <i class="fas fa-gamepad text-purple-600 mr-1"></i> Hot Games
                    </h2>
                     <a href="/games" class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-semibold py-1 px-3 rounded-full transition-colors">
                        More &rarr;
                    </a>
                </div>
                <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-8 gap-4">
                     <?php if (!empty($hot_games)): ?>
                        <?php foreach ($hot_games as $game): 
                            $title = htmlspecialchars($game["title"]);
                            $rating = htmlspecialchars($game["rating"]);
                            $icon_game_url = htmlspecialchars($game["icon"]);
                            $path = htmlspecialchars($game["path"]);
                        ?>
                            <a href="<?= $path ?>" title="<?= $title ?>" class="p-3 transition rounded-xl flex flex-col items-center text-center transform hover:-translate-y-1 duration-200 ease-in-out">
                                <div class="w-14 h-14 mb-2 flex items-center justify-center">
                                    <img src="<?= $icon_game_url ?>" loading="lazy" width="56" height="56" class="w-full h-full object-contain rounded-xl shadow-sm" alt="<?= $title ?> logo" decoding="async" onerror="this.onerror=null;this.src='https://placehold.co/56x56/e0e0e0/757575?text=Error';">
                                </div>
                                <h3 class="font-semibold text-gray-900 text-sm truncate w-full"><?= $title ?></h3>
                                <div class="flex items-center text-xs text-gray-600 mt-1 w-full justify-center gap-1">
                                   <i class="fas fa-star text-orange-500 text-xs"></i> <span><?= $rating ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class='col-span-full text-center text-gray-500'>Could not load game data.</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <aside class="w-full lg:w-64 flex-shrink-0">
            <div class="sticky top-20"> 
                <section class="bg-white shadow-sm rounded-lg p-4">
                    <h3 class="text-lg font-bold mb-4 text-gray-700 flex items-center gap-2">
                        <i class="fas fa-th-large text-gray-600"></i>
                        <span>App Categories</span>
                    </h3>
                    <nav class="flex flex-col gap-1" aria-label="App Categories">
                        <?php
                        $app_categories = [
                            ['slug'=>'tools','name'=>'Tools'], ['slug'=>'communication','name'=>'Communication'], ['slug'=>'social','name'=>'Social'],
                            ['slug'=>'productivity','name'=>'Productivity'], ['slug'=>'education','name'=>'Education'], ['slug'=>'entertainment','name'=>'Entertainment'],
                            ['slug'=>'photography','name'=>'Photography'], ['slug'=>'finance','name'=>'Finance'], ['slug'=>'shopping','name'=>'Shopping']
                        ];
                        foreach ($app_categories as $cat) {
                            $slug = htmlspecialchars($cat['slug']);
                            $name = htmlspecialchars($cat['name']);
                            $iconClass = get_category_icon($slug);
                            echo '<a href="/category/apps/' . $slug . '" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors text-gray-700">'
                               . '<i class="' . $iconClass . ' text-gray-600 w-4 h-4 text-center"></i>' . $name . '</a>';
                        }
                        ?>
                    </nav>
                </section>
                 <section class="bg-white shadow-sm rounded-lg p-4 mt-4">
                    <h3 class="text-lg font-bold mb-4 text-gray-700 flex items-center gap-2">
                        <i class="fas fa-gamepad text-gray-600"></i>
                        <span>Game Categories</span>
                    </h3>
                    <nav class="flex flex-col gap-1" aria-label="Game Categories">
                        <?php
                        $game_categories = [
                            ['slug'=>'game-action','name'=>'Action'], ['slug'=>'game-adventure','name'=>'Adventure'], ['slug'=>'game-arcade','name'=>'Arcade'],
                            ['slug'=>'game-board','name'=>'Board'], ['slug'=>'game-card','name'=>'Card'], ['slug'=>'game-casino','name'=>'Casino'],
                            ['slug'=>'game-casual','name'=>'Casual'], ['slug'=>'game-puzzle','name'=>'Puzzle'], ['slug'=>'game-racing','name'=>'Racing']
                        ];
                        foreach ($game_categories as $cat) {
                            $slug = htmlspecialchars($cat['slug']);
                            $name = htmlspecialchars($cat['name']);
                            $iconClass = get_category_icon($slug);
                            echo '<a href="/category/games/' . $slug . '" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors text-gray-700">'
                               . '<i class="' . $iconClass . ' text-gray-600 w-4 h-4 text-center"></i>' . $name . '</a>';
                        }
                        ?>
                    </nav>
                </section>
            </div>
        </aside>
    </div>
</main>

<?php
include 'includes/footer.php';
ob_end_flush();
?>
