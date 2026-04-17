<!DOCTYPE html>
<html lang="ja" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Admin', ENT_QUOTES, 'UTF-8') ?> — B-CashPay</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>

<!-- Top Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary fixed-top px-3" style="height:56px;">
    <a class="navbar-brand fw-bold text-warning me-4 d-flex align-items-center gap-2" href="/">
        <i class="bi bi-bank2"></i>
        B-CashPay
        <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;">Admin</span>
    </a>
    <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSidebar">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="d-flex align-items-center ms-auto gap-3">
        <?php if (!empty($currentUser)): ?>
        <span class="text-secondary d-none d-md-inline">
            <i class="bi bi-person-circle me-1"></i>
            <?= htmlspecialchars($currentUser['name'] ?? $currentUser['username'], ENT_QUOTES, 'UTF-8') ?>
            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($currentUser['role'], ENT_QUOTES, 'UTF-8') ?></span>
        </span>
        <a href="/logout" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-box-arrow-right"></i>
            <span class="d-none d-md-inline ms-1">ログアウト</span>
        </a>
        <?php endif; ?>
    </div>
</nav>

<div class="d-flex" style="padding-top:56px; min-height:100vh;">

    <!-- Sidebar -->
    <nav id="sidebar" class="sidebar d-flex flex-column flex-shrink-0 bg-dark border-end border-secondary">
        <ul class="nav nav-pills flex-column pt-3 px-2 gap-1">
            <?php
            $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $navItems = [
                '/'        => ['bi-speedometer2',  'ダッシュボード'],
                '/payments'=> ['bi-link-45deg',     '決済リンク'],
                '/deposits'=> ['bi-cash-coin',      '入金履歴'],
                '/banks'   => ['bi-building',       '銀行口座'],
                '/clients' => ['bi-key',            'APIクライアント'],
                '/scraper' => ['bi-robot',          'スクレイパー'],
            ];
            foreach ($navItems as $path => [$icon, $label]):
                $active = ($currentPath === $path || ($path !== '/' && str_starts_with($currentPath, $path)));
            ?>
            <li class="nav-item">
                <a href="<?= $path ?>" class="nav-link <?= $active ? 'active' : 'text-secondary' ?> d-flex align-items-center gap-2">
                    <i class="bi <?= $icon ?>"></i>
                    <span class="sidebar-label"><?= $label ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <!-- Main Content -->
    <main class="flex-grow-1 p-4" style="min-width:0;">
        <?php include dirname(__DIR__) . '/views/partials/flash.php'; ?>
        <?= $content ?? '' ?>
    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="/assets/js/admin.js"></script>
</body>
</html>
