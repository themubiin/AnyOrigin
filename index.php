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
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AnyOrigin Gateway - Ready for Setup</title><style>*{box-sizing:border-box}body{background:#090d16;color:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}.card{background:#0f172a;border:1px solid #1e293b;border-radius:18px;padding:36px;max-width:440px;width:100%;text-align:center;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5)}.pill{display:inline-block;padding:3px 12px;border-radius:99px;background:rgba(56,189,248,0.12);color:#38bdf8;font-size:11.5px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;margin-bottom:16px}h1{font-size:19px;font-weight:750;margin:0 0 10px;letter-spacing:-.3px}p{font-size:13.5px;color:#94a3b8;line-height:1.55;margin:0 0 24px}a{display:inline-flex;align-items:center;justify-content:center;background:#0284c7;color:#fff;font-size:13.5px;font-weight:650;text-decoration:none;padding:10px 20px;border-radius:10px;transition:background .15s}a:hover{background:#0369a1}</style></head><body><div class="card"><div class="pill">Edge Gateway</div><h1>No Upstream Origins Configured</h1><p>The reverse proxy is operational and ready. Access the admin console to register your primary and standby origins.</p><a href="/anyorigin/">Open Console &rarr;</a></div></body></html>';
    exit;
}

$request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$client_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$client_proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

$is_safe_method = in_array($request_method, ['GET', 'HEAD', 'OPTIONS'], true);
$timeout_seconds = max(1, (int)ceil(($rules['timeout_ms'] ?? 3000) / 1000));
$max_retries = max(1, (int)($rules['max_retries'] ?? count($origins)));
$routing_strategy = $rules['routing_strategy'] ?? 'priority';
$safe_post_only = !isset($rules['safe_post_only']) || (bool)$rules['safe_post_only'];
$forward_client_ip = !isset($rules['forward_client_ip']) || (bool)$rules['forward_client_ip'];
$rewrite_locations = !isset($rules['rewrite_locations']) || (bool)$rules['rewrite_locations'];
$rewrite_cookies = !isset($rules['rewrite_cookies']) || (bool)$rules['rewrite_cookies'];

// Round-robin load balancing rotation
if ($routing_strategy === 'round_robin' && count($origins) > 1) {
    $rotation_index = (int)(microtime(true) * 1000) % count($origins);
    if ($rotation_index > 0) {
        $origins = array_merge(array_slice($origins, $rotation_index), array_slice($origins, 0, $rotation_index));
    }
}

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
    if ($forward_client_ip) {
        $forward_headers[] = "X-Forwarded-For: " . ($_SERVER['REMOTE_ADDR'] ?? '');
    }
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

    // Idempotency check: don't retry non-safe methods (POST/PUT) on 5xx if safe_post_only is active
    $can_safely_retry = $is_safe_method || $is_402 || $is_429 || (!$safe_post_only && $is_5xx);

    $should_failover = false;
    if ($curl_error && $is_safe_method) {
        $should_failover = true;
    } elseif ($is_402 || $is_429 || ($is_5xx && $can_safely_retry)) {
        $should_failover = true;
    }

    if ($should_failover && $index < count($origins) - 1 && count($failed_origins) < $max_retries) {
        $failed_origins[] = $origin['name'];
        continue;
    }

    if (!empty($failed_origins)) {
        try {
            $dbPath = __DIR__ . '/data/anyorigin.db';
            if (file_exists($dbPath)) {
                $db = new PDO('sqlite:' . $dbPath);
                $stmt = $db->prepare("INSERT INTO incidents (id, from_origin, to_origin, status_code, uri, created_at) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($failed_origins as $fOrig) {
                    $incId = 'inc-' . bin2hex(random_bytes(4));
                    $stmt->execute([$incId, $fOrig, $origin['name'], (int)($http_code ?: 0), (string)($request_uri ?? '/'), date('Y-m-d H:i:s')]);
                }
            }
        } catch (\Throwable $e) {}
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
        if ($rewrite_locations && str_starts_with($lower_hdr, 'location:')) {
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
        if ($rewrite_cookies && str_starts_with($lower_hdr, 'set-cookie:')) {
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
