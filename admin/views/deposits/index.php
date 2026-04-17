<?php use BCashPay\Admin\View;
use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-header__title">入金履歴</h1>
        <p class="page-header__subtitle"><?= number_format($pagination['total']) ?> 件</p>
    </div>
</div>

<!-- Filters -->
<form method="GET" action="/deposits" class="filters">
    <div class="filters__item">
        <div class="field">
            <label class="field__label">状態</label>
            <select name="filter" class="select">
                <option value="">すべて</option>
                <option value="matched"   <?= ($filter ?? '') === 'matched'   ? 'selected' : '' ?>>マッチ済み</option>
                <option value="unmatched" <?= ($filter ?? '') === 'unmatched' ? 'selected' : '' ?>>未マッチ</option>
            </select>
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

    <div class="filters__item" style="flex:0 0 auto;display:flex;gap:8px;align-items:flex-end;">
        <button type="submit" class="btn btn--primary btn--sm">
            <i class="bi bi-search"></i>検索
        </button>
        <a href="/deposits" class="btn btn--ghost btn--sm">クリア</a>
    </div>
</form>

<!-- Table -->
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th>振込人名</th>
                <th class="num">金額</th>
                <th>取引日</th>
                <th>銀行口座</th>
                <th>決済リンク</th>
                <th>状態</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($deposits)): ?>
            <tr>
                <td colspan="8">
                    <div class="table-empty">
                        <i class="bi bi-arrow-down-right-circle"></i>
                        データがありません
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($deposits as $dep): ?>
            <?php $isMatched = !empty($dep['payment_link_id']); ?>
            <tr>
                <td class="mono text-muted"><?= (int) $dep['id'] ?></td>
                <td class="truncate" style="max-width:220px;"><?= htmlspecialchars($dep['depositor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="num mono">¥<?= number_format((float) $dep['amount']) ?></td>
                <td class="text-muted nowrap"><?= View::datetime($dep['transaction_date']) ?></td>
                <td>
                    <?= htmlspecialchars($dep['bank_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                    <div class="sub mono"><?= htmlspecialchars($dep['account_number'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td>
                    <?php if ($isMatched): ?>
                    <a href="/payments/<?= htmlspecialchars($dep['payment_link_id'], ENT_QUOTES, 'UTF-8') ?>"
                       style="color:var(--accent);font-size:12px;font-family:var(--font-mono);">
                        <?= htmlspecialchars($dep['reference_number'] ?? $dep['payment_link_id'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <div class="sub"><?= htmlspecialchars($dep['customer_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge--<?= $isMatched ? 'matched' : 'unmatched' ?>">
                        <?= $isMatched ? 'マッチ済み' : '未マッチ' ?>
                    </span>
                </td>
                <td>
                    <?php if (!$isMatched): ?>
                    <button type="button" class="btn btn--secondary btn--sm"
                            onclick="document.getElementById('matchModal<?= (int) $dep['id'] ?>').style.display='flex'">
                        手動マッチ
                    </button>
                    <?php endif; ?>
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
               href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['page'] - 1])) ?>">
                <i class="bi bi-chevron-left"></i>
            </a>
            <?php for ($p = max(1, $pagination['page'] - 3); $p <= min($pagination['totalPages'], $pagination['page'] + 3); $p++): ?>
            <a class="pagination__btn"
               style="<?= $p === $pagination['page'] ? 'background:var(--accent);color:white;border-color:var(--accent);' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="pagination__btn <?= $pagination['page'] >= $pagination['totalPages'] ? 'disabled' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['page'] + 1])) ?>">
                <i class="bi bi-chevron-right"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Manual Match Modals -->
<?php foreach ($deposits as $dep): ?>
<?php if (empty($dep['payment_link_id'])): ?>
<div id="matchModal<?= (int) $dep['id'] ?>"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:24px;">
    <div class="card" style="width:100%;max-width:480px;">
        <div class="card__header">
            <span class="card__title"><i class="bi bi-link"></i>手動マッチ — 入金 #<?= (int) $dep['id'] ?></span>
            <button type="button" class="btn btn--ghost btn--icon-only"
                    onclick="document.getElementById('matchModal<?= (int) $dep['id'] ?>').style.display='none'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <form method="POST" action="/deposits/<?= (int) $dep['id'] ?>/match">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div class="card__body vstack">
                <div style="font-size:13px;color:var(--fg-2);">
                    振込人: <strong style="color:var(--fg-0);"><?= htmlspecialchars($dep['depositor_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                    金額: <strong class="mono" style="color:var(--success);">¥<?= number_format((float) $dep['amount']) ?></strong>
                </div>
                <div class="field">
                    <label class="field__label">決済リンクID (ULID)</label>
                    <input type="text" name="payment_link_id" class="input mono"
                           placeholder="例: 01ABCDEFGHJKMNPQRSTVWX" required>
                    <p class="field__hint">pendingステータスの決済リンクのULIDを入力してください。</p>
                </div>
            </div>
            <div style="padding:12px 20px;border-top:1px solid var(--border-0);display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn--ghost btn--sm"
                        onclick="document.getElementById('matchModal<?= (int) $dep['id'] ?>').style.display='none'">閉じる</button>
                <button type="submit" class="btn btn--primary btn--sm">マッチ実行</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>
