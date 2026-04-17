<?php use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="/clients" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h2 class="h4 mb-0"><i class="bi bi-plus-circle me-2 text-warning"></i>APIクライアント追加</h2>
</div>

<div class="card" style="max-width:500px;">
    <div class="card-body">
        <form method="POST" action="/clients">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">
                <label class="form-label">クライアント名 <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control <?= !empty($errors['name']) ? 'is-invalid' : '' ?>"
                       value="<?= htmlspecialchars($old['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="例: My Shop" autofocus>
                <?php if (!empty($errors['name'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-4">
                <label class="form-label">コールバックURL</label>
                <input type="url" name="callback_url" class="form-control"
                       value="<?= htmlspecialchars($old['callback_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="https://example.com/webhooks/payment">
                <div class="form-text text-secondary">省略可。決済確認時のWebhook送信先。</div>
            </div>

            <div class="alert alert-info d-flex align-items-center gap-2 small">
                <i class="bi bi-info-circle-fill"></i>
                APIキーとWebhookシークレットは自動生成されます。作成後に確認できます。
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-save me-1"></i>作成
                </button>
                <a href="/clients" class="btn btn-outline-secondary">キャンセル</a>
            </div>
        </form>
    </div>
</div>
