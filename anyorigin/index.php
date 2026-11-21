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
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("UPDATE failover_rules SET trigger_402 = ?, trigger_429 = ?, trigger_5xx = ?, latency_threshold_ms = ?, updated_at = ? WHERE site_id = 'default-site'");
        $stmt->execute([$trigger_402, $trigger_429, $trigger_5xx, $timeout_ms, $now]);

        syncConfigCache($pdo);
        $respond(true, "Failover rules saved");
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
];

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>AnyOrigin - Console</title>
<style>
:root{--bg:#f6f5f1;--sf:#fff;--s2:#faf9f6;--bd:#e7e4dd;--tx:#1b1a17;--mu:#78746b;--ac:#0a84d6;--ax:#fff;--as:#e6f3fc;--ok:#16a34a;--os:#e5f6ea;--er:#e11d48;--es:#fde8ed;--wa:#d97706;--ws:#fdf0dc;--sh:0 1px 2px rgba(30,25,10,.04),0 8px 24px -12px rgba(30,25,10,.12);--gr:#ece9e2}
[data-theme=dark]{--bg:#0a0e17;--sf:#121826;--s2:#0e1420;--bd:#222b3e;--tx:#e9edf5;--mu:#8a94a8;--ac:#38bdf8;--ax:#06121c;--as:#10283a;--ok:#34d399;--os:#0f2a22;--er:#fb7185;--es:#33141c;--wa:#fbbf24;--ws:#33270f;--sh:0 1px 2px rgba(0,0,0,.3),0 12px 28px -14px rgba(0,0,0,.6);--gr:#1b2334}
@media(prefers-color-scheme:dark){:root:not([data-theme=light]){--bg:#0a0e17;--sf:#121826;--s2:#0e1420;--bd:#222b3e;--tx:#e9edf5;--mu:#8a94a8;--ac:#38bdf8;--ax:#06121c;--as:#10283a;--ok:#34d399;--os:#0f2a22;--er:#fb7185;--es:#33141c;--wa:#fbbf24;--ws:#33270f;--sh:0 1px 2px rgba(0,0,0,.3),0 12px 28px -14px rgba(0,0,0,.6);--gr:#1b2334}}
*{box-sizing:border-box;margin:0}
body{background:var(--bg);color:var(--tx);font:14px/1.45 Inter,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased;padding-bottom:env(safe-area-inset-bottom)}
svg.i{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
button{font:inherit;color:inherit;cursor:pointer;border:0;background:none}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.num{font-variant-numeric:tabular-nums}
header{position:sticky;top:0;z-index:20;background:color-mix(in srgb,var(--bg) 82%,transparent);backdrop-filter:blur(14px);border-bottom:1px solid var(--bd);padding-top:env(safe-area-inset-top)}
.bar{max-width:1080px;margin:auto;padding:12px 20px;display:flex;align-items:center;gap:10px}
.logo{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#38bdf8,#6366f1);display:grid;place-items:center;color:#fff}
.brand{font-weight:750;font-size:16px;letter-spacing:-.3px}.sp{flex:1}
.ib{width:38px;height:38px;border-radius:11px;border:1px solid var(--bd);background:var(--sf);display:grid;place-items:center;color:var(--mu);transition:.15s}
.ib:hover{color:var(--tx);border-color:var(--mu)}
.moon{display:none}[data-theme=dark] .moon{display:block}[data-theme=dark] .sun{display:none}
@media(prefers-color-scheme:dark){:root:not([data-theme=light]) .moon{display:block}:root:not([data-theme=light]) .sun{display:none}}
main{max-width:1080px;margin:auto;padding:20px;display:grid;gap:16px}
.card{background:var(--sf);border:1px solid var(--bd);border-radius:18px;box-shadow:var(--sh)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:40px;padding:0 16px;border-radius:11px;font-weight:650;background:var(--ac);color:var(--ax);transition:.15s}
.btn:hover{filter:brightness(1.07)}.btn:active{transform:scale(.98)}.btn.g{background:var(--s2);color:var(--tx);border:1px solid var(--bd)}
.seg{display:inline-flex;padding:3px;background:var(--s2);border:1px solid var(--bd);border-radius:11px;gap:2px}
.seg button{padding:5px 12px;border-radius:8px;font-weight:650;font-size:12.5px;color:var(--mu)}
.seg button.a{background:var(--sf);color:var(--tx);box-shadow:0 1px 3px rgba(0,0,0,.12)}
.lbl{font-size:12px;color:var(--mu);font-weight:600;letter-spacing:.4px;text-transform:uppercase}
.top{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.hero{padding:18px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.pulse{width:46px;height:46px;border-radius:14px;background:var(--os);color:var(--ok);display:grid;place-items:center;position:relative}
.pulse:after{content:"";position:absolute;inset:0;border-radius:inherit;border:2px solid var(--ok);opacity:.5;animation:p 2s infinite}
@keyframes p{to{transform:scale(1.35);opacity:0}}
.big{font-size:22px;font-weight:750;letter-spacing:-.4px}.url{font-size:12.5px;color:var(--mu)}
.kp{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.k{padding:16px 16px 14px;display:grid;gap:2px;overflow:hidden}
.k b{font-size:27px;letter-spacing:-.9px;line-height:1.15}.k small{font-size:12px;font-weight:650}
.k .sk{height:30px;margin-top:6px}.up{color:var(--ok)}.dn{color:var(--er)}
.two{display:grid;grid-template-columns:1.8fr 1fr;gap:16px}
.pad{padding:18px}
.hd{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.leg{display:flex;gap:12px;font-size:12px;color:var(--mu);font-weight:600;flex-wrap:wrap;margin-top:8px}
.leg i,.srow i{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px}
#chart{width:100%;height:auto;display:block}
.sc{display:grid;gap:13px}
.sr{display:grid;gap:6px}.sr div:first-child{display:flex;justify-content:space-between;font-weight:600;font-size:13px}.sr span{color:var(--mu);font-weight:500}
.tr{height:7px;background:var(--s2);border-radius:9px;overflow:hidden;border:1px solid var(--bd)}.tr i{display:block;height:100%;border-radius:9px}
.sec{display:flex;align-items:center;justify-content:space-between;padding:2px}.sec h2,.pad h2{font-size:15px;letter-spacing:-.2px}
.list{display:grid;gap:10px}
.o{padding:14px 16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;transition:.2s}.o.off{opacity:.5}
.rank{width:30px;height:30px;border-radius:9px;background:var(--s2);border:1px solid var(--bd);display:grid;place-items:center;font-weight:700;font-size:12.5px;color:var(--mu)}
.o:first-child .rank{background:var(--as);color:var(--ac);border-color:transparent}
.om{flex:1;min-width:200px}.on{display:flex;align-items:center;gap:8px;font-weight:650;flex-wrap:wrap}
.tag{font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:var(--as);color:var(--ac)}
.dot{width:8px;height:8px;border-radius:50%;background:var(--ok)}.dot.d{background:var(--er)}
.ou{font-size:12.5px;color:var(--mu);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:46vw}
.om .tr{height:5px;margin-top:8px;max-width:340px}
.met{display:grid;text-align:right;min-width:78px}.met b{font-size:14px}.met span{font-size:11.5px;color:var(--mu)}
.oa{display:flex;align-items:center;gap:6px;margin-left:auto}
.ms{font-size:12.5px;font-weight:700;padding:5px 10px;border-radius:9px;background:var(--s2);border:1px solid var(--bd);min-width:62px;text-align:center}
.sm{width:34px;height:34px;border-radius:10px}
.mv{display:grid;gap:2px}.mv button{width:26px;height:17px;display:grid;place-items:center;color:var(--mu);border-radius:6px}.mv button:hover{background:var(--s2);color:var(--tx)}.mv svg{width:14px;height:14px}
.sw{width:42px;height:24px;border-radius:99px;background:var(--bd);position:relative;transition:.2s;flex:none}
.sw:after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.2s}
.sw.on{background:var(--ok)}.sw.on:after{left:21px}.del:hover{color:var(--er);border-color:var(--er)}
.ev{display:grid}.e{display:flex;align-items:center;gap:12px;padding:12px 0;border-top:1px solid var(--bd);flex-wrap:wrap}.e:first-child{border-top:0}
.ei{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex:none}
.e div:nth-child(2){flex:1;min-width:150px;font-weight:650}.e small{display:block;color:var(--mu);font-weight:500;font-size:12px}
.chip{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:99px}
.rr{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--bd)}.rr span:first-child{flex:1;font-weight:600}.rr small{color:var(--mu);font-weight:500;margin-left:6px}
.rg{padding:14px 0 4px}.rg div{display:flex;justify-content:space-between;align-items:center;font-weight:600;margin-bottom:10px}
input[type=range]{width:100%;accent-color:var(--ac);height:6px}
dialog{border:1px solid var(--bd);background:var(--sf);color:var(--tx);border-radius:20px;padding:22px;width:min(92vw,420px);box-shadow:0 30px 80px -20px rgba(0,0,0,.45);margin:auto}
dialog::backdrop{background:rgba(10,12,20,.5);backdrop-filter:blur(5px)}dialog h3{font-size:17px;margin-bottom:14px}
.f{display:grid;gap:6px;margin-bottom:12px}.f label{font-size:12.5px;font-weight:650;color:var(--mu)}
.f input{height:42px;border-radius:11px;border:1px solid var(--bd);background:var(--s2);color:var(--tx);padding:0 12px;font:inherit;outline:0}
.f input:focus{border-color:var(--ac);box-shadow:0 0 0 3px var(--as)}.acts{display:flex;gap:8px;justify-content:flex-end;margin-top:6px}
.toast{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom));transform:translate(-50%,30px);background:var(--tx);color:var(--bg);padding:11px 18px;border-radius:12px;font-weight:600;opacity:0;pointer-events:none;transition:.25s;z-index:50}.toast.s{opacity:1;transform:translate(-50%,0)}
.three{display:grid;grid-template-columns:1.2fr 1fr;gap:16px}
.alert-strip{padding:12px 18px;background:var(--ws);border:1px solid var(--wa);border-radius:14px;color:var(--tx);display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:12.5px}
@media(max-width:820px){.bar,main{padding-left:14px;padding-right:14px}.two,.three{grid-template-columns:1fr}.kp{grid-template-columns:1fr 1fr;gap:8px}.k b{font-size:22px}.hero .btn{width:100%}
.oa{width:100%;margin-left:44px;justify-content:flex-start}.oa .sw{margin-left:auto;margin-right:44px}.ou{max-width:66vw}.met{text-align:left;min-width:64px}.om{min-width:150px}}
</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute"><defs>
<symbol id="shield" viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><circle cx="12" cy="11" r="1.6"/></symbol>
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
<symbol id="swap" viewBox="0 0 24 24"><path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/></symbol>
</defs></svg>

<header><div class="bar">
 <div class="logo"><svg class="i"><use href="#shield"/></svg></div><span class="brand">AnyOrigin</span><span class="sp"></span>
 <button class="ib" id="theme" aria-label="Theme"><svg class="i sun"><use href="#sun"/></svg><svg class="i moon"><use href="#moon"/></svg></button>
 <button class="ib" id="btnPwd" aria-label="Password"><svg class="i"><use href="#key"/></svg></button>
 <a href="login.php?logout=1" class="ib" aria-label="Logout"><svg class="i"><use href="#out"/></svg></a>
</div></header>

<main>
 <?php if ($usingDefaultPwd): ?>
 <div class="alert-strip">
  <div style="display:flex;align-items:center;gap:8px">
   <svg class="i" style="color:var(--wa)"><use href="#bolt"/></svg>
   <span><strong>Security alert:</strong> You are currently using the default password. Update it now.</span>
  </div>
  <button class="btn g" style="height:32px;padding:0 12px;font-size:12px" onclick="$('#pwdDlg').showModal()">Change password</button>
 </div>
 <?php endif; ?>

 <div class="top"><div class="sec" style="flex:1;padding:0"><h2 style="font-size:20px;letter-spacing:-.4px">Overview</h2></div>
  <div class="seg" id="range"><button data-r="0" class="a">24h</button><button data-r="1">7d</button><button data-r="2">30d</button></div></div>

 <section class="card hero">
  <div class="pulse"><svg class="i"><use href="#check"/></svg></div>
  <div style="flex:1;min-width:180px"><div class="lbl">Serving now</div><div class="big" id="hn"></div><div class="url mono" id="hu"></div></div>
  <button class="btn" id="add"><svg class="i"><use href="#plus"/></svg>Add origin</button>
 </section>

 <section class="kp" id="kp"></section>

 <section class="two">
  <div class="card pad">
   <div class="hd"><div class="seg" id="tab"><button data-t="0" class="a">Requests</button><button data-t="1">Latency</button></div></div>
   <svg id="chart" viewBox="0 0 640 220"></svg><div class="leg" id="leg"></div>
  </div>
  <div class="card pad"><div class="hd"><h2>Responses</h2><span class="lbl" id="rt"></span></div><div class="sc" id="sc"></div></div>
 </section>

 <div class="sec"><h2>Origins</h2><span class="lbl" id="cnt"></span></div>
 <section class="list" id="list"></section>

 <section class="three">
  <div class="card pad"><div class="hd"><h2>Failover log</h2><span class="lbl">Recent</span></div><div class="ev" id="ev"></div></div>
  <div class="card pad">
   <h2 style="margin-bottom:4px">Rules</h2>
   <div class="rr"><span>402<small>billing cap</small></span><button class="sw <?= !empty($rules['trigger_402']) ? 'on' : '' ?>" id="sw402" aria-label="402"></button></div>
   <div class="rr"><span>429<small>rate limit</small></span><button class="sw <?= !empty($rules['trigger_429']) ? 'on' : '' ?>" id="sw429" aria-label="429"></button></div>
   <div class="rr"><span>5xx<small>server error</small></span><button class="sw <?= !empty($rules['trigger_5xx']) ? 'on' : '' ?>" id="sw5xx" aria-label="5xx"></button></div>
   <div class="rg"><div><span>Timeout</span><span class="mono" id="tv" style="color:var(--ac)"><?= $rules['latency_threshold_ms'] ?? 2500 ?> ms</span></div><input type="range" id="tm" min="500" max="6000" step="250" value="<?= $rules['latency_threshold_ms'] ?? 2500 ?>"></div>
   <div class="rg"><div><span>Retries</span><div class="seg" id="rty"><button>1</button><button class="a">2</button><button>3</button></div></div></div>
   <div class="rg"><div><span>Health check</span><div class="seg" id="hc"><button>30s</button><button class="a">1m</button><button>5m</button></div></div></div>
   <button class="btn" id="saveRules" style="width:100%;margin-top:6px">Save rules</button>
  </div>
 </section>
</main>

<dialog id="dlg"><h3>Add origin</h3>
 <form id="frm">
  <div class="f"><label>Name</label><input required placeholder="Netlify standby" id="fn"></div>
  <div class="f"><label>URL</label><input required type="url" class="mono" placeholder="https://my-app.netlify.app" id="fu"></div>
  <div class="f"><label>Host header <span style="font-weight:500">(optional)</span></label><input class="mono" placeholder="Auto from URL" id="fh"></div>
  <div class="acts"><button type="button" class="btn g" id="cx">Cancel</button><button type="submit" class="btn">Save</button></div>
 </form>
</dialog>

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

const R=[{len:24,m:1,l:'24h',d:['+8.4%','+0.2%','−12 ms'],ev:3},{len:28,m:6,l:'7d',d:['+14.1%','−0.1%','−6 ms'],ev:9},{len:30,m:24,l:'30d',d:['+31.7%','+0.3%','−4 ms'],ev:21}];
const EV=[
 {a:'Vercel',b:'Netlify',code:'402',t:'2h ago',n:2140,k:'er'},
 {a:'Netlify',b:'Render VPS',code:'429',t:'9h ago',n:386,k:'wa'},
 {a:'Vercel',b:'Netlify',code:'503',t:'1d ago',n:912,k:'er'},
 {a:'Netlify',b:'Render VPS',code:'Timeout',t:'2d ago',n:154,k:'wa'}
];

let ri=0,tb=0;
const toast=m=>{const t=$('#toast');t.textContent=m;t.classList.add('s');clearTimeout(t._);t._=setTimeout(()=>t.classList.remove('s'),2200)};

async function postAction(action, data = {}) {
  const form = new FormData();
  form.append('action', action);
  form.append('ajax', '1');
  for (const k in data) form.append(k, data[k]);
  try {
    const res = await fetch('/anyorigin/index.php', { method: 'POST', body: form });
    return await res.json();
  } catch (err) {
    return { success: false, message: 'Network error' };
  }
}

function ser(o,j,r){
  return [...Array(r.len)].map((_,i)=>{
    let v=(o.w||1000)*r.m*(1+Math.sin(i*.6+j*2)*.22+Math.cos(i*1.7+j)*.1);
    if(!o.ok&&i>r.len*.72)v*=.04;
    if(j==0&&O.some(x=>!x.ok)&&i>r.len*.72)v*=1.5;
    return o.on?Math.round(v):0;
  });
}

function spark(v,c){
  const mx=Math.max(...v),mn=Math.min(...v),p=v.map((y,i)=>(i*90/(v.length-1)).toFixed(1)+','+(28-(y-mn)/(mx-mn||1)*24-2).toFixed(1)).join(' ');
  return`<svg class="sk" viewBox="0 0 90 30" preserveAspectRatio="none"><polyline points="${p}" fill="none" stroke="${c}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
}

function render(){
 const r=R[ri],S=O.map((o,j)=>ser(o,j,r)),T=S.map(a=>a.reduce((x,y)=>x+y,0)),tot=T.reduce((x,y)=>x+y,0)||1;
 const sum=[...Array(r.len)].map((_,i)=>S.reduce((a,s)=>a+s[i],0));
 const live=O.find(o=>o.on&&o.ok)||O[0]||{n:'None',u:'No host configured'};
 const avg=Math.round(O.filter(o=>o.on).reduce((a,o)=>a+o.ms,0)/(O.filter(o=>o.on).length||1));

 $('#hn').textContent=live.n;$('#hu').textContent=live.u;$('#cnt').textContent=O.length+' total';

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

 $('#list').innerHTML=O.map((o,i)=>`<div class="card o ${o.on?'':'off'}"><div class="rank">${i+1}</div>
 <div class="om"><div class="on"><span class="dot ${o.ok?'':'d'}"></span>${o.n}${i==0?'<span class="tag">Primary</span>':''}</div><div class="ou mono">${o.u}</div><div class="tr"><i style="width:${Math.round(T[i]/tot*100)}%;background:${o.c}"></i></div></div>
 <div class="met"><b class="num">${F(T[i])}</b><span>${Math.round(T[i]/tot*100)}% traffic</span></div>
 <div class="oa"><span class="ms mono num">${o.ms} ms</span>
 <div class="mv"><button data-m="${i},-1" aria-label="Up">${ic('up')}</button><button data-m="${i},1" aria-label="Down">${ic('dn')}</button></div>
 <button class="ib sm" data-t="${i}" aria-label="Test">${ic('bolt')}</button><button class="ib sm del" data-d="${i}" aria-label="Delete">${ic('trash')}</button>
 <button class="sw ${o.on?'on':''}" data-s="${i}" aria-label="Toggle"></button></div></div>`).join('');

 $('#ev').innerHTML=EV.slice(0,r.ev>3?4:3).map(e=>`<div class="e"><div class="ei" style="background:var(--${e.k=='er'?'es':'ws'});color:var(--${e.k})">${ic('swap')}</div><div>${e.a} → ${e.b}<small>${F(e.n)} requests rerouted</small></div><span class="chip" style="background:var(--${e.k=='er'?'es':'ws'});color:var(--${e.k})">${e.code}</span><span class="lbl" style="min-width:58px;text-align:right;text-transform:none">${e.t}</span></div>`).join('');
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

function seg(id,fn){
 $(id).onclick=e=>{
   const b=e.target.closest('button');
   if(!b)return;
   $$(id+' button').forEach(x=>x.classList.remove('a'));
   b.classList.add('a');
   fn&&fn(b);
 };
}

seg('#range',b=>{ri=+b.dataset.r;render()});
seg('#tab',b=>{tb=+b.dataset.t;render()});
seg("#hc");
seg("#rty");

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

// Rules switches
$('#sw402').onclick=function(){this.classList.toggle('on')};
$('#sw429').onclick=function(){this.classList.toggle('on')};
$('#sw5xx').onclick=function(){this.classList.toggle('on')};

$('#tm').oninput=e=>$('#tv').textContent=e.target.value+' ms';

$('#saveRules').onclick=async ()=>{
 const res = await postAction('update_rules', {
   trigger_402: $('#sw402').classList.contains('on') ? 1 : 0,
   trigger_429: $('#sw429').classList.contains('on') ? 1 : 0,
   trigger_5xx: $('#sw5xx').classList.contains('on') ? 1 : 0,
   timeout_ms: $('#tm').value,
 });
 toast(res.message);
};

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
$('#theme').onclick=()=>{
 const d=root.dataset.theme?root.dataset.theme=='dark':matchMedia('(prefers-color-scheme:dark)').matches;
 root.dataset.theme=d?'light':'dark';
 try{localStorage.setItem('ao-theme',root.dataset.theme)}catch(_){};
};

render();
</script>
</body>
</html>
