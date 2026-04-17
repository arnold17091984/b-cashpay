<?php use BCashPay\Admin\View;
use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-header__title">銀行口座</h1>
        <p class="page-header__subtitle">スクレイパー対象の口座を管理します</p>
    </div>
    <div class="page-header__actions">
        <a href="/banks/new" class="btn btn--primary btn--sm">
            <i class="bi bi-plus-lg"></i>追加
        </a>
    </div>
</div>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th>銀行名</th>
                <th>口座番号</th>
                <th>口座名義</th>
                <th>スクレイパー</th>
                <th>状態</th>
                <th>最終スクレイプ</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($banks)): ?>
            <tr>
                <td colspan="8">
                    <div class="table-empty">
                        <i class="bi bi-bank"></i>
                        銀行口座がありません
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($banks as $bank): ?>
            <tr>
                <td class="mono text-muted"><?= (int) $bank['id'] ?></td>
                <td>
                    <span style="font-weight:500;color:var(--fg-0);"><?= htmlspecialchars($bank['bank_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="sub"><?= htmlspecialchars($bank['branch_name'], ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td class="mono"><?= htmlspecialchars($bank['account_number'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($bank['account_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php if (!empty($bank['scrape_adapter_key'])): ?>
                    <span class="badge badge--info"><?= htmlspecialchars($bank['scrape_adapter_key'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="vstack" style="gap:4px;">
                        <span class="badge badge--<?= $bank['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $bank['is_active'] ? '有効' : '無効' ?>
                        </span>
                        <span class="badge badge--<?= View::scraperBadge($bank['scrape_status']) ?>">
                            <?= htmlspecialchars($bank['scrape_status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                </td>
                <td class="text-muted nowrap"><?= View::datetime($bank['scrape_last_success_at']) ?></td>
                <td>
                    <div class="hstack" style="gap:4px;">
                        <a href="/banks/<?= (int) $bank['id'] ?>/edit"
                           class="btn btn--ghost btn--icon-only" title="編集">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" action="/banks/<?= (int) $bank['id'] ?>/delete"
                              onsubmit="return confirm('この銀行口座を無効化しますか？')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn--danger btn--icon-only" title="無効化">
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
