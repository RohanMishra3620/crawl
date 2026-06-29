<?php
declare(strict_types=1);

/**
 * Crawl a website, collect links found on its pages, and save them to urls.txt.
 *
 * CLI:
 *   php backlink_crawler.php https://example.com
 *
 * Browser:
 *   /backlink_crawler.php?url=https://example.com
 */

const OUTPUT_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'urls.txt';
const MAX_PAGES = 200;
const REQUEST_TIMEOUT = 15;
const USER_AGENT = 'Mozilla/5.0 (compatible; SimpleBacklinkCrawler/1.0)';

$startUrl = PHP_SAPI === 'cli'
    ? ($argv[1] ?? '')
    : ($_GET['url'] ?? '');

$startUrl = normalizeStartUrl(trim((string) $startUrl));

if ($startUrl === null) {
    respond("Usage: php backlink_crawler.php https://example.com\nOr open: backlink_crawler.php?url=https://example.com", 400);
}

$startHost = parse_url($startUrl, PHP_URL_HOST);
if (!is_string($startHost) || $startHost === '') {
    respond("Invalid start URL: {$startUrl}", 400);
}

$queue = [$startUrl];
$visitedPages = [];
$foundLinks = [];

while ($queue !== [] && count($visitedPages) < MAX_PAGES) {
    $pageUrl = array_shift($queue);

    if (isset($visitedPages[$pageUrl])) {
        continue;
    }

    $visitedPages[$pageUrl] = true;
    $html = fetchHtml($pageUrl);

    if ($html === null) {
        continue;
    }

    foreach (extractLinks($html, $pageUrl) as $link) {
        $foundLinks[$link] = true;

        if (isSameHost($link, $startHost) && !isset($visitedPages[$link]) && !in_array($link, $queue, true)) {
            $queue[] = $link;
        }
    }
}

$links = array_keys($foundLinks);
sort($links, SORT_STRING);

file_put_contents(OUTPUT_FILE, implode(PHP_EOL, $links) . ($links === [] ? '' : PHP_EOL), LOCK_EX);

respond(
    "Crawled " . count($visitedPages) . " page(s).\n" .
    "Found " . count($links) . " unique link(s).\n" .
    "Saved to " . OUTPUT_FILE
);

function normalizeStartUrl(string $url): ?string
{
    if ($url === '') {
        return null;
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    return normalizeUrl($url);
}

function fetchHtml(string $url): ?string
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => REQUEST_TIMEOUT,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    ]);

    $body = curl_exec($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

    if (!is_string($body) || $statusCode < 200 || $statusCode >= 400) {
        return null;
    }

    if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
        return null;
    }

    return $body;
}

/**
 * @return list<string>
 */
function extractLinks(string $html, string $baseUrl): array
{
    $dom = new DOMDocument();

    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $links = [];

    foreach ($dom->getElementsByTagName('a') as $anchor) {
        $href = trim($anchor->getAttribute('href'));

        if ($href === '' || preg_match('#^(mailto:|tel:|javascript:)#i', $href)) {
            continue;
        }

        $absoluteUrl = resolveUrl($href, $baseUrl);
        if ($absoluteUrl === null) {
            continue;
        }

        $links[$absoluteUrl] = true;
    }

    return array_keys($links);
}

function resolveUrl(string $url, string $baseUrl): ?string
{
    if (str_starts_with($url, '//')) {
        $baseScheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return normalizeUrl($baseScheme . ':' . $url);
    }

    if (preg_match('#^https?://#i', $url)) {
        return normalizeUrl($url);
    }

    $base = parse_url($baseUrl);
    if (!isset($base['scheme'], $base['host'])) {
        return null;
    }

    $path = $base['path'] ?? '/';
    $directory = preg_replace('#/[^/]*$#', '/', $path) ?: '/';

    if (str_starts_with($url, '/')) {
        $path = $url;
    } else {
        $path = $directory . $url;
    }

    return normalizeUrl($base['scheme'] . '://' . $base['host'] . normalizePath($path));
}

function normalizeUrl(string $url): ?string
{
    $parts = parse_url($url);

    if (!isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $host = strtolower($parts['host']);
    $path = normalizePath($parts['path'] ?? '/');
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return $scheme . '://' . $host . $path . $query;
}

function normalizePath(string $path): string
{
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

function isSameHost(string $url, string $host): bool
{
    $urlHost = parse_url($url, PHP_URL_HOST);

    return is_string($urlHost) && strtolower($urlHost) === strtolower($host);
}

function respond(string $message, int $statusCode = 200): never
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo $message . PHP_EOL;
    exit($statusCode >= 400 ? 1 : 0);
}
