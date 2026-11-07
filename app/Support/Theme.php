<?php
declare(strict_types=1);

namespace App\Support;

/** Light / dark / system appearance. Stored per user; guests follow their device. */
final class Theme
{
    public const MODES = ['system', 'light', 'dark'];

    public static function current(): string
    {
        $user = auth()->user();
        $mode = $user ? (string) $user->preference('theme') : 'system';
        return in_array($mode, self::MODES, true) ? $mode : 'system';
    }
}
