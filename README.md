# Asset Manager

PHP 8.1+ / PDO (MySQL or MariaDB) web interface for the `assets` database. No Composer dependencies.

## Setup

Everything is done in the browser:

1. Have a MySQL/MariaDB user ready. If the database doesn't exist, setup creates it (the user needs the
   `CREATE` privilege), and a completely empty database is filled from `assets.schema.sql`.
   An existing assets database is left as it is.
2. Point the web server's document root at `public/` (Apache: `.htaccess` is included; nginx: `try_files $uri /index.php?$query_string;`).
   The `config/` folder must be writable by the web server during setup.
3. Open the site. With no config yet you are sent to **`/setup.php`**, which asks for the database
   credentials and settings, optionally applies database migrations and creates an administrator,
   then writes `config/config.php`. Setup locks itself once that file exists
   (delete the file to re-run it). Use a DB account with only SELECT/INSERT/UPDATE/DELETE
   (plus ALTER/CREATE if you want the web migrations to run under it).
4. Sign in. Later schema changes: **`/migrate.php`** (administrators only) lists and applies pending migrations.

### Importing the legacy data (one-time)

Once setup is done and every migration is applied, an administrator can open **Import** in the sidebar
(`/import`) and upload a `.sql` data dump (phpMyAdmin / mysqldump `INSERT` statements) from the old application.
The file is parsed, never executed; you review every automatic fix and warning before anything is written, then
the data tables are replaced in one transaction (your own account is kept). The old text user IDs are replaced by new
numbers and every reference is translated. Large dumps need
`upload_max_filesize` and `post_max_size` raised in php.ini and, behind nginx, `client_max_body_size`
(nginx's default is only 1 MB). After the cutover, delete the `modules/Import` folder to remove the tool.

### Migrations

`migrations/*.sql` are applied once each, in filename order, and recorded in a `schema_migrations` table.
To change the schema, add the next numbered file (e.g. `002_add_something.sql`) and run it from the Migrations page.
MySQL cannot roll back DDL, so write migrations to be safe to re-run. `001_widen_password_column.sql` widens
`users.password` (it is `varchar(40)`, too small for `password_hash()`); until it is applied, existing
MD5/SHA-1 passwords still work but are not upgraded and the change-password form cannot save.

Migrations `009`–`013` turn `users.user_id` from text into an auto-incrementing integer and re-point
`permissions`, `assets` (added by / changed by / retired by) and `transfers` at the new numbers. The old text IDs
are not kept. Anyone referenced who no longer exists (or had a blank ID) is attached to a disabled
**Missing user** placeholder so history stays readable. **Back up the database before applying them**: MySQL cannot
roll back schema changes, so a failure part-way means restoring the backup. Sessions from before the change are
simply treated as signed out.

For local development: `php -S localhost:8000 -t public public/index.php`

## Layout

| Path | Purpose |
|------|---------|
| `public/index.php` | Front controller for the application |
| `public/setup.php` | First-run wizard (self-locking); `public/migrate.php` redirects to the admin Migrations page |
| `src/Core/` | Framework: `App` (request pipeline), `Router`, `Auth`, `Csrf`, `Session`, `Database` (PDO), `View`, `Migrator`, `Installer` |
| `templates/` | Layouts (`layout-app`, `layout-auth`) and shared partials |
| `modules/<Name>/` | Feature modules — one folder per tab |
| `public/static/` | CSS / JS (theme tokens for light + dark live at the top of `app.css`) |

## Request pipeline (`App::handle`)

1. Start session, send security headers (CSP with nonce, `X-Frame-Options`, etc.)
2. **Auth gate** — anything except the login routes redirects to `/login` when not signed in (unknown URLs included)
3. **CSRF** — every non-GET request must carry a valid token (`csrf_field()` in forms, or `X-CSRF-Token` header)
4. **Authorization** — routes may be flagged `['admin' => true]`
5. Handler runs and returns the HTML

## Adding a module (new tab)

Create `modules/Reports/ReportsModule.php`. That's it — modules are auto-discovered.

```php
<?php
declare(strict_types=1);
namespace App\Modules\Reports;

use App\Core\{Module, Request, Router};

final class ReportsModule extends Module
{
    public function routes(Router $router): void
    {
        $router->get('/reports', [$this, 'index']);
        // $router->post('/reports/run', [$this, 'run'], ['admin' => true]);
    }

    public function nav(): array
    {
        return [['label' => 'Reports', 'path' => '/reports', 'icon' => 'box', 'order' => 30]];
    }

    public function index(Request $req): string
    {
        $rows = $this->app->db->all('SELECT ... WHERE x = ?', [$value]);
        return $this->render('index', ['title' => 'Reports', 'rows' => $rows]); // modules/Reports/views/index.php
    }
}
```

In views, wrap every dynamic value in `e()`; in forms include `<?= csrf_field() ?>`.
Use `$this->app->auth->departmentScope('a.department_id')` to restrict queries to the
departments a user has access to (admins see everything). New icons go in `src/Core/Icons.php`.

## Security notes

- **SQL**: PDO with real (non-emulated) prepared statements; user input is never concatenated into SQL.
- **XSS**: all output goes through `e()` (`htmlspecialchars`, `ENT_QUOTES`, UTF-8); CSP forbids inline styles and un-nonced scripts.
- **Passwords**: `password_hash()` / `password_verify()`; unknown usernames take the same code path as bad passwords.
- **Sessions**: HttpOnly + SameSite=Lax cookie, strict mode, ID regenerated on login, idle timeout (default 30 min).
