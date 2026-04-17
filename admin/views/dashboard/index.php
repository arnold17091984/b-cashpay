<?php use BCashPay\Admin\View; ?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-header__title">ダッシュボード</h1>
        <p class="page-header__subtitle"><?= date('Y/m/d H:i') ?> 更新</p>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat stat--warning">
        <div class="stat__label"><i class="bi bi-hourglass-split"></i>今日の決済リンク</div>
        <div class="stat__value">
            <span class="stat__value--mono"><?= (int) ($linkCounts['pending'] ?? 0) ?></span>
            <span class="stat__unit">pending</span>
        </div>
        <div class="stat__sub">
            confirmed <?= (int) ($linkCounts['confirmed'] ?? 0) ?> &nbsp;·&nbsp;
            expired <?= (int) ($linkCounts['expired'] ?? 0) ?>
        </div>
    </div>

    <div class="stat stat--success">
        <div class="stat__label"><i class="bi bi-cash-coin"></i>今日のマッチ済み入金</div>
        <div class="stat__value">
            <span class="stat__value--mono"><?= (int) ($todayDeposits['matched'] ?? 0) ?></span>
            <span class="stat__unit">/ <?= (int) ($todayDeposits['total'] ?? 0) ?></span>
        </div>
        <div class="stat__sub">マッチ済み / 合計</div>
    </div>

    <div class="stat stat--accent">
        <div class="stat__label"><i class="bi bi-graph-up-arrow"></i>今週の売上</div>
        <div class="stat__value">
            <span class="stat__unit">¥</span>
            <span class="stat__value--mono"><?= number_format(array_sum($revenueDays)) ?></span>
        </div>
        <div class="stat__sub">確認済み金額（7日間）</div>
    </div>

    <div class="stat">
        <div class="stat__label"><i class="bi bi-building"></i>アクティブリソース</div>
        <div class="stat__value">
            <span class="stat__value--mono"><?= (int) $activeBanks ?></span>
            <span class="stat__unit">口座</span>
        </div>
        <div class="stat__sub">APIクライアント <?= (int) $activeClients ?> 件</div>
    </div>
</div>

<!-- Chart + Scraper grid -->
<div style="display:grid;grid-template-columns:1fr 380px;gap:16px;margin-bottom:24px;">

    <!-- Revenue Chart -->
    <div class="card">
        <div class="card__header">
            <span class="card__title"><i class="bi bi-graph-up-arrow"></i>過去7日の確認済み売上</span>
        </div>
        <div class="card__body">
            <div class="chart-wrap">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Scraper Status -->
    <div class="card">
        <div class="card__header">
            <span class="card__title"><i class="bi bi-activity"></i>スクレイパー状態</span>
        </div>
        <?php if (empty($scraperStatus)): ?>
        <div class="card__body">
            <p class="text-muted" style="font-size:13px;margin:0;">銀行口座がありません。</p>
        </div>
        <?php else: ?>
        <table class="table">
            <tbody>
                <?php foreach ($scraperStatus as $s): ?>
                <tr>
                    <td>
                        <div style="font-size:13px;font-weight:500;color:var(--fg-0);"><?= htmlspecialchars($s['bank_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="sub mono"><?= htmlspecialchars($s['account_number'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($s['last_run_at'])): ?>
                        <div class="sub">最終: <?= View::datetime($s['last_run_at']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-right nowrap">
                        <span class="badge badge--<?= View::scraperBadge($s['scrape_status']) ?>">
                            <?= htmlspecialchars($s['scrape_status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<!-- Recent Payment Links -->
<div class="table-wrap">
    <div class="card__header" style="padding:14px 16px;border-bottom:1px solid var(--border-0);display:flex;align-items:center;justify-content:space-between;">
        <span class="card__title"><i class="bi bi-clock-history"></i>最近の決済リンク</span>
        <a href="/payments" class="btn btn--ghost btn--sm">一覧を見る <i class="bi bi-arrow-right"></i></a>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>参照番号</th>
                <th>クライアント</th>
                <th>顧客名</th>
                <th class="num">金額</th>
                <th>ステータス</th>
                <th>作成日時</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentLinks)): ?>
            <tr><td colspan="6" class="table-empty" style="padding:40px 20px;">データなし</td></tr>
            <?php else: ?>
            <?php foreach ($recentLinks as $link): ?>
            <tr style="cursor:pointer;" onclick="location.href='/payments/<?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?>'">
                <td class="mono" style="color:var(--accent);"><?= htmlspecialchars($link['reference_number'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($link['client_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($link['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="num mono">¥<?= number_format((float) $link['amount']) ?></td>
                <td>
                    <span class="badge badge--<?= View::statusBadge($link['status']) ?>">
                        <?= htmlspecialchars($link['status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </td>
                <td class="text-muted nowrap"><?= View::datetime($link['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
window.addEventListener('load', function () {
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded');
        return;
    }
    const labels = <?= json_encode(array_keys($revenueDays), JSON_UNESCAPED_UNICODE) ?>;
    const values = <?= json_encode(array_values($revenueDays)) ?>;
    const ctx = document.getElementById('revenueChart');
    if (!ctx) return;

    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, 'rgba(16,185,129,0.18)');
    gradient.addColorStop(1, 'rgba(16,185,129,0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels.map(d => {
                const dt = new Date(d);
                return (dt.getMonth() + 1) + '/' + dt.getDate();
            }),
            datasets: [{
                label: '確認済み (JPY)',
                data: values,
                borderColor: '#10b981',
                borderWidth: 2,
                pointBackgroundColor: '#10b981',
                pointRadius: 3,
                pointHoverRadius: 5,
                backgroundColor: gradient,
                fill: true,
                tension: 0.35,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: c => '¥' + c.parsed.y.toLocaleString()
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { color: '#71717a', callback: v => '¥' + Number(v).toLocaleString() },
                    grid: { color: 'rgba(255,255,255,0.04)' },
                    border: { color: 'transparent' }
                },
                x: {
                    ticks: { color: '#71717a' },
                    grid: { display: false },
                    border: { color: 'transparent' }
                }
            }
        }
    });
});
</script>
