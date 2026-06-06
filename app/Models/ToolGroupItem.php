<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolGroupItem extends Model
{
    use UsesPlatformConnection;

    protected $fillable = [
        'tool_group_id',
        'tool_id',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Tool::class, 'tool_group_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }
}
