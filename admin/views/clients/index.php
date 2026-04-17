<?php use BCashPay\Admin\View;
use BCashPay\Admin\Auth;
$csrf = Auth::csrfToken();
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="h4 mb-0"><i class="bi bi-key me-2 text-warning"></i>APIクライアント</h2>
    <a href="/clients/new" class="btn btn-warning btn-sm">
        <i class="bi bi-plus-lg me-1"></i>追加
    </a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-dark table-striped mb-0">
            <thead class="table-secondary">
                <tr>
                    <th>ID</th>
                    <th>名前</th>
                    <th>APIキー</th>
                    <th>コールバックURL</th>
                    <th class="text-center">状態</th>
                    <th>作成日</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                <tr><td colspan="7" class="text-center text-secondary py-4">APIクライアントがありません</td></tr>
                <?php else: ?>
                <?php foreach ($clients as $c): ?>
                <tr>
                    <td><?= (int) $c['id'] ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <code class="text-warning api-key-masked" data-key="<?= htmlspecialchars($c['api_key'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= substr($c['api_key'], 0, 8) ?>••••••••••••••••
                            </code>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                    onclick="toggleApiKey(this)" title="表示/非表示">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                    onclick="copyToClipboard('<?= htmlspecialchars($c['api_key'], ENT_QUOTES, 'UTF-8') ?>', this)"
                                    title="コピー">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </td>
                    <td class="small text-break"><?= htmlspecialchars($c['callback_url'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $c['is_active'] ? '有効' : '無効' ?>
                        </span>
                    </td>
                    <td class="text-secondary"><?= View::datetime($c['created_at'], 'Y/m/d') ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <form method="POST" action="/clients/<?= (int) $c['id'] ?>/rotate"
                                  onsubmit="return confirm('APIキーをローテーションしますか？既存のキーは無効になります。')">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="キーローテーション">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </form>
                            <form method="POST" action="/clients/<?= (int) $c['id'] ?>/delete"
                                  onsubmit="return confirm('このAPIクライアントを無効化しますか？')">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="無効化">
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
</div>
