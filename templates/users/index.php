<?php
/** @var array $users @var string $csrf @var int $me */
use App\View\Present;
$roles = ['admin', 'editor', 'readonly'];
?>
<div class="page-head">
  <div>
    <h1>Users</h1>
    <p>Map district SSO accounts to a role. First-login users start <strong>read-only</strong> until granted access.</p>
  </div>
</div>

<div class="card card--pad" style="margin-bottom:18px;">
  <div class="form-section" style="margin-top:0;">Add user (pre-provision SSO access)</div>
  <form method="post" action="<?= e(url('/users/add')) ?>" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div style="flex:1; min-width:240px;">
      <label class="field-label">Email (district SSO)</label>
      <input class="field" type="email" name="email" required placeholder="name@tuscaloosacityschools.com">
    </div>
    <div style="flex:1; min-width:180px;">
      <label class="field-label">Display name</label>
      <input class="field" type="text" name="display_name" placeholder="A. Reyes">
    </div>
    <div>
      <label class="field-label">Role</label>
      <select class="field" name="role" style="min-width:130px;">
        <option value="readonly">readonly</option>
        <option value="editor">editor</option>
        <option value="admin">admin</option>
      </select>
    </div>
    <button class="btn btn--primary" type="submit" style="height:38px;">Add user</button>
  </form>
  <p class="muted" style="font-size:11.5px; margin:10px 0 0;">The account is matched by email on their first SSO login. Roles can be changed below at any time.</p>
</div>

<div class="card table-wrap">
  <table class="table">
    <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Active</th><th>Last login</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td class="cell-name"><?= e($u['email']) ?></td>
        <td><?= e($u['display_name'] ?? '—') ?></td>
        <td>
          <form method="post" action="<?= e(url('/users/role')) ?>" style="display:flex; gap:8px; align-items:center; margin:0;">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="user_id" value="<?= e($u['user_id']) ?>">
            <select class="select" name="role"<?= (int) $u['user_id'] === $me ? ' disabled' : '' ?>>
              <?php foreach ($roles as $r): ?>
                <option value="<?= e($r) ?>"<?= $u['role'] === $r ? ' selected' : '' ?>><?= e($r) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ((int) $u['user_id'] !== $me): ?>
              <button class="btn btn--ghost" type="submit" style="height:36px; padding:0 12px;">Save</button>
            <?php else: ?>
              <span class="muted" style="font-size:11.5px;">(you)</span>
            <?php endif; ?>
          </form>
        </td>
        <td><?= ((int) $u['is_active'] === 1) ? '<span class="badge badge--active"><span class="dot"></span>Active</span>' : '<span class="badge badge--disabled"><span class="dot"></span>Inactive</span>' ?></td>
        <td class="mono" style="font-size:12px;"><?= e($u['last_login_at'] ?? 'never') ?></td>
        <td></td>
      </tr>
      <?php endforeach; ?>
      <?php if ($users === []): ?><tr><td colspan="6" class="empty">No users yet. They appear on first SSO login.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
