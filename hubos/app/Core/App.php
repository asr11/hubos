<?php

declare(strict_types=1);

namespace HubOS\Core;

use Throwable;

/** HTTP kernel: headers -> route -> session -> CSRF -> auth -> permission -> handler. */
final class App
{
    /** @param array<string, mixed> $config */
    public static function run(array $config): void
    {
        $https = self::isHttps();
        self::securityHeaders($https);
        $view = new View(HUBOS_ROOT . '/views');

        try {
            $response = self::handle($config, $https, $view);
        } catch (HttpException $e) {
            $response = $e->status === 401
                ? Response::redirect('/login')
                : self::errorPage($view, $config, $e->status, $e->getMessage());
        } catch (Throwable $e) {
            self::log($config, $e);
            $response = self::errorPage($view, $config, 500, 'אירעה שגיאה פנימית');
        }
        $response->send();
    }

    /** @param array<string, mixed> $config */
    private static function handle(array $config, bool $https, View $view): Response
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $router = new Router();
        (require HUBOS_ROOT . '/app/routes.php')($router);
        $match = $router->match($method, $path);

        if ($match['status'] === 404) {
            throw new HttpException(404, 'הדף לא נמצא');
        }
        if ($match['status'] === 405) {
            $resp = self::errorPage($view, $config, 405, 'שיטת בקשה לא נתמכת');
            $resp->headers['Allow'] = implode(', ', $match['allow'] ?? []);
            return $resp;
        }

        Auth::startSession($config, $https);

        // CSRF on every state-changing request, before touching the database.
        if ($method !== 'GET' && $method !== 'HEAD' && !Csrf::verify(Csrf::fromRequest())) {
            throw new HttpException(403, 'הבקשה נדחתה: אסימון אבטחה חסר או שגוי (CSRF)');
        }

        $pdo = DB::connect((string) $config['db_path']);
        $ctx = new Context($config, $pdo, new Auth($pdo, $config), $view);
        $opts = $match['opts'] ?? [];

        if (!empty($opts['auth']) && $ctx->user() === null) {
            return Response::redirect('/login');
        }
        if (isset($opts['perm'])) {
            $ctx->permissions()->authorize($ctx->requireUser(), (string) $opts['perm']);
        }

        $handler = $match['handler'];
        if (is_array($handler) && is_string($handler[0] ?? null)) {
            $handler = [new $handler[0](), $handler[1]];
        }
        $response = $handler($ctx, $match['params'] ?? []);
        if (!$response instanceof Response) {
            throw new \LogicException('Handler must return a Response');
        }
        return $response;
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    private static function securityHeaders(bool $https): void
    {
        header_remove('X-Powered-By');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; "
            . "frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cache-Control: no-store, max-age=0');
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** @param array<string, mixed> $config */
    private static function errorPage(View $view, array $config, int $status, string $message): Response
    {
        try {
            $html = $view->render('errors/error', [
                'appName' => (string) $config['app_name'],
                'status'  => $status,
                'message' => $message,
                'title'   => 'שגיאה ' . $status,
            ], 'layouts/auth');
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>' . $status . '</title><p>' . View::e($message) . '</p>';
        }
        return Response::html($html, $status);
    }

    /** @param array<string, mixed> $config */
    private static function log(array $config, Throwable $e): void
    {
        $dir = (string) $config['log_path'];
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            error_log('[hubos] ' . $e->getMessage());
            return;
        }
        $line = sprintf("[%s] %s: %s @ %s:%d\n", gmdate('c'), $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
