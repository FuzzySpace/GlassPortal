<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\SignedLaunchTokenService;
use GlassHouse\PortalAuth\DTO\BackChannelRedeemResult;
use GlassHouse\PortalAuth\DTO\SignedLaunchVerificationResult;
use GlassHouse\PortalAuth\DTO\VerifiedLaunchContext;
use GlassHouse\PortalAuth\Replay\ArrayReplayStore;
use GlassHouse\PortalAuth\Sso\ModuleSecretResolver;
use GlassHouse\PortalAuth\Sso\SignedLaunchTokenParser;
use GlassHouse\PortalAuth\Sso\SignedLaunchVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SDK dogfood test — proves the glasshouse/portal-auth SDK correctly validates
 * tokens issued by GlassPortal's own SignedLaunchTokenService.
 *
 * This is the integration bridge: GlassPortal generates the token; a module
 * (consuming only the SDK) verifies it. If this test passes, any downstream
 * module using the SDK can trust the same contract.
 */
class SdkDogfoodTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'dogfood-integration-secret-long-enough-for-hmac-sha256';
    private const ISSUER = 'glassportal';

    /** @var OrganizationModuleLink */
    private $link;

    /** @var User */
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['glasshouse_sso.signing_secret'         => self::SECRET]);
        config(['glasshouse_sso.per_module_secrets'      => []]);
        config(['glasshouse_sso.keys'                   => []]);
        config(['glasshouse_sso.issuer'                 => self::ISSUER]);
        config(['glasshouse_sso.default_ttl_seconds'    => 60]);
        config(['glasshouse_sso.max_ttl_seconds'        => 300]);
        config(['glasshouse_sso.clock_skew_seconds'     => 30]);
        config(['glasshouse_sso.nonce_cache_ttl_seconds' => 600]);

        $org        = Organization::factory()->create();
        $this->link = OrganizationModuleLink::factory()
            ->withLaunchUrl('https://panel.test')
            ->forModule('glasspanel', 'GlassPanel')
            ->create(['organization_id' => $org->id, 'auth_mode' => 'signed_launch', 'status' => 'active']);
        $this->user = User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org->id,
            'email'           => 'panel-user@example.test',
            'name'            => 'Panel User',
        ]);
    }

    private function makeVerifier(?string $secret = null, array $perModule = []): SignedLaunchVerifier
    {
        return new SignedLaunchVerifier(
            secretResolver: new ModuleSecretResolver($secret ?? self::SECRET, $perModule),
            replayStore:    new ArrayReplayStore(),
            parser:         new SignedLaunchTokenParser(),
            issuer:         self::ISSUER,
            clockSkew:      30,
        );
    }

    private function generateToken(): array
    {
        return (new SignedLaunchTokenService())->generate($this->link, $this->user);
    }

    // =========================================================================
    // Core dogfood: GlassPortal token → SDK verifier
    // =========================================================================

    public function test_sdk_verifies_token_generated_by_portal_service(): void
    {
        $generated = $this->generateToken();
        $verifier  = $this->makeVerifier();

        $result = $verifier->verify($generated['token'], 'glasspanel');

        $this->assertTrue($result->ok, "SDK must accept a valid GlassPortal-issued token. Reason: {$result->reason}");
        $this->assertSame('glasspanel', $result->context->audience);
        $this->assertSame((string) $this->user->id, $result->context->userId);
        $this->assertSame((string) $this->link->organization_id, $result->context->orgId);
        $this->assertSame((string) $this->link->id, $result->context->moduleLinkId);
    }

    public function test_sdk_context_carries_correct_identity(): void
    {
        $result = $this->makeVerifier()->verify($this->generateToken()['token'], 'glasspanel');

        $this->assertTrue($result->ok);
        $this->assertSame($this->user->email, $result->context->email);
        $this->assertSame($this->user->name, $result->context->name);
        $this->assertSame('customer', $result->context->role);
        $this->assertSame(self::ISSUER, $result->context->issuer);
    }

    public function test_sdk_result_is_correct_dto_types(): void
    {
        $result = $this->makeVerifier()->verify($this->generateToken()['token'], 'glasspanel');

        $this->assertInstanceOf(SignedLaunchVerificationResult::class, $result);
        $this->assertInstanceOf(VerifiedLaunchContext::class, $result->context);
    }

    // =========================================================================
    // Security: no leakage
    // =========================================================================

    public function test_raw_token_not_present_in_result(): void
    {
        $generated = $this->generateToken();
        $result    = $this->makeVerifier()->verify($generated['token'], 'glasspanel');

        $serialized = serialize($result);
        $this->assertStringNotContainsString($generated['token'], $serialized,
            'Raw token must never appear in the verification result');
    }

    public function test_signing_secret_not_present_in_result(): void
    {
        $result     = $this->makeVerifier()->verify($this->generateToken()['token'], 'glasspanel');
        $serialized = serialize($result);

        $this->assertStringNotContainsString(self::SECRET, $serialized,
            'Signing secret must never appear in the verification result');
    }

    public function test_signing_secret_not_present_in_failure_result(): void
    {
        $result     = $this->makeVerifier()->verify('invalid.token.here', 'glasspanel');
        $serialized = serialize($result);

        $this->assertStringNotContainsString(self::SECRET, $serialized);
    }

    // =========================================================================
    // Tamper detection
    // =========================================================================

    public function test_sdk_rejects_tampered_payload(): void
    {
        $generated = $this->generateToken();
        $parts     = explode('.', $generated['token']);
        $parser    = new SignedLaunchTokenParser();

        // Replace the payload with a manipulated one claiming a different user
        $badPayload = base64_encode(json_encode(['sub' => '9999', 'aud' => 'glasspanel']));
        $parts[1]   = rtrim(strtr($badPayload, '+/', '-_'), '=');

        $result = $this->makeVerifier()->verify(implode('.', $parts), 'glasspanel');

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_signature', $result->reason);
    }

    public function test_sdk_rejects_token_signed_with_different_secret(): void
    {
        // Use wrong secret to sign; portal signed with correct one
        $wrongVerifier = $this->makeVerifier('completely-different-secret-not-the-portal-key');
        $result        = $wrongVerifier->verify($this->generateToken()['token'], 'glasspanel');

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_signature', $result->reason);
    }

    public function test_sdk_rejects_token_for_wrong_module(): void
    {
        // Token was issued for glasspanel, verified for aria
        $result = $this->makeVerifier()->verify($this->generateToken()['token'], 'aria');

        $this->assertFalse($result->ok);
        $this->assertSame('wrong_audience', $result->reason);
    }

    // =========================================================================
    // Replay detection
    // =========================================================================

    public function test_sdk_detects_replay_on_second_use(): void
    {
        $token    = $this->generateToken()['token'];
        $verifier = $this->makeVerifier();

        $first  = $verifier->verify($token, 'glasspanel');
        $second = $verifier->verify($token, 'glasspanel');

        $this->assertTrue($first->ok);
        $this->assertFalse($second->ok);
        $this->assertSame('replay_detected', $second->reason);
    }

    public function test_separate_verifier_instances_share_no_replay_state(): void
    {
        // Each ArrayReplayStore is fresh — two independent verifiers don't share state
        $token = $this->generateToken()['token'];

        $v1 = $this->makeVerifier();
        $v2 = $this->makeVerifier();

        $r1 = $v1->verify($token, 'glasspanel');
        $r2 = $v2->verify($token, 'glasspanel');

        // Both succeed because they have separate ArrayReplayStore instances
        $this->assertTrue($r1->ok, 'First verifier must succeed');
        $this->assertTrue($r2->ok, 'Independent verifier must also succeed with fresh replay store');
    }

    // =========================================================================
    // Per-module secret — end-to-end via portal config
    // =========================================================================

    public function test_sdk_verifies_token_using_per_module_secret(): void
    {
        $moduleSecret = 'glasspanel-specific-secret-long-enough-for-hmac';
        config(['glasshouse_sso.per_module_secrets' => ['glasspanel' => $moduleSecret]]);

        $generated = $this->generateToken();  // service now uses per-module secret
        $verifier  = $this->makeVerifier(
            secret:    'global-that-must-not-be-used',
            perModule: ['glasspanel' => $moduleSecret],
        );

        $result = $verifier->verify($generated['token'], 'glasspanel');

        $this->assertTrue($result->ok,
            "SDK must verify a token signed with the per-module secret. Reason: {$result->reason}");
    }

    public function test_sdk_rejects_token_when_per_module_secret_mismatched(): void
    {
        $portalSecret = 'glasspanel-secret-portal-has-this-long-enough';
        $wrongSecret  = 'glasspanel-secret-module-has-wrong-version-xx';

        config(['glasshouse_sso.per_module_secrets' => ['glasspanel' => $portalSecret]]);
        $generated = $this->generateToken();

        $verifier = $this->makeVerifier(
            secret:    'global',
            perModule: ['glasspanel' => $wrongSecret],  // module has wrong secret
        );

        $result = $verifier->verify($generated['token'], 'glasspanel');
        $this->assertFalse($result->ok);
        $this->assertSame('invalid_signature', $result->reason);
    }

    // =========================================================================
    // SDK autoload proof
    // =========================================================================

    public function test_all_sdk_classes_are_autoloadable(): void
    {
        $classes = [
            \GlassHouse\PortalAuth\Sso\SignedLaunchVerifier::class,
            \GlassHouse\PortalAuth\Sso\ModuleSecretResolver::class,
            \GlassHouse\PortalAuth\Sso\SignedLaunchTokenParser::class,
            \GlassHouse\PortalAuth\Replay\ArrayReplayStore::class,
            \GlassHouse\PortalAuth\Replay\LaravelCacheReplayStore::class,
            \GlassHouse\PortalAuth\Contracts\SecretResolverInterface::class,
            \GlassHouse\PortalAuth\Contracts\ReplayStoreInterface::class,
            \GlassHouse\PortalAuth\DTO\SignedLaunchVerificationResult::class,
            \GlassHouse\PortalAuth\DTO\VerifiedLaunchContext::class,
            \GlassHouse\PortalAuth\DTO\BackChannelRedeemResult::class,
            \GlassHouse\PortalAuth\Exceptions\PortalAuthException::class,
            \GlassHouse\PortalAuth\Laravel\PortalAuthServiceProvider::class,
            \GlassHouse\PortalAuth\Laravel\Middleware\VerifySignedModuleLaunch::class,
            \GlassHouse\PortalAuth\Laravel\Middleware\VerifyBackChannelMtls::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                class_exists($class) || interface_exists($class),
                "SDK class not autoloadable: {$class}"
            );
        }
    }

    // =========================================================================
    // DTO: BackChannelRedeemResult
    // =========================================================================

    public function test_back_channel_redeem_result_builds_from_response(): void
    {
        $result = BackChannelRedeemResult::fromResponse([
            'ok'         => true,
            'module_key' => 'glasspanel',
            'user_id'    => '42',
            'org_id'     => '7',
            'email'      => 'user@example.com',
            'name'       => 'Test User',
            'role'       => 'customer',
            'expires_at' => time() + 60,
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame('glasspanel', $result->moduleKey);
        $this->assertSame('42', $result->userId);
    }

    public function test_back_channel_error_result_carries_no_pii(): void
    {
        $result = BackChannelRedeemResult::fromErrorResponse([
            'ok'     => false,
            'reason' => 'code_replayed',
        ]);

        $this->assertFalse($result->ok);
        $this->assertSame('code_replayed', $result->reason);
        $this->assertNull($result->email);
        $this->assertNull($result->name);
    }
}
