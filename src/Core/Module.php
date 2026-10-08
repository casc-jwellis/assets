<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base class for feature modules ("tabs").
 *
 * To add a module, create modules/<Name>/<Name>Module.php that extends this
 * class in namespace App\Modules\<Name>. It is discovered automatically; no
 * other file needs editing. Override:
 *
 *   routes(Router $router)  register URLs
 *   nav(): array            sidebar entries, e.g.
 *                           [['label' => 'Assets', 'path' => '/assets', 'icon' => 'box', 'order' => 20]]
 *                           add 'admin' => true to show it to admins only
 *
 * Templates live in modules/<Name>/views/ and are rendered with $this->render().
 */
abstract class Module
{
    public function __construct(protected App $app)
    {
    }

    public function routes(Router $router): void
    {
    }

    /** @return list<array{label:string,path:string,icon?:string,order?:int,admin?:bool}> */
    public function nav(): array
    {
        return [];
    }

    /**
     * "?q=foo&page=2" (or ""): the list view the user came from. Appended to links from list
     * to detail/edit pages (and back) so "back" returns to the same search and page.
     */
    protected string $listQs = '';

    /** Render modules/<Name>/views/<view>.php inside the given layout ('app', 'auth' or null). */
    protected function render(string $view, array $data = [], ?string $layout = 'app'): string
    {
        $dir = dirname((new \ReflectionClass($this))->getFileName());
        // Views get $listQs automatically.
        return $this->app->view->render($dir . '/views/' . $view . '.php', $data + ['listQs' => $this->listQs], $layout);
    }

    /**
     * Build the list state from validated pieces only (never echo the raw query string), so the
     * link can only ever carry a text search and a page number back to the list.
     */
    protected function listState(string $search, int $page): string
    {
        $qs = http_build_query(array_filter(['q' => $search, 'page' => $page > 1 ? $page : null]));
        return $qs === '' ? '' : '?' . $qs;
    }

    /** On detail/edit pages: pick the list state up from the request's ?q= and ?page=. */
    protected function rememberList(Request $req): void
    {
        $this->listQs = $this->listState(mb_substr(trim((string) $req->query('q')), 0, 64), max(1, (int) $req->query('page', '1')));
    }

    protected function redirect(string $path): never
    {
        Response::redirect($path);
    }
}
