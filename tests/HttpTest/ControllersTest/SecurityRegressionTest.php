<?php

namespace Tests;

use App\Mail\ForgotPassword;
use App\Models\User;
use App\Services\AuthLink;
use App\Services\Facades\Option;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class SecurityRegressionTest extends TestCase
{
    public function testEmailRuleRejectsHeaderAndNewlineInjection(): void
    {
        foreach (["victim@example.test\r\nBcc: other@example.test", "victim@example.test\n", "victim@example.test\0"] as $email) {
            $this->assertTrue(validator(['email' => $email], ['email' => 'email'])->fails());
        }
        $this->assertTrue(validator(['email' => 'normal@example.test'], ['email' => 'email'])->passes());
    }

    public function testOrdinaryAdministratorCannotChangeExecutableSourcesOrInstallPlugins(): void
    {
        $this->actingAs(User::factory()->create(['permission' => User::ADMIN]));
        Option::set('update_source', 'https://trusted.example.test/update.json');
        $this->get('/admin/options')->assertOk()->assertDontSee('name="update_source"', false);
        $this->post('/admin/options', ['option' => 'updates', 'update_source_preset' => 'patch'])->assertForbidden();
        $this->postJson('/admin/options', ['option' => true, 'update_source' => 'https://attacker.example.test/update.json'])->assertOk();
        $this->assertSame('https://trusted.example.test/update.json', option('update_source'));
        Http::fake();
        $this->postJson('/admin/plugins/market/download', ['name' => 'test'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function testPasswordResetMailUsesCanonicalHost(): void
    {
        Cache::flush();
        Mail::fake();
        Option::set('site_url', 'https://skin.example.test');
        $user = User::factory()->create();
        $this->withSession(['captcha' => 'known'])->postJson('http://attacker.example.test/auth/forgot', ['email' => $user->email, 'captcha' => 'known'])->assertJson(['code' => 0]);
        Mail::assertSent(ForgotPassword::class, fn ($mail) => str_starts_with($mail->url, 'https://skin.example.test/auth/reset/') && !str_contains($mail->url, 'attacker.example.test'));
    }

    public function testCanonicalAuthLinkKeepsSubdirectoryWithoutDuplicatingIt(): void
    {
        Option::set('site_url', 'https://skin.example.test/skin');
        $this->assertSame('https://skin.example.test/skin/auth/reset/1', AuthLink::absolute('/skin/auth/reset/1'));
        $this->assertSame('https://skin.example.test/skin/auth/reset/1', AuthLink::absolute('/auth/reset/1'));
    }

    public function testTokenGuardDoesNotQueryUsersWithArrayCredentials(): void
    {
        $provider = \Mockery::mock(\Illuminate\Contracts\Auth\UserProvider::class);
        $provider->shouldNotReceive('retrieveByCredentials');
        $request = \Illuminate\Http\Request::create('/api', 'GET', ['api_token' => ['invalid' => true]]);
        $guard = new \Illuminate\Auth\TokenGuard($provider, $request);
        $this->assertNull($guard->user());
    }

    public function testJwtRejectsWeakHmacKeys(): void
    {
        $this->expectException(\DomainException::class);
        \Firebase\JWT\JWT::encode(['sub' => 1], 'weak', 'HS256');
    }

    public function testPassportUpgradeKeepsTokenIssuanceAndApiAuthentication(): void
    {
        $user = User::factory()->create();
        $user->changePassword('Security-test-password-123');
        $client = app(\Laravel\Passport\ClientRepository::class)->createPasswordGrantClient(null, 'Security integration test', 'http://localhost', 'users');
        $response = $this->postJson('/oauth/token', ['grant_type' => 'password', 'client_id' => $client->id, 'client_secret' => $client->secret, 'username' => $user->email, 'password' => 'Security-test-password-123', 'scope' => 'User.Read'])->assertOk();
        $token = $response->json('access_token');
        $this->assertIsString($token);
        auth()->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/user')->assertOk()->assertJsonPath('uid', $user->uid);
    }
}
