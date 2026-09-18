<?php
declare(strict_types=1);
namespace App\Contracts;
interface SpeechProviderInterface
{
    public function transcribe(string $audioPath, array $options = []): array;
}