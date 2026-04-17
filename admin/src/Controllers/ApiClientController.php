<?php

declare(strict_types=1);

namespace BCashPay\Admin\Controllers;

use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
use BCashPay\Database;

class ApiClientController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Database $db,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireAuth();

        $clients = $this->db->fetchAll(
            "SELECT * FROM api_clients ORDER BY id ASC"
        );

        View::render('clients/index', [
            'title'      => 'APIクライアント',
            'clients'    => $clients,
            'currentUser' => $this->auth->user(),
        ]);
    }

    public function create(): void
    {
        $this->auth->requireAuth();

        View::render('clients/create', [
            'title'      => 'APIクライアント追加',
            'old'        => [],
            'errors'     => [],
            'currentUser' => $this->auth->user(),
        ]);
    }

    public function store(): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $name        = trim($_POST['name'] ?? '');
        $callbackUrl = trim($_POST['callback_url'] ?? '');

        $errors = [];
        if ($name === '') {
            $errors['name'] = '名前は必須です。';
        }

        if (!empty($errors)) {
            View::render('clients/create', [
                'title'      => 'APIクライアント追加',
                'old'        => ['name' => $name, 'callback_url' => $callbackUrl],
                'errors'     => $errors,
                'currentUser' => $this->auth->user(),
            ]);
            return;
        }

        $apiKey        = bin2hex(random_bytes(32));
        $webhookSecret = bin2hex(random_bytes(64));
        $now           = date('Y-m-d H:i:s');

        $this->db->insert('api_clients', [
            'name'           => $name,
            'api_key'        => $apiKey,
            'webhook_secret' => $webhookSecret,
            'callback_url'   => $callbackUrl ?: null,
            'is_active'      => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        View::setFlash('success', "APIクライアントを作成しました。APIキー: {$apiKey}");
        header('Location: /clients');
        exit;
    }

    public function rotateKey(int $id): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $client = $this->db->fetchOne("SELECT id FROM api_clients WHERE id = ? LIMIT 1", [$id]);
        if ($client === null) {
            http_response_code(404);
            exit;
        }

        $newKey = bin2hex(random_bytes(32));
        $now    = date('Y-m-d H:i:s');

        $this->db->update(
            'api_clients',
            ['api_key' => $newKey, 'updated_at' => $now],
            ['id' => $id]
        );

        View::setFlash('success', "APIキーをローテーションしました。新しいキー: {$newKey}");
        header('Location: /clients');
        exit;
    }

    public function delete(int $id): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        // Soft delete
        $this->db->update(
            'api_clients',
            ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );

        View::setFlash('success', 'APIクライアントを無効化しました。');
        header('Location: /clients');
        exit;
    }
}
