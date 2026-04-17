<?php use BCashPay\Admin\View; ?>

<!-- Page Header -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="h4 mb-0"><i class="bi bi-speedometer2 me-2 text-warning"></i>ダッシュボード</h2>
    <span class="text-secondary small"><?= date('Y年m月d日 H:i') ?> 更新</span>
</div>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-warning h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-secondary small mb-1">本日 pending</p>
                        <h3 class="fw-bold text-warning mb-0"><?= (int) ($linkCounts['pending'] ?? 0) ?></h3>
                    </div>
                    <i class="bi bi-hourglass-split fs-2 text-warning opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-success h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-secondary small mb-1">本日 confirmed</p>
                        <h3 class="fw-bold text-success mb-0"><?= (int) ($linkCounts['confirmed'] ?? 0) ?></h3>
                    </div>
                    <i class="bi bi-check-circle-fill fs-2 text-success opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-info h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-secondary small mb-1">本日の入金</p>
                        <h3 class="fw-bold text-info mb-0"><?= (int) ($todayDeposits['matched'] ?? 0) ?> / <?= (int) ($todayDeposits['total'] ?? 0) ?></h3>
                        <small class="text-secondary">マッチ済み / 合計</small>
                    </div>
                    <i class="bi bi-cash-coin fs-2 text-info opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-secondary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-secondary small mb-1">アクティブ</p>
                        <h3 class="fw-bold mb-0"><?= (int) $activeBanks ?> 口座 / <?= (int) $activeClients ?> クライアント</h3>
                    </div>
                    <i class="bi bi-building fs-2 opacity-25"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Revenue Chart -->
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-graph-up-arrow text-success"></i>
                <strong>過去7日の確認済み売上</strong>
            </div>
            <div class="card-body">
                <canvas id="revenueChart" height="100"></canvas>
            </div>
        </div>
    </div>

    <!-- Scraper Status -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-robot text-info"></i>
                <strong>スクレイパー状態</strong>
            </div>
            <div class="card-body p-0">
                <?php if (empty($scraperStatus)): ?>
                <p class="text-secondary p-3 mb-0">銀行口座がありません。</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($scraperStatus as $s): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($s['bank_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <small class="text-secondary"><?= htmlspecialchars($s['account_number'], ENT_QUOTES, 'UTF-8') ?></small>
                            <?php if (!empty($s['last_run_at'])): ?>
                            <br><small class="text-secondary">最終: <?= View::datetime($s['last_run_at']) ?></small>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-<?= View::scraperBadge($s['scrape_status']) ?>">
                            <?= htmlspecialchars($s['scrape_status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Payment Links -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-clock-history text-warning"></i>
            <strong>最近の決済リンク</strong>
        </div>
        <a href="/payments" class="btn btn-sm btn-outline-secondary">一覧を見る</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-dark mb-0">
            <thead class="table-secondary">
                <tr>
                    <th>参照番号</th>
                    <th>クライアント</th>
                    <th>顧客名</th>
                    <th class="text-end">金額</th>
                    <th class="text-center">ステータス</th>
                    <th>作成日時</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentLinks)): ?>
                <tr><td colspan="6" class="text-center text-secondary py-4">データなし</td></tr>
                <?php else: ?>
                <?php foreach ($recentLinks as $link): ?>
                <tr class="cursor-pointer" onclick="location.href='/payments/<?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?>'">
                    <td><code class="text-info"><?= htmlspecialchars($link['reference_number'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($link['client_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($link['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-end fw-semibold"><?= View::yen($link['amount']) ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= View::statusBadge($link['status']) ?>">
                            <?= htmlspecialchars($link['status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="text-secondary"><?= View::datetime($link['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function() {
    const labels = <?= json_encode(array_keys($revenueDays), JSON_UNESCAPED_UNICODE) ?>;
    const values = <?= json_encode(array_values($revenueDays)) ?>;

    const ctx = document.getElementById('revenueChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels.map(d => {
                const dt = new Date(d);
                return (dt.getMonth() + 1) + '/' + dt.getDate();
            }),
            datasets: [{
                label: '確認済み金額 (JPY)',
                data: values,
                backgroundColor: 'rgba(25, 135, 84, 0.5)',
                borderColor: 'rgba(25, 135, 84, 1)',
                borderWidth: 1,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => '¥' + ctx.parsed.y.toLocaleString()
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => '¥' + Number(v).toLocaleString()
                    },
                    grid: { color: 'rgba(255,255,255,0.05)' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>
