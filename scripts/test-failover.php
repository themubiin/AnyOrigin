<?php
/**
 * AnyOrigin Standalone PHP Verification Suite
 * Run with: php scripts/test-failover.php
 */

require_once __DIR__ . '/../anyorigin/bootstrap.php';

echo "=== AnyOrigin Self-Hosted Verification Suite ===\n\n";

// 1. Verify Database Initialization
echo "1. Checking SQLite database initialization...\n";
$pdo = getDb();
$originsCount = (int)$pdo->query("SELECT COUNT(*) FROM origins")->fetchColumn();
echo "✓ SQLite connected successfully. Origins found: {$originsCount}\n";

// 2. Verify SSRF Protection
echo "\n2. Testing SSRF Shield...\n";
$test1 = validateOriginUrl("http://127.0.0.1:8080");
$test2 = validateOriginUrl("http://localhost:3000");
$test3 = validateOriginUrl("http://169.254.169.254/latest/meta-data");
$test4 = validateOriginUrl("https://example.com");

if ($test1['valid'] || $test2['valid'] || $test3['valid']) {
    echo "❌ SSRF Test Failed: Internal IPs allowed!\n";
    exit(1);
}
if (!$test4['valid']) {
    echo "❌ SSRF Test Failed: Valid URL blocked!\n";
    exit(1);
}
echo "✓ SSRF Shield successfully blocked private IPs and cloud metadata.\n";

// 3. Verify Cache Generation
echo "\n3. Testing fast-read config cache generation...\n";
$config = syncConfigCache($pdo);
if (!isset($config['origins']) || !file_exists(CONFIG_PATH)) {
    echo "❌ Cache generation failed!\n";
    exit(1);
}
echo "✓ Fast-path cache (data/config.json) generated with " . count($config['origins']) . " active origins.\n";

echo "\n✅ ALL PHP TESTS PASSED SUCCESSFULLY! Ready for cPanel & VPS.\n\n";
