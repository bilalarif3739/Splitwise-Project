<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Token;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sections 3-8: registration, login, logout, profile and the random-token rules.
 */
final class AuthTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array{0: TestResponse, 1: array<string, mixed>}
     */
    private function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);

        return [$this->postJson('/api/register', $payload), $payload];
    }

    public function test_register_creates_a_user_and_returns_a_random_token(): void
    {
        [$response, $payload] = $this->registerUser();

        $response->assertStatus(201)->assertJsonPath('success', true);

        $token = $response->json('data.token');

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertTrue(User::where('email', $payload['email'])->exists());
        $this->assertTrue(Token::where('api_token', $token)->exists());
        $this->assertStringNotContainsString($payload['password'], $response->getContent());
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        $this->registerUser();

        [$response] = $this->registerUser();

        $response->assertStatus(422)->assertJsonPath('success', false);
        $response->assertJsonStructure(['success', 'message', 'errors' => ['email']]);
        $this->assertSame(1, User::where('email', 'test@example.com')->count());
    }

    public function test_register_validates_its_input(): void
    {
        $response = $this->postJson('/api/register', ['email' => 'not-an-email']);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $response->assertJsonStructure(['success', 'message', 'errors' => ['name', 'email', 'password']]);
    }

    public function test_login_returns_a_new_token(): void
    {
        [$registered, $payload] = $this->registerUser();
        $registerToken = $registered->json('data.token');

        $response = $this->postJson('/api/login', [
            'email' => $payload['email'],
            'password' => $payload['password'],
        ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $loginToken = $response->json('data.token');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $loginToken);
        $this->assertNotSame($registerToken, $loginToken);
        $this->assertTrue(Token::where('api_token', $loginToken)->exists());
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        [, $payload] = $this->registerUser();

        $this->postJson('/api/login', [
            'email' => $payload['email'],
            'password' => 'not-the-password',
        ])->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_login_rejects_an_unknown_email(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
        ])->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
        $this->postJson('/api/logout')->assertStatus(401);
    }

    public function test_protected_routes_reject_an_invalid_token(): void
    {
        $this->withToken('garbage')->getJson('/api/me')->assertStatus(401);
    }

    public function test_me_returns_the_user_without_secrets(): void
    {
        [$registered, $payload] = $this->registerUser();
        $token = $registered->json('data.token');

        $response = $this->withToken($token)->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['email' => $payload['email']]);

        $this->assertStringNotContainsString($payload['password'], $response->getContent());
        $this->assertStringNotContainsString((string) $token, $response->getContent());
    }

    public function test_logout_invalidates_only_the_presented_token(): void
    {
        [$registered, $payload] = $this->registerUser();
        $tokenA = $registered->json('data.token');

        $tokenB = $this->postJson('/api/login', [
            'email' => $payload['email'],
            'password' => $payload['password'],
        ])->json('data.token');

        $this->withToken($tokenA)->postJson('/api/logout')->assertStatus(200);

        $this->withToken($tokenA)->getJson('/api/me')->assertStatus(401);
        $this->withToken($tokenB)->getJson('/api/me')->assertStatus(200);

        $this->assertFalse(Token::where('api_token', $tokenA)->exists());
        $this->assertTrue(Token::where('api_token', $tokenB)->exists());
    }

    public function test_tokens_are_random_and_not_derived_from_user_data(): void
    {
        [$first, $firstPayload] = $this->registerUser();
        [$second] = $this->registerUser(['email' => 'second@example.com']);

        $tokenA = (string) $first->json('data.token');
        $tokenB = (string) $second->json('data.token');

        $this->assertNotSame($tokenA, $tokenB);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tokenB);
        $this->assertStringNotContainsString($firstPayload['email'], $tokenA);
        $this->assertStringNotContainsString(
            (string) User::where('email', $firstPayload['email'])->first()?->stringId(),
            $tokenA
        );
    }
}