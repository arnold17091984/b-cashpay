<?php
/** @var bool $botEnabled */
/** @var array<int, array<string, mixed>> $admins */
/** @var array<string, mixed>|null $freshToken */
/** @var array<int, array<string, mixed>> $activeTokens */
/** @var string $csrf */

$escape = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$flash      = $_SESSION['flash'] ?? null;        unset($_SESSION['flash']);
$flashError = $_SESSION['flash_error'] ?? null;  unset($_SESSION['flash_error']);
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Telegram 設定</h1>
        <p class="page-header__subtitle">チャットからの決済リンク発行を管理します。</p>
    </div>
</div>

<?php if ($flash): ?>
<div class="alert alert--success" style="margin-bottom:16px;"><?= $escape((string) $flash) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert--danger" style="margin-bottom:16px;"><?= $escape((string) $flashError) ?></div>
<?php endif; ?>

<!-- Bot enable/disable (kill switch) -->
<div class="card" style="margin-bottom:16px;">
    <div class="card__header">
        <span class="card__title">
            <i class="bi bi-power"></i>
            Bot 稼働状態
        </span>
    </div>
    <div class="card__body" style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
        <div>
            <div style="font-size:15px;font-weight:500;">
                <?php if ($botEnabled): ?>
                    <span style="color:var(--success);">● 有効</span> — チャットからの /new コマンドを受け付け中
                <?php else: ?>
                    <span style="color:var(--danger);">● 無効</span> — 全ての Telegram コマンドを無視します
                <?php endif; ?>
            </div>
            <div class="sub" style="margin-top:4px;">
                緊急時の kill switch。無効化しても発行済みリンクは通常どおり機能します。
            </div>
        </div>
        <form method="POST" action="/settings/telegram/toggle">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="enable" value="<?= $botEnabled ? '0' : '1' ?>">
            <button type="submit" class="btn <?= $botEnabled ? 'btn--danger' : 'btn--primary' ?>">
                <?= $botEnabled ? '無効化' : '有効化' ?>
            </button>
        </form>
    </div>
</div>

<!-- Freshly-issued token (one-time display) -->
<?php if ($freshToken): ?>
<div class="card" style="margin-bottom:16px;border:1px solid var(--accent);">
    <div class="card__header">
        <span class="card__title" style="color:var(--accent);">
            <i class="bi bi-key"></i>
            Bind トークン発行済み
        </span>
    </div>
    <div class="card__body">
        <p style="margin:0 0 12px 0;">
            <b><?= $escape((string) $freshToken['username']) ?></b> 用の一時トークンです。
            Telegram 群で以下を送信して紐付けてください:
        </p>
        <div class="mono" style="background:var(--bg-2);padding:12px;border-radius:6px;font-size:14px;user-select:all;">
            /bind <?= $escape((string) $freshToken['token']) ?>
        </div>
        <p class="sub" style="margin:8px 0 0 0;">
            <i class="bi bi-clock"></i>
            有効期限: <?= $escape((string) $freshToken['expires_at']) ?> (15分)
        </p>
    </div>
</div>
<?php endif; ?>

<!-- Admin user list + per-user binding controls -->
<div class="card">
    <div class="card__header">
        <span class="card__title">
            <i class="bi bi-people"></i>
            管理者と Telegram 紐付け
        </span>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>管理者</th>
                <th>Telegram user_id</th>
                <th class="text-right">1件あたり上限</th>
                <th class="text-right">24時間累計上限</th>
                <th class="text-right">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($admins as $a): ?>
            <tr>
                <td>
                    <div style="font-weight:500;"><?= $escape((string) ($a['name'] ?: $a['username'])) ?></div>
                    <div class="sub mono"><?= $escape((string) $a['username']) ?> · <?= $escape((string) $a['role']) ?></div>
                </td>
                <td class="mono">
                    <?php if ($a['telegram_user_id']): ?>
                        <span style="color:var(--success);">●</span>
                        <?= $escape((string) $a['telegram_user_id']) ?>
                        <?php if (!$a['telegram_enabled']): ?>
                            <span class="badge badge--warning">無効</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">未紐付け</span>
                    <?php endif; ?>
                </td>
                <td class="text-right mono">¥<?= number_format((int) $a['per_link_cap']) ?></td>
                <td class="text-right mono">¥<?= number_format((int) $a['daily_amount_cap']) ?></td>
                <td class="text-right">
                    <?php if ($a['telegram_user_id']): ?>
                    <form method="POST" action="/settings/telegram/unbind" style="display:inline;"
                          onsubmit="return confirm('この管理者の Telegram 紐付けを解除しますか？');">
                        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                        <input type="hidden" name="admin_user_id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="btn btn--ghost btn--sm">
                            <i class="bi bi-unlink"></i> 解除
                        </button>
                    </form>
                    <?php else: ?>
                    <form method="POST" action="/settings/telegram/bind" style="display:inline;">
                        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                        <input type="hidden" name="admin_user_id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="btn btn--primary btn--sm">
                            <i class="bi bi-key"></i> /bind トークン発行
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Active (unconsumed) tokens -->
<?php if (!empty($activeTokens)): ?>
<div class="card" style="margin-top:16px;">
    <div class="card__header">
        <span class="card__title">
            <i class="bi bi-hourglass-split"></i>
            未消費トークン
        </span>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>管理者</th>
                <th>トークン</th>
                <th class="text-right">期限</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($activeTokens as $t): ?>
            <tr>
                <td><?= $escape((string) $t['username']) ?></td>
                <td class="mono" style="user-select:all;">/bind <?= $escape((string) $t['token']) ?></td>
                <td class="text-right sub"><?= $escape((string) $t['expires_at']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
