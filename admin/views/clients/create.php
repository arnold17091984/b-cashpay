<?php use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<!-- Page Header -->
<div class="page-header">
    <div class="hstack gap-lg">
        <a href="/clients" class="btn btn--ghost btn--icon-only" title="戻る">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h1 class="page-header__title">API クライアント追加</h1>
            <p class="page-header__subtitle">新しい接続クライアントを登録します</p>
        </div>
    </div>
</div>

<div class="card" style="max-width:500px;">
    <div class="card__header">
        <span class="card__title"><i class="bi bi-key"></i>クライアント情報</span>
    </div>
    <div class="card__body">
        <form method="POST" action="/clients">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="field">
                <label class="field__label" for="client_name">クライアント名 <span style="color:var(--danger);">*</span></label>
                <input type="text" id="client_name" name="name" class="input"
                       value="<?= htmlspecialchars($old['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="例: My Shop" autofocus
                       style="<?= (!empty($errors) && is_array($errors) && !empty($errors['name'])) ? 'border-color:var(--danger);' : '' ?>">
                <?php if (!empty($errors) && is_array($errors) && !empty($errors['name'])): ?>
                <p class="field__hint" style="color:var(--danger);"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field__label" for="callback_url">コールバック URL</label>
                <input type="url" id="callback_url" name="callback_url" class="input"
                       value="<?= htmlspecialchars($old['callback_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="https://example.com/webhooks/payment">
                <p class="field__hint">省略可。決済確認時の Webhook 送信先。</p>
            </div>

            <div class="flash flash--info" style="margin-bottom:20px;">
                <i class="bi bi-info-circle-fill"></i>
                <span>API キーと Webhook シークレットは自動生成されます。作成後に確認できます。</span>
            </div>

            <div class="hstack">
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-save"></i>作成
                </button>
                <a href="/clients" class="btn btn--ghost">キャンセル</a>
            </div>
        </form>
    </div>
</div>
