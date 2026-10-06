<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $group
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'description',
    ];

    /**
     * Get a setting by key with fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("system_setting:{$key}", now()->addHour(), function () use ($key, $default) {
            $record = static::query()->where('key', $key)->first();

            return $record?->value ?? $default;
        });
    }

    /**
     * Set/update a setting value and clear its cache.
     */
    public static function set(string $key, mixed $value, ?string $group = null, ?string $description = null): static
    {
        $attributes = ['value' => $value !== null ? (string) $value : null];
        if ($group !== null) {
            $attributes['group'] = $group;
        }
        if ($description !== null) {
            $attributes['description'] = $description;
        }

        $record = static::query()->updateOrCreate(['key' => $key], $attributes);
        Cache::forget("system_setting:{$key}");
        Cache::forget('system_settings:all_grouped');

        return $record;
    }

    /**
     * Get all settings grouped by group name.
     *
     * @return Collection<string, Collection<int, static>>
     */
    public static function getAllGrouped(): Collection
    {
        return Cache::remember('system_settings:all_grouped', now()->addHour(), function () {
            return static::query()->get()->groupBy('group');
        });
    }

    /**
     * Clear all settings cache.
     */
    public static function clearCache(): void
    {
        Cache::forget('system_settings:all_grouped');
        foreach (static::query()->pluck('key') as $key) {
            Cache::forget("system_setting:{$key}");
        }
    }
}
