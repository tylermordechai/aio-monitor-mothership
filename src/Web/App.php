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
use Aio\Enrol\AgentAdmin;
use Aio\Enrol\TokenStore;
use Aio\Pki\CrlTrigger;
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
 *
 * The admin pages (M-070, MS-SEC-07): the enrolment page (tokens, agents) and
 * the host page's revoke and unrevoke buttons. Admins only, a CSRF token on
 * every change, an audit row with the user's ID and address (MS-SEC-08). Their
 * writes go through aio_admin (admin.cnf), connected only after the role
 * check; aio_dashboard still can't change an agent or a token.
 */
final class App
{
    private ?PDO $pdo = null;

    private ?PDO $adminPdo = null;

    /**
     * @param ?\Closure(): PDO $connect for tests; default: dashboard.cnf
     * @param ?\Closure(): PDO $connectAdmin for tests; default: admin.cnf (M-070)
     * @param ?\Closure(): void $afterRevocation for tests; default: CrlTrigger (M-071)
     */
    public function __construct(
        private readonly Config $config,
        private readonly View $view,
        private readonly Session $session,
        private readonly ?\Closure $connect = null,
        private readonly ?\Closure $connectAdmin = null,
        private readonly ?\Closure $afterRevocation = null,
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
        if ($r->method === 'POST' && $r->path === '/admin/tokens') {
            return $this->createToken($r, $user);
        }
        if ($r->method === 'POST' && preg_match('#^/admin/tokens/(\d{1,10})/cancel$#', $r->path, $m)) {
            return $this->cancelToken($r, (int) $m[1], $user);
        }
        if ($r->method === 'POST' && preg_match('#^/hosts/(\d{1,10})/(revoke|unrevoke)$#', $r->path, $m)) {
            return $this->revokeAgent($r, (int) $m[1], $m[2] === 'revoke', $user);
        }
        if ($r->method !== 'GET') {
            return $this->notAllowed($r);
        }
        return match (true) {
            $r->path === '/' => $this->home($r, $user),
            $r->path === '/api/map' => $this->mapData($r),
            $r->path === '/admin/enrolment' => $this->enrolmentPage($user),
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
            // M-070: the agent's certificates and the revoke button, for admins.
            'agentAdmin' => Roles::managesAgents($user['role']) && $host['agent_id'] !== null
                ? (new AgentAdmin($this->db()))->show((string) $host['agent_id'])
                : null,
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

    // --- Admin: enrolment and revocation (M-070, MPD-40) ----------------------

    /**
     * @param array{id: int, username: string, display_name: ?string, role: string} $user
     * @param array<string, string> $form
     */
    private function enrolmentPage(array $user, array $form = [], ?string $error = null, int $status = 200): WebResponse
    {
        if (!Roles::managesAgents($user['role'])) {
            return $this->adminOnly($user);
        }
        $store = $this->tokenStore($this->db());
        return $this->page('admin-enrolment', $user, [
            'title' => 'Enrolment',
            'tokens' => $store->list(true, null, 100),
            'groups' => $store->groups(),
            'agents' => (new AgentAdmin($this->db()))->list(),
            'crl' => CrlTrigger::fromConfig($this->config)->status(time()),
            'manualHours' => $this->config->int('enrol', 'manual_token_hours'),
            'maxDays' => $this->config->int('enrol', 'deployment_max_days'),
            'staleAfter' => $this->config->int('dashboard', 'stale_after_s'),
            'form' => $form,
            'error' => $error,
        ], $status);
    }

    /**
     * POST /admin/tokens: a new token, shown once on the answer to this POST
     * (never in a redirect, a URL or a log), with Cache-Control: no-store
     * (M-068). Only its SHA-256 is stored (MS-SEC-03, MS-SEC-10).
     *
     * @param array{id: int, username: string, display_name: ?string, role: string} $user
     */
    private function createToken(WebRequest $r, array $user): WebResponse
    {
        if (!Roles::managesAgents($user['role'])) {
            return $this->adminOnly($user);
        }
        if (!Csrf::valid($r, $this->session->csrfToken())) {
            return $this->expired($user);
        }
        $form = [
            'kind' => $r->p('kind'),
            'days' => $r->p('days'),
            'group' => $r->p('group'),
            'hostname' => trim($r->p('hostname')),
            'reenroll' => strtolower(trim($r->p('reenroll'))),
            'note' => $r->p('note'),
        ];
        $o = [
            'kind' => $form['kind'],
            'group' => $form['group'],
            // A manual token has no choice of length (M-021); the days field is for deployment tokens.
            'days' => $form['kind'] === 'deployment' ? $form['days'] : null,
            'hostname' => $form['hostname'] === '' ? null : $form['hostname'],
            'reenroll' => $form['reenroll'] === '' ? null : $form['reenroll'],
            'note' => $form['note'] === '' ? null : $form['note'],
        ];
        try {
            $t = $this->tokenStore($this->adminDb())->create($o, $user['username'], null, $user['id'], $r->ip);
        } catch (\InvalidArgumentException $e) {
            return $this->enrolmentPage($user, $form, ucfirst(self::formMessage($e->getMessage())) . '. No token was made.', 400);
        }
        return $this->page('admin-token', $user, ['title' => 'New enrolment token', 't' => $t])
            ->with('Cache-Control', 'no-store');
    }

    /** @param array{id: int, username: string, display_name: ?string, role: string} $user */
    private function cancelToken(WebRequest $r, int $id, array $user): WebResponse
    {
        if (!Roles::managesAgents($user['role'])) {
            return $this->adminOnly($user);
        }
        if (!Csrf::valid($r, $this->session->csrfToken())) {
            return $this->expired($user);
        }
        try {
            $this->tokenStore($this->adminDb())->cancel($id, $user['username'], null, $user['id'], $r->ip);
            $this->session->flash('ok|' . "Token $id is cancelled. It can't be used to enrol.");
        } catch (\InvalidArgumentException $e) {
            $this->session->flash('bad|' . ucfirst($e->getMessage()) . '. Nothing changed.');
        }
        return WebResponse::redirect('/admin/enrolment');
    }

    /**
     * POST /hosts/{id}/revoke and /unrevoke (M-070, MS-SEC-02, MS-SEC-08): the
     * form names the agent it was shown for, so a page that is out of date
     * (the host has had a new agent since) changes nothing. A reason is required.
     *
     * @param array{id: int, username: string, display_name: ?string, role: string} $user
     */
    private function revokeAgent(WebRequest $r, int $hostPk, bool $revoke, array $user): WebResponse
    {
        if (!Roles::managesAgents($user['role'])) {
            return $this->adminOnly($user);
        }
        if (!Csrf::valid($r, $this->session->csrfToken())) {
            return $this->expired($user);
        }
        $host = (new HostQuery($this->db(), $this->config))->host($hostPk, $this->config, time());
        if ($host === null) {
            return $this->page('error', $user, ['title' => 'Not found', 'message' => 'There is no host with this number.'], 404);
        }
        $agentId = $r->p('agent_id');
        if ($host['agent_id'] === null || !hash_equals((string) $host['agent_id'], $agentId)) {
            $this->session->flash('bad|The host has a different agent now. Nothing changed; check the page and try again.');
            return WebResponse::redirect('/hosts/' . $hostPk);
        }
        $admin = new AgentAdmin($this->adminDb());
        try {
            if ($revoke) {
                $admin->revoke($agentId, $r->p('reason'), $user['username'], $user['id'], $r->ip);
                $msg = 'The agent is revoked: every request it makes is refused from now on.';
            } else {
                $admin->unrevoke($agentId, $r->p('reason'), $user['username'], $user['id'], $r->ip);
                $msg = "The agent is unrevoked: its certificates that aren't revoked themselves work again once the revocation list is rebuilt.";
            }
        } catch (\InvalidArgumentException $e) {
            $this->session->flash('bad|' . ucfirst(self::formMessage($e->getMessage())) . '. Nothing changed.');
            return WebResponse::redirect('/hosts/' . $hostPk);
        }
        $this->session->flash('ok|' . $msg . $this->rebuildCrl());
        return WebResponse::redirect('/hosts/' . $hostPk);
    }

    /**
     * M-071: a revocation or unrevocation asks for a CRL rebuild. The database
     * check already refuses a revoked agent, so a failed request is reported,
     * not fatal.
     */
    private function rebuildCrl(): string
    {
        try {
            if ($this->afterRevocation !== null) {
                ($this->afterRevocation)();
            } else {
                CrlTrigger::fromConfig($this->config)->request();
            }
            return ' The revocation list is being rebuilt.';
        } catch (\Throwable $e) {
            error_log('aio dashboard: CRL rebuild request failed: ' . $e::class);
            return ' The revocation list could not be asked to rebuild; the daily rebuild will catch up (see the health page).';
        }
    }

    /** The services' messages name the CLI's options (--reason); the form's fields have the same names. */
    private static function formMessage(string $m): string
    {
        return (string) preg_replace(['/ \(--reason="\.\.\."\)/', '/--([a-z]+)/'], ['', '"$1"'], $m);
    }

    private function tokenStore(PDO $pdo): TokenStore
    {
        return new TokenStore($pdo, $this->config->int('enrol', 'manual_token_hours'), $this->config->int('enrol', 'deployment_max_days'));
    }

    /** @param array{id: int, username: string, display_name: ?string, role: string} $user */
    private function adminOnly(array $user): WebResponse
    {
        return $this->page('error', $user, ['title' => 'Not allowed', 'message' => 'Enrolment and revocation are for admins only.'], 403);
    }

    /** @param array{id: int, username: string, display_name: ?string, role: string} $user */
    private function expired(array $user): WebResponse
    {
        return $this->page('error', $user, ['title' => 'Nothing changed', 'message' => 'The form had expired. Go back, reload the page and try again.'], 400);
    }

    /**
     * @param ?array{id: int, username: string, display_name: ?string, role: string} $user
     * @param array<string, mixed> $v
     */
    private function page(string $template, ?array $user, array $v, int $status = 200): WebResponse
    {
        // The message of the last change (M-070): "ok|..." or "bad|...".
        $flash = $user === null ? null : $this->session->flash();
        $notice = $flash === null ? null : (str_starts_with($flash, 'ok|') ? ['ok', substr($flash, 3)] : ['bad', str_starts_with($flash, 'bad|') ? substr($flash, 4) : $flash]);
        // M-071, M-068: admins see a CRL that expires within 7 days, or whose last rebuild failed, on every page.
        if ($user !== null && $template !== 'admin-enrolment' && Roles::managesAgents($user['role'])) {
            $crl = CrlTrigger::fromConfig($this->config)->status(time());
            if ($crl['state'] !== 'ok') {
                $v['crlWarning'] = $crl['message'];
            }
        }
        return WebResponse::html($this->view->render($template, $v + ['user' => $user, 'csrf' => $this->session->csrfToken(), 'notice' => $notice]), $status);
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

    /** aio_admin (M-070): only ever called after Roles::managesAgents(). */
    private function adminDb(): PDO
    {
        return $this->adminPdo ??= $this->connectAdmin !== null
            ? ($this->connectAdmin)()
            : Db::connect($this->config->string('db', 'admin_cnf'), $this->config->string('db', 'name'));
    }

    private function db(): PDO
    {
        return $this->pdo ??= $this->connect !== null
            ? ($this->connect)()
            : Db::connect($this->config->string('db', 'dashboard_cnf'), $this->config->string('db', 'name'));
    }
}
