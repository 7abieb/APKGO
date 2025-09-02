<?php
// config.php
// This file loads the configuration from config.json and makes it available globally.

define('CONFIG_FILE_PATH', __DIR__ . '/config.json');

/**
 * Loads configuration from the JSON file.
 * This function is designed to be called once and its result cached or used to define constants.
 * @return array The configuration array.
 */
function loadSiteConfig(): array {
    if (!file_exists(CONFIG_FILE_PATH)) {
        // Fallback to default if config.json is missing
        error_log("config.json not found. Using default configuration.");
        return [
            'seo' => [
                'default_title' => 'YanduX - Free APK & XAPK Downloads - Safe Android Apps & Games',
                'default_description' => 'Download free and safe APK and XAPK files for Android apps and games on Yandux. Get the latest versions quickly and easily. Your trusted source for free Android downloads.',
                'default_keywords' => 'APK download, XAPK download, free android apps, free android games, safe apk, yandux, download apk free, android downloader',
                'robots' => 'index, follow'
            ],
            'domains' => [
                'user_domain' => 'Yandux.Biz',
                'source_domain' => 'apkfab.com'
            ],
            'header_links' => [
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Categories', 'url' => '/categories'],
                ['name' => 'Chat', 'url' => '/chat.php']
            ],
            'footer_links' => [
                ['name' => 'Privacy', 'url' => '/privacy'],
                ['name' => 'About Us', 'url' => '/about'],
                ['name' => 'Contact Us', 'url' => '/contact'],
                ['name' => 'FAQ', 'url' => '/FAQ'],
                ['name' => 'Sitemap', 'url' => '/sitemap.php']
            ]
        ];
    }

    $configContent = file_get_contents(CONFIG_FILE_PATH);
    $config = json_decode($configContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Error decoding config.json: " . json_last_error_msg() . ". Using default configuration.");
        // Fallback to default if JSON is invalid
        return [
            'seo' => [
                'default_title' => 'YanduX - Free APK & XAPK Downloads - Safe Android Apps & Games',
                'default_description' => 'Download free and safe APK and XAPK files for Android apps and games on Yandux. Get the latest versions quickly and easily. Your trusted source for free Android downloads.',
                'robots' => 'index, follow'
            ],
            'domains' => [
                'user_domain' => 'Yandux.Biz',
                'source_domain' => 'apkfab.com'
            ],
            'header_links' => [
                ['name' => 'Home', 'url' => '/'],
                ['name' => 'Categories', 'url' => '/categories'],
                ['name' => 'Chat', 'url' => '/chat.php']
            ],
            'footer_links' => [
                ['name' => 'Privacy', 'url' => '/privacy'],
                ['name' => 'About Us', 'url' => '/about'],
                ['name' => 'Contact Us', 'url' => '/contact'],
                ['name' => 'FAQ', 'url' => '/FAQ'],
                ['name' => 'Sitemap', 'url' => '/sitemap.php']
            ]
        ];
    }

    return $config;
}

// Load the configuration
$siteConfig = loadSiteConfig();

// Define constants for common settings used across the site
if (!defined('USER_DOMAIN')) {
    define('USER_DOMAIN', $siteConfig['domains']['user_domain'] ?? 'Yandux.Biz');
}
if (!defined('SOURCE_DOMAIN')) {
    define('SOURCE_DOMAIN', $siteConfig['domains']['source_domain'] ?? 'apkfab.com');
}

// SEO Defaults (can be overridden by individual pages)
if (!isset($pageTitle)) {
    $pageTitle = htmlspecialchars($siteConfig['seo']['default_title'] ?? 'Default Title');
}
if (!isset($pageDescription)) {
    $pageDescription = htmlspecialchars($siteConfig['seo']['default_description'] ?? 'Default Description');
}
if (!isset($pageKeywords)) {
    $pageKeywords = htmlspecialchars($siteConfig['seo']['default_keywords'] ?? 'default, keywords');
}
if (!isset($pageRobots)) {
    $pageRobots = htmlspecialchars($siteConfig['seo']['robots'] ?? 'index, follow');
}

// Make links available globally
$headerLinks = $siteConfig['header_links'] ?? [];
$footerLinks = $siteConfig['footer_links'] ?? [];

// You might also want to define a global function to get links if needed outside of header/footer
function getHeaderLinks(): array {
    global $headerLinks;
    return $headerLinks;
}

function getFooterLinks(): array {
    global $footerLinks;
    return $footerLinks;
}

?>
