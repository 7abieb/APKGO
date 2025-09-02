<?php
// suggest.php
header('Content-Type: application/json; charset=utf-8');

// Get the search query from the parameter.
$query = isset($_GET['query']) ? trim($_GET['query']) : '';

// We need at least 2 characters for a meaningful search suggestion
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// --- Helper Functions (Copied for self-containment and consistency with info (4).php) ---

/**
 * Abbreviates large numbers to K, M, B.
 * @param float|int $num The number to abbreviate.
 * @return string The abbreviated number or the original number.
 */
function abbreviate_number($num): string {
    $num = (float)$num;
    if ($num >= 1000000000) return round($num / 1000000000, 1) . 'B';
    if ($num >= 1000000)    return round($num / 1000000, 1) . 'M';
    if ($num >= 1000)       return round($num / 1000, 1) . 'K';
    return (string)$num;
}

/**
 * Parses review count text and abbreviates if it's purely numeric.
 * @param string $text The review text (e.g., "1.2M", "5,000", "1234").
 * @return string Formatted review count.
 */
function parseReviewCount(string $text): string {
    $trimmed = trim(str_replace([',', '.'], '', $text)); // Remove thousands separators for numeric check
    if (preg_match('/[KMB]$/i', trim($text))) {
        return trim($text);
    } elseif (ctype_digit($trimmed)) {
        return abbreviate_number((float)$trimmed);
    }
    return trim($text);
}

/**
 * Optimized review count conversion with type hinting.
 * This function is not strictly needed for suggestions but kept for consistency if other parts of suggest.php were to use it.
 */
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

/**
 * Creates a URL-friendly slug from a string.
 * Handles Unicode characters and common separators.
 * @param string $string Input string.
 * @return string URL slug.
 */
function createSlug(string $string): string {
    $slug = strtolower(trim($string));
    // Replace non-alphanumeric characters (except dash) with dash
    $slug = preg_replace('/[^a-z0-9\p{L}]+/u', '-', $slug); // Added \p{L} for Unicode letters, 'u' modifier for Unicode
    $slug = preg_replace('/-+/', '-', $slug); // Replace multiple dashes with a single dash
    $slug = trim($slug, '-'); // Trim dashes from the beginning and end
    // URL encode the slug to handle non-ASCII characters safely in the URL path
    // Use rawurlencode for path segments
    $slug = rawurlencode($slug);
    return empty($slug) ? 'app' : $slug;
}

/**
 * Extracts the package name (e.g., com.example.app) and potentially a preceding slug
 * from a URL or path fragment. Designed to be robust for various source URL structures.
 * @param string $input The input string (URL or path fragment) from the source.
 * @return array An associative array containing 'slug' and 'package_name'.
 */
function extractSlugAndPackageName(string $input): array {
    $decodedInput = urldecode($input);
    // Parse the URL to get just the path part, handling potential full URLs
    $path = parse_url($decodedInput, PHP_URL_PATH);
    if ($path === null) { // If parse_url failed or input was just a path fragment
        $path = $decodedInput;
    }

    // Split the path by slash and remove empty parts
    $parts = array_filter(explode('/', trim($path, '/')));

    $packageName = '';
    $slug = '';

    // Iterate from the end to find the package name and then the slug before it
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $part = $parts[$i];

        // Check if the current part looks like a package name
        // Criteria: contains at least one dot, no spaces, not a reserved keyword.
        // Use strtolower for comparison against reserved keywords.
        if (strpos($part, '.') !== false && !preg_match('/\s/', $part) && !in_array(strtolower($part), ['versions', 'download', 'related', 'category', 'developer', 'app'])) {
            $packageName = $part;

            // The part before the package name might be the slug
            if ($i > 0) {
                $potentialSlug = $parts[$i - 1];
                 // Check if the potential slug is not a reserved keyword and doesn't look like a package name or domain
                if (!in_array(strtolower($potentialSlug), ['app', 'category', 'developer', 'versions', 'download', 'related']) && strpos($potentialSlug, '.') === false) {
                    $slug = $potentialSlug;
                }
            }
            break; // Found package name, stop searching
        }
    }

    // If package name is still empty, check if the very last part is a package name
    // This handles cases where the URL is just /package.name
    if (empty($packageName) && count($parts) > 0) {
        $lastPart = end($parts);
         if (strpos($lastPart, '.') !== false && !preg_match('/\s/', $lastPart) && !in_array(strtolower($lastPart), ['versions', 'download', 'related', 'category', 'developer', 'app'])) {
             $packageName = $lastPart;
             // If the last part is the package name and there's a part before it, that might be the slug
             if (count($parts) > 1) {
                 $secondLastPart = $parts[count($parts) - 2];
                 if (!in_array(strtolower($secondLastPart), ['app', 'category', 'developer', 'versions', 'download', 'related']) && strpos($secondLastPart, '.') === false) {
                      $slug = $secondLastPart;
                 }
             }
         }
    }
    return ['slug' => $slug, 'package_name' => $packageName];
}


// Extract app data with improved error handling and timeout
function extractApkDataForSuggestions($keyword) {
    // Using a specific search URL for apps/games
    $searchUrl = 'https://apkfab.com/search?q=' . urlencode($keyword);
    $ch = curl_init();

    $curlOptions = [
        CURLOPT_URL => $searchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false, // Consider enabling for production if you have CA certs
        CURLOPT_TIMEOUT => 10, // Shorter timeout for suggestions
        CURLOPT_CONNECTTIMEOUT => 3, // Shorter connect timeout
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: max-age=0',
            'Connection: keep-alive',
            'Referer: https://apkfab.com/' // Referer can sometimes help
        ]
    ];

    curl_setopt_array($ch, $curlOptions);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        // Log error if needed: error_log("cURL error: " . curl_error($ch));
        curl_close($ch);
        return ['error' => 'Connection error', 'apps' => []];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
         // Log error if needed: error_log("HTTP error: " . $httpCode);
        return ['error' => 'Server error (HTTP ' . $httpCode . ')', 'apps' => []];
    }

    try {
        $apps = [];
        $dom = new DOMDocument();
        // Suppress HTML parsing errors
        @$dom->loadHTML($response, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        // XPath query to find app list items - adjusted slightly for robustness
        $appNodes = $xpath->query("//div[contains(@class, 'list-template')]/div[contains(@class, 'list')]") ?:
                   $xpath->query("//div[@class='list']");


        if (!$appNodes || $appNodes->length === 0) {
            return ['apps' => []]; // No apps found
        }

        // Extract data for each app node
        foreach ($appNodes as $appNode) {
            $app = extractAppDataForSuggestions($xpath, $appNode);
            if ($app) {
                $apps[] = $app;
            }
        }

        return ['apps' => $apps];

    } catch (Exception $e) {
         // Log error if needed: error_log("DOM parsing error: " . $e->getMessage());
        return ['error' => 'Failed to process results', 'apps' => []];
    }
}

// Separate function to extract individual app data needed for suggestions
function extractAppDataForSuggestions($xpath, $appNode) {
    $linkNode = $xpath->query(".//a", $appNode)->item(0);
    if (!$linkNode) return null;

    // Get the title
    $appTitle = $linkNode->getAttribute('title');
    if (empty($appTitle)) {
        $titleNode = $xpath->query(".//div[contains(@class, 'title')]", $appNode)->item(0);
        $appTitle = $titleNode ? trim($titleNode->textContent) : '';
    }

    // Get the icon URL
    $iconNode = $xpath->query(".//div[contains(@class, 'icon')]/img", $appNode)->item(0);
    $appIcon = extractIconUrlForSuggestions($iconNode); // Use a dedicated icon extraction

    // *** Get the source app URL (e.g., from apkfab.com) ***
    $sourceAppUrl = $linkNode->getAttribute('href');
    // Prepend base URL if it's a relative path (e.g., /app-name)
    if (!empty($sourceAppUrl) && strpos($sourceAppUrl, 'http') !== 0) {
        $sourceAppUrl = 'https://apkfab.com' . $sourceAppUrl; // Adjust base URL if needed
    }

    // --- Transform apkfab.com URL to yandux.biz URL format ---
    $extractedInfo = extractSlugAndPackageName($sourceAppUrl);
    $slug = $extractedInfo['slug'];
    $packageName = $extractedInfo['package_name'];

    // If we couldn't get a proper package name, or slug, we can't form the direct link
    if (empty($packageName)) {
        return null; // Don't return this suggestion if we can't form a direct link
    }

    // If slug is empty, try to create one from the title
    if (empty($slug)) {
        $slug = createSlug($appTitle ?: $packageName);
    }

    // Construct the direct app URL for your site
    $directAppUrl = '/' . rawurlencode($slug) . '/' . rawurlencode($packageName);


    // Only return if we have a title and a direct app URL
    if (empty($appTitle) || empty($directAppUrl)) return null;

    return [
        'title' => $appTitle,
        'icon' => $appIcon,
        'url' => $directAppUrl // This is the direct app URL for your site
    ];
}

// Helper function specifically for extracting icon URL for suggestions
function extractIconUrlForSuggestions($iconNode) {
    if (!$iconNode) return '';

    // Check common attributes for lazy loading or standard src
    $imgAttributes = ['data-src', 'src', 'data-original', 'data-lazy-src'];
    foreach ($imgAttributes as $attr) {
        if ($iconNode->hasAttribute($attr)) {
            $src = $iconNode->getAttribute($attr);
            // Avoid placeholder images if possible and ensure it's not empty
            if (!empty($src) && strpos($src, 'placeholder') === false) {
                return $src;
            }
        }
    }
    // Fallback to src if no specific data attribute found
    return $iconNode->hasAttribute('src') ? $iconNode->getAttribute('src') : '';
}


// --- Main logic for suggestions ---

// Fetch app data using the scraping logic
$result = extractApkDataForSuggestions($query);

$suggestions = [];
// Check if we got apps and no major error
if (!isset($result['error']) && !empty($result['apps'])) {
    // Take up to the first 8 apps as suggestions
    $suggestions = array_slice($result['apps'], 0, 8);
}

// Output the formatted suggestions as a JSON array.
// The structure now includes {title: ..., icon: ..., url: ...}
echo json_encode($suggestions, JSON_UNESCAPED_UNICODE);

?>
