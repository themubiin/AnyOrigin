<?php
require_once __DIR__ . '/bootstrap.php';
requireAuth();

$pdo = getDb();
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_origin') {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $host_header = trim($_POST['host_header'] ?? '');

        $validation = validateOriginUrl($url);
        if (!$validation['valid']) {
            $message = $validation['error'];
            $messageType = 'error';
        } else {
            $maxPriority = (int)$pdo->query("SELECT MAX(priority) FROM origins")->fetchColumn();
            $priority = $maxPriority + 1;
            if (empty($host_header)) {
                $host_header = $validation['host'];
            }

            $id = 'orig-' . bin2hex(random_bytes(4));
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO origins (id, site_id, name, url, priority, weight, host_header_override, is_active, status, latency_ms, last_checked_at, created_at, updated_at) VALUES (?, 'default-site', ?, ?, ?, 100, ?, 1, 'healthy', 0, ?, ?, ?)");
            $stmt->execute([$id, $name, $url, $priority, $host_header, $now, $now, $now]);

            syncConfigCache($pdo);
            $message = "Added origin '{$name}'.";
        }
    } elseif ($action === 'delete_origin') {
        $id = $_POST['id'] ?? '';
        $pdo->prepare("DELETE FROM origins WHERE id = ?")->execute([$id]);
        syncConfigCache($pdo);
        $message = "Origin deleted.";
    } elseif ($action === 'move_priority') {
        $id = $_POST['id'] ?? '';
        $direction = $_POST['direction'] ?? 'up';

        $origins = $pdo->query("SELECT id, priority FROM origins ORDER BY priority ASC")->fetchAll();
        $currentIndex = -1;
        foreach ($origins as $idx => $orig) {
            if ($orig['id'] === $id) {
                $currentIndex = $idx;
                break;
            }
        }

        if ($currentIndex !== -1) {
            $targetIndex = ($direction === 'up') ? $currentIndex - 1 : $currentIndex + 1;
            if ($targetIndex >= 0 && $targetIndex < count($origins)) {
                $currentId = $origins[$currentIndex]['id'];
                $targetId = $origins[$targetIndex]['id'];

                $currentPriority = $origins[$currentIndex]['priority'];
                $targetPriority = $origins[$targetIndex]['priority'];

                $pdo->prepare("UPDATE origins SET priority = ? WHERE id = ?")->execute([$targetPriority, $currentId]);
                $pdo->prepare("UPDATE origins SET priority = ? WHERE id = ?")->execute([$currentPriority, $targetId]);

                syncConfigCache($pdo);
                $message = "Priority updated.";
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare("SELECT is_active FROM origins WHERE id = ?");
        $stmt->execute([$id]);
        $current = (int)$stmt->fetchColumn();
        $newVal = $current ? 0 : 1;
        $pdo->prepare("UPDATE origins SET is_active = ? WHERE id = ?")->execute([$newVal, $id]);
        syncConfigCache($pdo);
        $message = $newVal ? "Origin enabled." : "Origin disabled.";
    } elseif ($action === 'update_rules') {
        $trigger_402 = !empty($_POST['trigger_402']) ? 1 : 0;
        $trigger_429 = !empty($_POST['trigger_429']) ? 1 : 0;
        $trigger_5xx = !empty($_POST['trigger_5xx']) ? 1 : 0;
        $timeout_ms = max(500, (int)($_POST['timeout_ms'] ?? 3000));
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("UPDATE failover_rules SET trigger_402 = ?, trigger_429 = ?, trigger_5xx = ?, latency_threshold_ms = ?, updated_at = ? WHERE site_id = 'default-site'");
        $stmt->execute([$trigger_402, $trigger_429, $trigger_5xx, $timeout_ms, $now]);

        syncConfigCache($pdo);
        $message = "Failover rules updated.";
    } elseif ($action === 'ping_host') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM origins WHERE id = ?");
        $stmt->execute([$id]);
        $origin = $stmt->fetch();
        if ($origin) {
            $ch = curl_init($origin['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_NOBODY => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            if (!empty($origin['host_header_override'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Host: " . $origin['host_header_override']]);
            }
            $start = microtime(true);
            curl_exec($ch);
            $latency = round((microtime(true) - $start) * 1000);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $status = ($code > 0 && $code < 500 && $code !== 402 && $code !== 429) ? 'healthy' : 'down';
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE origins SET latency_ms = ?, status = ?, last_checked_at = ? WHERE id = ?")->execute([$latency, $status, $now, $id]);
            syncConfigCache($pdo);

            $message = "{$origin['name']}: HTTP {$code} ({$latency}ms)";
            if ($err) $message .= " [{$err}]";
        }
    } elseif ($action === 'change_password') {
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT OR REPLACE INTO admin_settings (key, value, updated_at) VALUES ('admin_password_hash', ?, ?)")->execute([$hash, $now]);
            $message = "Password updated.";
        } else {
            $message = "Password must be at least 6 characters.";
            $messageType = 'error';
        }
    }
}

$origins = $pdo->query("SELECT * FROM origins ORDER BY priority ASC")->fetchAll();
$rules = $pdo->query("SELECT * FROM failover_rules LIMIT 1")->fetch() ?: [
    'trigger_402' => 1,
    'trigger_429' => 1,
    'trigger_5xx' => 1,
    'latency_threshold_ms' => 3000,
];

$primaryOrigin = $origins[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AnyOrigin - Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { background-color: #0b0f19; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    </style>
</head>
<body class="min-h-screen text-slate-100 flex flex-col">

    <header class="border-b border-slate-800 bg-[#0f172a] px-6 py-3 flex items-center justify-between">
        <div class="flex items-center gap-2.5">
            <div class="h-8 w-8 rounded-lg bg-sky-500/10 border border-sky-500/20 flex items-center justify-center">
                <i data-lucide="shield" class="h-4 w-4 text-sky-400"></i>
            </div>
            <div>
                <span class="font-bold text-sm text-white">AnyOrigin</span>
                <span class="text-[11px] text-slate-400 ml-1.5 font-mono">v1.0</span>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('passwordModal').classList.remove('hidden')" 
                    class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-slate-300 hover:text-white transition-colors">
                Change Password
            </button>
            <a href="login.php?logout=1" 
               class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-slate-400 hover:text-rose-400 transition-colors">
                Logout
            </a>
        </div>
    </header>

    <main class="flex-1 max-w-5xl w-full mx-auto p-6 space-y-6">

        <?php if (!empty($message)): ?>
            <div class="p-3 rounded-lg border flex items-center justify-between <?= $messageType === 'error' ? 'bg-rose-500/10 border-rose-500/20 text-rose-300' : 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300' ?> text-xs">
                <span><?= htmlspecialchars($message) ?></span>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white">&times;</button>
            </div>
        <?php endif; ?>

        <div class="rounded-xl border border-slate-800 bg-slate-900/60 p-5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <div class="text-[11px] font-mono text-slate-400 uppercase tracking-wider">Primary Origin</div>
                <div class="text-base font-semibold text-white mt-0.5">
                    <?= $primaryOrigin ? htmlspecialchars($primaryOrigin['name']) : 'None' ?>
                    <?php if ($primaryOrigin): ?>
                        <span class="text-xs font-normal text-slate-400 font-mono ml-2">(<?= htmlspecialchars($primaryOrigin['url']) ?>)</span>
                    <?php endif; ?>
                </div>
            </div>

            <button onclick="document.getElementById('addModal').classList.remove('hidden')" 
                    class="rounded-lg bg-sky-500 hover:bg-sky-400 px-3.5 py-2 text-xs font-semibold text-slate-950 transition-colors shrink-0">
                + Add Origin
            </button>
        </div>

        <div class="rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-800 bg-slate-900/90 flex items-center justify-between">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-300">Origin Priority List</h2>
                <span class="text-[11px] text-slate-400"><?= count($origins) ?> configured</span>
            </div>

            <div class="divide-y divide-slate-800">
                <?php foreach ($origins as $idx => $orig): 
                    $isPrimary = ($idx === 0);
                ?>
                    <div class="p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4 <?= !$orig['is_active'] ? 'opacity-40' : '' ?>">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="flex flex-col gap-0.5 items-center">
                                <form method="POST">
                                    <input type="hidden" name="action" value="move_priority">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" <?= $idx === 0 ? 'disabled' : '' ?> class="p-1 text-slate-500 hover:text-slate-300 disabled:opacity-20">
                                        <i data-lucide="chevron-up" class="h-3.5 w-3.5"></i>
                                    </button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="action" value="move_priority">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" <?= $idx === count($origins)-1 ? 'disabled' : '' ?> class="p-1 text-slate-500 hover:text-slate-300 disabled:opacity-20">
                                        <i data-lucide="chevron-down" class="h-3.5 w-3.5"></i>
                                    </button>
                                </form>
                            </div>

                            <span class="font-mono text-xs text-slate-400 w-6">#<?= $idx + 1 ?></span>

                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-sm text-white"><?= htmlspecialchars($orig['name']) ?></span>
                                    <?php if ($isPrimary): ?>
                                        <span class="px-1.5 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-medium">PRIMARY</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-slate-400 font-mono mt-0.5 truncate">
                                    <?= htmlspecialchars($orig['url']) ?>
                                    <?php if (!empty($orig['host_header_override'])): ?>
                                        <span class="text-slate-500 ml-1.5">[Host: <?= htmlspecialchars($orig['host_header_override']) ?>]</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 self-end md:self-center shrink-0">
                            <span class="px-2 py-0.5 rounded text-xs font-mono bg-slate-800 text-slate-300">
                                <?= $orig['latency_ms'] ?>ms
                            </span>

                            <form method="POST">
                                <input type="hidden" name="action" value="ping_host">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                <button type="submit" class="rounded border border-slate-700 bg-slate-800 px-2 py-1 text-xs text-slate-300 hover:text-white">
                                    Test
                                </button>
                            </form>

                            <form method="POST">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                <button type="submit" class="rounded border border-slate-700 bg-slate-800 px-2 py-1 text-xs <?= $orig['is_active'] ? 'text-emerald-400' : 'text-slate-400' ?>">
                                    <?= $orig['is_active'] ? 'Enabled' : 'Disabled' ?>
                                </button>
                            </form>

                            <form method="POST" onsubmit="return confirm('Remove origin?')">
                                <input type="hidden" name="action" value="delete_origin">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                <button type="submit" <?= count($origins) <= 1 ? 'disabled' : '' ?> class="p-1.5 text-slate-500 hover:text-rose-400 disabled:opacity-20">
                                    <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <form method="POST" class="rounded-xl border border-slate-800 bg-slate-900/60 p-5 space-y-4">
                <input type="hidden" name="action" value="update_rules">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-300">Failover Rules</h3>

                <div class="space-y-2.5">
                    <label class="flex items-center gap-2.5 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" name="trigger_402" value="1" <?= !empty($rules['trigger_402']) ? 'checked' : '' ?> class="rounded border-slate-700 bg-slate-800 text-sky-500">
                        <span>Failover on HTTP 402 (Payment / Bandwidth limit)</span>
                    </label>

                    <label class="flex items-center gap-2.5 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" name="trigger_429" value="1" <?= !empty($rules['trigger_429']) ? 'checked' : '' ?> class="rounded border-slate-700 bg-slate-800 text-sky-500">
                        <span>Failover on HTTP 429 (Rate limit)</span>
                    </label>

                    <label class="flex items-center gap-2.5 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" name="trigger_5xx" value="1" <?= !empty($rules['trigger_5xx']) ? 'checked' : '' ?> class="rounded border-slate-700 bg-slate-800 text-sky-500">
                        <span>Failover on HTTP 500, 502, 503, 504 (Server crash)</span>
                    </label>

                    <div class="pt-2">
                        <div class="flex justify-between text-xs text-slate-300 mb-1">
                            <span>Timeout Threshold</span>
                            <span class="font-mono text-sky-400" id="timeoutVal"><?= $rules['latency_threshold_ms'] ?? 3000 ?>ms</span>
                        </div>
                        <input type="range" name="timeout_ms" min="500" max="6000" step="250" 
                               value="<?= $rules['latency_threshold_ms'] ?? 3000 ?>" 
                               oninput="document.getElementById('timeoutVal').innerText = this.value + 'ms'"
                               class="w-full accent-sky-400">
                    </div>
                </div>

                <button type="submit" class="rounded-lg bg-sky-500 px-3.5 py-1.5 text-xs font-semibold text-slate-950 hover:bg-sky-400 transition-colors">
                    Save Rules
                </button>
            </form>

            <div class="rounded-xl border border-slate-800 bg-slate-900/60 p-5 space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-300">System Info</h3>
                <div class="text-xs text-slate-400 space-y-1.5 font-mono">
                    <div>PHP: <?= PHP_VERSION ?></div>
                    <div>Database: SQLite (data/anyorigin.db)</div>
                    <div>Cache: Active (data/config.json)</div>
                </div>
                <p class="text-[11px] text-slate-500 leading-relaxed pt-1">
                    Incoming requests hitting <code>/</code> route through <code>index.php</code>. When an origin fails, the request is immediately retried on the next available tier.
                </p>
            </div>
        </div>

    </main>

    <div id="addModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="w-full max-w-sm rounded-xl border border-slate-800 bg-slate-900 p-5 shadow-xl">
            <h3 class="text-sm font-semibold text-white mb-3">Add Origin</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="add_origin">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Name</label>
                    <input type="text" name="name" required placeholder="e.g. Netlify Standby" 
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-white focus:border-sky-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">URL</label>
                    <input type="url" name="url" required placeholder="https://my-app.netlify.app" 
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-white focus:border-sky-500 focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Host Header (optional)</label>
                    <input type="text" name="host_header" placeholder="Default from URL" 
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-white focus:border-sky-500 focus:outline-none font-mono">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="px-3 py-1.5 text-xs text-slate-400">Cancel</button>
                    <button type="submit" class="rounded-lg bg-sky-500 px-3.5 py-1.5 text-xs font-semibold text-slate-950 hover:bg-sky-400">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="passwordModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="w-full max-w-sm rounded-xl border border-slate-800 bg-slate-900 p-5 shadow-xl">
            <h3 class="text-sm font-semibold text-white mb-3">Change Password</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="change_password">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">New Password</label>
                    <input type="password" name="new_password" required minlength="6" placeholder="Min 6 characters" 
                           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-white focus:border-sky-500 focus:outline-none">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('passwordModal').classList.add('hidden')" class="px-3 py-1.5 text-xs text-slate-400">Cancel</button>
                    <button type="submit" class="rounded-lg bg-sky-500 px-3.5 py-1.5 text-xs font-semibold text-slate-950 hover:bg-sky-400">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
