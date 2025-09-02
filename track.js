/**
 * track.js
 *
 * This script collects essential visitor data (browser, OS, device, URL, referrer,
 * session, time on page) and sends it to a PHP endpoint for logging.
 *
 * IMPORTANT: IP address, country, city, and other detailed geolocation data
 * are now looked up on the server-side by log_visit.php to ensure consistency
 * and bypass client-side API rate limits.
 *
 * A simple session ID is generated and stored in localStorage for consistency
 * across multiple page views within a single browsing session.
 * Time on page is approximated using `visibilitychange` and `beforeunload` events.
 */

(function() {
    // Configuration for the logging endpoint (now also serves as fetch endpoint)
    const CONSOLIDATED_ENDPOINT = 'https://yandux.biz/log_visit.php';

    let sessionId = localStorage.getItem('visitor_session_id');
    let pageLoadTime = Date.now(); // Timestamp when the page started loading
    let visitStartTime = Date.now(); // Timestamp when this specific page view began

    /**
     * Generates a unique session ID if one doesn't exist.
     * Uses a combination of timestamp and a random number.
     */
    function generateSessionId() {
        if (!sessionId) {
            sessionId = `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
            localStorage.setItem('visitor_session_id', sessionId);
        }
    }

    /**
     * Gets browser, OS, and device type from the User Agent string.
     * @returns {Object} An object containing browser, os, and device_type.
     */
    function getBrowserInfo() {
        const ua = navigator.userAgent;
        let browser = 'Unknown';
        let os = 'Unknown';
        let deviceType = 'Desktop';

        // Detect OS
        if (/Windows/i.test(ua)) os = 'Windows';
        else if (/Mac/i.test(ua)) os = 'macOS';
        else if (/Linux/i.test(ua)) os = 'Linux';
        else if (/Android/i.test(ua)) { os = 'Android'; deviceType = 'Mobile'; }
        else if (/iOS|iPhone|iPad|iPod/i.test(ua)) { os = 'iOS'; deviceType = 'Mobile'; }

        // Detect Browser
        if (/Chrome/i.test(ua) && !/Edge|Edg/i.test(ua)) browser = 'Chrome';
        else if (/Firefox/i.test(ua)) browser = 'Firefox';
        else if (/Safari/i.test(ua) && !/Chrome/i.test(ua)) browser = 'Safari';
        else if (/Edge|Edg/i.test(ua)) browser = 'Edge';
        else if (/MSIE|Trident/i.test(ua)) browser = 'IE';
        else if (/Opera|OPR/i.test(ua)) browser = 'Opera';

        // Further refine device type
        if (/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test(ua)) deviceType = 'Tablet';
        if (/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua) && deviceType !== 'Tablet') deviceType = 'Mobile';


        return { browser, os, device_type: deviceType };
    }

    /**
     * Sends collected visitor data to the consolidated backend endpoint.
     * @param {Object} data - The data object to send.
     */
    async function sendVisitData(data) {
        try {
            const response = await fetch(CONSOLIDATED_ENDPOINT, { // Use CONSOLIDATED_ENDPOINT
                method: 'POST', // Always POST for logging
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
                // Keepalive ensures the request is sent even if the page is unloaded
                keepalive: true
            });

            // console.log('Visit data sent:', response.ok ? 'Success' : 'Failed', data);
        } catch (error) {
            console.error('Error sending visit data:', error);
        }
    }

    /**
     * Calculates time spent on the page based on active time.
     * @returns {number} Time spent in seconds.
     */
    function calculateTimeOnPage() {
        const now = Date.now();
        const timeSpent = Math.floor((now - visitStartTime) / 1000);
        return timeSpent;
    }

    /**
     * Collects and sends initial page visit data.
     * Note: IP, Country, City are now determined server-side.
     */
    function trackPageLoad() {
        generateSessionId(); // Ensure session ID exists

        const browserInfo = getBrowserInfo();

        const data = {
            action: 'page_load', // Indicate this is an initial page load
            session_id: sessionId,
            user_id: null, // Placeholder for actual user ID if logged in (e.g., from a global JS var)
            // IP, country, city will be looked up server-side by log_visit.php
            ip_address: null, // Sending null, server will use REMOTE_ADDR
            country: null,    // Server will look this up
            city: null,       // Server will look this up
            browser: browserInfo.browser,
            os: browserInfo.os,
            device_type: browserInfo.device_type,
            visited_url: window.location.href,
            referrer: document.referrer || null,
            page_load_time_ms: Date.now() - pageLoadTime, // Time until this script sends data
            screen_resolution: `${window.screen.width}x${window.screen.height}`
        };

        sendVisitData(data);
    }

    /**
     * Handles page unload/visibility change to send final time on page.
     */
    function handlePageUnload() {
        const timeOnPage = calculateTimeOnPage();
        const data = {
            action: 'page_unload', // Indicate this is an unload event
            session_id: sessionId,
            visited_url: window.location.href, // Re-send URL for accuracy
            time_on_page_seconds: timeOnPage,
            timestamp: new Date().toISOString() // Current time for the update
        };
        sendVisitData(data);
    }

    // Event listeners to track page transitions
    window.addEventListener('beforeunload', handlePageUnload);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            handlePageUnload();
        } else {
            // Page became visible again, reset start time for future calculations
            visitStartTime = Date.now();
        }
    });

    // Initial page load tracking
    trackPageLoad();

})();
