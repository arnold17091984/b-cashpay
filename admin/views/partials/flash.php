<?php
use BCashPay\Admin\View;

$success = View::flash('success');
$error   = View::flash('error');
$info    = View::flash('info');
?>
<?php if ($success !== null): ?>
<div class="flash flash--success alert-auto-dismiss" role="alert">
    <i class="bi bi-check-circle-fill"></i>
    <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
    <button type="button" class="btn-close" onclick="this.parentElement.remove()" aria-label="閉じる">&times;</button>
</div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="flash flash--danger" role="alert">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
    <button type="button" class="btn-close" onclick="this.parentElement.remove()" aria-label="閉じる">&times;</button>
</div>
<?php endif; ?>
<?php if ($info !== null): ?>
<div class="flash flash--info alert-auto-dismiss" role="alert">
    <i class="bi bi-info-circle-fill"></i>
    <span><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></span>
    <button type="button" class="btn-close" onclick="this.parentElement.remove()" aria-label="閉じる">&times;</button>
</div>
<?php endif; ?>
