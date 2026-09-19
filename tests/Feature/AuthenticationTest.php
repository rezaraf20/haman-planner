<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/planner')->assertRedirect('/login');
    }

    public function test_user_can_login_and_access_planner(): void
    {
        User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'correct-password',
        ])->assertRedirect('/planner');

        $this->get('/planner')->assertOk();
    }

    public function test_guest_cannot_read_planner_api(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();
    }

    public function test_authenticated_browser_can_read_planner_api_without_api_token(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->actingAs($user)->getJson('/api/tasks')->assertOk();
    }
}
