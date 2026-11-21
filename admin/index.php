<?php
require_once __DIR__ . '/bootstrap.php';
requireAuth();

$pdo = getDb();
$message = '';
$messageType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Add Origin
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
            $message = "Host '{$name}' added to failover chain!";
        }
    }

    // 2. Delete Origin
    elseif ($action === 'delete_origin') {
        $id = $_POST['id'] ?? '';
        $pdo->prepare("DELETE FROM origins WHERE id = ?")->execute([$id]);
        syncConfigCache($pdo);
        $message = "Origin removed from chain.";
    }

    // 3. Move Priority
    elseif ($action === 'move_priority') {
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
                $message = "Priority chain updated!";
            }
        }
    }

    // 4. Toggle Active
    elseif ($action === 'toggle_active') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare("SELECT is_active FROM origins WHERE id = ?");
        $stmt->execute([$id]);
        $current = (int)$stmt->fetchColumn();
        $newVal = $current ? 0 : 1;
        $pdo->prepare("UPDATE origins SET is_active = ? WHERE id = ?")->execute([$newVal, $id]);
        syncConfigCache($pdo);
        $message = $newVal ? "Host activated." : "Host paused.";
    }

    // 5. Update Failover Rules
    elseif ($action === 'update_rules') {
        $trigger_402 = !empty($_POST['trigger_402']) ? 1 : 0;
        $trigger_429 = !empty($_POST['trigger_429']) ? 1 : 0;
        $trigger_5xx = !empty($_POST['trigger_5xx']) ? 1 : 0;
        $timeout_ms = max(500, (int)($_POST['timeout_ms'] ?? 3000));
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("UPDATE failover_rules SET trigger_402 = ?, trigger_429 = ?, trigger_5xx = ?, latency_threshold_ms = ?, updated_at = ? WHERE site_id = 'default-site'");
        $stmt->execute([$trigger_402, $trigger_429, $trigger_5xx, $timeout_ms, $now]);

        syncConfigCache($pdo);
        $message = "Failover rules updated & compiled to cache!";
    }

    // 6. Ping Host
    elseif ($action === 'ping_host') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM origins WHERE id = ?");
        $stmt->execute([$id]);
        $origin = $stmt->fetch();
        if ($origin) {
            $ch = curl_init($origin['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
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

            $message = "Pinged {$origin['name']}: HTTP {$code} in {$latency}ms";
            if ($err) $message .= " ($err)";
        }
    }

    // 7. Change Password
    elseif ($action === 'change_password') {
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT OR REPLACE INTO admin_settings (key, value, updated_at) VALUES ('admin_password_hash', ?, ?)")->execute([$hash, $now]);
            $message = "Admin password updated successfully!";
        } else {
            $message = "Password must be at least 6 characters.";
            $messageType = 'error';
        }
    }
}

// Fetch view data
$origins = $pdo->query("SELECT * FROM origins ORDER BY priority ASC")->fetchAll();
$rules = $pdo->query("SELECT * FROM failover_rules LIMIT 1")->fetch() ?: [
    'trigger_402' => 1,
    'trigger_429' => 1,
    'trigger_5xx' => 1,
    'latency_threshold_ms' => 3000,
];

$primaryOrigin = $origins[0] ?? null;
$backupCount = max(0, count($origins) - 1);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AnyOrigin — Self-Hosted Traffic & Failover Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { background-color: #090d16; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #090d16; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 3px; }
    </style>
</head>
<body class="min-h-screen text-slate-100 flex flex-col">

    <!-- Top Navbar -->
    <header class="border-b border-slate-800/80 bg-[#0c1220]/90 backdrop-blur-md sticky top-0 z-40 px-6 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="h-9 w-9 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center shadow-md shadow-sky-500/20">
                <i data-lucide="shield" class="h-5 w-5 text-white"></i>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <span class="font-bold text-base text-white tracking-tight">AnyOrigin</span>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-semibold">
                        FAST-PATH ACTIVE
                    </span>
                </div>
                <p class="text-[11px] text-slate-400">Zero-Dependency Multi-Cloud Failover</p>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="document.getElementById('passwordModal').classList.remove('hidden')" 
                    class="flex items-center gap-1.5 rounded-lg border border-slate-800 bg-slate-900 px-3 py-1.5 text-xs text-slate-300 hover:text-white hover:bg-slate-800 transition-all">
                <i data-lucide="key" class="h-3.5 w-3.5 text-slate-400"></i>
                <span>Password</span>
            </button>
            <a href="login.php?logout=1" 
               class="rounded-lg border border-slate-800 bg-slate-900 p-2 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-all" title="Logout">
                <i data-lucide="log-out" class="h-4 w-4"></i>
            </a>
        </div>
    </header>

    <!-- Main Container -->
    <main class="flex-1 max-w-6xl w-full mx-auto p-6 space-y-6">

        <?php if (!empty($message)): ?>
            <div class="p-3.5 rounded-xl border flex items-center justify-between <?= $messageType === 'error' ? 'bg-rose-500/10 border-rose-500/30 text-rose-300' : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' ?> text-xs">
                <div class="flex items-center gap-2">
                    <i data-lucide="<?= $messageType === 'error' ? 'alert-triangle' : 'check-circle' ?>" class="h-4 w-4"></i>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white">✕</button>
            </div>
        <?php endif; ?>

        <!-- Beginner-Friendly Status Banner -->
        <div class="rounded-2xl border border-slate-800 bg-gradient-to-r from-[#0f172a] via-[#111c35] to-[#0f172a] p-6 shadow-xl flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="h-12 w-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0">
                    <i data-lucide="activity" class="h-6 w-6 text-emerald-400 animate-pulse"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-emerald-400 uppercase tracking-wider font-mono">100% Protection Status</span>
                    </div>
                    <h2 class="text-lg font-bold text-white mt-0.5">
                        Routing via: <?= $primaryOrigin ? htmlspecialchars($primaryOrigin['name']) : 'None' ?>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        If limits (402/429) or crashes occur, traffic instantly fails over to <?= $backupCount ?> standby <?= $backupCount === 1 ? 'server' : 'servers' ?>.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <div class="text-right hidden sm:block mr-2">
                    <div class="text-xs font-semibold text-slate-200">Ingress Engine</div>
                    <div class="text-[11px] font-mono text-emerald-400">&lt; 1ms Fast-Path</div>
                </div>
                <button onclick="document.getElementById('addModal').classList.remove('hidden')" 
                        class="flex items-center gap-1.5 rounded-xl bg-sky-500 hover:bg-sky-400 px-4 py-2.5 text-xs font-semibold text-slate-950 transition-all shadow-md shadow-sky-500/20">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    <span>Add Host</span>
                </button>
            </div>
        </div>

        <!-- Upstream Chain Card -->
        <div class="rounded-2xl border border-slate-800 bg-[#0f172a] overflow-hidden shadow-xl">
            <div class="p-5 border-b border-slate-800/80 bg-slate-900/40 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-sm text-white flex items-center gap-2">
                        <i data-lucide="server" class="h-4 w-4 text-sky-400"></i>
                        Upstream Hosting Chain (Priority Order)
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Use arrows to reorder. Top server gets primary traffic. Lower servers take over when upper ones hit limits.
                    </p>
                </div>
            </div>

            <div class="divide-y divide-slate-800/60">
                <?php foreach ($origins as $idx => $orig): 
                    $isPrimary = ($idx === 0);
                ?>
                    <div class="p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4 <?= !$orig['is_active'] ? 'opacity-50 bg-slate-950/20' : 'hover:bg-slate-800/20' ?> transition-colors">
                        <div class="flex items-center gap-3 min-w-0">
                            <!-- Priority controls -->
                            <div class="flex flex-col gap-0.5 items-center">
                                <form method="POST">
                                    <input type="hidden" name="action" value="move_priority">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" <?= $idx === 0 ? 'disabled' : '' ?> class="p-1 text-slate-500 hover:text-sky-400 disabled:opacity-20">
                                        <i data-lucide="chevron-up" class="h-4 w-4"></i>
                                    </button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="action" value="move_priority">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" <?= $idx === count($origins)-1 ? 'disabled' : '' ?> class="p-1 text-slate-500 hover:text-sky-400 disabled:opacity-20">
                                        <i data-lucide="chevron-down" class="h-4 w-4"></i>
                                    </button>
                                </form>
                            </div>

                            <!-- Priority badge -->
                            <div class="h-9 w-9 rounded-xl flex items-center justify-center font-mono font-bold text-xs shrink-0 <?= $isPrimary ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : ($idx === 1 ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'bg-slate-800 text-slate-400 border border-slate-700') ?>">
                                #<?= $idx + 1 ?>
                            </div>

                            <!-- Host details -->
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-sm text-white"><?= htmlspecialchars($orig['name']) ?></span>
                                    <?php if ($isPrimary): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                            PRIMARY
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                            STANDBY
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-slate-400 font-mono mt-0.5 truncate flex items-center gap-2">
                                    <span class="text-slate-300"><?= htmlspecialchars($orig['url']) ?></span>
                                    <?php if (!empty($orig['host_header_override'])): ?>
                                        <span class="text-slate-500 text-[11px]">[Host: <?= htmlspecialchars($orig['host_header_override']) ?>]</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right buttons -->
                        <div class="flex items-center gap-2.5 self-end md:self-center shrink-0">
                            <!-- Latency badge -->
                            <span class="px-2.5 py-1 rounded-lg text-xs font-mono border border-slate-800 bg-slate-900 <?= $orig['status'] === 'healthy' ? 'text-emerald-400' : 'text-rose-400' ?>">
                                <?= $orig['latency_ms'] ?>ms
                            </span>

                            <!-- Ping Test -->
                            <form method="POST">
                                <input type="hidden" name="action" value="ping_host">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                <button type="submit" class="flex items-center gap-1 rounded-lg border border-slate-700 bg-slate-800 px-2.5 py-1 text-xs text-slate-300 hover:text-white transition-colors">
                                    <i data-lucide="refresh-cw" class="h-3 w-3"></i>
                                    <span>Ping</span>
                                </button>
                            </form>

                            <!-- Toggle Active -->
                            <form method="POST">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                <button type="submit" class="text-xs px-2.5 py-1 rounded-lg border transition-colors <?= $orig['is_active'] ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-slate-700 bg-slate-800 text-slate-400' ?>">
                                    <?= $orig['is_active'] ? 'Active' : 'Enable' ?>
                                </button>
                            </form>

                            <!-- Delete -->
                            <form method="POST" onsubmit="return confirm('Remove this host?')">
                                <input type="hidden" name="action" value="delete_origin">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($orig['id']) ?>">
                                <button type="submit" <?= count($origins) <= 1 ? 'disabled' : '' ?> class="p-1.5 text-slate-500 hover:text-rose-400 disabled:opacity-20 transition-colors">
                                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Failover Rules & Settings -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Rules Form -->
            <form method="POST" class="rounded-2xl border border-slate-800 bg-[#0f172a] p-5 flex flex-col justify-between shadow-xl">
                <input type="hidden" name="action" value="update_rules">
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <i data-lucide="sliders" class="h-4 w-4 text-sky-400"></i>
                        <h3 class="font-bold text-sm text-white">Smart Failover Triggers</h3>
                    </div>
                    <p class="text-xs text-slate-400 mb-4">
                        Choose conditions that trigger an automatic, sub-second switch to standby hosts.
                    </p>

                    <div class="space-y-3">
                        <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-800 bg-slate-900/60 cursor-pointer hover:border-slate-700">
                            <input type="checkbox" name="trigger_402" value="1" <?= !empty($rules['trigger_402']) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded accent-sky-500">
                            <div>
                                <span class="text-xs font-semibold text-slate-200">HTTP 402 (Payment / Cloud Limits)</span>
                                <p class="text-[11px] text-slate-400 mt-0.5">Fails over when Vercel, Netlify, or cloud free tier limits are exhausted.</p>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-800 bg-slate-900/60 cursor-pointer hover:border-slate-700">
                            <input type="checkbox" name="trigger_429" value="1" <?= !empty($rules['trigger_429']) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded accent-sky-500">
                            <div>
                                <span class="text-xs font-semibold text-slate-200">HTTP 429 (Too Many Requests / Rate Limits)</span>
                                <p class="text-[11px] text-slate-400 mt-0.5">Evacuates visitors to backup servers when the primary origin enforces rate limiting.</p>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-800 bg-slate-900/60 cursor-pointer hover:border-slate-700">
                            <input type="checkbox" name="trigger_5xx" value="1" <?= !empty($rules['trigger_5xx']) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded accent-sky-500">
                            <div>
                                <span class="text-xs font-semibold text-slate-200">HTTP 500, 502, 503, 504 (Server Crashes)</span>
                                <p class="text-[11px] text-slate-400 mt-0.5">Intercepts server crashes and routes user requests to healthy backups.</p>
                            </div>
                        </label>

                        <div class="p-3 rounded-xl border border-slate-800 bg-slate-900/60">
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-xs font-semibold text-slate-200">Latency Timeout Threshold</span>
                                <span class="text-xs font-mono text-sky-400" id="timeoutLabel"><?= $rules['latency_threshold_ms'] ?? 3000 ?>ms</span>
                            </div>
                            <input type="range" name="timeout_ms" min="500" max="8000" step="250" 
                                   value="<?= $rules['latency_threshold_ms'] ?? 3000 ?>" 
                                   oninput="document.getElementById('timeoutLabel').innerText = this.value + 'ms'"
                                   class="w-full accent-sky-400 cursor-pointer">
                        </div>
                    </div>
                </div>

                <div class="pt-4 mt-4 border-t border-slate-800/80 flex justify-end">
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-sky-500 to-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-md shadow-sky-500/20 hover:from-sky-400 hover:to-indigo-500 transition-all">
                        Save Failover Policy
                    </button>
                </div>
            </form>

            <!-- Deployment & Architecture Guide -->
            <div class="rounded-2xl border border-slate-800 bg-[#0f172a] p-5 flex flex-col justify-between shadow-xl">
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <i data-lucide="zap" class="h-4 w-4 text-amber-400"></i>
                        <h3 class="font-bold text-sm text-white">cPanel Deployment Status</h3>
                    </div>
                    <p class="text-xs text-slate-400 mb-4">
                        AnyOrigin is running locally on your server. No Cloudflare worker is required.
                    </p>

                    <div class="space-y-2.5 text-xs">
                        <div class="p-3 rounded-xl border border-slate-800/80 bg-slate-900/40 flex items-start gap-3">
                            <i data-lucide="check" class="h-4 w-4 text-emerald-400 mt-0.5 shrink-0"></i>
                            <div>
                                <span class="font-semibold text-slate-200">100k+ Req/Day Fast Path Ready</span>
                                <p class="text-[11px] text-slate-400">Public visitors hitting your domain are routed via <code class="text-sky-300">index.php</code> with microsecond latency.</p>
                            </div>
                        </div>

                        <div class="p-3 rounded-xl border border-slate-800/80 bg-slate-900/40 flex items-start gap-3">
                            <i data-lucide="check" class="h-4 w-4 text-emerald-400 mt-0.5 shrink-0"></i>
                            <div>
                                <span class="font-semibold text-slate-200">Zero Database Setup Needed</span>
                                <p class="text-[11px] text-slate-400">Settings are auto-saved to <code class="text-sky-300">data/anyorigin.db</code> and cached to <code class="text-sky-300">data/config.json</code>.</p>
                            </div>
                        </div>

                        <div class="p-3 rounded-xl border border-slate-800/80 bg-slate-900/40 flex items-start gap-3">
                            <i data-lucide="check" class="h-4 w-4 text-emerald-400 mt-0.5 shrink-0"></i>
                            <div>
                                <span class="font-semibold text-slate-200">Host & Redirect Rewriter Active</span>
                                <p class="text-[11px] text-slate-400">Visitors remain on your domain even when failover hops to Netlify or VPS.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-4 mt-4 border-t border-slate-800/80 flex items-center justify-between text-xs text-slate-400">
                    <span>cPanel / Apache / Nginx Drop-in</span>
                    <span class="text-emerald-400 font-mono">PHP <?= PHP_VERSION ?></span>
                </div>
            </div>
        </div>

    </main>

    <!-- Modal: Add Host -->
    <div id="addModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
        <div class="w-full max-w-md rounded-2xl border border-slate-800 bg-[#0f172a] p-6 shadow-2xl">
            <h3 class="text-base font-bold text-white mb-1">Add Upstream Host</h3>
            <p class="text-xs text-slate-400 mb-4">Add your Vercel, Netlify, Render, or VPS URL to the failover chain.</p>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_origin">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Host Name</label>
                    <input type="text" name="name" required placeholder="e.g. Netlify Standby" 
                           class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3.5 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Target URL</label>
                    <input type="url" name="url" required placeholder="https://my-app.netlify.app" 
                           class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3.5 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Host Header Override (Optional)</label>
                    <input type="text" name="host_header" placeholder="Auto-detected from URL" 
                           class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3.5 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none font-mono">
                    <p class="text-[11px] text-slate-500 mt-1">Leave empty to use the hostname from the Target URL.</p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
                    <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold text-slate-400 hover:text-white">Cancel</button>
                    <button type="submit" class="rounded-xl bg-sky-500 px-4 py-2 text-xs font-semibold text-slate-950 hover:bg-sky-400 shadow-md shadow-sky-500/20">Add Host</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Change Password -->
    <div id="passwordModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
        <div class="w-full max-w-sm rounded-2xl border border-slate-800 bg-[#0f172a] p-6 shadow-2xl">
            <h3 class="text-base font-bold text-white mb-1">Change Admin Password</h3>
            <p class="text-xs text-slate-400 mb-4">Set a strong password for your AnyOrigin dashboard.</p>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="change_password">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">New Password</label>
                    <input type="password" name="new_password" required minlength="6" placeholder="Min 6 characters" 
                           class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3.5 py-2 text-sm text-white focus:border-sky-500 focus:outline-none font-mono">
                </div>
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
                    <button type="button" onclick="document.getElementById('passwordModal').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold text-slate-400 hover:text-white">Cancel</button>
                    <button type="submit" class="rounded-xl bg-sky-500 px-4 py-2 text-xs font-semibold text-slate-950 hover:bg-sky-400">Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
