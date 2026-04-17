<?php use BCashPay\Admin\View;
use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-header__title">スクレイパー</h1>
        <p class="page-header__subtitle">銀行口座のスクレイピング状態と実行ログ</p>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat stat--success">
        <div class="stat__label"><i class="bi bi-check-circle"></i>成功</div>
        <div class="stat__value">
            <span class="stat__value--mono"><?= (int) ($stats['completed'] ?? 0) ?></span>
        </div>
        <div class="stat__sub">過去 7 日間</div>
    </div>

    <div class="stat stat--danger" style="--stat-color:var(--danger);">
        <div class="stat__label" style="color:var(--fg-3);"><i class="bi bi-x-circle"></i>失敗</div>
        <div class="stat__value">
            <span class="stat__value--mono" style="color:var(--danger);"><?= (int) ($stats['failed'] ?? 0) ?></span>
        </div>
        <div class="stat__sub">過去 7 日間</div>
    </div>

    <div class="stat stat--accent">
        <div class="stat__label"><i class="bi bi-activity"></i>実行中</div>
        <div class="stat__value">
            <span class="stat__value--mono"><?= (int) ($stats['running'] ?? 0) ?></span>
        </div>
        <div class="stat__sub">現在</div>
    </div>

    <div class="stat">
        <div class="stat__label"><i class="bi bi-bar-chart"></i>合計実行</div>
        <div class="stat__value">
            <span class="stat__value--mono"><?= (int) ($stats['total'] ?? 0) ?></span>
        </div>
        <div class="stat__sub">過去 7 日間</div>
    </div>
</div>

<!-- Bank Scraper Status -->
<div class="table-wrap" style="margin-bottom:24px;">
    <div style="padding:14px 16px;border-bottom:1px solid var(--border-0);">
        <span class="card__title"><i class="bi bi-building"></i>銀行口座のスクレイパー状態</span>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>銀行名</th>
                <th>口座番号</th>
                <th>ステータス</th>
                <th>最終成功</th>
                <th>次回予定</th>
                <th class="num">連続失敗</th>
                <th class="num">直近取引数</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($banks)): ?>
            <tr>
                <td colspan="8">
                    <div class="table-empty">
                        <i class="bi bi-building-slash"></i>
                        アクティブな銀行口座がありません
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($banks as $bank): ?>
            <tr>
                <td style="font-weight:500;color:var(--fg-0);"><?= htmlspecialchars($bank['bank_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="mono"><?= htmlspecialchars($bank['account_number'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <span class="badge badge--<?= View::scraperBadge($bank['scrape_status']) ?>">
                        <?= htmlspecialchars($bank['scrape_status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </td>
                <td class="text-muted nowrap"><?= View::datetime($bank['scrape_last_success_at']) ?></td>
                <td class="text-muted nowrap"><?= View::datetime($bank['next_run_at']) ?></td>
                <td class="num mono">
                    <?php $failures = (int) ($bank['scrape_consecutive_failures'] ?? 0); ?>
                    <span style="<?= $failures > 0 ? 'color:var(--danger);font-weight:700;' : 'color:var(--fg-3);' ?>">
                        <?= $failures ?>
                    </span>
                </td>
                <td class="num text-muted"><?= (int) ($bank['transactions_found'] ?? 0) ?></td>
                <td>
                    <?php if ($bank['is_active']): ?>
                    <form method="POST" action="/scraper/<?= (int) $bank['id'] ?>/run-now">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn--secondary btn--sm">
                            <i class="bi bi-play-fill"></i>今すぐ
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

<!-- Recent Task Logs -->
<div class="table-wrap">
    <div style="padding:14px 16px;border-bottom:1px solid var(--border-0);">
        <span class="card__title"><i class="bi bi-list-ul"></i>最近の実行ログ <span class="text-muted" style="font-weight:400;font-size:12px;">最新 20 件</span></span>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th>銀行</th>
                <th>ステータス</th>
                <th>実行日時</th>
                <th class="num">取引数</th>
                <th class="num">マッチ数</th>
                <th class="num">実行時間</th>
                <th>エラー</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tasks)): ?>
            <tr>
                <td colspan="8">
                    <div class="table-empty">
                        <i class="bi bi-journal-x"></i>
                        ログなし
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($tasks as $task): ?>
            <tr>
                <td class="mono text-muted"><?= (int) $task['id'] ?></td>
                <td>
                    <?= htmlspecialchars($task['bank_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    <div class="sub mono"><?= htmlspecialchars($task['account_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td>
                    <span class="badge badge--<?= View::taskBadge($task['status']) ?>">
                        <?= htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </td>
                <td class="text-muted nowrap"><?= View::datetime($task['last_run_at']) ?></td>
                <td class="num mono"><?= (int) $task['transactions_found'] ?></td>
                <td class="num mono"><?= (int) $task['transactions_matched'] ?></td>
                <td class="num mono text-muted">
                    <?= $task['duration_seconds'] !== null ? number_format((float) $task['duration_seconds'], 2) . 's' : '—' ?>
                </td>
                <td class="truncate" style="max-width:200px;color:var(--danger);font-size:12px;">
                    <?= htmlspecialchars($task['error_message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
