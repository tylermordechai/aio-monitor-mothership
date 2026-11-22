<?php

declare(strict_types=1);

namespace Aio\Web;

/**
 * MS-SEC-05 CSRF protection for every POST: the session's token in the
 * form's `_csrf` field, and the browser's Origin / Sec-Fetch-Site headers,
 * when sent, must say the request came from the dashboard itself. The
 * session cookie is also SameSite=Strict, so this is the second of two locks.
 */
final class Csrf
{
    public const FIELD = '_csrf';

    public static function valid(WebRequest $r, string $expected): bool
    {
        $sent = $r->p(self::FIELD);
        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            return false;
        }
        $origin = $r->header('origin');
        if ($origin !== null && $origin !== 'null' && $r->host !== '' && strcasecmp($origin, 'https://' . $r->host) !== 0) {
            return false;
        }
        if ($origin === 'null') {
            return false;
        }
        $site = $r->header('sec-fetch-site');
        return $site === null || in_array($site, ['same-origin', 'none'], true);
    }
}
