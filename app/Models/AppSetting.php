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
}
