<?php

declare(strict_types=1);

// Cross-site request forgery tokens. SameSite=Strict already blocks
// cross-site POSTs on current browsers, but this is the second lock in case
// that ever isn't true for a given cookie or browser.

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
