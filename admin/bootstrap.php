<?php

declare(strict_types=1);

/**
 * AnyOrigin - SQLite Database & Cache Bootstrap
 */

session_start();

define('ANYORIGIN_DIR', dirname(__DIR__));
define('DATA_DIR', ANYORIGIN_DIR . '/data');
define('DB_PATH', DATA_DIR . '/anyorigin.db');
define('CONFIG_PATH', DATA_DIR . '/config.json');

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sites (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            domain TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS origins (
            id TEXT PRIMARY KEY,
            site_id TEXT NOT NULL,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            priority INTEGER NOT NULL,
            weight INTEGER NOT NULL DEFAULT 100,
            host_header_override TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'healthy',
            latency_ms INTEGER NOT NULL DEFAULT 0,
            last_checked_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS failover_rules (
            id TEXT PRIMARY KEY,
            site_id TEXT NOT NULL UNIQUE,
            trigger_402 INTEGER NOT NULL DEFAULT 1,
            trigger_429 INTEGER NOT NULL DEFAULT 1,
            trigger_5xx INTEGER NOT NULL DEFAULT 1,
            latency_threshold_ms INTEGER NOT NULL DEFAULT 3000,
            max_retries INTEGER NOT NULL DEFAULT 2,
            retry_interval_ms INTEGER NOT NULL DEFAULT 500,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS admin_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
    ");

    $pwdCheck = $pdo->prepare("SELECT value FROM admin_settings WHERE key = 'admin_password_hash'");
    $pwdCheck->execute();
    if (!$pwdCheck->fetchColumn()) {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT OR REPLACE INTO admin_settings (key, value, updated_at) VALUES (?, ?, ?)")
            ->execute(['admin_password_hash', password_hash('admin123', PASSWORD_DEFAULT), $now]);
    }

    syncConfigCache($pdo);
    return $pdo;
}

function syncConfigCache(?PDO $pdo = null): array {
    if (!$pdo) {
        $pdo = getDb();
    }

    $origins = $pdo->query("SELECT id, name, url, priority, host_header_override, is_active, status, latency_ms FROM origins WHERE is_active = 1 ORDER BY priority ASC")->fetchAll();
    $rules = $pdo->query("SELECT * FROM failover_rules LIMIT 1")->fetch() ?: [
        'trigger_402' => 1,
        'trigger_429' => 1,
        'trigger_5xx' => 1,
        'latency_threshold_ms' => 3000,
    ];

    $config = [
        'updated_at' => time(),
        'rules' => [
            'trigger_402' => !empty($rules['trigger_402']),
            'trigger_429' => !empty($rules['trigger_429']),
            'trigger_5xx' => !empty($rules['trigger_5xx']),
            'timeout_ms' => (int)($rules['latency_threshold_ms'] ?? 3000),
            'safe_post_only' => true,
        ],
        'origins' => array_map(function($o) {
            $host = !empty($o['host_header_override']) ? $o['host_header_override'] : parse_url($o['url'], PHP_URL_HOST);
            return [
                'id' => $o['id'],
                'name' => $o['name'],
                'url' => rtrim($o['url'], '/'),
                'host' => $host,
                'priority' => (int)$o['priority'],
            ];
        }, $origins),
    ];

    file_put_contents(CONFIG_PATH, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
    return $config;
}

function validateOriginUrl(string $url): array {
    if (empty($url)) {
        return ['valid' => false, 'error' => 'URL cannot be empty.'];
    }

    $parsed = parse_url(trim($url));
    if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
        return ['valid' => false, 'error' => 'Invalid URL. Must include http:// or https://'];
    }

    if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
        return ['valid' => false, 'error' => 'Only HTTP and HTTPS protocols are supported.'];
    }

    $host = strtolower($parsed['host']);
    $blocked = [
        '/^127\./',
        '/^10\./',
        '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
        '/^192\.168\./',
        '/^169\.254\./',
        '/^0\./',
        '/^localhost$/i',
        '/^::1$/',
        '/^fe80:/i',
    ];

    foreach ($blocked as $pattern) {
        if (preg_match($pattern, $host)) {
            return ['valid' => false, 'error' => "Private or loopback address ($host) is blocked."];
        }
    }

    return ['valid' => true, 'host' => $host];
}

function isAuthenticated(): bool {
    return !empty($_SESSION['anyorigin_auth']);
}

function requireAuth(): void {
    if (!isAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}
