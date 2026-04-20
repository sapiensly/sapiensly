<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::remember("app_setting.{$key}", 60, function () use ($key, $default) {
            $setting = static::find($key);

            return $setting ? $setting->value : $default;
        });
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("app_setting.{$key}");
    }

    public static function isRegistrationEnabled(): bool
    {
        try {
            return self::getValue('registration_enabled', 'true') === 'true';
        } catch (\Throwable) {
            return true;
        }
    }

    // ─── admin-v2 access settings ─────────────────────────────────────────
    //
    // All keys under `access.*` are managed by the Access screen. Boolean
    // values are stored as literal 'true'/'false' strings; arrays are stored
    // as JSON. `session_lifetime_minutes` is an integer in string form.
    // ──────────────────────────────────────────────────────────────────────

    public static function getBool(string $key, bool $default = false): bool
    {
        $raw = self::getValue($key, $default ? 'true' : 'false');

        return $raw === 'true' || $raw === true || $raw === '1' || $raw === 1;
    }

    public static function setBool(string $key, bool $value): void
    {
        self::setValue($key, $value ? 'true' : 'false');
    }

    /**
     * @return array<int, string>
     */
    public static function getStringList(string $key): array
    {
        $raw = self::getValue($key, '[]');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded)
            ? array_values(array_filter($decoded, 'is_string'))
            : [];
    }

    /**
     * @param  array<int, string>  $values
     */
    public static function setStringList(string $key, array $values): void
    {
        // De-duplicate case-insensitively and preserve insertion order.
        $seen = [];
        $clean = [];
        foreach ($values as $v) {
            if (! is_string($v)) {
                continue;
            }
            $normalized = strtolower(trim($v));
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $clean[] = trim($v);
        }
        self::setValue($key, json_encode(array_values($clean), JSON_UNESCAPED_UNICODE));
    }

    public static function getInt(string $key, int $default): int
    {
        $raw = self::getValue($key, (string) $default);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    public static function getNullableInt(string $key): ?int
    {
        $raw = self::getValue($key, null);
        if ($raw === null || $raw === '' || $raw === 'null') {
            return null;
        }

        return is_numeric($raw) ? (int) $raw : null;
    }
}
