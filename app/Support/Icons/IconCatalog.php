<?php

namespace App\Support\Icons;

/**
 * The canonical list of named icons the app builder may use anywhere a block
 * accepts an `icon` (buttons, feature cards, stats, nav, table actions, …). It
 * is the AI-facing half of the icon system: the runtime renderer mirrors the
 * SAME set in resources/js/runtime/icons.ts, and IconCatalogTest enforces the two
 * never drift. A manifest may also use any emoji as an `icon`.
 */
final class IconCatalog
{
    /**
     * Kebab-case icon names (rendered as Lucide icons in the runtime).
     *
     * @var list<string>
     */
    public const NAMES = [
        'activity', 'alarm-clock', 'alert-circle', 'alert-triangle', 'archive',
        'arrow-down', 'arrow-left', 'arrow-right', 'arrow-up', 'award',
        'badge-check', 'bar-chart', 'battery', 'bell', 'bike', 'bookmark', 'box',
        'boxes', 'briefcase', 'building', 'calculator', 'calendar', 'calendar-days',
        'camera', 'car', 'check', 'check-circle', 'chevron-down', 'chevron-right',
        'clipboard', 'clock', 'cloud', 'code', 'cog', 'coins', 'compass', 'contact',
        'credit-card', 'database', 'dollar', 'download', 'eye', 'eye-off', 'factory',
        'file', 'file-text', 'filter', 'flag', 'flame', 'folder', 'gauge', 'gift',
        'globe', 'graduation', 'grid', 'hammer', 'hand-coins', 'headphones', 'heart',
        'heart-pulse', 'help', 'home', 'image', 'inbox', 'info', 'key', 'landmark',
        'layers', 'dashboard', 'leaf', 'lightbulb', 'line-chart', 'link', 'list',
        'lock', 'mail', 'map', 'map-pin', 'message-circle', 'message', 'mic', 'minus',
        'monitor', 'moon', 'more', 'navigation', 'package', 'palette', 'paperclip',
        'pencil', 'edit', 'percent', 'phone', 'pie-chart', 'piggy-bank', 'plane',
        'play', 'plus', 'printer', 'qr-code', 'receipt', 'refresh', 'rocket', 'save',
        'scale', 'search', 'send', 'settings', 'share', 'shield', 'shield-check',
        'shopping-bag', 'shopping-cart', 'smartphone', 'smile', 'sparkles', 'star',
        'stethoscope', 'store', 'sun', 'tag', 'target', 'thumbs-up', 'trash',
        'trending-down', 'trending-up', 'trophy', 'truck', 'upload', 'user',
        'user-check', 'user-plus', 'users', 'utensils', 'video', 'wallet', 'wrench',
        'zap',
    ];
}
