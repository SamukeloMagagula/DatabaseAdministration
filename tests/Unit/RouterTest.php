<?php

namespace Tests\Unit;

use App\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_matches_a_static_route(): void
    {
        $router = new Router();
        $router->add('GET', '/hello', fn($p) => 'hi');

        $match = $router->match('GET', '/hello');

        $this->assertNotNull($match);
        $this->assertSame('hi', ($match['handler'])($match['params']));
    }

    public function test_matches_a_route_with_params(): void
    {
        $router = new Router();
        $router->add('GET', '/db/{db}/table/{table}', fn($p) => $p['db'] . ':' . $p['table']);

        $match = $router->match('GET', '/db/shop/table/orders');

        $this->assertSame(['db' => 'shop', 'table' => 'orders'], $match['params']);
        $this->assertSame('shop:orders', ($match['handler'])($match['params']));
    }

    public function test_params_are_percent_decoded(): void
    {
        $router = new Router();
        $router->add('GET', '/db/{db}/table/{table}', fn($p) => $p['db'] . ':' . $p['table']);

        $match = $router->match('GET', '/db/my%20shop/table/order%2Ditems%25');

        $this->assertSame(['db' => 'my shop', 'table' => 'order-items%'], $match['params']);
    }

    public function test_encoded_slash_stays_inside_one_segment(): void
    {
        $router = new Router();
        $router->add('GET', '/db/{db}/tables', fn($p) => $p['db']);

        $match = $router->match('GET', '/db/a%2Fb/tables');

        $this->assertSame('a/b', $match['params']['db']);
    }

    public function test_returns_null_when_no_route_matches(): void
    {
        $router = new Router();
        $router->add('GET', '/hello', fn($p) => 'hi');

        $this->assertNull($router->match('GET', '/missing'));
    }

    public function test_method_must_also_match(): void
    {
        $router = new Router();
        $router->add('GET', '/hello', fn($p) => 'hi');

        $this->assertNull($router->match('POST', '/hello'));
    }
}
