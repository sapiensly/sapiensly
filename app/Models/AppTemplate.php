<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A starter app an organization saved from one of its own.
 *
 * The whole package is stored, not a reference to the app it came from: a
 * template must keep working after that app is edited beyond recognition or
 * deleted outright. It is a snapshot by design, which is also why
 * `source_app_id` is provenance only and never a foreign key.
 */
class AppTemplate extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'icon',
        'kind',
        'source_app_id',
        'package',
    ];

    protected function casts(): array
    {
        return [
            'package' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'tpl';
    }

    /**
     * The shape the picker renders — deliberately the same as a built-in
     * template's, so the UI shows one list and not two.
     *
     * @return array<string, mixed>
     */
    public function toCatalogEntry(): array
    {
        $manifest = $this->package['manifest'] ?? [];

        return [
            'slug' => $this->id,
            'name' => $this->name,
            'description' => (string) ($this->description ?? ''),
            'icon' => $this->icon,
            'kind' => $this->kind,
            'objects' => count($manifest['objects'] ?? []),
            'pages' => count($manifest['pages'] ?? []),
            'source' => 'organization',
            'records' => is_array($this->package['records'] ?? null),
        ];
    }
}
