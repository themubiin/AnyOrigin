<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
requireAuth();

$pdo = getDb();

// Handle AJAX & API actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput) && isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $_POST = array_merge($_POST, $json);
        }
    }

    $action = $_POST['action'] ?? '';
    $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

    $respond = function(bool $success, string $message, array $extra = []) use ($isAjax) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
            exit;
        }
        header('Location: /anyorigin/index.php');
        exit;
    };

    if ($action === 'add_origin') {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $host_header = trim($_POST['host_header'] ?? '');

        $validation = validateOriginUrl($url);
        if (!$validation['valid']) {
            $respond(false, $validation['error']);
        }

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
        $respond(true, "Origin added successfully", ['id' => $id]);
    }

    if ($action === 'delete_origin') {
        $id = $_POST['id'] ?? '';
        $pdo->prepare("DELETE FROM origins WHERE id = ?")->execute([$id]);
        syncConfigCache($pdo);
        $respond(true, "Origin removed");
    }

    if ($action === 'move_priority') {
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
                $respond(true, "Priority updated");
            }
        }
        $respond(false, "Cannot reorder further");
    }

    if ($action === 'toggle_active') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare("SELECT is_active FROM origins WHERE id = ?");
        $stmt->execute([$id]);
        $origin = $stmt->fetch();
        if ($origin) {
            $newActive = $origin['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE origins SET is_active = ?, updated_at = ? WHERE id = ?")->execute([$newActive, date('Y-m-d H:i:s'), $id]);
            syncConfigCache($pdo);
            $respond(true, $newActive ? "Origin enabled" : "Origin disabled", ['active' => $newActive]);
        }
        $respond(false, "Origin not found");
    }

    if ($action === 'update_rules') {
        $trigger_402 = !empty($_POST['trigger_402']) ? 1 : 0;
        $trigger_429 = !empty($_POST['trigger_429']) ? 1 : 0;
        $trigger_5xx = !empty($_POST['trigger_5xx']) ? 1 : 0;
        $timeout = max(100, min(30000, (int)($_POST['timeout_ms'] ?? 2500)));
        $max_retries = max(1, min(5, (int)($_POST['max_retries'] ?? 2)));
        $now = date('Y-m-d H:i:s');

        $pdo->prepare("UPDATE failover_rules SET trigger_402 = ?, trigger_429 = ?, trigger_5xx = ?, latency_threshold_ms = ?, max_retries = ?, updated_at = ? WHERE site_id = 'default-site'")
            ->execute([$trigger_402, $trigger_429, $trigger_5xx, $timeout, $max_retries, $now]);

        if (isset($_POST['routing_strategy'])) {
            setAdminSetting($pdo, 'routing_strategy', in_array($_POST['routing_strategy'], ['priority', 'round_robin']) ? $_POST['routing_strategy'] : 'priority');
        }
        if (isset($_POST['safe_post_only'])) {
            setAdminSetting($pdo, 'safe_post_only', !empty($_POST['safe_post_only']) ? '1' : '0');
        }
        if (isset($_POST['forward_client_ip'])) {
            setAdminSetting($pdo, 'forward_client_ip', !empty($_POST['forward_client_ip']) ? '1' : '0');
        }
        if (isset($_POST['rewrite_locations'])) {
            setAdminSetting($pdo, 'rewrite_locations', !empty($_POST['rewrite_locations']) ? '1' : '0');
        }
        if (isset($_POST['rewrite_cookies'])) {
            setAdminSetting($pdo, 'rewrite_cookies', !empty($_POST['rewrite_cookies']) ? '1' : '0');
        }
        if (isset($_POST['health_check_interval'])) {
            setAdminSetting($pdo, 'health_check_interval', in_array($_POST['health_check_interval'], ['10s', '30s', '1m', '5m']) ? $_POST['health_check_interval'] : '1m');
        }

        syncConfigCache($pdo);
        $respond(true, "All settings & rules saved successfully");
    }

    if ($action === 'purge_cache') {
        syncConfigCache($pdo);
        $respond(true, "Config cache purged and recompiled (<1ms)");
    }

    if ($action === 'reset_lockouts') {
        $pdo->exec("DELETE FROM login_attempts");
        $respond(true, "Login brute-force lockouts cleared");
    }

    if ($action === 'clear_incidents') {
        $pdo->exec("DELETE FROM incidents");
        $respond(true, "Incident log cleared");
    }

    if ($action === 'ping_host') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM origins WHERE id = ?");
        $stmt->execute([$id]);
        $origin = $stmt->fetch();
        if ($origin) {
            $ch = curl_init($origin['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_NOBODY => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
            ]);
            if (!empty($origin['host_header_override'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Host: " . $origin['host_header_override']]);
            }
            $start = microtime(true);
            curl_exec($ch);
            $latency = round((microtime(true) - $start) * 1000);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $now = date('Y-m-d H:i:s');
            if ($err || $code === 0) {
                $status = 'down';
                $pdo->prepare("UPDATE origins SET latency_ms = 0, status = 'down', last_checked_at = ? WHERE id = ?")->execute([$now, $id]);
                syncConfigCache($pdo);

                $reason = 'Unreachable';
                if ($err) {
                    if (str_contains($err, 'Could not resolve host') || str_contains($err, 'Couldn\'t resolve host')) {
                        $reason = 'DNS failed (Domain does not exist)';
                    } elseif (str_contains($err, 'Connection timed out') || str_contains($err, 'timed out')) {
                        $reason = 'Connection timed out';
                    } elseif (str_contains($err, 'Failed to connect')) {
                        $reason = 'Connection refused / Port closed';
                    } elseif (str_contains($err, 'SSL') || str_contains($err, 'certificate')) {
                        $reason = 'SSL Handshake failed';
                    }
                }
                $respond(false, "{$origin['name']}: {$reason} (HTTP 0)", ['latency' => 0, 'status' => 'down', 'code' => 0]);
            } else {
                $isHealthy = ($code >= 200 && $code < 400);
                $status = $isHealthy ? 'healthy' : 'down';
                $storedLatency = $isHealthy ? $latency : 0;
                $pdo->prepare("UPDATE origins SET latency_ms = ?, status = ?, last_checked_at = ? WHERE id = ?")->execute([$storedLatency, $status, $now, $id]);
                syncConfigCache($pdo);

                $msg = $isHealthy 
                    ? "{$origin['name']}: Online · HTTP {$code} ({$latency}ms)"
                    : "{$origin['name']}: HTTP {$code} ({$latency}ms)";
                $respond($isHealthy, $msg, ['latency' => $latency, 'status' => $status, 'code' => $code]);
            }
        }
        $respond(false, "Origin not found");
    }

    if ($action === 'change_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        $existingHash = $pdo->query("SELECT value FROM admin_settings WHERE key = 'admin_password_hash'")->fetchColumn();

        if (!$existingHash || !password_verify($currentPass, $existingHash)) {
            $respond(false, "Current password is incorrect");
        } elseif (strlen($newPass) < 8) {
            $respond(false, "New password must be at least 8 characters");
        } elseif ($newPass !== $confirmPass) {
            $respond(false, "New passwords do not match");
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE admin_settings SET value = ?, updated_at = ? WHERE key = 'admin_password_hash'")
                ->execute([$newHash, $now]);

            session_regenerate_id(true);
            $respond(true, "Password updated successfully");
        }
    }
}

// Fetch active data for UI render
$origins = $pdo->query("SELECT * FROM origins ORDER BY priority ASC")->fetchAll();
$rules = $pdo->query("SELECT * FROM failover_rules LIMIT 1")->fetch() ?: [
    'trigger_402' => 1,
    'trigger_429' => 1,
    'trigger_5xx' => 1,
    'latency_threshold_ms' => 2500,
    'max_retries' => 2,
];

$routing_strategy = getAdminSetting($pdo, 'routing_strategy', 'priority');
$safe_post_only = getAdminSetting($pdo, 'safe_post_only', '1') === '1';
$forward_client_ip = getAdminSetting($pdo, 'forward_client_ip', '1') === '1';
$rewrite_locations = getAdminSetting($pdo, 'rewrite_locations', '1') === '1';
$rewrite_cookies = getAdminSetting($pdo, 'rewrite_cookies', '1') === '1';
$health_check_interval = getAdminSetting($pdo, 'health_check_interval', '1m');

$palette = ['#0284c7', '#10b981', '#6366f1', '#f59e0b', '#f43f5e', '#8b5cf6'];

$originsJson = array_map(function($o, $idx) use ($palette) {
    return [
        'id' => $o['id'],
        'n' => $o['name'],
        'u' => $o['url'],
        'h' => $o['host_header_override'] ?? '',
        'ms' => (int)($o['latency_ms'] ?? 0),
        'ok' => ($o['status'] === 'healthy') ? 1 : 0,
        'on' => (int)$o['is_active'],
        'c' => $palette[$idx % count($palette)],
    ];
}, $origins, array_keys($origins));

// Fetch real incident logs
$incidents = $pdo->query("SELECT * FROM incidents ORDER BY created_at DESC LIMIT 50")->fetchAll();
$incidentsJson = array_map(function($inc) {
    return [
        'id' => $inc['id'],
        'from' => $inc['from_origin'],
        'to' => $inc['to_origin'],
        'code' => $inc['status_code'] > 0 ? (string)$inc['status_code'] : 'Timeout / Err',
        'time' => date('M j, H:i', strtotime($inc['created_at'])),
        'uri' => $inc['uri'] ?? '/',
        'badge' => ($inc['status_code'] >= 500 || $inc['status_code'] === 0) ? 'er' : 'wa'
    ];
}, $incidents);

$timeout_ms = (int)($rules['latency_threshold_ms'] ?? 2500);
$max_retries = (int)($rules['max_retries'] ?? 2);
$trigger_402 = !empty($rules['trigger_402']);
$trigger_429 = !empty($rules['trigger_429']);
$trigger_5xx = !empty($rules['trigger_5xx']);

$usingDefaultPwd = isUsingDefaultPassword();
$dbFile = DATA_DIR . '/anyorigin.db';
$dbSize = file_exists($dbFile) ? round(filesize($dbFile) / 1024, 1) . ' KB' : 'N/A';
$configFile = CONFIG_PATH;
$configSize = file_exists($configFile) ? round(filesize($configFile) / 1024, 1) . ' KB' : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>AnyOrigin - Edge Failover Console</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;750;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f8fafc;--sf:#ffffff;--s2:#f1f5f9;--bd:#e2e8f0;--bd-h:#cbd5e1;
  --tx:#0f172a;--mu:#64748b;--ac:#0284c7;--ac-glow:rgba(2,132,199,0.15);
  --ax:#ffffff;--as:#e0f2fe;--ok:#10b981;--ok-glow:rgba(16,185,129,0.25);
  --os:#ecfdf5;--er:#f43f5e;--er-glow:rgba(244,63,94,0.25);--es:#fff1f2;
  --wa:#f59e0b;--ws:#fef3c7;
  --sh:0 1px 3px 0 rgba(0,0,0,0.04),0 1px 2px -1px rgba(0,0,0,0.04);
  --sh-card:0 4px 6px -1px rgba(0,0,0,0.03),0 2px 4px -2px rgba(0,0,0,0.03);
  --sh-hover:0 10px 25px -5px rgba(0,0,0,0.06),0 8px 10px -6px rgba(0,0,0,0.04);
  --gr:#e2e8f0;
}
[data-theme=dark]{
  --bg:#090d16;--sf:#0f172a;--s2:#1e293b;--bd:#1e293b;--bd-h:#334155;
  --tx:#f8fafc;--mu:#94a3b8;--ac:#38bdf8;--ac-glow:rgba(56,189,248,0.18);
  --ax:#090d16;--as:#0c2d48;--ok:#34d399;--ok-glow:rgba(52,211,153,0.3);
  --os:#063c2c;--er:#fb7185;--er-glow:rgba(251,113,133,0.3);--es:#3f121d;
  --wa:#fbbf24;--ws:#3d2b07;
  --sh:0 1px 3px 0 rgba(0,0,0,0.4),0 1px 2px -1px rgba(0,0,0,0.4);
  --sh-card:0 4px 6px -1px rgba(0,0,0,0.3),0 2px 4px -2px rgba(0,0,0,0.3);
  --sh-hover:0 12px 28px -6px rgba(0,0,0,0.5);
  --gr:#1e293b;
}
@media(prefers-color-scheme:dark){
  :root:not([data-theme=light]){
    --bg:#090d16;--sf:#0f172a;--s2:#1e293b;--bd:#1e293b;--bd-h:#334155;
    --tx:#f8fafc;--mu:#94a3b8;--ac:#38bdf8;--ac-glow:rgba(56,189,248,0.18);
    --ax:#090d16;--as:#0c2d48;--ok:#34d399;--ok-glow:rgba(52,211,153,0.3);
    --os:#063c2c;--er:#fb7185;--er-glow:rgba(251,113,133,0.3);--es:#3f121d;
    --wa:#fbbf24;--ws:#3d2b07;
    --sh:0 1px 3px 0 rgba(0,0,0,0.4),0 1px 2px -1px rgba(0,0,0,0.4);
    --sh-card:0 4px 6px -1px rgba(0,0,0,0.3),0 2px 4px -2px rgba(0,0,0,0.3);
    --sh-hover:0 12px 28px -6px rgba(0,0,0,0.5);
    --gr:#1e293b;
  }
}
*{box-sizing:border-box;margin:0}
body{
  background:var(--bg);color:var(--tx);
  font:14px/1.5 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  -webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;
  padding-bottom:env(safe-area-inset-bottom);
}
svg.i{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
button{font:inherit;color:inherit;cursor:pointer;border:0;background:none}
.mono{font-family:"JetBrains Mono",ui-monospace,SFMono-Regular,Menlo,monospace}.num{font-variant-numeric:tabular-nums}

/* App Layout & Sleek Sidebar */
.app-layout{display:flex;min-height:100vh}
.sidebar{
  width:246px;background:var(--sf);border-right:1px solid var(--bd);
  display:flex;flex-direction:column;position:sticky;top:0;height:100vh;
  padding:20px 16px;flex-shrink:0;z-index:40;transition:transform .25s ease;
}
.side-top{display:flex;align-items:center;gap:12px;padding-bottom:18px;border-bottom:1px solid var(--bd);margin-bottom:14px}
.logo{
  width:36px;height:36px;border-radius:11px;
  background:linear-gradient(135deg,#0284c7 0%,#6366f1 100%);
  display:grid;place-items:center;color:#fff;
  box-shadow:0 3px 10px rgba(2,132,199,0.3);
}
.brand{font-weight:750;font-size:16px;letter-spacing:-.3px}
.side-sub{font-size:11px;color:var(--mu);font-weight:600;letter-spacing:.3px;text-transform:uppercase}
.side-close{display:none;margin-left:auto}
.side-nav{display:flex;flex-direction:column;gap:5px;flex:1}
.nav-item{
  display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;
  font-weight:600;font-size:13.5px;color:var(--mu);text-decoration:none;
  transition:all .15s ease;text-align:left;width:100%;
}
.nav-item:hover{color:var(--tx);background:var(--s2)}
.nav-item.active{color:var(--ac);background:var(--as);font-weight:650}
.nav-badge{
  margin-left:auto;font-size:11px;font-weight:700;padding:2px 7px;
  border-radius:99px;background:var(--s2);color:var(--mu);
}
.nav-item.active .nav-badge{background:var(--ac);color:#fff}
.side-footer{border-top:1px solid var(--bd);padding-top:14px;margin-top:auto;display:grid;gap:10px}
.side-status{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;color:var(--ok)}
.live-dot{
  width:7px;height:7px;border-radius:50%;background:var(--ok);
  box-shadow:0 0 0 2px var(--os),0 0 8px var(--ok-glow);
  animation:lp 2s infinite;
}
@keyframes lp{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.2)}}
.side-user{display:flex;align-items:center;gap:8px}

.main-wrapper{flex:1;min-width:0;display:flex;flex-direction:column;background:var(--bg)}
.mobile-header{display:none;align-items:center;gap:10px;padding:12px 18px;background:var(--sf);border-bottom:1px solid var(--bd);position:sticky;top:0;z-index:30}
.side-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);backdrop-filter:blur(3px);z-index:35}

.content{max-width:1120px;width:100%;margin:0 auto;padding:24px 28px;display:grid;gap:20px}
.view-pane{display:grid;gap:20px}

.card{
  background:var(--sf);border:1px solid var(--bd);border-radius:16px;
  box-shadow:var(--sh-card);transition:box-shadow .2s ease,border-color .2s ease;
}
.card:hover{border-color:var(--bd-h)}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:7px;
  height:38px;padding:0 15px;border-radius:10px;font-weight:650;font-size:13px;
  background:var(--ac);color:var(--ax);transition:all .15s ease;
  box-shadow:0 1px 2px rgba(2,132,199,0.2);
}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px)}
.btn:active{transform:translateY(0)}
.btn.g{background:var(--s2);color:var(--tx);border:1px solid var(--bd);box-shadow:none}
.btn.g:hover{background:var(--sf);border-color:var(--mu)}
.btn.sm{height:30px;padding:0 10px;font-size:12px;border-radius:8px}

.seg{display:inline-flex;padding:3px;background:var(--s2);border:1px solid var(--bd);border-radius:10px;gap:2px}
.seg button{padding:5px 12px;border-radius:7px;font-weight:650;font-size:12px;color:var(--mu);transition:all .15s ease}
.seg button:hover{color:var(--tx)}
.seg button.a{background:var(--sf);color:var(--tx);box-shadow:0 1px 3px rgba(0,0,0,.08)}

.lbl{font-size:11.5px;color:var(--mu);font-weight:650;letter-spacing:.3px;text-transform:uppercase}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.top h2{font-size:20px;font-weight:750;letter-spacing:-.4px}

.hero{padding:18px 22px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.pulse{
  width:44px;height:44px;border-radius:13px;background:var(--os);
  color:var(--ok);display:grid;place-items:center;position:relative;flex-shrink:0;
}
.pulse:after{content:"";position:absolute;inset:0;border-radius:inherit;border:2px solid var(--ok);opacity:.5;animation:p 2s infinite}
.pulse.down{background:var(--es);color:var(--er)}
.pulse.down:after{border-color:var(--er)}
@keyframes p{to{transform:scale(1.35);opacity:0}}
.big{font-size:20px;font-weight:750;letter-spacing:-.4px;margin-top:2px}
.url{font-size:12.5px;color:var(--mu)}

.kp{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.k{padding:16px 18px;display:grid;gap:3px;position:relative}
.k b{font-size:26px;letter-spacing:-.8px;line-height:1.15;font-weight:750}
.k small{font-size:12px;font-weight:600;margin-top:2px}
.up{color:var(--ok)}.dn{color:var(--er)}.mu{color:var(--mu)}

.two{display:grid;grid-template-columns:1.4fr 1fr;gap:16px}
.pad{padding:20px}
.hd{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.hd h2{font-size:15px;font-weight:700;letter-spacing:-.2px}

.tr{height:6px;background:var(--s2);border-radius:9px;overflow:hidden;border:1px solid var(--bd)}
.tr i{display:block;height:100%;border-radius:9px;transition:width .3s ease}

.list{display:grid;gap:10px}
.o{
  padding:14px 18px;display:flex;align-items:center;gap:14px;
  flex-wrap:wrap;transition:all .15s ease;
}
.o.off{opacity:.5}
.rank{
  width:30px;height:30px;border-radius:9px;background:var(--s2);
  border:1px solid var(--bd);display:grid;place-items:center;
  font-weight:700;font-size:12px;color:var(--mu);flex-shrink:0;
}
.o:first-child .rank{
  background:linear-gradient(135deg,#0284c7 0%,#6366f1 100%);
  color:#fff;border-color:transparent;box-shadow:0 2px 8px rgba(2,132,199,0.35);
}
.om{flex:1;min-width:200px}.on{display:flex;align-items:center;gap:8px;font-weight:650;flex-wrap:wrap}
.tag{font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:var(--as);color:var(--ac)}
.dot{
  width:8px;height:8px;border-radius:50%;background:var(--ok);
  box-shadow:0 0 0 2px var(--os),0 0 8px var(--ok-glow);display:inline-block;flex-shrink:0;
}
.dot.d{background:var(--er);box-shadow:0 0 0 2px var(--es),0 0 8px var(--er-glow)}
.ou{font-size:12px;color:var(--mu);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:44vw}
.met{display:grid;text-align:right;min-width:80px}.met b{font-size:13.5px}.met span{font-size:11px;color:var(--mu)}
.oa{display:flex;align-items:center;gap:7px;margin-left:auto}

.ms{
  font-size:12px;font-weight:700;padding:4px 10px;border-radius:8px;
  background:var(--s2);border:1px solid var(--bd);min-width:64px;text-align:center;
}
.ms.healthy{background:var(--os);color:var(--ok);border-color:rgba(16,185,129,0.25)}
.ms.down{background:var(--es);color:var(--er);border-color:rgba(244,63,94,0.25)}

.mv{display:grid;gap:2px}.mv button{width:26px;height:16px;display:grid;place-items:center;color:var(--mu);border-radius:5px;transition:.15s}
.mv button:hover{background:var(--s2);color:var(--tx)}
.mv svg{width:13px;height:13px}

.ib{
  width:36px;height:36px;border-radius:10px;border:1px solid var(--bd);
  background:var(--sf);display:grid;place-items:center;color:var(--mu);
  transition:all .15s ease;
}
.ib:hover{color:var(--tx);border-color:var(--mu);background:var(--s2)}
.ib.sm{width:32px;height:32px;border-radius:8px}
.ib.sm svg{width:15px;height:15px}
.del:hover{color:var(--er);border-color:var(--er);background:var(--es)}

.sw{width:40px;height:22px;border-radius:99px;background:var(--bd);position:relative;transition:.2s;flex:none}
.sw:after{content:"";position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.2s}
.sw.on{background:var(--ok)}.sw.on:after{left:20px}

.spinning svg{animation:spin 0.8s linear infinite}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

.ev{display:grid}.e{display:flex;align-items:center;gap:12px;padding:12px 0;border-top:1px solid var(--bd);flex-wrap:wrap}.e:first-child{border-top:0}
.ei{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex:none}
.chip{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:99px}

.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.rr{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid var(--bd)}
.rr span:first-child{flex:1;font-weight:600;display:flex;align-items:center;gap:6px}
.rr small{color:var(--mu);font-weight:500;margin-left:4px}
.rg{padding:14px 0 4px}.rg div{display:flex;justify-content:space-between;align-items:center;font-weight:600;margin-bottom:10px}
input[type=range]{width:100%;accent-color:var(--ac);height:6px}

.i-btn{
  display:inline-grid;place-items:center;width:16px;height:16px;border-radius:50%;
  background:var(--s2);color:var(--mu);font-size:10.5px;font-weight:750;
  border:1px solid var(--bd);cursor:pointer;flex-shrink:0;transition:all .15s;
}
.i-btn:hover{background:var(--ac);color:#fff;border-color:transparent}

.global-tooltip{
  position:fixed;z-index:9999;background:var(--tx);color:var(--bg);padding:7px 11px;border-radius:9px;
  font-size:12px;font-weight:500;line-height:1.35;max-width:270px;pointer-events:none;opacity:0;
  transform:translateY(4px);transition:opacity .15s,transform .15s;box-shadow:0 8px 24px rgba(0,0,0,.25);
}
.global-tooltip.show{opacity:1;transform:translateY(0)}

.alert-strip{
  padding:12px 18px;background:var(--ws);border:1px solid rgba(245,158,11,0.35);border-radius:14px;
  color:var(--tx);display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:13px;
  box-shadow:0 1px 3px rgba(245,158,11,0.06);
}
.env-table{width:100%;border-collapse:collapse;font-size:12.5px}.env-table td{padding:9px 0;border-bottom:1px solid var(--bd)}.env-table td:last-child{text-align:right}

dialog{
  border:1px solid var(--bd);background:var(--sf);color:var(--tx);border-radius:20px;
  padding:24px;width:min(92vw,440px);box-shadow:0 30px 80px -15px rgba(0,0,0,.4);margin:auto;
}
dialog::backdrop{background:rgba(10,12,20,.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}
dialog h3{font-size:17px;margin-bottom:14px;font-weight:750}
.f{display:grid;gap:6px;margin-bottom:14px}.f label{font-size:12.5px;font-weight:650;color:var(--mu)}
.f input{height:42px;border-radius:10px;border:1px solid var(--bd);background:var(--s2);color:var(--tx);padding:0 12px;font:inherit;outline:0;transition:all .15s ease}
.f input:focus{border-color:var(--ac);box-shadow:0 0 0 3px var(--as)}
.acts{display:flex;gap:8px;justify-content:flex-end;margin-top:6px}

.toast{
  position:fixed;left:50%;bottom:calc(24px + env(safe-area-inset-bottom));transform:translate(-50%,20px) scale(0.96);
  background:rgba(15, 23, 42, 0.94);color:#ffffff;
  padding:10px 20px;border-radius:99px;font-weight:600;font-size:13px;letter-spacing:-0.1px;
  backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
  border:1px solid rgba(255,255,255,0.12);
  opacity:0;pointer-events:none;
  transition:all .22s cubic-bezier(0.16,1,0.3,1);
  z-index:999;box-shadow:0 12px 32px -4px rgba(0,0,0,.35);
  display:inline-flex;align-items:center;gap:8px;
}
.toast.s{opacity:1;transform:translate(-50%,0) scale(1)}

.moon{display:none}[data-theme=dark] .moon{display:block}[data-theme=dark] .sun{display:none}
@media(prefers-color-scheme:dark){:root:not([data-theme=light]) .moon{display:block}:root:not([data-theme=light]) .sun{display:none}}

@media(max-width:860px){
 .sidebar{position:fixed;inset:0 auto 0 0;width:270px;transform:translateX(-100%);box-shadow:var(--sh-hover);z-index:100}
 .sidebar.open{transform:translateX(0)}
 .side-backdrop.open{display:block}
 .side-close{display:grid}
 .mobile-header{display:flex}
 .content{padding:16px}
 .kp{grid-template-columns:1fr 1fr;gap:10px}
 .k b{font-size:22px}
 .two,.settings-grid{grid-template-columns:1fr}
 .oa{width:100%;margin-left:44px;justify-content:flex-start}
 .oa .sw{margin-left:auto}
 .ou{max-width:64vw}
}
</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute"><defs>
<symbol id="shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol>
<symbol id="dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></symbol>
<symbol id="server" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></symbol>
<symbol id="sliders" viewBox="0 0 24 24"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></symbol>
<symbol id="swap" viewBox="0 0 24 24"><path d="M16 3l4 4-4 4"/><path d="M20 7H4"/><path d="M8 21l-4-4 4-4"/><path d="M4 17h16"/></symbol>
<symbol id="tool" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></symbol>
<symbol id="sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></symbol>
<symbol id="moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></symbol>
<symbol id="plus" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></symbol>
<symbol id="up" viewBox="0 0 24 24"><path d="M18 15l-6-6-6 6"/></symbol>
<symbol id="dn" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
<symbol id="trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12h10l1-12M9 7V4h6v3"/></symbol>
<symbol id="bolt" viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></symbol>
<symbol id="key" viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/></symbol>
<symbol id="out" viewBox="0 0 24 24"><path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/></symbol>
<symbol id="check" viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></symbol>
<symbol id="menu" viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></symbol>
<symbol id="x" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol>
</defs></svg>

<div class="side-backdrop" id="sideBackdrop"></div>

<div class="app-layout">
 <!-- Left Sidebar -->
 <aside class="sidebar" id="sidebar">
  <div class="side-top">
   <div class="logo"><svg class="i"><use href="#shield"/></svg></div>
   <div>
    <div class="brand">AnyOrigin</div>
    <div class="side-sub">Edge Console</div>
   </div>
   <button class="ib side-close" id="sideClose" aria-label="Close menu"><svg class="i"><use href="#x"/></svg></button>
  </div>

  <nav class="side-nav">
   <button type="button" class="nav-item active" data-view="overview">
    <svg class="i"><use href="#dashboard"/></svg>
    <span>Overview</span>
   </button>
   <button type="button" class="nav-item" data-view="origins">
    <svg class="i"><use href="#server"/></svg>
    <span>Origins</span>
    <span class="nav-badge" id="sideOriginCount"><?= count($origins) ?></span>
   </button>
   <button type="button" class="nav-item" data-view="rules">
    <svg class="i"><use href="#sliders"/></svg>
    <span>Rules & Gateway</span>
   </button>
   <button type="button" class="nav-item" data-view="logs">
    <svg class="i"><use href="#swap"/></svg>
    <span>Incident Logs</span>
   </button>
   <button type="button" class="nav-item" data-view="system">
    <svg class="i"><use href="#tool"/></svg>
    <span>Maintenance</span>
   </button>
  </nav>

  <div class="side-footer">
   <div class="side-status">
    <span class="live-dot"></span>
    <span>Gateway Active</span>
   </div>
   <div class="side-user">
    <button class="ib sm" id="theme" aria-label="Theme"><svg class="i sun"><use href="#sun"/></svg><svg class="i moon"><use href="#moon"/></svg></button>
    <button class="ib sm" id="btnPwd" aria-label="Password"><svg class="i"><use href="#key"/></svg></button>
    <a href="login.php?logout=1" class="ib sm" aria-label="Logout"><svg class="i"><use href="#out"/></svg></a>
   </div>
  </div>
 </aside>

 <!-- Main Content Wrapper -->
 <div class="main-wrapper">
  <!-- Mobile Header -->
  <div class="mobile-header">
   <button class="ib sm" id="sideOpen" aria-label="Menu"><svg class="i"><use href="#menu"/></svg></button>
   <div class="logo sm" style="width:28px;height:28px;border-radius:8px"><svg class="i" style="width:16px;height:16px"><use href="#shield"/></svg></div>
   <span class="brand" style="font-size:15px">AnyOrigin</span>
   <span style="flex:1"></span>
   <button class="ib sm" id="mobileTheme" aria-label="Theme"><svg class="i sun"><use href="#sun"/></svg><svg class="i moon"><use href="#moon"/></svg></button>
   <button class="ib sm" id="mobilePwd" aria-label="Password"><svg class="i"><use href="#key"/></svg></button>
  </div>

  <main class="content">
   <?php if ($usingDefaultPwd): ?>
   <div class="alert-strip">
    <div style="display:flex;align-items:center;gap:8px">
     <svg class="i" style="color:var(--wa)"><use href="#bolt"/></svg>
     <span><strong>Security alert:</strong> You are currently using the default password. Update it now.</span>
    </div>
    <button class="btn g sm" onclick="$('#pwdDlg').showModal()">Change password</button>
   </div>
   <?php endif; ?>

   <!-- VIEW 1: OVERVIEW -->
   <div class="view-pane" id="view-overview">
    <div class="top">
     <div>
      <h2>Overview</h2>
      <span class="lbl">Cluster status and upstream telemetry</span>
     </div>
    </div>

    <section class="card hero">
     <div class="pulse" id="heroPulse"><svg class="i"><use href="#check"/></svg></div>
     <div style="flex:1;min-width:180px">
      <div class="lbl">Primary Upstream Origin</div>
      <div class="big" id="hn"></div>
      <div class="url mono" id="hu"></div>
     </div>
     <div style="display:flex;gap:8px;align-items:center">
      <button class="btn g" onclick="switchView('origins')">Configure origins</button>
      <button class="btn" id="add"><svg class="i"><use href="#plus"/></svg>Add origin</button>
     </div>
    </section>

    <section class="kp" id="kp"></section>

    <section class="two">
     <!-- Real Upstream Health & Latency -->
     <div class="card pad">
      <div class="hd">
       <div>
        <h2 style="margin:0">Upstream Health & Latency</h2>
        <span class="lbl">Measured response time per origin</span>
       </div>
       <button class="btn g sm" onclick="switchView('origins')">Manage →</button>
      </div>
      <div id="originLatencies" style="display:grid;gap:12px;margin-top:10px"></div>
     </div>

     <!-- Gateway Rules & Policy Summary -->
     <div class="card pad">
      <div class="hd">
       <div>
        <h2 style="margin:0">Active Gateway Policy</h2>
        <span class="lbl">Runtime proxy routing configuration</span>
       </div>
       <button class="btn g sm" onclick="switchView('rules')">Edit rules →</button>
      </div>
      <table class="env-table" style="margin-top:4px">
       <tr><td>Routing Strategy</td><td class="mono num" style="font-weight:650;color:var(--tx)"><?= $routing_strategy === 'round_robin' ? 'Round-Robin' : 'Priority Active-Standby' ?></td></tr>
       <tr><td>Failover Triggers</td><td class="mono num" style="font-weight:650;color:var(--tx)"><?= implode(', ', array_filter([$trigger_402 ? '402' : '', $trigger_429 ? '429' : '', $trigger_5xx ? '5xx' : ''])) ?: 'None' ?></td></tr>
       <tr><td>Connect Timeout</td><td class="mono num"><?= $timeout_ms ?> ms</td></tr>
       <tr><td>Max Retry Attempts</td><td class="mono num"><?= $max_retries ?></td></tr>
       <tr><td>Safe POST Idempotency</td><td class="mono num"><?= $safe_post_only ? '<span style="color:var(--ok)">Protected</span>' : '<span style="color:var(--mu)">Disabled</span>' ?></td></tr>
       <tr><td>Forward Client IP (XFF)</td><td class="mono num"><?= $forward_client_ip ? '<span style="color:var(--ok)">Active</span>' : '<span style="color:var(--mu)">Disabled</span>' ?></td></tr>
       <tr><td>Routing Table Cache</td><td class="mono num"><?= $configSize ?> (JSON)</td></tr>
      </table>
     </div>
    </section>

    <!-- Recent Failovers -->
    <div class="card pad">
     <div class="hd">
      <div>
       <h2 style="margin:0">Recent Failover Incidents</h2>
       <span class="lbl">Automatically rerouted visitor traffic events</span>
      </div>
      <button class="btn g sm" onclick="switchView('logs')">View all logs →</button>
     </div>
     <div id="quickLogs" class="ev" style="margin-top:10px"></div>
    </div>
   </div>

   <!-- VIEW 2: ORIGINS -->
   <div class="view-pane" id="view-origins" style="display:none">
    <div class="top">
     <div>
      <h2>Origins & Routing</h2>
      <span class="lbl">Configured upstream servers and failover order</span>
     </div>
     <button class="btn" onclick="$('#dlg').showModal()"><svg class="i"><use href="#plus"/></svg>Add origin</button>
    </div>

    <section class="list" id="list"></section>
   </div>

   <!-- VIEW 3: RULES & GATEWAY -->
   <div class="view-pane" id="view-rules" style="display:none">
    <div class="top">
     <div>
      <h2>Gateway & Failover Rules</h2>
      <span class="lbl">Proxy behavior, timeouts, and edge routing</span>
     </div>
     <button type="button" class="btn" id="saveRulesTop"><svg class="i"><use href="#check"/></svg>Save rules</button>
    </div>

    <section class="settings-grid">
     <!-- Card 1: Failover Triggers -->
     <div class="card pad">
      <div class="hd">
       <div style="display:flex;align-items:center">
        <h2 style="margin:0">Failover Triggers</h2>
        <button type="button" class="i-btn" data-tip="AnyOrigin reroutes incoming visitors to the next healthy standby origin whenever the primary returns any of these status codes.">i</button>
       </div>
       <span class="tag">Triggers</span>
      </div>
      <div class="rr">
       <span>HTTP 402 Payment Required<small>Billing quota / plan limits</small><button type="button" class="i-btn" data-tip="Reroutes if your primary host exhausts bandwidth or usage quota and returns HTTP 402.">i</button></span>
       <button class="sw <?= $trigger_402 ? 'on' : '' ?>" id="sw402" aria-label="Trigger 402"></button>
      </div>
      <div class="rr">
       <span>HTTP 429 Too Many Requests<small>Rate limit thresholds</small><button type="button" class="i-btn" data-tip="Reroutes when primary host rate-limits traffic so visitors continue to get served seamlessly.">i</button></span>
       <button class="sw <?= $trigger_429 ? 'on' : '' ?>" id="sw429" aria-label="Trigger 429"></button>
      </div>
      <div class="rr">
       <span>HTTP 5xx Server Errors<small>500, 502, 503, 504</small><button type="button" class="i-btn" data-tip="Reroutes instantly on backend crashes, gateway errors, or provider downtime.">i</button></span>
       <button class="sw <?= $trigger_5xx ? 'on' : '' ?>" id="sw5xx" aria-label="Trigger 5xx"></button>
      </div>
      <div class="rr">
       <span>Safe Idempotency<small>Protect POST/PUT from duplicate execution</small><button type="button" class="i-btn" data-tip="Guarantees non-idempotent operations (like payments) are not blindly retried on 5xx to avoid duplicate charges.">i</button></span>
       <button class="sw <?= $safe_post_only ? 'on' : '' ?>" id="swSafePost" aria-label="Safe Idempotency"></button>
      </div>
     </div>

     <!-- Card 2: Strategy & Resilience -->
     <div class="card pad">
      <div class="hd">
       <div style="display:flex;align-items:center">
        <h2 style="margin:0">Routing & Resilience</h2>
        <button type="button" class="i-btn" data-tip="Configure how traffic is routed and timeout tolerances before triggering a failover.">i</button>
       </div>
       <span class="tag">Resilience</span>
      </div>
      <div class="rg">
       <div>
        <span>Routing Strategy<button type="button" class="i-btn" data-tip="Priority routes 100% of traffic to Rank #1 until failure. Round-robin rotates requests across all active healthy origins.">i</button></span>
        <div class="seg" id="segStrategy">
         <button type="button" data-val="priority" class="<?= $routing_strategy === 'priority' ? 'a' : '' ?>">Priority</button>
         <button type="button" data-val="round_robin" class="<?= $routing_strategy === 'round_robin' ? 'a' : '' ?>">Round-Robin</button>
        </div>
       </div>
      </div>
      <div class="rg">
       <div>
        <span>Connect Timeout<button type="button" class="i-btn" data-tip="Max time to wait for origin connection before triggering failover.">i</button></span>
        <span class="mono num" id="tv"><?= $timeout_ms ?> ms</span>
       </div>
       <input type="range" min="100" max="10000" step="100" value="<?= $timeout_ms ?>" id="tm">
      </div>
      <div class="rg">
       <div>
        <span>Max Retry Attempts<button type="button" class="i-btn" data-tip="Number of backup origins to try before returning an error to the visitor.">i</button></span>
        <div class="seg" id="rty">
         <button type="button" data-val="1" class="<?= $max_retries === 1 ? 'a' : '' ?>">1</button>
         <button type="button" data-val="2" class="<?= $max_retries === 2 ? 'a' : '' ?>">2</button>
         <button type="button" data-val="3" class="<?= $max_retries === 3 ? 'a' : '' ?>">3</button>
        </div>
       </div>
      </div>
      <div class="rg">
       <div>
        <span>Health Check Interval<button type="button" class="i-btn" data-tip="Frequency of periodic background health checks.">i</button></span>
        <div class="seg" id="hc">
         <button type="button" data-val="10s" class="<?= $health_check_interval === '10s' ? 'a' : '' ?>">10s</button>
         <button type="button" data-val="30s" class="<?= $health_check_interval === '30s' ? 'a' : '' ?>">30s</button>
         <button type="button" data-val="1m" class="<?= $health_check_interval === '1m' ? 'a' : '' ?>">1m</button>
         <button type="button" data-val="5m" class="<?= $health_check_interval === '5m' ? 'a' : '' ?>">5m</button>
        </div>
       </div>
      </div>
     </div>

     <!-- Card 3: Headers & Gateway Rewriting -->
     <div class="card pad">
      <div class="hd">
       <div style="display:flex;align-items:center">
        <h2 style="margin:0">Proxy Headers & Cookies</h2>
        <button type="button" class="i-btn" data-tip="Manage HTTP request/response headers so backend apps see real client IPs, hosts, and valid auth cookies.">i</button>
       </div>
       <span class="tag">Gateway</span>
      </div>
      <div class="rr">
       <span>Forward Client IP<small>X-Forwarded-For header</small><button type="button" class="i-btn" data-tip="Passes the real visitor IP address to your backend applications instead of the proxy server IP.">i</button></span>
       <button class="sw <?= $forward_client_ip ? 'on' : '' ?>" id="swClientIp" aria-label="Forward IP"></button>
      </div>
      <div class="rr">
       <span>Preserve Host Domain<small>X-Forwarded-Host header</small><button type="button" class="i-btn" data-tip="Passes original domain visited by client to multi-domain backend applications.">i</button></span>
       <button class="sw on" id="swHostHdr" aria-label="Preserve Host" disabled style="opacity:.65"></button>
      </div>
      <div class="rr">
       <span>Rewrite Redirects<small>Location response header</small><button type="button" class="i-btn" data-tip="Rewrites backend internal redirect URLs to your custom public domain so visitors never see internal hostnames.">i</button></span>
       <button class="sw <?= $rewrite_locations ? 'on' : '' ?>" id="swRewrLoc" aria-label="Rewrite Location"></button>
      </div>
      <div class="rr">
       <span>Normalize Cookies<small>Set-Cookie Domain</small><button type="button" class="i-btn" data-tip="Automatically rewrites backend cookie domain attributes to ensure user login sessions work seamlessly on your domain.">i</button></span>
       <button class="sw <?= $rewrite_cookies ? 'on' : '' ?>" id="swRewrCk" aria-label="Rewrite Cookie"></button>
      </div>
     </div>

     <!-- Card 4: Quick Action Save -->
     <div class="card pad" style="display:flex;flex-direction:column;justify-content:space-between">
      <div>
       <div class="hd">
        <h2 style="margin:0">Apply Changes</h2>
        <span class="tag">System</span>
       </div>
       <p style="font-size:12.5px;color:var(--mu);margin:10px 0 16px;line-height:1.6">
        Rules compile directly into a compiled routing cache (<span class="mono">data/config.json</span>). Updates take effect instantaneously on incoming requests.
       </p>
      </div>
      <button type="button" class="btn" id="saveRules" style="width:100%"><svg class="i"><use href="#check"/></svg>Save all settings</button>
     </div>
    </section>
   </div>

   <!-- VIEW 4: INCIDENT LOGS -->
   <div class="view-pane" id="view-logs" style="display:none">
    <div class="top">
     <div>
      <h2>Failover Incident Log</h2>
      <span class="lbl">Historical record of automatic upstream rerouting</span>
     </div>
     <div style="display:flex;gap:8px;align-items:center">
      <div class="seg" id="logFilter">
       <button data-f="all" class="a">All</button>
       <button data-f="402">402</button>
       <button data-f="429">429</button>
       <button data-f="5xx">5xx</button>
      </div>
      <button type="button" class="btn g sm" id="btnClearLogs">Clear log</button>
     </div>
    </div>

    <div class="card pad">
     <div class="ev" id="ev"></div>
    </div>
   </div>

   <!-- VIEW 5: MAINTENANCE & SYSTEM -->
   <div class="view-pane" id="view-system" style="display:none">
    <div class="top">
     <div>
      <h2>System & Maintenance</h2>
      <span class="lbl">Cache synchronization, security, and environment</span>
     </div>
    </div>

    <section class="two">
     <div class="card pad">
      <div class="hd"><h2>Operational Tools</h2><span class="tag">Maintenance</span></div>
      <div style="display:grid;gap:14px;margin-top:6px">
       <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
        <div>
         <strong>Purge Routing Cache</strong><button type="button" class="i-btn" data-tip="Forces AnyOrigin proxy to flush and regenerate the data/config.json cache instantly.">i</button>
         <small style="display:block;color:var(--mu)">Sync SQLite table to JSON</small>
        </div>
        <button type="button" class="btn g sm" id="btnPurge">Purge cache</button>
       </div>

       <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:12px;border-top:1px solid var(--bd)">
        <div>
         <strong>Reset Login Lockouts</strong><button type="button" class="i-btn" data-tip="Clears temporary IP blocks resulting from consecutive failed login attempts.">i</button>
         <small style="display:block;color:var(--mu)">Unblock rate-limited IP addresses</small>
        </div>
        <button type="button" class="btn g sm" id="btnResetLock">Clear blocks</button>
       </div>

       <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:12px;border-top:1px solid var(--bd)">
        <div>
         <strong>Admin Credentials</strong>
         <small style="display:block;color:var(--mu)">Update admin password securely</small>
        </div>
        <button type="button" class="btn g sm" onclick="$('#pwdDlg').showModal()">Change password</button>
       </div>
      </div>
     </div>

     <div class="card pad">
      <div class="hd"><h2>Environment Telemetry</h2><span class="tag">Runtime</span></div>
      <table class="env-table">
       <tr><td>PHP Runtime</td><td class="mono num"><?= PHP_VERSION ?></td></tr>
       <tr><td>SQLite Version</td><td class="mono num"><?= $pdo->query('select sqlite_version()')->fetchColumn() ?></td></tr>
       <tr><td>cURL Engine</td><td class="mono num"><?= curl_version()['version'] ?></td></tr>
       <tr><td>OPcache Active</td><td class="mono num"><?= function_exists('opcache_get_status') && opcache_get_status() ? '<span style="color:var(--ok)">Active</span>' : '<span style="color:var(--mu)">Disabled</span>' ?></td></tr>
       <tr><td>Database File</td><td class="mono num"><?= $dbSize ?></td></tr>
       <tr><td>Config Cache</td><td class="mono num"><?= $configSize ?></td></tr>
      </table>
     </div>
    </section>
   </div>
  </main>
 </div>
</div>

<!-- Add Origin Modal -->
<dialog id="dlg"><h3>Add upstream origin</h3>
 <form id="frm">
  <div class="f"><label>Origin Name</label><input required placeholder="e.g. Primary Edge, Koyeb Standby" id="fn"></div>
  <div class="f"><label>Target URL (HTTPS)</label><input required type="url" placeholder="https://origin.example.com" id="fu"></div>
  <div class="f"><label>Host Header Override (Optional)</label><input placeholder="origin.example.com" id="fh"></div>
  <div class="acts"><button type="button" class="btn g" id="cx">Cancel</button><button type="submit" class="btn">Add origin</button></div>
 </form>
</dialog>

<!-- Change Password Modal -->
<dialog id="pwdDlg"><h3>Change password</h3>
 <form id="pwdFrm">
  <div class="f"><label>Current password</label><input required type="password" placeholder="••••••••" id="curPwd"></div>
  <div class="f"><label>New password (min 8 chars)</label><input required type="password" minlength="8" placeholder="••••••••" id="newPwd"></div>
  <div class="f"><label>Confirm new password</label><input required type="password" minlength="8" placeholder="••••••••" id="cnfPwd"></div>
  <div class="acts"><button type="button" class="btn g" id="pwdCx">Cancel</button><button type="submit" class="btn">Update password</button></div>
 </form>
</dialog>

<div class="toast" id="toast"></div>

<script>
const $=s=>document.querySelector(s),$$=s=>document.querySelectorAll(s),ic=n=>`<svg class="i"><use href="#${n}"/></svg>`;

let O = <?= json_encode($originsJson) ?>;
let EV = <?= json_encode($incidentsJson) ?>;
let activeLogFilter = 'all';

const toast=m=>{const t=$('#toast');t.textContent=m;t.classList.add('s');clearTimeout(t._);t._=setTimeout(()=>t.classList.remove('s'),2400)};

async function postAction(action, data = {}) {
  const form = new FormData();
  form.append('action', action);
  form.append('ajax', '1');
  for (const k in data) form.append(k, data[k]);
  try {
    const res = await fetch('/anyorigin/index.php', { method: 'POST', body: form });
    return await res.json();
  } catch(e) {
    return { success: false, message: 'Network or server error' };
  }
}

function render() {
  const onlineHosts = O.filter(o => o.on && o.ok && o.ms > 0);
  const avg = onlineHosts.length ? Math.round(onlineHosts.reduce((a, o) => a + o.ms, 0) / onlineHosts.length) : 0;
  const live = O.find(o => o.on) || { n: 'No active host configured', u: 'Add an origin in the Origins tab', ms: 0, ok: 0 };

  $('#hn').textContent = live.n;
  $('#hu').textContent = live.u;
  $('#sideOriginCount').textContent = O.length;

  const heroPulse = $('#heroPulse');
  if (heroPulse) {
    heroPulse.className = (live.ok && live.ms > 0) ? 'pulse' : 'pulse down';
  }

  // 4 Real KPI Cards
  const totalOrigins = O.length;
  const healthyCount = onlineHosts.length;
  const standbyCount = O.filter(o => !o.ok || o.ms === 0).length;

  const K = [
    ['Total Origins', totalOrigins, `${O.filter(o => o.on).length} active in pool`, 'up'],
    ['Reachable Hosts', healthyCount, healthyCount ? 'Responding to health checks' : 'No reachable hosts', healthyCount ? 'up' : 'dn'],
    ['Standby / Offline', standbyCount, standbyCount ? 'Backups & unreachable' : 'All origins online', 'mu'],
    ['Avg Latency', avg ? avg + ' ms' : 'Offline', avg ? 'Across reachable hosts' : 'No latency recorded', avg ? 'up' : 'dn']
  ];

  $('#kp').innerHTML = K.map(k => `
    <div class="card k">
      <span class="lbl">${k[0]}</span>
      <b class="num">${k[1]}</b>
      <small class="${k[3]}">${k[2]}</small>
    </div>
  `).join('');

  // Upstream Health & Latency Comparison in Overview
  const maxMs = Math.max(...O.map(o => o.ms), 200) || 200;
  $('#originLatencies').innerHTML = O.length ? O.map((o, i) => {
    const isOnline = o.ok && o.ms > 0;
    const msBadge = isOnline 
      ? `<span class="ms mono num healthy">${o.ms} ms</span>` 
      : `<span class="ms mono num down">Down</span>`;
    const barWidth = isOnline ? Math.max(8, Math.min(100, Math.round((o.ms / maxMs) * 100))) : 0;
    return `
      <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--bd)">
        <span class="rank" style="width:26px;height:26px;font-size:11.5px">${i + 1}</span>
        <span class="dot ${isOnline ? '' : 'd'}"></span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:650;font-size:13px;display:flex;align-items:center;gap:6px">
            ${o.n} ${i === 0 ? '<span class="tag">Primary</span>' : ''}
          </div>
          <div class="ou mono" style="font-size:11.5px">${o.u}</div>
          <div class="tr" style="height:4px;margin-top:6px;max-width:240px">
            <i style="width:${barWidth}%;background:${isOnline ? 'var(--ok)' : 'var(--er)'}"></i>
          </div>
        </div>
        ${msBadge}
      </div>
    `;
  }).join('') : '<div style="padding:16px 0;color:var(--mu);text-align:center">No origins configured yet.</div>';

  // Full Origins List (Origins View)
  $('#list').innerHTML = O.length ? O.map((o, i) => {
    const isOnline = o.ok && o.ms > 0;
    const msBadge = isOnline 
      ? `<span class="ms mono num healthy">${o.ms} ms</span>` 
      : `<span class="ms mono num down">Down</span>`;
    const trafficInfo = (i === 0 && o.on)
      ? `<b class="num" style="color:var(--ok)">Active</b><span>Primary target</span>`
      : `<b class="num" style="color:var(--mu)">Standby</b><span>${o.on ? 'Failover target' : 'Disabled'}</span>`;

    return `
      <div class="card o ${o.on ? '' : 'off'}">
        <div class="rank">${i + 1}</div>
        <div class="om">
          <div class="on">
            <span class="dot ${isOnline ? '' : 'd'}"></span>${o.n}${i === 0 ? '<span class="tag">Primary</span>' : ''}
          </div>
          <div class="ou mono">${o.u}</div>
        </div>
        <div class="met">${trafficInfo}</div>
        <div class="oa">
          ${msBadge}
          <div class="mv">
            <button data-m="${i},-1" aria-label="Up">${ic('up')}</button>
            <button data-m="${i},1" aria-label="Down">${ic('dn')}</button>
          </div>
          <button class="ib sm" data-t="${i}" aria-label="Test">${ic('bolt')}</button>
          <button class="ib sm del" data-d="${i}" aria-label="Delete">${ic('trash')}</button>
          <button class="sw ${o.on ? 'on' : ''}" data-s="${i}" aria-label="Toggle"></button>
        </div>
      </div>
    `;
  }).join('') : '<div style="padding:32px 0;text-align:center;color:var(--mu)">No origins added yet. Click "+ Add origin" to get started.</div>';

  // Incident Logs (Filtered)
  const filteredEV = activeLogFilter === 'all' 
    ? EV 
    : EV.filter(e => e.code.startsWith(activeLogFilter));

  const logHtml = filteredEV.length ? filteredEV.map(e => `
    <div class="e">
      <div class="ei" style="background:var(--${e.badge=='er'?'es':'ws'});color:var(--${e.badge})">${ic('swap')}</div>
      <div style="flex:1;min-width:180px">
        <div style="font-weight:650">${e.from} → ${e.to}</div>
        <small style="color:var(--mu)">Path: <span class="mono">${e.uri}</span> · ${e.time}</small>
      </div>
      <span class="chip" style="background:var(--${e.badge=='er'?'es':'ws'});color:var(--${e.badge})">${e.code}</span>
    </div>
  `).join('') : `
    <div style="padding:36px 16px;text-align:center;color:var(--mu)">
      <svg class="i" style="width:32px;height:32px;margin:0 auto 10px;opacity:.5;display:block"><use href="#check"/></svg>
      <div style="font-weight:650;font-size:14px;color:var(--tx)">No Failover Incidents</div>
      <p style="font-size:12px;margin-top:4px;color:var(--mu)">No upstream failures (402, 429, 5xx) or timeouts have been recorded.</p>
    </div>
  `;

  $('#ev').innerHTML = logHtml;

  // Quick logs in overview
  const quickLogEl = $('#quickLogs');
  if (quickLogEl) {
    quickLogEl.innerHTML = EV.length ? EV.slice(0, 3).map(e => `
      <div class="e">
        <div class="ei" style="background:var(--${e.badge=='er'?'es':'ws'});color:var(--${e.badge})">${ic('swap')}</div>
        <div style="flex:1;min-width:160px">
          <div style="font-weight:650">${e.from} → ${e.to}</div>
          <small style="color:var(--mu)">${e.time}</small>
        </div>
        <span class="chip" style="background:var(--${e.badge=='er'?'es':'ws'});color:var(--${e.badge})">${e.code}</span>
      </div>
    `).join('') : `
      <div style="padding:24px 16px;text-align:center;color:var(--mu)">
        <div style="font-weight:600;font-size:13px;color:var(--tx)">No Recent Incidents</div>
        <small style="color:var(--mu)">All requests are being served by primary origin.</small>
      </div>
    `;
  }
}

// Navigation & View switching
function switchView(viewName) {
  $$('.view-pane').forEach(p => p.style.display = 'none');
  const target = $(`#view-${viewName}`);
  if (target) {
    target.style.display = 'grid';
    $$('.nav-item').forEach(b => {
      b.classList.toggle('active', b.dataset.view === viewName);
    });
    try { localStorage.setItem('ao-view', viewName); } catch(_) {}
  }
}

$$('.nav-item').forEach(btn => {
  btn.onclick = () => {
    switchView(btn.dataset.view);
    closeMobileSidebar();
  };
});

// Mobile Sidebar
const sidebar = $('#sidebar');
const sideBackdrop = $('#sideBackdrop');
function openMobileSidebar() {
  sidebar.classList.add('open');
  sideBackdrop.classList.add('open');
}
function closeMobileSidebar() {
  sidebar.classList.remove('open');
  sideBackdrop.classList.remove('open');
}
const sideOpen = $('#sideOpen');
if (sideOpen) sideOpen.onclick = openMobileSidebar;
const sideClose = $('#sideClose');
if (sideClose) sideClose.onclick = closeMobileSidebar;
sideBackdrop.onclick = closeMobileSidebar;

// Segment controls
$$('.seg').forEach(sg => {
  if (sg.id === 'range' || sg.id === 'tab') return;
  sg.onclick = e => {
    const b = e.target.closest('button');
    if (!b) return;
    sg.querySelectorAll('button').forEach(x => x.classList.remove('a'));
    b.classList.add('a');
  };
});

// Incident Logs Filter
const logFilter = $('#logFilter');
if (logFilter) {
  logFilter.onclick = e => {
    const b = e.target.closest('button');
    if (!b) return;
    logFilter.querySelectorAll('button').forEach(x => x.classList.remove('a'));
    b.classList.add('a');
    activeLogFilter = b.dataset.f;
    render();
  };
}

// Clear Logs
const btnClearLogs = $('#btnClearLogs');
if (btnClearLogs) {
  btnClearLogs.onclick = async () => {
    toast('Clearing logs...');
    const res = await postAction('clear_incidents');
    if (res.success) {
      EV = [];
      render();
    }
    toast(res.message);
  };
}

// Origins List Event Delegation
$('#list').onclick = async e => {
  const b = e.target.closest('button');
  if (!b) return;

  // Toggle active
  if (b.dataset.s) {
    const o = O[b.dataset.s];
    toast(o.on ? 'Disabling host...' : 'Enabling host...');
    const res = await postAction('toggle_active', { id: o.id });
    if (res.success) {
      o.on = res.active;
      render();
    }
    toast(res.message);
  }
  // Delete origin
  else if (b.dataset.d) {
    const idx = parseInt(b.dataset.d);
    const o = O[idx];
    if (confirm(`Remove origin "${o.n}"?`)) {
      toast('Removing host...');
      const res = await postAction('delete_origin', { id: o.id });
      if (res.success) {
        O.splice(idx, 1);
        render();
      }
      toast(res.message);
    }
  }
  // Move priority up/down
  else if (b.dataset.m) {
    const [idx, dir] = b.dataset.m.split(',').map(Number);
    const targetIdx = idx + dir;
    if (targetIdx >= 0 && targetIdx < O.length) {
      toast('Reordering priority...');
      const res = await postAction('move_priority', {
        id: O[idx].id,
        direction: dir === -1 ? 'up' : 'down'
      });
      if (res.success) {
        const tmp = O[idx];
        O[idx] = O[targetIdx];
        O[targetIdx] = tmp;
        render();
      }
      toast(res.message);
    }
  }
  // Ping test with tactile spinning animation
  else if (b.dataset.t) {
    const o = O[b.dataset.t];
    b.classList.add('spinning');
    toast('Testing ' + o.n + '...');
    const res = await postAction('ping_host', { id: o.id });
    b.classList.remove('spinning');
    o.ms = (res && res.latency) ? res.latency : 0;
    o.ok = (res && res.status === 'healthy') ? 1 : 0;
    render();
    toast(res.message);
  }
};

// Switches in settings grid
$$('.settings-grid .sw:not([disabled])').forEach(sw => {
  sw.onclick = function() { this.classList.toggle('on'); };
});

$('#tm').oninput = e => $('#tv').textContent = e.target.value + ' ms';

const saveRulesHandler = async () => {
  toast('Saving settings...');
  const res = await postAction('update_rules', {
    trigger_402: $('#sw402').classList.contains('on') ? 1 : 0,
    trigger_429: $('#sw429').classList.contains('on') ? 1 : 0,
    trigger_5xx: $('#sw5xx').classList.contains('on') ? 1 : 0,
    safe_post_only: $('#swSafePost').classList.contains('on') ? 1 : 0,
    routing_strategy: $('#segStrategy button.a')?.dataset.val || 'priority',
    timeout_ms: $('#tm').value,
    max_retries: $('#rty button.a')?.dataset.val || 2,
    health_check_interval: $('#hc button.a')?.dataset.val || '1m',
    forward_client_ip: $('#swClientIp').classList.contains('on') ? 1 : 0,
    rewrite_locations: $('#swRewrLoc').classList.contains('on') ? 1 : 0,
    rewrite_cookies: $('#swRewrCk').classList.contains('on') ? 1 : 0,
  });
  toast(res.message);
};

$('#saveRules').onclick = saveRulesHandler;
const saveTop = $('#saveRulesTop');
if (saveTop) saveTop.onclick = saveRulesHandler;

$('#btnPurge').onclick = async () => {
  toast('Purging cache...');
  const res = await postAction('purge_cache');
  toast(res.message);
};

$('#btnResetLock').onclick = async () => {
  toast('Resetting lockouts...');
  const res = await postAction('reset_lockouts');
  toast(res.message);
};

// Interactive tooltip system for i-buttons
const tipEl = document.createElement('div');
tipEl.className = 'global-tooltip';
document.body.appendChild(tipEl);

function positionTip(btn) {
  tipEl.textContent = btn.getAttribute('data-tip');
  tipEl.classList.add('show');
  const rect = btn.getBoundingClientRect();
  const tipRect = tipEl.getBoundingClientRect();
  let left = rect.left + (rect.width / 2) - (tipRect.width / 2);
  let top = rect.top - tipRect.height - 8;
  if (left < 10) left = 10;
  if (left + tipRect.width > window.innerWidth - 10) left = window.innerWidth - tipRect.width - 10;
  if (top < 10) top = rect.bottom + 8;
  tipEl.style.left = left + 'px';
  tipEl.style.top = top + 'px';
}

document.addEventListener('mouseover', e => {
  const btn = e.target.closest('[data-tip]');
  if (btn) positionTip(btn);
});
document.addEventListener('mouseout', e => {
  if (e.target.closest('[data-tip]')) tipEl.classList.remove('show');
});
document.addEventListener('click', e => {
  const btn = e.target.closest('[data-tip]');
  if (btn) {
    e.stopPropagation();
    positionTip(btn);
  } else {
    tipEl.classList.remove('show');
  }
});

// Add Origin Modal
const dlg = $('#dlg');
$('#add').onclick = () => dlg.showModal();
$('#cx').onclick = () => dlg.close();

$('#frm').onsubmit = async e => {
  e.preventDefault();
  const name = $('#fn').value;
  const url = $('#fu').value;
  const host = $('#fh').value;
  toast('Adding host...');
  const res = await postAction('add_origin', { name, url, host_header: host });
  if (res.success) {
    O.push({ id: res.id, n: name, u: url, h: host, ms: 0, ok: 0, on: 1, c: '#0284c7' });
    $('#frm').reset();
    dlg.close();
    render();
  }
  toast(res.message);
};

// Password Modal
const pwdDlg = $('#pwdDlg');
$('#btnPwd').onclick = () => pwdDlg.showModal();
const mobPwd = $('#mobilePwd');
if (mobPwd) mobPwd.onclick = () => pwdDlg.showModal();
$('#pwdCx').onclick = () => pwdDlg.close();

$('#pwdFrm').onsubmit = async e => {
  e.preventDefault();
  const cur = $('#curPwd').value;
  const nw = $('#newPwd').value;
  const cnf = $('#cnfPwd').value;
  toast('Updating password...');
  const res = await postAction('change_password', {
    current_password: cur,
    new_password: nw,
    confirm_password: cnf
  });
  toast(res.message);
  if (res.success) {
    $('#pwdFrm').reset();
    pwdDlg.close();
    const alertStrip = $('.alert-strip');
    if (alertStrip) alertStrip.remove();
  }
};

// Theme switcher
const root = document.documentElement;
try {
  const t = localStorage.getItem('ao-theme');
  if (t) root.dataset.theme = t;
} catch(_) {}
const toggleTheme = () => {
  const d = root.dataset.theme ? root.dataset.theme == 'dark' : matchMedia('(prefers-color-scheme:dark)').matches;
  root.dataset.theme = d ? 'light' : 'dark';
  try { localStorage.setItem('ao-theme', root.dataset.theme); } catch(_) {};
};
$('#theme').onclick = toggleTheme;
const mobTheme = $('#mobileTheme');
if (mobTheme) mobTheme.onclick = toggleTheme;

// Restore saved view
try {
  const savedView = localStorage.getItem('ao-view');
  if (savedView && $(`#view-${savedView}`)) {
    switchView(savedView);
  }
} catch(_) {}

render();
</script>
</body>
</html>
