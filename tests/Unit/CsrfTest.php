<?php

namespace Tests\Unit;

use App\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function test_token_is_stable_across_calls_in_the_same_session(): void
    {
        $first = Csrf::token();
        $second = Csrf::token();

        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
    }

    public function test_validate_accepts_the_current_token(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::validate($token));
    }

    public function test_validate_rejects_a_wrong_token(): void
    {
        Csrf::token();

        $this->assertFalse(Csrf::validate('not-the-token'));
    }

    public function test_validate_rejects_null(): void
    {
        Csrf::token();

        $this->assertFalse(Csrf::validate(null));
    }

    public function test_validate_rejects_when_no_token_was_ever_issued(): void
    {
        $this->assertFalse(Csrf::validate('anything'));
    }
}
