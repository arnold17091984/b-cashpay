<?php
use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
$csrf = Auth::csrfToken();
$canCancel = $link['status'] === 'pending';
$canMatch  = $link['status'] === 'pending' && $deposit === null;
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="/payments" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h2 class="h4 mb-0">
        <i class="bi bi-link-45deg me-2 text-warning"></i>
        決済詳細
        <code class="text-info ms-2" style="font-size:.9em;"><?= htmlspecialchars($link['reference_number'], ENT_QUOTES, 'UTF-8') ?></code>
    </h2>
    <span class="badge bg-<?= View::statusBadge($link['status']) ?> fs-6">
        <?= htmlspecialchars($link['status'], ENT_QUOTES, 'UTF-8') ?>
    </span>
</div>

<div class="row g-4">

    <!-- Payment Info Card -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>決済情報</div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr><th class="text-secondary" style="width:40%;">ID</th><td><code class="small"><?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                    <tr><th class="text-secondary">参照番号</th><td><code class="text-info"><?= htmlspecialchars($link['reference_number'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                    <tr><th class="text-secondary">外部ID</th><td><?= htmlspecialchars($link['external_id'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th class="text-secondary">金額</th><td class="fw-bold text-success fs-5"><?= View::yen($link['amount']) ?></td></tr>
                    <tr><th class="text-secondary">通貨</th><td><?= htmlspecialchars($link['currency'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th class="text-secondary">顧客名</th><td><?= htmlspecialchars($link['customer_name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th class="text-secondary">顧客メール</th><td><?= htmlspecialchars($link['customer_email'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th class="text-secondary">作成日時</th><td><?= View::datetime($link['created_at']) ?></td></tr>
                    <tr><th class="text-secondary">有効期限</th>
                        <td class="<?= strtotime($link['expires_at']) < time() ? 'text-danger' : '' ?>">
                            <?= View::datetime($link['expires_at']) ?>
                        </td>
                    </tr>
                    <?php if (!empty($link['confirmed_at'])): ?>
                    <tr><th class="text-secondary">確認日時</th><td class="text-success"><?= View::datetime($link['confirmed_at']) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($link['cancelled_at'])): ?>
                    <tr><th class="text-secondary">キャンセル日時</th><td class="text-danger"><?= View::datetime($link['cancelled_at']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th class="text-secondary">コールバックURL</th><td class="small text-break"><?= htmlspecialchars($link['callback_url'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Bank Account Info -->
    <div class="col-12 col-lg-6">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-building me-2"></i>振込先口座</div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr><th class="text-secondary" style="width:40%;">銀行名</th><td><?= htmlspecialchars($link['bank_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th class="text-secondary">支店名</th><td><?= htmlspecialchars($link['branch_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th class="text-secondary">口座種別</th><td><?= htmlspecialchars($link['account_type'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th class="text-secondary">口座番号</th><td><code><?= htmlspecialchars($link['account_number'] ?? '-', ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                    <tr><th class="text-secondary">口座名義</th><td><?= htmlspecialchars($link['account_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Matched Deposit -->
        <div class="card">
            <div class="card-header"><i class="bi bi-cash-coin me-2"></i>マッチした入金</div>
            <div class="card-body">
                <?php if ($deposit !== null): ?>
                <table class="table table-sm table-borderless">
                    <tr><th class="text-secondary" style="width:40%;">入金ID</th><td><?= (int) $deposit['id'] ?></td></tr>
                    <tr><th class="text-secondary">振込人名</th><td><?= htmlspecialchars($deposit['depositor_name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th class="text-secondary">金額</th><td class="fw-bold text-success"><?= View::yen($deposit['amount']) ?></td></tr>
                    <tr><th class="text-secondary">取引日</th><td><?= View::datetime($deposit['transaction_date']) ?></td></tr>
                    <tr><th class="text-secondary">マッチ日時</th><td><?= View::datetime($deposit['matched_at']) ?></td></tr>
                    <tr><th class="text-secondary">銀行取引ID</th><td><code class="small"><?= htmlspecialchars($deposit['bank_transaction_id'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                </table>
                <?php else: ?>
                <p class="text-secondary mb-0"><i class="bi bi-dash-circle me-1"></i>入金未マッチ</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <?php if ($canCancel || $canMatch): ?>
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header text-warning"><i class="bi bi-tools me-2"></i>アクション</div>
            <div class="card-body d-flex flex-wrap gap-3">
                <?php if ($canCancel): ?>
                <form method="POST" action="/payments/<?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?>/cancel"
                      onsubmit="return confirm('この決済リンクをキャンセルしますか？')">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle me-1"></i>キャンセル
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($canMatch && !empty($unmatchedDeposits)): ?>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#matchModal">
                    <i class="bi bi-link me-1"></i>手動マッチ
                </button>
                <?php elseif ($canMatch): ?>
                <span class="text-secondary align-self-center">
                    <i class="bi bi-info-circle me-1"></i>マッチ可能な入金がありません
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Webhook Logs -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-send me-2"></i>Webhookログ</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-dark mb-0">
                    <thead class="table-secondary">
                        <tr>
                            <th>#</th>
                            <th>URL</th>
                            <th class="text-center">レスポンス</th>
                            <th>送信日時</th>
                            <th>作成日時</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($webhookLogs)): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-3">ログなし</td></tr>
                        <?php else: ?>
                        <?php foreach ($webhookLogs as $log): ?>
                        <tr>
                            <td><?= (int) $log['attempt'] ?></td>
                            <td class="small text-break"><?= htmlspecialchars($log['url'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center">
                                <?php $code = (int) ($log['response_code'] ?? 0); ?>
                                <span class="badge bg-<?= $code >= 200 && $code < 300 ? 'success' : 'danger' ?>">
                                    <?= $code ?: '-' ?>
                                </span>
                            </td>
                            <td class="text-secondary"><?= View::datetime($log['delivered_at']) ?></td>
                            <td class="text-secondary"><?= View::datetime($log['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Manual Match Modal -->
<?php if ($canMatch && !empty($unmatchedDeposits)): ?>
<div class="modal fade" id="matchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link me-2"></i>手動マッチ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/payments/<?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?>/match">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-body">
                    <p class="text-secondary">以下の未マッチ入金から選択してください:</p>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead><tr>
                                <th></th><th>振込人名</th><th class="text-end">金額</th><th>取引日</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($unmatchedDeposits as $ud): ?>
                                <tr>
                                    <td>
                                        <input type="radio" name="deposit_id" class="form-check-input"
                                               value="<?= (int) $ud['id'] ?>" required>
                                    </td>
                                    <td><?= htmlspecialchars($ud['depositor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end <?= (float)$ud['amount'] === (float)$link['amount'] ? 'text-success fw-bold' : 'text-warning' ?>">
                                        <?= View::yen($ud['amount']) ?>
                                    </td>
                                    <td class="text-secondary"><?= View::datetime($ud['transaction_date']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success">マッチ実行</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
