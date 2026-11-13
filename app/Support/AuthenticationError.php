<?php

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

class AuthenticationError
{
    private const MISSING_TOKEN_MESSAGE = 'Authentication token is missing. Please provide a Bearer token.';

    private const INVALID_TOKEN_MESSAGE = 'Authentication token is invalid. Please log in again.';

    public static function messageFor(Request $request): string
    {
        $authorization = trim((string) $request->header('Authorization', ''));

        if ($authorization === '' || strcasecmp($authorization, 'Bearer') === 0) {
            return self::MISSING_TOKEN_MESSAGE;
        }

        $plainToken = $request->bearerToken();

        if (empty($plainToken)) {
            return self::INVALID_TOKEN_MESSAGE;
        }

        $model = Sanctum::$personalAccessTokenModel;

        if (str_contains($plainToken, '|') && (new $model)->getKeyType() === 'int') {
            [$id, $secret] = explode('|', $plainToken, 2);

            if (! ctype_digit($id) || empty($secret)) {
                return self::INVALID_TOKEN_MESSAGE;
            }
        }

        $accessToken = $model::findToken($plainToken);

        if ($accessToken === null) {
            return self::INVALID_TOKEN_MESSAGE;
        }

        $expiration = config('sanctum.expiration');

        if (($accessToken->expires_at !== null && $accessToken->expires_at->isPast())
            || ($expiration && ! $accessToken->created_at->gt(now()->subMinutes($expiration)))) {
            return 'Authentication token has expired. Please log in again.';
        }

        return self::INVALID_TOKEN_MESSAGE;
    }
}
