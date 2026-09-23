<?php

declare(strict_types=1);

/**
 * Cross-site request forgery tokens.
 *
 * The session cookie is SameSite=Strict, which already blocks cross-site
 * POSTs on current browsers — but it is the only thing doing so. One changed
 * cookie attribute, one browser that treats Strict differently, and every
 * state-changing endpoint in the app is forgeable. This is the second lock.
 *
 * The token lives in the session, so every form on an authenticated page can
 * read it straight from $_SESSION without a round trip.
 */

/** The session's token, minted on first use. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

/** Constant-time comparison against the session's token. False if either is missing. */
function csrf_valid(?string $candidate): bool
{
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $candidate === null || $candidate === '') return false;
    return hash_equals($expected, $candidate);
}
