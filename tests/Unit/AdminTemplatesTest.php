<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Web\Roles;
use Aio\Web\View;
use PHPUnit\Framework\TestCase;

/** The admin pages' templates and role rule (M-070, MS-SEC-05, MS-SEC-07). */
final class AdminTemplatesTest extends TestCase
{
    private const EVIL = '<script>alert(1)</script>';

    private static function view(): View
    {
        return new View(dirname(__DIR__, 2) . '/templates');
    }

    public function testMsSec07OnlyAdminsManageAgents(): void
    {
        self::assertTrue(Roles::managesAgents('admin'));
        self::assertFalse(Roles::managesAgents('analyst'));
        self::assertFalse(Roles::managesAgents('viewer'));
        self::assertFalse(Roles::managesAgents('root'));
    }

    public function testMsSec05EnrolmentPageEscapesEverythingAndCarriesTheCsrfToken(): void
    {
        $html = self::view()->render('admin-enrolment', [
            'user' => ['id' => 1, 'username' => 'a', 'display_name' => null, 'role' => 'admin'],
            'csrf' => 'tok123',
            'notice' => ['ok', self::EVIL],
            'error' => self::EVIL,
            'form' => ['hostname' => self::EVIL],
            'groups' => ['default', self::EVIL],
            'manualHours' => 24,
            'maxDays' => 7,
            'staleAfter' => 300,
            'tokens' => [[
                'id' => 5, 'state' => 'open', 'token_kind' => 'manual', 'reenroll_agent_id' => null, 'group_name' => self::EVIL,
                'expected_hostname' => self::EVIL, 'created_by_name' => self::EVIL, 'created_at' => '2026-11-28 10:00:00.000000',
                'expires_at' => '2026-11-29 10:00:00.000000', 'used_by_agent_id' => null, 'note' => self::EVIL, 'cancelled_by' => null,
            ]],
            'agents' => [[
                'agent_id' => '11111111-1111-4111-8111-111111111111', 'status' => 'active', 'host_pk' => 3, 'hostname' => self::EVIL,
                'group_name' => 'default', 'last_checkin_at' => null, 'checkin_epoch' => null, 'ingest_epoch' => null,
                'queue_records' => null, 'last_version' => self::EVIL, 'usable_certs' => 1, 'cert_expires' => '2027-11-28 10:00:00',
            ]],
            'crl' => ['state' => 'warn', 'message' => self::EVIL, 'entries' => 2],
        ]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertGreaterThanOrEqual(8, substr_count($html, '&lt;script&gt;'));
        self::assertSame(3, substr_count($html, 'name="_csrf" value="tok123"'), 'sign-out, the token form and the one cancel button');
        self::assertStringContainsString('action="/admin/tokens/5/cancel"', $html);
        self::assertStringContainsString('href="/admin/enrolment"', $html, 'the admin sees the Enrolment link');
    }

    public function testM068TheTokenPageShowsTheTokenOnceAndEscaped(): void
    {
        $html = self::view()->render('admin-token', [
            'user' => ['id' => 1, 'username' => 'a', 'display_name' => null, 'role' => 'admin'],
            'csrf' => 'x',
            't' => ['id' => 9, 'token' => 'aioe_' . str_repeat('A', 43), 'kind' => 'deployment', 'group' => self::EVIL, 'expires_at' => '2026-12-01 00:00:00', 'hostname' => null, 'reenroll' => null],
        ]);
        self::assertSame(1, substr_count($html, 'aioe_' . str_repeat('A', 43)));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('shown only on this page', $html);
    }

    public function testTheLayoutHidesTheEnrolmentLinkFromNonAdminsAndShowsTheCrlWarning(): void
    {
        $v = ['user' => ['id' => 1, 'username' => 'v', 'display_name' => null, 'role' => 'viewer'], 'csrf' => 'x', 'title' => 'T', 'message' => 'm'];
        self::assertStringNotContainsString('/admin/enrolment', self::view()->render('error', $v));
        $v['user']['role'] = 'admin';
        $v['crlWarning'] = 'expires soon';
        $html = self::view()->render('error', $v);
        self::assertStringContainsString('Revocation list:</strong> expires soon', $html);
    }
}
