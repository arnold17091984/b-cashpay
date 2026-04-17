<?php

declare(strict_types=1);

namespace BCashPay\Admin\Controllers;

use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
use BCashPay\Database;

class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Database $db,
    ) {
    }

    public function showLogin(): void
    {
        if ($this->auth->check()) {
            header('Location: /');
            exit;
        }
        View::render('auth/login', [], false);
    }

    public function login(): void
    {
        if ($this->auth->check()) {
            header('Location: /');
            exit;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            View::render('auth/login', ['error' => 'ユーザー名とパスワードを入力してください。'], false);
            return;
        }

        if ($this->auth->login($username, $password)) {
            header('Location: /');
            exit;
        }

        View::render('auth/login', ['error' => 'ユーザー名またはパスワードが間違っています。'], false);
    }

    public function logout(): void
    {
        $this->auth->logout();
        header('Location: /login');
        exit;
    }
}
