<?php use BCashPay\Admin\View;
use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="h4 mb-0"><i class="bi bi-building me-2 text-warning"></i>銀行口座</h2>
    <a href="/banks/new" class="btn btn-warning btn-sm">
        <i class="bi bi-plus-lg me-1"></i>追加
    </a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-dark table-striped mb-0">
            <thead class="table-secondary">
                <tr>
                    <th>ID</th>
                    <th>銀行名</th>
                    <th>支店</th>
                    <th>口座番号</th>
                    <th>口座名義</th>
                    <th class="text-center">スクレイパー</th>
                    <th class="text-center">状態</th>
                    <th>最終スクレイプ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($banks)): ?>
                <tr><td colspan="9" class="text-center text-secondary py-4">銀行口座がありません</td></tr>
                <?php else: ?>
                <?php foreach ($banks as $bank): ?>
                <tr>
                    <td><?= (int) $bank['id'] ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($bank['bank_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($bank['branch_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code><?= htmlspecialchars($bank['account_number'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($bank['account_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-center">
                        <?php if (!empty($bank['scrape_adapter_key'])): ?>
                        <span class="badge bg-info"><?= htmlspecialchars($bank['scrape_adapter_key'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                        <span class="text-secondary">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $bank['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $bank['is_active'] ? '有効' : '無効' ?>
                        </span>
                        <br>
                        <span class="badge bg-<?= View::scraperBadge($bank['scrape_status']) ?> mt-1">
                            <?= htmlspecialchars($bank['scrape_status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="text-secondary"><?= View::datetime($bank['scrape_last_success_at']) ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="/banks/<?= (int) $bank['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="/banks/<?= (int) $bank['id'] ?>/delete"
                                  onsubmit="return confirm('この銀行口座を無効化しますか？')">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-slash-circle"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
