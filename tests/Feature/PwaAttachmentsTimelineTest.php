<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\Attachment;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PwaAttachmentsTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments');
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => Hash::make('secret123'), 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'en']);
        $this->other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => Hash::make('secret123'), 'is_active' => true, 'onboarded_at' => now()]);
    }

    private function h(User $u): array
    {
        $plain = 'pw-'.$u->id.str_repeat('w', 40);
        ApiToken::firstOrCreate(['token_hash' => hash('sha256', $plain)], ['user_id' => $u->id, 'name' => 't', 'token_prefix' => substr($plain, 0, 12)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    private function pdf(string $name = 'report.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    // ---------------------------------------------------------------- PWA

    public function test_pwa_is_installable_and_never_caches_private_data(): void
    {
        $m = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();
        $this->assertSame('standalone', $m['display']);
        $this->assertStringStartsWith('/planner', $m['start_url']);
        $this->assertContains('512x512', array_column($m['icons'], 'sizes'));
        $this->assertContains('maskable', array_column($m['icons'], 'purpose'));
        foreach ($m['icons'] as $icon) {
            $this->assertFileExists(public_path(parse_url($icon['src'], PHP_URL_PATH)));
        }
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("'/offline'", $sw);
        $this->assertStringNotContainsString("'/api", $sw, 'API responses are never pre-cached');
        $this->assertStringContainsString("req.method !== 'GET'", $sw);

        $this->get('/offline')->assertOk()->assertSee(__('pwa.offline_title', [], 'en'))->assertSee(__('pwa.offline_text', [], 'fa'));
        $html = $this->actingAs($this->user)->get('/planner')->assertOk()->getContent();
        $this->assertStringContainsString('manifest.webmanifest', $html);
        $this->assertStringContainsString("serviceWorker.register('/sw.js')", $html);
        $this->post('/logout')->assertRedirect()->assertHeader('Clear-Site-Data', '"cache"');
    }

    // ---------------------------------------------------------------- attachments

    public function test_attachments_upload_list_download_delete(): void
    {
        $task = Task::create(['user_id' => $this->user->id, 'title' => 'Contract']);
        $h = $this->h($this->user);
        $a = $this->withHeaders($h)->post('/api/task/'.$task->id.'/attachments', ['file' => $this->pdf('قرارداد نهایی.pdf')])->assertCreated()->json();
        $this->assertSame('application/pdf', $a['mime']);
        $this->assertSame('قرارداد نهایی.pdf', $a['original_name']);
        $this->assertArrayNotHasKey('path', $a);

        $stored = Attachment::withoutGlobalScopes()->find($a['id']);
        $this->assertStringNotContainsString('.pdf', $stored->path, 'stored under a random name without extension');
        Storage::disk('attachments')->assertExists($stored->path);

        $this->withHeaders($h)->getJson('/api/task/'.$task->id.'/attachments')->assertOk()->assertJsonCount(1, 'data');
        $dl = $this->withHeaders($h)->get('/api/attachments/'.$a['id'].'/download')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment;', $dl->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $dl->streamedContent());
        $this->assertTrue(ActivityLog::withoutGlobalScopes()->where('action', 'attachment_added')->exists());

        $this->withHeaders($h)->deleteJson('/api/attachments/'.$a['id'])->assertNoContent();
        Storage::disk('attachments')->assertMissing($stored->path);
    }

    public function test_file_type_is_sniffed_and_limits_apply(): void
    {
        $task = Task::create(['user_id' => $this->user->id, 'title' => 'T']);
        $h = $this->h($this->user);
        $post = fn ($file) => $this->withHeaders($h)->post('/api/task/'.$task->id.'/attachments', ['file' => $file], ['Accept' => 'application/json']);

        $post(UploadedFile::fake()->createWithContent('invoice.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\0", 64)."PE\0\0"))->assertStatus(422);
        $post(UploadedFile::fake()->createWithContent('photo.png', '<?php system($_GET["c"]); ?>'))->assertStatus(422);
        $post(UploadedFile::fake()->createWithContent('run.sh', "#!/bin/sh\nrm -rf /\n"))->assertStatus(422);
        $post(UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'))->assertStatus(422);
        config(['planner.attachments.max_kb' => 1]);
        $post(UploadedFile::fake()->createWithContent('big.txt', str_repeat('a', 5000)))->assertStatus(422);
        config(['planner.attachments.max_kb' => 10240]);
        $post(UploadedFile::fake()->createWithContent('notes.txt', 'hello'))->assertCreated();

        $this->seed(PlanSeeder::class);
        $free = Plan::where('code', 'free')->first();
        $free->update(['limits' => array_merge($free->limits, ['attachment_storage_mb' => 0])]);
        $post($this->pdf())->assertStatus(402)->assertJsonPath('metric', 'attachment_storage_mb');
        $free->update(['limits' => array_merge($free->limits, ['attachment_storage_mb' => 100]), 'features' => array_merge($free->features, ['attachments' => false])]);
        $post($this->pdf())->assertStatus(402)->assertJsonPath('metric', 'feature_attachments');
    }

    public function test_attachments_are_private_and_removed_with_their_owner(): void
    {
        $mine = Task::create(['user_id' => $this->user->id, 'title' => 'Mine']);
        $theirs = Task::create(['user_id' => $this->other->id, 'title' => 'Theirs']);
        $theirProject = Project::create(['user_id' => $this->other->id, 'title' => 'P']);
        $a = $this->withHeaders($this->h($this->other))->post('/api/task/'.$theirs->id.'/attachments', ['file' => $this->pdf()])->json();
        $h = $this->h($this->user);

        $this->withHeaders($h)->getJson('/api/task/'.$theirs->id.'/attachments')->assertNotFound();
        $this->withHeaders($h)->post('/api/task/'.$theirs->id.'/attachments', ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertNotFound();
        $this->withHeaders($h)->post('/api/project/'.$theirProject->id.'/attachments', ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertNotFound();
        $this->withHeaders($h)->get('/api/attachments/'.$a['id'].'/download')->assertNotFound();
        $this->withHeaders($h)->deleteJson('/api/attachments/'.$a['id'])->assertNotFound();
        $this->withHeaders($h)->getJson('/api/unknown/'.$mine->id.'/attachments')->assertNotFound();
        $this->assertSame(1, Attachment::withoutGlobalScopes()->count());

        // Deleting the task removes its files; deleting the account removes the rest.
        $path = Attachment::withoutGlobalScopes()->find($a['id'])->path;
        $this->withHeaders($this->h($this->other))->deleteJson('/api/tasks/'.$theirs->id)->assertNoContent();
        Storage::disk('attachments')->assertMissing($path);
        $this->assertSame(0, Attachment::withoutGlobalScopes()->count());

        $x = $this->withHeaders($h)->post('/api/task/'.$mine->id.'/attachments', ['file' => $this->pdf()])->json();
        $xPath = Attachment::withoutGlobalScopes()->find($x['id'])->path;
        $this->actingAs($this->user)->post('/settings/delete', ['current_password' => 'secret123', 'confirm' => 'DELETE'])->assertRedirect(route('login'));
        $this->assertNull(User::find($this->user->id));
        Storage::disk('attachments')->assertMissing($xPath);
    }

    // ---------------------------------------------------------------- activity timeline

    public function test_timeline_shows_own_planner_activity_with_actor(): void
    {
        $h = $this->h($this->user);
        $task = $this->withHeaders($h)->postJson('/api/tasks', ['title' => 'Write spec'])->json();
        $this->withHeaders($h)->putJson('/api/tasks/'.$task['id'], ['status' => 'completed']);
        ActivityLog::create(['user_id' => $this->user->id, 'actor_type' => 'ai', 'channel' => 'web', 'action' => 'rescheduled', 'entity_type' => Task::class, 'entity_id' => (string) $task['id'],
            'after_json' => ['planned_start' => now()->toIso8601String()], 'created_at' => now()]);
        ActivityLog::create(['user_id' => $this->user->id, 'actor_type' => 'system', 'action' => 'plan_changed', 'entity_type' => User::class, 'entity_id' => (string) $this->user->id, 'created_at' => now()]);
        ActivityLog::create(['user_id' => $this->other->id, 'actor_type' => 'user', 'action' => 'created', 'entity_type' => Task::class, 'entity_id' => '999', 'after_json' => ['title' => 'Other secret'], 'created_at' => now()]);

        $r = $this->withHeaders($h)->getJson('/api/activity/timeline')->assertOk()->json();
        $this->assertSame('Today', $r['groups'][0]['label']);
        $items = collect($r['groups'][0]['items']);
        $this->assertStringContainsString('Write spec', $items->pluck('text')->implode(' '));
        $this->assertContains('completed', $items->pluck('action')->all());
        $this->assertSame('ai', $items->firstWhere('action', 'rescheduled')['actor']);
        $this->assertSame('user', $items->firstWhere('action', 'created')['actor']);
        $this->assertSame('api', $items->firstWhere('action', 'created')['channel']);
        $this->assertNotContains('plan_changed', $items->pluck('action')->all(), 'internal events are hidden');
        $this->assertStringNotContainsString('Other secret', json_encode($r));

        $ai = $this->withHeaders($h)->getJson('/api/activity/timeline?actor=ai')->json();
        $this->assertSame(['ai'], collect($ai['groups'])->flatMap(fn ($g) => array_column($g['items'], 'actor'))->unique()->values()->all());
    }

    public function test_heavy_api_use_does_not_lock_out_account_actions(): void
    {
        $h = $this->h($this->user);
        for ($i = 0; $i < 8; $i++) {
            $this->withHeaders($h)->getJson('/api/tasks')->assertOk();
        }
        $this->actingAs($this->user)->get('/settings/export')->assertOk();
    }
}
