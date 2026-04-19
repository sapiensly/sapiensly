<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    use HasFactory, HasPrefixedUlid;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'whatsapp_connection_id',
        'name',
        'language',
        'category',
        'components',
        'status',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'wtpl';
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }
}
