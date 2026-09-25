<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AdminAndSupportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x'), 'is_admin' => true, 'is_active' => true, 'telegram_chat_id' => '900']);
        $this->user = User::create(['name' => 'Sara', 'email' => 'sara@example.com', 'password' => Hash::make('x'), 'is_active' => true, 'telegram_chat_id' => '777', 'telegram_username' => 'sara_tg', 'telegram_linked_at' => now()]);
        $this->other = User::create(['name' => 'Omid', 'email' => 'omid@example.com', 'password' => Hash::make('x'), 'is_active' => true]);
    }

    private function telegramTo(string $chat): array
    {
        return collect(Http::recorded())
            ->filter(fn ($p) => str_ends_with($p[0]->url(), 'sendMessage') && (string) ($p[0]->data()['chat_id'] ?? '') === $chat)
            ->map(fn ($p) => $p[0]->data()['text'])->values()->all();
    }

    public function test_admin_pages_are_admin_only(): void
    {
        foreach (['/admin', '/admin/users', '/admin/settings', '/admin/support', '/admin/access', '/admin/users/export'] as $url) {
            $this->actingAs($this->user)->get($url)->assertForbidden();
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
        $this->actingAs($this->user)->post('/admin/settings', ['app_name' => 'Hack'])->assertForbidden();
        $this->actingAs($this->user)->post('/admin/users/'.$this->other->id.'/toggle', ['field' => 'is_admin'])->assertForbidden();
        $this->assertFalse($this->other->fresh()->is_admin);
    }

    public function test_admin_sees_users_with_telegram_and_stats_but_no_planner_content(): void
    {
        Task::create(['user_id' => $this->user->id, 'title' => 'Sara private task']);
        $this->user->markSeen();

        $overview = $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('کل کاربران', $overview);

        $html = $this->actingAs($this->admin)->get('/admin/users')->assertOk()->getContent();
        $this->assertStringContainsString('Sara', $html);
        $this->assertStringContainsString('@sara_tg', $html);
        $this->assertStringContainsString('ID: 777', $html);
        $this->assertStringNotContainsString('Sara private task', $html);

        $filtered = $this->actingAs($this->admin)->get('/admin/users?filter=no_telegram')->getContent();
        $this->assertStringContainsString('Omid', $filtered);
        $this->assertStringNotContainsString('sara@example.com', $filtered);

        $csv = $this->actingAs($this->admin)->get('/admin/users/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('sara_tg', $csv);
        $this->assertStringContainsString('777', $csv);
    }

    public function test_admin_can_toggle_users_but_not_self(): void
    {
        $this->actingAs($this->admin)->post('/admin/users/'.$this->other->id.'/toggle', ['field' => 'is_active'])->assertRedirect();
        $this->assertFalse($this->other->fresh()->is_active);
        $this->actingAs($this->admin)->post('/admin/users/'.$this->admin->id.'/toggle', ['field' => 'is_admin'])->assertSessionHasErrors();
        $this->assertTrue($this->admin->fresh()->is_admin);
    }

    public function test_settings_logo_registration_and_announcement(): void
    {
        $png = UploadedFile::fake()->image('logo.png', 120, 40);
        $this->actingAs($this->admin)->post('/admin/settings', [
            'app_name' => 'HamanTech Planner', 'app_tagline' => 'Tagline X', 'announcement' => 'Maintenance tonight',
            'support_enabled' => '1', 'logo' => $png,
        ])->assertRedirect('/admin/settings');

        $this->assertSame('HamanTech Planner', AppSettings::get('app_name'));
        $this->assertStringStartsWith('data:image/png;base64,', (string) AppSettings::get('logo'));
        $this->assertFalse(AppSettings::bool('registration_enabled'));

        auth()->logout();
        $login = $this->get('/login')->getContent();
        $this->assertStringContainsString('HamanTech Planner', $login);
        $this->assertStringContainsString('data:image/png;base64,', $login);
        $this->assertStringNotContainsString('/register', $login);
        $this->get('/register')->assertNotFound();

        $dash = $this->actingAs($this->user)->get('/planner')->getContent();
        $this->assertStringContainsString('Maintenance tonight', $dash);
        $this->assertStringContainsString('/support', $dash);

        $svg = UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml');
        $this->actingAs($this->admin)->post('/admin/settings', ['app_name' => 'X', 'logo' => $svg])->assertSessionHasErrors('logo');
    }

    public function test_support_ticket_flow_and_isolation(): void
    {
        $this->actingAs($this->user)->post('/support', ['subject' => 'Cannot link bot', 'body' => 'Help please'])->assertRedirect();
        $ticket = SupportTicket::first();
        $this->assertSame($this->user->id, (int) $ticket->user_id);
        $this->assertSame('open', $ticket->status);
        $this->assertNotEmpty($this->telegramTo('900'), 'admin should be notified');

        $this->actingAs($this->other)->get('/support/'.$ticket->id)->assertNotFound();
        $this->actingAs($this->other)->post('/support/'.$ticket->id.'/reply', ['body' => 'x'])->assertNotFound();
        $this->assertStringNotContainsString('Cannot link bot', $this->actingAs($this->other)->get('/support')->getContent());

        $this->actingAs($this->admin)->post('/admin/support/'.$ticket->id.'/reply', ['body' => 'Fixed it'])->assertRedirect();
        $this->assertSame('answered', $ticket->fresh()->status);
        $this->assertTrue(collect($this->telegramTo('777'))->contains(fn ($t) => str_contains($t, 'Fixed it')));

        $page = $this->actingAs($this->user)->get('/support/'.$ticket->id)->assertOk()->getContent();
        $this->assertStringContainsString('Fixed it', $page);

        $this->actingAs($this->user)->post('/support/'.$ticket->id.'/close')->assertRedirect();
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->actingAs($this->user)->post('/support/'.$ticket->id.'/reply', ['body' => 'again'])->assertRedirect();
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_support_can_be_disabled_for_users(): void
    {
        AppSettings::put(['support_enabled' => false]);
        $this->actingAs($this->user)->get('/support')->assertNotFound();
        $this->actingAs($this->admin)->get('/support')->assertOk();
    }

    public function test_last_seen_is_tracked(): void
    {
        $this->assertNull($this->other->last_seen_at);
        $this->actingAs($this->other)->get('/planner')->assertOk();
        $this->assertNotNull($this->other->fresh()->last_seen_at);
    }
}
