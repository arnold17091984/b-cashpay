<?php use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="/banks" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h2 class="h4 mb-0"><i class="bi bi-building-add me-2 text-warning"></i>銀行口座追加</h2>
</div>

<div class="card" style="max-width:700px;">
    <div class="card-body">
        <form method="POST" action="/banks">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <?php include __DIR__ . '/_form.php'; ?>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-save me-1"></i>保存
                </button>
                <a href="/banks" class="btn btn-outline-secondary">キャンセル</a>
            </div>
        </form>
    </div>
</div>
