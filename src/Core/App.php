<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application kernel: wires services together and runs the front-controller
 * pipeline (session -> auth gate -> CSRF -> authorization -> handler).
 */
final class App
{
    private static ?self $instance = null;

    public readonly Config $config;
    public readonly Request $request;
    public readonly Session $session;
    public readonly Csrf $csrf;
    public readonly Database $db;
    public readonly Auth $auth;
    public readonly View $view;
    public readonly Router $router;
    public readonly string $nonce;

    /** @var list<Module> */
    private array $modules = [];

    private function __construct(Config $config)
    {
        $this->nonce = base64_encode(random_bytes(16));
        $this->config = $config;
        $this->request = new Request();
        $this->session = new Session($this->config, $this->request);
        $this->csrf = new Csrf($this->session);
        $this->db = new Database($this->config);
        $this->auth = new Auth($this->db, $this->session, $this->config, $this->csrf);
        $this->view = new View();
        $this->router = new Router();
    }

    public static function boot(): self
    {
        if (self::$instance === null) {
            ini_set('display_errors', '0');
            error_reporting(E_ALL);
            self::$instance = new self(Config::load());
            self::$instance->discoverModules();
        }
        return self::$instance;
    }

    /**
     * Minimal app for setup.php: services and templates, but no config file,
     * no modules, no routing and no login gate.
     */
    public static function bootSetup(): self
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);
        return self::$instance = new self(Config::fromArray([]));
    }

    public static function instance(): self
    {
        return self::$instance ?? throw new \LogicException('App not booted.');
    }

    public function run(): void
    {
        try {
            $this->handle();
        } catch (\Throwable $e) {
            error_log((string) $e);
            $this->error(500, 'Something went wrong', $this->config->get('app.debug') ? (string) $e : null);
        }
    }

    private function handle(): void
    {
        $req = $this->request;
        $this->session->start();
        $this->sendSecurityHeaders();

        $match = $this->router->match($req->method, $req->path);
        $public = $match['status'] === 200 && !empty($match['opts']['public']);

        // 1. Nothing is reachable without logging in (unknown URLs included).
        if (!$public && !$this->auth->check()) {
            // Remember where they were headed, but only for a real page: browsers also request things
            // like /favicon.ico while signed out, and sending someone there after login gives a 404.
            if ($req->method === 'GET' && $match['status'] === 200 && $req->path !== '/') {
                $this->session->set('auth.next', $req->path);
            }
            Response::redirect('/login');
        }

        // 2. CSRF for every state-changing request, including the login form.
        if ($req->method !== 'GET' && !$this->csrf->verify($req)) {
            // A request bigger than post_max_size arrives with an empty $_POST, which looks like a missing token.
            $tooBig = $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
            $this->error(403, 'Security check failed', $tooBig
                ? 'The upload was larger than the server accepts (post_max_size ' . ini_get('post_max_size') . '). Raise post_max_size and upload_max_filesize in php.ini (and client_max_body_size in nginx), or use a smaller file.'
                : 'Your session or form token has expired. Go back, reload the page and try again.');
        }

        if ($match['status'] === 404) {
            $this->error(404, 'Page not found');
        }
        if ($match['status'] === 405) {
            header('Allow: GET, POST');
            $this->error(405, 'Method not allowed');
        }

        // 3. Authorization.
        if (!empty($match['opts']['admin']) && !$this->auth->isAdmin()) {
            $this->error(403, 'Access denied', 'You do not have permission to view this page.');
        }

        echo ($match['handler'])($req, $match['params']);
    }

    /** @var array<string,bool> */
    private array $migrationCache = [];

    /** Has this migration been applied? Lets a feature switch itself on only once its schema exists. */
    public function migrationApplied(string $name): bool
    {
        return $this->migrationCache[$name]
            ??= (new Migrator($this->db, APP_ROOT . '/migrations'))->isApplied($name);
    }

    /** @return list<array{label:string,path:string,icon:string,order:int,admin:bool}> nav entries visible to the current user */
    public function navItems(): array
    {
        $items = [];
        foreach ($this->modules as $module) {
            foreach ($module->nav() as $item) {
                if (!empty($item['admin']) && !$this->auth->isAdmin()) {
                    continue;
                }
                $items[] = $item + ['icon' => 'box', 'order' => 100, 'admin' => false];
            }
        }
        usort($items, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        return $items;
    }

    public function error(int $status, string $title, ?string $detail = null): never
    {
        http_response_code($status);
        $layout = $this->auth->check() ? 'app' : 'auth';
        echo $this->view->render(
            APP_ROOT . '/templates/error.php',
            ['title' => $title, 'status' => $status, 'detail' => $detail],
            $layout
        );
        exit;
    }

    private function discoverModules(): void
    {
        foreach (glob(APP_ROOT . '/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $class = "App\\Modules\\{$name}\\{$name}Module";
            if (!is_file("{$dir}/{$name}Module.php") || !is_subclass_of($class, Module::class)) {
                continue;
            }
            $module = new $class($this);
            $module->routes($this->router);
            $this->modules[] = $module;
        }
    }

    public function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        // Scripts: our own files plus the per-request nonce'd theme bootstrap. No inline styles.
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$this->nonce}'; "
            . "style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        if ($this->request->isSecure()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        // Authenticated pages must not be cached by the browser or proxies.
        header('Cache-Control: no-store');
    }
}
