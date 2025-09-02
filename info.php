<?php

// Error reporting for debugging (consider turning off/down in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Configuration ---
define('USER_DOMAIN', 'Yandux.Biz'); // Define your domain as a constant
define('SOURCE_DOMAIN', 'apkfab.com'); // Source domain for scraping
define('VERSIONS_INITIAL_DISPLAY_COUNT', 5); // Define how many versions to show initially
define('DESCRIPTION_TRUNCATE_LENGTH', 300); // Define the length to truncate the description
define('LOCAL_APP_DATA_DIR', __DIR__ . '/uploads/'); // Directory where local app data is stored
define('SIDEBAR_DEV_APPS_DISPLAY_COUNT', 5); // Number of developer apps to show before a 'view more' link
define('SIDEBAR_RELATED_APPS_DISPLAY_COUNT', 5); // Number of related apps to show initially

// --- Helper Functions ---

/**
 * Safely encodes HTML entities for display. Decodes existing entities first.
 */
function safe_htmlspecialchars(string $string): string {
    return htmlspecialchars(html_entity_decode($string, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

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
 * Cleans and formats app description HTML. Replaces source domain with user domain.
 * @param string $desc Raw description HTML.
 * @return string Processed description HTML.
 */
function processDescription(string $desc): string {
    // Remove specific "read more" links pointing back to the source
    $desc = preg_replace('/<a[^>]+href=["\'][^"\']*' . preg_quote(SOURCE_DOMAIN, '/') . '[^"\']*["\'][^>]*>\s*read more\s*<\/a>/i', '', $desc);
    $desc = preg_replace('/read more/i', '', $desc); // Also remove plain "read more" text
    // Replace links to the source domain with links to the user domain (if applicable, though less common in descriptions)
    $desc = preg_replace('/https?:\/\/' . preg_quote(SOURCE_DOMAIN, '/') . '/i', 'https://' . USER_DOMAIN, $desc);
    // Auto-link plain URLs that are not already links
    $desc = preg_replace_callback('/(?<!href=["\'])(https?:\/\/[^\s"<]+)/i', function($matches) {
        return '<a href="' . $matches[0] . '" target="_blank" rel="nofollow noopener">' . $matches[0] . '</a>';
    }, $desc);
    // Format specific headings like "Editor's Review", "About", "What's New"
    $pattern = '/(<p><strong>)(Editor\'s Review|About|What\'s New)(.*?<\/strong><\/p>)/is'; // Added 's' modifier for dotall
     $desc = preg_replace_callback($pattern, function($match) {
         // Keep the strong tag content but wrap in a styled paragraph
         return '<p class="text-sm font-semibold text-blue-700">' . htmlspecialchars($match[2]) . '</p>' . trim(str_replace($match[1].$match[2].$match[3], '', $match[0]));
     }, $desc);
    return $desc;
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
    return $slug;
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
                 $secondLastPart = $parts[count(array_values($parts)) - 2]; // Use array_values to re-index after filter
                 if (!in_array(strtolower($secondLastPart), ['app', 'category', 'developer', 'versions', 'download', 'related']) && strpos($secondLastPart, '.') === false) {
                      $slug = $secondLastPart;
                 }
             }
         }
    }
    return ['slug' => $slug, 'package_name' => $packageName];
}


/**
 * Makes a URL absolute or relative to the user's domain.
 * Primarily used for assets (icons, screenshots) and external links.
 * Internal links (like related apps, download) are constructed separately.
 * @param string $url The URL fragment or absolute URL from the source.
 * @param string $sourceBaseUrl The base URL of the source website.
 * @param string $userBaseUrl The base URL of the user's website.
 * @return string The processed URL.
 */
function processLink(string $url, string $sourceBaseUrl, string $userBaseUrl): string {
    if (empty($url) || $url === '#') return '';
    $url = trim($url);
    $sourceBaseUrl = rtrim($sourceBaseUrl, '/');
    $sourceHost = parse_url($sourceBaseUrl, PHP_URL_HOST);
    $userHost = parse_url($userBaseUrl, PHP_URL_HOST);


    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        $urlHost = parse_url($url, PHP_URL_HOST);
        // If the URL points to the source domain, make it root-relative
        if ($urlHost === $sourceHost) {
            $path = parse_url($url, PHP_URL_PATH) ?? '/';
            $query = parse_url($url, PHP_URL_QUERY);
            return $path . ($query ? '?' . $query : '');
        } elseif ($urlHost === $userHost) {
             // If the URL already points to the user's domain, ensure it's root-relative
            $path = parse_url($url, PHP_URL_PATH) ?? '/';
            $query = parse_url($url, PHP_URL_QUERY);
            return $path . ($query ? '?' . $query : '');
        }
        else {
            // External link, return as is
            return $url;
        }
    } elseif (strpos($url, '//') === 0) {
         // Protocol-relative URL
         $tempFullUrl = 'https:' . $url; // Assume HTTPS for parsing host
         $urlHost = parse_url($tempFullUrl, PHP_URL_HOST);
         if ($urlHost === $sourceHost || $urlHost === $userHost) {
            // If it points to source or user domain, make it root-relative
            $path = parse_url($tempFullUrl, PHP_URL_PATH) ?? '/';
            $query = parse_url($tempFullUrl, PHP_URL_QUERY);
            return $path . ($query ? '?' . $query : '');
         } else {
             // External protocol-relative link, return as is
             return $url;
         }
    } elseif (strpos($url, '/') === 0) {
        // Already root-relative, return as is
        return $url;
    }
    else {
         // Relative path (e.g., "image.jpg"). Assume relative to source base URL path.
         $sourcePath = parse_url($sourceBaseUrl, PHP_URL_PATH) ?? '';
         return rtrim($sourcePath, '/') . '/' . ltrim($url, '/');
    }
}

/**
 * Constructs the download link for a previous version variant.
 * Uses the provided slug and package name, and includes the SHA1 hash.
 * @param string $slug The slug for the URL.
 * @param string $packageName The package name for the URL.
 * @param string $sha1FromVariant The SHA1 hash of the variant.
 * @return string The constructed download link or empty string.
 */
function constructPreviousVersionDownloadLink(string $slug, string $packageName, string $sha1FromVariant): string {
    if (empty($slug) || empty($packageName) || empty($sha1FromVariant)) {
        // Log missing data for debugging
        error_log("Old version link construction failed: Slug (" . $slug . "), Package (" . $packageName . "), or SHA1 (" . $sha1FromVariant . ") missing.");
        return '';
    }
    // Ensure slug and package name are URL-encoded for the path
    $encodedSlug = rawurlencode($slug);
    $encodedPackageName = rawurlencode($packageName);
    $encodedSha1 = urlencode($sha1FromVariant); // SHA1 goes in query string

    return '/' . $encodedSlug . '/' . $encodedPackageName . '/download?sha1=' . $encodedSha1;
}

/**
 * Loads app details from a local metadata.json file if it exists.
 * @param string $packageName The package name of the app.
 * @return array|null Returns an array of app details if metadata.json is found and valid, otherwise null.
 */
function loadLocalAppDetails(string $packageName): ?array {
    $safePackageName = preg_replace('/[^a-zA-Z0-9\._-]+/', '', $packageName);
    $metadataFilePath = LOCAL_APP_DATA_DIR . $safePackageName . '/metadata.json';

    if (file_exists($metadataFilePath)) {
        $metadataContent = file_get_contents($metadataFilePath);
        if ($metadataContent === false) {
            error_log("Failed to read local metadata file: " . $metadataFilePath);
            return null;
        }
        $metadata = json_decode($metadataContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Failed to decode local metadata JSON: " . json_last_error_msg());
            return null;
        }

        // Validate essential fields and structure for display
        if (isset($metadata['app_name'], $metadata['package_name'], $metadata['app_version'], $metadata['sdk_requirement'], $metadata['file_size'], $metadata['uploaded_filename'])) {
            $localAppDetails = [
                'name' => $metadata['app_name'],
                'package_name' => $metadata['package_name'],
                'version' => $metadata['app_version'],
                'requirements' => $metadata['sdk_requirement'],
                'description' => safe_htmlspecialchars($metadata['app_description'] ?? ''), // Use safe_htmlspecialchars for description
                'file_size' => $metadata['file_size'],
                'uploaded_filename' => $metadata['uploaded_filename'],
                'icon' => $metadata['app_icon_url'] ?? '', // This would be the relative path, e.g., /uploads/com.pkg/icons/icon.png
                'update_date' => $metadata['upload_date'] ?? 'N/A',
                'slug' => $metadata['generated_slug'] ?? createSlug($metadata['app_name']), // Use generated slug or create one
                'is_local_upload' => true, // Flag for internal use
                // Other fields that are not part of local upload but needed for appDetails structure
                'error' => null,
                'rating' => '',
                'reviews' => '',
                'reviews_parsed' => '',
                'developer' => 'User Upload', // Default developer for local uploads
                'developer_link' => '',
                'price' => '0',
                'price_currency' => '',
                'screenshots' => [], // No screenshots from local upload
                'more_info' => [
                    'Package Name' => ['text' => $metadata['package_name'], 'link' => ''],
                    'Size' => ['text' => $metadata['file_size'], 'link' => ''],
                    'Version' => ['text' => $metadata['app_version'], 'link' => ''],
                    'Requirements' => ['text' => $metadata['sdk_requirement'], 'link' => ''],
                    'Update Date' => ['text' => $metadata['upload_date'] ?? 'N/A', 'link' => ''],
                    'Offered By' => ['text' => 'User Upload', 'link' => ''], // Display user upload as developer
                ],
                'related_apps' => [], // No related apps from local upload
                'developer_apps' => [], // No developer apps from local upload
                'matched_sha1' => 'N/A', // SHA1 not extracted from local APK
                'matched_arch' => 'N/A',
                'play_store_link' => 'https://play.google.com/store/apps/details?id=' . urlencode($metadata['package_name']),
            ];

            // If a local icon URL exists, ensure it's relative
            if (!empty($localAppDetails['icon']) && strpos($localAppDetails['icon'], '/') !== 0) {
                 $localAppDetails['icon'] = '/' . ltrim(str_replace(__DIR__ . '/', '', LOCAL_APP_DATA_DIR . $safePackageName . '/icons/icon.png'), '/');
            }


            return $localAppDetails;
        } else {
            error_log("Local metadata file is missing essential fields for package: " . $packageName);
        }
    }
    return null;
}

function extractAppDetails(string $appUrl, string $sourceBaseUrl, string $userBaseUrl): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $appUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9', 'Cache-Control: no-cache', 'Pragma: no-cache',
            'Sec-Fetch-Dest: document', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-User: ?1', 'Upgrade-Insecure-Requests: 1', 'Referer: ' . $sourceBaseUrl . '/'
        ]
    ]);
    $response = curl_exec($ch);
    $curlError = curl_errno($ch) ? curl_error($ch) : null;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) return ['error' => 'Network error.'];
    if ($httpCode !== 200) return ['error' => 'App not found ('.$httpCode.').'];
    if (empty($response)) return ['error' => 'Empty response.'];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!@$dom->loadHTML($response)) return ['error' => 'Parse error.'];
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $appDetails = ['error' => null];

    $detailsContainer = $xpath->query("//div[contains(@class, 'detail_banner')] | //div[contains(@class, 'app_info')] | //section[contains(@class, 'head-widget')]")->item(0);
    $nameNode = $xpath->query(".//h1", $detailsContainer)->item(0) ?? $xpath->query(".//div[@class='title']/h1", $detailsContainer)->item(0);
    $appDetails['name'] = $nameNode ? trim($nameNode->textContent) : '';
    $iconNode = $xpath->query(".//img[contains(@class, 'icon')]", $detailsContainer)->item(0) ?? $xpath->query(".//div[@class='icon']/img", $detailsContainer)->item(0);
    $iconSrc = $iconNode ? ($iconNode->getAttribute('data-src') ?: $iconNode->getAttribute('src')) : '';
    $appDetails['icon'] = $iconSrc ? processLink($iconSrc, $sourceBaseUrl, $userBaseUrl) : '';
    $ratingNode = $xpath->query("//span[contains(@class, 'rating')]//span[contains(@class, 'star_icon')] | //div[@class='stars']/@data-rating")->item(0);
    $appDetails['rating'] = $ratingNode ? trim($ratingNode->textContent) : '';
    $reviewNode = $xpath->query("//span[contains(@class, 'review_icon')] | //a[@href='#reviews']/span[contains(@class,'num')]")->item(0);
    $appDetails['reviews'] = $reviewNode ? trim($reviewNode->textContent) : '';
    $versionNode = $xpath->query(".//span[contains(@style, 'color: #0284fe')]", $detailsContainer)->item(0) ?? $xpath->query(".//span[contains(text(), 'Version:')]", $detailsContainer)->item(0) ?? $xpath->query("//dt[text()='Version']/following-sibling::dd[1]")->item(0) ?? $xpath->query("//div[contains(@class, 'detail_more_info')]//p[contains(text(), 'Latest Version:')]/following-sibling::p")->item(0);
    $appDetails['version'] = $versionNode ? trim(str_replace(['Version:', 'Latest Version:'], '', $versionNode->textContent)) : '';

    // --- Developer Info Extraction (Improved) ---
    $developerNode = $xpath->query(".//span[@itemprop='publisher']", $detailsContainer)->item(0) ?? $xpath->query(".//a[contains(@class, 'developers')]/span", $detailsContainer)->item(0) ?? $xpath->query(".//a[contains(@href, '/developer/')]/span", $detailsContainer)->item(0) ?? $xpath->query("//dt[text()='Developer']/following-sibling::dd[1]/a")->item(0) ?? $xpath->query("//div[contains(@class, 'detail_more_info')]//p[contains(text(), 'Offered By:')]/following-sibling::p/a")->item(0);
    $appDetails['developer'] = $developerNode ? trim($developerNode->textContent) : '';
    
    $developerLinkNode = ($developerNode instanceof DOMElement && strtolower($developerNode->tagName) === 'a') ? $developerNode : ($developerNode ? $developerNode->parentNode : null);
    $developerHref = ($developerLinkNode instanceof DOMElement && strtolower($developerLinkNode->tagName) === 'a') ? $developerLinkNode->getAttribute('href') : '';
    
    if (!empty($appDetails['developer'])) {
        // If we have a developer name, always construct our own link.
        $appDetails['developer_link'] = '/developer/' . rawurlencode($appDetails['developer']);
    } elseif (!empty($developerHref)) {
        // Fallback if name extraction failed but a link was found
        $appDetails['developer_link'] = processLink($developerHref, $sourceBaseUrl, $userBaseUrl);
    } else {
        $appDetails['developer_link'] = '';
    }

    $packageNode = $xpath->query("//div[contains(@class, 'detail_more_info')]//p[contains(text(), 'Package Name:')]/following-sibling::p")->item(0) ?? $xpath->query("//dt[text()='Package Name']/following-sibling::dd[1]")->item(0);
    $appDetails['package_name'] = $packageNode ? trim($packageNode->textContent) : ''; // Fixed: was $packageNameNode
    $priceContentNode = $xpath->query(".//div[contains(@class, 'new_detail_price')]//meta[@itemprop='price']/@content", $detailsContainer)->item(0);
    $appDetails['price'] = $priceContentNode ? trim($priceContentNode->nodeValue) : '';
    $priceCurrencyNode = $xpath->query(".//div[contains(@class, 'new_detail_price')]//meta[@itemprop='priceCurrency']/@content", $detailsContainer)->item(0);
    $appDetails['price_currency'] = $priceCurrencyNode ? trim($priceCurrencyNode->nodeValue) : '';
    if (empty($appDetails['price'])) {
        $priceTextNode = $xpath->query(".//div[contains(@class, 'new_detail_price')]//span[contains(@class, 'price')]", $detailsContainer)->item(0);
        if ($priceTextNode) {
            $rawPriceText = trim($priceTextNode->textContent);
            if (preg_match('/(Price:)?\s*([$€£¥₹])?(\d+(\.\d+)?)/i', $rawPriceText, $matches)) {
                 $appDetails['price'] = $matches[3];
                 if (empty($appDetails['price_currency']) && !empty($matches[2])) {
                     switch ($matches[2]) {
                         case '$': $appDetails['price_currency'] = 'USD'; break; case '€': $appDetails['price_currency'] = 'EUR'; break;
                         case '£': $appDetails['price_currency'] = 'GBP'; break; case '¥': $appDetails['price_currency'] = 'JPY'; break;
                         case '₹': $appDetails['price_currency'] = 'INR'; break; default: $appDetails['price_currency'] = $matches[2];
                     }
                 }
            } elseif (trim(strtolower($rawPriceText)) === 'free') {
                 $appDetails['price'] = '0'; $appDetails['price_currency'] = '';
            }
        }
    }
    if (empty($appDetails['price']) || trim(strtolower($appDetails['price'])) === 'free') {
        $appDetails['price'] = '0'; $appDetails['price_currency'] = '';
    }
    $descriptionNode = $xpath->query("//div[contains(@class, 'description')]/div[contains(@class, 'content')] | //div[@itemprop='description'] | //div[contains(@class, 'description_wrap')]")->item(0);
    $descriptionHtml = '';
    if ($descriptionNode) foreach ($descriptionNode->childNodes as $child) $descriptionHtml .= $dom->saveHTML($child);
    $appDetails['description'] = processDescription(trim($descriptionHtml));
    $screenshots = [];
    $screenshotContainer = $xpath->query("//div[contains(@class, 'screenshot')] | //div[contains(@class, 'screenshots')] | //div[contains(@class, 'app_screenshots')]")->item(0);
    if ($screenshotContainer) {
        $screenshotNodes = $xpath->query(".//img", $screenshotContainer);
        if ($screenshotNodes) foreach ($screenshotNodes as $img) {
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
            if (!empty($src) && strpos($src, 'data:image') !== 0) $screenshots[] = processLink($src, $sourceBaseUrl, $userBaseUrl);
        }
    }
    $appDetails['screenshots'] = $screenshots;
    $moreInfoItems = [];
    $infoNodesDL = $xpath->query("//div[contains(@class, 'detail_more_info')]//dl/dt | //div[@class='details-section-contents']//div[@class='meta-info']/div[@class='title']");
    $infoNodesP = $xpath->query("//div[contains(@class, 'detail_more_info')]//div[contains(@class, 'item')]/p[1] | //div[contains(@class, 'app-info')]//div[contains(@class, 'info')]/p[1]");
    if ($infoNodesDL && $infoNodesDL->length > 0) {
        foreach ($infoNodesDL as $labelNode) {
            $label = trim(rtrim($labelNode->textContent, ':')); $valueNode = $xpath->query("./following-sibling::dd[1] | ./following-sibling::div[@class='description'][1]", $labelNode)->item(0);
            $valueText = $valueNode ? trim($valueNode->textContent) : ''; $linkNode = $valueNode ? $xpath->query(".//a", $valueNode)->item(0) : null;
            $link = $linkNode ? $linkNode->getAttribute('href') : '';
            if (!empty($label)) {
                // Ensure Google Play link is always direct if package name is available
                if (strtolower($label) === 'google play' && !empty($appDetails['package_name'])) {
                    $moreInfoItems[$label] = ['text' => 'View on Google Play', 'link' => 'https://play.google.com/store/apps/details?id=' . urlencode($appDetails['package_name'])];
                } else {
                    $processedLink = $link ? processLink($link, $sourceBaseUrl, $userBaseUrl) : '';
                    if ($linkNode && (empty($valueText) || trim($valueText) === trim($linkNode->textContent))) $valueText = trim($linkNode->textContent);
                    if (!isset($moreInfoItems[$label]) || (empty($moreInfoItems[$label]['link']) && !empty($processedLink))) $moreInfoItems[$label] = ['text' => $valueText, 'link' => $processedLink];
                }
            }
        }
    } elseif ($infoNodesP && $infoNodesP->length > 0) {
         foreach ($infoNodesP as $labelNode) {
             $parentNode = $labelNode->parentNode; $label = trim(str_replace(':', '', $labelNode->textContent));
             $valueNode = $xpath->query("./following-sibling::p[1]", $labelNode)->item(0) ?? $xpath->query("p[2]", $parentNode)->item(0);
             $valueText = $valueNode ? trim($valueNode->textContent) : ''; $linkNode = $valueNode ? $xpath->query(".//a", $valueNode)->item(0) : null;
             $link = $linkNode ? $linkNode->getAttribute('href') : '';
              if (!empty($label)) {
                  // Ensure Google Play link is always direct if package name is available
                  if (strtolower($label) === 'google play' && !empty($appDetails['package_name'])) {
                      $moreInfoItems[$label] = ['text' => 'View on Google Play', 'link' => 'https://play.google.com/store/apps/details?id=' . urlencode($appDetails['package_name'])];
                  } else {
                      $processedLink = $link ? processLink($link, $sourceBaseUrl, $userBaseUrl) : '';
                      if ($linkNode && (empty($valueText) || trim($valueText) === trim($linkNode->textContent))) $valueText = trim($linkNode->textContent);
                     if (!isset($moreInfoItems[$label]) || (empty($moreInfoItems[$label]['link']) && !empty($processedLink))) $moreInfoItems[$label] = ['text' => $valueText, 'link' => $processedLink];
                  }
             }
         }
    }
     foreach ($moreInfoItems as $label => $info) {
         $cleanedLabel = trim(strtolower($label));
         switch ($cleanedLabel) {
             case 'package name': if (empty($appDetails['package_name'])) $appDetails['package_name'] = $info['text']; break;
             case 'category':
                 if (empty($appDetails['category']) || (!empty($info['text']) && empty($appDetails['category_link']) && !empty($info['link']))) {
                     $appDetails['category'] = $info['text'];
                     $processedCategoryLink = $info['link'];
                     $appDetails['category_link'] = $processedCategoryLink;
                 }
                 break;
             case 'update date': case 'updated': if (empty($appDetails['update_date'])) $appDetails['update_date'] = $info['text']; break;
             case 'latest version': case 'version': if (empty($appDetails['version'])) $appDetails['version'] = $info['text']; break;
             case 'requirements': if (empty($appDetails['requirements'])) $appDetails['requirements'] = $info['text']; break;
             case 'installs': if (empty($appDetails['installs'])) $appDetails['installs'] = $info['text']; break;
             case 'content rating': if (empty($appDetails['content_rating'])) $appDetails['content_rating'] = $info['text']; break;
             case 'offered by': case 'developer': if (empty($appDetails['developer']) || (!empty($info['text']) && empty($appDetails['developer_link']) && !empty($info['link']))) { $appDetails['developer'] = $info['text']; $appDetails['developer_link'] = $info['link']; } break;
             case 'size': if (empty($appDetails['size'])) $appDetails['size'] = $info['text']; break;
             case 'google play':
                 // Always ensure the Google Play link is direct to play.google.com
                 if (!empty($appDetails['package_name'])) {
                     $appDetails['play_store_link'] = 'https://play.google.com/store/apps/details?id=' . urlencode($appDetails['package_name']);
                 } else {
                     if (strpos($info['link'], 'play.google.com') !== false) {
                         $appDetails['play_store_link'] = $info['link'];
                     } else {
                         $appDetails['play_store_link'] = ''; // Clear if it's not a direct Play Store link
                     }
                 }
                 break;
         }
     }
    $appDetails['more_info'] = $moreInfoItems;
    global $packageName;
    if (empty($appDetails['package_name']) && !empty($packageName)) {
         $appDetails['package_name'] = $packageName;
         if (!isset($appDetails['more_info']['Package Name']) || empty($appDetails['more_info']['Package Name']['text'])) $appDetails['more_info']['Package Name'] = ['text' => $packageName, 'link' => ''];
    }
    global $urlSlug;
    $appDetails['slug'] = !empty($urlSlug) ? $urlSlug : createSlug($appDetails['name'] ?: $appDetails['package_name']);
    if (empty($appDetails['slug']) && (!empty($appDetails['name']) || !empty($appDetails['package_name']))) {
        $appDetails['slug'] = 'app';
    }

    $relatedApps = [];
    $relatedContainer = $xpath->query("//div[contains(@class, 'detail_related')][not(.//div[contains(@class, 'title')][contains(., 'Developer')])]")->item(0);
    $appDetails['related_title'] = $relatedContainer ? trim($xpath->query(".//div[contains(@class, 'title')] | .//h3", $relatedContainer)->item(0)->textContent ?? 'You May Also Like') : 'You May Also Like';
    if ($relatedContainer) {
        $relatedNodes = $xpath->query(".//a[contains(@class, 'item')] | .//li/a | .//div[contains(@class, 'card')]/a", $relatedContainer);
        if ($relatedNodes) foreach ($relatedNodes as $node) {
            $relatedApp = []; $relatedUrl = $node->getAttribute('href');
            if (empty($relatedUrl) || $relatedUrl === '#') continue;
            $relatedExtractedInfo = extractSlugAndPackageName($relatedUrl);
            $relatedApp['id'] = $relatedExtractedInfo['package_name'];
            if (empty($relatedApp['id'])) continue;
            $relatedApp['title'] = $node->getAttribute('title') ?: trim($xpath->query(".//div[contains(@class, 'text')]/p[1] | .//div[contains(@class, 'title')] | .//span[@class='title']", $node)->item(0)->textContent ?? '');
            if (empty($relatedApp['title'])) $relatedApp['title'] = $relatedApp['id'];
            $relatedApp['slug'] = !empty($relatedExtractedInfo['slug']) ? $relatedExtractedInfo['slug'] : (createSlug($relatedApp['title']) ?: 'app');
            $iconNode = $xpath->query(".//div[contains(@class, 'icon')]/img | .//img[contains(@class, 'cover-image')]", $node)->item(0);
            $iconSrc = $iconNode ? ($iconNode->getAttribute('data-src') ?: $iconNode->getAttribute('src')) : '';
            $relatedApp['icon'] = $iconSrc ? processLink($iconSrc, $sourceBaseUrl, $userBaseUrl) : '';
            $descNode = $xpath->query(".//div[contains(@class, 'text')]/p[2] | .//div[contains(@class, 'description')] | .//div[contains(@class, 'subtitle')]", $node)->item(0);
            $relatedApp['description'] = $descNode ? trim($descNode->textContent) : '';
            if (empty($relatedApp['description'])) {
                $descNodeAlt = $xpath->query(".//div[contains(@class, 'text')]/p[1] | .//div[contains(@class, 'category')]", $node)->item(0);
                if ($descNodeAlt && trim($descNodeAlt->textContent) !== $relatedApp['title']) $relatedApp['description'] = trim($descNodeAlt->textContent);
            }
            $ratingNode = $xpath->query(".//span[contains(@class, 'star_icon')] | .//div[@class='stars']/@data-rating | .//span[@class='num-ratings']", $node)->item(0);
            $relatedApp['rating'] = $ratingNode ? trim($ratingNode->textContent) : '';
            $reviewNode = $xpath->query(".//span[contains(@class, 'review_icon')] | .//span[@class='num-ratings']", $node)->item(0);
            $relatedApp['reviews'] = $reviewNode ? trim($reviewNode->textContent) : '';
            $relatedApp['link'] = '/' . rawurlencode($relatedApp['slug']) . '/' . rawurlencode($relatedApp['id']);
            $relatedApps[] = $relatedApp;
        }
    }
    $appDetails['related_apps'] = $relatedApps;

    $developerApps = [];
    $devAppsContainer = $xpath->query("//div[contains(@class, 'related')][.//div[contains(@class, 'title') or contains(@class, 'h3')][contains(., 'Developer')]]")->item(0);
    if ($devAppsContainer) {
        $devAppNodes = $xpath->query(".//a[contains(@class, 'item')] | .//li/a | .//div[contains(@class, 'card')]/a", $devAppsContainer);
        if ($devAppNodes) foreach ($devAppNodes as $node) {
            $devApp = []; $devAppUrl = $node->getAttribute('href');
            if (empty($devAppUrl) || $devAppUrl === '#') continue;
            $devAppExtractedInfo = extractSlugAndPackageName($devAppUrl);
            $devApp['id'] = $devAppExtractedInfo['package_name'];
            if (empty($devApp['id'])) continue;
            $devApp['title'] = $node->getAttribute('title') ?: trim($xpath->query(".//div[contains(@class, 'text')]/p[1] | .//div[contains(@class, 'title')] | .//span[@class='title']", $node)->item(0)->textContent ?? '');
            if (empty($devApp['title'])) $devApp['title'] = $devApp['id'];
            $devApp['slug'] = !empty($devAppExtractedInfo['slug']) ? $devAppExtractedInfo['slug'] : (createSlug($devApp['title']) ?: 'app');
            $iconNode = $xpath->query(".//div[contains(@class, 'icon')]/img | .//img[contains(@class, 'cover-image')]", $node)->item(0);
            $iconSrc = $iconNode ? ($iconNode->getAttribute('data-src') ?: $iconNode->getAttribute('src')) : '';
            $devApp['icon'] = $iconSrc ? processLink($iconSrc, $sourceBaseUrl, $userBaseUrl) : '';
            $descNode = $xpath->query(".//div[contains(@class, 'text')]/p[2] | .//div[contains(@class, 'description')] | .//div[contains(@class, 'subtitle')]", $node)->item(0);
            $devApp['description'] = $descNode ? trim($descNode->textContent) : '';
            $ratingNode = $xpath->query(".//span[contains(@class, 'star_icon')] | .//div[@class='stars']/@data-rating | .//span[@class='num-ratings']", $node)->item(0);
            $devApp['rating'] = $ratingNode ? trim($ratingNode->textContent) : '';
            $reviewNode = $xpath->query(".//span[contains(@class, 'review_icon')] | .//span[@class='num-ratings']", $node)->item(0);
            $devApp['reviews'] = $reviewNode ? trim($reviewNode->textContent) : '';
            $devApp['link'] = '/' . rawurlencode($devApp['slug']) . '/' . rawurlencode($devApp['id']);
            $developerApps[] = $devApp;
        }
    }
    $appDetails['developer_apps'] = $developerApps;

    if (empty($appDetails['name']) || empty($appDetails['package_name'])) {
        if (empty($appDetails['name'])) $appDetails['error'] = $appDetails['error'] ?? 'Failed to parse essential app details.';
    }
    return $appDetails;
}

function fetchDownloadInfo(string $appBaseUrl, string $sourceBaseUrl): array {
    $downloadPageUrl = rtrim($appBaseUrl, '/') . '/download';
    $info = ['file_size' => '', 'file_type' => '', 'sha1' => '', 'download_link_href' => ''];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $downloadPageUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9', 'Cache-Control: no-cache', 'Pragma: no-cache',
            'Sec-Fetch-Dest: document', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-User: ?1', 'Upgrade-Insecure-Requests: 1', 'Referer: ' . $appBaseUrl
        ]
    ]);
    $html = curl_exec($ch); $curlError = curl_errno($ch) ? curl_error($ch) : null; $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($curlError || $httpCode >= 400 || empty($html)) return [];
    $dom = new DOMDocument(); libxml_use_internal_errors(true);
    if (!@$dom->loadHTML($html)) return [];
    libxml_clear_errors(); $xpath = new DOMXPath($dom);
    $button = $xpath->query("//a[contains(@class, 'down_btn') and (@data-dt-file-size or @data-dt-file-type)] | //a[@id='download_link'] | //a[contains(@class,'download-btn') and (@data-dt-file-size or @data-dt-file-type)]")->item(0);
    $sha1Node = $xpath->query("//p[contains(strong, 'SHA1:')]/text()[normalize-space()]")->item(0);
    if ($button) {
         $info['file_size'] = trim($button->getAttribute('data-dt-file-size'));
         $info['file_type'] = trim(strtoupper($button->getAttribute('data-dt-file-type')));
         $info['download_link_href'] = $button->getAttribute('href');
         if (empty($info['file_size']) || empty($info['file_type'])) {
             $buttonText = trim($button->textContent);
             if (preg_match('/Download\s+(APK|XAPK)\s*\(?\s*([^\)]+)\s*\)?/i', $buttonText, $matches)) {
                 if (empty($info['file_type'])) $info['file_type'] = strtoupper($matches[1]);
                 if (empty($info['file_size'])) $info['file_size'] = trim($matches[2]);
             }
             if (empty($info['file_type'])) {
                 if (stripos($buttonText, 'XAPK') !== false) $info['file_type'] = 'XAPK';
                 elseif (stripos($buttonText, 'APK') !== false) $info['file_type'] = 'APK';
             }
         }
    }
    if ($sha1Node) $info['sha1'] = trim($sha1Node->textContent);
    else if (!empty($info['download_link_href'])) {
        $query = parse_url($info['download_link_href'], PHP_URL_QUERY);
        if ($query) { parse_str($query, $params);
            if (isset($params['h']) && !empty($params['h'])) $info['sha1'] = $params['h'];
            elseif (isset($params['sha1']) && !empty($params['sha1'])) $info['sha1'] = $params['sha1'];
        }
    }
    if (empty($info['file_type'])) $info['file_type'] = 'APK';
    if (!empty($info['sha1']) || !empty($info['download_link_href'])) return $info;
    return [];
}

function extractPreviousVersions(string $appBaseUrl, string $sourceBaseUrl, string $userSlug, string $userPackageName): array {
    $versionsPageUrl = rtrim($appBaseUrl, '/') . '/versions'; $previousVersions = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $versionsPageUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9', 'Cache-Control: no-cache', 'Pragma: no-cache',
            'Sec-Fetch-Dest: document', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-User: ?1', 'Upgrade-Insecure-Requests: 1', 'Referer: ' . $appBaseUrl
        ]
    ]);
    $html = curl_exec($ch); $curlError = curl_errno($ch) ? curl_error($ch) : null; $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($curlError) return ['error' => 'Network error (versions).'];
    if ($httpCode >= 400) { if ($httpCode === 404) return []; return ['error' => 'Could not fetch versions ('.$httpCode.').']; }
    if (empty($html)) return ['error' => 'Empty response (versions).'];
    $dom = new DOMDocument(); libxml_use_internal_errors(true);
    if (!@$dom->loadHTML($html)) return ['error' => 'Parse error (versions).'];
    libxml_clear_errors(); $xpath = new DOMXPath($dom);
    $versionHistoryContainer = $xpath->query("//div[contains(@class, 'version_history')]")->item(0);
    if (!$versionHistoryContainer) return [];
    $versionListNodes = $xpath->query(".//div[contains(@class, 'list')]", $versionHistoryContainer);
    if ($versionListNodes) foreach ($versionListNodes as $listNode) {
        $versionData = ['version' => '', 'date' => '', 'size' => '', 'type' => '', 'bundle_type_badge_text' => '', 'whats_new' => '', 'variants' => []];
        $packageInfoNode = $xpath->query(".//div[contains(@class, 'package_info')]", $listNode)->item(0);
        if ($packageInfoNode) {
            $versionNode = $xpath->query(".//span[contains(@class, 'version')]", $packageInfoNode)->item(0);
            $versionData['version'] = $versionNode ? trim($versionNode->textContent) : '';
            
            $textNodes = $xpath->query(".//div[contains(@class, 'text')]/span", $packageInfoNode);
            $dateNode = $textNodes->item(0);
            $sizeNode = $textNodes->item(1);

            if ($dateNode) { $versionData['date'] = trim($dateNode->textContent); }
            if ($sizeNode) { $versionData['size'] = trim($sizeNode->textContent); }

            if ($xpath->query(".//span[contains(@class, 'xapk')]", $packageInfoNode)->length > 0) $versionData['type'] = 'XAPK';
            elseif ($xpath->query(".//span[contains(@class, 'apk')]", $packageInfoNode)->length > 0) $versionData['type'] = 'APK';
            if (empty($versionData['type'])) {
                $sizeText = $sizeNode ? trim($sizeNode->textContent) : '';
                if (stripos($sizeText, 'XAPK') !== false) $versionData['type'] = 'XAPK'; elseif (stripos($sizeText, 'APK') !== false) $versionData['type'] = 'APK';
            }
            $bundleTypeBadgeNode = $xpath->query(".//span[contains(@class, 'obb')]", $packageInfoNode)->item(0);
            $versionData['bundle_type_badge_text'] = $bundleTypeBadgeNode ? trim($bundleTypeBadgeNode->textContent) : ''; // Fixed null check
        }
        $infoBoxNode = $xpath->query(".//div[contains(@class, 'info-fix')]/div[contains(@class, 'info_box')]", $listNode)->item(0);
        if ($infoBoxNode) {
            $whatsNewNode = $xpath->query(".//div[contains(@class, 'whats_new')]", $infoBoxNode)->item(0);
            if ($whatsNewNode) { $whatsNewHtml = ''; foreach ($whatsNewNode->childNodes as $child) $whatsNewHtml .= $dom->saveHTML($child); $versionData['whats_new'] = trim($whatsNewHtml); }
            $variantRows = $xpath->query(".//div[contains(@class, 'table')]/div[contains(@class, 'table-row')][not(contains(@class,'table-head'))]", $infoBoxNode);
            if ($variantRows && $variantRows->length > 0) foreach ($variantRows as $row) {
                $cells = $xpath->query("./div[contains(@class, 'table-cell')]", $row);
                if ($cells->length >= 5) {
                    $variant = ['variant_id' => '', 'date' => $versionData['date'], 'arch' => '', 'android_req' => '', 'dpi' => '', 'download_link' => '', 'type' => $versionData['type'] ?: 'Unknown', 'size' => $versionData['size'], 'sha1' => '', 'base_apk' => '', 'split_apks' => ''];
                    $cell0 = $cells->item(0); $popupNode = $xpath->query(".//div[contains(@class, 'popup')]/p", $cell0);
                    if ($popupNode->length >= 2) { 
                        $variantIdNode = $popupNode->item(0);
                        $variantDateNode = $popupNode->item(1);
                        $variant['variant_id'] = $variantIdNode ? trim($variantIdNode->textContent) : '';
                        $variant['date'] = $variantDateNode ? trim($variantDateNode->textContent) : $versionData['date'];
                    }
                    $verInfoNode = $xpath->query(".//div[contains(@class, 'ver-info')]", $cell0)->item(0);
                    if ($verInfoNode) {
                        $sha1Node = $xpath->query(".//p[contains(strong, 'SHA1:')]/text()[normalize-space()]", $verInfoNode)->item(0);
                        $variant['sha1'] = $sha1Node ? trim($sha1Node->textContent) : '';
                        
                        $sizeNode = $xpath->query(".//p[contains(strong, 'Size:')]/text()[normalize-space()]", $verInfoNode)->item(0);
                        $variant['size'] = $sizeNode ? trim($sizeNode->textContent) : $versionData['size'];
                        
                        $baseApkNode = $xpath->query(".//p[contains(strong, 'Base APK:')]/text()[normalize-space()]", $verInfoNode)->item(0);
                        $variant['base_apk'] = $baseApkNode ? trim($baseApkNode->textContent) : '';
                        
                        $splitApksNode = $xpath->query(".//p[contains(strong, 'Split APKs:')]/text()[normalize-space()]", $verInfoNode)->item(0);
                        $variant['split_apks'] = $splitApksNode ? trim($splitApksNode->textContent) : '';
                    }
                    $archCell = $cells->item(1);
                    $androidReqCell = $cells->item(2);
                    $dpiCell = $cells->item(3);

                    $variant['arch'] = $archCell ? trim($archCell->textContent) : ''; 
                    $variant['android_req'] = $androidReqCell ? trim($androidReqCell->textContent) : ''; 
                    $variant['dpi'] = $dpiCell ? trim($dpiCell->textContent) : '';

                    $sourceHref = ''; $downloadLinkNode = $xpath->query(".//a[contains(@class, 'down_text') or contains(@class, 'down-button')]", $cells->item(4))->item(0);
                    if ($downloadLinkNode) { $sourceHref = $downloadLinkNode->getAttribute('href'); $linkText = trim($downloadLinkNode->textContent);
                        if (empty($variant['type']) || $variant['type'] === 'Unknown') { if (stripos($linkText, 'XAPK') !== false) $variant['type'] = 'XAPK'; elseif (stripos($linkText, 'APK') !== false) $variant['type'] = 'APK'; }
                    }
                    if (empty($variant['sha1']) && !empty($sourceHref)) {
                        $query = parse_url($sourceHref, PHP_URL_QUERY); if ($query) { parse_str($query, $params);
                            if (isset($params['h']) && !empty($params['h'])) $variant['sha1'] = $params['h']; elseif (isset($params['sha1']) && !empty($params['sha1'])) $variant['sha1'] = $params['sha1'];
                        }
                    }
                    $variant['download_link'] = constructPreviousVersionDownloadLink($userSlug, $userPackageName, $variant['sha1']);
                    if (!empty($variant['download_link'])) $versionData['variants'][] = $variant;
                }
            } else {
                $apkDownloadNode = $xpath->query(".//div[contains(@class, 'v_h_button')]/a[contains(@class, 'down')]", $listNode)->item(0);
                if ($apkDownloadNode) {
                    $archNode = $xpath->query(".//div[contains(@class, 'info_box')]//p[contains(strong, 'Architecture:')]/text()[normalize-space()]", $listNode)->item(0);
                    $androidReqNode = $xpath->query(".//div[contains(@class, 'info_box')]//p[contains(strong, 'Requires Android:')]/text()[normalize-space()]", $listNode)->item(0);
                    $dpiNode = $xpath->query(".//div[contains(@class, 'info_box')]//p[contains(strong, 'Screen DPI:')]/text()[normalize-space()]", $listNode)->item(0);
                    $sha1Node = $xpath->query(".//div[contains(@class, 'info_box')]//p[contains(strong, 'SHA1:')]/text()[normalize-space()]", $listNode)->item(0);
                    $sizeNode = $xpath->query(".//div[contains(@class, 'info_box')]//p[contains(strong, 'Size:')]/text()[normalize-space()]", $listNode)->item(0);

                    $variant = [
                        'variant_id' => 'N/A', 
                        'date' => $versionData['date'], 
                        'arch' => $archNode ? trim($archNode->textContent) : 'N/A', 
                        'android_req' => $androidReqNode ? trim($androidReqNode->textContent) : 'N/A', 
                        'dpi' => $dpiNode ? trim($dpiNode->textContent) : 'N/A', 
                        'download_link' => '', 
                        'type' => $versionData['type'] ?: 'APK', 
                        'sha1' => $sha1Node ? trim($sha1Node->textContent) : '', 
                        'size' => $sizeNode ? trim($sizeNode->textContent) : $versionData['size'], 
                        'base_apk' => '', 
                        'split_apks' => ''
                    ];
                    $sourceHref = $apkDownloadNode->getAttribute('href');
                    if (empty($variant['sha1']) && !empty($sourceHref)) {
                        $query = parse_url($sourceHref, PHP_URL_QUERY); if ($query) { parse_str($query, $params);
                            if (isset($params['h']) && !empty($params['h'])) $variant['sha1'] = $params['h']; elseif (isset($params['sha1']) && !empty($params['sha1'])) $variant['sha1'] = $params['sha1'];
                        }
                    }
                    $variant['download_link'] = constructPreviousVersionDownloadLink($userSlug, $userPackageName, $variant['sha1']);
                    if (!empty($variant['download_link'])) $versionData['variants'][] = $variant;
                }
            }
        }
        if (!empty($versionData['version']) && !empty($versionData['variants'])) $previousVersions[] = $versionData;
    }
    return $previousVersions;
}

/**
 * Normalizes a string by removing whitespace and converting to lowercase.
 * @param string $str The string to normalize.
 * @return string The normalized string.
 */
function normalize_string(string $str): string {
    return preg_replace('/\s+/', '', strtolower($str));
}

/**
 * Normalizes an Android requirement string for comparison.
 * e.g., "Android 5.0+" becomes "5.0".
 * @param string $req The requirement string.
 * @return string The normalized version number.
 */
function normalize_requirement(string $req): string {
    preg_match('/(\d+(\.\d+)?)/', $req, $matches);
    return $matches[0] ?? '';
}

// --- Main Logic ---

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$pathInfo = str_replace($scriptName, '', $requestUri);
$pathInfo = trim(parse_url($pathInfo, PHP_URL_PATH) ?? '', '/');

$extractedInfoFromUrl = extractSlugAndPackageName($pathInfo);
$urlSlug = $extractedInfoFromUrl['slug'];
$packageName = $extractedInfoFromUrl['package_name'];

if (empty($packageName) && isset($_GET['id'])) {
    $rawAppIdFromGet = trim($_GET['id']);
    $extractedInfoFromGet = extractSlugAndPackageName($rawAppIdFromGet);
    $packageName = $extractedInfoFromGet['package_name'];
    if (empty($urlSlug) && !empty($packageName)) {
        $urlSlug = createSlug($packageName);
    }
}

if (empty($packageName)) {
    header('Location: /?error=invalid_app_id');
    exit;
}

// FIRST: Try to load local app details
$appDetails = loadLocalAppDetails($packageName);
$isLocalApp = $appDetails !== null;

$error = null;
$downloadInfo = [];
$latestDownloadLink = '';
$isPaidApp = false; // Assume not paid for local apps unless specified

if (!$isLocalApp) {
    // If no local app found, proceed with scraping logic
    $baseAppIdForSource = rawurlencode($packageName);
    $pathParts = array_filter(explode('/', trim($pathInfo, '/')));
    if (count($pathParts) >= 2) {
         $potentialSlugPart = $pathParts[count($pathParts) - 2];
         $potentialPackagePart = $pathParts[count($pathParts) - 1];
         if ($potentialPackagePart === $packageName && !in_array(strtolower($potentialSlugPart), ['versions', 'download', 'related', 'category', 'developer', 'app'])) {
             $sourceAppBaseUrl = 'https://' . SOURCE_DOMAIN . '/' . rawurlencode($potentialSlugPart) . '/' . $baseAppIdForSource;
         } else {
              $sourceAppBaseUrl = 'https://' . SOURCE_DOMAIN . '/' . $baseAppIdForSource;
         }
    } else {
         $sourceAppBaseUrl = 'https://' . SOURCE_DOMAIN . '/' . $baseAppIdForSource;
    }

    $sourceUrlToScrape = $sourceAppBaseUrl;
    if (strpos($pathInfo, '/versions') !== false) {
        $sourceUrlToScrape = rtrim($sourceAppBaseUrl, '/') . '/versions';
    } elseif (strpos($pathInfo, '/download') !== false) {
         $sourceUrlToScrape = rtrim($sourceAppBaseUrl, '/') . '/download';
    }

    $sourceBaseUrl = 'https://' . SOURCE_DOMAIN;
    $userBaseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";

    $appDetails = extractAppDetails($sourceUrlToScrape, $sourceBaseUrl, $userBaseUrl);
    $error = $appDetails['error'];
    $isPaidApp = isset($appDetails['price']) && is_numeric($appDetails['price']) && (float)$appDetails['price'] > 0;

    // --- Start SHA1 & Arch Fix Logic ---
    // Initialize properties to hold the matched values. They will remain null if no match is found.
    $appDetails['matched_sha1'] = null;
    $appDetails['matched_arch'] = null;

    // 1. Fetch main download button info (size, type)
    if (!$error && !$isPaidApp) {
        $downloadInfo = fetchDownloadInfo($sourceAppBaseUrl, $sourceBaseUrl);
        // Use downloadInfo's file_size and file_type for the main download button display. This is CRUCIAL.
        $appDetails['file_size'] = $downloadInfo['file_size'] ?? $appDetails['size'] ?? '';
        $appDetails['file_type'] = $downloadInfo['file_type'] ?? 'APK';
    }

    // 2. Fetch previous versions data to find the matching variant
    $previousVersions = [];
    if (!$error) {
        $finalSlugForVersions = !empty($appDetails['slug']) ? $appDetails['slug'] : $urlSlug;
        $finalPackageNameForVersions = !empty($appDetails['package_name']) ? $appDetails['package_name'] : $packageName;
        if (!empty($finalSlugForVersions) && !empty($finalPackageNameForVersions)) {
            $previousVersions = extractPreviousVersions($sourceAppBaseUrl, $sourceBaseUrl, $finalSlugForVersions, $finalPackageNameForVersions);
            if (isset($previousVersions['error'])) {
                error_log("Error fetching previous versions: " . $previousVersions['error']);
                $previousVersions = [];
            }
        }
    }
    
    // 3. Find the correct variant by matching size AND requirements from the latest version group.
    if (!empty($previousVersions) && isset($previousVersions[0]['variants'])) {
        $targetSize = $appDetails['file_size'];
        $targetReq = $appDetails['requirements'];
        $latestVersionGroupVariants = $previousVersions[0]['variants'];
        $bestMatchVariant = null;

        if (!empty($targetSize) && !empty($targetReq) && !empty($latestVersionGroupVariants)) {
            $normalizedTargetSize = normalize_string($targetSize);
            $normalizedTargetReq = normalize_requirement($targetReq);
            
            foreach ($latestVersionGroupVariants as $variant) {
                $normalizedVariantSize = normalize_string($variant['size']);
                $normalizedVariantReq = normalize_requirement($variant['android_req']);
                
                // Strict check for both size and requirement.
                if ($normalizedVariantSize === $normalizedTargetSize && $normalizedVariantReq === $normalizedTargetReq) {
                    $bestMatchVariant = $variant;
                    break; // Found the exact match, no need to continue.
                }
            }
        }
        
        // 4. If a perfect match was found, store its SHA1 and Architecture.
        if ($bestMatchVariant !== null) {
            $appDetails['matched_sha1'] = $bestMatchVariant['sha1'];
            // Set architecture, defaulting to 'Universal' if it's empty.
            $appDetails['matched_arch'] = !empty(trim($bestMatchVariant['arch'])) ? trim($bestMatchVariant['arch']) : 'Universal';
        }
    }
    // No fallback is needed. If a match isn't found, the values remain null and won't be displayed.

    // 5. Construct download link (it does not need SHA1)
    if (!$error && !$isPaidApp) {
        if (!empty($appDetails['slug']) && !empty($appDetails['package_name'])) {
             $latestDownloadLink = '/' . rawurlencode($appDetails['slug']) . '/' . rawurlencode($appDetails['package_name']) . '/download';
        } elseif (!empty($urlSlug) && !empty($packageName)) {
             $latestDownloadLink = '/' . rawurlencode($urlSlug) . '/' . rawurlencode($packageName) . '/download';
        }
    } elseif (!$error && $isPaidApp) {
        $latestDownloadLink = '';
    }
    // --- End SHA1 & Arch Fix Logic ---

} else {
    // Local app details loaded, construct download link for local file
    $generatedSlug = $appDetails['slug'] ?? createSlug($appDetails['name']);
    $latestDownloadLink = '/' . rawurlencode($generatedSlug) . '/' . rawurlencode($appDetails['package_name']) . '/download';
    $downloadInfo['file_size'] = $appDetails['file_size'];
    $downloadInfo['file_type'] = strtoupper(pathinfo($appDetails['uploaded_filename'], PATHINFO_EXTENSION));
    $downloadInfo['sha1'] = $appDetails['matched_sha1'] ?? ''; // From metadata, or N/A
}


// For local apps, previous versions are not supported via this simple metadata
// If you want to support multiple versions for local apps, you'd need a more complex
// local storage system for each version's metadata.

$appNameForTitle = !empty($appDetails['name']) ? htmlspecialchars($appDetails['name']) : "App";
$appVersionForTitle = !empty($appDetails['version']) ? 'v' . htmlspecialchars(ltrim($appDetails['version'], 'v')) : '';
$fileTypeForTitle = !empty($appDetails['file_type']) ? htmlspecialchars($appDetails['file_type']) : 'APK';
$priceStatusForTitle = $isPaidApp ? 'Buy' : 'Free';
$pageTitle = $appNameForTitle . (!empty($appVersionForTitle) ? ' ' . $appVersionForTitle : '') . ' Download ' . $fileTypeForTitle . ' ' . $priceStatusForTitle . ' - ' . USER_DOMAIN . ' Free & Safe APK' . (!empty($appDetails['file_type']) && $appDetails['file_type'] === 'XAPK' ? ' XAPK' : '') . ' Downloads';

include 'includes/header.php';

if (!$error) {
    // Ensure all necessary keys are set for display, even if they come from local or are defaulted
    $appDetails['reviews_parsed'] = $appDetails['reviews_parsed'] ?? (!empty($appDetails['reviews']) ? parseReviewCount($appDetails['reviews']) : '');

    // Populate more_info if not already set, or if it's a local app where defaults are needed
    if (!isset($appDetails['more_info'])) $appDetails['more_info'] = [];
    // The previous dynamic population of more_info is removed here as it will be manually constructed below
    // to allow for grouping as per user's request.
}

$infoIcons = [
    'Package Name' => 'fas fa-box-archive', 'Category' => 'fas fa-folder-open', 'Update Date' => 'fas fa-calendar-check', 'Updated' => 'fas fa-calendar-check',
    'Latest Version' => 'fas fa-code-branch', 'Version' => 'fas fa-code-branch', 'Requirements' => 'fab fa-android', 'Developer website' => 'fas fa-globe',
    'Permissions' => 'fas fa-shield-halved', 'Installs' => 'fas fa-download', 'Content Rating' => 'fas fa-star-half-alt', 'Offered By' => 'fas fa-building',
    'Developer' => 'fas fa-user-tie', 'Size' => 'fas fa-file-lines', 'Report' => 'fas fa-flag', 'default' => 'fas fa-info-circle', 'Google Play' => 'fab fa-google-play',
    'SHA1' => 'fas fa-fingerprint', 'Architecture' => 'fas fa-microchip' // Icon for SHA1 and new Architecture
];
$excludedMoreInfoItems = ['Need Update', 'Report']; // These are now implicitly excluded by manual construction

function getCurrentUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? USER_DOMAIN;
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
    return $protocol . "://" . $host . $path;
}
$currentUrl = getCurrentUrl();
$qrShareUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($currentUrl);
$playStoreLink = !empty($appDetails['play_store_link']) ? htmlspecialchars($appDetails['play_store_link']) : (!empty($appDetails['package_name']) ? 'https://play.google.com/store/apps/details?id=' . htmlspecialchars($appDetails['package_name']) : '');
$fullDescription = $appDetails['description'] ?? '';
$needsShowMore = false;
$plainTextDescription = strip_tags($fullDescription);
if (mb_strlen($plainTextDescription) > DESCRIPTION_TRUNCATE_LENGTH) $needsShowMore = true;
?>

<div class="flex flex-col lg:flex-row gap-4 mb-8 text-sm max-w-screen-xl mx-auto px-0 sm:px-4">
    <div class="w-full lg:flex-grow lg:w-2/3">
        <div class="bg-white dark:bg-gray-900 rounded-md shadow-sm p-4 md:p-6">
            <nav class="text-sm mb-4 text-gray-500 dark:text-gray-400" aria-label="Breadcrumb">
                <ol class="list-none p-0 inline-flex flex-wrap items-center w-full">
                    <li class="flex items-center">
                        <a href="/" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition-colors duration-150 flex items-center" title="Home">
                            <i class="fas fa-home text-base"></i>
                            <span class="ml-2 hidden sm:inline">Home</span>
                        </a>
                    </li>
                    
                    <?php
                    if (!$error && !empty($appDetails['category_link'])) {
                        $rawCategoryPath = $appDetails['category_link'];
                        $cleanedPath = trim(preg_replace('/^\/category\/?/i', '', $rawCategoryPath), '/');

                        if (!empty($cleanedPath)) {
                            $pathParts = explode('/', $cleanedPath);
                            $currentPathForLink = '/category/';
                            
                            // Check for 'apps' or 'games' as the first segment
                            if (isset($pathParts[0])) {
                                if ($pathParts[0] === 'apps') {
                                    echo '<li class="flex items-center mx-2"><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>';
                                    echo '<li class="flex items-center"><a href="/category/apps" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400" title="Apps">Apps</a></li>';
                                    array_shift($pathParts); // Remove 'apps' from parts to process
                                    $currentPathForLink .= 'apps/';
                                } elseif ($pathParts[0] === 'games') {
                                     echo '<li class="flex items-center mx-2"><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>';
                                    echo '<li class="flex items-center"><a href="/category/games" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400" title="Games">Games</a></li>';
                                    array_shift($pathParts); // Remove 'games' from parts to process
                                    $currentPathForLink .= 'games/';
                                }
                            }

                            foreach ($pathParts as $part) {
                                if (empty($part)) continue;
                                
                                $partText = ucwords(str_replace(['-', '_'], ' ', $part));
                                $currentPathForLink .= rawurlencode($part) . '/';
                                $linkHref = htmlspecialchars(rtrim($currentPathForLink, '/'));

                                echo '<li class="flex items-center mx-2"><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>';
                                echo '<li class="flex items-center"><a href="' . $linkHref . '" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400" title="' . htmlspecialchars($partText) . '">' . htmlspecialchars($partText) . '</a></li>';
                            }
                        }
                    }
                    ?>
                    
                    <li class="flex items-center mx-2"><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
                    <li class="flex items-center">
                        <span class="font-semibold text-gray-800 dark:text-gray-100 truncate" title="<?php echo htmlspecialchars($appDetails['name'] ?? 'App'); ?>">
                            <?php echo htmlspecialchars($appDetails['name'] ?? 'App'); ?>
                        </span>
                    </li>
                    
                    <li class="ml-auto flex items-center pl-4">
                        <span id="qrPopupButton" class="inline-flex items-center bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-medium px-2 py-1 rounded-md cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700 shadow-sm" title="Share Page (QR Code)">
                            <i class="fas fa-qrcode"></i>
                        </span>
                    </li>
                </ol>
            </nav>
            <style>
                /* Removed custom-scrollbar styles for sidebar sections as requested */
                
                .badge-flat { border-radius: 0.375rem; padding: 0.25rem 0.6rem; font-size: .625rem; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; line-height: 1; }
                .badge-apk { background-color: #10b981; color: #ffffff; } /* Darker Green */
                .badge-xapk { background-color: #3b82f6; color: #ffffff; } /* Darker Blue */
                .badge-obb, .badge-apks { background-color: #f59e0b; color: #ffffff; } /* Darker Gold/Yellow */
                .badge-latest { background-color: #153132; color: #ffffff; }
                
                .faq-header {
                    background-color: transparent;
                    border: 1px solid #e5e7eb;
                    border-radius: 0.375rem;
                    margin-bottom: 0.5rem;
                }
                .dark .faq-header { border-color: #374151; }
                .faq-header:hover { background-color: rgba(107, 114, 128, 0.05); }
                .dark .faq-header:hover { background-color: rgba(156, 163, 175, 0.1); }
                .faq-body {
                    background-color: transparent;
                    border: 1px solid #e5e7eb;
                    border-top: none;
                    border-radius: 0 0 0.375rem 0.375rem;
                    padding: 1rem;
                    margin-top: -0.5rem;
                    margin-bottom: 0.5rem;
                    display: none; /* Changed to display: none directly */
                }
                .dark .faq-body { border-color: #374151; }
                .faq-body p { margin-bottom: 0; }
                .faq-body .flex { align-items: flex-start; }
                .faq-body .flex i { margin-top: 0.25rem; }
                
                /* Flat, organized QR Modal styles */
                #qrCodeModal {
                    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                    background-color: rgba(0, 0, 0, 0.7);
                    display: flex; align-items: center; justify-content: center;
                    z-index: 1050; opacity: 0; visibility: hidden;
                    transition: opacity 0.3s ease, visibility 0.3s ease; padding: 1rem;
                }
                #qrCodeModal.active { opacity: 1; visibility: visible; }
                .qr-modal-content {
                    background-color: #fff;
                    padding: 1.5rem; /* Reduced padding */
                    border-radius: 8px; /* Slightly less rounded */
                    box-shadow: none; /* Removed shadow for flat design */
                    text-align: center;
                    max-width: 380px; /* Increased max width slightly to accommodate more buttons */
                    width: 100%;
                    position: relative;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    gap: 1rem; /* Space between elements */
                    border: 1px solid #e0e0e0; /* Subtle border for flat look */
                }
                .dark .qr-modal-content {
                    background-color: #1f2937;
                    border-color: #374151;
                }
                .qr-modal-close-x {
                    position: absolute;
                    top: 0.5rem;
                    right: 0.5rem;
                    background: transparent;
                    border: none;
                    font-size: 1.8rem;
                    font-weight: bold;
                    color: #777;
                    cursor: pointer;
                    line-height: 1;
                    padding: 0.5rem;
                    border-radius: 50%;
                    transition: background-color 0.2s ease, color 0.2s ease;
                }
                .qr-modal-close-x:hover {
                    color: #333;
                    background-color: #f3f4f6;
                }
                .dark .qr-modal-close-x:hover {
                    color: #eee;
                    background-color: #374151;
                }
                .qr-modal-notice {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 0.85rem;
                    color: #4b5563;
                    margin-bottom: 0.5rem; /* Adjusted margin */
                    text-align: center; /* Centered text */
                    width: 100%;
                }
                .dark .qr-modal-notice { color: #d1d5db; }
                .qr-modal-notice i { color: #3b82f6; margin-right: 0.5rem; }
                .qr-modal-content img {
                    max-width: 100%; width: 180px; height: 180px; /* Slightly smaller QR code */
                    border-radius: 4px; /* Less rounded corners */
                    margin-bottom: 0.5rem; /* Adjusted margin */
                    border: 1px solid #eee;
                    background-color: white;
                    object-fit: contain;
                }
                .dark .qr-modal-content img {
                    border-color: #374151;
                    background-color: #2d3748;
                }
                .qr-modal-url {
                    font-size: 0.75rem; /* Smaller font size */
                    color: #555; word-break: break-all;
                    background-color: #f7f7f7; /* Lighter background */
                    padding: 0.5rem; /* Reduced padding */
                    border-radius: 4px; /* Less rounded */
                    margin-bottom: 0.5rem;
                    text-align: center; /* Centered text */
                    width: 100%;
                    font-family: monospace; /* Monospace font for URL */
                }
                .dark .qr-modal-url {
                    background-color: #2d3748;
                    color: #d1d5db;
                }
                .qr-modal-buttons {
                    display: flex;
                    flex-wrap: wrap; /* Allow buttons to wrap */
                    justify-content: center;
                    width: 100%;
                    margin-top: 0.5rem;
                    gap: 0.5rem; /* Gap between buttons */
                }
                .qr-modal-buttons button,
                .qr-modal-buttons a { /* Apply styles to anchor tags too */
                    padding: 8px 12px; /* Adjusted padding */
                    border: 1px solid #ccc; /* Subtle border */
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 0.85rem; /* Slightly smaller font */
                    font-weight: 500;
                    transition: background-color 0.2s ease, transform 0.1s ease, border-color 0.2s ease;
                    flex-grow: 1;
                    max-width: 140px; /* Adjusted max-width */
                    display: flex; /* Make them flex containers for icon + text */
                    align-items: center;
                    justify-content: center;
                    text-decoration: none; /* Remove underline for links */
                }
                .dark .qr-modal-buttons button,
                .dark .qr-modal-buttons a {
                    border-color: #4b5563;
                }
                .qr-modal-buttons button:active,
                .qr-modal-buttons a:active { transform: scale(0.98); }
                
                /* Specific button styles */
                .qr-modal-buttons .copy-btn {
                    background-color: #2563eb; color: white; border-color: #2563eb;
                }
                .qr-modal-buttons .copy-btn:hover { background-color: #1d4ed8; border-color: #1d4ed8; }
                .dark .qr-modal-buttons .copy-btn { background-color: #3b82f6; border-color: #3b82f6; }
                .dark .qr-modal-buttons .copy-btn:hover { background-color: #60a5fa; border-color: #60a5fa; }

                .qr-modal-buttons .share-btn {
                    background-color: #f0f0f0; color: #333;
                }
                .qr-modal-buttons .share-btn:hover { background-color: #e0e0e0; }
                .dark .qr-modal-buttons .share-btn {
                    background-color: #374151; color: #d1d5db;
                }
                .dark .qr-modal-buttons .share-btn:hover { background-color: #4b5563; }
                
                .qr-modal-buttons i.mr-1 { margin-right: 0.5rem; } /* Consistent icon spacing */

                /* New styles for More Information cards */
                .info-card {
                    display: flex;
                    align-items: flex-start;
                    padding: 0.75rem; /* Padding for the card */
                    border-radius: 0.5rem; /* Rounded corners for the card */
                    box-shadow: 0 1px 2px rgba(0,0,0,0.05); /* Subtle shadow */
                }
                /* Explicitly remove underline for all links inside info-cards */
                .info-card a {
                    text-decoration: none !important; 
                }
                .info-card a:hover {
                    text-decoration: underline !important;
                }

                /* Mobile-specific adjustments for info-card */
                @media (max-width: 639px) { /* Tailwind's default 'sm' breakpoint is 640px */
                    .info-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr)) !important; /* Force 2 columns on small screens */
                    }
                }
            </style>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4" role="alert">
                    <strong class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i> Error!</strong>
                    <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php else: ?>
                <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4 mb-3"> <div class="flex-shrink-0">
                        <?php if (!empty($appDetails['icon'])): ?>
                            <img src="<?php echo htmlspecialchars($appDetails['icon']); ?>"
                                 class="w-24 h-24 rounded-lg shadow border border-gray-100 dark:border-gray-800 object-cover bg-gray-100 dark:bg-gray-800"
                                 alt="<?php echo htmlspecialchars($appDetails['name']); ?> Icon"
                                 loading="lazy"
                                 onerror="this.onerror=null; this.src='assets/images/app-placeholder.png'; this.classList.add('object-contain');">
                        <? else: ?>
                            <div class="w-24 h-24 bg-gradient-to-br from-gray-200 to-gray-300 dark:from-gray-700 dark:to-gray-800 rounded-lg flex items-center justify-center shadow border border-gray-100 dark:border-gray-800">
                                <i class="fas fa-mobile-alt text-gray-500 dark:text-gray-400 text-4xl"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="w-full flex flex-col space-y-1 text-center sm:text-left">
                        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 mb-0"><?php echo htmlspecialchars($appDetails['name']); ?></h1>
                        <?php if (!empty($appDetails['developer'])):
                            $devName = htmlspecialchars($appDetails['developer']);
                            $devLink = htmlspecialchars($appDetails['developer_link']);
                        ?>
                             <div class="text-sm text-gray-600 dark:text-gray-400 mt-0.5 mb-1">
                                 <i class="fas fa-user-tie mr-1 opacity-75"></i>By:
                                 <?php if ($devLink): ?>
                                     <a href="<?php echo $devLink; ?>" class="text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 transition-colors duration-150 font-medium"><?php echo $devName; ?></a>
                                 <?php else: ?>
                                     <span class="text-gray-700 dark:text-gray-300 font-medium"><?php echo $devName; ?></span>
                                 <?php endif; ?>
                             </div>
                        <?php endif; ?>
                         <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 text-xs pt-1">
                            <?php if (!empty($appDetails['version'])): ?>
                                <span class="inline-flex items-center bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 font-medium px-2 py-1 rounded-md shadow-sm">Version: <?php echo htmlspecialchars(ltrim($appDetails['version'], 'v')); ?></span>
                            <?php endif; ?>
                             <?php if (!empty($appDetails['update_date'])):
                                $formattedDate = ''; $timestamp = strtotime($appDetails['update_date']);
                                if ($timestamp !== false) $formattedDate = date('F j, Y', $timestamp);
                                else $formattedDate = htmlspecialchars($appDetails['update_date']);
                             ?>
                                <span class="inline-flex items-center bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200 font-medium px-2 py-1 rounded-md shadow-sm"><i class="fas fa-calendar-check mr-1"></i>Updated: <?php echo $formattedDate; ?></span>
                            <?php endif; ?>
                             <?php if (!empty($appDetails['requirements'])): ?>
                                <span class="inline-flex items-center bg-lime-100 text-lime-800 dark:bg-lime-900 dark:text-lime-200 font-medium px-2 py-1 rounded-md shadow-sm" title="Requires Android: <?php echo htmlspecialchars($appDetails['requirements']); ?>"><i class="fab fa-android mr-1"></i><?php echo htmlspecialchars($appDetails['requirements']); ?></span>
                            <?php endif; ?>
                             <?php if (!empty($appDetails['rating'])): ?>
                                <span class="inline-flex items-center bg-orange-500 text-white font-medium px-1.5 py-0.5 rounded-md shadow-sm text-xs"><i class="fas fa-star mr-1"></i><?php echo htmlspecialchars($appDetails['rating']); ?></span>
                            <?php endif; ?>
                             <?php if (!empty($appDetails['reviews_parsed'])): ?>
                                <span class="inline-flex items-center bg-gray-200 text-gray-700 px-1.5 py-0.5 rounded-md font-medium text-xs" title="<?php echo htmlspecialchars($appDetails['reviews_parsed']); ?> Reviews"><i class="fas fa-users mr-1"></i><?php echo htmlspecialchars($appDetails['reviews_parsed']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-2 flex-wrap mt-3 justify-center sm:justify-start">
                            <?php
                            $playStoreLinkForButton = !empty($appDetails['package_name']) ? 'https://play.google.com/store/apps/details?id=' . htmlspecialchars($appDetails['package_name']) : '';
                            if ($isPaidApp && !empty($playStoreLinkForButton)) :
                                $currencySymbol = ''; $priceValue = htmlspecialchars($appDetails['price']);
                                switch (strtoupper($appDetails['price_currency'] ?? '')) {
                                    case 'USD': $currencySymbol = '$'; break; case 'EUR': $currencySymbol = '€'; break;
                                    case 'GBP': $currencySymbol = '£'; break; case 'JPY': $currencySymbol = '¥'; break;
                                    case 'INR': $currencySymbol = '₹'; break;
                                    default: if (strpos($priceValue, '$') === false && strpos($priceValue, '€') === false && strpos($priceValue, '£') === false && strpos($priceValue, '¥') === false && strpos($priceValue, '₹') === false) $currencySymbol = ($appDetails['price_currency'] ? htmlspecialchars($appDetails['price_currency']).' ' : '$');
                                }
                                $priceDisplay = $currencySymbol . preg_replace('/[^0-9\.]/', '', $priceValue);
                                if (empty($currencySymbol) && !empty($priceValue)) $priceDisplay = $priceValue;
                            ?>
                                <a href="<?php echo $playStoreLinkForButton; ?>" target="_blank" rel="nofollow noopener" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-md shadow-sm flex items-center justify-center text-sm font-semibold transition duration-150 ease-in-out w-full sm:w-auto">
                                    <i class="fab fa-google-play mr-2"></i>Buy on Google Play (<?php echo $priceDisplay; ?>)<i class="fas fa-external-link-alt text-xs opacity-60 ml-1.5"></i>
                                </a>
                            <?php elseif (!empty($latestDownloadLink)) :
                                $fileTypeBtn = $appDetails['file_type'] ?? 'APK'; // Changed to use appDetails['file_type']
                                $fileSizeBtn = $appDetails['file_size'] ?? ''; // Changed to use appDetails['file_size']
                                $downloadButtonText = 'Download Latest ' . htmlspecialchars($fileTypeBtn);
                                if ($isLocalApp) { // For locally uploaded apps
                                    $downloadButtonText = 'Download ' . htmlspecialchars($appDetails['uploaded_filename']);
                                    $fileSizeBtn = $appDetails['file_size'];
                                }
                            ?>
                                <a href="<?php echo htmlspecialchars($latestDownloadLink); ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md shadow-sm flex items-center justify-center text-sm font-semibold transition duration-150 ease-in-out">
                                    <i class="fas fa-download mr-2"></i> <?php echo $downloadButtonText; ?>
                                    <?php if (!empty($fileSizeBtn)): ?>
                                        <span class="ml-2 bg-white/20 px-2 py-0.5 rounded text-white text-[11px] font-normal"><?php echo htmlspecialchars($fileSizeBtn); ?></span>
                                    <?php endif; ?>
                                </a>
                                <?php if (!empty($playStoreLinkForButton)): ?>
                                   <a href="<?php echo $playStoreLinkForButton; ?>" target="_blank" rel="nofollow noopener" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-md shadow-sm flex items-center justify-center text-sm font-semibold transition duration-150 ease-in-out">
                                       <i class="fab fa-google-play mr-2"></i>Google Play <i class="fas fa-external-link-alt text-xs opacity-60 ml-1.5"></i>
                                   </a>
                                <?php endif; ?>
                           <?php else: ?>
                               <span class="bg-gray-400 text-white px-4 py-2 rounded-md shadow-sm flex items-center justify-center text-sm font-semibold cursor-not-allowed"><i class="fas fa-download mr-2"></i>Download Unavailable</span>
                               <?php if (!empty($playStoreLinkForButton)): ?>
                                  <a href="<?php echo $playStoreLinkForButton; ?>" target="_blank" rel="nofollow noopener" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-md shadow-sm flex items-center justify-center text-sm font-semibold transition duration-150 ease-in-out">
                                      <i class="fab fa-google-play mr-2"></i>Google Play <i class="fas fa-external-link-alt text-xs opacity-60 ml-1.5"></i>
                                  </a>
                               <?php endif; ?>
                            <?php endif; ?>
                       </div>
                    </div>
                </div>

                <?php if (!empty($appDetails['screenshots'])): ?>
                    <!-- Removed border-t and reduced padding-top to pt-1 (approx. 4px) for 2px margin visual effect -->
                    <div class="mb-4 pt-1 -mx-4 sm:mx-0">
                        <div class="relative group">
                            <button id="scrollPrevButton" aria-label="Scroll Previous" class="scroll-button absolute left-1 top-1/2 transform -translate-y-1/2 z-20 bg-gray-700/60 text-white rounded-md w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 hover:bg-gray-800 transition-opacity duration-300 disabled:opacity-30 disabled:cursor-not-allowed">
                                <i class="fas fa-chevron-left text-sm"></i>
                            </button>
                            <div id="screenshotContainer" class="flex overflow-x-auto space-x-1 scroll-smooth snap-x snap-mandatory px-1">
                                <?php foreach ($appDetails['screenshots'] as $key => $screenshot): ?>
                                    <img src="<?php echo htmlspecialchars($screenshot); ?>" alt="Screenshot <?php echo $key + 1; ?> for <?php echo htmlspecialchars($appDetails['name']); ?>" data-index="<?php echo $key; ?>" class="flex-shrink-0 cursor-pointer object-contain h-48 sm:h-64 md:h-72 lg:h-80 bg-white dark:bg-black snap-center min-w-[calc(33.33%-0.33rem)] w-auto" loading="lazy" onclick="openModal(<?php echo $key; ?>)" onerror="this.onerror=null;this.src='https://placehold.co/300x500/e5e7eb/1f2937?text=Image+Unavailable';">
                                <?php endforeach; ?>
                            </div>
                            <button id="scrollNextButton" aria-label="Scroll Next" class="scroll-button absolute right-1 top-1/2 transform -translate-y-1/2 z-20 bg-gray-700/60 text-white rounded-md w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 hover:bg-gray-800 transition-opacity duration-300 disabled:opacity-30 disabled:cursor-not-allowed">
                                <i class="fas fa-chevron-right text-sm"></i>
                            </button>
                        </div>
                    </div>
                    <div id="screenshotModal" class="fixed inset-0 bg-black/80 z-[100] flex items-center justify-center hidden p-4 transition-opacity duration-300" onclick="if(event.target.id === 'screenshotModal') closeModal();">
                        <button class="absolute top-4 right-4 text-white text-4xl hover:text-gray-300 z-[102] transition-transform hover:scale-110" onclick="closeModal()" aria-label="Close">
                            <i class="fas fa-times"></i>
                        </button>
                        <button id="modalPrevButton" class="absolute left-4 sm:left-8 top-1/2 transform -translate-y-1/2 text-white hover:text-gray-300 z-[102] transition-transform hover:scale-110 bg-black/30 hover:bg-black/50 rounded-full w-12 h-12 items-center justify-center hidden">
                            <i class="fas fa-chevron-left text-2xl"></i>
                        </button>
                        <div class="relative w-full h-full flex items-center justify-center">
                             <img id="modalImage" src="" alt="Enlarged Screenshot" class="max-w-[calc(100vw-4rem)] max-h-[calc(100vh-4rem)] object-contain rounded-md shadow-lg z-[101]">
                        </div>
                        <button id="modalNextButton" class="absolute right-4 sm:right-8 top-1/2 transform -translate-y-1/2 text-white hover:text-gray-300 z-[102] transition-transform hover:scale-110 bg-black/30 hover:bg-black/50 rounded-full w-12 h-12 items-center justify-center hidden">
                            <i class="fas fa-chevron-right text-2xl"></i>
                        </button>
                    </div>
                    <script>
                        const allScreenshots = <?php echo json_encode($appDetails['screenshots']); ?>;
                        let currentScreenshotIndex = 0;

                        const ssModal = document.getElementById('screenshotModal');
                        const ssModalImg = document.getElementById('modalImage');
                        const modalPrevBtn = document.getElementById('modalPrevButton');
                        const modalNextBtn = document.getElementById('modalNextButton');

                        function getHighQualityImageUrl(url) {
                            if (!url) return '';
                            try {
                                const urlObj = new URL(url, window.location.origin);
                                urlObj.searchParams.set('h', '1200');
                                urlObj.searchParams.delete('w');
                                return urlObj.toString();
                            } catch (e) {
                                return url;
                            }
                        }

                        window.openModal = function(index) {
                            if (!ssModal || !ssModalImg || !allScreenshots || allScreenshots.length === 0) return;

                            currentScreenshotIndex = index;
                            const imageUrl = getHighQualityImageUrl(allScreenshots[currentScreenshotIndex]);
                            ssModalImg.src = imageUrl;
                            ssModal.classList.remove('hidden');
                            document.body.style.overflow = 'hidden';
                            updateModalButtons();
                        }

                        window.closeModal = function() {
                            if (!ssModal || !ssModalImg) return;
                            ssModal.classList.add('hidden');
                            ssModalImg.src = "";
                            document.body.style.overflow = '';
                        }

                        function updateModalButtons() {
                            if (!modalPrevBtn || !modalNextBtn || !allScreenshots) return;
                            modalPrevBtn.style.display = (currentScreenshotIndex > 0) ? 'flex' : 'none';
                            modalNextBtn.style.display = (currentScreenshotIndex < allScreenshots.length - 1) ? 'flex' : 'none';
                        }

                        function navigateScreenshots(direction) {
                            if (!allScreenshots || allScreenshots.length === 0) return;
                            const newIndex = currentScreenshotIndex + direction;
                            if (newIndex >= 0 && newIndex < allScreenshots.length) {
                                openModal(newIndex);
                            }
                        }

                        if (modalPrevBtn) modalPrevBtn.addEventListener('click', () => navigateScreenshots(-1));
                        if (modalNextBtn) modalNextBtn.addEventListener('click', () => navigateScreenshots(1));

                        document.addEventListener('keydown', (event) => {
                            if (ssModal && !ssModal.classList.contains('hidden')) {
                                if (event.key === 'Escape') closeModal();
                                else if (event.key === 'ArrowLeft') modalPrevBtn.click();
                                else if (event.key === 'ArrowRight') modalNextBtn.click();
                            }
                        });
                    </script>
                 <?php endif; ?>

                <?php if (!empty($fullDescription)): ?>
                    <div class="mb-6 pt-4 border-t border-gray-100 dark:border-gray-800">
                        <h2 class="text-base font-semibold mb-2 text-gray-800 dark:text-gray-100 flex items-center"><i class="fas fa-file-alt text-gray-500 dark:text-gray-400 mr-2 text-base"></i>Description</h2>
                         <div id="descriptionContent" class="prose prose-sm max-w-none text-gray-700 dark:text-gray-300 leading-relaxed bg-white dark:bg-transparent p-0 text-xs overflow-hidden <?php echo $needsShowMore ? 'max-h-40 fade-mask' : ''; ?>">
                             <?php echo $fullDescription; ?>
                         </div>
                         <?php if ($needsShowMore): ?>
                             <button id="showMoreDescription" class="mt-2 text-blue-600 dark:text-blue-400 hover:underline text-xs font-semibold">Show More</button>
                         <?php endif; ?>
                    </div>
                    <style>
                        .fade-mask { mask-image: linear-gradient(to bottom, black 60%, transparent 100%); -webkit-mask-image: linear-gradient(to bottom, black 60%, transparent 100%); }
                         #descriptionContent p { margin-bottom: 0.75em; } #descriptionContent strong { font-weight: 600; } #descriptionContent a { color: #2563eb; text-decoration: underline; }
                         .dark #descriptionContent a { color: #60a5fa; }
                    </style>
                    <script>
                        document.addEventListener('DOMContentLoaded', () => {
                            const descriptionContent = document.getElementById('descriptionContent'); const showMoreButton = document.getElementById('showMoreDescription');
                            if (descriptionContent && showMoreButton) showMoreButton.addEventListener('click', () => { descriptionContent.classList.remove('max-h-40', 'fade-mask'); showMoreButton.style.display = 'none'; });
                        });
                    </script>
                <?php endif; ?>

                 <div class="mb-6 pt-4 border-t border-gray-100 dark:border-gray-800">
                    <h2 class="text-base font-semibold mb-2 text-gray-800 dark:text-gray-100 flex items-center"><i class="fas fa-info-circle text-gray-500 dark:text-gray-400 mr-2 text-base"></i>More Information</h2>
                    <?php 
                    // Prepare data for grouped display
                    $infoItemsFinal = [];

                    // Row 1: Package Name & Size
                    $packageNameText = htmlspecialchars($appDetails['package_name'] ?? 'N/A');
                    $fileSizeText = htmlspecialchars($appDetails['file_size'] ?? 'N/A');
                    $infoItemsFinal[] = [
                        'type' => 'pair',
                        'items' => [
                            ['icon' => $infoIcons['Package Name'], 'text' => $packageNameText, 'label' => ''],
                            ['icon' => $infoIcons['Size'], 'text' => $fileSizeText, 'label' => 'Size:'],
                        ]
                    ];

                    // Row 2: Version & Update Date
                    $versionText = htmlspecialchars(ltrim($appDetails['version'] ?? 'N/A', 'v'));
                    $updateDateTextFormatted = '';
                    $timestamp = strtotime($appDetails['update_date'] ?? 'N/A');
                    if ($timestamp !== false) $updateDateTextFormatted = date('F j, Y', $timestamp);
                    else $updateDateTextFormatted = htmlspecialchars($appDetails['update_date'] ?? 'N/A');

                    $infoItemsFinal[] = [
                        'type' => 'pair',
                        'items' => [
                            ['icon' => $infoIcons['Version'], 'text' => $versionText, 'label' => 'Version:'],
                            ['icon' => $infoIcons['Update Date'], 'text' => $updateDateTextFormatted, 'label' => 'Updated:'],
                        ]
                    ];

                    // Row 3: Category & Offered By (Developer)
                    $categoryIcon = $infoIcons['Category'];
                    $categoryText = htmlspecialchars($appDetails['category'] ?? 'N/A');
                    $categoryLink = htmlspecialchars($appDetails['category_link'] ?? '');
                    $categoryContent = !empty($categoryLink) ? "<a href='{$categoryLink}' class='text-blue-700 dark:text-blue-400'>{$categoryText}</a>" : $categoryText;
                    
                    $developerText = htmlspecialchars($appDetails['developer'] ?? 'N/A');
                    $developerLink = htmlspecialchars($appDetails['developer_link'] ?? '');
                    $developerContent = !empty($developerLink) ? "<a href='{$developerLink}' class='text-blue-700 dark:text-blue-400'>{$developerText}</a>" : $developerText;

                    $infoItemsFinal[] = [
                        'type' => 'pair',
                        'items' => [
                            ['icon' => $categoryIcon, 'text' => $categoryContent, 'label' => ''],
                            ['icon' => $infoIcons['Offered By'], 'text' => $developerContent, 'label' => 'Offered By:'],
                        ]
                    ];

                    // Row 4: Requirements & Google Play Link
                    $requirementsText = htmlspecialchars($appDetails['requirements'] ?? 'N/A');
                    $googlePlayLinkForDisplay = '';
                    if (!empty($appDetails['package_name'])) {
                        $googlePlayLinkForDisplay = 'https://play.google.com/store/apps/details?id=' . urlencode($appDetails['package_name']);
                    } elseif (!empty($appDetails['play_store_link']) && strpos($appDetails['play_store_link'], 'play.google.com') !== false) {
                        $googlePlayLinkForDisplay = $appDetails['play_store_link'];
                    }
                    $googlePlayContent = (!empty($googlePlayLinkForDisplay) ? "<a href='{$googlePlayLinkForDisplay}' target='_blank' rel='nofollow noopener' class='text-blue-700 dark:text-blue-400'><i class='fab fa-google-play mr-1'></i>Available on Google Play</a>" : "Not on Google Play");
                    
                    $infoItemsFinal[] = [
                        'type' => 'pair',
                        'items' => [
                            ['icon' => $infoIcons['Requirements'], 'text' => $requirementsText, 'label' => ''],
                            ['icon' => $infoIcons['Google Play'], 'text' => $googlePlayContent, 'label' => ''],
                        ]
                    ];

                    // Row 5: SHA1 & Architecture (conditionally displayed)
                    if (!empty($appDetails['matched_sha1']) && !empty($appDetails['matched_arch'])) {
                        $sha1Text = htmlspecialchars($appDetails['matched_sha1']);
                        $archText = htmlspecialchars($appDetails['matched_arch']);
                        $infoItemsFinal[] = [
                            'type' => 'pair',
                            'items' => [
                                ['icon' => $infoIcons['SHA1'], 'text' => $sha1Text, 'label' => 'SHA1:'],
                                ['icon' => $infoIcons['Architecture'], 'text' => $archText, 'label' => 'Architecture:'],
                            ]
                        ];
                    }

                    // Determine if using list layout (single column) based on content length
                    $useListLayout = false;
                    $maxCharLengthForGridPair = 60; // Max combined characters for two items in a grid pair
                    
                    foreach ($infoItemsFinal as $group) {
                        if ($group['type'] === 'pair') {
                            $combinedLength = 0;
                            foreach ($group['items'] as $item) {
                                $contentToMeasure = strip_tags($item['label'] . $item['text']);
                                $combinedLength += mb_strlen($contentToMeasure);
                            }
                            if ($combinedLength > $maxCharLengthForGridPair) {
                                $useListLayout = true;
                                break;
                            }
                        }
                    }
                    ?>
                    <div class="space-y-3">
                        <?php
                        foreach ($infoItemsFinal as $groupIndex => $group):
                            $groupClass = '';
                            if ($group['type'] === 'pair') {
                                $groupClass = $useListLayout ? 'grid grid-cols-1' : 'grid grid-cols-1 sm:grid-cols-2';
                            } else {
                                $groupClass = 'grid grid-cols-1';
                            }
                            ?>
                            <div class="<?php echo $groupClass; ?> gap-3">
                                <?php
                                foreach ($group['items'] as $item):
                                    $currentCardBg = 'bg-gray-50 dark:bg-gray-900'; 
                                    $iconTextColorClass = 'text-gray-500 dark:text-gray-400';
                                    
                                    $labelContent = '';
                                    if (!empty($item['label'])) {
                                        $labelContent = "<span class='font-medium'>" . htmlspecialchars($item['label']) . "</span> ";
                                    }
                                    $displayContent = $item['text'];
                                ?>
                                    <div class="info-card <?php echo $currentCardBg; ?> text-gray-800 dark:text-gray-100">
                                        <i class="<?php echo $item['icon']; ?> <?php echo $iconTextColorClass; ?> mr-2 mt-1 flex-shrink-0"></i>
                                        <div class="flex-1 <?php echo ($item['icon'] === $infoIcons['SHA1']) ? 'break-all' : ''; ?>">
                                            <?php echo $labelContent . $displayContent; ?>
                                        </div>
                                    </div>
                                <?php 
                                endforeach;
                                ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                

                 <?php if (!empty($previousVersions) && !$isLocalApp): // Only show previous versions if NOT a local app ?>
                 <div class="mb-6 pt-4 border-t border-gray-100 dark:border-gray-800">
                     <h2 class="text-base font-semibold mb-3 text-gray-800 dark:text-gray-100 flex items-center"><i class="fas fa-history text-gray-500 dark:text-gray-400 mr-2 text-base"></i>Previous Versions & Other Versions</h2> <!-- Changed title -->
                     <div id="versionAccordion" class="space-y-2">
                         <?php $totalVersions = count($previousVersions); $displayCount = min($totalVersions, VERSIONS_INITIAL_DISPLAY_COUNT);
                         $latestMainVersion = trim(strtolower(ltrim($appDetails['version'] ?? '', 'v')));
                         for ($i = 0; $i < $totalVersions; $i++): $pVersion = $previousVersions[$i];
                             $isLatestInOldList = (!empty($latestMainVersion) && trim(strtolower(ltrim($pVersion['version'], 'v'))) === $latestMainVersion);
                             $headerClasses = 'version-header flex justify-between items-center w-full px-4 py-2.5 text-sm font-semibold transition-colors duration-200 focus:outline-none text-gray-700 dark:text-gray-200 hover:bg-green-50 dark:hover:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:border-green-300 dark:hover:border-green-700 rounded-md';
                             $fileTypeBadge = '';
                             if (!empty($pVersion['type'])) {
                                 $badgeClass = '';
                                 $icon = '<i class="fas fa-file-code mr-1 text-white"></i>'; // Default white icon
                                 switch(strtoupper($pVersion['type'])) {
                                     case 'APK': $badgeClass = 'badge-apk'; break;
                                     case 'XAPK': $badgeClass = 'badge-xapk'; $icon = '<i class="fas fa-file-zipper mr-1 text-white"></i>'; break;
                                     case 'OBB': case 'APKS': $badgeClass = 'badge-obb'; $icon = '<i class="fas fa-box-open mr-1 text-white"></i>'; break;
                                     default: $badgeClass = 'bg-gray-200 text-gray-800'; $icon = '';
                                 }
                                 // Made file type bold here
                                 $fileTypeBadge = '<span class="ml-2 badge-flat ' . $badgeClass . '">' . $icon . '<span class="font-bold">' . htmlspecialchars($pVersion['type']) . '</span></span>';
                             }
                             $bundleTypeBadge = '';
                             if (!empty($pVersion['bundle_type_badge_text'])) {
                                 $badgeClass = '';
                                 $icon = '<i class="fas fa-box-open mr-1 text-white"></i>'; // Default white icon
                                 switch(strtoupper($pVersion['bundle_type_badge_text'])) {
                                     case 'OBB': case 'APKS': $badgeClass = 'badge-obb'; break;
                                     default: $badgeClass = 'bg-gray-200 text-gray-800'; $icon = '';
                                 }
                                 $bundleTypeBadge = '<span class="ml-2 badge-flat ' . $badgeClass . '">' . $icon . htmlspecialchars($pVersion['bundle_type_badge_text']) . '</span>';
                             }
                         ?>
                             <div class="version-item rounded-md overflow-hidden shadow-sm <?php echo ($i >= $displayCount) ? 'hidden' : ''; ?>">
                                 <button class="<?php echo $headerClasses; ?>" data-target="#version-body-<?php echo $i; ?>" aria-expanded="false" aria-controls="version-body-<?php echo $i; ?>">
                                     <span class="flex items-center flex-wrap"><?php echo htmlspecialchars(ltrim($pVersion['version'], 'v')); ?> <!-- Removed 'V' prefix -->
                                         <?php if ($isLatestInOldList): ?><span class="ml-2 badge-flat badge-latest">Latest</span><?php endif; ?>
                                         <span class="ml-3 text-xs font-normal text-gray-500 dark:text-gray-400">
                                             <?php $formattedPrevDate = ''; $prevTimestamp = strtotime($pVersion['date']);
                                                if ($prevTimestamp !== false) $formattedPrevDate = date('M j, Y', $prevTimestamp); else $formattedPrevDate = htmlspecialchars($pVersion['date']);
                                                echo $formattedPrevDate; ?>
                                         </span>
                                          <?php echo $fileTypeBadge; echo $bundleTypeBadge; ?>
                                          <?php 
                                          // Conditional variant count display
                                          $variantCount = count($pVersion['variants']);
                                          if ($variantCount > 1): ?>
                                              <span class="ml-2 text-xs font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-boxes mr-1"></i><?php echo $variantCount; ?> Variants</span>
                                          <?php endif; ?>
                                     </span>
                                     <i class="fas fa-chevron-down transform transition-transform duration-200"></i>
                                 </button>
                                 <div id="version-body-<?php echo $i; ?>" class="version-body hidden px-4 py-3 bg-white dark:bg-gray-800/50" aria-labelledby="version-header-<?php $i; ?>">
                                     <?php if (!empty($pVersion['variants'])): ?>
                                         <div class="overflow-x-auto">
                                             <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                                 <thead class="bg-gray-100 dark:bg-gray-800">
                                                     <tr>
                                                         <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wider">Variant <i class="fas fa-info-circle ml-1 text-gray-500" title="Unique identifier for this specific version variant"></i></th>
                                                         <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wider">Date <i class="fas fa-calendar-alt ml-1 text-gray-500"></i></th> <!-- New Date column header -->
                                                         <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wider">Arch <i class="fas fa-microchip ml-1 text-gray-500"></i></th>
                                                         <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wider">Android <i class="fab fa-android ml-1 text-gray-500"></i></th>
                                                         <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wider">DPI <i class="fas fa-dot-circle ml-1 text-gray-500"></i></th>
                                                         <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wider">Size <i class="fas fa-file-lines ml-1 text-gray-500"></i></th>
                                                         <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wider">SHA1 <i class="fas fa-fingerprint ml-1 text-gray-500"></i></th> <!-- New SHA1 column header -->
                                                         <th scope="col" class="px-2 py-1 text-left md:text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase tracking-wider">Action <i class="fas fa-download ml-1 text-gray-500"></i></th>
                                                     </tr>
                                                 </thead>
                                                 <tbody class="bg-white dark:bg-transparent divide-y divide-gray-100 dark:divide-gray-700">
                                                     <?php foreach ($pVersion['variants'] as $variant): ?>
                                                         <tr>
                                                             <td class="px-2 py-1 whitespace-nowrap text-gray-700 dark:text-gray-300 text-xs"><?php echo htmlspecialchars($variant['variant_id'] ?: 'N/A'); ?></td>
                                                             <td class="px-2 py-1 whitespace-nowrap text-gray-700 dark:text-gray-300 text-xs">
                                                                 <?php 
                                                                    $formattedVariantDate = '';
                                                                    $variantTimestamp = strtotime($variant['date']);
                                                                    if ($variantTimestamp !== false) {
                                                                        $formattedVariantDate = date('M j, Y', $variantTimestamp);
                                                                    } else {
                                                                        $formattedVariantDate = htmlspecialchars($variant['date'] ?: 'N/A');
                                                                    }
                                                                    echo $formattedVariantDate; 
                                                                 ?>
                                                             </td> <!-- Variant Date column data -->
                                                             <td class="px-2 py-1 whitespace-nowrap text-gray-700 dark:text-gray-300 text-xs"><?php echo htmlspecialchars($variant['arch'] ?: 'N/A'); ?></td>
                                                             <td class="px-2 py-1 whitespace-nowrap text-gray-700 dark:text-gray-300 text-xs"><?php echo htmlspecialchars($variant['android_req'] ?: 'N/A'); ?></td>
                                                             <td class="px-2 py-1 whitespace-nowrap text-gray-700 dark:text-gray-300 text-xs"><?php echo htmlspecialchars($variant['dpi'] ?: 'N/A'); ?></td>
                                                             <td class="px-2 py-1 whitespace-nowrap text-gray-700 dark:text-gray-300 text-xs"><?php echo htmlspecialchars($variant['size'] ?: $pVersion['size'] ?: 'N/A'); ?></td>
                                                             <td class="px-2 py-1 whitespace-nowrap text-gray-700 dark:text-gray-300 text-xs text-ellipsis overflow-hidden" style="max-width: 130px;" title="<?php echo htmlspecialchars($variant['sha1'] ?: 'N/A'); ?>">
                                                                 <?php echo htmlspecialchars($variant['sha1'] ?: 'N/A'); ?>
                                                             </td> <!-- Increased max-width for SHA1 -->
                                                             <td class="px-2 py-1 whitespace-nowrap text-left md:text-center"> <!-- Added text-left md:text-center -->
                                                                 <?php if (!empty($variant['download_link'])): ?>
                                                                     <a href="<?php echo htmlspecialchars($variant['download_link']); ?>" class="inline-flex items-center justify-center px-2 py-1 border border-transparent rounded-md shadow-sm text-xs font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 md:hidden w-8 h-6"> <i class="fas fa-download"></i> </a>
                                                                     <a href="<?php echo htmlspecialchars($variant['download_link']); ?>" class="hidden md:inline-flex items-center px-2 py-1 border border-transparent rounded-md shadow-sm text-xs font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"> <i class="fas fa-download mr-1 -ml-0.5 h-3 w-3"></i> Download <?php echo htmlspecialchars($variant['type']); ?></a>
                                                                 <?php else: ?>
                                                                     <span class="text-gray-400 text-xs italic">Unavailable</span>
                                                                 <?php endif; ?>
                                                             </td>
                                                         </tr>
                                                     <?php endforeach; ?>
                                                 </tbody>
                                             </table>
                                         </div>
                                     <?php else: ?>
                                         <p class="text-gray-500 dark:text-gray-400 italic text-xs">No variants listed for this version.</p>
                                     <?php endif;
                                     if (!empty($pVersion['whats_new'])): ?>
                                         <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700">
                                             <h4 class="text-sm font-semibold mb-1 text-gray-800 dark:text-gray-200 flex items-center"><i class="fas fa-bullhorn text-gray-500 dark:text-gray-400 mr-2 text-sm"></i>What's New:</h4>
                                             <div class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed"><?php echo $pVersion['whats_new']; ?></div>
                                         </div>
                                     <?php endif; ?>
                                 </div>
                             </div>
                         <?php endfor; ?>
                     </div>
                     <?php if ($totalVersions > $displayCount): ?>
                         <button id="loadMoreVersions" class="mt-4 w-full bg-white hover:bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 font-semibold py-2 px-4 rounded-md transition-colors duration-200 text-sm border border-gray-200 dark:border-gray-700">
                             Load More Versions (<?php echo $totalVersions - $displayCount; ?> remaining)
                         </button>
                     <?php endif; ?>
                 </div>
                <?php elseif (!$error && empty($previousVersions) && !$isLocalApp): // Only show this if NOT a local app and no versions found ?>
                     <div class="mb-6 pt-4 border-t border-gray-100 dark:border-gray-800">
                         <h2 class="text-base font-semibold mb-3 text-gray-800 dark:text-gray-100 flex items-center"><i class="fas fa-history text-gray-500 dark:text-gray-400 mr-2 text-base"></i>Previous Versions & Other Versions</h2> <!-- Changed title -->
                         <p class="text-gray-500 dark:text-gray-400 text-xs italic">No previous versions found on the source site.</p>
                     </div>
                <?php endif; ?>

                <div class="mb-6 pt-4 border-t border-gray-100 dark:border-gray-800">
                    <h2 class="text-base font-semibold mb-3 text-gray-800 dark:text-gray-100 flex items-center"><i class="fas fa-question-circle text-gray-500 dark:text-gray-400 mr-2 text-base"></i>Frequently Asked Questions</h2>
                    <div id="faqAccordion" class="space-y-2 text-xs text-gray-700 dark:text-gray-300">
                        <div class="faq-item rounded-md overflow-hidden">
                            <button class="faq-header flex justify-between items-center w-full px-4 py-3 text-sm font-semibold transition-colors duration-200 focus:outline-none" data-target="#faq-body-0" aria-expanded="false" aria-controls="faq-body-0">
                                <span class="flex items-center"><i class="fas fa-mobile-alt mr-2 text-blue-600"></i>What is an APK?</span><i class="fas fa-chevron-down transform transition-transform duration-200"></i>
                            </button>
                            <div id="faq-body-0" class="faq-body" aria-labelledby="faq-header-0"><p class="leading-relaxed">An APK (Android Package Kit) is the package file format used by the Android operating system for distribution and installation of mobile apps.</p></div>
                        </div>
                        <div class="faq-item rounded-md overflow-hidden">
                            <button class="faq-header flex justify-between items-center w-full px-4 py-3 text-sm font-semibold transition-colors duration-200 focus:outline-none" data-target="#faq-body-1" aria-expanded="false" aria-controls="faq-body-1">
                                <span class="flex items-center"><i class="fas fa-box-open mr-2 text-purple-600"></i>What is an XAPK?</span><i class="fas fa-chevron-down transform transition-transform duration-200"></i>
                            </button>
                            <div id="faq-body-1" class="faq-body" aria-labelledby="faq-header-1"><p class="leading-relaxed">An XAPK is a package format for Android apps that contains the main APK file and additional OBB (Opaque Binary Blob) or split APK files, often used for large apps or games with significant data.</p></div>
                        </div>
                        <div class="faq-item rounded-md overflow-hidden">
                            <button class="faq-header flex justify-between items-center w-full px-4 py-3 text-sm font-semibold transition-colors duration-200 focus:outline-none" data-target="#faq-body-2" aria-expanded="false" aria-controls="faq-body-2">
                                <span class="flex items-center"><i class="fas fa-shield-alt mr-2 text-green-600"></i>Are APKs/XAPKs from this source safe?</span><i class="fas fa-chevron-down transform transition-transform duration-200"></i>
                            </button>
                            <div id="faq-body-2" class="faq-body" aria-labelledby="faq-header-2"><p class="leading-relaxed">Yes, your safety is our priority. Our files are sourced directly from the Google Play Store and uploaded by verified users. Each file is scanned by Google Play Protect and undergoes additional integrity checks on our end. They are signed with SHA1, ensuring the file you download is the original, untampered version. You can download with confidence knowing our files are safe and secure.</p></div>
                        </div>
                         <div class="faq-item rounded-md overflow-hidden">
                            <button class="faq-header flex justify-between items-center w-full px-4 py-3 text-sm font-semibold transition-colors duration-200 focus:outline-none" data-target="#faq-body-3" aria-expanded="false" aria-controls="faq-body-3">
                                <span class="flex items-center"><i class="fas fa-mobile mr-2 text-indigo-600"></i>How to install APK/XAPK files?</span><i class="fas fa-chevron-down transform transition-transform duration-200"></i>
                            </button>
                            <div id="faq-body-3" class="faq-body" aria-labelledby="faq-header-3"><p class="leading-relaxed">For APK files, simply download and open the file. You may need to enable installation from unknown sources in your device settings. For XAPK files, you typically need a dedicated XAPK installer app or a file manager that supports the format to install them correctly, as they contain additional data or split APKs.</p></div>
                        </div>
                    </div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const faqHeaders = document.querySelectorAll('#faqAccordion .faq-header');
                        faqHeaders.forEach(header => {
                            header.addEventListener('click', () => {
                                const targetId = header.getAttribute('data-target');
                                const targetBody = document.querySelector(targetId);
                                const arrowIcon = header.querySelector('.fa-chevron-down');
                                
                                // Close all other FAQ bodies and reset their arrows
                                document.querySelectorAll('#faqAccordion .faq-body').forEach(body => {
                                    if (body !== targetBody) {
                                        body.style.display = 'none'; // Use direct style manipulation
                                        const openHeader = body.previousElementSibling;
                                        if (openHeader && openHeader.classList.contains('faq-header')) {
                                            openHeader.querySelector('.fa-chevron-down').classList.remove('rotate-180');
                                            openHeader.setAttribute('aria-expanded', 'false');
                                        }
                                    }
                                });

                                // Toggle the clicked FAQ
                                if (targetBody.style.display === 'block') { // Check current display style
                                    targetBody.style.display = 'none';
                                    arrowIcon.classList.remove('rotate-180');
                                    header.setAttribute('aria-expanded', 'false');
                                } else {
                                    targetBody.style.display = 'block'; // Use direct style manipulation
                                    arrowIcon.classList.add('rotate-180');
                                    header.setAttribute('aria-expanded', 'true');
                                }
                            });
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>

    <aside class="w-full lg:w-1/3 lg:flex-shrink-0">
        <div class="lg:sticky lg:top-4 space-y-4"> <!-- Adjusted top margin here -->
            <?php if (!$error && !empty($appDetails['related_apps'])): ?>
                <div class="bg-white dark:bg-gray-900 rounded-md shadow-sm p-3">
                     <h3 class="text-base font-bold mb-2 text-gray-800 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2 flex items-center">
                        <i class="fas fa-star text-orange-400 mr-2"></i> <?php echo htmlspecialchars($appDetails['related_title'] ?? 'You May Also Like'); ?>
                    </h3>
                    <div class="space-y-2 mt-2"> <!-- Removed max-h and overflow classes -->
                         <?php
                         $relatedAppsToShow = array_slice($appDetails['related_apps'], 0, SIDEBAR_RELATED_APPS_DISPLAY_COUNT);
                         foreach ($relatedAppsToShow as $relatedApp):
                            $relatedLink = $relatedApp['link'] ?? '#'; $relatedTitle = htmlspecialchars($relatedApp['title'] ?? 'App');
                            $relatedIcon = htmlspecialchars($relatedApp['icon'] ?? ''); $relatedDesc = htmlspecialchars($relatedApp['description'] ?? '');
                            $relatedRating = htmlspecialchars($relatedApp['rating'] ?? ''); $relatedReviewsParsed = !empty($relatedApp['reviews']) ? parseReviewCount($relatedApp['reviews']) : '';
                            if ($relatedLink !== '#'):
                         ?>
                            <a href="<?php echo $relatedLink; ?>" title="View <?php echo $relatedTitle; ?>" class="flex items-center space-x-3 p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors duration-200 border border-transparent hover:border-gray-200 dark:hover:border-gray-700 group">
                                <div class="flex-shrink-0">
                                    <?php if (!empty($relatedIcon)): ?>
                                        <!-- Increased icon size for sidebar apps (w-16 h-16) -->
                                        <img src="<?php echo $relatedIcon; ?>" class="w-16 h-16 rounded-md object-cover border border-gray-100 dark:border-gray-800 bg-gray-100 dark:bg-gray-800" alt="<?php echo $relatedTitle; ?> Icon" loading="lazy" onerror="this.onerror=null; this.src='assets/images/app-placeholder.png'; this.classList.add('object-contain');">
                                    <?php else: ?>
                                        <div class="w-16 h-16 bg-gray-200 dark:bg-gray-800 rounded-md flex items-center justify-center border border-gray-100 dark:border-gray-800"><i class="fas fa-mobile-alt text-gray-400 text-3xl"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate group-hover:text-blue-600 dark:group-hover:text-blue-400"><?php echo $relatedTitle; ?></h4>
                                    <?php if (!empty($relatedDesc)): ?><p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-1 mt-0.5"><?php echo $relatedDesc; ?></p><?php endif; ?>
                                    <div class="flex items-center text-[10px] mt-1.5 space-x-2 flex-wrap">
                                        <?php if (!empty($relatedRating)): ?><span class="inline-flex items-center bg-orange-500 text-white px-1.5 py-0.5 rounded-md font-medium text-xs"><i class="fas fa-star mr-1"></i><?php echo $relatedRating; ?></span><?php endif; ?>
                                        <?php if (!empty($relatedReviewsParsed)): ?><span class="inline-flex items-center bg-gray-200 text-gray-700 px-1.5 py-0.5 rounded-md font-medium text-xs"><i class="fas fa-users mr-1"></i><?php echo $relatedReviewsParsed; ?></span><?php endif; ?>
                                    </div>
                                </div>
                                 <i class="fas fa-chevron-right text-gray-400 text-xs ml-auto group-hover:text-blue-500 dark:group-hover:text-blue-400 transition-colors duration-200 flex-shrink-0"></i>
                            </a>
                             <?php endif;
                        endforeach; ?>
                    </div>
                    <?php if (count($appDetails['related_apps']) > SIDEBAR_RELATED_APPS_DISPLAY_COUNT): ?>
                        <div class="mt-3">
                             <a href="/category/<?php echo rawurlencode($appDetails['category']); ?>" class="block w-full text-center bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 font-semibold py-2 px-4 rounded-md transition-colors duration-200 text-sm">
                                View More Alternatives
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif (!$error && !$isLocalApp): // Only show alternative apps if NOT a local app ?>
                 <div class="bg-white dark:bg-gray-900 rounded-md shadow-sm p-4">
                     <h3 class="text-base font-bold mb-3 text-gray-800 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2 flex items-center"><i class="fas fa-star text-orange-400 mr-2"></i> You May Also Like</h3>
                     <div class="bg-gray-100 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 px-3 py-2 rounded-md text-sm" role="alert"><i class="fas fa-info-circle mr-1"></i> Could not load alternative apps.</div>
                 </div>
             <?php endif; ?>

            <?php if (!$error && !empty($appDetails['developer_apps'])): ?>
                <div class="bg-white dark:bg-gray-900 rounded-md shadow-sm p-3">
                    <!-- Made sidebar title a clickable link/button -->
                     <h3 class="text-base font-bold mb-2 text-gray-800 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2 flex items-center">
                        <i class="fas fa-cubes text-teal-400 mr-2"></i> <!-- Changed icon here -->
                        <?php if (!empty($appDetails['developer_link'])): ?>
                            <a href="<?php echo htmlspecialchars($appDetails['developer_link']); ?>" target="_blank" rel="noopener noreferrer" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors duration-150">
                                More from <?php echo htmlspecialchars($appDetails['developer']); ?> <i class="fas fa-external-link-alt text-xs opacity-60 ml-1.5"></i>
                            </a>
                        <?php else: ?>
                            More from <?php echo htmlspecialchars($appDetails['developer']); ?>
                        <?php endif; ?>
                    </h3>
                    <div class="space-y-2 mt-2"> <!-- Removed max-h and overflow classes -->
                         <?php 
                         $devAppsToShow = array_slice($appDetails['developer_apps'], 0, SIDEBAR_DEV_APPS_DISPLAY_COUNT);
                         foreach ($devAppsToShow as $devApp):
                            $devAppLink = $devApp['link'] ?? '#';
                            $devAppTitle = htmlspecialchars($devApp['title'] ?? 'App');
                            $devAppIcon = htmlspecialchars($devApp['icon'] ?? '');
                            $devAppDesc = htmlspecialchars($devApp['description'] ?? '');
                            $devAppRating = htmlspecialchars($devApp['rating'] ?? '');
                            $devAppReviewsParsed = !empty($devApp['reviews']) ? parseReviewCount($devApp['reviews']) : '';
                            if ($devAppLink !== '#'):
                         ?>
                            <a href="<?php echo $devAppLink; ?>" title="View <?php echo $devAppTitle; ?>" class="flex items-center space-x-3 p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors duration-200 border border-transparent hover:border-gray-200 dark:hover:border-gray-700 group">
                                <div class="flex-shrink-0">
                                    <?php if (!empty($devAppIcon)): ?>
                                        <!-- Increased icon size for sidebar apps (w-16 h-16) -->
                                        <img src="<?php echo $devAppIcon; ?>" class="w-16 h-16 rounded-md object-cover border border-gray-100 dark:border-gray-800 bg-gray-100 dark:bg-gray-800" alt="<?php echo $devAppTitle; ?> Icon" loading="lazy" onerror="this.onerror=null; this.src='assets/images/app-placeholder.png'; this.classList.add('object-contain');">
                                    <?php else: ?>
                                        <div class="w-16 h-16 bg-gray-200 dark:bg-gray-800 rounded-md flex items-center justify-center border border-gray-100 dark:border-gray-800"><i class="fas fa-mobile-alt text-gray-400 text-3xl"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate group-hover:text-blue-600 dark:group-hover:text-blue-400"><?php echo $devAppTitle; ?></h4>
                                    <?php if (!empty($devAppDesc)): ?><p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-1 mt-0.5"><?php echo $devAppDesc; ?></p><?php endif; ?>
                                    <div class="flex items-center text-[10px] mt-1.5 space-x-2 flex-wrap">
                                        <?php if (!empty($devAppRating)): ?><span class="inline-flex items-center bg-orange-500 text-white px-1.5 py-0.5 rounded-md font-medium text-xs"><i class="fas fa-star mr-1"></i><?php echo $devAppRating; ?></span><?php endif; ?>
                                        <?php if (!empty($devAppReviewsParsed)): ?><span class="inline-flex items-center bg-gray-200 text-gray-700 px-1.5 py-0.5 rounded-md font-medium text-xs"><i class="fas fa-users mr-1"></i><?php echo $devAppReviewsParsed; ?></span><?php endif; ?>
                                    </div>
                                </div>
                                 <i class="fas fa-chevron-right text-gray-400 text-xs ml-auto group-hover:text-blue-500 dark:group-hover:text-blue-400 transition-colors duration-200 flex-shrink-0"></i>
                            </a>
                             <?php endif;
                        endforeach; ?>
                    </div>
                    <?php if (count($appDetails['developer_apps']) > SIDEBAR_DEV_APPS_DISPLAY_COUNT && !empty($appDetails['developer_link'])): ?>
                        <div class="mt-3">
                             <a href="<?php echo htmlspecialchars($appDetails['developer_link']); ?>" target="_blank" rel="noopener noreferrer" class="block w-full text-center bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 font-semibold py-2 px-4 rounded-md transition-colors duration-200 text-sm">
                                More apps from <?php echo htmlspecialchars($appDetails['developer']); ?> <i class="fas fa-external-link-alt text-xs opacity-60 ml-1.5"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php /* Updated QR Code Modal HTML structure */ ?>
<div id="qrCodeModal" class="hidden">
    <div class="qr-modal-content">
        <button id="qrCodeModalCloseX" class="qr-modal-close-x" aria-label="Close QR Code Modal">&times;</button>

        <div class="qr-modal-notice">
            <i class="fas fa-mobile-alt mr-2"></i>
            <span>Scan to open this page on your device.</span>
        </div>

        <img id="qrCodeModalImage" src="" alt="QR Code for page link">

        <div id="qrCodeModalUrl" class="qr-modal-url"></div>

        <div class="qr-modal-buttons">
            <button id="qrCodeCopyButton" class="copy-btn"><i class="fas fa-copy mr-1"></i>Copy URL</button>
            <?php
            // Social Share Options
            $shareText = urlencode("Check out " . $appNameForTitle . " on " . USER_DOMAIN . ": " . $currentUrl);
            $shareUrl = urlencode($currentUrl);
            ?>
            <a href="https://telegram.me/share/url?url=<?php echo $shareUrl; ?>&text=<?php echo $shareText; ?>" target="_blank" rel="noopener noreferrer" class="share-btn">
                <i class="fab fa-telegram-plane mr-1"></i>Telegram
            </a>
            <a href="https://wa.me/?text=<?php echo $shareText; ?>" target="_blank" rel="noopener noreferrer" class="share-btn">
                <i class="fab fa-whatsapp mr-1"></i>WhatsApp
            </a>
            <a href="mailto:?subject=Check%20out%20this%20app&body=<?php echo $shareText; ?>" target="_blank" rel="noopener noreferrer" class="share-btn">
                <i class="fas fa-envelope mr-1"></i>Email
            </a>
            <a href="sms:?body=<?php echo $shareText; ?>" target="_blank" rel="noopener noreferrer" class="share-btn">
                <i class="fas fa-sms mr-1"></i>SMS
            </a>
        </div>
    </div>
</div>


<script>
    // Screenshot scroller button logic
    (function() {
        const ssContainer = document.getElementById('screenshotContainer');
        const ssPrevBtn = document.getElementById('scrollPrevButton');
        const ssNextBtn = document.getElementById('scrollNextButton');
        function updateScrollButtons() {
            if (!ssContainer || !ssPrevBtn || !ssNextBtn) return;
            setTimeout(() => {
                const scrollLeft = ssContainer.scrollLeft; const maxScrollLeft = ssContainer.scrollWidth - ssContainer.clientWidth;
                ssPrevBtn.disabled = scrollLeft <= 1; ssNextBtn.disabled = maxScrollLeft <= ssContainer.clientWidth + 1;
                if (maxScrollLeft > 0 && scrollLeft >= maxScrollLeft - 1) ssNextBtn.disabled = true;
            }, 50);
        }
        if (ssContainer && ssPrevBtn && ssNextBtn) {
            const scrollAmount = ssContainer.clientWidth * 0.75;
            ssPrevBtn.addEventListener('click', () => ssContainer.scrollBy({ left: -scrollAmount, behavior: 'smooth' }));
            ssNextBtn.addEventListener('click', () => ssContainer.scrollBy({ left: scrollAmount, behavior: 'smooth' }));
            let scrollTimeout; ssContainer.addEventListener('scroll', () => { clearTimeout(scrollTimeout); scrollTimeout = setTimeout(updateScrollButtons, 100); }, { passive: true });
            let resizeTimeout; window.addEventListener('resize', () => { clearTimeout(resizeTimeout); resizeTimeout = setTimeout(updateScrollButtons, 150); });
            const observer = new MutationObserver(updateScrollButtons); observer.observe(ssContainer, { childList: true });
            const images = ssContainer.getElementsByTagName('img');
            if (images.length > 0) for (let img of images) if (!img.complete) { img.addEventListener('load', updateScrollButtons); img.addEventListener('error', updateScrollButtons); }
            setTimeout(updateScrollButtons, 100); window.addEventListener('load', () => setTimeout(updateScrollButtons, 500));
        }
    })();

    document.addEventListener('DOMContentLoaded', () => {
        // Version Accordion Logic
        const versionHeaders = document.querySelectorAll('.version-header');
        const versionItems = document.querySelectorAll('.version-item');
        const loadMoreButton = document.getElementById('loadMoreVersions');
        const initialDisplayCount = <?php echo VERSIONS_INITIAL_DISPLAY_COUNT; ?>;
        versionItems.forEach((item, index) => { if (index >= initialDisplayCount) item.classList.add('hidden'); });
        versionHeaders.forEach(header => {
            header.addEventListener('click', () => {
                const targetId = header.getAttribute('data-target'); const targetBody = document.querySelector(targetId);
                const arrowIcon = header.querySelector('.fa-chevron-down'); const isExpanded = header.getAttribute('aria-expanded') === 'true';
                document.querySelectorAll('.version-body:not(.hidden)').forEach(body => {
                    if (body !== targetBody) { body.classList.add('hidden'); const openHeader = body.previousElementSibling;
                        if (openHeader && openHeader.classList.contains('version-header')) { openHeader.querySelector('.fa-chevron-down').classList.remove('rotate-180'); openHeader.setAttribute('aria-expanded', 'false'); }
                    }
                });
                if (isExpanded) { targetBody.classList.add('hidden'); arrowIcon.classList.remove('rotate-180'); header.setAttribute('aria-expanded', 'false'); }
                else { targetBody.classList.remove('hidden'); arrowIcon.classList.add('rotate-180'); header.setAttribute('aria-expanded', 'true'); }
            });
        });
        if (loadMoreButton) {
            loadMoreButton.addEventListener('click', () => { versionItems.forEach(item => item.classList.remove('hidden')); loadMoreButton.style.display = 'none'; });
            if (versionItems.length <= initialDisplayCount) loadMoreButton.style.display = 'none';
        }

        // QR Code Popup Logic
        const qrPopupButton = document.getElementById('qrPopupButton');
        const qrCodeModal = document.getElementById('qrCodeModal');
        const qrCodeModalImage = document.getElementById('qrCodeModalImage');
        const qrCodeModalUrl = document.getElementById('qrCodeModalUrl');
        const qrCodeCopyButton = document.getElementById('qrCodeCopyButton');
        const qrCodeModalCloseX = document.getElementById('qrCodeModalCloseX');

        const pageUrl = <?php echo json_encode($currentUrl); ?>;
        const pageQrImageUrl = <?php echo json_encode($qrShareUrl); ?>;

        function openQrModal() {
            if (!qrCodeModal || !qrCodeModalImage || !qrCodeModalUrl) return;
            qrCodeModalImage.src = pageQrImageUrl;
            qrCodeModalUrl.textContent = pageUrl;
            qrCodeModal.classList.add('active');
            qrCodeModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeQrModal() {
            if (!qrCodeModal) return;
            qrCodeModal.classList.remove('active');
            qrCodeModal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        if (qrPopupButton) qrPopupButton.addEventListener('click', openQrModal);
        if (qrCodeModalCloseX) qrCodeModalCloseX.addEventListener('click', closeQrModal);

        if (qrCodeModal) qrCodeModal.addEventListener('click', (event) => {
            if (event.target === qrCodeModal) closeQrModal();
        });

        if (qrCodeCopyButton && pageUrl) {
            qrCodeCopyButton.addEventListener('click', () => {
                document.execCommand('copy'); // Use document.execCommand for clipboard copy due to iframe restrictions
                qrCodeCopyButton.innerHTML = '<i class="fas fa-check mr-1"></i>Copied!';
                setTimeout(() => { qrCodeCopyButton.innerHTML = '<i class="fas fa-copy mr-1"></i>Copy URL'; }, 2000);
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && qrCodeModal && qrCodeModal.classList.contains('active')) closeQrModal();
        });
    });
</script>

<?php include 'includes/footer.php'; ?>