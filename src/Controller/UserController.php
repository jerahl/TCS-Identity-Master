<?php

declare(strict_types=1);

namespace App\Controller;

use App\Db;
use App\Service\AuthService;
use App\Support\Csrf;

/**
 * Admin Users screen: list app_users and change their role. Admin-only (also
 * enforced by the route capability map). Role changes are audited.
 */
final class UserController extends Controller
{
    public function index(): string
    {
        $rows = Db::connect(Db::ROLE_APP)->query(
            'SELECT user_id, email, display_name, role, is_active, last_login_at, created_at
             FROM app_user ORDER BY role, email'
        )->fetchAll();

        return $this->render('users/index', [
            'users' => $rows,
            'csrf'  => Csrf::token(),
            'me'    => $this->auth()->user()['user_id'] ?? 0,
        ], 'users', 'Administration  /  Users', 'Users — TCS Identity Master');
    }

    public function updateRole(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/users'));
        }
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? '');
        $me = (int) ($this->auth()->user()['user_id'] ?? 0);

        if (!AuthService::isValidRole($role)) {
            $this->flash('Invalid role.');
            return $this->redirect(url('/users'));
        }
        if ($userId === $me) {
            $this->flash('You cannot change your own role.');
            return $this->redirect(url('/users'));
        }

        try {
            $db = Db::connect(Db::ROLE_APP);
            $cur = $db->prepare('SELECT email, role FROM app_user WHERE user_id = :id');
            $cur->execute([':id' => $userId]);
            $before = $cur->fetch();
            if ($before === false) {
                $this->flash('User not found.');
                return $this->redirect(url('/users'));
            }
            $db->prepare('UPDATE app_user SET role = :r WHERE user_id = :id')
                ->execute([':r' => $role, ':id' => $userId]);

            (new \App\Service\AuditService($db))->log('user', $userId, 'update',
                ['role' => $before['role']], ['role' => $role], $this->auth()->user()['email'] ?? 'admin');

            $this->flash("Role for {$before['email']} set to {$role}.");
        } catch (\Throwable $e) {
            error_log('[idm] updateRole: ' . $e->getMessage());
            $this->flash('Could not update the role.');
        }
        return $this->redirect(url('/users'));
    }
}
