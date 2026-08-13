<?php
namespace App\Core;

final class App
{
    public const ROOT = __DIR__ . '/../..';

    public static function boot(): void
    {
        Env::load(self::ROOT . '/.env');
        date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Asia/Makassar'));
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $sessionPath = self::ROOT . '/storage/sessions';
            if (!is_dir($sessionPath)) mkdir($sessionPath, 0775, true);
            session_save_path($sessionPath);
            session_name('monitoring_air_session');
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
            session_start();
        }
        $timeout = (int) Env::get('SESSION_TIMEOUT_MINUTES', 120) * 60;
        if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $timeout) {
            session_unset();
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = time();
    }
}
