<?php use BCashPay\Admin\View; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="h4 mb-0"><i class="bi bi-link-45deg me-2 text-warning"></i>決済リンク</h2>
</div>

<!-- Filters -->
<form method="GET" action="/payments" class="card mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small text-secondary">ステータス</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">すべて</option>
                    <?php foreach (['pending','confirmed','expired','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small text-secondary">検索</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="参照番号・外部ID・顧客名"
                       value="<?= htmlspecialchars($search ?? '', ENT_QUOTES, 'UTF-8') ?>">
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
                <a href="/payments" class="btn btn-outline-secondary btn-sm">クリア</a>
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
                    <th style="width:130px;">参照番号</th>
                    <th>クライアント</th>
                    <th>顧客名</th>
                    <th class="text-end">金額</th>
                    <th class="text-center">ステータス</th>
                    <th>作成日時</th>
                    <th>有効期限</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($links)): ?>
                <tr><td colspan="8" class="text-center text-secondary py-4">データがありません</td></tr>
                <?php else: ?>
                <?php foreach ($links as $link): ?>
                <tr>
                    <td><code class="text-info"><?= htmlspecialchars($link['reference_number'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($link['client_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?= htmlspecialchars($link['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($link['external_id'])): ?>
                        <br><small class="text-secondary"><?= htmlspecialchars($link['external_id'], ENT_QUOTES, 'UTF-8') ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold"><?= View::yen($link['amount']) ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= View::statusBadge($link['status']) ?>">
                            <?= htmlspecialchars($link['status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="text-secondary"><?= View::datetime($link['created_at']) ?></td>
                    <td class="text-secondary <?= strtotime($link['expires_at']) < time() ? 'text-danger' : '' ?>">
                        <?= View::datetime($link['expires_at']) ?>
                    </td>
                    <td>
                        <a href="/payments/<?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?>"
                           class="btn btn-sm btn-outline-info">詳細</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pagination['totalPages'] > 1): ?>
    <div class="card-footer d-flex justify-content-center">
        <nav>
            <ul class="pagination pagination-sm mb-0">
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
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
