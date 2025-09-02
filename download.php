<?php
// --- Route: /appid/download ---

// Error reporting for debugging (consider turning off/down in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Configuration ---
define('USER_DOMAIN', 'Yandux.Biz');
define('SOURCE_DOMAIN', 'apkfab.com');
define('DESCRIPTION_QUICK_OVERVIEW_LENGTH', 550);

// --- Helper Functions ---

/**
 * Safely encodes HTML entities for display. Decodes existing entities first.
 */
function safe_htmlspecialchars(string $string): string {
    return htmlspecialchars(html_entity_decode($string, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

/**
 * Formats a date string into "Month Day, Year" (e.g., "May 10, 2025").
 * @param string $dateString The raw date string.
 * @return string The formatted date string, or an empty string if input is empty or unparseable.
 */
function formatDisplayDate(string $dateString): string {
    $trimmedDateString = trim($dateString);
    if (empty($trimmedDateString)) {
        return '';
    }
    // Common non-date values that might be scraped
    if (in_array(strtolower($trimmedDateString), ['n/a', 'unknown', 'varies with device'])) {
        return '';
    }

    $timestamp = strtotime($trimmedDateString);
    if ($timestamp === false) {
        // Attempt to remove "Updated: " or similar prefixes if strtotime failed
        $cleanedDateString = preg_replace('/^(updated on|update on|updated|update date):\s*/i', '', $trimmedDateString);
        $timestamp = strtotime($cleanedDateString);
        if ($timestamp === false) {
            return ''; // Could not parse
        }
    }
    return date('F j, Y', $timestamp);
}


/**
 * Checks if a string is a plausible version number.
 * @param string $versionCandidate The string to check.
 * @return bool True if it seems like a valid version string, false otherwise.
 */
function isValidVersionString(string $versionCandidate): bool {
    if (empty($versionCandidate)) return false;

    // Remove common prefixes and 'v'
    $cleanedVersion = trim(str_ireplace(
        ['Version:', 'Latest Version:', 'v', 'Update on:', 'Updated on:', 'Update', 'Updated', 'Date:', 'Release:'],
        '',
        $versionCandidate
    ));

    if (empty($cleanedVersion)) return false;

    // Check for full month names or common date patterns that are not versions
    $monthIndicators = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'January', 'February', 'March', 'April', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    foreach ($monthIndicators as $month) {
        if (stripos($cleanedVersion, $month) !== false) {
            // If it looks like "Month Day, Year" or "Day Month Year" it's likely a date not a version
            // Allow cases like "1.0-January" or "Build January" but not just "January 2024"
            if (!preg_match('/[0-9][\s-]*'.$month.'/i', $cleanedVersion) && !preg_match('/'.$month.'[\s-]*[0-9]/i', $cleanedVersion) && preg_match('/'.$month.'[\s,]+[0-9]{1,2}([\s,]+[0-9]{2,4})?/i', $cleanedVersion)) {
                 return false; // Looks like a date "Month Day, YEAR"
            }
        }
    }
    
    // Reject if it's purely a date format like DD/MM/YYYY orYYYY-MM-DD
    if (preg_match('/^\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}$/', $cleanedVersion)) return false; 
    if (preg_match('/^\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2}$/', $cleanedVersion)) return false; 


    // Relaxed length check: Allow up to 70 characters for complex version strings
    if (strlen($cleanedVersion) > 70) { 
        return false;
    }

    // Reject if it has too many spaces without typical version keywords (alpha, beta, etc.)
    if (substr_count($cleanedVersion, ' ') > 2 && !preg_match('/(alpha|beta|rc|nightly|stable|final|build|snapshot|ga)/i', $cleanedVersion)) {
        return false;
    }
    
    // Special check for 4-digit numbers that could be years, allow if prefixed with 'v' in original
    if (preg_match('/^(19|20)\d{2}$/', $cleanedVersion) && strlen($cleanedVersion) == 4) {
        // If the original candidate did not start with 'v' (or similar version indicators), it's likely a year.
        if (strtolower(substr($versionCandidate, 0, 1)) !== 'v') {
            return false;
        }
    }

    // General pattern for versions (numbers, dots, hyphens, underscores, common version keywords)
    // Must contain at least one digit or a version keyword
    if (preg_match('/^[a-zA-Z0-9]+([._-][a-zA-Z0-9]+)*$/', $cleanedVersion)) {
        if (preg_match('/\d/', $cleanedVersion) || preg_match('/(alpha|beta|rc|nightly|stable|final|build|snapshot|ga)/i', $cleanedVersion)) {
            // Avoid matching "0" or "00" unless it's part of a more complex version like "0.1"
            if (preg_match('/^0+$/', $cleanedVersion) && strlen($cleanedVersion) < 3 && strpos($versionCandidate, '.') === false && strpos($versionCandidate, '-') === false) {
                return false; 
            }
            return true;
        }
    }

    return false;
}


/**
 * Fetches HTML content from a given URL using cURL.
 */
function fetchHtml($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36', // Updated User-Agent
        CURLOPT_SSL_VERIFYPEER => false, // Consider security implications for production
        CURLOPT_TIMEOUT => 30, // Increased timeout
        CURLOPT_CONNECTTIMEOUT => 15, // Increased connect timeout
         CURLOPT_HTTPHEADER => [ // More comprehensive headers
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
             'Sec-Fetch-Dest: document',
             'Sec-Fetch-Mode: navigate',
             'Sec-Fetch-Site: same-origin', // Or 'none' if appropriate
             'Sec-Fetch-User: ?1',
             'Upgrade-Insecure-Requests: 1',
             'Referer: https://' . SOURCE_DOMAIN . '/' // Adding a referer
        ]
    ]);
    $response = curl_exec($ch);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || !$response || $httpCode >= 400) {
        $logMsg = "Failed to fetch $url. HTTP Code: $httpCode. Curl Error: $err";
        error_log($logMsg); // Log the error
         return ['error' => $err ?: "Failed to fetch (HTTP $httpCode): $url"];
    }
    return $response;
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
 * Processes raw description HTML.
 */
function processDescription(string $desc): string {
    // Remove specific "read more" links pointing back to the source
    $desc = preg_replace('/<a[^>]+href=["\'][^"\']*' . preg_quote(SOURCE_DOMAIN, '/') . '[^"\']*["\'][^>]*>\s*read more\s*<\/a>/i', '', $desc);
    $desc = preg_replace('/read more/i', '', $desc); // Also remove plain "read more" text
    // Auto-link plain URLs that are not already links
    $desc = preg_replace_callback('/(?<!href=["\'])(https?:\/\/[^\s"<]+)/i', function($matches) {
        return '<a href="' . safe_htmlspecialchars($matches[0]) . '" target="_blank" rel="nofollow noopener">' . safe_htmlspecialchars($matches[0]) . '</a>';
    }, $desc);
    // Format specific headings like "Editor's Review", "About", "What's New"
    $pattern = '/(<p><strong>)(Editor\'s Review|About|What\'s New)(.*?<\/strong><\/p>)/is'; // Added 's' modifier for dotall
     $desc = preg_replace_callback($pattern, function($match) {
         // Keep the strong tag content but wrap in a styled paragraph
         return '<p class="text-sm font-semibold text-blue-700">' . safe_htmlspecialchars($match[2]) . '</p>' . trim(str_replace($match[1].$match[2].$match[3], '', $match[0]));
     }, $desc);
    return $desc;
}

/**
 * Creates a URL-friendly slug from a string.
 * Handles Unicode characters and common separators.
 * @param string $string Input string.
 * @return string URL slug.
 */
function slugify(string $string): string {
    $slug = strtolower(trim($string));
    // Replace non-alphanumeric characters (except dash) with dash
    $slug = preg_replace('/[^a-z0-9\p{L}]+/u', '-', $slug); // Added \p{L} for Unicode letters, 'u' modifier for Unicode
    $slug = preg_replace('/-+/', '-', $slug); // Replace multiple dashes with a single dash
    $slug = trim($slug, '-'); // Trim dashes from the beginning and end
    // URL encode the slug to handle non-ASCII characters safely in the URL path
    // Use rawurlencode for path segments
    $slug = rawurlencode($slug);
    return $slug; // No longer defaults to 'app', fallback handled at call site
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

    // If slug is still empty at this point, it means no suitable slug was found in the URL structure.
    // The calling code (extractAppDetails) will handle generating a slug from the app name if needed.

    return ['slug' => $slug, 'package_name' => $packageName];
}


/**
 * Makes a URL absolute or relative to the user's domain.
 */
function processLink(string $url, string $sourceBaseUrl, string $userBaseUrl): string {
    if (empty($url) || $url === '#') return '';
    $url = trim($url);
    $sourceBaseUrl = rtrim($sourceBaseUrl, '/');
    $sourceHost = parse_url($sourceBaseUrl, PHP_URL_HOST);
    // $userHost = parse_url($userBaseUrl, PHP_URL_HOST); // Not strictly needed here

    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) { // Absolute URL
        $urlHost = parse_url($url, PHP_URL_HOST);
        if ($urlHost === $sourceHost) { // Points to source domain
            $path = parse_url($url, PHP_URL_PATH) ?? '/';
            $query = parse_url($url, PHP_URL_QUERY);
            return rtrim($path, '/') . ($query ? '?' . $query : ''); // Make it root-relative
        } else { // External link
            return $url;
        }
    }
    elseif (strpos($url, '//') === 0) { // Protocol-relative URL
         $tempFullUrl = 'https:' . $url; // Assume HTTPS for parsing host
         $urlHost = parse_url($tempFullUrl, PHP_URL_HOST);
         if ($urlHost === $sourceHost) { // Points to source domain
            $path = parse_url($tempFullUrl, PHP_URL_PATH) ?? '/';
            $query = parse_url($tempFullUrl, PHP_URL_QUERY);
            return rtrim($path, '/') . ($query ? '?' . $query : ''); // Make it root-relative
         } else { // External protocol-relative link
             return $url;
         }
    }
    elseif (strpos($url, '/') === 0) { // Already root-relative
        return rtrim($url, '/');
    }
    else { // Relative path (e.g., "image.jpg" or "some/path/image.jpg")
         // Assume it's relative to the source's base URL's root or a specific path if known
         // For simplicity here, making it relative to source base.
         // More robust handling might require knowing the current page's path on the source.
         return $sourceBaseUrl . '/' . ltrim($url, './');
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
 * Fetches and extracts comprehensive app details from the main app page.
 */
function fetchAndParseAppDetails(string $appUrl, string $sourceBaseUrl): array {
    $html = fetchHtml($appUrl);
    if (is_array($html) && isset($html['error'])) return $html;
    if (empty($html)) return ['error' => 'Received empty response from source when fetching app details.'];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!@$dom->loadHTML($html)) {
        libxml_clear_errors(); return ['error' => 'Failed to parse app details from source HTML.'];
    }
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $appDetails = ['error' => null, 'related_apps' => [], 'category' => '', 'category_link' => '', 'version_name' => '', 'update_date' => '', 'rating' => '', 'review_count' => ''];

    // Main details container
    $detailsContainerNode = $xpath->query("//div[contains(@class, 'detail_banner')] | //div[contains(@class, 'app_info')] | //section[contains(@class, 'head-widget')]")->item(0);
    if (!$detailsContainerNode) {
        return ['error' => 'Could not find main app details container on source page.'];
    }

    // App Name
    $nameNode = $xpath->query(".//h1", $detailsContainerNode)->item(0) ?? $xpath->query(".//div[@class='title']/h1", $detailsContainerNode)->item(0);
    $appDetails['name'] = $nameNode ? trim($nameNode->textContent) : '';

    // App Icon
    $iconNode = $xpath->query(".//img[contains(@class, 'icon')]", $detailsContainerNode)->item(0) ?? $xpath->query(".//div[@class='icon']/img", $detailsContainerNode)->item(0);
    $iconSrc = $iconNode ? ($iconNode->getAttribute('data-src') ?: $iconNode->getAttribute('src')) : '';
    $appDetails['icon'] = $iconSrc ? processLink($iconSrc, $sourceBaseUrl, 'https://' . USER_DOMAIN) : '';

    // Rating and Review Count for Main App (using more robust selectors from info.php)
    $ratingNode = $xpath->query("//span[contains(@class, 'rating')]//span[contains(@class, 'star_icon')] | //div[@class='stars']/@data-rating | .//div[contains(@class, 'score_num')] | .//span[contains(@class, 'score_num')] | .//div[contains(@class, 'score')]//span[@class='num']", $detailsContainerNode)->item(0);
    $appDetails['rating'] = $ratingNode ? (
        $ratingNode->nodeName === 'div' && $ratingNode->hasAttribute('data-rating') ? trim($ratingNode->getAttribute('data-rating')) : trim($ratingNode->textContent)
    ) : '';

    $reviewCountNode = $xpath->query("//span[contains(@class, 'review_icon')] | //a[@href='#reviews']/span[contains(@class,'num')] | .//span[contains(@class, 'num_reviews')] | .//span[contains(@class, 'reviews_count')] | .//div[contains(@class, 'score')]//span[@class='reviews']", $detailsContainerNode)->item(0);
    if ($reviewCountNode) {
        $rawReviewCount = trim($reviewCountNode->textContent);
        // Use parseReviewCount to handle various formats (e.g., "1.2M", "5,000", "1234")
        $appDetails['review_count'] = parseReviewCount($rawReviewCount);
    }


    // Version Name Extraction (Primary Selectors from banner/app_info)
    $versionNode = $xpath->query(".//span[contains(@style, 'color: #0284fe')]", $detailsContainerNode)->item(0) ?? $xpath->query(".//span[contains(text(), 'Version:')]", $detailsContainerNode)->item(0) ?? $xpath->query("//dt[text()='Version']/following-sibling::dd[1]")->item(0);
    $potentialVersion = $versionNode ? trim(str_ireplace(['Version:', 'Latest Version:', 'v'], '', $versionNode->textContent)) : '';
    if (isValidVersionString($potentialVersion)) {
        $appDetails['version_name'] = $potentialVersion;
    }
    
    // Update Date Extraction (Primary Selectors from banner/app_info)
    $updateDateNode = $xpath->query(".//span[contains(text(), 'Update on:')]", $detailsContainerNode)->item(0) ?? $xpath->query("//dt[text()='Update Date']/following-sibling::dd[1]")->item(0) ?? $xpath->query(".//span[contains(text(), 'Updated:')]", $detailsContainerNode)->item(0);
    if ($updateDateNode) {
        $appDetails['update_date'] = trim(str_replace(['Update on:', 'Updated:', 'Update Date:'], '', $updateDateNode->textContent));
    }

    // Fallback: More Information Section Parsing (for version and update date if still empty)
    $moreInfoItems = [];
    // DL/DT/DD structure
    $infoNodesDL = $xpath->query("//div[contains(@class, 'detail_more_info')]//dl/dt | //div[@class='details-section-contents']//div[@class='meta-info']/div[@class='title']");
    if ($infoNodesDL && $infoNodesDL->length > 0) {
        foreach ($infoNodesDL as $labelNode) {
            $label = trim(rtrim($labelNode->textContent, ':'));
            $valueNode = $xpath->query("./following-sibling::dd[1] | ./following-sibling::div[@class='description'][1]", $labelNode)->item(0);
            $valueText = $valueNode ? trim($valueNode->textContent) : '';
            if (!empty($label) && !empty($valueText)) {
                $moreInfoItems[strtolower($label)] = $valueText;
            }
        }
    }
    // Simpler p-tag based "More Info" structure (e.g., some APKPure layouts)
    $infoNodesP = $xpath->query("//div[contains(@class, 'detail_more_info')]//div[contains(@class, 'item')]/p[1] | //div[contains(@class, 'app-info')]//div[contains(@class, 'info')]/p[1]");
     if ($infoNodesP && $infoNodesP->length > 0) {
         foreach ($infoNodesP as $labelNode) {
             $label = trim(str_replace(':', '', $labelNode->textContent));
             $valueNode = $xpath->query("./following-sibling::p[1]", $labelNode)->item(0) ?? $xpath->query("p[2]", $labelNode->parentNode)->item(0);
             $valueText = $valueNode ? trim($valueNode->textContent) : '';
             if (!empty($label) && !empty($valueText) && !isset($moreInfoItems[strtolower($label)])) { // Avoid overwriting DL data
                 $moreInfoItems[strtolower($label)] = $valueText;
             }
         }
     }

    if (empty($appDetails['version_name'])) {
        $versionKeys = ['latest version', 'version'];
        foreach($versionKeys as $key) {
            if (isset($moreInfoItems[$key])) {
                $potentialMoreInfoVersion = trim(str_ireplace(['Latest Version:', 'Version:', 'v'], '', $moreInfoItems[$key]));
                if (isValidVersionString($potentialMoreInfoVersion)) {
                    $appDetails['version_name'] = $potentialMoreInfoVersion;
                    break;
                }
            }
        }
    }
     if (empty($appDetails['update_date'])) {
        $dateKeys = ['update date', 'updated', 'update on']; // Added 'update on'
         foreach($dateKeys as $key) {
            if (isset($moreInfoItems[$key]) && !empty($moreInfoItems[$key])) {
                $appDetails['update_date'] = $moreInfoItems[$key];
                break;
            }
         }
    }
    // Ensure they are at least empty strings if not found
    if (empty($appDetails['version_name'])) $appDetails['version_name'] = '';
    if (empty($appDetails['update_date'])) $appDetails['update_date'] = '';


    // File Size
    $fileSizeNode = $xpath->query("//span[contains(text(), 'Size:')]", $detailsContainerNode)->item(0) ?? $xpath->query("//dt[text()='Size']/following-sibling::dd[1]")->item(0);
    $appDetails['file_size'] = $fileSizeNode ? trim(str_replace('Size:', '', $fileSizeNode->textContent)) : ($moreInfoItems['size'] ?? '');


    // Developer
    $developerNode = $xpath->query(".//a[contains(@class, 'developers')]/span", $detailsContainerNode)->item(0) ?? $xpath->query(".//a[contains(@href, '/developer/')]/span", $detailsContainerNode)->item(0) ?? $xpath->query("//dt[text()='Developer']/following-sibling::dd[1]/a")->item(0);
    $appDetails['developer'] = $developerNode ? trim($developerNode->textContent) : ($moreInfoItems['offered by'] ?? $moreInfoItems['developer'] ?? '');
    
    // FIX for "Attempt to read property "tagName" on null" and "strtolower(): Passing null to parameter #1 ($string) is deprecated"
    $developerLinkNode = null;
    if ($developerNode instanceof DOMElement) {
        if (strtolower($developerNode->tagName) === 'a') {
            $developerLinkNode = $developerNode;
        } else {
            // If $developerNode is a child (e.g., span) of the link, get its parent
            $parentNode = $developerNode->parentNode;
            if ($parentNode instanceof DOMElement && strtolower($parentNode->tagName) === 'a') {
                $developerLinkNode = $parentNode;
            }
        }
    }
    $developerHref = ($developerLinkNode instanceof DOMElement) ? $developerLinkNode->getAttribute('href') : '';
    $appDetails['developer_link'] = $developerHref ? processLink($developerHref, $sourceBaseUrl, 'https://' . USER_DOMAIN) : '';

    // Package Name
    $packageNameNode = $xpath->query("//div[contains(@class, 'detail_more_info')]//p[contains(text(), 'Package Name:')]/following-sibling::p | //dt[text()='Package Name']/following-sibling::dd[1]", $detailsContainerNode)->item(0);
    $appDetails['package_name'] = $packageNameNode ? trim($packageNameNode->textContent) : ($moreInfoItems['package name'] ?? '');
    $appDetails['package_name'] = trim($appDetails['package_name']);


    // Category
    if(empty($appDetails['category'])){
        $categoryLinkNode = $xpath->query("//a[contains(@href, '/category/')]", $detailsContainerNode)->item(0);
        if($categoryLinkNode){
            $appDetails['category'] = trim($categoryLinkNode->textContent);
            $appDetails['category_link'] = processLink($categoryLinkNode->getAttribute('href'), $sourceBaseUrl, 'https://' . USER_DOMAIN);
        } elseif (isset($moreInfoItems['category'])) {
            $appDetails['category'] = $moreInfoItems['category'];
            // Attempt to find a link if category text was from moreInfoItems
            $categoryDtNode = $xpath->query("//div[contains(@class, 'detail_more_info')]//dl/dt[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'category')]")->item(0);
            if ($categoryDtNode) {
                $categoryDdLinkNode = $xpath->query("./following-sibling::dd[1]//a", $categoryDtNode)->item(0);
                if ($categoryDdLinkNode) {
                    $appDetails['category_link'] = processLink($categoryDdLinkNode->getAttribute('href'), $sourceBaseUrl, 'https://' . USER_DOMAIN);
                }
            }
        }
    }

    // Android Requirements
    $androidReqNode = $xpath->query("//p[contains(strong, 'Requires Android:')]/text()[normalize-space()] | //dt[text()='Requires Android']/following-sibling::dd[1]", $detailsContainerNode)->item(0);
    $appDetails['android_req'] = $androidReqNode ? trim(str_replace('Requires Android:', '', $androidReqNode->textContent)) : ($moreInfoItems['requirements'] ?? $moreInfoItems['requires android'] ?? '');


    // Description
    $descriptionNode = $xpath->query("//div[contains(@class, 'description')]/div[contains(@class, 'content')] | //div[@itemprop='description']")->item(0);
    $descriptionHtml = '';
    if ($descriptionNode) {
        foreach ($descriptionNode->childNodes as $child) $descriptionHtml .= $dom->saveHTML($child);
        $appDetails['description'] = processDescription(trim($descriptionHtml));
    } else $appDetails['description'] = '';

    // Related Apps - This section will be removed from the display, but parsing is kept if needed elsewhere
    $relatedApps = [];
    $relatedContainer = $xpath->query("//div[contains(@class, 'detail_related')] | //div[contains(@class, 'related_app')] | //section[contains(@class, 'related-widget')]")->item(0);
    
    $relatedTitleNode = null;
    if ($relatedContainer) {
        $relatedTitleNode = $xpath->query(".//div[contains(@class, 'title')] | .//h3", $relatedContainer)->item(0);
    }
    $appDetails['related_title'] = $relatedTitleNode ? trim($relatedTitleNode->textContent) : 'You May Also Like';

    if ($relatedContainer) {
        $relatedNodes = $xpath->query(".//a[contains(@class, 'item')] | .//li/a | .//div[contains(@class, 'card')]/a", $relatedContainer);
        if ($relatedNodes) {
            foreach ($relatedNodes as $node) {
                $relatedApp = [];
                $rawRelatedUrl = $node->getAttribute('href');
                
                // Use the new extractSlugAndPackageName function
                $relatedExtractedInfo = extractSlugAndPackageName($rawRelatedUrl);
                $relatedApp['package_name'] = $relatedExtractedInfo['package_name'];

                // Process to be relative to user domain
                $relatedApp['url'] = processLink($rawRelatedUrl, $sourceBaseUrl, 'https://' . USER_DOMAIN); 
                
                $relatedTitleNode = $node->getAttribute('title') ?: ($xpath->query(".//div[contains(@class, 'text')]/p[1] | .//div[contains(@class, 'title')] | .//span[@class='title']", $node)->item(0)->textContent ?? '');
                $relatedApp['title'] = trim($relatedTitleNode);
                
                if (empty($relatedApp['title']) || empty($relatedApp['package_name'])) continue; // Ensure title and package name are present

                // Determine the slug for the related app link
                $relatedApp['slug'] = $relatedExtractedInfo['slug'];
                if (empty($relatedApp['slug'])) { // Fallback if extractSlugAndPackageName didn't find a slug
                    $relatedApp['slug'] = slugify($relatedApp['title'] ?: $relatedApp['package_name']);
                    if (empty($relatedApp['slug'])) $relatedApp['slug'] = 'app'; // Final fallback
                }

                $iconNodeRel = $xpath->query(".//div[contains(@class, 'icon')]/img | .//img[contains(@class, 'cover-image')]", $node)->item(0);
                $iconSrcRel = $iconNodeRel ? ($iconNodeRel->getAttribute('data-src') ?: $iconNodeRel->getAttribute('src')) : '';
                $relatedApp['icon'] = $iconSrcRel ? processLink($iconSrcRel, $sourceBaseUrl, 'https://' . USER_DOMAIN) : '';

                $descNodeRel = $xpath->query(".//div[contains(@class, 'text')]/p[2] | .//div[contains(@class, 'description')] | .//div[contains(@class, 'subtitle')]", $node)->item(0);
                $relatedApp['description'] = $descNodeRel ? trim($descNodeRel->textContent) : '';
                // Fallback for description if primary is empty or same as title
                if (empty($relatedApp['description']) || $relatedApp['description'] === $relatedApp['title']) {
                     $descNodeAlt = $xpath->query(".//div[contains(@class, 'text')]/p[1] | .//div[contains(@class, 'category')]", $node)->item(0);
                     if ($descNodeAlt && trim($descNodeAlt->textContent) !== $relatedApp['title']) {
                         $relatedApp['description'] = trim($descNodeAlt->textContent);
                     } else {
                          $relatedApp['description'] = ''; // Ensure it's empty if no suitable description found
                     }
                 }

                // Try to get rating and review count for related apps (using more robust selectors)
                $relatedApp['rating'] = '';
                $relatedApp['review_count'] = '';

                $relatedRatingNode = $xpath->query(".//span[contains(@class, 'star_icon')] | .//div[@class='stars']/@data-rating | .//span[contains(@class, 'score')] | .//span[contains(@class, 'rating-value')]", $node)->item(0);
                if ($relatedRatingNode) {
                    $relatedApp['rating'] = ($relatedRatingNode->nodeName === 'div' && $relatedRatingNode->hasAttribute('data-rating')) ? trim($relatedRatingNode->getAttribute('data-rating')) : trim($relatedRatingNode->textContent);
                    // If it's a number, format it to one decimal place if needed
                    if (is_numeric($relatedApp['rating'])) {
                        $relatedApp['rating'] = number_format((float)$relatedApp['rating'], 1);
                    }
                }

                $relatedReviewCountNode = $xpath->query(".//span[contains(@class, 'review_icon')] | .//span[@class='num-ratings'] | .//span[contains(@class, 'reviews')] | .//span[contains(@class, 'review-count')]", $node)->item(0);
                if ($relatedReviewCountNode) {
                    $rawRelatedReviewCount = trim($relatedReviewCountNode->textContent);
                    $relatedApp['review_count'] = parseReviewCount($rawRelatedReviewCount);
                }

                $relatedApps[] = $relatedApp;
            }
        }
    }
    $appDetails['related_apps'] = $relatedApps;
    
    // Update slug for the main app details
    $appDetails['slug'] = slugify($appDetails['name'] ?: $appDetails['package_name']); 
    if (empty($appDetails['slug'])) $appDetails['slug'] = 'app';


    // Final check for essential details
    if (empty($appDetails['name']) && empty($appDetails['package_name'])) {
         $appDetails['error'] = $appDetails['error'] ?? 'Failed to parse essential app details.';
    } elseif (empty($appDetails['name']) && !empty($appDetails['package_name'])) {
          $appDetails['name'] = $appDetails['package_name']; // Use package name as name if name is missing
    } elseif (empty($appDetails['package_name']) && !empty($appDetails['name'])) {
          // Try to extract package name from the app URL if not found otherwise
          $extractedFromAppUrl = extractSlugAndPackageName($appUrl);
          $appDetails['package_name'] = $extractedFromAppUrl['package_name'];
    }
    return $appDetails;
}

/**
 * Fetches intermediate download info from the source's download page (not the final binary link).
 */
function fetchIntermediateDownloadInfo(string $appUrl, string $sha1 = ''): array {
    $downloadPageUrl = rtrim($appUrl, '/') . '/download';
    if ($sha1) $downloadPageUrl .= '?sha1=' . urlencode($sha1);

    $info = ['final_url' => '', 'file_size' => '', 'file_type' => 'APK', 'version_name' => '', 'update_date' => ''];
    $html = fetchHtml($downloadPageUrl); // Use the general fetchHtml function
    if (is_array($html) && isset($html['error'])) return []; // Error from fetchHtml
    if (empty($html)) return [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!@$dom->loadHTML($html)) { libxml_clear_errors(); return []; }
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Try to find the main download button/link
    $button = $xpath->query("//a[contains(@class, 'down_btn') and @href] | //a[@id='download_link' and @href] | //a[contains(@class,'download-btn') and @href]")->item(0);
    
    // Extract version name from this page if possible (often more specific for the download)
    $potentialVersion = '';
    $versionNodeSpecific = $xpath->query("//h1[contains(@class, 'app-name')]//small")->item(0); // APKPure style
    if ($versionNodeSpecific) {
        $potentialVersion = trim(str_ireplace(['Version:', 'v'], '', $versionNodeSpecific->textContent));
    }
    if (!isValidVersionString($potentialVersion)) { // Fallback to more generic selectors if needed
        $versionNodeGeneric = $xpath->query("//div[contains(@class, 'app_info')]//span[contains(text(), 'Version:')]")->item(0); // Corrected XPath
        if ($versionNodeGeneric) {
            $potentialVersion = trim(str_ireplace(['Version:', 'v'], '', $versionNodeGeneric->textContent));
        }
    }

    if (isValidVersionString($potentialVersion)) {
        $info['version_name'] = $potentialVersion;
    } else {
        $info['version_name'] = ''; // Ensure it's empty if no valid version found
    }
    
    // Extract update date from this page
    // CORRECTED XPATH SYNTAX ON THIS LINE (removed extra ']')
    $dateNode = $xpath->query("//div[contains(@class, 'app_info')]//span[contains(text(), 'Update on:')] | //p[contains(text(), 'Updated on:')] | //div[contains(@class,'app_info')]//span[contains(text(),'Updated:')] | //div[contains(@class,'app_info')]//span[contains(text(),'Update Date:')]")->item(0);
    if ($dateNode) { // Check if node exists before accessing textContent
         $info['update_date'] = trim(str_replace(['Updated on:', 'Update on:', 'Updated:', 'Update Date:'], '', $dateNode->textContent));
    }


    if ($button) {
         $info['final_url'] = $button->getAttribute('href'); // This is the URL to the *next* step, or the binary
         $info['file_size'] = trim($button->getAttribute('data-dt-file-size'));
         $info['file_type'] = trim(strtoupper($button->getAttribute('data-dt-file-type')));

         // Fallback for file type and size from button text if attributes are missing
         if (empty($info['file_size']) || empty($info['file_type'])) {
             $buttonText = trim($button->textContent);
             if (preg_match('/Download\s+(APK|XAPK)\s*\(?\s*([^\)]+)\s*\)?/i', $buttonText, $matches)) {
                 if (empty($info['file_type'])) $info['file_type'] = strtoupper($matches[1]);
                 if (empty($info['file_size'])) $info['file_size'] = trim($matches[2]);
             }
             // Further fallback for file type based on keywords in text
             if (empty($info['file_type'])) {
                 if (stripos($buttonText, 'XAPK') !== false) $info['file_type'] = 'XAPK';
                 elseif (stripos($buttonText, 'APK') !== false) $info['file_type'] = 'APK';
             }
         }
    }
    // Ensure file_type is one of the known valid types, default to APK
    if (!in_array($info['file_type'], ['APK', 'XAPK'])) $info['file_type'] = 'APK';

    return !empty($info['final_url']) ? $info : [];
}

/**
 * Extracts specific version info by SHA1 from the versions page HTML.
 */
function extractVersionInfoBySha1(string $versionsPageHtml, string $targetSha1, string $appIdForLog = 'N/A'): ?array {
    if (is_array($versionsPageHtml) && isset($versionsPageHtml['error'])) return null; // Error from fetchHtml
    if (empty($versionsPageHtml)) return null;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!@$dom->loadHTML($versionsPageHtml)) { libxml_clear_errors(); return null; }
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Find all version list items
    $versionListNodes = $xpath->query("//div[contains(@class, 'version_history')]//div[contains(@class, 'list')]");
    if (!$versionListNodes || $versionListNodes->length === 0) return null;

    foreach ($versionListNodes as $listNode) {
        // Get main version name for this entry
        $mainVersionNode = $xpath->query(".//div[contains(@class, 'package_info')]//span[contains(@class, 'version')]", $listNode)->item(0);
        $potentialMainVersionName = $mainVersionNode ? trim(str_ireplace('v', '', $mainVersionNode->textContent)) : '';
        $mainVersionName = isValidVersionString($potentialMainVersionName) ? $potentialMainVersionName : '';
        
        $mainVersionDateNode = $xpath->query(".//div[contains(@class, 'package_info')]//div[contains(@class, 'text')]/span[1]", $listNode)->item(0);
        $mainVersionDate = $mainVersionDateNode ? trim($mainVersionDateNode->textContent) : '';

        // Look for variants table first
        $variantRows = $xpath->query(".//div[contains(@class, 'info_box')]//div[contains(@class, 'table')]/div[contains(@class, 'table-row')][not(contains(@class,'table-head'))]", $listNode);
        if ($variantRows && $variantRows->length > 0) {
            foreach ($variantRows as $row) {
                $cells = $xpath->query("./div[contains(@class, 'table-cell')]", $row);
                if ($cells->length >= 5) { // Expect at least 5 cells for variant details + download
                    $verInfoCell = $cells->item(0); // Cell containing SHA1, Size, etc. in a popup/div
                    $currentSha1Node = $xpath->query(".//div[contains(@class, 'ver-info')]//p[contains(strong, 'SHA1:')]/text()[normalize-space()]", $verInfoCell)->item(0);
                    $currentSha1 = $currentSha1Node ? trim($currentSha1Node->textContent) : '';

                    if (!empty($currentSha1) && strcasecmp($currentSha1, $targetSha1) === 0) {
                        // Found the matching SHA1
                        $fileSizeNode = $xpath->query(".//div[contains(@class, 'ver-info')]//p[contains(strong, 'Size:')]/text()[normalize-space()]", $verInfoCell)->item(0);
                        $fileSize = $fileSizeNode ? trim($fileSizeNode->textContent) : '';
                        
                        $variantDate = ''; $popupDateNodes = $xpath->query(".//div[contains(@class, 'ver-info')]//div[contains(@class, 'popup')]/p", $verInfoCell);
                        if ($popupDateNodes->length >= 2) $variantDate = trim($popupDateNodes->item(1)->textContent ?? ''); // Second p tag in popup is often date
                        
                        $androidReq = ($cells->item(2)) ? trim($cells->item(2)->textContent ?? '') : ''; // 3rd cell for Android Req
                        
                        // DPI and Arch might be in ver-info or separate cells
                        $dpiNode = $xpath->query(".//div[contains(@class, 'ver-info')]//p[contains(strong, 'Screen DPI:')]/text()[normalize-space()]", $verInfoCell)->item(0);
                        $dpi = $dpiNode ? trim($dpiNode->textContent) : '';
                        if(empty($dpi) && $cells->length > 3) $dpi = ($cells->item(3)) ? trim($cells->item(3)->textContent ?? '') : ''; // Fallback to 4th cell for DPI

                        $archNode = $xpath->query(".//div[contains(@class, 'ver-info')]//p[contains(strong, 'Architecture:')]/text()[normalize-space()]", $verInfoCell)->item(0);
                        $arch = $archNode ? trim($archNode->textContent) : '';
                        if(empty($arch) && $cells->length > 1) { // Fallback to 2nd cell for Arch
                            $potentialArch = ($cells->item(1)) ? trim($cells->item(1)->textContent ?? '') : '';
                             if (!empty($potentialArch) && preg_match('/(arm|x86|mips|arm64|x86_64|mips64)/i', $potentialArch)) $arch = $potentialArch;
                        }
                        
                        // Determine File Type for this variant
                        $fileType = 'APK'; // Default
                        $actionCell = $cells->item(count($cells) - 1); // Last cell is usually download
                        if ($actionCell) {
                            $downloadLinkNode = $xpath->query(".//a[contains(@class, 'down_text') or contains(@class, 'down-button')]", $actionCell)->item(0);
                            if ($downloadLinkNode) {
                                $dataTypeAttr = trim(strtoupper($downloadLinkNode->getAttribute('data-dt-file-type')));
                                if ($dataTypeAttr === 'XAPK' || $dataTypeAttr === 'APK') {
                                    $fileType = $dataTypeAttr;
                                }
                                // If attribute not present or not definitive, check link text
                                if ($fileType === 'APK' && stripos(trim($downloadLinkNode->textContent), 'XAPK') !== false) {
                                    $fileType = 'XAPK';
                                }
                            }
                        }
                        // If still APK, check the overall version entry for an XAPK badge
                        if ($fileType === 'APK' && $xpath->query("ancestor::div[contains(@class, 'list')]//div[contains(@class, 'package_info')]//span[contains(@class, 'xapk')]", $row)->length > 0) {
                            $fileType = 'XAPK';
                        }

                        return ['version_name' => $mainVersionName, 'file_size' => $fileSize ?: 'N/A', 'file_type' => $fileType,
                                'download_title' => "Download " . strtoupper($fileType) . " " . ($mainVersionName ?: 'App'),
                                'update_date' => $variantDate ?: $mainVersionDate, 'android_req' => $androidReq,
                                'dpi' => $dpi, 'arch' => $arch, 'sha1' => $currentSha1];
                    }
                }
            }
        } else { // No variants table, check for simpler structure (often for older versions or single APKs)
            $infoBoxNodeForSha1 = $xpath->query(".//div[contains(@class, 'info_box')]", $listNode)->item(0);
            if ($infoBoxNodeForSha1) {
                $currentSha1Node = $xpath->query(".//p[contains(strong, 'SHA1:')]/text()[normalize-space()]", $infoBoxNodeForSha1)->item(0);
                $currentSha1 = $currentSha1Node ? trim($currentSha1Node->textContent) : '';

                if (!empty($currentSha1) && strcasecmp($currentSha1, $targetSha1) === 0) {
                    $fileSizeNode = $xpath->query(".//p[contains(strong, 'Size:')]/text()[normalize-space()]", $infoBoxNodeForSha1)->item(0);
                    $fileSize = $fileSizeNode ? trim($fileSizeNode->textContent) : '';
                    if (empty($fileSize)) {
                        $packageInfoSizeNode = $xpath->query(".//div[contains(@class, 'package_info')]//div[contains(@class, 'text')]/span[2]", $listNode)->item(0);
                        $fileSize = $packageInfoSizeNode ? trim($packageInfoSizeNode->textContent) : '';
                    }
                    
                    $androidReqNode = $xpath->query(".//p[contains(strong, 'Requires Android:')]/text()[normalize-space()]", $infoBoxNodeForSha1)->item(0);
                    $androidReq = $androidReqNode ? trim($androidReqNode->textContent) : '';
                    
                    $dpiNode = $xpath->query(".//p[contains(strong, 'Screen DPI:')]/text()[normalize-space()]", $infoBoxNodeForSha1)->item(0);
                    $dpi = $dpiNode ? trim($dpiNode->textContent) : '';

                    $archNode = $xpath->query(".//p[contains(strong, 'Architecture:')]/text()[normalize-space()]", $infoBoxNodeForSha1)->item(0);
                    $arch = $archNode ? trim($archNode->textContent) : '';
                    
                    $fileType = 'APK'; // Default
                    // Check for XAPK badge in package_info for this version entry
                    if ($xpath->query(".//div[contains(@class, 'package_info')]//span[contains(@class, 'xapk')]", $listNode)->length > 0) {
                        $fileType = 'XAPK';
                    }
                    // Check download button if present in this simpler structure
                    $simpleDownloadButton = $xpath->query(".//div[contains(@class,'v_h_button')]/a", $listNode)->item(0);
                    if($simpleDownloadButton) {
                        $dataTypeAttr = trim(strtoupper($simpleDownloadButton->getAttribute('data-dt-file-type')));
                         if ($dataTypeAttr === 'XAPK' || $dataTypeAttr === 'APK') {
                            $fileType = $dataTypeAttr; // Prioritize attribute
                        } elseif ($fileType === 'APK' && stripos(trim($simpleDownloadButton->textContent), 'XAPK') !== false) {
                            $fileType = 'XAPK'; // Fallback to text
                        }
                    }

                    return ['version_name' => $mainVersionName, 'file_size' => $fileSize, 'file_type' => $fileType,
                            'download_title' => "Download " . strtoupper($fileType) . " " . ($mainVersionName ?: 'App'),
                            'update_date' => $mainVersionDate, 'android_req' => $androidReq,
                            'dpi' => $dpi, 'arch' => $arch, 'sha1' => $currentSha1];
                }
            }
        }
    }
    return null; // SHA1 not found
}

/**
 * Extracts all previous versions from the versions page HTML.
 * This function is copied from info.php to ensure consistency.
 */
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
            if ($textNodes->length >= 2) { 
                $versionData['date'] = trim($textNodes->item(0)->textContent ?? ''); 
                $versionData['size'] = trim($textNodes->item(1)->textContent ?? ''); 
            }
            if ($xpath->query(".//span[contains(@class, 'xapk')]", $packageInfoNode)->length > 0) $versionData['type'] = 'XAPK';
            elseif ($xpath->query(".//span[contains(@class, 'apk')]", $packageInfoNode)->length > 0) $versionData['type'] = 'APK';
            if (empty($versionData['type'])) {
                $sizeText = trim($textNodes->item(1)->textContent ?? '');
                if (stripos($sizeText, 'XAPK') !== false) $versionData['type'] = 'XAPK'; elseif (stripos($sizeText, 'APK') !== false) $versionData['type'] = 'APK';
            }
            $bundleTypeBadgeNode = $xpath->query(".//span[contains(@class, 'obb')]", $packageInfoNode)->item(0);
            if ($bundleTypeBadgeNode) $versionData['bundle_type_badge_text'] = trim($bundleTypeBadgeNode->textContent); else $versionData['bundle_type_badge_text'] = '';
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
                    if ($popupNode->length >= 2) { $variant['variant_id'] = trim($popupNode->item(0)->textContent ?? ''); $variant['date'] = trim($popupNode->item(1)->textContent ?? $versionData['date']); }
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
                    $variant['arch'] = ($cells->item(1)) ? trim($cells->item(1)->textContent ?? '') : ''; 
                    $variant['android_req'] = ($cells->item(2)) ? trim($cells->item(2)->textContent ?? '') : ''; 
                    $variant['dpi'] = ($cells->item(3)) ? trim($cells->item(3)->textContent ?? '') : '';
                    
                    $sourceHref = ''; $downloadLinkNode = $xpath->query(".//a[contains(@class, 'down_text') or contains(@class, 'down-button')]", $cells->item(4))->item(0);
                    if ($downloadLinkNode) { $sourceHref = $downloadLinkNode->getAttribute('href'); $linkText = trim($downloadLinkNode->textContent);
                        if (empty($variant['type']) || $variant['type'] === 'Unknown') { if (stripos($linkText, 'XAPK') !== false) $variant['type'] = 'XAPK'; elseif (stripos($linkText, 'APK') !== false) $variant['type'] = 'APK'; }
                    }
                    if (empty($variant['sha1']) && !empty($sourceHref)) {
                        $query = parse_url($sourceHref, PHP_URL_QUERY); if ($query) { parse_str($query, $params);
                            if (isset($params['h']) && !empty($params['h'])) $variant['sha1'] = $params['h']; elseif (isset($params['sha1']) && !empty($params['sha1'])) $variant['sha1'] = $params['sha1'];
                        }
                    }
                    // Construct download link for previous version variants
                    $variant['download_link'] = '/' . rawurlencode($userSlug) . '/' . rawurlencode($userPackageName) . '/download?sha1=' . urlencode($variant['sha1']);
                    if (!empty($variant['download_link'])) $versionData['variants'][] = $variant;
                }
            } else {
                // Handle single APK/XAPK entry for a version if no variants table
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
                    // Construct download link for previous version variants
                    $variant['download_link'] = '/' . rawurlencode($userSlug) . '/' . rawurlencode($userPackageName) . '/download?sha1=' . urlencode($variant['sha1']);
                    if (!empty($variant['download_link'])) $versionData['variants'][] = $variant;
                }
            }
        }
        if (!empty($versionData['version']) && !empty($versionData['variants'])) $previousVersions[] = $versionData;
    }
    return $previousVersions;
}

// --- Proxy Logic (at the very top of the file) ---
// This block handles requests where down.php acts as a direct download proxy.
// It checks for 'id' and 'file' parameters, which are expected for proxy requests.
if (isset($_GET['id']) && isset($_GET['file'])) {
    $rawAppIdFromUrlForProxy = urldecode(trim($_GET['id']));
    $sha1ForProxy = isset($_GET['sha1']) && preg_match('/^[a-f0-9]{40}$/i', $_GET['sha1']) ? strtolower($_GET['sha1']) : '';

    // Construct the source URL for fetching app details, which will then lead to the final download URL.
    // Ensure the app ID is URL-encoded for the outgoing request
    // Use extractSlugAndPackageName to get the package name for the source URL
    $extractedInfoForProxy = extractSlugAndPackageName(ltrim($rawAppIdFromUrlForProxy, '/'));
    $packageNameForProxy = $extractedInfoForProxy['package_name'];
    $slugForProxy = $extractedInfoForProxy['slug'];

    $sourceAppUrlForProxy = 'https://' . SOURCE_DOMAIN . '/';
    if (!empty($slugForProxy)) {
        $sourceAppUrlForProxy .= rawurlencode($slugForProxy) . '/';
    }
    $sourceAppUrlForProxy .= rawurlencode($packageNameForProxy);


    // Fetch the actual download URL from the source website.
    // This function internally handles whether it's the latest version or a specific SHA1.
    $intermediateDownloadInfoForProxy = fetchIntermediateDownloadInfo($sourceAppUrlForProxy, $sha1ForProxy);

    if (!empty($intermediateDownloadInfoForProxy['final_url'])) {
        $finalDownloadUrl = $intermediateDownloadInfoForProxy['final_url'];

        // Set appropriate headers for file download
        header('Content-Type: application/octet-stream'); // Generic binary file type
        header('Content-Disposition: attachment; filename="' . basename($_GET['file']) . '"'); // Use the 'file' parameter as filename
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');

        // If the source URL is external, we can redirect or stream.
        // For simplicity and to avoid issues with large files or direct streaming,
        // a direct redirect is often preferred for proxying.
        header('Location: ' . $finalDownloadUrl);
        exit; // Terminate script after redirect
    } else {
        // If the final download URL could not be retrieved, redirect to the app's info page
        // or a custom error page for a better user experience.
        error_log("Proxy failed to find final_url for ID: " . $rawAppIdFromUrlForProxy . " SHA1: " . $sha1ForProxy);
        header('Location: /' . ltrim($rawAppIdFromUrlForProxy, '/')); // Redirect to the app's main page
        exit;
    }
}
// --- End of Proxy Logic ---


// --- Main Logic for Displaying the Download Page ---
$rawAppIdFromUrl = '';
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
// Regex to capture everything before '/download' as the app ID part
if (preg_match('~^/(.+?)/download(?:/?|$)~', $reqUri, $m)) {
    $rawAppIdFromUrl = urldecode($m[1]);
} elseif (isset($_GET['id'])) { // Fallback for ?id= query parameter
     $rawAppIdFromUrl = urldecode(trim($_GET['id']));
}

$sha1 = isset($_GET['sha1']) && preg_match('/^[a-f0-9]{40}$/i', $_GET['sha1']) ? strtolower($_GET['sha1']) : '';

if (empty($rawAppIdFromUrl)) {
    header('Location: /'); exit; // Redirect if no app ID
}

// Use the new extractSlugAndPackageName for the main app ID
$extractedInfoFromUrl = extractSlugAndPackageName($rawAppIdFromUrl);
$mainAppSlug = $extractedInfoFromUrl['slug'];
$mainAppPackageName = $extractedInfoFromUrl['package_name'];

// Construct source URL for app details
$sourceAppUrl = 'https://' . SOURCE_DOMAIN . '/';
if (!empty($mainAppSlug)) {
    $sourceAppUrl .= rawurlencode($mainAppSlug) . '/';
}
$sourceAppUrl .= rawurlencode($mainAppPackageName);

$sourceBaseUrl = 'https://' . SOURCE_DOMAIN;
$userBaseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$currentPageUrl = $userBaseUrl . $_SERVER['REQUEST_URI'];


// Initialize download information array
$downloadInfo = ['final_url' => '', 'file_size' => '', 'file_type' => 'APK', 'version_name' => '', 'package_name' => '',
                 'download_title' => 'Download APK/XAPK', 'update_date' => '', 'android_req' => '',
                 'dpi' => '', 'arch' => '', 'sha1' => ''];
$appInfo = []; // For general app details
$errorType = 'none';
$errorMessage = '';

// Fetch general app details first (name, icon, description, etc.)
$appInfo = fetchAndParseAppDetails($sourceAppUrl, $sourceBaseUrl);

if (isset($appInfo['error'])) {
    $errorType = 'app_details_error';
    $errorMessage = $appInfo['error'];
    // Prepare for error display
    $pageTitle = "Error | " . USER_DOMAIN;
    $metaDescription = "Error loading app details. " . USER_DOMAIN;
    $canonicalUrl = $currentPageUrl;
    $breadcrumbs = [['name' => '<i class="fas fa-home text-blue-600"></i>', 'url' => '/', 'is_html' => true], ['name' => 'Error', 'url' => '#']];
    include 'includes/header.php'; // Assuming header.php handles $pageTitle etc.
     ?>
     <div class="flex flex-col lg:flex-row gap-6 mb-8 text-sm justify-center px-4 max-w-screen-xl mx-auto">
         <div class="w-full max-w-2xl lg:w-2/3">
             <div class="bg-white rounded-lg shadow-md p-4 md:p-6 mb-6">
                <nav aria-label="Breadcrumb" class="text-sm mb-4">
                    <ol class="list-none p-0 inline-flex space-x-2 text-gray-700">
                        <?php foreach ($breadcrumbs as $index => $crumb): ?>
                            <li class="flex items-center">
                                <?php if ($index > 0): ?>
                                    <svg class="w-3 h-3 text-gray-400 mx-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg>
                            <?php endif; ?>
                            <?php if (isset($crumb['url']) && $crumb['url'] !== '#' && $index < count($breadcrumbs) - 1): ?>
                                <a href="<?php echo safe_htmlspecialchars($crumb['url']); ?>" class="text-blue-600 hover:underline"><?php echo !empty($crumb['is_html']) ? $crumb['name'] : $crumb['name']; ?></a>
                            <?php else: ?>
                                <span class="text-gray-500"><?php echo !empty($crumb['is_html']) ? $crumb['name'] : $crumb['name']; ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                </nav>
                 <h1 class="text-xl sm:text-2xl font-semibold mb-4">App Download Error</h1>
                 <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert">
                     <strong class="font-bold"><i class="fas fa-times-circle mr-2"></i> Error Loading App Details!</strong>
                     <span class="block sm:inline"><?php echo safe_htmlspecialchars($errorMessage); ?></span>
                 </div>
             </div>
         </div>
     </div>
     <?php
     include 'includes/footer.php';
     exit;
}


// Ensure package name is set in $downloadInfo and $appInfo
$downloadInfo['package_name'] = $appInfo['package_name'] ?: $mainAppPackageName ?: 'unknown_package';
if (empty($appInfo['package_name']) && !empty($downloadInfo['package_name'])) {
    $appInfo['package_name'] = $downloadInfo['package_name'];
}
if (empty($appInfo['name']) && !empty($appInfo['package_name'])) { // If app name is missing, use package name
    $appInfo['name'] = $appInfo['package_name'];
}

// --- Logic for specific SHA1 or LATEST version ---
if ($sha1) {
    // A specific SHA1 was requested. This logic is unchanged.
    $downloadInfo['sha1'] = $sha1;
    $versionsPageUrl = $sourceAppUrl . '/versions';
    $versionsPageHtml = fetchHtml($versionsPageUrl);
    $versionSpecificInfo = extractVersionInfoBySha1($versionsPageHtml, $sha1, $rawAppIdFromUrl);

    if ($versionSpecificInfo) {
        $downloadInfo = array_merge($downloadInfo, $versionSpecificInfo); 
        $intermediateDownloadInfo = fetchIntermediateDownloadInfo($sourceAppUrl, $sha1);

        if (!empty($intermediateDownloadInfo['final_url'])) {
             $downloadInfo['final_url'] = $intermediateDownloadInfo['final_url'];
             // Keep prioritizing info from the versions table for specific sha1 requests
        } else {
             $errorType = 'download_not_available';
             $errorMessage = "Could not retrieve the final download link for version (SHA1: " . safe_htmlspecialchars($sha1) . ").";
             $downloadInfo['final_url'] = '';
        }
    } else {
        $errorType = 'invalid_sha1';
        $errorMessage = "Requested version (SHA1: " . safe_htmlspecialchars($sha1) . ") not found on the versions page.";
        $downloadInfo['final_url'] = '';
    }
} else { 
    // LATEST version (no SHA1 provided). *** THIS IS THE REVISED LOGIC ***
    $intermediateDownloadInfo = fetchIntermediateDownloadInfo($sourceAppUrl);
    if (!empty($intermediateDownloadInfo['final_url'])) {
        $downloadInfo['final_url'] = $intermediateDownloadInfo['final_url'];
    }

    // Populate the main app info with version/date details from the intermediate page if they are more specific.
    $appInfo['version_name'] = $intermediateDownloadInfo['version_name'] ?: $appInfo['version_name'];
    $appInfo['update_date'] = $intermediateDownloadInfo['update_date'] ?: $appInfo['update_date'];

    // *CRITICAL*: The target size for our search MUST be from the intermediate download page, not the general app info page.
    $targetSize = $intermediateDownloadInfo['file_size'] ?? '';

    // Now, fetch previous versions to try and find a detailed variant matching our target.
    $finalSlugForVersions = !empty($appInfo['slug']) ? $appInfo['slug'] : slugify($appInfo['name'] ?: $appInfo['package_name']);
    if (empty($finalSlugForVersions)) $finalSlugForVersions = 'app';
    $finalPackageNameForVersions = !empty($appInfo['package_name']) ? $appInfo['package_name'] : $mainAppPackageName;

    $previousVersions = [];
    if (!empty($finalSlugForVersions) && !empty($finalPackageNameForVersions)) {
        $previousVersions = extractPreviousVersions($sourceAppUrl, $sourceBaseUrl, $finalSlugForVersions, $finalPackageNameForVersions);
        if (isset($previousVersions['error'])) {
            error_log("Error fetching previous versions for latest: " . $previousVersions['error']);
            $previousVersions = [];
        }
    }

    $bestVariantInfo = null;

    if (!empty($previousVersions) && isset($previousVersions[0])) {
        $latestVersionGroup = $previousVersions[0];
        $variantsToSearch = $latestVersionGroup['variants'];
        
        // Try to find a variant that exactly matches the size from the intermediate download page.
        if (!empty($targetSize) && !empty($variantsToSearch)) {
            foreach ($variantsToSearch as $variant) {
                // normalize_string helper function removes spaces and lowercases for a reliable comparison.
                $normalizedVariantSize = preg_replace('/\s+/', '', strtolower($variant['size']));
                $normalizedTargetSize = preg_replace('/\s+/', '', strtolower($targetSize));
                if ($normalizedVariantSize === $normalizedTargetSize) {
                    $bestVariantInfo = $variant; // Found a perfect match!
                    break;
                }
            }
        }
    }
    
    // Now, populate the downloadInfo based on whether a match was found.
    if ($bestVariantInfo !== null) {
        // A matching variant WAS FOUND. Use its detailed info. This is the ideal case.
        $downloadInfo['sha1'] = $bestVariantInfo['sha1'] ?? '';
        $downloadInfo['arch'] = $bestVariantInfo['arch'] ?: 'Universal';
        $downloadInfo['dpi'] = $bestVariantInfo['dpi'] ?: 'No Dpi';
        $downloadInfo['android_req'] = $bestVariantInfo['android_req'] ?: ($appInfo['android_req'] ?? '');
        $downloadInfo['file_size'] = $bestVariantInfo['size'] ?: ($intermediateDownloadInfo['file_size'] ?? 'N/A');
        $downloadInfo['file_type'] = $bestVariantInfo['type'] ?: ($intermediateDownloadInfo['file_type'] ?? 'APK');
        $downloadInfo['version_name'] = $latestVersionGroup['version'] ?? ($appInfo['version_name'] ?? '');
        $downloadInfo['update_date'] = $bestVariantInfo['date'] ?? ($appInfo['update_date'] ?? '');
    } else {
        // NO MATCHING VARIANT FOUND. Fallback logic.
        // Use the data from the intermediate download page and the main app page.
        
        $downloadInfo['file_size'] = $intermediateDownloadInfo['file_size'] ?? ($appInfo['file_size'] ?? 'N/A');
        $downloadInfo['file_type'] = $intermediateDownloadInfo['file_type'] ?? ($appInfo['file_type'] ?? 'APK');
        $downloadInfo['version_name'] = $intermediateDownloadInfo['version_name'] ?: ($appInfo['version_name'] ?? '');
        $downloadInfo['update_date']  = $intermediateDownloadInfo['update_date'] ?: ($appInfo['update_date'] ?? '');
        $downloadInfo['android_req']  = $appInfo['android_req'] ?? '';
        
        // Set defaults for the info we couldn't find.
        $downloadInfo['arch'] = 'Universal';
        $downloadInfo['dpi'] = 'No Dpi';
        $downloadInfo['sha1'] = ''; // This will hide the SHA1 field.
    }
}


// Final checks and defaults if errors occurred or info is missing
if ($errorType === 'none' && empty($downloadInfo['final_url'])) {
    $errorType = 'download_not_available';
    $errorMessage = $errorMessage ?: "Download information could not be retrieved for this version.";
}

// Ensure essential fields have fallbacks
$downloadInfo['file_size'] = $downloadInfo['file_size'] ?: 'N/A';
$downloadInfo['file_type'] = $downloadInfo['file_type'] ?: 'APK'; 
$downloadInfo['version_name'] = $downloadInfo['version_name'] ?: 'N/A'; 
foreach (['update_date', 'android_req', 'dpi', 'arch', 'sha1'] as $key) {
    $downloadInfo[$key] = $downloadInfo[$key] ?? '';
}

// Determine if the currently displayed download is the "latest" version
$isCurrentDownloadLatest = false;
if (empty($sha1) && $errorType === 'none' && !empty($downloadInfo['final_url'])) {
    $isCurrentDownloadLatest = true;
}

// Prepare display name for the app
$appNameForDisplay = safe_htmlspecialchars($appInfo['name'] ?: $mainAppPackageName);

// Determine download button title
if ($errorType !== 'none' || empty($downloadInfo['final_url'])) {
    $downloadInfo['final_url'] = ''; // Ensure no download link if error
    $downloadInfo['download_title'] = 'Download Unavailable';
} else {
    $versionForTitle = ($downloadInfo['version_name'] !== 'N/A' && !empty($downloadInfo['version_name'])) ? safe_htmlspecialchars($downloadInfo['version_name']) : '';
    $downloadInfo['download_title'] = trim("Download " . strtoupper(safe_htmlspecialchars($downloadInfo['file_type'])) . " " . $versionForTitle);
    if (empty(str_replace(['Download ', strtoupper(safe_htmlspecialchars($downloadInfo['file_type']))], '', $downloadInfo['download_title']))) { 
          $downloadInfo['download_title'] = "Download " . strtoupper(safe_htmlspecialchars($downloadInfo['file_type']));
    }
}

$waitSeconds = 8; // Countdown timer

// Page Title Construction
$pageTitleAppName = $appNameForDisplay;
$pageTitleVersion = ($downloadInfo['version_name'] !== 'N/A' && !empty($downloadInfo['version_name'])) ? safe_htmlspecialchars($downloadInfo['version_name']) : '';
$pageTitleFileType = ($downloadInfo['file_type'] !== 'APK' && !empty($downloadInfo['file_type'])) ? strtoupper(safe_htmlspecialchars($downloadInfo['file_type'])) : ''; 
$pageTitle = implode(" ", array_filter(["Download", $pageTitleAppName, $pageTitleVersion, $pageTitleFileType, "| " . USER_DOMAIN]));


// Filename for download
$safeName = slugify($appInfo['name'] ?: $downloadInfo['package_name']);
if (empty($safeName)) $safeName = 'app';
$versionForFilename = ($downloadInfo['version_name'] !== 'N/A' && !empty($downloadInfo['version_name'])) ? str_replace(' ', '_', $downloadInfo['version_name']) : '';
$fileExt = strtolower($downloadInfo['file_type'] ?: 'apk');
$filename = "{$safeName}_" . ($versionForFilename ?: ($sha1 ? 's-' . substr($sha1,0,7) : 'latest')) . "_" . USER_DOMAIN . ".{$fileExt}";
$filename = str_replace(['__', '_N/A_'], ['_', '_'], $filename);

// Proxy URL for download
$downloadProxyUrl = "/download-proxy.php?id=" . urlencode($mainAppSlug . '/' . $mainAppPackageName) . "&file=" . urlencode($filename);
if ($sha1) $downloadProxyUrl .= "&sha1=" . urlencode($sha1);


// Link to latest version download page (for error messages)
$latestVersionUrl = "/" . rawurlencode($mainAppSlug) . "/" . rawurlencode($mainAppPackageName) . "/download";


// Meta Description
$appNameForMeta = $appNameForDisplay;
$versionForMeta = ($downloadInfo['version_name'] !== 'N/A' && !empty($downloadInfo['version_name'])) ? " version " . safe_htmlspecialchars($downloadInfo['version_name']) : " latest version";
$fileTypeForMeta = safe_htmlspecialchars($downloadInfo['file_type']);
$metaDescription = "Download " . $appNameForMeta . $versionForMeta . ". Get " . $fileTypeForMeta . " for Android. Safe and fast download from " . USER_DOMAIN . ".";

if (!empty($appInfo['description'])) {
    $plainTextDesc = strip_tags(html_entity_decode($appInfo['description'], ENT_QUOTES, 'UTF-8'));
    $shortDescTextForMeta = mb_substr($plainTextDesc, 0, 155);
    if (mb_strlen($plainTextDesc) > 155) {
        $lastSpace = mb_strrpos($shortDescTextForMeta, ' ');
        $shortDescTextForMeta = ($lastSpace !== false) ? mb_substr($shortDescTextForMeta, 0, $lastSpace) . '...' : $shortDescTextForMeta . '...';
    }
    $metaDescription = safe_htmlspecialchars($shortDescTextForMeta);
}
$canonicalUrl = $currentPageUrl;

// JSON-LD Schema
$jsonLd = [
    "@context" => "https://schema.org",
    "@type" => "SoftwareApplication",
    "name" => $appNameForDisplay,
    "operatingSystem" => "Android",
    "applicationCategory" => !empty($appInfo['category']) ? safe_htmlspecialchars($appInfo['category']) : "MobileApplication",
    "description" => safe_htmlspecialchars(strip_tags(html_entity_decode($appInfo['description'] ?: $metaDescription, ENT_QUOTES, 'UTF-8'))),
    "softwareVersion" => ($downloadInfo['version_name'] !== 'N/A' && !empty($downloadInfo['version_name'])) ? safe_htmlspecialchars($downloadInfo['version_name']) : (($appInfo['version_name'] ?? '') ?: ''),
    "offers" => ["@type" => "Offer", "price" => "0", "priceCurrency" => "USD"]
];
if (!empty($appInfo['icon'])) $jsonLd['image'] = safe_htmlspecialchars($appInfo['icon']);
if (!empty($downloadInfo['final_url'])) $jsonLd['downloadUrl'] = $userBaseUrl . safe_htmlspecialchars($downloadProxyUrl);
if (!empty($appInfo['developer'])) $jsonLd['author'] = ["@type" => "Person", "name" => safe_htmlspecialchars($appInfo['developer'])];
$jsonLdData = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);


// Breadcrumbs
$appInfoPageUrl = '/' . rawurlencode($appInfo['slug']) . '/' . rawurlencode($appInfo['package_name']);

$breadcrumbs = [];
$breadcrumbs[] = ['name' => '<i class="fas fa-home text-blue-600"></i>', 'url' => '/', 'is_html' => true];

if (!empty($appInfo['category'])) {
    $categoryName = safe_htmlspecialchars($appInfo['category']);
    $categoryUrl = (!empty($appInfo['category_link']) && strpos($appInfo['category_link'], '/') === 0)
                   ? safe_htmlspecialchars($appInfo['category_link'])
                   : '/category/' . slugify($appInfo['category']);
    $breadcrumbs[] = ['name' => $categoryName, 'url' => $categoryUrl];
}
$breadcrumbs[] = ['name' => $appNameForDisplay, 'url' => $appInfoPageUrl];
$breadcrumbs[] = ['name' => 'Download', 'url' => '#'];


include 'includes/header.php';
?>
<div class="flex flex-col lg:flex-row gap-6 mb-8 text-sm max-w-screen-xl mx-auto px-2 sm:px-4">
    <div class="w-full lg:w-2/3">
        <div class="bg-white rounded-lg shadow-md p-4 md:p-6 mb-6">
            <nav aria-label="Breadcrumb" class="text-sm mb-4">
                <ol class="list-none p-0 inline-flex space-x-2 text-gray-700">
                    <?php foreach ($breadcrumbs as $index => $crumb): ?>
                        <li class="flex items-center">
                            <?php if ($index > 0): ?>
                                <svg class="w-3 h-3 text-gray-400 mx-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg>
                            <?php endif; ?>
                            <?php if (isset($crumb['url']) && $crumb['url'] !== '#' && $index < count($breadcrumbs) - 1): ?>
                                <a href="<?php echo safe_htmlspecialchars($crumb['url']); ?>" class="text-blue-600 hover:underline"><?php echo !empty($crumb['is_html']) ? $crumb['name'] : $crumb['name']; ?></a>
                            <?php else: ?>
                                <span class="text-gray-500"><?php echo !empty($crumb['is_html']) ? $crumb['name'] : $crumb['name']; ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                </nav>

            <div class="flex items-start mb-4 pb-4 border-b border-gray-100">
                <?php if (!empty($appInfo['icon'])): ?>
                    <img src="<?php echo safe_htmlspecialchars($appInfo['icon']); ?>" alt="<?php echo $appNameForDisplay; ?> Icon" class="w-20 h-20 rounded-lg shadow-sm object-cover mr-4 border border-gray-200">
                <?php else: ?>
                     <div class="w-20 h-20 rounded-lg shadow-sm object-cover mr-4 bg-gray-200 flex items-center justify-center border border-gray-200">
                         <i class="fas fa-mobile-alt text-gray-500 text-3xl"></i>
                     </div>
                <?php endif; ?>
                <div class="flex-1">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-800 mb-0.5 inline-flex items-center">
                        <?php echo $appNameForDisplay; ?>
                        <span id="trustAppBadge" class="ml-3 inline-flex items-center bg-blue-600 text-white font-semibold px-2 py-1 rounded-full shadow-md cursor-pointer hover:bg-blue-700 transition-colors duration-200 text-xs">
                            <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            Trusted
                        </span>
                    </h1>
                    <?php if (!empty($appInfo['developer'])): ?>
                         <div class="text-xs text-gray-600 mb-1.5">
                             <i class="fas fa-user-alt mr-1 text-gray-500"></i>By:
                             <?php
                                  $devName = safe_htmlspecialchars($appInfo['developer']);
                                  $devLink = $appInfo['developer_link'] ?? '';
                              ?>
                             <?php if ($devLink): ?>
                                 <a href="<?php echo safe_htmlspecialchars($devLink); ?>" <?php if(strpos($devLink, 'http') === 0) echo 'target="_blank" rel="nofollow noopener"'; ?> class="text-blue-600 hover:underline font-medium">
                                     <?php echo $devName; ?> <?php if(strpos($devLink, 'http') === 0) echo '<i class="fas fa-external-link-alt text-xs opacity-60 ml-0.5"></i>'; ?>
                                 </a>
                             <?php else: ?>
                                 <span class="text-gray-700 font-medium"><?php echo $devName; ?></span>
                             <?php endif; ?>
                         </div>
                    <?php endif; ?>
                    <div class="flex flex-wrap items-center gap-2 text-xs mt-1 mb-1">
                        <?php if ($isCurrentDownloadLatest): ?>
                             <span class="bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-1 rounded-full inline-block uppercase tracking-wide shadow-sm">
                                 <i class="fas fa-check-circle mr-1"></i>Latest Version
                             </span>
                        <?php elseif ($errorType === 'none' && !empty($downloadInfo['final_url'])): ?>
                             <span class="bg-orange-100 text-orange-800 text-xs font-semibold px-2.5 py-1 rounded-full inline-block uppercase tracking-wide shadow-sm">
                                 <i class="fas fa-history mr-1"></i>Older Version
                             </span>
                        <?php endif; ?>
                        <?php if ($errorType === 'invalid_sha1' || ($sha1 && empty($downloadInfo['final_url']))): ?>
                            <span class="bg-red-100 text-red-800 text-xs font-semibold px-2.5 py-1 rounded-full inline-block uppercase tracking-wide shadow-sm">
                                <i class="fas fa-exclamation-circle mr-1"></i>Version Unavailable
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs pt-1 mb-4">
                <?php if (!empty($downloadInfo['version_name']) && $downloadInfo['version_name'] !== 'N/A'): ?>
                    <span class="inline-flex items-center font-medium px-2.5 py-1.5 rounded-md shadow-sm border border-gray-200 text-gray-700">
                        <i class="fas fa-code-branch mr-1.5 text-sky-500"></i>Version: <?php echo safe_htmlspecialchars($downloadInfo['version_name']); ?>
                    </span>
                <?php endif; ?>
                <?php 
                $formattedDisplayUpdateDate = formatDisplayDate($downloadInfo['update_date']);
                if (!empty($formattedDisplayUpdateDate)): 
                ?>
                    <span class="inline-flex items-center font-medium px-2.5 py-1.5 rounded-md shadow-sm border border-gray-200 text-gray-700">
                        <i class="fas fa-calendar-alt mr-1.5 text-slate-500"></i>Updated: <?php echo safe_htmlspecialchars($formattedDisplayUpdateDate); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($downloadInfo['file_size']) && $downloadInfo['file_size'] !== 'N/A'): ?>
                    <span class="inline-flex items-center font-medium px-2.5 py-1.5 rounded-md shadow-sm border border-gray-200 text-gray-700">
                        <i class="fas fa-hdd mr-1.5 text-teal-500"></i>Size: <?php echo safe_htmlspecialchars($downloadInfo['file_size']); ?>
                    </span>
                <?php endif; ?>
                 <?php if (!empty($downloadInfo['file_type'])): ?>
                    <span class="inline-flex items-center font-medium px-2.5 py-1.5 rounded-md shadow-sm border border-gray-200 text-gray-700">
                        <i class="fas fa-file-archive mr-1.5 text-cyan-500"></i>Type: <?php echo strtoupper(safe_htmlspecialchars($downloadInfo['file_type'])); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($downloadInfo['android_req'])): ?>
                    <span class="inline-flex items-center font-medium px-2.5 py-1.5 rounded-md shadow-sm border border-gray-200 text-gray-700">
                        <i class="fab fa-android mr-1.5 text-lime-500"></i>Requires: <?php echo safe_htmlspecialchars($downloadInfo['android_req']); ?>
                    </span>
                <?php endif; ?>
                 <?php if (!empty($appInfo['package_name'])): ?>
                    <span class="inline-flex items-center font-medium px-2.5 py-1.5 rounded-md shadow-sm col-span-full sm:col-span-1 border border-gray-200 text-gray-700" title="Package Name: <?php echo safe_htmlspecialchars($appInfo['package_name']); ?>">
                        <i class="fas fa-box-open mr-1.5 text-gray-700"></i>Package: <?php echo safe_htmlspecialchars($appInfo['package_name']); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($downloadInfo['arch'])): ?>
                    <span class="inline-flex items-center font-medium px-2.5 py-1.5 rounded-md shadow-sm border border-gray-200 text-gray-700">
                        <i class="fas fa-microchip mr-1.5 text-fuchsia-500"></i>Arch: <?php echo safe_htmlspecialchars($downloadInfo['arch']); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($downloadInfo['sha1'])): ?>
                    <span class="inline-flex items-center font-medium px-2.5 py-1.5 rounded-md shadow-sm col-span-full border border-gray-200 text-gray-700">
                        <i class="fas fa-fingerprint mr-1.5 text-zinc-500"></i>SHA1: <?php echo safe_htmlspecialchars($downloadInfo['sha1']); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($downloadInfo['dpi'])): ?>
                    <span class="inline-flex items-center font-medium px-2.5 py-1.5 rounded-md shadow-sm border border-gray-200 text-gray-700">
                        <i class="fas fa-ruler-combined mr-1.5 text-violet-500"></i>DPI: <?php echo safe_htmlspecialchars($downloadInfo['dpi']); ?>
                    </span>
                <?php endif; ?>
                <span id="shareQrBtn" class="inline-flex items-center bg-indigo-100 text-indigo-800 font-medium px-2.5 py-1.5 rounded-md shadow-sm cursor-pointer hover:bg-indigo-200" title="Share QR Code">
                    <i class="fas fa-share-alt mr-1.5 text-indigo-500"></i>Share Page
                </span>
            </div>

            <?php if ($errorType === 'invalid_sha1'): ?>
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-800 px-4 py-3 rounded-md mb-4 shadow-sm" role="alert">
                    <strong class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i> Version Not Found!</strong>
                    <span class="block sm:inline"><?php echo safe_htmlspecialchars($errorMessage); ?></span>
                     <p class="text-xs mt-1">The requested version (SHA1) could not be found or matched on the source site.</p>
                </div>
                 <?php if (!empty($appInfo['name']) || !empty($mainAppPackageName)): ?>
                     <div class="text-center mt-4"><a href="<?php echo safe_htmlspecialchars($latestVersionUrl); ?>" class="text-blue-600 hover:underline font-medium"><i class="fas fa-sync-alt mr-1"></i> Try Latest Version</a></div>
                <?php endif; ?>
            <?php elseif ($errorType === 'download_not_available'): ?>
                <div class="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded-md mb-4 shadow-sm" role="alert">
                    <strong class="font-bold"><i class="fas fa-exclamation-circle mr-2"></i> Download Not Available!</strong>
                    <span class="block sm:inline"><?php echo safe_htmlspecialchars($errorMessage); ?></span>
                     <p class="text-xs mt-1">The download link for this version could not be prepared. This might be temporary.</p>
                </div>
                 <?php if ($sha1 && (!empty($appInfo['name']) || !empty($mainAppPackageName))): ?>
                      <div class="text-center mt-4"><a href="<?php echo safe_htmlspecialchars($latestVersionUrl); ?>" class="text-blue-600 hover:underline font-medium"><i class="fas fa-sync-alt mr-1"></i> Try Latest Version Instead</a></div>
                 <?php endif; ?>
            <?php elseif ($errorType === 'none' && !empty($downloadInfo['final_url'])): ?>
                <div class="bg-indigo-50 border border-indigo-100 rounded-md p-4 flex flex-col items-center mb-4 shadow-sm">
                    <div class="text-lg font-semibold mb-2 text-indigo-700">Your download is almost ready...</div>
                    <div id="countdown" class="text-xl font-bold text-indigo-900 mb-3 flex items-center justify-center"><i class="fas fa-hourglass-half fa-spin mr-2"></i> <?php echo $waitSeconds; ?></div>
                    <div class="text-xs text-gray-500 mb-3">Please wait while we prepare your download link.</div>
                    <a id="finalDownloadBtn" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg text-base font-semibold opacity-50 pointer-events-none flex items-center justify-center shadow-md hover:shadow-lg" href="javascript:void(0);" rel="nofollow noopener">
                        <i class="fas fa-download mr-2"></i> <span id="downloadBtnText"><?php echo safe_htmlspecialchars($downloadInfo['download_title']); ?></span>
                         <?php if (!empty($downloadInfo['file_size']) && $downloadInfo['file_size'] !== 'N/A'): ?>
                             <span class="ml-3 bg-white/20 px-2 py-0.5 rounded-full text-white text-xs font-normal"><?php echo safe_htmlspecialchars($downloadInfo['file_size']); ?></span>
                         <?php endif; ?>
                    </a>
                </div>
                <div class="text-xs text-gray-500 text-center mt-3">If the download doesn't start automatically, <a href="javascript:void(0);" id="manualDownload" class="text-blue-600 hover:underline font-medium">click here to start it manually</a>.</div>
            <?php endif; ?>

            <?php
            $quickOverviewText = '';
            if (!empty($appInfo['description'])) {
                $plainTextDesc = strip_tags(html_entity_decode($appInfo['description'], ENT_QUOTES, 'UTF-8'));
                $shortDescText = $plainTextDesc;
                if (mb_strlen($plainTextDesc) > DESCRIPTION_QUICK_OVERVIEW_LENGTH) {
                    $shortDescText = mb_substr($plainTextDesc, 0, DESCRIPTION_QUICK_OVERVIEW_LENGTH);
                    $lastSpace = mb_strrpos($shortDescText, ' ');
                    $shortDescText = ($lastSpace !== false) ? mb_substr($shortDescText, 0, $lastSpace) . '...' : $shortDescText . '...';
                }
                $quickOverviewText = trim($shortDescText);
                if (!empty($quickOverviewText)):
            ?>
            <div class="mt-6 pt-4 border-t border-gray-100">
                <h3 class="text-md font-semibold text-gray-700 mb-1.5 flex items-center"><i class="fas fa-info-circle text-gray-400 mr-2"></i>Quick Overview</h3>
                <p class="text-xs text-gray-600 leading-relaxed"><?php echo safe_htmlspecialchars($quickOverviewText); ?></p>
            </div>
            <?php
                endif;
            }
            ?>
            </div>
    </div>

    <div class="w-full lg:w-1/3 lg:flex-shrink-0">
         <?php if (!empty($appInfo['related_apps'])): ?>
            <div class="bg-white rounded-lg shadow-md p-3 sticky top-4 lg:top-5">
                 <h3 class="text-base font-bold mb-2 text-gray-800 border-b border-gray-200 pb-2 flex items-center">
                    <i class="fas fa-thumbs-up text-blue-500 mr-2"></i> <?php echo safe_htmlspecialchars($appInfo['related_title'] ?? 'You May Also Like'); ?>
                </h3>
                <div class="space-y-2 mt-2 max-h-[75vh] overflow-y-auto pr-1 custom-scrollbar">
                     <?php foreach ($appInfo['related_apps'] as $relatedApp): ?>
                        <?php
                            $relatedLink = '#';
                            if(!empty($relatedApp['package_name']) && !empty($relatedApp['slug'])) {
                                $relatedLink = '/' . rawurlencode($relatedApp['slug']) . '/' . rawurlencode($relatedApp['package_name']);
                            } elseif (!empty($relatedApp['url'])) {
                                $relatedLink = safe_htmlspecialchars($relatedApp['url']);
                            }

                            $relatedTitle = safe_htmlspecialchars($relatedApp['title'] ?? 'App');
                            $relatedIcon = safe_htmlspecialchars($relatedApp['icon'] ?? '');
                            $relatedDesc = safe_htmlspecialchars($relatedApp['description'] ?? 'View details...');
                            $relatedRating = !empty($relatedApp['rating']) ? safe_htmlspecialchars($relatedApp['rating']) : 'N/A';
                            $relatedReviewCount = !empty($relatedApp['review_count']) ? safe_htmlspecialchars($relatedApp['review_count']) : 'N/A';
                        ?>
                        <?php if ($relatedLink !== '#'): ?>
                        <a href="<?php echo $relatedLink; ?>" title="View <?php echo $relatedTitle; ?>" class="flex items-center space-x-3 p-2 rounded-md hover:bg-gray-50 transition-colors duration-200 border border-transparent hover:border-gray-200 group">
                            <div class="flex-shrink-0">
                                <?php if (!empty($relatedIcon)): ?>
                                    <img src="<?php echo $relatedIcon; ?>" class="w-16 h-16 rounded-md object-cover border border-gray-100 bg-gray-100" alt="<?php echo $relatedTitle; ?> Icon" loading="lazy" onerror="this.onerror=null; this.src='https://placehold.co/64x64/e2e8f0/94a3b8?text=Icon'; this.classList.add('object-contain');">
                                <?php else: ?>
                                    <div class="w-16 h-16 bg-gray-200 rounded-md flex items-center justify-center border border-gray-100"><i class="fas fa-mobile-alt text-gray-400 text-2xl"></i></div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="text-sm font-semibold text-gray-800 truncate group-hover:text-blue-600"><?php echo $relatedTitle; ?></h4>
                                <p class="text-xs text-gray-500 line-clamp-1 mt-0.5"><?php echo $relatedDesc; ?></p>
                                <div class="flex items-center text-[9px] text-gray-600 mt-1.5 space-x-1.5 flex-wrap">
                                    <span class="inline-flex items-center bg-yellow-100 text-yellow-800 font-medium px-1.5 py-0.5 rounded-md"><i class="fas fa-star text-yellow-500 mr-0.5"></i> <?php echo $relatedRating; ?></span>
                                    <span class="inline-flex items-center bg-purple-100 text-purple-800 font-medium px-1.5 py-0.5 rounded-md"><i class="fas fa-users text-purple-500 mr-0.5"></i> <?php echo $relatedReviewCount; ?></span>
                                </div>
                            </div>
                             <i class="fas fa-chevron-right text-gray-400 text-xs ml-auto group-hover:text-blue-500 transition-colors duration-200 flex-shrink-0"></i>
                        </a>
                         <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
         <?php elseif ($errorType !== 'app_details_error'): ?>
             <div class="bg-white rounded-lg shadow-md p-4 sticky top-4 lg:top-5">
                 <h3 class="text-base font-bold mb-3 text-gray-800 border-b border-gray-200 pb-2 flex items-center"><i class="fas fa-thumbs-up text-blue-500 mr-2"></i> You May Also Like</h3>
                 <div class="bg-gray-100 border border-gray-300 text-gray-700 px-3 py-2 rounded-md text-sm" role="alert"><i class="fas fa-info-circle mr-1"></i> Could not load alternative apps.</div>
             </div>
         <?php endif; ?>
    </div>
</div>

<div id="qrModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center hidden z-50 p-4">
    <div class="relative p-5 border w-full max-w-sm shadow-lg rounded-md bg-white">
        <button id="closeQrModalX" class="absolute top-2 right-2 text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
        <div class="mt-3 text-center">
            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-2">Share this Page</h3>
            <div id="qrcodeOutput" class="flex justify-center my-4">
                </div>
            <p class="text-xs text-gray-500 mb-4">Scan QR to open on another device.</p>
             <div class="flex justify-center space-x-4 mb-4">
                 <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode($currentPageUrl); ?>&text=Check out this app download from <?php echo USER_DOMAIN; ?>!" target="_blank" rel="noopener noreferrer" class="text-gray-500 hover:text-gray-700" title="Share on Twitter"><i class="fab fa-twitter fa-2x"></i></a>
                  <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($currentPageUrl); ?>" target="_blank" rel="noopener noreferrer" class="text-gray-500 hover:text-gray-700" title="Share on Facebook"><i class="fab fa-facebook fa-2x"></i></a>
                 <a href="mailto:?subject=App Download: <?php echo $appNameForDisplay; ?>&body=Check out this app download from <?php echo USER_DOMAIN; ?>: <?php echo urlencode($currentPageUrl); ?>" class="text-gray-500 hover:text-gray-700" title="Share via Email"><i class="fas fa-envelope fa-2x"></i></a>
                 <button class="text-gray-500 hover:text-gray-700" onclick="copyPageUrl('<?php echo safe_htmlspecialchars($currentPageUrl); ?>', this)" title="Copy Link"><i class="fas fa-copy fa-2x"></i></button>
            </div>
            <div id="copyConfirmation" class="text-xs text-green-600 mt-2 hidden">Link copied!</div>
             <div class="items-center px-4 py-3"><button id="closeQrModalBtn" class="px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md w-full shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-400">Close</button>
            </div>
        </div>
    </div>
</div>

<div id="trustAppPopup" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center hidden z-50 p-4">
    <div class="relative p-6 border w-full max-w-md mx-auto shadow-lg rounded-lg bg-white transform transition-all sm:my-8 sm:align-middle sm:max-w-lg">
        <button id="closeTrustPopupX" class="absolute top-3 right-3 text-gray-500 hover:text-gray-700 text-2xl leading-none font-semibold">&times;</button>
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-blue-100">
                <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.001 12.001 0 002 12c0 2.757 1.122 5.214 2.921 7.071C6.727 20.73 9.261 22 12 22s5.273-1.27 7.071-2.929A12.001 12.001 0 0022 12c0-2.757-1.122-5.214-2.921-7.071z"></path>
                </svg>
            </div>
            <h3 class="text-lg leading-6 font-bold text-gray-900 mt-4 mb-2">App Trust & Safety</h3>
            <div class="mt-2 px-4 py-2">
                <p class="text-sm text-gray-600 mb-4">
                    This app has undergone rigorous security checks to ensure your safety.
                </p>
                <ul class="text-left text-sm text-gray-700 space-y-3">
                    <li class="flex items-center">
                        <svg class="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="font-medium">Scanned by Google Play Protect:</span> This app has been verified by Google Play Protect for harmful behavior.
                    </li>
                    <li class="flex items-center">
                        <svg class="w-5 h-5 text-blue-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a2 2 0 00-2 2v1a2 2 0 002 2h4a2 2 0 002-2v-1a2 2 0 00-2-2H8z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="font-medium">Uploaded by Verified Publisher:</span> The publisher of this app is recognized as a verified entity on Google Play.
                    </li>
                </ul>
            </div>
            <div class="mt-5 sm:mt-6">
                <button id="closeTrustPopupBtn" type="button" class="inline-flex justify-center w-full rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:text-sm">
                    Got it!
                </button>
            </div>
        </div>
    </div>
</div>


<?php if ($errorType === 'none' && !empty($downloadInfo['final_url'])): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    // Countdown Timer Logic
    let seconds = <?php echo $waitSeconds; ?>;
    const finalDownloadBtn = document.getElementById('finalDownloadBtn');
    const countdownDisplay = document.getElementById('countdown');
    const manualDownloadLink = document.getElementById('manualDownload');
    const countdownIcon = countdownDisplay ? countdownDisplay.querySelector('.fa-hourglass-half') : null;

    if (finalDownloadBtn) { 
        finalDownloadBtn.style.pointerEvents = 'none';
        finalDownloadBtn.classList.add('grayscale'); 
        finalDownloadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (finalDownloadBtn.style.pointerEvents !== 'none') {
                window.location.href = '<?php echo $downloadProxyUrl; ?>';
            }
        });
    }

    if (manualDownloadLink) {
        manualDownloadLink.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = '<?php echo $downloadProxyUrl; ?>';
        });
    }

    function enableDownloadButton() {
        if (finalDownloadBtn && countdownDisplay) {
            finalDownloadBtn.classList.remove('opacity-50', 'grayscale');
            finalDownloadBtn.style.pointerEvents = 'auto';
            countdownDisplay.innerHTML = '<i class="fas fa-check-circle mr-2 text-green-500"></i> Ready!';
            if(countdownIcon) countdownIcon.classList.remove('fa-spin');
        }
    }

    function startCountdown() {
        if (seconds > 0) {
            seconds--;
            if (countdownDisplay) {
                 countdownDisplay.innerHTML = '<i class="fas fa-hourglass-half fa-spin mr-2"></i> ' + seconds;
            }
            setTimeout(startCountdown, 1000);
        } else {
            enableDownloadButton();
            window.location.href = '<?php echo $downloadProxyUrl; ?>';
        }
    }

    if (finalDownloadBtn && countdownDisplay && seconds > 0) {
         countdownDisplay.innerHTML = '<i class="fas fa-hourglass-half fa-spin mr-2"></i> ' + seconds;
         setTimeout(startCountdown, 1000);
    } else if (finalDownloadBtn) {
        enableDownloadButton();
    }

    // QR Code Modal Logic
    const qrModal = document.getElementById('qrModal');
    const shareQrBtn = document.getElementById('shareQrBtn');
    const closeQrModalBtn = document.getElementById('closeQrModalBtn');
    const closeQrModalX = document.getElementById('closeQrModalX');
    const qrcodeOutputDiv = document.getElementById('qrcodeOutput');
    let qrCodeInstance = null;

    if (shareQrBtn && qrModal && closeQrModalBtn && closeQrModalX && qrcodeOutputDiv) {
        shareQrBtn.addEventListener('click', function() {
            qrModal.classList.remove('hidden');
            if (!qrCodeInstance) {
                 qrcodeOutputDiv.innerHTML = '';
                 qrCodeInstance = new QRCode(qrcodeOutputDiv, {
                    text: '<?php echo $currentPageUrl; ?>',
                    width: 128,
                    height: 128,
                    colorDark : "#000000",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.H
                });
            }
        });
        closeQrModalBtn.addEventListener('click', function() { qrModal.classList.add('hidden'); });
        closeQrModalX.addEventListener('click', function() { qrModal.classList.add('hidden'); });
        qrModal.addEventListener('click', function(event) {
            if (event.target === qrModal) {
                qrModal.classList.add('hidden');
            }
        });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !qrModal.classList.contains('hidden')) {
                qrModal.classList.add('hidden');
            }
        });
    }
    function copyPageUrl(url, buttonElement) {
        const tempInput = document.createElement('textarea');
        tempInput.value = url;
        document.body.appendChild(tempInput);
        tempInput.select();
        try {
            document.execCommand('copy');
            const originalText = buttonElement.innerHTML;
            buttonElement.innerHTML = '<i class="fas fa-check fa-2x text-green-500"></i>';
            const conf = document.getElementById('copyConfirmation');
            if (conf) { conf.classList.remove('hidden');}

            setTimeout(() => {
                buttonElement.innerHTML = originalText;
                if (conf) { conf.classList.add('hidden');}
            }, 2000);
        } catch (err) {
            console.error('Could not copy URL: ', err);
            const messageBox = document.createElement('div');
            messageBox.className = 'fixed bottom-4 right-4 bg-red-600 text-white px-4 py-2 rounded-md shadow-lg z-50';
            messageBox.textContent = 'Failed to copy URL.';
            document.body.appendChild(messageBox);
            setTimeout(() => {
                messageBox.remove();
            }, 3000);
        } finally {
            document.body.removeChild(tempInput);
        }
    }

    // Trust App Popup Logic
    const trustAppBadge = document.getElementById('trustAppBadge');
    const trustAppPopup = document.getElementById('trustAppPopup');
    const closeTrustPopupBtn = document.getElementById('closeTrustPopupBtn');
    const closeTrustPopupX = document.getElementById('closeTrustPopupX');

    if (trustAppBadge && trustAppPopup && closeTrustPopupBtn && closeTrustPopupX) {
        trustAppBadge.addEventListener('click', function() {
            trustAppPopup.classList.remove('hidden');
        });

        closeTrustPopupBtn.addEventListener('click', function() {
            trustAppPopup.classList.add('hidden');
        });

        closeTrustPopupX.addEventListener('click', function() {
            trustAppPopup.classList.add('hidden');
        });

        trustAppPopup.addEventListener('click', function(event) {
            if (event.target === trustAppPopup) {
                trustAppPopup.classList.add('hidden');
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !trustAppPopup.classList.contains('hidden')) {
                trustAppPopup.classList.add('hidden');
            }
        });
    }
</script>
<?php endif; ?>

<?php
include 'includes/footer.php';
?>
