<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>ログイン — B-Pay Admin</title>
    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.20/index.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="login-page">

<div class="login-card">
    <div class="login-brand">
        <div class="brand-mark">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
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

    <h1 class="login-title">サインイン</h1>
    <p class="login-subtitle">管理画面へアクセスします</p>

    <?php if (!empty($error)): ?>
    <div class="flash flash--danger" style="margin-bottom: 20px;">
        <i class="bi bi-exclamation-circle"></i>
        <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="/login" autocomplete="off" style="display:flex; flex-direction:column; gap:16px;">
        <div class="field" style="margin:0;">
            <label class="field__label" for="username">ユーザー名</label>
            <input
                type="text"
                id="username"
                name="username"
                class="input"
                placeholder="admin"
                required
                autofocus
                value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            >
        </div>

        <div class="field" style="margin:0;">
            <label class="field__label" for="password">パスワード</label>
            <input type="password" id="password" name="password" class="input" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn btn--primary" style="margin-top: 8px; height: 40px; font-size: 14px;">
            ログイン
            <i class="bi bi-arrow-right"></i>
        </button>
    </form>

    <p class="login-footer">
        B-Pay &middot; <?= date('Y') ?>
    </p>
</div>

</body>
</html>
