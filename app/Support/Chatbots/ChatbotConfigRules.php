<?php

namespace App\Support\Chatbots;

/**
 * Single source of truth for the widget config + allowed-origins validation
 * rules of a Chatbot. Shared by the web Store/Update form requests and the MCP
 * create_chatbot/update_chatbot tools so both accept identical shapes.
 */
class ChatbotConfigRules
{
    /**
     * The shared `config.*` and `allowed_origins` rules.
     *
     * @return array<string, mixed>
     */
    public static function widgetConfig(): array
    {
        return [
            'config' => ['nullable', 'array'],
            'config.appearance' => ['nullable', 'array'],
            'config.appearance.primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'config.appearance.background_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'config.appearance.text_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'config.appearance.logo_url' => ['nullable', 'url', 'max:2048'],
            'config.appearance.position' => ['nullable', 'string', 'in:bottom-right,bottom-left'],
            'config.appearance.welcome_message' => ['nullable', 'string', 'max:500'],
            'config.appearance.placeholder_text' => ['nullable', 'string', 'max:100'],
            'config.appearance.widget_title' => ['nullable', 'string', 'max:50'],

            'config.behavior' => ['nullable', 'array'],
            'config.behavior.auto_open_delay' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'config.behavior.require_visitor_info' => ['nullable', 'boolean'],
            'config.behavior.collect_email' => ['nullable', 'boolean'],
            'config.behavior.collect_name' => ['nullable', 'boolean'],
            'config.behavior.show_powered_by' => ['nullable', 'boolean'],

            'config.advanced' => ['nullable', 'array'],
            'config.advanced.custom_css' => ['nullable', 'string', 'max:10000'],
            'config.advanced.custom_font_family' => ['nullable', 'string', 'max:100'],

            'allowed_origins' => ['nullable', 'array', 'max:20'],
            'allowed_origins.*' => ['string', 'url', 'max:255'],
        ];
    }
}
