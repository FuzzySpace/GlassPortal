<?php

namespace Tests\Unit\PortalAuthSdk;

use GlassHouse\PortalAuth\Replay\ArrayReplayStore;
use GlassHouse\PortalAuth\Sso\ModuleSecretResolver;
use GlassHouse\PortalAuth\Sso\SignedLaunchTokenParser;
use GlassHouse\PortalAuth\Sso\SignedLaunchVerifier;
use GlassHouse\PortalAuth\Laravel\Middleware\VerifySignedModuleLaunch;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Tests that the SDK middleware enforces POST-body-only token delivery.
 */
class SdkMiddlewareQueryStringTest extends TestCase
{
    private const SECRET     = 'test-signing-secret-long-enough-for-hmac-256';
    private const MODULE_KEY = 'glasspanel';

    private VerifySignedModuleLaunch $middleware;
    private SignedLaunchTokenParser  $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new SignedLaunchTokenParser();
        $replayStore  = new ArrayReplayStore();
        $verifier     = new SignedLaunchVerifier(
            secretResolver: new ModuleSecretResolver(self::SECRET),
            replayStore:    $replayStore,
            parser:         $this->parser,
            issuer:         'glassportal',
            clockSkew:      30,
        );
        $this->middleware = new VerifySignedModuleLaunch($verifier);
    }

    private function buildToken(): string
    {
        $now    = time();
        $claims = [
            'iss' => 'glassportal', 'aud' => self::MODULE_KEY,
            'sub' => '1', 'org' => '1', 'mid' => '1',
            'email' => 'u@e.com', 'name' => 'U', 'role' => 'customer',
            'iat' => $now, 'exp' => $now + 60,
            'nonce' => 'n1', 'jti' => bin2hex(random_bytes(8)),
        ];
        $h   = $this->parser->encode(json_encode(['alg' => 'HS256', 'typ' => 'SLP']));
        $p   = $this->parser->encode(json_encode($claims));
        $sig = $this->parser->hmacB64("{$h}.{$p}", self::SECRET);
        return "{$h}.{$p}.{$sig}";
    }

    public function test_query_string_token_rejected_with_400(): void
    {
        $token   = $this->buildToken();
        $request = Request::create(
            '/' . self::MODULE_KEY . '/sso',
            'POST',
            [],    // post params
            [],    // cookies
            [],    // files
            [],    // server
        );
        // Manually add query parameter
        $request->query->set('signed_launch_token', $token);
        // Simulate route parameter
        $request->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('POST', '/glasspanel/sso', []), function ($route) {
            $route->bind(Request::create('/glasspanel/sso', 'POST'));
        }));

        $response = $this->middleware->handle($request, fn ($r) => response()->json(['ok' => true]), self::MODULE_KEY);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('query_string_token', $data['reason']);
    }

    public function test_post_body_token_accepted(): void
    {
        $token   = $this->buildToken();
        $request = Request::create('/', 'POST', ['signed_launch_token' => $token]);

        $response = $this->middleware->handle(
            $request,
            fn ($r) => response()->json(['ok' => true]),
            self::MODULE_KEY
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_missing_token_returns_401(): void
    {
        $request  = Request::create('/', 'POST');
        $response = $this->middleware->handle(
            $request,
            fn ($r) => response()->json(['ok' => true]),
            self::MODULE_KEY
        );

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('missing_token', $data['reason']);
    }
}
