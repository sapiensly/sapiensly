# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What is Sapiensly?

Sapiensly is a B2B SaaS platform for **Autonomous Agent Orchestration**. It transforms passive chatbots into active agents that execute tasks.

**MVP Focus: Autonomous Customer Service** - Deploy a "digital squad" that resolves Level 1 and 2 support tickets without human intervention.

### The Agent Triad
1. **Triage Agent**: Classifies intent, urgency, and sentiment
2. **Knowledge Agent (RAG)**: Searches company documentation (manuals, FAQs) with strict tenant isolation
3. **Action Agent**: Executes real-world operations (check orders, process refunds, update records) via controlled tools

### Core Architecture Patterns

**Modern Monolith**: Prioritizes development speed and enterprise robustness.

**Central Orchestrator**: No linear scripts. The backend dynamically decides which agent/tool to invoke based on conversation state.

**Tooling Layer**: Laravel packages are encapsulated as AI Tools. Agents interact with the real world through controlled internal APIs—never touching the database directly.

**Tenant-Aware RAG**: Vector search (pgvector) automatically injects WorkOS Organization ID filters. Agents cannot access data from other tenants.

**Streaming Feedback**: AI inference is decoupled via queues. Each agent step streams to the frontend via WebSockets, showing users the bot "thinking" rather than just waiting.

### Key Technologies
- **AI Integration**: Prism (PHP abstraction for LLMs with Tool Calling)
- **Hybrid Database**: PostgreSQL + pgvector for relational data and embeddings
- **Multi-tenancy**: WorkOS for SSO and strict Organization-based data isolation
- **Async Processing**: Redis + Laravel Horizon for AI queues
- **Real-time**: Laravel Reverb + Echo for WebSocket token streaming

## Build & Development Commands

```bash
# Development (starts server, queue, logs, and vite concurrently)
composer dev

# Development with SSR
composer dev:ssr

# Run tests (clears config then runs pest)
composer test

# Run a single test file
php artisan test tests/Feature/DashboardTest.php

# Run a specific test by name
php artisan test --filter=test_dashboard_is_displayed

# PHP code formatting (Laravel Pint)
./vendor/bin/pint

# Frontend linting (ESLint with auto-fix)
npm run lint

# Frontend formatting (Prettier)
npm run format

# Check frontend formatting
npm run format:check

# Build frontend assets
npm run build
```

## Architecture Overview

This is a Laravel 12 + Inertia.js + Vue 3 application using WorkOS for authentication.

### Backend Structure
- **Routes**: Split across `routes/web.php`, `routes/auth.php`, `routes/settings.php`
- **Authentication**: WorkOS integration via `laravel/workos` package with session validation middleware
- **Controllers**: Located in `app/Http/Controllers/`, with settings controllers in a `Settings/` subdirectory

### Frontend Structure
- **Entry point**: `resources/js/app.ts` - initializes Inertia and Vue
- **Pages**: `resources/js/pages/` - Inertia page components (Dashboard.vue, Welcome.vue, settings/)
- **Layouts**: `resources/js/layouts/` - AppLayout.vue with sidebar/header variants, settings/Layout.vue
- **Components**: `resources/js/components/` - App-specific components; `ui/` subdirectory contains shadcn/vue components (excluded from linting)
- **Composables**: `resources/js/composables/` - Vue composables (useAppearance, useInitials, useTwoFactorAuth)
- **Types**: `resources/js/types/index.d.ts` - TypeScript interfaces for User, Auth, NavItem, AppPageProps
- **Utilities**: `resources/js/lib/utils.ts` - `cn()` for class merging, URL helpers

### UI Component Library
Uses reka-ui (shadcn/vue) components with Tailwind CSS v4. Component variants use `class-variance-authority`. The `cn()` utility combines `clsx` and `tailwind-merge` for conditional classes.

### Styling
- Tailwind CSS v4 with CSS variables for theming (light/dark mode via `.dark` class)
- Theme defined in `resources/css/app.css` with CSS custom properties
- Uses `tw-animate-css` for animations

### Wayfinder
The project uses `laravel/wayfinder` for type-safe routing between Laravel and the frontend.

## Code Style

### PHP
- Uses Laravel Pint for formatting
- PHP 8.4 required
- Tests use Pest

### TypeScript/Vue
- ESLint + Prettier with TypeScript support
- Single quotes, semicolons, 4-space tabs
- Vue single-file components with `<script setup lang="ts">`
- Multi-word component names rule disabled
- Explicit `any` types allowed
