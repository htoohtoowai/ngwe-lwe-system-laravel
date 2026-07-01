<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Auth\AuthenticationException;
use Tests\TestCase;

class NgweLweTokenServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('a', 32));
        config()->set('ngwe_lwe.auth.token_ttl_seconds', 3600);
    }

    public function test_token_contains_python_compatible_user_payload(): void
    {
        $user = new User([
            'username' => 'owner',
            'full_name' => 'Owner Name',
            'role' => 'owner',
            'is_active' => true,
            'auth_version' => 3,
        ]);
        $user->id = 7;

        $payload = (new NgweLweTokenService)->decode((new NgweLweTokenService)->create($user));

        $this->assertSame(7, $payload['user_id']);
        $this->assertSame('owner', $payload['username']);
        $this->assertSame('owner', $payload['role']);
        $this->assertSame(3, $payload['auth_version']);
        $this->assertGreaterThan(time(), $payload['exp']);
    }

    public function test_decode_rejects_tampered_signature(): void
    {
        $this->expectException(AuthenticationException::class);

        (new NgweLweTokenService)->decode('{"user_id":1}|bad-signature');
    }

    public function test_decode_rejects_expired_token(): void
    {
        $this->expectException(AuthenticationException::class);

        config()->set('ngwe_lwe.auth.token_ttl_seconds', -1);

        $user = new User(['username' => 'owner', 'role' => 'owner']);
        $user->id = 1;

        (new NgweLweTokenService)->decode((new NgweLweTokenService)->create($user));
    }
}
