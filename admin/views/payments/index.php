<?php use BCashPay\Admin\View; ?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-header__title">決済リンク</h1>
        <p class="page-header__subtitle"><?= number_format($pagination['total']) ?> 件</p>
    </div>
    <div class="page-header__actions">
        <a href="/payments/new" class="btn btn--primary btn--sm">
            <i class="bi bi-plus-lg"></i>新規作成
        </a>
    </div>
</div>

<!-- Filters -->
<form method="GET" action="/payments" class="filters">
    <div class="filters__item">
        <div class="field">
            <label class="field__label">ステータス</label>
            <select name="status" class="select">
                <option value="">すべて</option>
                <?php foreach (['pending', 'confirmed', 'expired', 'cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="filters__item" style="flex:2 1 260px;">
        <div class="field">
            <label class="field__label">検索</label>
            <div class="input-group">
                <span class="input-group__icon"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="input"
                       placeholder="参照番号・外部ID・顧客名"
                       value="<?= htmlspecialchars($search ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>
    </div>

    <div class="filters__item">
        <div class="field">
            <label class="field__label">開始日</label>
            <input type="date" name="date_from" class="input"
                   value="<?= htmlspecialchars($dateFrom ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>

    <div class="filters__item">
        <div class="field">
            <label class="field__label">終了日</label>
            <input type="date" name="date_to" class="input"
                   value="<?= htmlspecialchars($dateTo ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>

    <div class="filters__item" style="flex:0 0 auto;display:flex;gap:8px;align-items:flex-end;padding-bottom:0;">
        <button type="submit" class="btn btn--primary btn--sm">
            <i class="bi bi-search"></i>検索
        </button>
        <a href="/payments" class="btn btn--ghost btn--sm">クリア</a>
    </div>
</form>

<!-- Table -->
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th style="width:130px;">参照番号</th>
                <th>クライアント</th>
                <th>顧客名</th>
                <th class="num">金額</th>
                <th>ステータス</th>
                <th>作成日時</th>
                <th>有効期限</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($links)): ?>
            <tr>
                <td colspan="8">
                    <div class="table-empty">
                        <i class="bi bi-link-45deg"></i>
                        データがありません
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($links as $link): ?>
            <tr>
                <td class="mono" style="color:var(--accent);font-size:12px;"><?= htmlspecialchars($link['reference_number'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($link['client_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?= htmlspecialchars($link['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if (!empty($link['external_id'])): ?>
                    <div class="sub mono"><?= htmlspecialchars($link['external_id'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </td>
                <td class="num mono">¥<?= number_format((float) $link['amount']) ?></td>
                <td>
                    <span class="badge badge--<?= View::statusBadge($link['status']) ?>">
                        <?= htmlspecialchars($link['status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </td>
                <td class="text-muted nowrap"><?= View::datetime($link['created_at']) ?></td>
                <td class="nowrap <?= strtotime($link['expires_at']) < time() ? '' : 'text-muted' ?>"
                    style="<?= strtotime($link['expires_at']) < time() ? 'color:var(--danger);' : '' ?>">
                    <?= View::datetime($link['expires_at']) ?>
                </td>
                <td>
                    <a href="/payments/<?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn--ghost btn--icon-only" title="詳細">
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pagination['totalPages'] > 1): ?>
    <div class="pagination">
        <span>ページ <?= $pagination['page'] ?> / <?= $pagination['totalPages'] ?></span>
        <div class="pagination__nav">
            <a class="pagination__btn <?= $pagination['page'] <= 1 ? 'disabled' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['page'] - 1])) ?>"
               aria-disabled="<?= $pagination['page'] <= 1 ? 'true' : 'false' ?>">
                <i class="bi bi-chevron-left"></i>
            </a>
            <?php for ($p = max(1, $pagination['page'] - 3); $p <= min($pagination['totalPages'], $pagination['page'] + 3); $p++): ?>
            <a class="pagination__btn <?= $p === $pagination['page'] ? '' : '' ?>"
               style="<?= $p === $pagination['page'] ? 'background:var(--accent);color:white;border-color:var(--accent);' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="pagination__btn <?= $pagination['page'] >= $pagination['totalPages'] ? 'disabled' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['page'] + 1])) ?>"
               aria-disabled="<?= $pagination['page'] >= $pagination['totalPages'] ? 'true' : 'false' ?>">
                <i class="bi bi-chevron-right"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>
