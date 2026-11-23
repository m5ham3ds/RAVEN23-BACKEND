<?php

namespace App\Providers;

use App\Contracts\Storage\PrivateObjectStorage;
use App\Services\Storage\FilesystemPrivateObjectStorage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PrivateObjectStorage::class, static function (): PrivateObjectStorage {
            $disk = (string) config('filesystems.private_disk', 'private_media');

            return new FilesystemPrivateObjectStorage(Storage::disk($disk));
        });
    }

    public function boot(): void
    {
        $secret = (string) config('app.key');

        $fingerprint = static function (string $purpose, string $value) use ($secret): string {
            // PII/device identifiers never appear in cache keys or logs.
            return $purpose.':'.hash_hmac('sha256', $value, $secret);
        };

        $ipFingerprint = static function (Request $request) use ($fingerprint): string {
            return $fingerprint('ip', (string) ($request->ip() ?: 'unknown'));
        };

        $emailFingerprint = static function (Request $request) use ($fingerprint): ?string {
            $email = Str::lower(trim((string) $request->input('email', '')));

            return $email === '' ? null : $fingerprint('email', $email);
        };

        $deviceFingerprint = static function (Request $request) use ($fingerprint): ?string {
            $deviceId = trim((string) $request->input('device_id', ''));

            return $deviceId === '' ? null : $fingerprint('device', $deviceId);
        };

        $limit = static fn (string $key, int $perMinute): Limit => Limit::perMinute(max(1, $perMinute))->by($key);

        RateLimiter::for('auth-register', function (Request $request) use ($limit, $ipFingerprint, $emailFingerprint): array {
            $limits = [
                $limit(
                    'register:'.$ipFingerprint($request),
                    (int) config('security.rate_limits.register_ip_per_minute', 5),
                ),
            ];

            if (($identity = $emailFingerprint($request)) !== null) {
                $limits[] = $limit(
                    'register:'.$identity,
                    (int) config('security.rate_limits.register_identity_per_minute', 5),
                );
            }

            return $limits;
        });

        RateLimiter::for('auth-login', function (Request $request) use ($limit, $ipFingerprint, $emailFingerprint): array {
            $limits = [
                $limit(
                    'login:'.$ipFingerprint($request),
                    (int) config('security.rate_limits.login_ip_per_minute', 30),
                ),
            ];

            if (($identity = $emailFingerprint($request)) !== null) {
                $limits[] = $limit(
                    'login:'.$identity,
                    (int) config('security.rate_limits.login_identity_per_minute', 10),
                );
            }

            return $limits;
        });

        RateLimiter::for('auth-google', function (Request $request) use ($limit, $ipFingerprint, $deviceFingerprint): array {
            $limits = [
                $limit(
                    'google:'.$ipFingerprint($request),
                    (int) config('security.rate_limits.google_ip_per_minute', 10),
                ),
            ];

            if (($device = $deviceFingerprint($request)) !== null) {
                $limits[] = $limit(
                    'google:'.$device,
                    (int) config('security.rate_limits.device_ip_per_minute', 30),
                );
            }

            return $limits;
        });

        RateLimiter::for('auth-guest', function (Request $request) use ($limit, $ipFingerprint, $deviceFingerprint): array {
            $limits = [
                $limit(
                    'guest:'.$ipFingerprint($request),
                    (int) config('security.rate_limits.guest_ip_per_minute', 10),
                ),
            ];

            if (($device = $deviceFingerprint($request)) !== null) {
                $limits[] = $limit(
                    'guest:'.$device,
                    (int) config('security.rate_limits.device_ip_per_minute', 30),
                );
            }

            return $limits;
        });

        RateLimiter::for('auth-password-reset', function (Request $request) use ($limit, $ipFingerprint, $emailFingerprint): array {
            $limits = [
                $limit(
                    'password-reset:'.$ipFingerprint($request),
                    (int) config('security.rate_limits.password_reset_ip_per_minute', 5),
                ),
            ];

            if (($identity = $emailFingerprint($request)) !== null) {
                $limits[] = $limit(
                    'password-reset:'.$identity,
                    (int) config('security.rate_limits.password_reset_identity_per_minute', 3),
                );
            }

            return $limits;
        });

        RateLimiter::for('auth-device-management', function (Request $request) use ($limit, $ipFingerprint, $fingerprint): array {
            $perMinute = (int) config('security.rate_limits.device_management_per_minute', 30);
            $limits = [$limit('device-management:'.$ipFingerprint($request), $perMinute)];

            if ($request->user()) {
                $limits[] = $limit(
                    'device-management:'.$fingerprint('user', (string) $request->user()->getAuthIdentifier()),
                    $perMinute,
                );
            }

            return $limits;
        });

        RateLimiter::for('auth-email-verification', function (Request $request) use ($limit, $ipFingerprint, $emailFingerprint, $fingerprint): array {
            $limits = [
                $limit(
                    'email-verification:'.$ipFingerprint($request),
                    (int) config('security.rate_limits.email_verification_ip_per_minute', 5),
                ),
            ];

            $identity = $emailFingerprint($request);
            if ($identity === null) {
                $userId = $request->input('user_id') ?? $request->user()?->getAuthIdentifier();
                if ($userId !== null) {
                    $identity = $fingerprint('user', (string) $userId);
                }
            }

            if ($identity !== null) {
                $limits[] = $limit(
                    'email-verification:'.$identity,
                    (int) config('security.rate_limits.email_verification_identity_per_minute', 3),
                );
            }

            return $limits;
        });

        RateLimiter::for('auth-username-check', function (Request $request) use ($limit, $ipFingerprint, $fingerprint): array {
            $limits = [
                $limit(
                    'username-check:'.$ipFingerprint($request),
                    (int) config('security.rate_limits.username_check_ip_per_minute', 30),
                ),
            ];

            $username = Str::lower(trim((string) $request->query('username', '')));
            if ($username !== '') {
                $limits[] = $limit(
                    'username-check:'.$fingerprint('username', $username),
                    (int) config('security.rate_limits.username_check_ip_per_minute', 30),
                );
            }

            return $limits;
        });
    }
}
