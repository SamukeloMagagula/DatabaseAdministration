<?php

namespace Tests\Unit;

use App\Http;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function test_redirect_returns_empty_body(): void
    {
        $this->assertSame('', Http::redirect('/somewhere'));
    }
}
