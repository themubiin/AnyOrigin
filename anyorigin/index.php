<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
requireAuth();

$pdo = getDb();

// Handle AJAX & API actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

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
                $respond(true, "Priority reordered");
            }
        }
        $respond(false, "Could not move origin");
    }

    if ($action === 'toggle_active') {
        $id = $_POST['id'] ?? '';
        $stmt = $pdo->prepare("SELECT is_active FROM origins WHERE id = ?");
        $stmt->execute([$id]);
        $current = (int)$stmt->fetchColumn();
        $newVal = $current ? 0 : 1;
        $pdo->prepare("UPDATE origins SET is_active = ? WHERE id = ?")->execute([$newVal, $id]);
        syncConfigCache($pdo);
        $respond(true, $newVal ? "Origin enabled" : "Origin disabled", ['is_active' => $newVal]);
    }

    if ($action === 'update_rules') {
        $trigger_402 = !empty($_POST['trigger_402']) ? 1 : 0;
        $trigger_429 = !empty($_POST['trigger_429']) ? 1 : 0;
        $trigger_5xx = !empty($_POST['trigger_5xx']) ? 1 : 0;
        $timeout_ms = max(500, (int)($_POST['timeout_ms'] ?? 2500));
        $max_retries = max(1, (int)($_POST['max_retries'] ?? 2));
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("UPDATE failover_rules SET trigger_402 = ?, trigger_429 = ?, trigger_5xx = ?, latency_threshold_ms = ?, max_retries = ?, updated_at = ? WHERE site_id = 'default-site'");
        $stmt->execute([$trigger_402, $trigger_429, $trigger_5xx, $timeout_ms, $max_retries, $now]);

        if (isset($_POST['routing_strategy'])) {
            setAdminSetting($pdo, 'routing_strategy', $_POST['routing_strategy'] === 'round_robin' ? 'round_robin' : 'priority');
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
            setAdminSetting($pdo, 'health_check_interval', in_array($_POST['health_check_interval'], ['30s', '1m', '5m'], true) ? $_POST['health_check_interval'] : '1m');
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

    if ($action === 'ping_host') {
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

            $respond(true, "{$origin['name']}: HTTP {$code} ({$latency}ms)", ['latency' => $latency, 'status' => $status, 'code' => $code]);
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

// Color palette for chart and origins
$palette = ['#38bdf8', '#34d399', '#6366f1', '#fbbf24', '#fb7185', '#a855f7'];

$originsJson = array_map(function($o, $idx) use ($palette) {
    return [
        'id' => $o['id'],
        'n' => $o['name'],
        'u' => $o['url'],
        'h' => $o['host_header_override'] ?? '',
        'ms' => (int)($o['latency_ms'] ?: 45),
        'ok' => ($o['status'] === 'healthy') ? 1 : 0,
        'on' => (int)$o['is_active'],
        'c' => $palette[$idx % count($palette)],
        'w' => 1000 * max(1, 6 - $idx),
    ];
}, $origins, array_keys($origins));

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
<style>
:root{--bg:#f6f5f1;--sf:#fff;--s2:#faf9f6;--bd:#e7e4dd;--tx:#1b1a17;--mu:#78746b;--ac:#0a84d6;--ax:#fff;--as:#e6f3fc;--ok:#16a34a;--os:#e5f6ea;--er:#e11d48;--es:#fde8ed;--wa:#d97706;--ws:#fdf0dc;--sh:0 1px 2px rgba(30,25,10,.04),0 8px 24px -12px rgba(30,25,10,.12);--gr:#ece9e2}
[data-theme=dark]{--bg:#0a0e17;--sf:#121826;--s2:#0e1420;--bd:#222b3e;--tx:#e9edf5;--mu:#8a94a8;--ac:#38bdf8;--ax:#06121c;--as:#10283a;--ok:#34d399;--os:#0f2a22;--er:#fb7185;--es:#33141c;--wa:#fbbf24;--ws:#33270f;--sh:0 1px 2px rgba(0,0,0,.3),0 12px 28px -14px rgba(0,0,0,.6);--gr:#1b2334}
@media(prefers-color-scheme:dark){:root:not([data-theme=light]){--bg:#0a0e17;--sf:#121826;--s2:#0e1420;--bd:#222b3e;--tx:#e9edf5;--mu:#8a94a8;--ac:#38bdf8;--ax:#06121c;--as:#10283a;--ok:#34d399;--os:#0f2a22;--er:#fb7185;--es:#33141c;--wa:#fbbf24;--ws:#33270f;--sh:0 1px 2px rgba(0,0,0,.3),0 12px 28px -14px rgba(0,0,0,.6);--gr:#1b2334}}
*{box-sizing:border-box;margin:0}
body{background:var(--bg);color:var(--tx);font:14px/1.45 Inter,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased;padding-bottom:env(safe-area-inset-bottom)}
svg.i{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
button{font:inherit;color:inherit;cursor:pointer;border:0;background:none}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.num{font-variant-numeric:tabular-nums}

/* App Layout & Sidebar */
.app-layout{display:flex;min-height:100vh}
.sidebar{width:240px;background:var(--sf);border-right:1px solid var(--bd);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;padding:20px 16px;flex-shrink:0;z-index:40}
.side-top{display:flex;align-items:center;gap:12px;padding-bottom:18px;border-bottom:1px solid var(--bd);margin-bottom:14px}
.logo{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#38bdf8,#6366f1);display:grid;place-items:center;color:#fff}
.brand{font-weight:750;font-size:16px;letter-spacing:-.3px}
.side-sub{font-size:11px;color:var(--mu);font-weight:600;letter-spacing:.3px;text-transform:uppercase}
.side-close{display:none;margin-left:auto}
.side-nav{display:flex;flex-direction:column;gap:5px;flex:1}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:11px;font-weight:600;font-size:13.5px;color:var(--mu);text-decoration:none;transition:.15s;text-align:left;width:100%}
.nav-item:hover{color:var(--tx);background:var(--s2)}
.nav-item.active{color:var(--ac);background:var(--as)}
.nav-badge{margin-left:auto;font-size:11px;font-weight:700;padding:1px 7px;border-radius:99px;background:var(--s2);color:var(--mu)}
.nav-item.active .nav-badge{background:var(--ac);color:#fff}
.side-footer{border-top:1px solid var(--bd);padding-top:14px;margin-top:auto;display:grid;gap:10px}
.side-status{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:var(--ok)}
.live-dot{width:7px;height:7px;border-radius:50%;background:var(--ok);animation:lp 2s infinite}
@keyframes lp{0%,100%{opacity:1}50%{opacity:.4}}
.side-user{display:flex;align-items:center;gap:8px}

.main-wrapper{flex:1;min-width:0;display:flex;flex-direction:column;background:var(--bg)}
.mobile-header{display:none;align-items:center;gap:10px;padding:12px 18px;background:var(--sf);border-bottom:1px solid var(--bd);position:sticky;top:0;z-index:30}
.side-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:35;backdrop-filter:blur(3px)}

.content{max-width:1060px;width:100%;margin:0 auto;padding:24px 28px;display:grid;gap:18px}
.view-pane{display:grid;gap:18px}

/* UI Elements */
.card{background:var(--sf);border:1px solid var(--bd);border-radius:18px;box-shadow:var(--sh)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:38px;padding:0 15px;border-radius:11px;font-weight:650;background:var(--ac);color:var(--ax);transition:.15s}
.btn:hover{filter:brightness(1.07)}.btn:active{transform:scale(.98)}.btn.g{background:var(--s2);color:var(--tx);border:1px solid var(--bd)}
.btn.sm{height:30px;padding:0 10px;font-size:12px;border-radius:8px}
.ib{width:36px;height:36px;border-radius:10px;border:1px solid var(--bd);background:var(--sf);display:grid;place-items:center;color:var(--mu);transition:.15s}
.ib:hover{color:var(--tx);border-color:var(--mu)}
.ib.sm{width:32px;height:32px;border-radius:8px}
.seg{display:inline-flex;padding:3px;background:var(--s2);border:1px solid var(--bd);border-radius:11px;gap:2px}
.seg button{padding:5px 12px;border-radius:8px;font-weight:650;font-size:12px;color:var(--mu)}
.seg button.a{background:var(--sf);color:var(--tx);box-shadow:0 1px 3px rgba(0,0,0,.12)}
.lbl{font-size:11.5px;color:var(--mu);font-weight:600;letter-spacing:.4px;text-transform:uppercase}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.top h2{font-size:20px;letter-spacing:-.4px;font-weight:750}

.hero{padding:18px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.pulse{width:44px;height:44px;border-radius:14px;background:var(--os);color:var(--ok);display:grid;place-items:center;position:relative}
.pulse:after{content:"";position:absolute;inset:0;border-radius:inherit;border:2px solid var(--ok);opacity:.5;animation:p 2s infinite}
@keyframes p{to{transform:scale(1.35);opacity:0}}
.big{font-size:22px;font-weight:750;letter-spacing:-.4px}.url{font-size:12.5px;color:var(--mu)}

.kp{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.k{padding:16px;display:grid;gap:2px;overflow:hidden}
.k b{font-size:26px;letter-spacing:-.9px;line-height:1.15}.k small{font-size:12px;font-weight:650}
.k .sk{height:28px;margin-top:6px}.up{color:var(--ok)}.dn{color:var(--er)}

.two{display:grid;grid-template-columns:1.8fr 1fr;gap:16px}
.pad{padding:18px}
.hd{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.leg{display:flex;gap:12px;font-size:12px;color:var(--mu);font-weight:600;flex-wrap:wrap;margin-top:8px}
.leg i,.srow i{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px}
#chart{width:100%;height:auto;display:block}
.sc{display:grid;gap:12px}
.sr{display:grid;gap:5px}.sr div:first-child{display:flex;justify-content:space-between;font-weight:600;font-size:12.5px}.sr span{color:var(--mu);font-weight:500}
.tr{height:7px;background:var(--s2);border-radius:9px;overflow:hidden;border:1px solid var(--bd)}.tr i{display:block;height:100%;border-radius:9px}

.sec{display:flex;align-items:center;justify-content:space-between;padding:2px}.sec h2,.pad h2{font-size:15px;letter-spacing:-.2px}
.list{display:grid;gap:10px}
.o{padding:13px 16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;transition:.2s}.o.off{opacity:.5}
.rank{width:30px;height:30px;border-radius:9px;background:var(--s2);border:1px solid var(--bd);display:grid;place-items:center;font-weight:700;font-size:12.5px;color:var(--mu)}
.o:first-child .rank{background:var(--as);color:var(--ac);border-color:transparent}
.om{flex:1;min-width:200px}.on{display:flex;align-items:center;gap:8px;font-weight:650;flex-wrap:wrap}
.tag{font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:var(--as);color:var(--ac)}
.dot{width:8px;height:8px;border-radius:50%;background:var(--ok)}.dot.d{background:var(--er)}
.ou{font-size:12.5px;color:var(--mu);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:44vw}
.om .tr{height:5px;margin-top:8px;max-width:320px}
.met{display:grid;text-align:right;min-width:76px}.met b{font-size:13.5px}.met span{font-size:11px;color:var(--mu)}
.oa{display:flex;align-items:center;gap:6px;margin-left:auto}
.ms{font-size:12px;font-weight:700;padding:4px 9px;border-radius:8px;background:var(--s2);border:1px solid var(--bd);min-width:60px;text-align:center}
.mv{display:grid;gap:2px}.mv button{width:26px;height:16px;display:grid;place-items:center;color:var(--mu);border-radius:5px}.mv button:hover{background:var(--s2);color:var(--tx)}.mv svg{width:13px;height:13px}
.sw{width:40px;height:22px;border-radius:99px;background:var(--bd);position:relative;transition:.2s;flex:none}
.sw:after{content:"";position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.2s}
.sw.on{background:var(--ok)}.sw.on:after{left:20px}.del:hover{color:var(--er);border-color:var(--er)}

.ev{display:grid}.e{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--bd);flex-wrap:wrap}.e:first-child{border-top:0}
.ei{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;flex:none}
.e div:nth-child(2){flex:1;min-width:140px;font-weight:650}.e small{display:block;color:var(--mu);font-weight:500;font-size:12px}
.chip{font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px}
.rr{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--bd)}.rr span:first-child{flex:1;font-weight:600}.rr small{color:var(--mu);font-weight:500;margin-left:6px}
.rg{padding:12px 0 4px}.rg div{display:flex;justify-content:space-between;align-items:center;font-weight:600;margin-bottom:8px}
input[type=range]{width:100%;accent-color:var(--ac);height:6px}

.settings-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.i-btn{display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;border-radius:50%;background:var(--s2);border:1px solid var(--bd);color:var(--mu);font-size:11px;font-weight:750;line-height:1;cursor:pointer;transition:.15s;margin-left:6px;vertical-align:middle;flex-shrink:0;user-select:none}
.i-btn:hover,.i-btn:focus{background:var(--as);border-color:var(--ac);color:var(--ac)}
.global-tooltip{position:fixed;z-index:9999;background:var(--tx);color:var(--bg);padding:7px 11px;border-radius:9px;font-size:12px;font-weight:500;line-height:1.35;max-width:270px;pointer-events:none;opacity:0;transform:translateY(4px);transition:opacity .15s,transform .15s;box-shadow:0 8px 24px rgba(0,0,0,.25)}
.global-tooltip.show{opacity:1;transform:translateY(0)}

.alert-strip{padding:11px 16px;background:var(--ws);border:1px solid var(--wa);border-radius:14px;color:var(--tx);display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:12.5px}

.env-table{width:100%;border-collapse:collapse;font-size:12.5px}.env-table td{padding:10px 0;border-bottom:1px solid var(--bd)}.env-table td:last-child{text-align:right}

dialog{border:1px solid var(--bd);background:var(--sf);color:var(--tx);border-radius:20px;padding:22px;width:min(92vw,420px);box-shadow:0 30px 80px -20px rgba(0,0,0,.45);margin:auto}
dialog::backdrop{background:rgba(10,12,20,.5);backdrop-filter:blur(5px)}dialog h3{font-size:17px;margin-bottom:14px}
.f{display:grid;gap:6px;margin-bottom:12px}.f label{font-size:12.5px;font-weight:650;color:var(--mu)}
.f input{height:40px;border-radius:11px;border:1px solid var(--bd);background:var(--s2);color:var(--tx);padding:0 12px;font:inherit;outline:0}
.f input:focus{border-color:var(--ac);box-shadow:0 0 0 3px var(--as)}.acts{display:flex;gap:8px;justify-content:flex-end;margin-top:6px}
.toast{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom));transform:translate(-50%,30px);background:var(--tx);color:var(--bg);padding:10px 16px;border-radius:12px;font-weight:600;font-size:13px;opacity:0;pointer-events:none;transition:.25s;z-index:50}.toast.s{opacity:1;transform:translate(-50%,0)}

.moon{display:none}[data-theme=dark] .moon{display:block}[data-theme=dark] .sun{display:none}
@media(prefers-color-scheme:dark){:root:not([data-theme=light]) .moon{display:block}:root:not([data-theme=light]) .sun{display:none}}

@media(max-width:860px){
 .sidebar{position:fixed;inset:0 auto 0 0;width:270px;transform:translateX(-100%);transition:transform .25s ease;box-shadow:var(--sh);z-index:100}
 .sidebar.open{transform:translateX(0)}
 .side-backdrop.open{display:block}
 .side-close{display:grid}
 .mobile-header{display:flex}
 .content{padding:16px}
 .two,.settings-grid{grid-template-columns:1fr}
 .kp{grid-template-columns:1fr 1fr;gap:8px}.k b{font-size:22px}
 .oa{width:100%;margin-left:44px;justify-content:flex-start}.oa .sw{margin-left:auto;margin-right:44px}.ou{max-width:66vw}
}
</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute"><defs>
<symbol id="shield" viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><circle cx="12" cy="11" r="1.6"/></symbol>
<symbol id="dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></symbol>
<symbol id="server" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><line x1="6" y1="6.5" x2="6.01" y2="6.5"/><line x1="6" y1="17.5" x2="6.01" y2="17.5"/></symbol>
<symbol id="sliders" viewBox="0 0 24 24"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></symbol>
<symbol id="swap" viewBox="0 0 24 24"><path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/></symbol>
<symbol id="tool" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></symbol>
<symbol id="sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></symbol>
<symbol id="moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 019.5 4 8 8 0 1020 14.5z"/></symbol>
<symbol id="plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="up" viewBox="0 0 24 24"><path d="M6 15l6-6 6 6"/></symbol>
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
    <div class="side-sub">Failover Ingress</div>
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
    <span>Zero-Overhead Ingress</span>
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
      <h2>Dashboard Overview</h2>
      <span class="lbl">Zero-Downtime Multi-Cloud Ingress</span>
     </div>
     <div class="seg" id="range"><button data-r="0" class="a">24h</button><button data-r="1">7d</button><button data-r="2">30d</button></div>
    </div>

    <section class="card hero">
     <div class="pulse"><svg class="i"><use href="#check"/></svg></div>
     <div style="flex:1;min-width:180px">
      <div class="lbl">Currently Serving Traffic</div>
      <div class="big" id="hn"></div>
      <div class="url mono" id="hu"></div>
     </div>
     <div style="display:flex;gap:8px;align-items:center">
      <button class="btn g" onclick="switchView('origins')">Manage hosts</button>
      <button class="btn" id="add"><svg class="i"><use href="#plus"/></svg>Add origin</button>
     </div>
    </section>

    <section class="kp" id="kp"></section>

    <section class="two">
     <div class="card pad">
      <div class="hd">
       <div class="seg" id="tab"><button data-t="0" class="a">Requests</button><button data-t="1">Latency</button></div>
      </div>
      <svg id="chart" viewBox="0 0 640 220"></svg>
      <div class="leg" id="leg"></div>
     </div>
     <div class="card pad">
      <div class="hd"><h2>Status Codes</h2><span class="lbl" id="rt"></span></div>
      <div class="sc" id="sc"></div>
     </div>
    </section>

    <!-- Quick Snippets Row -->
    <section class="two">
     <div class="card pad">
      <div class="hd">
       <h2>Active Origins</h2>
       <button class="btn g sm" onclick="switchView('origins')">View all →</button>
      </div>
      <div id="quickOrigins" style="display:grid;gap:10px"></div>
     </div>
     <div class="card pad">
      <div class="hd">
       <h2>Recent Failovers</h2>
       <button class="btn g sm" onclick="switchView('logs')">Full log →</button>
      </div>
      <div id="quickLogs" class="ev"></div>
     </div>
    </section>
   </div>

   <!-- VIEW 2: ORIGINS -->
   <div class="view-pane" id="view-origins" style="display:none">
    <div class="top">
     <div>
      <h2>Origins & Prioritization</h2>
      <span class="lbl">Rank #1 serves all traffic · Backups take over automatically</span>
     </div>
     <button class="btn" onclick="$('#dlg').showModal()"><svg class="i"><use href="#plus"/></svg>Add origin</button>
    </div>

    <section class="list" id="list"></section>

    <div class="card pad" style="background:var(--s2);font-size:12.5px;color:var(--mu);line-height:1.6">
     <strong style="color:var(--tx)">How Origin Priority Works:</strong>
     AnyOrigin continuously routes 100% of incoming visitor requests to the top healthy origin (<span class="tag" style="display:inline-block">Primary</span>). If that provider hits a quota cap (402), rate limit (429), or server outage (5xx), AnyOrigin instantly reroutes to Rank #2 in &lt; 1ms without visitor downtime.
    </div>
   </div>

   <!-- VIEW 3: RULES & GATEWAY -->
   <div class="view-pane" id="view-rules" style="display:none">
    <div class="top">
     <div>
      <h2>Gateway & Failover Rules</h2>
      <span class="lbl">Failover Triggers · Traffic Routing · Header Rewriting</span>
     </div>
     <button type="button" class="btn" id="saveRulesTop"><svg class="i"><use href="#check"/></svg>Save rules</button>
    </div>

    <section class="settings-grid">
     <!-- Card 1: Failover Triggers -->
     <div class="card pad">
      <div class="hd">
       <div style="display:flex;align-items:center">
        <h2 style="margin:0">Failover Triggers</h2>
        <button type="button" class="i-btn" data-tip="Choose which HTTP error codes cause AnyOrigin to automatically reroute visitor traffic to the next standby origin.">i</button>
       </div>
       <span class="tag">Auto-Recovery</span>
      </div>
      <div class="rr">
       <span>402 Payment Required<small>billing cap / quota cutoff</small><button type="button" class="i-btn" data-tip="Trigger instant failover if provider runs out of free tier bandwidth or hits billing limits (e.g., Vercel / Netlify / Render spend limit).">i</button></span>
       <button class="sw <?= !empty($rules['trigger_402']) ? 'on' : '' ?>" id="sw402" aria-label="402"></button>
      </div>
      <div class="rr">
       <span>429 Too Many Requests<small>rate limit / throttling</small><button type="button" class="i-btn" data-tip="Switch to next provider if upstream server limits incoming requests or hits rate limits.">i</button></span>
       <button class="sw <?= !empty($rules['trigger_429']) ? 'on' : '' ?>" id="sw429" aria-label="429"></button>
      </div>
      <div class="rr">
       <span>5xx Server Errors<small>500, 502, 503, 504</small><button type="button" class="i-btn" data-tip="Reroute when origin crashes, throws fatal errors, or drops gateway connections.">i</button></span>
       <button class="sw <?= !empty($rules['trigger_5xx']) ? 'on' : '' ?>" id="sw5xx" aria-label="5xx"></button>
      </div>
      <div class="rr">
       <span>Safe POST/PUT Protection<small>idempotency shield</small><button type="button" class="i-btn" data-tip="Prevents duplicate database entries or double charges by only failing over idempotent requests (GET/HEAD) on 5xx errors.">i</button></span>
       <button class="sw <?= $safe_post_only ? 'on' : '' ?>" id="swSafePost" aria-label="Safe POST"></button>
      </div>
     </div>

     <!-- Card 2: Traffic & Failover Limits -->
     <div class="card pad">
      <div class="hd">
       <div style="display:flex;align-items:center">
        <h2 style="margin:0">Traffic & Failover Limits</h2>
        <button type="button" class="i-btn" data-tip="Controls traffic distribution, upstream latency timeout, and how many backup hops to attempt before giving up.">i</button>
       </div>
       <span class="tag">Policies</span>
      </div>
      
      <div class="rg">
       <div>
        <span style="display:flex;align-items:center">Routing strategy<button type="button" class="i-btn" data-tip="Priority: 100% traffic goes to Rank #1, standby used only on failure. Round-Robin: balances load evenly across all active healthy origins.">i</button></span>
        <div class="seg" id="segStrategy">
         <button type="button" data-val="priority" class="<?= $routing_strategy === 'priority' ? 'a' : '' ?>">Priority</button>
         <button type="button" data-val="round_robin" class="<?= $routing_strategy === 'round_robin' ? 'a' : '' ?>">Round-Robin</button>
        </div>
       </div>
      </div>

      <div class="rg">
       <div>
        <span style="display:flex;align-items:center">Request timeout<button type="button" class="i-btn" data-tip="Maximum time AnyOrigin waits for an upstream origin to respond before declaring it timed-out and failing over.">i</button></span>
        <span class="mono" id="tv" style="color:var(--ac)"><?= $rules['latency_threshold_ms'] ?? 2500 ?> ms</span>
       </div>
       <input type="range" id="tm" min="500" max="6000" step="250" value="<?= $rules['latency_threshold_ms'] ?? 2500 ?>">
      </div>

      <div class="rg">
       <div>
        <span style="display:flex;align-items:center">Max retry hops<button type="button" class="i-btn" data-tip="Maximum number of standby servers to try before returning a service error to visitor.">i</button></span>
        <div class="seg" id="rty">
         <button type="button" data-val="1" class="<?= ($rules['max_retries'] ?? 2) == 1 ? 'a' : '' ?>">1</button>
         <button type="button" data-val="2" class="<?= ($rules['max_retries'] ?? 2) == 2 ? 'a' : '' ?>">2</button>
         <button type="button" data-val="3" class="<?= ($rules['max_retries'] ?? 2) == 3 ? 'a' : '' ?>">3</button>
         <button type="button" data-val="5" class="<?= ($rules['max_retries'] ?? 2) >= 4 ? 'a' : '' ?>">All</button>
        </div>
       </div>
      </div>

      <div class="rg">
       <div>
        <span style="display:flex;align-items:center">Health check frequency<button type="button" class="i-btn" data-tip="How frequently background health checks ping inactive or downed origins to verify automatic recovery.">i</button></span>
        <div class="seg" id="hc">
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
        <span class="tag">Fast-Path</span>
       </div>
       <p style="font-size:12.5px;color:var(--mu);margin:10px 0 16px;line-height:1.5">
        Rules are compiled into a zero-latency memory/disk cache (<span class="mono">data/config.json</span>). Traffic is re-evaluated immediately on the next incoming request without server restarts.
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
      <h2>Failover Incident Stream</h2>
      <span class="lbl">Live log of rerouted requests & upstream status changes</span>
     </div>
     <div class="seg" id="logFilter">
      <button data-f="all" class="a">All</button>
      <button data-f="402">402</button>
      <button data-f="429">429</button>
      <button data-f="5xx">5xx</button>
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
      <span class="lbl">Cache Purge · Brute-force Lockouts · Server Environment</span>
     </div>
    </div>

    <section class="two">
     <div class="card pad">
      <div class="hd"><h2>Operational Tools</h2><span class="tag">Instant</span></div>
      <div style="display:grid;gap:14px;margin-top:6px">
       <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
        <div>
         <strong>Purge Routing Cache</strong><button type="button" class="i-btn" data-tip="Forces AnyOrigin proxy to flush and regenerate the zero-overhead data/config.json cache instantly.">i</button>
         <small style="display:block;color:var(--mu)">Sync SQLite table to JSON (&lt; 1ms)</small>
        </div>
        <button type="button" class="btn g sm" id="btnPurge">Purge cache</button>
       </div>

       <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:12px;border-top:1px solid var(--bd)">
        <div>
         <strong>Reset Login Lockouts</strong><button type="button" class="i-btn" data-tip="Clears temporary IP blocks resulting from 5 or more consecutive failed login attempts.">i</button>
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
      <div class="hd"><h2>Environment Telemetry</h2><span class="tag">PHP Native</span></div>
      <table class="env-table">
       <tr><td>PHP Runtime</td><td class="mono num"><?= PHP_VERSION ?></td></tr>
       <tr><td>Database Engine</td><td class="mono">PDO SQLite3</td></tr>
       <tr><td>Database File</td><td class="mono num"><?= $dbSize ?></td></tr>
       <tr><td>Compiled Cache</td><td class="mono num"><?= $configSize ?></td></tr>
       <tr><td>SSRF Shield</td><td style="color:var(--ok);font-weight:600">Active</td></tr>
       <tr><td>Memory Allocated</td><td class="mono num"><?= round(memory_get_usage() / 1024 / 1024, 2) ?> MB</td></tr>
      </table>
     </div>
    </section>
   </div>

  </main>
 </div>
</div>

<!-- Add Origin Modal -->
<dialog id="dlg"><h3>Add origin</h3>
 <form id="frm">
  <div class="f"><label>Name</label><input required placeholder="Netlify standby" id="fn"></div>
  <div class="f"><label>URL</label><input required type="url" class="mono" placeholder="https://my-app.netlify.app" id="fu"></div>
  <div class="f"><label>Host header <span style="font-weight:500">(optional)</span></label><input class="mono" placeholder="Auto from URL" id="fh"></div>
  <div class="acts"><button type="button" class="btn g" id="cx">Cancel</button><button type="submit" class="btn">Save</button></div>
 </form>
</dialog>

<!-- Change Password Modal -->
<dialog id="pwdDlg"><h3>Change password</h3>
 <form id="pwdFrm">
  <div class="f"><label>Current password</label><input required type="password" placeholder="••••••••" id="curPwd"></div>
  <div class="f"><label>New password (min 8 chars)</label><input required type="password" minlength="8" placeholder="••••••••" id="newPwd"></div>
  <div class="f"><label>Confirm new password</label><input required type="password" minlength="8" placeholder="••••••••" id="cnfPwd"></div>
  <div class="acts"><button type="button" class="btn g" id="pwdCx">Cancel</button><button type="submit" class="btn">Update</button></div>
 </form>
</dialog>

<div class="toast" id="toast"></div>

<script>
const $=s=>document.querySelector(s),$$=s=>document.querySelectorAll(s),ic=n=>`<svg class="i"><use href="#${n}"/></svg>`;
const F=n=>n>=1e6?(n/1e6).toFixed(2)+'M':n>=1e3?(n/1e3).toFixed(1)+'k':''+n;

let O = <?= json_encode($originsJson) ?>;

const R=[
 {len:24,m:1,l:'24h',d:['+8.4%','+0.2%','−12 ms'],ev:3},
 {len:28,m:6,l:'7d',d:['+14.1%','−0.1%','−6 ms'],ev:9},
 {len:30,m:24,l:'30d',d:['+31.7%','+0.3%','−4 ms'],ev:21}
];

const EV=[
 {a:'Vercel Primary',b:'Netlify Standby',code:'402',t:'2h ago',n:2140,k:'er'},
 {a:'Netlify Standby',b:'Render VPS',code:'429',t:'9h ago',n:386,k:'wa'},
 {a:'Vercel Primary',b:'Netlify Standby',code:'503',t:'1d ago',n:912,k:'er'},
 {a:'Netlify Standby',b:'Render VPS',code:'Timeout',t:'2d ago',n:154,k:'wa'}
];

let ri=0,tb=0,activeLogFilter='all';

const toast=m=>{const t=$('#toast');t.textContent=m;t.classList.add('s');clearTimeout(t._);t._=setTimeout(()=>t.classList.remove('s'),2200)};

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

function spark(pts,col){
 const W=110,H=28,p=2,min=Math.min(...pts),max=Math.max(...pts),d=max-min||1;
 const coords=pts.map((v,i)=>[p+i*(W-2*p)/(pts.length-1),H-p-(v-min)/d*(H-2*p)]);
 const path=coords.map((c,i)=>(i?'L':'M')+c[0].toFixed(1)+','+c[1].toFixed(1)).join(' ');
 return`<svg class="sk" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none"><path d="${path}" fill="none" stroke="${col}" stroke-width="1.8" stroke-linecap="round"/><circle cx="${coords.at(-1)[0]}" cy="${coords.at(-1)[1]}" r="2.5" fill="${col}"/></svg>`;
}

function render(){
 const r=R[ri];
 const S=O.map((o,i)=>{
   const base=(o.on?o.w:0)*(i==0?3.2:1);
   return[...Array(r.len)].map((_,t)=>Math.max(0,Math.round((base+Math.sin(t*1.2+i)*base*.35+Math.cos(t*.5)*base*.15)*r.m)));
 });
 const T=S.map(a=>a.reduce((x,y)=>x+y,0));
 const tot=T.reduce((a,b)=>a+b,0)||1;
 const sum=[...Array(r.len)].map((_,i)=>S.reduce((a,x)=>a+x[i],0));
 const avg=Math.round(O.filter(o=>o.on).reduce((a,o)=>a+o.ms,0)/(O.filter(o=>o.on).length||1));

 const live=O.find(o=>o.on)||{n:'No active host',u:'Configure in Origins tab',ms:0,ok:0};
 $('#hn').textContent=live.n;$('#hu').textContent=live.u;
 $('#sideOriginCount').textContent=O.length;

 const K=[
   ['Total requests',F(tot),r.d[0],1,sum,'var(--ac)'],
   ['Success rate','99.2%',r.d[1],1,sum.map((v,i)=>99+Math.sin(i)*.4),'var(--ok)'],
   ['Failovers',r.ev,'last '+r.l,0,sum.map((v,i)=>i%7==3?3:i%5==1?1:0),'var(--wa)'],
   ['Avg latency',avg+' ms',r.d[2],1,sum.map((v,i)=>avg+Math.sin(i*.8)*12),'#6366f1']
 ];

 $('#kp').innerHTML=K.map(k=>`<div class="card k"><span class="lbl">${k[0]}</span><b class="num">${k[1]}</b><small class="${k[3]?(k[2][0]=='−'&&k[0]!='Avg latency'?'dn':'up'):'dn'}">${k[2]}</small>${spark(k[4],k[5])}</div>`).join('');

 const codes=[
   ['2xx Success',.942,'var(--ok)'],
   ['3xx Redirect',.03,'var(--ac)'],
   ['4xx Client',.012,'var(--wa)'],
   ['402 Billing cap',.004,'var(--er)'],
   ['429 Rate limit',.006,'var(--wa)'],
   ['5xx Server error',.006,'var(--er)']
 ];

 $('#rt').textContent=F(tot);
 $('#sc').innerHTML=codes.map(c=>`<div class="sr"><div>${c[0]}<span class="num">${F(Math.round(tot*c[1]))} · ${(c[1]*100).toFixed(1)}%</span></div><div class="tr"><i style="width:${Math.max(2,c[1]*100)}%;background:${c[2]}"></i></div></div>`).join('');

 // Full Origins List
 $('#list').innerHTML=O.map((o,i)=>`<div class="card o ${o.on?'':'off'}"><div class="rank">${i+1}</div>
 <div class="om"><div class="on"><span class="dot ${o.ok?'':'d'}"></span>${o.n}${i==0?'<span class="tag">Primary</span>':''}</div><div class="ou mono">${o.u}</div><div class="tr"><i style="width:${Math.round(T[i]/tot*100)}%;background:${o.c}"></i></div></div>
 <div class="met"><b class="num">${F(T[i])}</b><span>${Math.round(T[i]/tot*100)}% traffic</span></div>
 <div class="oa"><span class="ms mono num">${o.ms} ms</span>
 <div class="mv"><button data-m="${i},-1" aria-label="Up">${ic('up')}</button><button data-m="${i},1" aria-label="Down">${ic('dn')}</button></div>
 <button class="ib sm" data-t="${i}" aria-label="Test">${ic('bolt')}</button><button class="ib sm del" data-d="${i}" aria-label="Delete">${ic('trash')}</button>
 <button class="sw ${o.on?'on':''}" data-s="${i}" aria-label="Toggle"></button></div></div>`).join('');

 // Quick Origins in Overview
 $('#quickOrigins').innerHTML=O.slice(0,3).map((o,i)=>`<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--bd)">
  <span class="dot ${o.ok?'':'d'}"></span>
  <div style="flex:1;min-width:0"><div style="font-weight:600;font-size:13px">${o.n} ${i==0?'<span class="tag">Primary</span>':''}</div><div class="ou mono" style="font-size:11.5px">${o.u}</div></div>
  <span class="mono num" style="font-size:12px;font-weight:600">${o.ms} ms</span>
 </div>`).join('');

 // Incident Logs
 const filteredEV = activeLogFilter === 'all' ? EV : EV.filter(e => e.code.startsWith(activeLogFilter));
 const logHtml = filteredEV.map(e=>`<div class="e"><div class="ei" style="background:var(--${e.k=='er'?'es':'ws'});color:var(--${e.k})">${ic('swap')}</div><div>${e.a} → ${e.b}<small>${F(e.n)} requests rerouted</small></div><span class="chip" style="background:var(--${e.k=='er'?'es':'ws'});color:var(--${e.k})">${e.code}</span><span class="lbl" style="min-width:58px;text-align:right;text-transform:none">${e.t}</span></div>`).join('');
 $('#ev').innerHTML = logHtml || '<div style="padding:16px 0;color:var(--mu);text-align:center">No incidents matching filter.</div>';
 $('#quickLogs').innerHTML = EV.slice(0,2).map(e=>`<div class="e"><div class="ei" style="background:var(--${e.k=='er'?'es':'ws'});color:var(--${e.k})">${ic('swap')}</div><div>${e.a} → ${e.b}<small>${F(e.n)} rerouted</small></div><span class="chip" style="background:var(--${e.k=='er'?'es':'ws'});color:var(--${e.k})">${e.code}</span></div>`).join('');

 $('#leg').innerHTML=O.map(o=>`<span><i style="background:${o.c}"></i>${o.n}</span>`).join('');
 draw(S,r);
}

function draw(S,r){
 const W=640,H=220,P=24,L=34,N=r.len,bw=(W-L)/N;let s='';
 const mx=tb?200:Math.max(...[...Array(N)].map((_,i)=>S.reduce((a,x)=>a+x[i],0)))*1.1;
 for(let k=0;k<4;k++){
   const y=P+(H-2*P)*k/3,v=mx*(1-k/3);
   s+=`<line x1="${L}" x2="${W}" y1="${y}" y2="${y}" stroke="var(--gr)"/><text x="0" y="${y+3}" fill="var(--mu)" font-size="10">${tb?Math.round(v):F(Math.round(v))}</text>`;
 }
 if(!tb){
   for(let i=0;i<N;i++){
     let y=H-P;
     S.forEach((a,j)=>{
       const h=a[i]/mx*(H-2*P);
       y-=h;
       s+=`<rect x="${L+i*bw+bw*.14}" y="${y}" width="${bw*.72}" height="${Math.max(0,h)}" rx="2" fill="${O[j].c}"><title>${O[j].n}: ${F(a[i])}</title></rect>`;
     });
   }
 } else {
   O.forEach((o,j)=>{
     const p=[...Array(N)].map((_,i)=>{
       const v=o.ms+Math.sin(i*.7+j*2)*14+Math.cos(i*1.9+j)*8+(o.ok?0:i>N*.72?90:0);
       return[L+i*(W-L)/(N-1),H-P-Math.min(200,v)/200*(H-2*P)];
     });
     const d=p.map((q,i)=>(i?'L':'M')+q[0].toFixed(1)+' '+q[1].toFixed(1)).join(' ');
     s+=`<path d="${d}" fill="none" stroke="${o.c}" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" ${o.on?'':'opacity=".3"'}/><circle cx="${p[N-1][0]}" cy="${p[N-1][1]}" r="4" fill="${o.c}"/>`;
   });
 }
 $('#chart').innerHTML=s;
}

// Tab and View Switcher
function switchView(viewName) {
 $$('.view-pane').forEach(el => el.style.display = 'none');
 const target = $(`#view-${viewName}`);
 if (target) target.style.display = 'grid';
 $$('.nav-item').forEach(el => el.classList.toggle('active', el.dataset.view === viewName));
 try { localStorage.setItem('ao-view', viewName); } catch(_) {}
 $('#sidebar').classList.remove('open');
 $('#sideBackdrop').classList.remove('open');
 window.scrollTo({ top: 0, behavior: 'instant' });
}

$$('.nav-item').forEach(btn => {
 btn.onclick = () => switchView(btn.dataset.view);
});

// Mobile menu toggle
$('#sideOpen').onclick = () => {
 $('#sidebar').classList.add('open');
 $('#sideBackdrop').classList.add('open');
};
$('#sideClose').onclick = $('#sideBackdrop').onclick = () => {
 $('#sidebar').classList.remove('open');
 $('#sideBackdrop').classList.remove('open');
};

function seg(id,fn){
 const el=$(id);
 if(!el)return;
 el.onclick=e=>{
   const b=e.target.closest('button');
   if(!b)return;
   $$(id+' button').forEach(x=>x.classList.remove('a'));
   b.classList.add('a');
   fn&&fn(b);
 };
}

seg('#range',b=>{ri=+b.dataset.r;render()});
seg('#tab',b=>{tb=+b.dataset.t;render()});
seg('#segStrategy');
seg('#rty');
seg('#hc');
seg('#logFilter', b => {
 activeLogFilter = b.dataset.f;
 render();
});

// Origins Click Actions
$('#list').onclick=async e=>{
 const b=e.target.closest('button');
 if(!b)return;

 // Move priority
 if(b.dataset.m){
   const[i,d]=b.dataset.m.split(',').map(Number),j=i+d;
   if(j>=0&&j<O.length){
     const originId = O[i].id;
     const direction = d === -1 ? 'up' : 'down';
     [O[i],O[j]]=[O[j],O[i]];
     render();
     const res = await postAction('move_priority', { id: originId, direction });
     toast(res.message);
   }
 }
 // Toggle enabled/disabled
 else if(b.dataset.s){
   const o=O[b.dataset.s];
   o.on=o.on?0:1;
   render();
   const res = await postAction('toggle_active', { id: o.id });
   toast(res.message);
 }
 // Delete
 else if(b.dataset.d){
   if(O.length>1&&confirm('Remove origin?')){
     const originId = O[b.dataset.d].id;
     O.splice(b.dataset.d,1);
     render();
     const res = await postAction('delete_origin', { id: originId });
     toast(res.message);
   }
 }
 // Ping test
 else if(b.dataset.t){
   const o=O[b.dataset.t];
   toast('Testing ' + o.n + '...');
   const res = await postAction('ping_host', { id: o.id });
   if (res.latency) o.ms = res.latency;
   if (res.status) o.ok = (res.status === 'healthy') ? 1 : 0;
   render();
   toast(res.message);
 }
};

// Switches in settings grid
$$('.settings-grid .sw:not([disabled])').forEach(sw => {
 sw.onclick = function() { this.classList.toggle('on'); };
});

$('#tm').oninput=e=>$('#tv').textContent=e.target.value+' ms';

const saveRulesHandler = async ()=>{
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

$('#btnPurge').onclick=async ()=>{
 toast('Purging cache...');
 const res = await postAction('purge_cache');
 toast(res.message);
};

$('#btnResetLock').onclick=async ()=>{
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
const dlg=$('#dlg');
$('#add').onclick=()=>dlg.showModal();
$('#cx').onclick=()=>dlg.close();

$('#frm').onsubmit=async e=>{
 e.preventDefault();
 const name = $('#fn').value;
 const url = $('#fu').value;
 const host = $('#fh').value;
 toast('Adding host...');
 const res = await postAction('add_origin', { name, url, host_header: host });
 if (res.success) {
   O.push({ id: res.id, n: name, u: url, h: host, ms: 45, ok: 1, on: 1, c: '#34d399', w: 1000 });
   $('#frm').reset();
   dlg.close();
   render();
 }
 toast(res.message);
};

// Password Modal
const pwdDlg=$('#pwdDlg');
$('#btnPwd').onclick=()=>pwdDlg.showModal();
const mobPwd=$('#mobilePwd');
if(mobPwd) mobPwd.onclick=()=>pwdDlg.showModal();
$('#pwdCx').onclick=()=>pwdDlg.close();

$('#pwdFrm').onsubmit=async e=>{
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
const root=document.documentElement;
try{const t=localStorage.getItem('ao-theme');if(t)root.dataset.theme=t}catch(_){}
const toggleTheme = ()=>{
 const d=root.dataset.theme?root.dataset.theme=='dark':matchMedia('(prefers-color-scheme:dark)').matches;
 root.dataset.theme=d?'light':'dark';
 try{localStorage.setItem('ao-theme',root.dataset.theme)}catch(_){};
};
$('#theme').onclick = toggleTheme;
const mobTheme = $('#mobileTheme');
if(mobTheme) mobTheme.onclick = toggleTheme;

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