<?php

namespace App\Models;

use App\Enums\ChatbotStatus;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chatbot extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility, SoftDeletes;

    protected $fillable = [
        'user_id',
        'organization_id',
        'visibility',
        'agent_id',
        'agent_team_id',
        'name',
        'description',
        'status',
        'config',
        'allowed_origins',
    ];

    protected function casts(): array
    {
        return [
            'status' => ChatbotStatus::class,
            'visibility' => Visibility::class,
            'config' => 'array',
            'allowed_origins' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'chatbot';
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function agentTeam(): BelongsTo
    {
        return $this->belongsTo(AgentTeam::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ChatbotApiToken::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WidgetSession::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(WidgetConversation::class);
    }

    public function analytics(): HasMany
    {
        return $this->hasMany(ChatbotAnalytics::class);
    }

    // Helper Methods
    public function isActive(): bool
    {
        return $this->status === ChatbotStatus::Active;
    }

    public function getTarget(): Agent|AgentTeam|null
    {
        return $this->agent ?? $this->agentTeam;
    }

    public function isOriginAllowed(string $origin): bool
    {
        if (empty($this->allowed_origins)) {
            return true;
        }

        $parsedOrigin = parse_url($origin, PHP_URL_HOST) ?? $origin;

        foreach ($this->allowed_origins as $allowed) {
            $parsedAllowed = parse_url($allowed, PHP_URL_HOST) ?? $allowed;
            if ($parsedOrigin === $parsedAllowed) {
                return true;
            }
        }

        return false;
    }

    public function getAppearanceConfig(): array
    {
        return $this->config['appearance'] ?? $this->getDefaultAppearance();
    }

    public function getBehaviorConfig(): array
    {
        return $this->config['behavior'] ?? $this->getDefaultBehavior();
    }

    public function getAdvancedConfig(): array
    {
        return $this->config['advanced'] ?? $this->getDefaultAdvanced();
    }

    public static function getDefaultConfig(): array
    {
        return [
            'appearance' => self::getDefaultAppearance(),
            'behavior' => self::getDefaultBehavior(),
            'advanced' => self::getDefaultAdvanced(),
        ];
    }

    private static function getDefaultAppearance(): array
    {
        return [
            'primary_color' => '#3B82F6',
            'background_color' => '#FFFFFF',
            'text_color' => '#1F2937',
            'logo_url' => null,
            'position' => 'bottom-right',
            'welcome_message' => 'Hello! How can I help you today?',
            'placeholder_text' => 'Type your message...',
            'widget_title' => 'Support',
        ];
    }

    private static function getDefaultBehavior(): array
    {
        return [
            'auto_open_delay' => 0,
            'require_visitor_info' => false,
            'collect_email' => true,
            'collect_name' => true,
            'show_powered_by' => true,
        ];
    }

    private static function getDefaultAdvanced(): array
    {
        return [
            'custom_css' => null,
            'custom_font_family' => null,
        ];
    }
}
