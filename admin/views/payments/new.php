<?php
/** @var array $clients */
/** @var array $banks */
/** @var array $old */
/** @var array $errors */
/** @var string $csrf */

$err = fn(string $k): string =>
    isset($errors[$k])
        ? '<p class="field__error">' . htmlspecialchars($errors[$k], ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

$old_val = fn(string $k, string $default = ''): string =>
    htmlspecialchars((string) ($old[$k] ?? $default), ENT_QUOTES, 'UTF-8');
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">決済リンクを新規作成</h1>
        <p class="page-header__subtitle">お客様への振込案内用リンクを発行します。</p>
    </div>
    <div class="page-header__actions">
        <a href="/payments" class="btn btn--ghost btn--sm">
            <i class="bi bi-arrow-left"></i>戻る
        </a>
    </div>
</div>

<div style="max-width: 640px;">
<form method="POST" action="/payments" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

    <div class="card">
        <div class="card__header">
            <div class="card__title">
                <i class="bi bi-currency-yen"></i>
                決済情報
            </div>
        </div>
        <div class="card__body">

            <div class="field">
                <label class="field__label" for="amount">振込金額（円）<span style="color: var(--danger);">*</span></label>
                <input
                    type="number"
                    id="amount"
                    name="amount"
                    class="input"
                    min="1"
                    max="9999999"
                    step="1"
                    required
                    placeholder="50000"
                    value="<?= $old_val('amount') ?>"
                    style="font-size: 18px; font-family: 'JetBrains Mono', monospace; font-weight: 600;"
                >
                <p class="field__hint">税込・整数のみ</p>
                <?= $err('amount') ?>
            </div>

            <div class="field">
                <label class="field__label" for="customer_name">お客様氏名（表示用）<span style="color: var(--danger);">*</span></label>
                <input
                    type="text"
                    id="customer_name"
                    name="customer_name"
                    class="input"
                    required
                    placeholder="山田 太郎"
                    value="<?= $old_val('customer_name') ?>"
                >
                <p class="field__hint">管理画面・メール本文等に表示される名前（漢字可）</p>
                <?= $err('customer_name') ?>
            </div>

            <div class="field">
                <label class="field__label" for="customer_kana">振込依頼人名（カタカナ）<span style="color: var(--danger);">*</span></label>
                <input
                    type="text"
                    id="customer_kana"
                    name="customer_kana"
                    class="input"
                    required
                    placeholder="ヤマダ タロウ"
                    value="<?= $old_val('customer_kana') ?>"
                    style="font-feature-settings: 'palt';"
                >
                <p class="field__hint">
                    <strong style="color: var(--warning);">重要</strong>:
                    銀行振込時に入力される依頼人名。参照番号 + この文字列で入金を自動照合します。
                </p>
                <?= $err('customer_kana') ?>
            </div>

            <div class="field">
                <label class="field__label" for="customer_email">メールアドレス（任意）</label>
                <input
                    type="email"
                    id="customer_email"
                    name="customer_email"
                    class="input"
                    placeholder="yamada@example.com"
                    value="<?= $old_val('customer_email') ?>"
                >
                <p class="field__hint">記録用のみ（B-Pay からの自動送信はありません）</p>
                <?= $err('customer_email') ?>
            </div>

            <div class="field">
                <label class="field__label" for="external_id">外部参照 ID（任意）</label>
                <input
                    type="text"
                    id="external_id"
                    name="external_id"
                    class="input"
                    placeholder="TELEBET-ORDER-12345"
                    value="<?= $old_val('external_id') ?>"
                >
                <p class="field__hint">Telebet 等の外部システムの注文番号</p>
            </div>

        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="card__header">
            <div class="card__title">
                <i class="bi bi-gear"></i>
                設定
            </div>
        </div>
        <div class="card__body">

            <div class="grid-2">
                <div class="field">
                    <label class="field__label" for="api_client_id">API クライアント<span style="color: var(--danger);">*</span></label>
                    <select id="api_client_id" name="api_client_id" class="input" required>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= ((int) ($old['api_client_id'] ?? $clients[0]['id']) === (int) $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('api_client_id') ?>
                </div>

                <div class="field">
                    <label class="field__label" for="bank_account_id">振込先銀行口座<span style="color: var(--danger);">*</span></label>
                    <select id="bank_account_id" name="bank_account_id" class="input" required>
                        <?php foreach ($banks as $b): ?>
                            <option value="<?= (int) $b['id'] ?>"
                                <?= ((int) ($old['bank_account_id'] ?? $banks[0]['id']) === (int) $b['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(
                                    ($b['bank_name'] ?? '') . ' ' . ($b['branch_name'] ?? '') . ' ' . ($b['account_number'] ?? ''),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('bank_account_id') ?>
                </div>
            </div>

            <div class="field" style="margin-bottom: 0;">
                <label class="field__label" for="expires_hours">有効期限</label>
                <select id="expires_hours" name="expires_hours" class="input" style="max-width: 240px;">
                    <option value="24"  <?= ((int) ($old['expires_hours'] ?? 72) === 24)  ? 'selected' : '' ?>>24 時間後</option>
                    <option value="48"  <?= ((int) ($old['expires_hours'] ?? 72) === 48)  ? 'selected' : '' ?>>2 日後</option>
                    <option value="72"  <?= ((int) ($old['expires_hours'] ?? 72) === 72)  ? 'selected' : '' ?>>3 日後（既定）</option>
                    <option value="168" <?= ((int) ($old['expires_hours'] ?? 72) === 168) ? 'selected' : '' ?>>1 週間後</option>
                    <option value="720" <?= ((int) ($old['expires_hours'] ?? 72) === 720) ? 'selected' : '' ?>>30 日後</option>
                </select>
            </div>

        </div>
    </div>

    <div class="hstack" style="margin-top: 20px; justify-content: flex-end;">
        <a href="/payments" class="btn btn--ghost">キャンセル</a>
        <button type="submit" class="btn btn--primary">
            <i class="bi bi-link-45deg"></i>
            決済リンクを発行
        </button>
    </div>
</form>
</div>

<style>
.field__error {
    color: var(--danger);
    font-size: 11px;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.field__error::before { content: '⚠'; }
</style>
