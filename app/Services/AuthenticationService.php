<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UnauthenticatedException;
use App\Models\Token;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Every authentication decision lives here: registering users, verifying
 * credentials, issuing random tokens and invalidating them on logout.
 *
 * Controllers never touch passwords, hashing or token generation.
 */
final class AuthenticationService
{
    /**
     * @return array{0: User, 1: string} [user, plain token]
     */
    public function register(string $name, string $email, string $password): array
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $token = $this->issueToken($user);

        Log::info('User registered.', ['user_id' => $user->stringId()]);

        return [$user, $token];
    }

    /**
     * @return array{0: User, 1: string} [user, plain token]
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        // The same message covers "no such user" and "wrong password", so the
        // endpoint cannot be used to discover which emails are registered.
        if ($user === null || !Hash::check($password, (string) $user->password)) {
            Log::warning('Authentication failure.', [
                'email' => $email,
                'reason' => 'invalid_credentials',
            ]);

            throw new UnauthenticatedException('The provided credentials are incorrect.');
        }

        $token = $this->issueToken($user);

        Log::info('User logged in.', ['user_id' => $user->stringId()]);

        return [$user, $token];
    }

    /**
     * Invalidates exactly the token that was used for this request, so other
     * devices stay logged in.
     */
    public function logout(Token $token): void
    {
        $userId = $token->user_id;

        $token->delete();

        Log::info('User logged out.', ['user_id' => $userId]);
    }

    /**
     * Generate a cryptographically secure random token and persist it.
     *
     * random_bytes() draws from the operating system CSPRNG, so the token is
     * not derived from - and cannot be predicted from - the user id, email,
     * timestamp or password, which is exactly what section 4 demands.
     */
    private function issueToken(User $user): string
    {
        $apiToken = bin2hex(random_bytes(32));

        Token::create([
            'user_id' => $user->stringId(),
            'api_token' => $apiToken,
        ]);

        return $apiToken;
    }
}