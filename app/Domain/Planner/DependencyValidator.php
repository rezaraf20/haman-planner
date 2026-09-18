<?php
declare(strict_types=1);
namespace App\Domain\Planner;

final class DependencyValidator
{
    public function canStart(string $status, array $dependencyStatuses): bool
    {
        if (in_array($status, ['completed','cancelled'], true)) return false;
        return !array_filter($dependencyStatuses, fn(string $s) => $s !== 'completed');
    }
}