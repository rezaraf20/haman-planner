<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlannerSearchTest extends TestCase
{
 use RefreshDatabase;
 public function test_task_search_returns_matching_task(): void
 {
  config(['services.haman_planner.api_token'=>'testing-token']);
  $owner=\App\Models\User::create(['name'=>'Owner','email'=>'owner@example.com','password'=>'secret-pass-123','is_admin'=>true,'is_active'=>true]);
  Task::create(['user_id'=>$owner->id,'title'=>'Prepare Haman Planner','status'=>'inbox','importance'=>90,'weight'=>1]);
  Task::create(['user_id'=>$owner->id,'title'=>'Unrelated work','status'=>'inbox','importance'=>20,'weight'=>1]);
  $response=$this->withHeader('Authorization','Bearer testing-token')->getJson('/api/search?q=Haman');
  $response->assertOk()->assertJsonPath('tasks.0.title','Prepare Haman Planner');
 }
}
