/**
 * The runtime icon registry: a curated, generous set of Lucide icons the app
 * builder can use by NAME anywhere a block accepts an `icon` (buttons, feature
 * cards, stats, nav, table actions, …). Names are kebab-case and resolved
 * case-insensitively (spaces/underscores tolerated). Anything not in the registry
 * is treated as raw text, so an emoji still works.
 *
 * Tree-shaken: only these named imports ship in the runtime bundle. The SAME name
 * set is mirrored in app/Support/Icons/IconCatalog.php (the catalog the AI reads);
 * tests/Feature/Apps/IconCatalogTest.php enforces they stay in sync.
 */
import {
    Activity,
    AlarmClock,
    AlertCircle,
    AlertTriangle,
    Archive,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    Award,
    BadgeCheck,
    BarChart3,
    Battery,
    Bell,
    Bike,
    Bookmark,
    Box,
    Boxes,
    Briefcase,
    Building2,
    Calculator,
    Calendar,
    CalendarDays,
    Camera,
    Car,
    Check,
    CheckCircle,
    ChevronDown,
    ChevronRight,
    CircleMinus,
    CirclePlus,
    CircleX,
    ClipboardList,
    Clock,
    Cloud,
    Code,
    Cog,
    Coins,
    Compass,
    Contact,
    CreditCard,
    Database,
    DollarSign,
    Download,
    Eye,
    EyeOff,
    Factory,
    File,
    FileText,
    Filter,
    Flag,
    Flame,
    Folder,
    Gauge,
    Gift,
    Globe,
    GraduationCap,
    Grid3x3,
    Hammer,
    HandCoins,
    Headphones,
    Heart,
    HeartPulse,
    HelpCircle,
    Home,
    Image,
    Inbox,
    Info,
    Key,
    Landmark,
    Layers,
    LayoutDashboard,
    Leaf,
    Lightbulb,
    LineChart,
    Link as LinkIcon,
    List,
    Lock,
    Mail,
    Map,
    MapPin,
    MessageCircle,
    MessageSquare,
    Mic,
    Minus,
    Monitor,
    Moon,
    MoreHorizontal,
    Navigation,
    Package,
    Palette,
    Paperclip,
    Pencil,
    Percent,
    Phone,
    PieChart,
    PiggyBank,
    Plane,
    Play,
    Plus,
    Printer,
    QrCode,
    Receipt,
    RefreshCw,
    Rocket,
    Save,
    Scale,
    Search,
    Send,
    Settings,
    Share2,
    Shield,
    ShieldCheck,
    ShoppingBag,
    ShoppingCart,
    Sigma,
    Smartphone,
    Smile,
    Sparkles,
    Star,
    Stethoscope,
    Store,
    Sun,
    Tag,
    Target,
    ThumbsDown,
    ThumbsUp,
    Trash2,
    TrendingDown,
    TrendingUp,
    Trophy,
    Truck,
    Upload,
    User,
    UserCheck,
    UserPlus,
    Users,
    Utensils,
    Video,
    Wallet,
    Wrench,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';

/** name (kebab-case) → Lucide component. */
const REGISTRY: Record<string, Component> = {
    activity: Activity,
    'alarm-clock': AlarmClock,
    'alert-circle': AlertCircle,
    'alert-triangle': AlertTriangle,
    archive: Archive,
    'arrow-down': ArrowDown,
    'arrow-left': ArrowLeft,
    'arrow-right': ArrowRight,
    'arrow-up': ArrowUp,
    award: Award,
    'badge-check': BadgeCheck,
    'bar-chart': BarChart3,
    battery: Battery,
    bell: Bell,
    bike: Bike,
    bookmark: Bookmark,
    box: Box,
    boxes: Boxes,
    briefcase: Briefcase,
    building: Building2,
    calculator: Calculator,
    calendar: Calendar,
    'calendar-days': CalendarDays,
    camera: Camera,
    car: Car,
    check: Check,
    'check-circle': CheckCircle,
    'chevron-down': ChevronDown,
    'chevron-right': ChevronRight,
    clipboard: ClipboardList,
    clock: Clock,
    cloud: Cloud,
    code: Code,
    cog: Cog,
    coins: Coins,
    compass: Compass,
    contact: Contact,
    'credit-card': CreditCard,
    database: Database,
    dollar: DollarSign,
    download: Download,
    eye: Eye,
    'eye-off': EyeOff,
    factory: Factory,
    file: File,
    'file-text': FileText,
    filter: Filter,
    flag: Flag,
    flame: Flame,
    folder: Folder,
    gauge: Gauge,
    gift: Gift,
    globe: Globe,
    graduation: GraduationCap,
    grid: Grid3x3,
    hammer: Hammer,
    'hand-coins': HandCoins,
    headphones: Headphones,
    heart: Heart,
    'heart-pulse': HeartPulse,
    help: HelpCircle,
    home: Home,
    image: Image,
    inbox: Inbox,
    info: Info,
    key: Key,
    landmark: Landmark,
    layers: Layers,
    dashboard: LayoutDashboard,
    leaf: Leaf,
    lightbulb: Lightbulb,
    'line-chart': LineChart,
    link: LinkIcon,
    list: List,
    lock: Lock,
    mail: Mail,
    map: Map,
    'map-pin': MapPin,
    'message-circle': MessageCircle,
    message: MessageSquare,
    mic: Mic,
    minus: Minus,
    'minus-circle': CircleMinus,
    monitor: Monitor,
    moon: Moon,
    more: MoreHorizontal,
    navigation: Navigation,
    package: Package,
    palette: Palette,
    paperclip: Paperclip,
    pencil: Pencil,
    edit: Pencil,
    percent: Percent,
    phone: Phone,
    'pie-chart': PieChart,
    'piggy-bank': PiggyBank,
    plane: Plane,
    play: Play,
    plus: Plus,
    'plus-circle': CirclePlus,
    printer: Printer,
    'qr-code': QrCode,
    receipt: Receipt,
    refresh: RefreshCw,
    rocket: Rocket,
    save: Save,
    scale: Scale,
    search: Search,
    send: Send,
    settings: Settings,
    share: Share2,
    shield: Shield,
    'shield-check': ShieldCheck,
    'shopping-bag': ShoppingBag,
    'shopping-cart': ShoppingCart,
    sigma: Sigma,
    smartphone: Smartphone,
    smile: Smile,
    sparkles: Sparkles,
    star: Star,
    stethoscope: Stethoscope,
    store: Store,
    sun: Sun,
    tag: Tag,
    target: Target,
    'thumbs-down': ThumbsDown,
    'thumbs-up': ThumbsUp,
    trash: Trash2,
    'trending-down': TrendingDown,
    'trending-up': TrendingUp,
    trophy: Trophy,
    truck: Truck,
    upload: Upload,
    user: User,
    'user-check': UserCheck,
    'user-plus': UserPlus,
    users: Users,
    utensils: Utensils,
    video: Video,
    wallet: Wallet,
    wrench: Wrench,
    'x-circle': CircleX,
    zap: Zap,
};

/** The canonical icon names, for catalogs/tests. */
export const ICON_NAMES: string[] = Object.keys(REGISTRY);

function normalizeIconName(name: string): string {
    return name
        .trim()
        .toLowerCase()
        .replace(/[\s_]+/g, '-');
}

/**
 * Resolve an `icon` string to a Lucide component EAGERLY (no async), or null.
 * Only checks the curated REGISTRY above — for anything else, see
 * `resolveIconLazy`. Kept for callers that render icons synchronously.
 */
export function resolveIcon(name: string | null | undefined): Component | null {
    if (!name) {
        return null;
    }

    return REGISTRY[normalizeIconName(name)] ?? null;
}

/**
 * The full Lucide set, loaded lazily as ONE chunk on first miss and cached for
 * the rest of the session. See ./lucide-full for why this is a single import
 * rather than a per-icon `import.meta.glob` (the glob exploded into ~1,700
 * micro-chunks and, on an imperfect deploy, hundreds of 404s).
 */
let fullSet: Promise<typeof import('./lucide-full')> | null = null;

/**
 * Resolve ANY real Lucide icon by name, not just the curated REGISTRY —
 * so a model's plausible guess ("chart-column", "circle-alert") renders
 * instead of silently falling back to plain text just because it's outside
 * our hand-picked set. The curated REGISTRY still resolves instantly (no
 * fetch); the first name outside it triggers a single lazy chunk load, and an
 * invalid name resolves to null, same as today's "unknown → nothing" for
 * icon-shaped strings.
 */
export async function resolveIconLazy(
    name: string | null | undefined,
): Promise<Component | null> {
    if (!name) {
        return null;
    }
    const key = normalizeIconName(name);
    const eager = REGISTRY[key];
    if (eager) {
        return eager;
    }
    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(key)) {
        return null; // not icon-shaped (e.g. an emoji) — never worth a load
    }

    try {
        fullSet ??= import('./lucide-full');
        const mod = await fullSet;

        return mod.lookupLucide(key);
    } catch {
        return null;
    }
}
