<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\UnauthenticatedException;
use App\Models\Token;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single authentication entry point of the API.
 *
 * Reads the Authorization header, extracts the Bearer token, resolves it to a
 * user in MongoDB and attaches both the user and the token document to the
 * request. No controller performs authentication itself.
 */
final class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $this->extractBearerToken($request);

        if ($plainToken === null) {
            Log::warning('Authentication failed.', ['reason' => 'missing_token', 'path' => $request->path()]);

            throw new UnauthenticatedException('Authentication token is missing.');
        }

        $token = Token::where('api_token', $plainToken)->first();

        if ($token === null) {
            // The rejected token is never written to the log - only the reason.
            Log::warning('Authentication failed.', ['reason' => 'invalid_token', 'path' => $request->path()]);

            throw new UnauthenticatedException('Invalid authentication token.');
        }

        $user = User::find($token->user_id);

        if ($user === null) {
            // Token outlived its user: clean it up and reject.
            $token->delete();

            Log::warning('Authentication failed.', ['reason' => 'orphaned_token', 'path' => $request->path()]);

            throw new UnauthenticatedException('Invalid authentication token.');
        }

        // Attach the authenticated user (so $request->user() works, as Laravel
        // code expects) and the exact token document (so logout can delete
        // precisely the token that was presented).
        $request->setUserResolver(static fn(): User => $user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $plainToken = trim(substr($header, strlen('Bearer ')));

        return $plainToken === '' ? null : $plainToken;
    }
}