<?php
use BCashPay\Admin\View;

$success = View::flash('success');
$error   = View::flash('error');
$info    = View::flash('info');
?>
<?php if ($success !== null): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
    <i class="bi bi-check-circle-fill"></i>
    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($info !== null): ?>
<div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
    <i class="bi bi-info-circle-fill"></i>
    <?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
