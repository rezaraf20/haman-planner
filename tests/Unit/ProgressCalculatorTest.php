<?php
namespace Tests\Unit;
use App\Domain\Planner\ProgressCalculator;
use PHPUnit\Framework\TestCase;
class ProgressCalculatorTest extends TestCase {
 public function test_weighted_progress_is_calculated(): void {
  $this->assertSame(62.5,(new ProgressCalculator)->calculate([
   ['progress'=>100,'weight'=>1],['progress'=>25,'weight'=>1],
  ]));
 }
 public function test_zero_weights_return_zero(): void {
  $this->assertSame(0.0,(new ProgressCalculator)->calculate([
   ['progress'=>100,'weight'=>0],
  ]));
 }
}