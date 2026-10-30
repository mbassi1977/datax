<?php

namespace App\Support\Mfa;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class MfaService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_SECONDS = 300;

    public function issuer(User $user): string
    {
        return app(\App\Support\Settings\SettingsService::class)->effective($user->tenant_id, $user->organization_id)['brand.name'] ?? 'HRX';
    }

    public function isEnabled(User $user): bool
    {
        return $user->mfa_enabled_at !== null && $user->mfa_secret !== null;
    }

    /** Inicia (o reinicia) el alta: genera un secreto pendiente hasta que se confirme con un código válido. */
    public function begin(User $user): array
    {
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => Crypt::encryptString($secret), 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();

        return ['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, $user->email, $this->issuer($user))];
    }

    /** Confirma el alta con el primer código; devuelve los códigos de recuperación (se muestran una sola vez). */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->mfa_secret === null || $this->isEnabled($user)) {
            return null;
        }
        $step = Totp::verify(Crypt::decryptString($user->mfa_secret), $code);
        if ($step === null) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['mfa_enabled_at' => now(), 'mfa_last_step' => $step, 'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();

        return $codes;
    }

    /** Verifica un código TOTP o de recuperación. Los TOTP no se pueden reutilizar; los de recuperación se gastan. */
    public function check(User $user, ?string $code, ?string $recovery = null): bool
    {
        if (! $this->isEnabled($user)) {
            return false;
        }
        if ($code) {
            $step = Totp::verify(Crypt::decryptString($user->mfa_secret), $code);
            if ($step !== null && ($user->mfa_last_step === null || $step > $user->mfa_last_step)) {
                $user->forceFill(['mfa_last_step' => $step])->save();

                return true;
            }
        }
        if ($recovery) {
            $recovery = strtolower(trim($recovery));
            $codes = $user->mfa_recovery_codes ?? [];
            foreach ($codes as $i => $hash) {
                if (Hash::check($recovery, $hash)) {
                    unset($codes[$i]);
                    $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

                    return true;
                }
            }
        }

        return false;
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();
    }

    /** Token de corta vida entre el paso de contraseña y el de código (5 min). */
    public function challengeToken(User $user): string
    {
        return Crypt::encryptString(json_encode(['uid' => $user->id, 'exp' => now()->getTimestamp() + 300]));
    }

    public function userFromChallenge(string $token): ?User
    {
        try {
            $p = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($p) && ($p['exp'] ?? 0) >= now()->getTimestamp() ? User::find($p['uid'] ?? null) : null;
    }

    public function tooManyAttempts(User $user): bool
    {
        return RateLimiter::tooManyAttempts("mfa:{$user->id}", self::MAX_ATTEMPTS);
    }

    public function hit(User $user): void
    {
        RateLimiter::hit("mfa:{$user->id}", self::LOCK_SECONDS);
    }

    public function clear(User $user): void
    {
        RateLimiter::clear("mfa:{$user->id}");
    }

    private function newRecoveryCodes(): array
    {
        return collect(range(1, 10))->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))->all();
    }
}
