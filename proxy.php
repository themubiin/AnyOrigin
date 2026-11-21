<?php
/**
 * AnyOrigin Standalone PHP Reverse Proxy Engine
 * Deploy to cPanel / Shared Hosting / Apache / Nginx
 * 
 * Features:
 * - Zero Database Required
 * - Zero External Dependencies
 * - Sub-Second Multi-Cloud Failover
 * - Preserves Cookies, Sessions, and HTTP Headers
 */

// Upstream origins configuration (Can also be loaded from config.json)
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

$request_method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$headers = function_exists('getallheaders') ? getallheaders() : [];

$input_body = file_get_contents('php://input');

foreach ($origins as $index => $origin) {
    $target_url = rtrim($origin['url'], '/') . $request_uri;
    $ch = curl_init($target_url);

    $forward_headers = [];
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'host') {
            $forward_headers[] = "Host: " . $origin['host'];
        } else {
            $forward_headers[] = "$k: $v";
        }
    }
    $forward_headers[] = "X-AnyOrigin-Engine: PHP-Native";
    $forward_headers[] = "X-Forwarded-For: " . ($_SERVER['REMOTE_ADDR'] ?? '');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forward_headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout_seconds);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if (!empty($input_body) && $request_method !== 'GET' && $request_method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $input_body);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $should_failover = false;
    if ($curl_error) {
        $should_failover = true;
    } elseif ($trigger_402 && $http_code === 402) {
        $should_failover = true;
    } elseif ($trigger_429 && $http_code === 429) {
        $should_failover = true;
    } elseif ($trigger_5xx && $http_code >= 500) {
        $should_failover = true;
    }

    // If failover condition triggered and another origin exists in the chain, try it
    if ($should_failover && $index < count($origins) - 1) {
        continue;
    }

    // Stream back response to visitor
    $response_headers = substr($response, 0, $header_size);
    $response_body = substr($response, $header_size);

    http_response_code($http_code ?: 502);
    foreach (explode("\r\n", $response_headers) as $hdr) {
        if (!empty($hdr) && !str_starts_with(strtolower($hdr), 'transfer-encoding:')) {
            header($hdr, false);
        }
    }
    header("X-Served-By-Origin: " . $origin['name']);
    echo $response_body;
    exit;
}

http_response_code(502);
echo "All AnyOrigin upstream hosts are unreachable.";
