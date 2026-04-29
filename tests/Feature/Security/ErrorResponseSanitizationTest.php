<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ErrorResponseSanitizationTest extends TestCase
{
    public function test_sensitive_fields_are_redacted_in_json_error_responses(): void
    {
        Route::post('/__test/security/error-response', function (Request $request) {
            throw ValidationException::withMessages([
                'token' => (string) $request->input('token'),
                'password' => (string) $request->input('password'),
                'x-internal-secret' => (string) $request->header('X-Internal-Secret', ''),
                'safe' => 'value',
            ]);
        });

        $response = $this->withHeader('X-Internal-Secret', 'wa-secret-value')->postJson('/__test/security/error-response', [
            'token' => 'plain-token-value',
            'password' => 'plain-password-value',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'errors' => [
                    'token' => '[REDACTED]',
                    'password' => '[REDACTED]',
                    'x-internal-secret' => '[REDACTED]',
                    'safe' => ['value'],
                ],
            ]);

        $this->assertStringNotContainsString('plain-token-value', $response->getContent());
        $this->assertStringNotContainsString('plain-password-value', $response->getContent());
        $this->assertStringNotContainsString('wa-secret-value', $response->getContent());
    }
}
