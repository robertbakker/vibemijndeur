<?php

namespace App\Melvin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches an access token from the NDW Keycloak IAM using the OAuth2
 * "password" grant. The Melvin frontend client is a public client, so no
 * client secret is required.
 *
 * Tokens are cached until shortly before they expire.
 */
class OAuth2Client
{
    private const string CACHE_KEY = 'melvin.access_token';

    /** Seconds subtracted from the token lifetime to avoid using a near-expired token. */
    private const int EXPIRY_LEEWAY = 30;

    public function __construct(
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $username,
        private readonly string $password,
    ) {}

    public function accessToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->requestToken();
    }

    private function requestToken(): string
    {
        $response = Http::asForm()->post($this->tokenUrl, [
            'grant_type' => 'password',
            'client_id' => $this->clientId,
            'username' => $this->username,
            'password' => $this->password,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Failed to obtain Melvin access token (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Melvin token response did not contain an access_token');
        }

        $ttl = (int) $response->json('expires_in', 300) - self::EXPIRY_LEEWAY;
        if ($ttl > 0) {
            Cache::put(self::CACHE_KEY, $token, $ttl);
        }

        return $token;
    }
}
