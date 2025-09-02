<?php
/**
 * download-proxy.php
 *
 * This script acts as a proxy to fetch the final download link from the source.
 *
 * @version 6.0
 * @change  Improved the redirect handling logic with a more robust regex to
 * correctly parse the 'Location' header from the server's response.
 * This fixes the "Redirect detected, but no new location found" error
 * and ensures the sha1 parameter is preserved across redirects.
 */

// --- Parameters ---
$appId = isset($_GET['id']) ? trim($_GET['id']) : '';
$file = isset($_GET['file']) ? trim($_GET['file']) : '';
$sha1 = isset($_GET['sha1']) ? trim($_GET['sha1']) : '';

$errMsg = '';

if (!$appId || !$file) {
    $errMsg = "Missing or invalid parameters.";
} else {
    // --- Security Sanitization ---
    $appId = str_replace(['..', '\\'], '', $appId);
    $file = basename($file);
    $sha1_validated = preg_match('/^[a-f0-9]{40}$/i', $sha1) ? $sha1 : '';

    // --- Helper Functions ---

    /**
     * Fetches HTML, manually handling HTTP redirects to preserve the query string.
     * @param string $url The initial URL to request.
     * @return string|array The final HTML content or an error array.
     */
    function fetchHtmlWithRedirectHandling($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true, // We need headers to check for redirects
            CURLOPT_FOLLOWLOCATION => false, // We will handle redirects manually
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_errno($ch) ? curl_error($ch) : '';
        

        if ($err) {
            curl_close($ch);
            return ['error' => $err];
        }

        // Check for any redirect status code (301, 302, 307, etc.)
        if ($httpCode >= 300 && $httpCode < 400) {
            curl_close($ch); // Close the first handle
            
            // [FIX] Use a robust, case-insensitive regex to find the Location header.
            preg_match('/^Location:\s*(.*)/im', $response, $matches);
            $newUrl = trim($matches[1] ?? '');

            if (empty($newUrl)) {
                return ['error' => 'Redirect detected, but no new location found.'];
            }
            
            // The new URL from the 'Location' header will not have our query string.
            // We must re-attach it to ensure we get the correct version.
            $originalQuery = parse_url($url, PHP_URL_QUERY);
            if ($originalQuery) {
                // Check if the new URL already has a query string from the redirect
                if (parse_url($newUrl, PHP_URL_QUERY)) {
                    $newUrl .= '&' . $originalQuery;
                } else {
                    $newUrl .= '?' . $originalQuery;
                }
            }

            // Now, fetch the content from the fully corrected URL.
            // This time we can allow cURL to follow any further redirects.
            $ch2 = curl_init();
            curl_setopt_array($ch2, [
                CURLOPT_URL => $newUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true, // Safe to follow now
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 20,
            ]);
            $finalResponse = curl_exec($ch2);
            $err2 = curl_errno($ch2) ? curl_error($ch2) : '';
            curl_close($ch2);

            return $err2 ? ['error' => $err2] : $finalResponse;
        }

        curl_close($ch);
        // If not a redirect, we need to strip the headers from the initial response
        return substr($response, $headerSize);
    }

    function getFinalRedirectUrl($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);
        if ($finalUrl && filter_var($finalUrl, FILTER_VALIDATE_URL)) {
            return $finalUrl;
        } else {
            return ['error' => $err ?: 'Failed to resolve final CDN URL for: ' . $url];
        }
    }
    
    function extractDownloadLink($html) {
        if (is_array($html) && isset($html['error'])) return '';
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!@$dom->loadHTML($html)) { libxml_clear_errors(); return ''; }
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $linkNode = $xpath->query("//a[contains(@class, 'down_btn')]")->item(0);
        return $linkNode ? $linkNode->getAttribute('href') : '';
    }

    // --- Core Logic ---
    $sourceUrl = 'https://apkfab.com/' . $appId . '/download';
    if ($sha1_validated) {
        $sourceUrl .= '?sha1=' . urlencode($sha1_validated);
    }
    
    // Use the new function that correctly handles redirects
    $html = fetchHtmlWithRedirectHandling($sourceUrl);

    if (is_array($html) && isset($html['error'])) {
        $errMsg = "Could not fetch download page from source. " . htmlspecialchars($html['error']);
    } else {
        $intermediate_link = extractDownloadLink($html);
        
        if (!$intermediate_link) {
            $errMsg = "Could not find the download button link on the source page. The page layout may have changed or the version is unavailable.";
        } else {
            $final_url = getFinalRedirectUrl($intermediate_link);
        }
    }
    
    // --- Final Redirect or Error ---
    if (empty($errMsg) && !empty($final_url) && !is_array($final_url)) {
        $parsed = parse_url($final_url);
        $host = $parsed['host'] ?? '';
        if (preg_match('/\.winudf\.com$/', $host)) {
            parse_str($parsed['query'] ?? '', $query);
            $query['_fn'] = base64_encode($file);
            $new_query = http_build_query($query);
            $new_url = "{$parsed['scheme']}://{$parsed['host']}{$parsed['path']}?$new_query";
            header("Location: $new_url", true, 302);
            exit;
        } else {
            header("Location: $final_url", true, 302);
            exit;
        }
    } elseif (empty($errMsg)) {
         $errMsg = "Could not resolve final download URL. This can happen if the link from the source has expired. Please try again.";
         if (is_array($final_url) && isset($final_url['error'])) {
             $errMsg .= " Details: " . htmlspecialchars($final_url['error']);
         }
    }
}

// --- Error Page Display ---
$pageTitle = "Download Error | Yandux.biz";
include 'includes/header.php';
?>
<div class="flex flex-col items-center justify-center min-h-[60vh] px-4">
    <div class="w-full max-w-lg">
        <?php if ($errMsg): ?>
            <div class="flex flex-col items-center justify-center bg-white shadow rounded border border-red-200 animate-fade-in mb-8">
                <div class="flex items-center gap-3 py-8 px-4">
                    <span class="inline-flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                        <svg class="h-7 w-7 text-red-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.668 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.332.192 3.012 1.732 3z"/>
                        </svg>
                    </span>
                    <div>
                        <div class="text-lg font-bold text-red-600 mb-1">Oops, something went wrong!</div>
                        <div class="text-sm text-red-500"><?php echo htmlspecialchars($errMsg); ?></div>
                        <div class="text-xs text-gray-500 mt-2">
                            <a href="#" onclick="history.back();return false;" class="text-blue-600 hover:underline">Go Back</a>
                            or
                            <a href="/" class="text-blue-500 hover:underline">Go Home</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
