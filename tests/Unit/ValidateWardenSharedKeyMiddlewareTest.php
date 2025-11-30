<?php

namespace Warden\Tests\Unit;

use Illuminate\Http\Request;
use Warden\Http\Middleware\ValidateWardenSharedKey;
use Warden\Tests\TestCase;

class ValidateWardenSharedKeyMiddlewareTest extends TestCase
{
    protected ValidateWardenSharedKey $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ValidateWardenSharedKey;
    }

    public function test_allows_request_with_valid_header_key(): void
    {
        config(['warden.shared_key' => 'test-key']);

        $request = Request::create('/mcp/warden', 'POST');
        $request->headers->set('X-Warden-Key', 'test-key');

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['success' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_allows_request_with_valid_query_key(): void
    {
        config(['warden.shared_key' => 'test-key']);

        $request = Request::create('/mcp/warden?warden_key=test-key', 'POST');

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['success' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_allows_request_with_valid_bearer_token(): void
    {
        config(['warden.shared_key' => 'test-key']);

        $request = Request::create('/mcp/warden', 'POST');
        $request->headers->set('Authorization', 'Bearer test-key');

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['success' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_rejects_request_with_invalid_key(): void
    {
        config(['warden.shared_key' => 'test-key']);

        $request = Request::create('/mcp/warden', 'POST');
        $request->headers->set('X-Warden-Key', 'wrong-key');

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['success' => true]));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_rejects_request_with_missing_key(): void
    {
        config(['warden.shared_key' => 'test-key']);

        $request = Request::create('/mcp/warden', 'POST');

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['success' => true]));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_allows_request_when_no_key_configured_in_non_production(): void
    {
        config(['warden.shared_key' => null]);
        $this->app['env'] = 'local';

        $request = Request::create('/mcp/warden', 'POST');

        $response = $this->middleware->handle($request, fn ($req) => response()->json(['success' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }
}
