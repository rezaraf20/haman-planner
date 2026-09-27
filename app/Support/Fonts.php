<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;

/**
 * Web fonts, all self-hosted (no Google Fonts request — faster and reachable from Iran).
 *
 *  Persian: bundled Vazirmatn (OFL) or a font the admin uploads (e.g. a licensed IRANSans).
 *  Latin:   bundled Poppins (OFL) or the Persian font's own Latin glyphs ("system").
 *
 * Two internal families are combined with unicode-range, so every page simply uses
 * `font-family: var(--font)`: Latin characters come from the Latin font, Persian/Arabic
 * characters and Persian digits from the Persian font.
 */
final class Fonts
{
    public const FA_OPTIONS = ['vazirmatn', 'custom'];
    public const EN_OPTIONS = ['poppins', 'persian'];
    public const WEIGHTS = ['regular' => 400, 'bold' => 700];

    private const LATIN_RANGE = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-200B,U+2010-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
    private const PERSIAN_RANGE = 'U+0600-06FF,U+0750-077F,U+08A0-08FF,U+200C-200F,U+FB50-FDFF,U+FE70-FEFF';

    /** Uploaded font file (setting key font_file_{weight}) as [bytes, mime], or null. */
    public static function customFile(string $weight): ?array
    {
        if (!isset(self::WEIGHTS[$weight])) {
            return null;
        }
        try {
            $raw = AppSetting::query()->where('key', 'font_file_'.$weight)->value('value');
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($raw) || !str_contains($raw, ',')) {
            return null;
        }
        [$mime, $b64] = explode(',', $raw, 2);
        $bytes = base64_decode($b64, true);
        return $bytes === false ? null : [$bytes, $mime];
    }

    /** Short content hash per weight, used to bust browser caches after a new upload. */
    private static function versions(): array
    {
        $v = AppSettings::get('font_fa_version');
        return is_string($v) && $v !== '' ? (array) json_decode($v, true) : [];
    }

    public static function hasCustom(): bool
    {
        return isset(self::versions()['regular']);
    }

    /** @return string CSS: @font-face rules and the --font variable */
    public static function css(): string
    {
        $fa = AppSettings::get('font_fa') === 'custom' && self::hasCustom() ? 'custom' : 'vazirmatn';
        $en = AppSettings::get('font_en') === 'persian' ? 'persian' : 'poppins';
        $css = '';

        if ($fa === 'custom') {
            $versions = self::versions();
            foreach (self::WEIGHTS as $name => $weight) {
                if (!isset($versions[$name])) {
                    continue;
                }
                $url = route('fonts.custom', ['weight' => $name, 'v' => $versions[$name]]);
                $css .= "@font-face{font-family:'HP Persian';src:url('$url') format('".($versions[$name.'_format'] ?? 'woff2')."');font-weight:$weight;font-style:normal;font-display:swap}";
            }
        } else {
            $url = asset('fonts/vazirmatn/Vazirmatn-wght.woff2');
            $css .= "@font-face{font-family:'HP Persian';src:url('$url') format('woff2');font-weight:100 900;font-style:normal;font-display:swap}";
        }

        $stack = "'HP Persian',system-ui,-apple-system,'Segoe UI',Tahoma,sans-serif";
        if ($en === 'poppins') {
            foreach ([400, 500, 600, 700, 800] as $w) {
                $url = asset("fonts/poppins/poppins-latin-$w-normal.woff2");
                $css .= "@font-face{font-family:'HP Latin';src:url('$url') format('woff2');font-weight:$w;font-style:normal;font-display:swap;unicode-range:".self::LATIN_RANGE.'}';
            }
            // Keep Persian letters, ZWNJ and Persian digits out of the Latin face.
            $stack = "'HP Latin',".$stack;
        }
        return $css.':root{--font:'.$stack.'}button,input,select,textarea,optgroup{font-family:inherit}';
    }

    /** Store an uploaded woff/woff2 for one weight (null removes it). */
    public static function storeCustom(string $weight, ?string $bytes, string $format = 'woff2'): void
    {
        $versions = self::versions();
        if ($bytes === null) {
            AppSetting::query()->where('key', 'font_file_'.$weight)->delete();
            unset($versions[$weight], $versions[$weight.'_format']);
        } else {
            $mime = $format === 'woff' ? 'font/woff' : 'font/woff2';
            AppSetting::query()->updateOrCreate(['key' => 'font_file_'.$weight], ['value' => $mime.','.base64_encode($bytes)]);
            $versions[$weight] = substr(hash('sha256', $bytes), 0, 12);
            $versions[$weight.'_format'] = $format;
        }
        AppSettings::put(['font_fa_version' => $versions === [] ? '' : json_encode($versions)]);
    }

    public static function isFontFile(string $bytes): ?string
    {
        return match (substr($bytes, 0, 4)) {
            'wOF2' => 'woff2',
            'wOFF' => 'woff',
            default => null,
        };
    }
}
