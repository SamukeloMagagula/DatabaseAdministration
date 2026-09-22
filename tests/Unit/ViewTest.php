<?php

namespace Tests\Unit;

use App\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    protected function tearDown(): void
    {
        View::setViewsPath(dirname(__DIR__, 2) . '/resources/views');
    }

    public function test_render_injects_data_into_template_with_no_layout(): void
    {
        View::setViewsPath(__DIR__ . '/../Fixtures/views');

        $html = View::render('hello', ['name' => 'World'], null);

        $this->assertSame('Hello, World!', trim($html));
    }

    public function test_e_escapes_html_special_characters(): void
    {
        $this->assertSame('&lt;script&gt;', View::e('<script>'));
    }

    public function test_e_handles_null(): void
    {
        $this->assertSame('', View::e(null));
    }
}
