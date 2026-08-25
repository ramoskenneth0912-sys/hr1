<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Legacy auth bridge coverage.
 * Uses the real hr1_database for negative paths (guard returns null on
 * missing credentials). Uses an ephemeral SQLite table for the positive
 * session-cookie path so we never touch the production users table.
 */
class LegacyAuthBridgeTest extends TestCase
{
    // ---- Negative paths (guard against hr1_database, no writes) ----

    public function test_me_without_credentials_returns_legacy_401_envelope(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Authentication required.',
        ]);
        $this->assertArrayHasKey('errors', $response->json());
    }

    public function test_me_with_garbage_bearer_token_is_hard_401_without_session_fallback(): void
    {
        $response = $this->getJson('/api/auth/me', [
            'Authorization' => 'Bearer '.str_repeat('ab', 32),
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
    }

    public function test_me_with_malformed_authorization_header_is_401(): void
    {
        $response = $this->getJson('/api/auth/me', [
            'Authorization' => 'Basic Zm9vOmJhcg==',
        ]);

        $response->assertStatus(401);
    }

    // ---- Positive path: session cookie (ephemeral test DB) ----

    public function test_me_with_valid_session_cookie_returns_user(): void
    {
        // Arrange: create a temporary users table in the test DB (SQLite :memory:)
        // so the guard's User query can resolve the session's user_id.
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('username');
            $table->string('email')->unique();
            $table->string('password_hash')->default('');
            $table->string('role')->default('employee');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('users')->insert([
            'id' => 999,
            'username' => 'bridge_tester',
            'email' => 'bridge_test@example.com',
            'password_hash' => '',
            'role' => 'employee',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Arrange: create a legacy session file
        $tmpDir = (string) config('legacy.session_path');
        $this->assertNotEmpty($tmpDir, 'LEGACY_SESSION_PATH must be configured');

        $sid = 'phpunit-bridge-'.bin2hex(random_bytes(8));
        $file = rtrim($tmpDir, '/\\').DIRECTORY_SEPARATOR.'sess_'.$sid;
        $content = 'user_id|i:999;user_role|s:8:"employee";user_name|s:13:"bridge_tester";';
        file_put_contents($file, $content);
        $this->assertFileExists($file);

        try {
            $response = $this->call(
                'GET',
                '/api/auth/me',
                [],
                ['PHPSESSID' => $sid],
                [],
                ['Accept' => 'application/json']
            );

            $response->assertStatus(200);
            $response->assertJsonPath('success', true);
            $response->assertJsonPath('data.id', 999);
            $response->assertJsonPath('data.role', 'employee');
            $response->assertJsonPath('data.username', 'bridge_tester');
        } finally {
            @unlink($file);
            Schema::dropIfExists('users');
        }
    }

    // ---- Health endpoint (unchanged) ----

    public function test_health_still_public(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
    }
}
