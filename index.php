<?php

declare(strict_types=1);

/**
 * AnyOrigin - Ingress Router & Failover Proxy
 */

$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$request_path = parse_url($request_uri, PHP_URL_PATH) ?? '/';

// Direct static file pass-through for PHP CLI server
if (php_sapi_name() === 'cli-server') {
    $static_file = __DIR__ . $request_path;
    if ($request_path !== '/' && file_exists($static_file) && !is_dir($static_file)) {
        return false;
    }
}

// Pass console requests to the management panel
if (str_starts_with($request_path, '/anyorigin')) {
    $sub_path = substr($request_path, strlen('/anyorigin'));
    $file = __DIR__ . '/anyorigin' . ($sub_path === '' || $sub_path === '/' ? '/index.php' : $sub_path);
    if (file_exists($file) && !is_dir($file)) {
        require $file;
        exit;
    }
    require __DIR__ . '/anyorigin/index.php';
    exit;
}

// Load cached routing table
$config_file = __DIR__ . '/data/config.json';
if (!file_exists($config_file)) {
    require_once __DIR__ . '/anyorigin/bootstrap.php';
    getDb();
}

$raw_config = file_get_contents($config_file);
$config = $raw_config ? json_decode($raw_config, true) : null;
$origins = $config['origins'] ?? [];
$rules = $config['rules'] ?? [];

if (empty($origins)) {
    http_response_code(503);
    echo "<h1>503 Service Unavailable</h1><p>No upstream origins configured. Visit <a href='/anyorigin'>/anyorigin</a> to configure hosts.</p>";
    exit;
}

$request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$client_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$client_proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

$is_safe_method = in_array($request_method, ['GET', 'HEAD', 'OPTIONS'], true);
$timeout_seconds = max(1, (int)ceil(($rules['timeout_ms'] ?? 3000) / 1000));

// Capture request body for POST/PUT/PATCH
$incoming_headers = function_exists('getallheaders') ? getallheaders() : [];
$input_body = null;
if (!$is_safe_method) {
    $input_body = file_get_contents('php://input');
}

$start_time = microtime(true);
$failed_origins = [];

foreach ($origins as $index => $origin) {
    $target_url = rtrim($origin['url'], '/') . $request_uri;
    $upstream_host = !empty($origin['host']) ? $origin['host'] : parse_url($origin['url'], PHP_URL_HOST);

    $ch = curl_init($target_url);

    $forward_headers = [];
    foreach ($incoming_headers as $k => $v) {
        $lower_k = strtolower($k);
        if ($lower_k === 'host') {
            $forward_headers[] = "Host: " . $upstream_host;
        } elseif ($lower_k !== 'content-length' || $input_body !== null) {
            $forward_headers[] = "$k: $v";
        }
    }
    $forward_headers[] = "X-Forwarded-Host: " . $client_host;
    $forward_headers[] = "X-Forwarded-Proto: " . $client_proto;
    $forward_headers[] = "X-Forwarded-For: " . ($_SERVER['REMOTE_ADDR'] ?? '');
    $forward_headers[] = "X-AnyOrigin-Tier: " . ($index + 1);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $request_method,
        CURLOPT_HTTPHEADER => $forward_headers,
        CURLOPT_TIMEOUT => $timeout_seconds,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($input_body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $input_body);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $is_402 = (!empty($rules['trigger_402']) && $http_code === 402);
    $is_429 = (!empty($rules['trigger_429']) && $http_code === 429);
    $is_5xx = (!empty($rules['trigger_5xx']) && $http_code >= 500);

    // Idempotency check: don't retry non-safe methods (POST/PUT) on 5xx or connection drops
    $can_safely_retry = $is_safe_method || $is_402 || $is_429;

    $should_failover = false;
    if ($curl_error && $is_safe_method) {
        $should_failover = true;
    } elseif ($is_402 || $is_429 || ($is_5xx && $can_safely_retry)) {
        $should_failover = true;
    }

    if ($should_failover && $index < count($origins) - 1) {
        $failed_origins[] = $origin['name'];
        continue;
    }

    // Stream back response
    $total_latency = round((microtime(true) - $start_time) * 1000);
    $response_headers = substr($response, 0, $header_size);
    $response_body = substr($response, $header_size);

    http_response_code($http_code ?: 502);

    $header_lines = explode("\r\n", $response_headers);
    foreach ($header_lines as $hdr) {
        if (empty($hdr)) continue;
        $lower_hdr = strtolower($hdr);

        if (str_starts_with($lower_hdr, 'transfer-encoding:')) continue;

        // Rewrite Location header to keep visitor on main host
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

        // Rewrite cookie domain
        if (str_starts_with($lower_hdr, 'set-cookie:')) {
            $client_domain = explode(':', $client_host)[0];
            $rewritten_cookie = preg_replace('/Domain=[^;]+/i', 'Domain=' . $client_domain, $hdr);
            header($rewritten_cookie, false);
            continue;
        }

        header($hdr, false);
    }

    header("X-Served-By: " . $origin['name']);
    header("X-Response-Time: {$total_latency}ms");
    if (!empty($failed_origins)) {
        header("X-Failovers: " . implode(', ', $failed_origins));
    }

    echo $response_body;
    exit;
}

http_response_code(502);
echo "502 Bad Gateway: All upstream origins unreachable.";
