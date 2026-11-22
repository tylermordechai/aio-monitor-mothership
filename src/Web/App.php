<?php

declare(strict_types=1);

namespace Aio\Web;

use Aio\Config;
use Aio\Dashboard\FleetQuery;
use Aio\Dashboard\MapQuery;
use Aio\Db;
use PDO;

/**
 * The dashboard on 443 (milestone 5, M-054): sign-in, the server map and the
 * fleet table. One front controller (public/dashboard/index.php); Apache
 * sends every path that isn't a file here (FallbackResource).
 *
 * Every page needs a signed-in user, read again from `users` on each request.
 * Every POST needs a valid CSRF token (MS-SEC-05). The database user is
 * aio_dashboard, which reads everything except command lines and writes only
 * users and audit_log (M-053).
 */
final class App
{
    private ?PDO $pdo = null;

    /** @param ?\Closure(): PDO $connect for tests; default: dashboard.cnf */
    public function __construct(
        private readonly Config $config,
        private readonly View $view,
        private readonly Session $session,
        private readonly ?\Closure $connect = null,
    ) {
    }

    public function handle(WebRequest $r): WebResponse
    {
        $t = hrtime(true);
        try {
            $resp = $this->route($r);
        } catch (\Throwable $e) {
            // Class and place only: a message could hold data (MS-SEC-10).
            error_log(sprintf('aio dashboard: %s at %s:%d', $e::class, basename($e->getFile()), $e->getLine()));
            $resp = str_starts_with($r->path, '/api/')
                ? WebResponse::json(['error' => 'server_error'], 500)
                : WebResponse::html($this->view->render('error', ['title' => 'Something went wrong', 'message' => 'The page could not be built. The error is in the dashboard log.', 'user' => null, 'csrf' => '']), 500);
        }
        $ms = (hrtime(true) - $t) / 1e6;
        $slow = $this->config->int('dashboard', 'slow_log_ms');
        if ($slow > 0 && $ms >= $slow) {
            error_log(sprintf('aio dashboard slow: %s %.0f ms', $r->path, $ms));
        }
        return $resp->with('X-Render-Ms', sprintf('%.1f', $ms));
    }

    private function route(WebRequest $r): WebResponse
    {
        if ($r->path === '/login') {
            return $r->method === 'POST' ? $this->signIn($r) : $this->signInPage($r);
        }
        if ($r->path === '/logout') {
            return $r->method === 'POST' ? $this->signOut($r) : $this->notAllowed($r);
        }
        $user = $this->currentUser();
        if ($user === null) {
            return str_starts_with($r->path, '/api/')
                ? WebResponse::json(['error' => 'signed_out'], 401)
                : WebResponse::redirect('/login');
        }
        if ($r->method !== 'GET') {
            return $this->notAllowed($r);
        }
        return match (true) {
            $r->path === '/' => $this->home($r, $user),
            $r->path === '/api/map' => $this->mapData($r),
            (bool) preg_match('#^/hosts/(\d{1,10})$#', $r->path, $m) => $this->hostPage((int) $m[1], $user),
            default => $this->page('error', $user, ['title' => 'Not found', 'message' => 'There is no page at this address.'], 404),
        };
    }

    // --- Sign-in (MS-SEC-06) -------------------------------------------------

    private function signInPage(WebRequest $r): WebResponse
    {
        if ($this->currentUser() !== null) {
            return WebResponse::redirect('/');
        }
        return WebResponse::html($this->view->render('login', [
            'title' => 'Sign in',
            'csrf' => $this->session->csrfToken(),
            'message' => $this->session->flash(),
            'user' => null,
        ]));
    }

    private function signIn(WebRequest $r): WebResponse
    {
        if (!Csrf::valid($r, $this->session->csrfToken())) {
            $this->session->flash('The sign-in form had expired. Please try again.');
            return WebResponse::redirect('/login');
        }
        $auth = new Auth($this->db(), $this->lockout());
        $res = $auth->attempt($r->p('username'), $r->p('password'), $r->ip);
        if (!$res['ok'] || $res['user'] === null) {
            $this->session->flash(sprintf(
                'Sign-in failed. Check the user name and password. After %d failed tries an account is locked for %d minutes.',
                $this->config->int('dashboard', 'lockout_failures'),
                $this->config->int('dashboard', 'lockout_minutes'),
            ));
            return WebResponse::redirect('/login');
        }
        $this->session->signIn((int) $res['user']['id'], time());
        return WebResponse::redirect('/');
    }

    private function signOut(WebRequest $r): WebResponse
    {
        $user = $this->currentUser();
        if (!Csrf::valid($r, $this->session->csrfToken())) {
            return $this->page('error', $user, ['title' => 'Not signed out', 'message' => 'The form had expired. Reload the page and try again.'], 400);
        }
        if ($user !== null) {
            Audit::write($this->db(), Audit::SIGN_OUT, $user['id'], $user['username'], $r->ip);
        }
        $this->session->end();
        return WebResponse::redirect('/login');
    }

    /** @return ?array{id: int, username: string, display_name: ?string, role: string} */
    private function currentUser(): ?array
    {
        $uid = $this->session->userId(time());
        if ($uid === null) {
            return null;
        }
        $u = (new Auth($this->db(), $this->lockout()))->load($uid);
        if ($u === null) {
            $this->session->end();
        }
        return $u;
    }

    // --- Pages ---------------------------------------------------------------

    /** @param array{id: int, username: string, display_name: ?string, role: string} $user */
    private function home(WebRequest $r, array $user): WebResponse
    {
        $range = MapQuery::range($r->q('range', '24h'));
        $fleet = (new FleetQuery($this->db(), $this->config))->hosts();
        return $this->page('home', $user, [
            'title' => 'Server map',
            'range' => $range,
            'ranges' => MapQuery::RANGES,
            'fleet' => $fleet,
            'staleAfter' => $this->config->int('dashboard', 'stale_after_s'),
        ]);
    }

    private function mapData(WebRequest $r): WebResponse
    {
        $q = new MapQuery($this->db(), $this->config);
        return WebResponse::json($q->build(MapQuery::range($r->q('range', '24h')), time()));
    }

    /** @param array{id: int, username: string, display_name: ?string, role: string} $user */
    private function hostPage(int $hostPk, array $user): WebResponse
    {
        $s = $this->db()->prepare('SELECT id, hostname FROM hosts WHERE id = ?');
        $s->execute([$hostPk]);
        $h = $s->fetch();
        if (!is_array($h)) {
            return $this->page('error', $user, ['title' => 'Not found', 'message' => 'There is no host with this number.'], 404);
        }
        return $this->page('error', $user, ['title' => (string) $h['hostname'], 'message' => 'The host page is the next step of milestone 5.'], 200);
    }

    /**
     * @param ?array{id: int, username: string, display_name: ?string, role: string} $user
     * @param array<string, mixed> $v
     */
    private function page(string $template, ?array $user, array $v, int $status = 200): WebResponse
    {
        return WebResponse::html($this->view->render($template, $v + ['user' => $user, 'csrf' => $this->session->csrfToken()]), $status);
    }

    private function notAllowed(WebRequest $r): WebResponse
    {
        return str_starts_with($r->path, '/api/')
            ? WebResponse::json(['error' => 'method_not_allowed'], 405)
            : WebResponse::html($this->view->render('error', ['title' => 'Not allowed', 'message' => 'This address does not take that kind of request.', 'user' => null, 'csrf' => '']), 405)->with('Allow', 'GET');
    }

    private function lockout(): Lockout
    {
        return new Lockout($this->config->int('dashboard', 'lockout_failures'), $this->config->int('dashboard', 'lockout_minutes'));
    }

    private function db(): PDO
    {
        return $this->pdo ??= $this->connect !== null
            ? ($this->connect)()
            : Db::connect($this->config->string('db', 'dashboard_cnf'), $this->config->string('db', 'name'));
    }
}
