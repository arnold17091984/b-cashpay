<!DOCTYPE html>
<html lang="ja" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン — B-CashPay Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #0d1117; }
        .login-card { max-width: 420px; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
<div class="login-card w-100 px-3">
    <div class="text-center mb-4">
        <div class="display-6 fw-bold text-warning">
            <i class="bi bi-bank2"></i> B-CashPay
        </div>
        <p class="text-secondary">管理画面</p>
    </div>

    <div class="card border-secondary shadow-lg">
        <div class="card-body p-4">
            <h5 class="card-title mb-4">
                <i class="bi bi-person-lock me-2"></i>ログイン
            </h5>

            <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="/login" autocomplete="off">
                <div class="mb-3">
                    <label for="username" class="form-label">ユーザー名</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            placeholder="admin"
                            required
                            autofocus
                            value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">パスワード</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key"></i></span>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-warning w-100 fw-bold">
                    <i class="bi bi-box-arrow-in-right me-1"></i>ログイン
                </button>
            </form>
        </div>
    </div>

    <p class="text-center text-secondary mt-4 small">
        B-CashPay Admin Panel &copy; <?= date('Y') ?>
    </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
