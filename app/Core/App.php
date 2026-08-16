<?php
namespace App\Core;

final class App
{
    public const ROOT = __DIR__ . '/../..';

    public static function boot(): void
    {
        Env::load(self::ROOT . '/.env');
        $isProduction = (string) Env::get('APP_ENV', 'local') === 'production';
        ini_set('display_errors', $isProduction ? '0' : (Env::get('APP_DEBUG', false) ? '1' : '0'));
        ini_set('log_errors', '1');
        date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Asia/Makassar'));
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $sessionPath = self::ROOT . '/storage/sessions';
            if (!is_dir($sessionPath)) mkdir($sessionPath, 0775, true);
            session_save_path($sessionPath);
            session_name('monitoring_air_session');
            $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $isHttps]);
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
