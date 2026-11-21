<?php
require_once __DIR__ . '/bootstrap.php';

$pdo = getDb();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $hash = $pdo->query("SELECT value FROM settings WHERE key = 'admin_password_hash'")->fetchColumn();

    if ($hash && password_verify($password, $hash)) {
        $_SESSION['anyorigin_auth'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid admin password. Default is: admin123';
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['anyorigin_auth']);
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AnyOrigin — Admin Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { background-color: #090d16; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 text-slate-100">
    <div class="w-full max-w-md rounded-2xl border border-slate-800 bg-[#0f172a] p-8 shadow-2xl shadow-sky-950/20">
        <div class="flex flex-col items-center text-center mb-8">
            <div class="h-14 w-14 rounded-2xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-sky-500/25 mb-4">
                <i data-lucide="shield" class="h-7 w-7 text-white"></i>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                AnyOrigin
                <span class="text-xs px-2 py-0.5 rounded-full bg-sky-500/10 text-sky-400 border border-sky-500/20 font-mono">
                    v1.0
                </span>
            </h1>
            <p class="text-sm text-slate-400 mt-1">Multi-Cloud Failover & Load Balancer</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-5 flex items-center gap-2 rounded-xl bg-rose-500/10 border border-rose-500/20 p-3 text-xs text-rose-400">
                <i data-lucide="alert-circle" class="h-4 w-4 shrink-0"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-4">
            <div>
                <label class="block text-xs font-medium text-slate-300 uppercase tracking-wider mb-2 font-mono">
                    Admin Password
                </label>
                <div class="relative">
                    <input type="password" name="password" required autofocus
                           placeholder="Default: admin123"
                           class="w-full rounded-xl border border-slate-700 bg-slate-900/90 py-2.5 px-4 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500 font-mono">
                </div>
                <p class="text-[11px] text-slate-500 mt-1.5">
                    Default password is <code class="text-sky-400 font-mono">admin123</code>. Changeable inside the dashboard.
                </p>
            </div>

            <button type="submit"
                    class="w-full rounded-xl bg-gradient-to-r from-sky-500 to-indigo-600 py-2.5 px-4 text-sm font-semibold text-white shadow-lg shadow-sky-500/20 hover:from-sky-400 hover:to-indigo-500 transition-all flex items-center justify-center gap-2">
                <span>Access Dashboard</span>
                <i data-lucide="arrow-right" class="h-4 w-4"></i>
            </button>
        </form>

        <div class="mt-8 border-t border-slate-800/80 pt-4 text-center">
            <div class="inline-flex items-center gap-1.5 text-xs text-slate-500">
                <i data-lucide="sparkles" class="h-3.5 w-3.5 text-sky-400"></i>
                Zero External Database • 100k+ Req/Day Fast Path
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
