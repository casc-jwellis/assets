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

The Import page also has a **Delete tables and Config** button for starting over while testing: it drops every table in
the database, removes `config/config.php` and the files in `storage/attachments/`, and signs you out, and you land on
the setup wizard to build a fresh database. It asks for a tick and the words `DELETE ALL`, and it cannot be undone.

### Entering several identical assets

On the **New asset** page, tick **Keep data for batch input** before creating an asset. Instead of opening the new asset, the page
returns with the form filled in from it (every field, including the asset and serial numbers), so twenty laptops only need
those two changed each time. The checkbox is at the top of the form. Untick it on the last one. Nothing is stored for this: the form is filled from the asset just created, and only if you can add to its department.

### Attachments

Each asset page has an **Attachments** card for photos and documents: JPG, PNG, WebP, PDF, TXT and CSV (what a file
is comes from its content, not its name). Anyone with write access to the asset's department can add and delete
files (retired assets included); anyone who can see the asset can open them. The size limit is whatever PHP and nginx
allow (`upload_max_filesize`, `post_max_size`, `max_file_uploads`, and nginx's `client_max_body_size`); the upload form
shows the PHP values. Migration `014` creates the table.

The files are stored in `storage/attachments/` (outside the web root, never linked directly; the app serves them after
checking access). **A database dump does not contain them: back up `storage/attachments/` together with the database.**
Thumbnails are made when PHP's GD extension is installed (`php-gd`); without it the full image is shown instead.
To remove the feature, delete `modules/Attachments`.

### Theme

**Theme** in the sidebar (administrators only) changes the site name and the colours for everyone, including the
sign-in page. Pick one of the ten ready-made palettes (each has a light and a dark version) or adjust the five colours
yourself (accent, page background, cards, sidebar and text, per mode); a live preview shows both modes before you
save. Everything else (hover, borders, muted text, tints) is derived from those five, and a combination whose text or
accent colour would be hard to read (contrast below 4.5:1) is refused. The settings live in the `settings` table
(migration `015`); the colours reach the pages as a small generated stylesheet at `/theme/css`. The name set here
replaces the one entered during setup. The browser tab icon is the same box as the sidebar logo in the theme's accent colour (the light or dark accent, following the light/dark toggle), generated at `/theme/favicon.svg`; browsers cache tab icons hard, so an old icon can linger until the tab is reopened. To remove the feature, delete `modules/Theme` (the plain `static/favicon.svg` is used again).

### Reference data (departments, buildings, asset types)

**Reference data** in the sidebar (administrators only) adds, renames and deletes the lists assets are sorted into:
departments, buildings and asset types. Each list has its own tab (`/lists?tab=buildings` and so on), with the number of
entries on the tab, and shows how many assets (and, for departments, users) use every entry. Saving, cancelling or deleting
returns you to the same tab.

- **Renaming a department's abbreviation** also updates the transfer history, because the history records departments by
  abbreviation text; it all happens in one transaction, so a failure changes nothing.
- **Renaming a building's abbreviation** does not touch the history: it records locations as text such as `MAIN101`
  (abbreviation plus room), which cannot be rewritten safely, so past entries keep the old abbreviation as a record of
  where the asset was at the time.
- **Merging two departments** (the **Merge into another department** card on a department's page) moves everything to the
  department you keep, then deletes the other: its assets, the assets it bought, users with it as their home department, and
  department access (a user with access to both keeps the higher level). The transfer history is pointed at the surviving
  department. A review page shows exactly what moves first; the merge is one transaction.
- **`RETIRE` is a system department**: retiring and restoring assets is recorded against it, so it cannot be edited or
  deleted, and no other department may use that abbreviation.
- **Deleting** is only possible when nothing depends on it: no assets in or bought by a department, no users with it as their
  home department, no assets in a building, and no assets of a type. The delete page says what is in the way. Deleting a
  department that only has access entries and history removes the access entries; the history keeps its text.
- Abbreviations are letters, digits, hyphens or underscores (up to 6 for a department, 4 for a building) and are saved in
  capitals; names must be unique within their list.

### Email notifications

**Email** in the sidebar (administrators only) sends an email to a list of addresses when an asset is added, when
assets are retired and when assets are transferred (an edit that changes department, building or room counts; a bulk
retire or transfer sends one email listing everything that changed). Each event can be switched off separately.
Settings: the mail server (host, port, **STARTTLS on 587** or **SSL/TLS on 465**), username and password, the "send from"
address, who receives the emails (one address per line), and the public address of the site for the links. There is a
**Send test email** button and a short log of what was sent and why something failed.

- **Gmail:** use `smtp.gmail.com` with STARTTLS (587) or SSL/TLS (465), the full address as the username, and an
  **app password** (turn on 2-Step Verification for the account, then Google Account → Security → App passwords). A normal
  account password is refused. The "send from" address must be that account or one of its aliases.
- **The password** is stored encrypted in the database. The key is `storage/secret.key`, created on first use: it is outside
  the web root, never committed and not part of a database dump, so a dump alone does not reveal the password. **Back up
  `storage/` together with the database and treat that backup as sensitive.** If the key is lost, enter the password again.
- **Sending never blocks the work.** If the mail server is down or refuses, the asset change is still saved; the person who
  made it sees a warning and the details are in the log on the Email page. Connections time out quickly (10 to 30 seconds).
- **Needs** migrations `015` (settings) and `016` (email log), and the PHP `openssl` extension for encrypted connections.
  Mail is sent by the bundled library in `lib/tehsmtp/` (a pure-PHP SMTP client; nothing to install). To remove the feature,
  delete `modules/Email` (the library can stay or go).

### Roles: User, Asset Manager, Administrator

An administrator sets a person's **Role** on their user page (migration `018` adds the Asset Manager role).

| | User | Asset Manager | Administrator |
|---|---|---|---|
| Assets | only departments in their access list (Read: see and mark verified; Read & write: also add, edit, transfer, retire) | **every department**, Read & write, including retiring and attachments | everything |
| Restore a retired asset, change an asset number | no | no | yes |
| User accounts | no | create, edit, reset passwords, disable and re-enable **ordinary accounts only** | all accounts, and merging |
| Department access they can give | n/a | **Read only** (None or Read) | None, Read, Read & write |
| Role of an account they create | n/a | always an ordinary user | any |
| Theme, Email, Reference data, Migrations, Import | no | no | yes |

An Asset Manager sees no administrators or other Asset Managers in the user list and gets "Access denied" if they open
one by address. Where an administrator gave a user Read & write on a department, an Asset Manager sees it as locked text:
they cannot remove it or add another. They cannot change roles, make anyone an administrator or manager, or merge
accounts, whatever they send. An account marked as both is an administrator. Until migration `018` is applied there is no
Asset Manager role to give.
### New users and temporary passwords

The **New user** page has no password box. Creating a user generates a random temporary password (14 characters, no
look-alike letters or digits) and emails it, with the username and sign-in link, to the address you enter (the email is
required while mail is set up). The mail only needs the server and from address on the Email page: it does not depend on
the notifications switch. The first time the new user signs in, every page except **Change password** (and sign out)
sends them to choosing their own; the temporary password cannot be reused. If the email cannot be sent (mail not set up,
or the server refuses), the user is still created and the temporary password is shown to you **once**, on that page, so you
can pass it on. It is never stored in readable form, never put in the email log, and not shown again. You can still set
a password by hand when editing a user; that does not force a change. The Department access table has an **All departments** row to set None, Read or Read & write for every department at once. Needs migration `017` (until it is applied the
page keeps its old password boxes). Passwords are 10 to 64 characters wherever one is set (your own account, editing a user, the setup wizard); the limit is counted in bytes, so letters outside A to Z count as more than one. Signing in is not limited, so an older, longer password still works.
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
   (Before step 3, a user still on a temporary password is redirected to the change-password page; routes flagged
   `['pending_ok' => true]` are the exception.)
5. Handler runs and returns the HTML

## Messages (toasts)

A message for the person who just did something ($this->app->session->flash('success', 'Saved.'); types are `success`, `info`, `warning` and `danger`) is shown on the next page as a toast at the top of the screen that fades by itself: success after 5 seconds, info 6, warning and error 8. Hovering over one pauses it, there is a close button, and at most five show at once. The text travels as inert JSON in the page and is added with `textContent`, so it can never act as HTML; with scripting off the same messages show as plain boxes. Messages that belong to a form (a validation error above the fields you are fixing) are ordinary boxes in the page and are not toasts. From scripts, `window.toast(type, message)` shows one.

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
- **Department access**: Read lets a user see a department's assets and mark them verified ("Mark verified" records who and when, and changes nothing else); Read & write also allows adding, editing, transferring and retiring. Retired assets cannot be verified.
