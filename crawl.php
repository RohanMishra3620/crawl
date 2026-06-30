<?php
declare(strict_types=1);

/**
 * PHP 8.x recursive website crawler.
 *
 * Browser:
 *   http://localhost/office/crawl.php?url=https://example.com&max=500
 *
 * CLI:
 *   php crawl.php https://example.com 500
 */

const DEFAULT_MAX_PAGES = 500;
const OUTPUT_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'urls.txt';
const REQUEST_TIMEOUT_SECONDS = 25;
const CONNECT_TIMEOUT_SECONDS = 10;
const MAX_REDIRECTS = 10;
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
}

[$startUrl, $maxPages] = getInput();

if ($startUrl === null) {
    showUsage();
    exit(1);
}

$startUrl = normalizeUrl($startUrl, $startUrl);

if ($startUrl === null) {
    outputLine('Invalid start URL. Use a valid http:// or https:// URL.');
    exit(1);
}

crawl($startUrl, $maxPages);

/**
 * Fetch a URL using cURL and return status, final URL, body, and error details.
 *
 * @return array{ok: bool, status: int, url: string, contentType: string, body: string, error: string}
 */
function fetchPage(string $url): array
{
    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => MAX_REDIRECTS,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_ENCODING => '', // Accept and decode gzip/deflate/br when supported by the local cURL build.
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
    ];

    if (defined('CURLOPT_PROTOCOLS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }

    if (defined('CURLOPT_REDIR_PROTOCOLS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    curl_close($ch);

    return [
        'ok' => is_string($body) && $error === '' && $status >= 200 && $status < 400,
        'status' => $status,
        'url' => $finalUrl !== '' ? $finalUrl : $url,
        'contentType' => $contentType,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

/**
 * Convert an absolute, protocol-relative, root-relative, or path-relative URL to
 * a normalized absolute HTTP(S) URL. Unsupported schemes and anchors return null.
 */
function normalizeUrl(string $href, string $baseUrl): ?string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($href === '' || $href === '#') {
        return null;
    }

    if ($href[0] === '#') {
        return null;
    }

    if (preg_match('~^(mailto|javascript|tel):~i', $href)) {
        return null;
    }

    $base = parse_url($baseUrl);

    if (str_starts_with($href, '//')) {
        $scheme = isset($base['scheme']) && is_string($base['scheme']) ? strtolower($base['scheme']) : 'https';
        $href = $scheme . ':' . $href;
    } elseif (!preg_match('~^https?://~i', $href)) {
        if (!isset($base['scheme'], $base['host'])) {
            return null;
        }

        $scheme = strtolower((string) $base['scheme']);
        $host = strtolower((string) $base['host']);
        $port = isset($base['port']) ? ':' . (int) $base['port'] : '';

        if (str_starts_with($href, '/')) {
            $href = $scheme . '://' . $host . $port . $href;
        } else {
            $basePath = isset($base['path']) && is_string($base['path']) ? $base['path'] : '/';
            $directory = preg_replace('~/[^/]*$~', '/', $basePath);
            $directory = is_string($directory) && $directory !== '' ? $directory : '/';
            $href = $scheme . '://' . $host . $port . $directory . $href;
        }
    }

    $parts = parse_url($href);

    if (!isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $host = strtolower((string) $parts['host']);
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = normalizePath(isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/');
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . (string) $parts['query'] : '';

    return $scheme . '://' . $host . $port . $path . $query;
}

/**
 * Extract unique absolute links from a HTML document.
 *
 * @return list<string>
 */
function extractLinks(string $html, string $baseUrl): array
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return [];
    }

    $links = [];

    foreach ($dom->getElementsByTagName('a') as $anchor) {
        $href = $anchor->getAttribute('href');
        $url = normalizeUrl($href, $baseUrl);

        if ($url !== null) {
            $links[$url] = true;
        }
    }

    return array_keys($links);
}

/**
 * Recursively crawl internal pages once, collect every discovered link, and
 * save all unique discovered URLs to urls.txt.
 */
function crawl(string $startUrl, int $maxPages = DEFAULT_MAX_PAGES): void
{
    $startHost = getComparableHost($startUrl);

    if ($startHost === null) {
        outputLine('Invalid start URL host.');
        return;
    }

    $queue = [$startUrl];
    $queued = [$startUrl => true];
    $visited = [];
    $found = [];
    $issues = [];

    outputLine('Starting crawl: ' . $startUrl);
    outputLine('Maximum pages: ' . $maxPages);
    outputLine('');

    while ($queue !== [] && count($visited) < $maxPages) {
        $currentUrl = array_shift($queue);

        if (!is_string($currentUrl) || isset($visited[$currentUrl])) {
            continue;
        }

        unset($queued[$currentUrl]);
        $visited[$currentUrl] = true;

        outputLine('Crawling: ' . $currentUrl);

        $response = fetchPage($currentUrl);
        $finalUrl = normalizeUrl($response['url'], $currentUrl) ?? $currentUrl;

        if ($finalUrl !== $currentUrl) {
            $visited[$finalUrl] = true;
        }

        if (!$response['ok']) {
            $message = describeFetchProblem($response, $currentUrl);
            $issues[] = $message;
            outputLine('  Skipped: ' . $message);
            outputProgress(count($visited), count($found));
            continue;
        }

        if (!isHtmlResponse($response['contentType'], $response['body'])) {
            outputLine('  Skipped: response is not HTML (' . ($response['contentType'] ?: 'unknown content type') . ').');
            outputProgress(count($visited), count($found));
            continue;
        }

        $accessIssue = detectBlockedOrJavaScriptRequired($response['body'], $response['status']);

        if ($accessIssue !== null) {
            $issues[] = $currentUrl . ' - ' . $accessIssue;
            outputLine('  Notice: ' . $accessIssue);
        }

        $links = extractLinks($response['body'], $finalUrl);

        foreach ($links as $link) {
            $found[$link] = true;

            if (
                isInternalUrl($link, $startHost)
                && !isset($visited[$link])
                && !isset($queued[$link])
                && count($visited) + count($queue) < $maxPages
            ) {
                $queue[] = $link;
                $queued[$link] = true;
            }
        }

        outputProgress(count($visited), count($found));
    }

    $urls = array_keys($found);
    sort($urls, SORT_STRING);
    saveUrls($urls);

    outputLine('');
    outputLine('Finished.');
    outputLine('Pages visited: ' . count($visited));
    outputLine('Unique URLs found: ' . count($urls));
    outputLine('Saved to: ' . OUTPUT_FILE);

    if ($queue !== [] && count($visited) >= $maxPages) {
        outputLine('Stopped because the maximum page limit was reached.');
    }

    if ($issues !== []) {
        outputLine('');
        outputLine('Warnings:');
        foreach (array_unique($issues) as $issue) {
            outputLine('- ' . $issue);
        }
    }
}

/**
 * Save unique URLs to the output file, one per line.
 *
 * @param list<string> $urls
 */
function saveUrls(array $urls): void
{
    $content = implode(PHP_EOL, array_values(array_unique($urls)));

    if ($content !== '') {
        $content .= PHP_EOL;
    }

    file_put_contents(OUTPUT_FILE, $content, LOCK_EX);
}

function normalizePath(string $path): string
{
    $path = preg_replace('~/+~', '/', $path);
    $path = is_string($path) ? $path : '/';
    $segments = [];

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($segments);
            continue;
        }

        $segments[] = $segment;
    }

    return '/' . implode('/', $segments);
}

function isHtmlResponse(string $contentType, string $body): bool
{
    if ($contentType !== '') {
        return stripos($contentType, 'text/html') !== false
            || stripos($contentType, 'application/xhtml+xml') !== false;
    }

    return stripos($body, '<html') !== false || stripos($body, '<!doctype html') !== false;
}

function isInternalUrl(string $url, string $startHost): bool
{
    $host = getComparableHost($url);

    return $host !== null && $host === $startHost;
}

function getComparableHost(string $url): ?string
{
    $host = parse_url($url, PHP_URL_HOST);

    if (!is_string($host) || $host === '') {
        return null;
    }

    $host = strtolower($host);

    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

function describeFetchProblem(array $response, string $url): string
{
    if ($response['error'] !== '') {
        return $url . ' - cURL error: ' . $response['error'];
        
    }

    $status = (int) $response['status'];

    if ($status === 401 || $status === 403) {
        return $url . ' - HTTP ' . $status . ' access denied. The site may block automated requests.';
    }

    if ($status === 429) {
        return $url . ' - HTTP 429 rate limited. The site is refusing more requests right now.';
    }

    if ($status >= 400) {
        return $url . ' - HTTP error ' . $status . '.';
    }

    return $url . ' - empty or invalid response.';
}

function detectBlockedOrJavaScriptRequired(string $html, int $status): ?string
{
    $sample = strtolower(substr($html, 0, 200000));

    if ($status === 403 || str_contains($sample, 'access denied') || str_contains($sample, 'request blocked')) {
        return 'The target appears to be blocking this request. No bypass was attempted.';
    }

    $botSignals = [
        'cf-browser-verification',
        'cf-challenge',
        'checking your browser',
        'captcha',
        'recaptcha',
        'hcaptcha',
        'verify you are human',
        'unusual traffic',
    ];

    foreach ($botSignals as $signal) {
        if (str_contains($sample, $signal)) {
            return 'The target appears to require bot verification or anti-automation checks. No bypass was attempted.';
        }
    }

    $scriptCount = preg_match_all('~<script\b~i', $html);
    $anchorCount = preg_match_all('~<a\b~i', $html);
    $jsSignals = [
        'enable javascript',
        'requires javascript',
        'please enable js',
        'id="__next"',
        'id="root"',
        'id="app"',
    ];

    foreach ($jsSignals as $signal) {
        if (str_contains($sample, $signal) && $scriptCount > 5 && $anchorCount < 3) {
            return 'The page appears to require JavaScript rendering. This crawler does not render JavaScript.';
        }
    }

    return null;
}

function outputProgress(int $visitedCount, int $foundCount): void
{
    outputLine('  Pages visited: ' . $visitedCount . ' | URLs found: ' . $foundCount);
    outputLine('');
}

function outputLine(string $message): void
{
    echo $message . PHP_EOL;

    if (PHP_SAPI !== 'cli') {
        echo str_repeat(' ', 1024);
    }

    flush();
}

/**
 * @return array{0: ?string, 1: int}
 */
function getInput(): array
{
    if (PHP_SAPI === 'cli') {
        global $argv;

        $url = isset($argv[1]) ? trim((string) $argv[1]) : '';
        $max = isset($argv[2]) ? (int) $argv[2] : DEFAULT_MAX_PAGES;
    } else {
        $url = isset($_REQUEST['url']) ? trim((string) $_REQUEST['url']) : '';
        $max = isset($_REQUEST['max']) ? (int) $_REQUEST['max'] : DEFAULT_MAX_PAGES;
    }

    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    if ($max < 1) {
        $max = DEFAULT_MAX_PAGES;
    }

    return [$url !== '' ? $url : null, $max];
}

function showUsage(): void
{
    outputLine('Usage:');
    outputLine('  CLI:     php crawl.php https://example.com 500');
    outputLine('  Browser: crawl.php?url=https://example.com&max=500');
}
