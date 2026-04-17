<?php

declare(strict_types=1);

namespace BCashPay\Admin\Controllers;

use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
use BCashPay\Database;

class BankAccountController
{
    private const ADAPTERS = ['rakuten' => '楽天銀行', 'gmo_aozora' => 'GMOあおぞらネット銀行'];
    private const ACCOUNT_TYPES = ['普通', '当座'];

    public function __construct(
        private readonly Auth $auth,
        private readonly Database $db,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireAuth();

        $banks = $this->db->fetchAll(
            "SELECT * FROM bank_accounts ORDER BY id ASC"
        );

        View::render('banks/index', [
            'title'      => '銀行口座',
            'banks'      => $banks,
            'currentUser' => $this->auth->user(),
        ]);
    }

    public function create(): void
    {
        $this->auth->requireAuth();

        View::render('banks/create', [
            'title'       => '銀行口座追加',
            'adapters'    => self::ADAPTERS,
            'accountTypes' => self::ACCOUNT_TYPES,
            'old'         => [],
            'errors'      => [],
            'currentUser' => $this->auth->user(),
        ]);
    }

    public function store(): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $data   = $this->extractFields();
        $errors = $this->validate($data);

        if (!empty($errors)) {
            View::render('banks/create', [
                'title'       => '銀行口座追加',
                'adapters'    => self::ADAPTERS,
                'accountTypes' => self::ACCOUNT_TYPES,
                'old'         => $data,
                'errors'      => $errors,
                'currentUser' => $this->auth->user(),
            ]);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->insert('bank_accounts', array_merge($data, [
            'scrape_status'              => 'setup_pending',
            'scrape_consecutive_failures' => 0,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]));

        View::setFlash('success', '銀行口座を追加しました。');
        header('Location: /banks');
        exit;
    }

    public function edit(int $id): void
    {
        $this->auth->requireAuth();

        $bank = $this->db->fetchOne("SELECT * FROM bank_accounts WHERE id = ? LIMIT 1", [$id]);
        if ($bank === null) {
            http_response_code(404);
            echo '<h1>404 Not Found</h1>';
            exit;
        }

        View::render('banks/edit', [
            'title'       => '銀行口座編集',
            'bank'        => $bank,
            'adapters'    => self::ADAPTERS,
            'accountTypes' => self::ACCOUNT_TYPES,
            'old'         => $bank,
            'errors'      => [],
            'currentUser' => $this->auth->user(),
        ]);
    }

    public function update(int $id): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $bank = $this->db->fetchOne("SELECT * FROM bank_accounts WHERE id = ? LIMIT 1", [$id]);
        if ($bank === null) {
            http_response_code(404);
            exit;
        }

        $data   = $this->extractFields();
        $errors = $this->validate($data, $id);

        if (!empty($errors)) {
            View::render('banks/edit', [
                'title'       => '銀行口座編集',
                'bank'        => $bank,
                'adapters'    => self::ADAPTERS,
                'accountTypes' => self::ACCOUNT_TYPES,
                'old'         => $data,
                'errors'      => $errors,
                'currentUser' => $this->auth->user(),
            ]);
            return;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('bank_accounts', $data, ['id' => $id]);

        View::setFlash('success', '銀行口座を更新しました。');
        header('Location: /banks');
        exit;
    }

    public function delete(int $id): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        // Soft delete: just deactivate
        $this->db->update(
            'bank_accounts',
            ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );

        View::setFlash('success', '銀行口座を無効化しました。');
        header('Location: /banks');
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFields(): array
    {
        return [
            'bank_name'               => trim($_POST['bank_name'] ?? ''),
            'bank_code'               => trim($_POST['bank_code'] ?? ''),
            'branch_name'             => trim($_POST['branch_name'] ?? ''),
            'branch_code'             => trim($_POST['branch_code'] ?? ''),
            'account_type'            => trim($_POST['account_type'] ?? '普通'),
            'account_number'          => trim($_POST['account_number'] ?? ''),
            'account_name'            => trim($_POST['account_name'] ?? ''),
            'is_active'               => isset($_POST['is_active']) ? 1 : 0,
            'scrape_adapter_key'      => trim($_POST['scrape_adapter_key'] ?? '') ?: null,
            'scrape_login_url'        => trim($_POST['scrape_login_url'] ?? '') ?: null,
            'scrape_credentials_json' => trim($_POST['scrape_credentials_json'] ?? '') ?: null,
            'scrape_interval_minutes' => max(5, (int) ($_POST['scrape_interval_minutes'] ?? 15)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validate(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        if ($data['bank_name'] === '') {
            $errors['bank_name'] = '銀行名は必須です。';
        }
        if ($data['account_number'] === '') {
            $errors['account_number'] = '口座番号は必須です。';
        }
        if ($data['account_name'] === '') {
            $errors['account_name'] = '口座名義は必須です。';
        }

        // Check unique bank_code + account_number
        if ($data['account_number'] !== '' && $data['bank_code'] !== '') {
            $params = [$data['bank_code'], $data['account_number']];
            $sql    = "SELECT id FROM bank_accounts WHERE bank_code = ? AND account_number = ?";
            if ($excludeId !== null) {
                $sql    .= " AND id != ?";
                $params[] = $excludeId;
            }
            $existing = $this->db->fetchOne($sql, $params);
            if ($existing !== null) {
                $errors['account_number'] = 'この銀行コードと口座番号の組み合わせはすでに存在します。';
            }
        }

        return $errors;
    }
}
