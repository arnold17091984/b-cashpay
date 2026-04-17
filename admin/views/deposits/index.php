<?php use BCashPay\Admin\View;
use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="h4 mb-0"><i class="bi bi-cash-coin me-2 text-warning"></i>入金履歴</h2>
</div>

<!-- Filters -->
<form method="GET" action="/deposits" class="card mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small text-secondary">フィルター</label>
                <select name="filter" class="form-select form-select-sm">
                    <option value="">すべて</option>
                    <option value="matched"   <?= ($filter ?? '') === 'matched'   ? 'selected' : '' ?>>マッチ済み</option>
                    <option value="unmatched" <?= ($filter ?? '') === 'unmatched' ? 'selected' : '' ?>>未マッチ</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-secondary">開始日</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateFrom ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-secondary">終了日</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateTo ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-warning btn-sm flex-grow-1">
                    <i class="bi bi-search"></i> 検索
                </button>
                <a href="/deposits" class="btn btn-outline-secondary btn-sm">クリア</a>
            </div>
        </div>
    </div>
</form>

<!-- Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="text-secondary small"><?= number_format($pagination['total']) ?> 件</span>
        <span class="text-secondary small">ページ <?= $pagination['page'] ?> / <?= $pagination['totalPages'] ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-dark table-striped mb-0">
            <thead class="table-secondary">
                <tr>
                    <th>ID</th>
                    <th>振込人名</th>
                    <th class="text-end">金額</th>
                    <th>取引日</th>
                    <th>銀行口座</th>
                    <th>決済リンク</th>
                    <th class="text-center">状態</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deposits)): ?>
                <tr><td colspan="8" class="text-center text-secondary py-4">データがありません</td></tr>
                <?php else: ?>
                <?php foreach ($deposits as $dep): ?>
                <?php $isMatched = !empty($dep['payment_link_id']); ?>
                <tr>
                    <td><?= (int) $dep['id'] ?></td>
                    <td><?= htmlspecialchars($dep['depositor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-end fw-semibold"><?= View::yen($dep['amount']) ?></td>
                    <td class="text-secondary"><?= View::datetime($dep['transaction_date']) ?></td>
                    <td>
                        <?= htmlspecialchars($dep['bank_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                        <br><small class="text-secondary"><?= htmlspecialchars($dep['account_number'] ?? '-', ENT_QUOTES, 'UTF-8') ?></small>
                    </td>
                    <td>
                        <?php if ($isMatched): ?>
                        <a href="/payments/<?= htmlspecialchars($dep['payment_link_id'], ENT_QUOTES, 'UTF-8') ?>"
                           class="text-info small">
                            <?= htmlspecialchars($dep['reference_number'] ?? $dep['payment_link_id'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <br><small class="text-secondary"><?= htmlspecialchars($dep['customer_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                        <?php else: ?>
                        <span class="text-secondary">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $isMatched ? 'success' : 'warning' ?>">
                            <?= $isMatched ? 'マッチ済み' : '未マッチ' ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!$isMatched): ?>
                        <button type="button" class="btn btn-sm btn-outline-success"
                                data-bs-toggle="modal"
                                data-bs-target="#matchModal<?= (int) $dep['id'] ?>">
                            手動マッチ
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pagination['totalPages'] > 1): ?>
    <div class="card-footer d-flex justify-content-center">
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $pagination['page'] <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['page'] - 1])) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php for ($p = max(1, $pagination['page'] - 3); $p <= min($pagination['totalPages'], $pagination['page'] + 3); $p++): ?>
            <li class="page-item <?= $p === $pagination['page'] ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $pagination['page'] >= $pagination['totalPages'] ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['page'] + 1])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<!-- Manual Match Modals -->
<?php foreach ($deposits as $dep): ?>
<?php if (empty($dep['payment_link_id'])): ?>
<div class="modal fade" id="matchModal<?= (int) $dep['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">手動マッチ — 入金ID <?= (int) $dep['id'] ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/deposits/<?= (int) $dep['id'] ?>/match">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-body">
                    <p class="text-secondary">
                        振込人: <strong><?= htmlspecialchars($dep['depositor_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                        金額: <strong class="text-success"><?= View::yen($dep['amount']) ?></strong>
                    </p>
                    <div class="mb-3">
                        <label class="form-label">決済リンクID (ULID)</label>
                        <input type="text" name="payment_link_id" class="form-control font-monospace"
                               placeholder="例: 01ABCDEFGHJKMNPQRSTVWX" required>
                        <div class="form-text text-secondary">pendingステータスの決済リンクのULIDを入力してください。</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                    <button type="submit" class="btn btn-success">マッチ実行</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>
