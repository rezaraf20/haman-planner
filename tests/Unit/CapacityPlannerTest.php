<?php
namespace Tests\Unit;
use App\Domain\Planner\CapacityPlanner;
use PHPUnit\Framework\TestCase;
class CapacityPlannerTest extends TestCase {
 public function test_default_twenty_percent_buffer_is_reserved(): void {
  $this->assertSame(400,(new CapacityPlanner)->usableMinutes(500));
 }
 public function test_existing_commitments_reduce_capacity(): void {
  $this->assertSame(240,(new CapacityPlanner)->usableMinutes(500,200));
 }
}