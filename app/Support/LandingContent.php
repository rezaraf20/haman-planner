<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable texts of the public pages (landing, pricing, privacy, terms).
 *
 * The built-in texts in resources/lang/{fa,en}/marketing.php and legal.php are the defaults;
 * the panel stores only the fields that differ from them (per language) and they are layered
 * on top of the translations at request time, so every view keeps using __('marketing.…').
 * Emptying a field in the panel returns it to the built-in default.
 */
final class LandingContent
{
    private const KEY = 'landing_content';
    private const CACHE_KEY = 'haman:landing_content:v1';

    /**
     * Editable fields grouped into panel sections.
     *  text     one line          textarea  paragraph
     *  lines    list of strings, one per line
     *  rows:N   list of tuples with N columns (features: icon, title, text; FAQ/legal: title, text)
     *
     * @return array<string,array<string,string>> section => [ "group.key" => type ]
     */
    public static function sections(): array
    {
        return [
            'seo' => ['marketing.meta_title' => 'text', 'marketing.meta_description' => 'textarea',
                'marketing.pricing_meta_title' => 'text', 'marketing.pricing_meta_description' => 'textarea'],
            'hero' => ['marketing.hero_title' => 'text', 'marketing.hero_text' => 'textarea', 'marketing.hero_note' => 'text',
                'marketing.cta_start' => 'text'],
            'value' => ['marketing.value_title' => 'text',
                'marketing.value_1_title' => 'text', 'marketing.value_1_text' => 'textarea',
                'marketing.value_2_title' => 'text', 'marketing.value_2_text' => 'textarea',
                'marketing.value_3_title' => 'text', 'marketing.value_3_text' => 'textarea'],
            'how' => ['marketing.how_title' => 'text',
                'marketing.how_1_title' => 'text', 'marketing.how_1_text' => 'textarea',
                'marketing.how_2_title' => 'text', 'marketing.how_2_text' => 'textarea',
                'marketing.how_3_title' => 'text', 'marketing.how_3_text' => 'textarea'],
            'features' => ['marketing.features_title' => 'text', 'marketing.features' => 'rows:3'],
            'ai' => ['marketing.ai_title' => 'text', 'marketing.ai_text' => 'textarea', 'marketing.ai_points' => 'lines'],
            'telegram' => ['marketing.telegram_title' => 'text', 'marketing.telegram_text' => 'textarea', 'marketing.telegram_points' => 'lines'],
            'benefits' => ['marketing.benefits_title' => 'text', 'marketing.benefits' => 'lines'],
            'pricing' => ['marketing.pricing_title' => 'text', 'marketing.pricing_text' => 'textarea'],
            'faq' => ['marketing.faq_title' => 'text', 'marketing.faq' => 'rows:2'],
            'cta' => ['marketing.cta_title' => 'text', 'marketing.cta_text' => 'text', 'marketing.footer_rights' => 'text'],
            'privacy' => ['legal.privacy_title' => 'text', 'legal.privacy_meta' => 'textarea', 'legal.privacy' => 'rows:2'],
            'terms' => ['legal.terms_title' => 'text', 'legal.terms_meta' => 'textarea', 'legal.terms' => 'rows:2'],
        ];
    }

    /** @return array<string,string> "group.key" => type */
    public static function fields(): array
    {
        return array_merge(...array_values(self::sections()));
    }

    /** Built-in value from the language file (ignores panel overrides). */
    public static function default(string $locale, string $field): mixed
    {
        static $files = [];
        [$group, $key] = explode('.', $field, 2);
        $files[$locale][$group] ??= (array) require resource_path("lang/$locale/$group.php");
        return $files[$locale][$group][$key] ?? null;
    }

    /** @return array{fa:array<string,mixed>,en:array<string,mixed>,hide_legal_notice:bool} */
    public static function all(): array
    {
        $data = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                $raw = AppSetting::query()->where('key', self::KEY)->value('value');
            } catch (\Throwable) {
                return [];
            }
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            return is_array($decoded) ? $decoded : [];
        });
        return [
            'fa' => (array) ($data['fa'] ?? []),
            'en' => (array) ($data['en'] ?? []),
            'hide_legal_notice' => (bool) ($data['hide_legal_notice'] ?? false),
        ];
    }

    /** Current value for the panel form: override if any, else the built-in text. */
    public static function value(string $locale, string $field): mixed
    {
        return self::all()[$locale][$field] ?? self::default($locale, $field);
    }

    public static function isCustom(string $locale, string $field): bool
    {
        return array_key_exists($field, self::all()[$locale]);
    }

    public static function hideLegalNotice(): bool
    {
        return self::all()['hide_legal_notice'];
    }

    /**
     * Layer the overrides on top of the loaded translations (both languages). Every editable field is
     * written (override or built-in text), so repeated calls in one process never leave stale values.
     */
    public static function apply(): void
    {
        $all = self::all();
        $translator = app('translator');
        foreach (['fa', 'en'] as $locale) {
            foreach (['marketing', 'legal'] as $group) {
                $translator->load('*', $group, $locale); // load the files first so other keys stay available
            }
            $lines = [];
            foreach (array_keys(self::fields()) as $field) {
                $lines[$field] = $all[$locale][$field] ?? self::default($locale, $field);
            }
            $translator->addLines($lines, $locale);
        }
    }

    /**
     * Normalise submitted panel input for one language and keep only what differs from the default.
     *
     * @param array<string,mixed> $input "group.key" (dots replaced by "__" in form names) => value
     */
    public static function save(string $locale, array $input, ?bool $hideLegalNotice = null): void
    {
        $all = self::all();
        $overrides = [];
        foreach (self::fields() as $field => $type) {
            $name = str_replace('.', '__', $field);
            if (!array_key_exists($name, $input)) {
                if (array_key_exists($field, $all[$locale])) {
                    $overrides[$field] = $all[$locale][$field]; // field not in this form: keep
                }
                continue;
            }
            $value = self::normalise($type, $input[$name]);
            if ($value === null || $value === '' || $value === [] || $value == self::default($locale, $field)) {
                continue; // empty or unchanged → built-in text
            }
            $overrides[$field] = $value;
        }
        $all[$locale] = $overrides;
        if ($hideLegalNotice !== null) {
            $all['hide_legal_notice'] = $hideLegalNotice;
        }
        self::store($all);
    }

    public static function reset(string $locale): void
    {
        $all = self::all();
        $all[$locale] = [];
        self::store($all);
    }

    private static function store(array $all): void
    {
        AppSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => json_encode($all, JSON_UNESCAPED_UNICODE)]);
        Cache::forget(self::CACHE_KEY);
    }

    private static function normalise(string $type, mixed $value): mixed
    {
        if ($type === 'text' || $type === 'textarea') {
            $v = trim(str_replace("\r\n", "\n", (string) $value));
            return $type === 'text' ? preg_replace('/\s+/u', ' ', $v) : $v;
        }
        if ($type === 'lines') {
            $lines = preg_split('/\R/u', (string) $value) ?: [];
            return array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
        }
        // rows:N
        $cols = (int) substr($type, 5);
        $rows = [];
        foreach ((array) $value as $row) {
            $cells = [];
            for ($i = 0; $i < $cols; $i++) {
                $cells[] = trim(str_replace("\r\n", "\n", (string) (is_array($row) ? ($row[$i] ?? '') : '')));
            }
            // A row needs its main text columns (the icon of a feature may be empty).
            $required = $cols === 3 ? [1, 2] : [0, 1];
            if (array_filter($required, fn ($i) => $cells[$i] === '') === []) {
                $rows[] = $cells;
            }
        }
        return $rows;
    }
}
