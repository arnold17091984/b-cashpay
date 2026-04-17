<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title><?= htmlspecialchars($title ?? 'Admin', ENT_QUOTES, 'UTF-8') ?> — B-Pay</title>

    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.20/index.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>

<?php
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$navItems = [
    '/'         => ['bi-grid-1x2',     'ダッシュボード', 'Dashboard'],
    '/payments' => ['bi-link-45deg',   '決済リンク',     'Payments'],
    '/deposits' => ['bi-arrow-down-right-circle', '入金履歴',  'Deposits'],
    '/banks'    => ['bi-bank',         '銀行口座',       'Banks'],
    '/clients'  => ['bi-key',          'API クライアント', 'Clients'],
    '/scraper'  => ['bi-activity',     'スクレイパー',    'Scraper'],
    '/settings/telegram' => ['bi-telegram', 'Telegram設定', 'Telegram'],
];
?>

<div class="app">

    <!-- ─── Sidebar ─── -->
    <aside class="sidebar">
        <div class="sidebar__brand">
            <div class="brand-mark">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 21h18"/>
                    <path d="M3 10h18"/>
                    <path d="M5 6l7-3 7 3"/>
                    <path d="M4 10v11"/>
                    <path d="M20 10v11"/>
                    <path d="M8 14v3"/>
                    <path d="M12 14v3"/>
                    <path d="M16 14v3"/>
                </svg>
            </div>
            <div class="brand-name">
                <span>B-Pay</span>
                <em>Admin</em>
            </div>
        </div>

        <nav class="sidebar__nav">
            <div class="nav-section-label">OPERATIONS</div>
            <?php foreach ($navItems as $path => [$icon, $label, $english]):
                $active = ($currentPath === $path || ($path !== '/' && str_starts_with($currentPath, $path)));
            ?>
            <a href="<?= $path ?>" class="nav-item <?= $active ? 'is-active' : '' ?>">
                <i class="bi <?= $icon ?>"></i>
                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <?php if (!empty($currentUser)): ?>
        <div class="sidebar__user">
            <div class="user-avatar"><?= htmlspecialchars(mb_substr($currentUser['name'] ?? $currentUser['username'], 0, 1), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($currentUser['name'] ?? $currentUser['username'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="user-role"><?= htmlspecialchars($currentUser['role'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <a href="/logout" class="user-logout" title="ログアウト">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
        <?php endif; ?>
    </aside>

    <!-- ─── Main ─── -->
    <main class="main">
        <?php include dirname(__DIR__) . '/views/partials/flash.php'; ?>
        <?= $content ?? '' ?>
    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="/assets/js/admin.js"></script>
</body>
</html>
