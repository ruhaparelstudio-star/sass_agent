<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Provisions the system user that the wa-gateway sidecar uses to call back
 * into Laravel (POST /api/internal/whatsapp/accounts/upsert etc.).
 *
 * The internal/whatsapp routes are protected by `auth:sanctum` + `api.token`,
 * so the gateway must send a Bearer token. This seeder:
 *   - upserts a service user (no UI access — superadmin role keeps tenant-scope checks happy)
 *   - issues a fresh Sanctum personal access token
 *   - writes the plaintext token to .env at LARAVEL_INTERNAL_AUTH_TOKEN
 *
 * Re-running drops old tokens and rotates a new one.
 */
class WaGatewayTokenSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'wa-gateway@service.local';

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'WhatsApp Gateway Service',
                'password' => Hash::make(bin2hex(random_bytes(16))),
                'role' => UserRole::Superadmin,
                'email_verified_at' => now(),
            ],
        );

        // Rotate: drop existing tokens for this user, issue a new one.
        $user->tokens()->delete();
        $newToken = $user->createToken('wa-gateway-callback')->plainTextToken;

        $this->writeEnv('LARAVEL_INTERNAL_AUTH_TOKEN', $newToken);

        $this->command?->info("WA gateway service user: {$email}");
        $this->command?->info("Token written to .env (LARAVEL_INTERNAL_AUTH_TOKEN). Restart the wa-gateway container to pick it up.");
    }

    private function writeEnv(string $key, string $value): void
    {
        $envPath = base_path('.env');
        if (! is_file($envPath) || ! is_writable($envPath)) {
            $this->command?->warn(".env not writable; set {$key}={$value} manually.");
            return;
        }

        $contents = (string) file_get_contents($envPath);
        $line = $key.'='.$value;

        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        if (preg_match($pattern, $contents)) {
            $contents = preg_replace($pattern, $line, $contents);
        } else {
            $contents = rtrim($contents, "\n")."\n".$line."\n";
        }

        file_put_contents($envPath, $contents);
    }
}
