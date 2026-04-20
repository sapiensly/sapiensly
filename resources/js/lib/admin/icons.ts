/**
 * Admin v2 icon set — aliases over lucide-vue-next so templates can import
 * short, semantic names (`<NavDashboard />`, `<AuditAction />`) without
 * leaking the specific Lucide names everywhere. Matches the prototype ↔ Lucide
 * table in `handoff/design_tokens.md` §5.
 *
 * Usage:
 *   import { NavDashboard, Brain, Shield } from '@/lib/admin/icons';
 */

export {
    // navigation
    LayoutDashboard as NavDashboard,
    Users as NavUsers,
    Shield as NavAccess,
    Brain as NavAi,
    Cloud as NavCloud,
    Layers as NavStack,

    // entities
    Sparkles,
    Bot,
    Database,
    HardDrive,
    Server,
    Cpu,
    Zap,
    Radio,
    Plug,
    Library,

    // actions / affordances
    SlidersHorizontal,
    Lock,
    Key,
    Eye,
    EyeOff,
    Check,
    Ban,
    Mail,
    Trash2,
    MoreVertical,
    RefreshCw,
    Download,
    Search,
    Bell,
    Plus,
    X,

    // direction
    ChevronRight,
    ChevronDown,
    Menu,
    LogOut,
    ArrowLeftToLine as Back,

    // state
    AlertTriangle,
    Info,
    TrendingUp,
    Activity,
    Star,

    // narrative (three-layer story)
    Shield as Shield, // alias for reach-around; also as NavAccess
    Brain as Brain,
} from 'lucide-vue-next';
