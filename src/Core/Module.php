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

    /** Render modules/<Name>/views/<view>.php inside the given layout ('app', 'auth' or null). */
    protected function render(string $view, array $data = [], ?string $layout = 'app'): string
    {
        $dir = dirname((new \ReflectionClass($this))->getFileName());
        return $this->app->view->render($dir . '/views/' . $view . '.php', $data, $layout);
    }

    protected function redirect(string $path): never
    {
        Response::redirect($path);
    }
}
