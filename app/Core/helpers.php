<?php
use App\Core\Database;
use App\Core\Env;

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function app_url_base(): string {
    $configured = trim((string) Env::get('APP_URL', ''));
    if ($configured !== '') return rtrim($configured, '/');
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https') ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $basePath = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $basePath = preg_replace('#/public$#', '', rtrim($basePath, '/')) ?: '';
    return $scheme . '://' . $host . $basePath;
}
function url(string $path = ''): string {
    return app_url_base() . '/' . ltrim($path, '/');
}
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    $sessionToken = (string) ($_SESSION['csrf'] ?? '');
    $submittedToken = (string) ($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($sessionToken !== '' && $submittedToken !== '' && hash_equals($sessionToken, $submittedToken)) return;

    unset($_SESSION['csrf']);
    $message = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman lalu coba kembali.';
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) json_response(['success' => false, 'message' => $message], 419);

    flash('warning', $message);
    $fallback = user() ? url('dashboard') : url('login');
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '' && str_starts_with($referer, app_url_base())) $fallback = $referer;
    header('Location: ' . $fallback, true, 303);
    exit;
}
function user(): ?array { return $_SESSION['user'] ?? null; }
function has_role(array|string $roles): bool { return user() && in_array(user()['role'], (array)$roles, true); }
function require_auth(array $roles = []): void {
    if (!user()) redirect('login');
    if ($roles && !has_role($roles)) { http_response_code(403); view('errors/403', ['title' => 'Akses Ditolak']); exit; }
}
function flash(string $type, string $message): void { $_SESSION['flash'] = compact('type', 'message'); }
function pull_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function view(string $name, array $data = [], ?string $layout = 'layouts/admin'): void {
    extract($data, EXTR_SKIP);
    $viewFile = \App\Core\App::ROOT . '/app/Views/' . $name . '.php';
    if (!is_file($viewFile)) throw new RuntimeException("View tidak ditemukan: {$name}");
    ob_start(); require $viewFile; $content = ob_get_clean();
    if ($layout === null) { echo $content; return; }
    require \App\Core\App::ROOT . '/app/Views/' . $layout . '.php';
}
function json_response(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
function activity(string $action, string $module, ?int $referenceId = null, mixed $before = null, mixed $after = null): void {
    try {
        Database::query('INSERT INTO activity_logs(user_id,action,module,reference_id,data_before,data_after,ip_address,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())', [
            user()['id'] ?? null, $action, $module, $referenceId,
            $before ? json_encode($before) : null, $after ? json_encode($after) : null,
            $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
    } catch (Throwable) {}
}
