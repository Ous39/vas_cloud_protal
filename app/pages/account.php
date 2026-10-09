<?php
declare(strict_types=1);
// Page: ?page=account — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    $me = user();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $st = portal_pdo()->prepare('SELECT password_hash FROM portal_users WHERE id=?'); $st->execute([(int)$me['id']]); $row = $st->fetch();
        $new = (string)($_POST['new_password'] ?? '');
        if (!$row || !password_verify((string)($_POST['current_password'] ?? ''), $row['password_hash'])) flash('danger', 'Current password is incorrect.');
        elseif (strlen($new) < 10) flash('danger', 'New password must be at least 10 characters.');
        elseif ($new !== (string)($_POST['confirm_password'] ?? '')) flash('danger', 'New password and confirmation do not match.');
        else {
            portal_pdo()->prepare('UPDATE portal_users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$me['id']]);
            audit('password_change', 'vas_portal', 'portal_users', (string)$me['id'], 'User changed their own password');
            session_regenerate_id(true); unset($_SESSION['must_change_pw']); flash('success', 'Password changed.');
        }
        redirect('?page=account');
    }
    layout_start('My Account');
    ?><div class="row"><div class="col-lg-5"><div class="cardx"><h3>Change my password</h3>
    <p class="text-muted"><?=e($me['full_name'])?> · <?=e($me['username'])?> · <?=e($me['role'])?></p>
    <form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <label class="form-label">Current password</label><input type="password" class="form-control mb-2" name="current_password" required autocomplete="current-password">
        <label class="form-label">New password <small class="text-muted">(at least 10 characters)</small></label><input type="password" class="form-control mb-2" name="new_password" required minlength="10" autocomplete="new-password">
        <label class="form-label">Confirm new password</label><input type="password" class="form-control mb-3" name="confirm_password" required minlength="10" autocomplete="new-password">
        <button class="btn btn-primary">Change password</button></form></div></div></div>
    <?php layout_end(); exit;
