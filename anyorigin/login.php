<?php
require_once __DIR__ . '/bootstrap.php';

$pdo = getDb();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $hash = $pdo->query("SELECT value FROM admin_settings WHERE key = 'admin_password_hash'")->fetchColumn();

    if ($hash && password_verify($password, $hash)) {
        $_SESSION['anyorigin_auth'] = true;
        header('Location: index.php');
        exit;
    }

    $error = 'Incorrect password.';
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
    <title>AnyOrigin - Sign In</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { background-color: #0b0f19; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 text-slate-100">
    <div class="w-full max-w-sm rounded-xl border border-slate-800 bg-slate-900/80 p-8 shadow-xl">
        <div class="flex flex-col items-center text-center mb-6">
            <div class="h-11 w-11 rounded-xl bg-sky-500/10 border border-sky-500/20 flex items-center justify-center mb-3">
                <i data-lucide="shield" class="h-5 w-5 text-sky-400"></i>
            </div>
            <h1 class="text-xl font-bold tracking-tight text-white">AnyOrigin</h1>
            <p class="text-xs text-slate-400 mt-1">Management Console</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-4 rounded-lg bg-rose-500/10 border border-rose-500/20 p-3 text-xs text-rose-400">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-4">
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-1.5">
                    Password
                </label>
                <input type="password" name="password" required autofocus
                       placeholder="Default: admin123"
                       class="w-full rounded-lg border border-slate-700 bg-slate-800/80 py-2 px-3 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none">
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-sky-500 py-2 px-4 text-sm font-semibold text-slate-950 hover:bg-sky-400 transition-colors">
                Sign In
            </button>
        </form>

        <div class="mt-6 border-t border-slate-800/80 pt-3 text-center">
            <p class="text-[11px] text-slate-500">v1.0.0 &bull; Self-hosted</p>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
