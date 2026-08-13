<?php
namespace App\Controllers;

use App\Core\Database;

final class AuthController
{
    public function form(): void
    {
        if (user()) redirect('dashboard');
        view('auth/login', ['title' => 'Masuk'], 'layouts/auth');
    }

    public function login(): void
    {
        verify_csrf();
        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $recent = (int)Database::query("SELECT COUNT(*) FROM login_attempts WHERE ip_address=? AND successful=0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)", [$ip])->fetchColumn();
        if ($recent >= 5) {
            $answer = (int)($_POST['captcha'] ?? -1);
            if ($answer !== (int)($_SESSION['captcha_answer'] ?? -2)) {
                flash('danger', 'CAPTCHA salah. Silakan coba lagi.'); redirect('login');
            }
        }
        $stmt = Database::query("SELECT u.*, COALESCE(r.slug,'operator') role FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE (u.username=? OR u.email=?) AND u.deleted_at IS NULL LIMIT 1", [$identity, $identity]);
        $account = $stmt->fetch();
        $success = $account && $account['is_active'] && password_verify($password, $account['password']);
        Database::query('INSERT INTO login_attempts(identity,ip_address,user_agent,successful,attempted_at) VALUES(?,?,?,?,NOW())', [$identity,$ip,substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,500),$success ? 1 : 0]);
        if (!$success) {
            if ($recent + 1 >= 5) { $_SESSION['captcha_a'] = random_int(1, 9); $_SESSION['captcha_b'] = random_int(1, 9); $_SESSION['captcha_answer'] = $_SESSION['captcha_a'] + $_SESSION['captcha_b']; }
            flash('danger', 'Username/email atau kata sandi tidak sesuai.'); redirect('login');
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$account['id'],'name'=>$account['name'],'username'=>$account['username'],'role'=>$account['role'],'must_change_password'=>(bool)$account['must_change_password']];
        Database::query('UPDATE users SET last_login_at=NOW() WHERE id=?', [$account['id']]);
        activity('login', 'autentikasi', (int)$account['id']);
        redirect('dashboard');
    }

    public function logout(): void
    {
        if (user()) activity('logout', 'autentikasi', user()['id']);
        session_unset(); session_regenerate_id(true); redirect('login');
    }
}

