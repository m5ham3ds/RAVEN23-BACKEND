<?php

namespace App\Support;

use Google\Client as GoogleClient;
use Throwable;

class GoogleIdTokenVerifier
{
    /**
     * Verify signature, issuer, expiry and audience through google/apiclient.
     * The audience must be the same Web OAuth client ID used by Flutter.
     *
     * A remote certificate-fetch failure is intentionally allowed to reach
     * the controller, which returns a retryable 503 rather than pretending
     * that a valid identity was rejected.
     */
    public function verify(string $idToken): ?array
    {
        $clientId = (string) config('services.google.client_id');
        if ($clientId === '') {
            return null;
        }

        $client = new GoogleClient(['client_id' => $clientId]);

        try {
            $claims = $client->verifyIdToken($idToken);
        } catch (Throwable $exception) {
            // Malformed/invalid JWTs are authentication failures. Transport
            // and Google certificate-service outages are handled separately
            // by the controller as retryable service failures.
            if (
                $exception instanceof \UnexpectedValueException
                || $exception instanceof \InvalidArgumentException
                || $exception instanceof \DomainException
            ) {
                return null;
            }

            throw $exception;
        }

        return is_array($claims) ? $claims : null;
    }
}
