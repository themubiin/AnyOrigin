<?php
/**
 * AnyOrigin Standalone PHP Reverse Proxy Engine
 * Deploy to cPanel / Shared Hosting / Apache / Nginx
 * 
 * Features:
 * - Zero Database Required
 * - Zero External Dependencies
 * - Safe Idempotency (Protects POST/PUT from duplicate execution)
 * - Location Redirect & Set-Cookie Domain Rewriter
 * - Sub-Second Multi-Cloud Failover
 */

// Upstream origins configuration
$origins = [
    [
        'name' => 'Vercel Primary',
        'url' => 'https://my-app.vercel.app',
        'host' => 'my-app.vercel.app',
    ],
    [
        'name' => 'Netlify Standby',
        'url' => 'https://my-app.netlify.app',
        'host' => 'my-app.netlify.app',
    ],
    [
        'name' => 'VPS Disaster Recovery',
        'url' => 'https://vps.example.org',
        'host' => 'vps.example.org',
    ],
];

// Failover trigger rules
$trigger_402 = true; // Payment Required (Free Tier Exhausted)
$trigger_429 = true; // Rate Limit Hit
$trigger_5xx = true; // Server Crash (500, 502, 503, 504)
$timeout_seconds = 3;

$request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$client_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$client_proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

$is_safe_method = in_array($request_method, ['GET', 'HEAD', 'OPTIONS']);
$headers = function_exists('getallheaders') ? getallheaders() : [];
$input_body = file_get_contents('php://input');

foreach ($origins as $index => $origin) {
    $target_url = rtrim($origin['url'], '/') . $request_uri;
    $upstream_host = $origin['host'] ?: parse_url($origin['url'], PHP_URL_HOST);

    $ch = curl_init($target_url);

    $forward_headers = [];
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'host') {
            $forward_headers[] = "Host: " . $upstream_host;
        } else {
            $forward_headers[] = "$k: $v";
        }
    }
    $forward_headers[] = "X-AnyOrigin-Engine: PHP-Native";
    $forward_headers[] = "X-Forwarded-Host: " . $client_host;
    $forward_headers[] = "X-Forwarded-Proto: " . $client_proto;
    $forward_headers[] = "X-Forwarded-For: " . ($_SERVER['REMOTE_ADDR'] ?? '');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forward_headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout_seconds);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if (!empty($input_body) && !$is_safe_method) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $input_body);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $is_402 = ($trigger_402 && $http_code === 402);
    $is_429 = ($trigger_429 && $http_code === 429);
    $is_5xx = ($trigger_5xx && $http_code >= 500);

    // IDEMPOTENCY SAFETY:
    // Only retry non-idempotent methods (POST/PUT/DELETE) on 402/429 where upstream platform rejected prior to execution.
    // Never blindly retry POST on 5xx or network drop.
    $can_safely_retry = $is_safe_method || $is_402 || $is_429;

    $should_failover = false;
    if ($curl_error && $is_safe_method) {
        $should_failover = true;
    } elseif ($is_402 || $is_429 || ($is_5xx && $can_safely_retry)) {
        $should_failover = true;
    }

    if ($should_failover && $index < count($origins) - 1) {
        continue; // Failover to next origin!
    }

    // Process and rewrite response headers
    $response_headers = substr($response, 0, $header_size);
    $response_body = substr($response, $header_size);

    http_response_code($http_code ?: 502);

    $header_lines = explode("\r\n", $response_headers);
    foreach ($header_lines as $hdr) {
        if (empty($hdr)) continue;
        $lower_hdr = strtolower($hdr);

        if (str_starts_with($lower_hdr, 'transfer-encoding:')) {
            continue; // Skip chunked transfer conflicts
        }

        // 1. Rewrite Location header so visitor stays on original domain
        if (str_starts_with($lower_hdr, 'location:')) {
            $parts = explode(':', $hdr, 2);
            $location_val = trim($parts[1] ?? '');
            $parsed_loc = parse_url($location_val);
            if (!empty($parsed_loc['host']) && $parsed_loc['host'] === $upstream_host) {
                $rewritten_loc = $client_proto . '://' . $client_host . ($parsed_loc['path'] ?? '/') . (!empty($parsed_loc['query']) ? '?' . $parsed_loc['query'] : '');
                header("Location: " . $rewritten_loc, false);
                continue;
            }
        }

        // 2. Rewrite Set-Cookie domain to client host
        if (str_starts_with($lower_hdr, 'set-cookie:')) {
            $client_domain = explode(':', $client_host)[0];
            $rewritten_cookie = preg_replace('/Domain=[^;]+/i', 'Domain=' . $client_domain, $hdr);
            header($rewritten_cookie, false);
            continue;
        }

        header($hdr, false);
    }

    header("X-Served-By-Origin: " . $origin['name']);
    echo $response_body;
    exit;
}

http_response_code(502);
echo "All AnyOrigin upstream hosts are unreachable.";
