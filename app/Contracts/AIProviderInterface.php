<?php
declare(strict_types=1);
namespace App\Contracts;
interface AIProviderInterface
{
    public function chat(array $messages, array $options = []): array;
    public function parseIntent(string $input, array $context = []): array;
}