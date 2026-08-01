/**
 * Admin v2 icon set — aliases over @lucide/vue so templates can import
 * short, semantic names (`<NavDashboard />`, `<AuditAction />`) without
 * leaking the specific Lucide names everywhere. Matches the prototype ↔ Lucide
 * table in `handoff/design_tokens.md` §5.
 *
 * Usage:
 *   import { NavDashboard, Brain, Shield } from '@/lib/admin/icons';
 */

export {
    Activity,
    // state
    AlertTriangle,
    ArrowLeftToLine as Back,
    Ban,
    Bell,
    Bot, // alias for reach-around; also as NavAccess
    Brain as Brain,
    Check,
    ChevronDown,
    // direction
    ChevronRight,
    Cpu,
    Database,
    Download,
    ExternalLink,
    Eye,
    EyeOff,
    FileText,
    HardDrive,
    Info,
    Key,
    Library,
    Loader2,
    Lock,
    LogOut,
    Mail,
    Menu,
    MoreVertical,
    Shield as NavAccess,
    Brain as NavAi,
    Cloud as NavCloud,
    // navigation
    LayoutDashboard as NavDashboard,
    KeyRound as NavMcp,
    Layers as NavStack,
    Users as NavUsers,
    Pencil,
    Plug,
    Plus,
    Radio,
    RefreshCw,
    Rocket,
    ScrollText,
    Search,
    Server,
    // narrative (three-layer story)
    Shield as Shield,
    // actions / affordances
    SlidersHorizontal,
    // entities
    Sparkles,
    Star,
    Trash2,
    TrendingUp,
    X,
    Zap,
} from '@lucide/vue';
