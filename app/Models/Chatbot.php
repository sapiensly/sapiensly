<?php

namespace App\Models;

use App\Enums\ChatbotStatus;
use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chatbot extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility, SoftDeletes;
    use UsesPlatformConnection;

    protected $fillable = [
        'user_id',
        'organization_id',
        'visibility',
        'channel_id',
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

    public function botFlow(): HasOne
    {
        return $this->hasOne(BotFlow::class);
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

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Helper Methods
    public function isActive(): bool
    {
        return $this->status === ChatbotStatus::Active;
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

    /**
     * The widget appearance, with the organization Brandbook applied as a LIVE
     * fallback: any value the bot left at its built-in default adopts the current
     * org brand at serve time, so a brand change propagates to bots that never
     * customized that value. A bot's own customization always wins.
     *
     * @return array<string, mixed>
     */
    public function getAppearanceConfig(): array
    {
        $appearance = $this->config['appearance'] ?? self::getDefaultAppearance();

        $organization = $this->organization;
        if ($organization !== null) {
            $appearance = $organization->brandbook()->applyToChatbotAppearance($appearance, self::getDefaultAppearance());
        }

        return $appearance;
    }

    public function getBehaviorConfig(): array
    {
        return $this->config['behavior'] ?? $this->getDefaultBehavior();
    }

    public function getAdvancedConfig(): array
    {
        return $this->config['advanced'] ?? $this->getDefaultAdvanced();
    }

    /**
     * The default widget config SEEDED with an organization's Brandbook, used when
     * a new bot is created without an explicit config — so a fresh widget starts
     * on-brand. Org-less (personal) bots just get the plain defaults.
     *
     * @return array<string, mixed>
     */
    public static function defaultConfigForOrganization(?Organization $organization): array
    {
        $config = self::getDefaultConfig();

        if ($organization !== null) {
            $config['appearance'] = $organization->brandbook()->applyToChatbotAppearance(
                $config['appearance'],
                self::getDefaultAppearance(),
            );
        }

        return $config;
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
