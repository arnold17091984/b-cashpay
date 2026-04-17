<?php use BCashPay\Admin\View;
use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-header__title">API クライアント</h1>
        <p class="page-header__subtitle">外部システムの接続キーを管理します</p>
    </div>
    <div class="page-header__actions">
        <a href="/clients/new" class="btn btn--primary btn--sm">
            <i class="bi bi-plus-lg"></i>追加
        </a>
    </div>
</div>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th>名前</th>
                <th>API キー</th>
                <th>コールバック URL</th>
                <th>状態</th>
                <th>作成</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($clients)): ?>
            <tr>
                <td colspan="7">
                    <div class="table-empty">
                        <i class="bi bi-key"></i>
                        API クライアントがありません
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($clients as $c): ?>
            <tr>
                <td class="mono text-muted"><?= (int) $c['id'] ?></td>
                <td style="font-weight:500;color:var(--fg-0);"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <div class="hstack" style="gap:6px;">
                        <span class="mono api-key-masked"
                              style="font-size:12px;color:var(--fg-2);"
                              data-key="<?= htmlspecialchars($c['api_key'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(substr($c['api_key'], 0, 8), ENT_QUOTES, 'UTF-8') ?>••••••••••••••••
                        </span>
                        <button type="button"
                                class="btn btn--ghost btn--icon-only"
                                onclick="toggleApiKey(this)"
                                title="表示 / 非表示"
                                style="width:24px;height:24px;">
                            <i class="bi bi-eye" style="font-size:12px;"></i>
                        </button>
                        <button type="button"
                                class="copy-btn"
                                onclick="copyToClipboard('<?= htmlspecialchars($c['api_key'], ENT_QUOTES, 'UTF-8') ?>', this)"
                                title="コピー">
                            <i class="bi bi-clipboard"></i> コピー
                        </button>
                    </div>
                </td>
                <td class="truncate" style="max-width:220px;font-size:12px;">
                    <?= htmlspecialchars($c['callback_url'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td>
                    <span class="badge badge--<?= $c['is_active'] ? 'active' : 'inactive' ?>">
                        <?= $c['is_active'] ? '有効' : '無効' ?>
                    </span>
                </td>
                <td class="text-muted nowrap"><?= View::datetime($c['created_at'], 'Y/m/d') ?></td>
                <td>
                    <div class="hstack" style="gap:4px;">
                        <form method="POST" action="/clients/<?= (int) $c['id'] ?>/rotate"
                              onsubmit="return confirm('API キーをローテーションしますか？既存のキーは無効になります。')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn--ghost btn--icon-only" title="キーローテーション">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                        </form>
                        <form method="POST" action="/clients/<?= (int) $c['id'] ?>/delete"
                              onsubmit="return confirm('この API クライアントを無効化しますか？')">
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
