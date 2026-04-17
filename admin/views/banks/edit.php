<?php use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<!-- Page Header -->
<div class="page-header">
    <div class="hstack gap-lg">
        <a href="/banks" class="btn btn--ghost btn--icon-only" title="戻る">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h1 class="page-header__title">銀行口座編集 <span class="mono text-muted" style="font-size:16px;">#<?= (int) $bank['id'] ?></span></h1>
            <p class="page-header__subtitle"><?= htmlspecialchars($bank['bank_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
</div>

<div class="card" style="max-width:700px;">
    <div class="card__header">
        <span class="card__title"><i class="bi bi-pencil"></i>口座情報</span>
    </div>
    <div class="card__body">
        <form method="POST" action="/banks/<?= (int) $bank['id'] ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <?php include_once __DIR__ . '/_form.php'; ?>
            <div style="margin-top:24px;" class="hstack">
                <button type="submit" class="btn btn--primary">
                    <i class="bi bi-save"></i>更新
                </button>
                <a href="/banks" class="btn btn--ghost">キャンセル</a>
            </div>
        </form>
    </div>
</div>
