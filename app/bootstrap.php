<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }
});
$config = require ROOT . '/config/app.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    if (!is_dir($config['session_path'])) mkdir($config['session_path'], 0700, true);
    session_save_path($config['session_path']);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>str_starts_with($config['url'],'https://')]);
    session_start();
}
function e(mixed $value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrfField(): string { return '<input type="hidden" name="_csrf" value="'.e(csrf()).'">'; }
function validCsrf(): bool { return isset($_POST['_csrf'], $_SESSION['csrf']) && is_string($_POST['_csrf']) && hash_equals($_SESSION['csrf'], $_POST['_csrf']); }
function view(string $template, array $data = []): void {
    global $config;
    extract($data, EXTR_SKIP);
    $pageTitle = $pageTitle ?? 'Worship chords, thoughtfully arranged';
    $description = $description ?? 'A quieter place to prepare. Explore worship chord sheets, musical cues, and resources for your next gathering.';
    ob_start(); require ROOT . '/views/' . $template . '.php'; $content = ob_get_clean();
    require ROOT . '/views/layout.php';
}
function component(string $name, array $data = []): void { extract($data, EXTR_SKIP); require ROOT . '/views/components/' . $name . '.php'; }
function redirect(string $path): void { header('Location: '.$path, true, 303); exit; }
function icon(string $name, int $size=20): string {
    $paths = [
      'search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>',
      'heart'=>'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
      'arrow'=>'<path d="M4 12h16m-6-6 6 6-6 6"/>',
      'music'=>'<path d="M9 18V5l12-2v13M9 8l12-2"/><ellipse cx="6" cy="18" rx="3" ry="2.5"/><ellipse cx="18" cy="16" rx="3" ry="2.5"/>',
      'sun'=>'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
      'print'=>'<path d="M6 9V3h12v6M6 17H3V9h18v8h-3M6 14h12v7H6z"/>',
      'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
      'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
      'trend'=>'<path d="m3 17 6-6 4 4 8-10M15 5h6v6"/>',
      'book'=>'<path d="M12 5v16M12 5C8 2 3 3 3 3v16s5-1 9 2c4-3 9-2 9-2V3s-5-1-9 2Z"/>',
    ];
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['music']).'</svg>';
}
