<?php use BCashPay\Admin\View;
use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="h4 mb-0"><i class="bi bi-robot me-2 text-warning"></i>スクレイパー</h2>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-success text-center">
            <div class="card-body py-2">
                <div class="h3 fw-bold text-success"><?= (int) ($stats['completed'] ?? 0) ?></div>
                <div class="small text-secondary">成功（7日）</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-danger text-center">
            <div class="card-body py-2">
                <div class="h3 fw-bold text-danger"><?= (int) ($stats['failed'] ?? 0) ?></div>
                <div class="small text-secondary">失敗（7日）</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-info text-center">
            <div class="card-body py-2">
                <div class="h3 fw-bold text-info"><?= (int) ($stats['running'] ?? 0) ?></div>
                <div class="small text-secondary">実行中</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-secondary text-center">
            <div class="card-body py-2">
                <div class="h3 fw-bold"><?= (int) ($stats['total'] ?? 0) ?></div>
                <div class="small text-secondary">合計（7日）</div>
            </div>
        </div>
    </div>
</div>

<!-- Bank Accounts Scraper Status -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-building me-2"></i>銀行口座のスクレイパー状態</div>
    <div class="table-responsive">
        <table class="table table-hover table-dark mb-0">
            <thead class="table-secondary">
                <tr>
                    <th>銀行名</th>
                    <th>口座番号</th>
                    <th class="text-center">ステータス</th>
                    <th>最終成功</th>
                    <th>次回予定</th>
                    <th class="text-center">連続失敗</th>
                    <th class="text-end">直近の取引</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($banks)): ?>
                <tr><td colspan="8" class="text-center text-secondary py-4">アクティブな銀行口座がありません</td></tr>
                <?php else: ?>
                <?php foreach ($banks as $bank): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($bank['bank_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code><?= htmlspecialchars($bank['account_number'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td class="text-center">
                        <span class="badge bg-<?= View::scraperBadge($bank['scrape_status']) ?>">
                            <?= htmlspecialchars($bank['scrape_status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="text-secondary"><?= View::datetime($bank['scrape_last_success_at']) ?></td>
                    <td class="text-secondary"><?= View::datetime($bank['next_run_at']) ?></td>
                    <td class="text-center">
                        <?php $failures = (int) ($bank['scrape_consecutive_failures'] ?? 0); ?>
                        <span class="<?= $failures > 0 ? 'text-danger fw-bold' : 'text-secondary' ?>">
                            <?= $failures ?>
                        </span>
                    </td>
                    <td class="text-end text-secondary">
                        <?= (int) ($bank['transactions_found'] ?? 0) ?> 件
                    </td>
                    <td>
                        <?php if ($bank['is_active']): ?>
                        <form method="POST" action="/scraper/<?= (int) $bank['id'] ?>/run-now">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-sm btn-outline-info">
                                <i class="bi bi-play-fill"></i> 今すぐ
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Last 20 Task Runs -->
<div class="card">
    <div class="card-header"><i class="bi bi-list-ul me-2"></i>最近の実行ログ（最新20件）</div>
    <div class="table-responsive">
        <table class="table table-hover table-dark table-sm mb-0">
            <thead class="table-secondary">
                <tr>
                    <th>ID</th>
                    <th>銀行</th>
                    <th class="text-center">ステータス</th>
                    <th>実行日時</th>
                    <th class="text-end">取引数</th>
                    <th class="text-end">マッチ数</th>
                    <th class="text-end">実行時間</th>
                    <th>エラー</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tasks)): ?>
                <tr><td colspan="8" class="text-center text-secondary py-4">ログなし</td></tr>
                <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                <tr>
                    <td><?= (int) $task['id'] ?></td>
                    <td>
                        <?= htmlspecialchars($task['bank_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                        <br><small class="text-secondary"><?= htmlspecialchars($task['account_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= View::taskBadge($task['status']) ?>">
                            <?= htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="text-secondary"><?= View::datetime($task['last_run_at']) ?></td>
                    <td class="text-end"><?= (int) $task['transactions_found'] ?></td>
                    <td class="text-end"><?= (int) $task['transactions_matched'] ?></td>
                    <td class="text-end text-secondary">
                        <?= $task['duration_seconds'] !== null ? number_format((float) $task['duration_seconds'], 2) . 's' : '-' ?>
                    </td>
                    <td class="small text-danger" style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($task['error_message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
