<?php
// Shared form partial for bank account create/edit
// Variables: $old (array), $errors (array), $adapters (array), $accountTypes (array)
$v = fn(string $key) => htmlspecialchars((string) ($old[$key] ?? ''), ENT_QUOTES, 'UTF-8');
$err = fn(string $key) => !empty($errors[$key])
    ? '<p class="field__hint" style="color:var(--danger);">' . htmlspecialchars($errors[$key], ENT_QUOTES, 'UTF-8') . '</p>'
    : '';
$hasErr = fn(string $key) => !empty($errors[$key]);
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">

    <div class="field" style="grid-column:1 / 2;">
        <label class="field__label" for="bank_name">銀行名 <span style="color:var(--danger);">*</span></label>
        <input type="text" id="bank_name" name="bank_name" class="input"
               value="<?= $v('bank_name') ?>" placeholder="楽天銀行"
               style="<?= $hasErr('bank_name') ? 'border-color:var(--danger);' : '' ?>">
        <?= $err('bank_name') ?>
    </div>

    <div class="field" style="grid-column:2 / 3;">
        <label class="field__label" for="bank_code">銀行コード</label>
        <input type="text" id="bank_code" name="bank_code" class="input mono" maxlength="10"
               value="<?= $v('bank_code') ?>" placeholder="0036">
    </div>

    <div class="field" style="grid-column:1 / 2;">
        <label class="field__label" for="branch_name">支店名</label>
        <input type="text" id="branch_name" name="branch_name" class="input"
               value="<?= $v('branch_name') ?>" placeholder="法人営業部">
    </div>

    <div class="field" style="grid-column:2 / 3;">
        <label class="field__label" for="branch_code">支店コード</label>
        <input type="text" id="branch_code" name="branch_code" class="input mono" maxlength="10"
               value="<?= $v('branch_code') ?>" placeholder="001">
    </div>

    <div class="field" style="grid-column:1 / 2;">
        <label class="field__label" for="account_type">口座種別</label>
        <select id="account_type" name="account_type" class="select">
            <?php foreach ($accountTypes as $t): ?>
            <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($old['account_type'] ?? '普通') === $t ? 'selected' : '' ?>>
                <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field" style="grid-column:2 / 3;">
        <label class="field__label" for="account_number">口座番号 <span style="color:var(--danger);">*</span></label>
        <input type="text" id="account_number" name="account_number" class="input mono"
               value="<?= $v('account_number') ?>" placeholder="1234567"
               style="<?= $hasErr('account_number') ? 'border-color:var(--danger);' : '' ?>">
        <?= $err('account_number') ?>
    </div>

    <div class="field" style="grid-column:1 / -1;">
        <label class="field__label" for="account_name">口座名義 <span style="color:var(--danger);">*</span></label>
        <input type="text" id="account_name" name="account_name" class="input"
               value="<?= $v('account_name') ?>" placeholder="カ）テストコーポレーション"
               style="<?= $hasErr('account_name') ? 'border-color:var(--danger);' : '' ?>">
        <?= $err('account_name') ?>
    </div>

    <div class="field" style="grid-column:1 / -1;">
        <div class="form-check">
            <input type="checkbox" id="is_active" name="is_active" class="form-check-input"
                   <?= ($old['is_active'] ?? 1) ? 'checked' : '' ?>>
            <label class="field__label" for="is_active" style="margin:0;">有効</label>
        </div>
    </div>

</div>

<div style="height:1px;background:var(--border-0);margin:20px 0;"></div>
<p style="font-size:11px;font-weight:600;color:var(--fg-3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px;">スクレイパー設定</p>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">

    <div class="field" style="grid-column:1 / 2;">
        <label class="field__label" for="scrape_adapter_key">アダプター</label>
        <select id="scrape_adapter_key" name="scrape_adapter_key" class="select">
            <option value="">なし</option>
            <?php foreach ($adapters as $key => $label): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($old['scrape_adapter_key'] ?? '') === $key ? 'selected' : '' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field" style="grid-column:2 / 3;">
        <label class="field__label" for="scrape_interval_minutes">スクレイプ間隔（分）</label>
        <input type="number" id="scrape_interval_minutes" name="scrape_interval_minutes" class="input mono"
               min="5" max="1440"
               value="<?= htmlspecialchars((string) ($old['scrape_interval_minutes'] ?? 15), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="field" style="grid-column:1 / -1;">
        <label class="field__label" for="scrape_login_url">ログインURL</label>
        <input type="url" id="scrape_login_url" name="scrape_login_url" class="input"
               value="<?= $v('scrape_login_url') ?>" placeholder="https://...">
    </div>

    <div class="field" style="grid-column:1 / -1;">
        <label class="field__label" for="scrape_credentials_json">認証情報（JSON）</label>
        <textarea id="scrape_credentials_json" name="scrape_credentials_json" class="input mono"
                  rows="4" style="height:auto;resize:vertical;"
                  placeholder='{"username":"user","password":"pass","totp_secret":""}'><?= $v('scrape_credentials_json') ?></textarea>
        <p class="field__hint">JSON形式で入力してください。保存時に暗号化されます。</p>
    </div>

</div>
