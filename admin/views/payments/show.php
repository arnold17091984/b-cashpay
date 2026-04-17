<?php
use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
$csrf = Auth::csrfToken();
$canCancel = $link['status'] === 'pending';
$canMatch  = $link['status'] === 'pending' && $deposit === null;
$paymentUrl = rtrim($_SERVER['HTTP_HOST'] ?? '', '/') . '/pay/' . htmlspecialchars($link['reference_number'], ENT_QUOTES, 'UTF-8');
?>

<!-- Page Header -->
<div class="page-header">
    <div class="hstack gap-lg">
        <a href="/payments" class="btn btn--ghost btn--icon-only" title="戻る">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <div class="hstack">
                <h1 class="page-header__title mono" style="font-size:18px;"><?= htmlspecialchars($link['reference_number'], ENT_QUOTES, 'UTF-8') ?></h1>
                <span class="badge badge--<?= View::statusBadge($link['status']) ?>" style="font-size:12px;"><?= htmlspecialchars($link['status'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <p class="page-header__subtitle">決済詳細</p>
        </div>
    </div>
</div>

<!-- Two-column layout -->
<div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

    <!-- Left column -->
    <div class="vstack gap-lg">

        <!-- Payment Info -->
        <div class="card">
            <div class="card__header">
                <span class="card__title"><i class="bi bi-info-circle"></i>決済情報</span>
            </div>
            <div class="card__body">
                <dl class="kv">
                    <dt>ID</dt>
                    <dd class="mono" style="font-size:11px;"><?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>参照番号</dt>
                    <dd class="mono" style="color:var(--accent);"><?= htmlspecialchars($link['reference_number'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>外部ID</dt>
                    <dd><?= htmlspecialchars($link['external_id'] ?? '-', ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>金額</dt>
                    <dd class="mono" style="font-size:18px;color:var(--success);">¥<?= number_format((float) $link['amount']) ?></dd>

                    <dt>通貨</dt>
                    <dd><?= htmlspecialchars($link['currency'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>顧客名</dt>
                    <dd><?= htmlspecialchars($link['customer_name'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>顧客メール</dt>
                    <dd><?= htmlspecialchars($link['customer_email'] ?? '-', ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>コールバックURL</dt>
                    <dd class="truncate" style="max-width:400px;font-size:12px;"><?= htmlspecialchars($link['callback_url'] ?? '-', ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>作成日時</dt>
                    <dd class="text-muted"><?= View::datetime($link['created_at']) ?></dd>

                    <dt>有効期限</dt>
                    <dd style="<?= strtotime($link['expires_at']) < time() ? 'color:var(--danger);' : '' ?>">
                        <?= View::datetime($link['expires_at']) ?>
                    </dd>

                    <?php if (!empty($link['confirmed_at'])): ?>
                    <dt>確認日時</dt>
                    <dd style="color:var(--success);"><?= View::datetime($link['confirmed_at']) ?></dd>
                    <?php endif; ?>

                    <?php if (!empty($link['cancelled_at'])): ?>
                    <dt>キャンセル日時</dt>
                    <dd style="color:var(--danger);"><?= View::datetime($link['cancelled_at']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Bank Account -->
        <div class="card">
            <div class="card__header">
                <span class="card__title"><i class="bi bi-bank"></i>振込先口座</span>
            </div>
            <div class="card__body">
                <dl class="kv">
                    <dt>銀行名</dt>
                    <dd><?= htmlspecialchars($link['bank_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>支店名</dt>
                    <dd><?= htmlspecialchars($link['branch_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>口座種別</dt>
                    <dd><?= htmlspecialchars($link['account_type'] ?? '-', ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>口座番号</dt>
                    <dd class="mono"><?= htmlspecialchars($link['account_number'] ?? '-', ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>口座名義</dt>
                    <dd><?= htmlspecialchars($link['account_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>

        <!-- Matched Deposit -->
        <div class="card">
            <div class="card__header">
                <span class="card__title"><i class="bi bi-cash-coin"></i>入金情報</span>
            </div>
            <div class="card__body">
                <?php if ($deposit !== null): ?>
                <dl class="kv">
                    <dt>入金ID</dt>
                    <dd class="mono"><?= (int) $deposit['id'] ?></dd>

                    <dt>振込人名</dt>
                    <dd><?= htmlspecialchars($deposit['depositor_name'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt>金額</dt>
                    <dd class="mono" style="color:var(--success);">¥<?= number_format((float) $deposit['amount']) ?></dd>

                    <dt>取引日</dt>
                    <dd class="text-muted"><?= View::datetime($deposit['transaction_date']) ?></dd>

                    <dt>マッチ日時</dt>
                    <dd class="text-muted"><?= View::datetime($deposit['matched_at']) ?></dd>

                    <dt>銀行取引ID</dt>
                    <dd class="mono" style="font-size:11px;"><?= htmlspecialchars($deposit['bank_transaction_id'], ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
                <?php else: ?>
                <div style="padding:24px 0;text-align:center;color:var(--fg-3);">
                    <i class="bi bi-dash-circle" style="font-size:24px;display:block;margin-bottom:8px;color:var(--fg-4);"></i>
                    未マッチ
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Webhook Logs -->
        <div class="table-wrap">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border-0);">
                <span class="card__title"><i class="bi bi-send"></i>Webhookログ</span>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>URL</th>
                        <th style="width:80px;text-align:center;">レスポンス</th>
                        <th>送信日時</th>
                        <th>作成日時</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($webhookLogs)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="table-empty"><i class="bi bi-send-slash"></i>ログなし</div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($webhookLogs as $log): ?>
                    <tr>
                        <td class="mono text-muted"><?= (int) $log['attempt'] ?></td>
                        <td class="truncate" style="max-width:280px;font-size:12px;"><?= htmlspecialchars($log['url'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="text-align:center;">
                            <?php $code = (int) ($log['response_code'] ?? 0); ?>
                            <span class="badge badge--<?= ($code >= 200 && $code < 300) ? 'confirmed' : 'failed' ?>">
                                <?= $code ?: '-' ?>
                            </span>
                        </td>
                        <td class="text-muted nowrap"><?= View::datetime($log['delivered_at']) ?></td>
                        <td class="text-muted nowrap"><?= View::datetime($log['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- Right column: Actions -->
    <div class="vstack gap-lg">

        <div class="card">
            <div class="card__header">
                <span class="card__title"><i class="bi bi-tools"></i>アクション</span>
            </div>
            <div class="card__body vstack">

                <!-- Copy payment URL -->
                <div>
                    <div style="font-size:11px;font-weight:600;color:var(--fg-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">決済URL</div>
                    <div class="hstack" style="gap:6px;">
                        <span class="mono truncate" style="font-size:11px;color:var(--fg-2);flex:1;"><?= $paymentUrl ?></span>
                        <button type="button" class="copy-btn"
                                onclick="navigator.clipboard.writeText('<?= $paymentUrl ?>').then(()=>{this.textContent='Copied';setTimeout(()=>{this.innerHTML='<i class=\'bi bi-clipboard\'></i> コピー'},1500)})"
                                title="URLをコピー">
                            <i class="bi bi-clipboard"></i> コピー
                        </button>
                    </div>
                </div>

                <div style="height:1px;background:var(--border-0);"></div>

                <?php if ($canCancel): ?>
                <form method="POST" action="/payments/<?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?>/cancel"
                      onsubmit="return confirm('この決済リンクをキャンセルしますか？')">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn--danger btn--sm" style="width:100%;">
                        <i class="bi bi-x-circle"></i>キャンセル
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($canMatch && !empty($unmatchedDeposits)): ?>
                <button type="button" class="btn btn--primary btn--sm" style="width:100%;"
                        onclick="document.getElementById('matchModal').style.display='flex'">
                    <i class="bi bi-link"></i>手動マッチ
                </button>
                <?php elseif ($canMatch): ?>
                <p class="text-muted" style="font-size:12px;margin:0;">
                    <i class="bi bi-info-circle"></i> マッチ可能な入金がありません
                </p>
                <?php endif; ?>

            </div>
        </div>

    </div>

</div>

<!-- Manual Match Modal -->
<?php if ($canMatch && !empty($unmatchedDeposits)): ?>
<div id="matchModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:24px;">
    <div class="card" style="width:100%;max-width:640px;max-height:80vh;display:flex;flex-direction:column;">
        <div class="card__header">
            <span class="card__title"><i class="bi bi-link"></i>手動マッチ</span>
            <button type="button" class="btn btn--ghost btn--icon-only"
                    onclick="document.getElementById('matchModal').style.display='none'" title="閉じる">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <form method="POST" action="/payments/<?= htmlspecialchars($link['id'], ENT_QUOTES, 'UTF-8') ?>/match"
              style="display:flex;flex-direction:column;overflow:hidden;">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div style="overflow-y:auto;">
                <p style="padding:16px 20px 0;font-size:13px;color:var(--fg-2);">以下の未マッチ入金から選択してください:</p>
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:36px;"></th>
                            <th>振込人名</th>
                            <th class="num">金額</th>
                            <th>取引日</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unmatchedDeposits as $ud): ?>
                        <tr>
                            <td>
                                <input type="radio" name="deposit_id" class="form-check-input"
                                       value="<?= (int) $ud['id'] ?>" required>
                            </td>
                            <td><?= htmlspecialchars($ud['depositor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="num mono" style="<?= (float)$ud['amount'] === (float)$link['amount'] ? 'color:var(--success);font-weight:700;' : 'color:var(--warning);' ?>">
                                ¥<?= number_format((float) $ud['amount']) ?>
                            </td>
                            <td class="text-muted"><?= View::datetime($ud['transaction_date']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="padding:16px 20px;border-top:1px solid var(--border-0);display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn--ghost btn--sm"
                        onclick="document.getElementById('matchModal').style.display='none'">キャンセル</button>
                <button type="submit" class="btn btn--primary btn--sm">マッチ実行</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
