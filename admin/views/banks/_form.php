<?php
// Shared form partial for bank account create/edit
// Variables: $old (array), $errors (array), $adapters (array), $accountTypes (array)
$v = fn(string $key) => htmlspecialchars((string) ($old[$key] ?? ''), ENT_QUOTES, 'UTF-8');
$err = fn(string $key) => !empty($errors[$key])
    ? '<div class="invalid-feedback">' . htmlspecialchars($errors[$key], ENT_QUOTES, 'UTF-8') . '</div>'
    : '';
$cls = fn(string $key) => !empty($errors[$key]) ? 'is-invalid' : '';
?>

<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">銀行名 <span class="text-danger">*</span></label>
        <input type="text" name="bank_name" class="form-control <?= $cls('bank_name') ?>"
               value="<?= $v('bank_name') ?>" placeholder="楽天銀行">
        <?= $err('bank_name') ?>
    </div>
    <div class="col-md-4">
        <label class="form-label">銀行コード</label>
        <input type="text" name="bank_code" class="form-control" maxlength="10"
               value="<?= $v('bank_code') ?>" placeholder="0036">
    </div>

    <div class="col-md-8">
        <label class="form-label">支店名</label>
        <input type="text" name="branch_name" class="form-control"
               value="<?= $v('branch_name') ?>" placeholder="法人営業部">
    </div>
    <div class="col-md-4">
        <label class="form-label">支店コード</label>
        <input type="text" name="branch_code" class="form-control" maxlength="10"
               value="<?= $v('branch_code') ?>" placeholder="001">
    </div>

    <div class="col-md-4">
        <label class="form-label">口座種別</label>
        <select name="account_type" class="form-select">
            <?php foreach ($accountTypes as $t): ?>
            <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($old['account_type'] ?? '普通') === $t ? 'selected' : '' ?>>
                <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-8">
        <label class="form-label">口座番号 <span class="text-danger">*</span></label>
        <input type="text" name="account_number" class="form-control <?= $cls('account_number') ?>"
               value="<?= $v('account_number') ?>" placeholder="1234567">
        <?= $err('account_number') ?>
    </div>

    <div class="col-12">
        <label class="form-label">口座名義 <span class="text-danger">*</span></label>
        <input type="text" name="account_name" class="form-control <?= $cls('account_name') ?>"
               value="<?= $v('account_name') ?>" placeholder="カ）テストコーポレーション">
        <?= $err('account_name') ?>
    </div>

    <div class="col-12">
        <div class="form-check">
            <input type="checkbox" name="is_active" class="form-check-input" id="is_active"
                   <?= ($old['is_active'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_active">有効</label>
        </div>
    </div>

    <div class="col-12"><hr class="border-secondary"><p class="text-secondary small mb-0">スクレイパー設定</p></div>

    <div class="col-md-6">
        <label class="form-label">アダプター</label>
        <select name="scrape_adapter_key" class="form-select">
            <option value="">なし</option>
            <?php foreach ($adapters as $key => $label): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($old['scrape_adapter_key'] ?? '') === $key ? 'selected' : '' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">スクレイプ間隔（分）</label>
        <input type="number" name="scrape_interval_minutes" class="form-control" min="5" max="1440"
               value="<?= htmlspecialchars((string) ($old['scrape_interval_minutes'] ?? 15), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="col-12">
        <label class="form-label">ログインURL</label>
        <input type="url" name="scrape_login_url" class="form-control"
               value="<?= $v('scrape_login_url') ?>" placeholder="https://...">
    </div>

    <div class="col-12">
        <label class="form-label">認証情報（JSON）</label>
        <textarea name="scrape_credentials_json" class="form-control font-monospace" rows="4"
                  placeholder='{"username":"user","password":"pass","totp_secret":""}'><?= $v('scrape_credentials_json') ?></textarea>
        <div class="form-text text-secondary">JSON形式で入力してください。</div>
    </div>
</div>
