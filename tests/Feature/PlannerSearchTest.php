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
  Task::create(['title'=>'Prepare Haman Planner','status'=>'inbox','importance'=>90,'weight'=>1]);
  Task::create(['title'=>'Unrelated work','status'=>'inbox','importance'=>20,'weight'=>1]);
  $response=$this->withHeader('Authorization','Bearer '.(string)env('APP_API_TOKEN',''));
  if(env('APP_API_TOKEN','')==='') $response=$this->getJson('/api/search?q=Haman'); else $response=$response->getJson('/api/search?q=Haman');
  $response->assertOk()->assertJsonPath('tasks.0.title','Prepare Haman Planner');
 }
}
