<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\AccountInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthModuleUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_policy_requires_strong_passwords()
    {
        $rule = ['password' => ['required', Password::min(8)->letters()->numbers()->symbols()]];

        // Débil: Sin símbolos ni números
        $validator = Validator::make(['password' => 'password'], $rule);
        $this->assertTrue($validator->fails());

        // Fuerte
        $validator = Validator::make(['password' => 'StrongPass123!'], $rule);
        $this->assertFalse($validator->fails());
    }

    public function test_email_normalization()
    {
        $inputEmail = ' UsEr.NaME@GMAIL.COM ';
        $normalized = strtolower(trim($inputEmail));
        
        $this->assertEquals('user.name@gmail.com', $normalized);
    }

    public function test_totp_generation_and_validation()
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        
        $code = $google2fa->getCurrentOtp($secret);
        $isValid = $google2fa->verifyKey($secret, $code);

        $this->assertTrue($isValid);
    }

    public function test_recovery_codes_hashing()
    {
        $rawCode = strtolower(Str::random(4) . '-' . Str::random(4));
        $hash = hash('sha256', $rawCode);

        $this->assertNotEquals($rawCode, $hash);
        $this->assertEquals(64, strlen($hash)); // SHA-256 es 64 chars hex
    }

    public function test_account_invitation_states_and_validity()
    {
        $user = User::factory()->create();
        $invitation = AccountInvitation::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'test-token'),
            'state' => 'ACTIVE',
            'expires_at' => now()->addDay(),
            'created_by_user_id' => $user->id,
            'attempt_count' => 0
        ]);

        $this->assertTrue($invitation->isValid());

        $invitation->state = 'CONSUMED';
        $this->assertFalse($invitation->isValid());

        $invitation->state = 'ACTIVE';
        $invitation->expires_at = now()->subDay();
        $this->assertFalse($invitation->isValid());
    }

    public function test_user_states()
    {
        $user = User::factory()->create(['state' => 'PENDING_ACTIVATION']);
        $this->assertEquals('PENDING_ACTIVATION', $user->state);

        $user->state = 'BLOCKED';
        $user->save();
        $this->assertEquals('BLOCKED', $user->fresh()->state);
    }
}
