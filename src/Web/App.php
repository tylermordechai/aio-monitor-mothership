<?php

declare(strict_types=1);

namespace Aio\Web;

use Aio\Config;
use Aio\Dashboard\CommandLineReveal;
use Aio\Dashboard\FleetQuery;
use Aio\Dashboard\HostQuery;
use Aio\Dashboard\Labels;
use Aio\Dashboard\MapQuery;
use Aio\Db;
use PDO;

/**
 * The dashboard on 443 (milestone 5, M-054, M-056): sign-in, the server map,
 * the fleet table and the host page. One front controller (public/dashboard/index.php); Apache
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
        if ($r->method === 'POST' && preg_match('#^/hosts/(\d{1,10})/command-line$#', $r->path, $m)) {
            return $this->commandLine($r, (int) $m[1], $user);
        }
        if ($r->method !== 'GET') {
            return $this->notAllowed($r);
        }
        return match (true) {
            $r->path === '/' => $this->home($r, $user),
            $r->path === '/api/map' => $this->mapData($r),
            (bool) preg_match('#^/hosts/(\d{1,10})$#', $r->path, $m) => $this->hostPage($r, (int) $m[1], $user),
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

    /**
     * The host page (MS-UI-02, MS-UI-07, M-056): a header, the coverage gaps
     * for the range, and one tab, each tab its own request so a page reads
     * only what it shows (MS-UI-08).
     *
     * @param array{id: int, username: string, display_name: ?string, role: string} $user
     */
    private function hostPage(WebRequest $r, int $hostPk, array $user): WebResponse
    {
        $now = time();
        $q = new HostQuery($this->db(), $this->config);
        $host = $q->host($hostPk, $this->config, $now);
        if ($host === null) {
            return $this->page('error', $user, ['title' => 'Not found', 'message' => 'There is no host with this number.'], 404);
        }
        $tab = HostQuery::tab($r->q('tab', 'connections'));
        $range = MapQuery::range($r->q('range', '24h'));
        $since = $now - MapQuery::RANGES[$range];
        $v = [
            'host' => $host,
            'tab' => $tab,
            'range' => $range,
            'ranges' => MapQuery::RANGES,
            'since' => gmdate('Y-m-d H:i:s', $since),
            'limit' => $q->limit(),
            'gaps' => $q->gaps($hostPk, $since, $now),
            'seesCommandLines' => Roles::seesCommandLines($user['role']),
            'csrf' => $this->session->csrfToken(),
        ];
        switch ($tab) {
            case 'connections':
                $v['recent'] = $q->connections($hostPk, $since, $this->config);
                $v['snapshot'] = $q->latestSnapshot($hostPk);
                $v['live'] = $v['snapshot'] === null ? [] : $q->liveConnections($hostPk, $v['snapshot']);
                break;
            case 'listening':
                $v['snapshot'] = $q->latestSnapshot($hostPk);
                $v['listening'] = $v['snapshot'] === null ? [] : $q->listening($hostPk, $v['snapshot']);
                break;
            case 'processes':
                $v['processes'] = $q->processes($hostPk, $since);
                break;
            case 'executables':
                $v['executables'] = $q->executables($hostPk);
                break;
            case 'inventory':
                $v['inventory'] = $q->latestInventory($hostPk);
                $v['items'] = [];
                if ($v['inventory'] !== null) {
                    foreach (['apps', 'services', 'tasks', 'startup', 'drivers', 'roles'] as $k) {
                        $v['items'][$k] = $q->inventoryItems($hostPk, $k, $v['inventory']);
                    }
                }
                $v['changes'] = $q->inventoryChanges($hostPk, $since);
                break;
            case 'software':
                $v['inventory'] = $q->latestInventory($hostPk);
                $v['software'] = $v['inventory'] === null ? null : $q->serverSoftware($hostPk, $v['inventory']);
                $v['snapshot'] = $q->latestSnapshot($hostPk);
                $v['owners'] = Labels::portOwners(
                    $v['snapshot'] === null ? [] : $q->listening($hostPk, $v['snapshot']),
                    $v['software']['sites'] ?? [],
                    $v['software']['sql'] ?? [],
                );
                break;
        }
        $v['tabHtml'] = $this->view->render('host-' . $tab, $v, null);
        return $this->page('host', $user, ['title' => $host['hostname']] + $v);
    }

    /**
     * POST /hosts/{id}/command-line (MS-SEC-07..09): one command line, for an
     * analyst or admin, audited. A POST, so a link, a prefetch or the browser
     * history never opens one; the CSRF token is required.
     *
     * @param array{id: int, username: string, display_name: ?string, role: string} $user
     */
    private function commandLine(WebRequest $r, int $hostPk, array $user): WebResponse
    {
        if (!Csrf::valid($r, $this->session->csrfToken())) {
            return $this->page('error', $user, ['title' => 'Not shown', 'message' => 'The form had expired. Go back, reload the page and try again.'], 400);
        }
        $reveal = new CommandLineReveal(
            $this->db(),
            fn (): PDO => Db::connect($this->config->string('db', 'analyst_cnf'), $this->config->string('db', 'name')),
        );
        $res = $reveal->reveal($user, $hostPk, (int) $r->p('agent_pk'), (int) $r->p('seq'), $r->p('ts'), $r->ip);
        return match ($res['status']) {
            'not_allowed' => $this->page('error', $user, ['title' => 'Not allowed', 'message' => 'Command lines are shown to analysts and admins only.'], 403),
            'not_found' => $this->page('error', $user, ['title' => 'Not found', 'message' => 'There is no such process on this host.'], 404),
            default => $this->page('command-line', $user, ['title' => 'Command line', 'host_pk' => $hostPk, 'res' => $res]),
        };
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
