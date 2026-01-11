# Sapiensly

A B2B SaaS platform for **Autonomous Agent Orchestration**. Transform passive chatbots into active agents that execute tasks.

## Overview

Sapiensly enables organizations to deploy autonomous AI agents that handle customer service without human intervention. The platform focuses on resolving Level 1 and 2 support tickets through a coordinated "digital squad" of specialized agents.

### The Agent Triad

1. **Triage Agent** - Classifies intent, urgency, and sentiment
2. **Knowledge Agent (RAG)** - Searches company documentation with strict tenant isolation
3. **Action Agent** - Executes real-world operations via controlled tools

## Features

- **Chatbot Management** - Create, configure, and deploy chatbots with customizable appearance and behavior
- **Agent System** - Build standalone agents or orchestrate agent teams for complex workflows
- **Knowledge Bases** - RAG-powered document search with automatic chunking and vector embeddings
- **Tool Execution** - Connect agents to REST APIs, GraphQL, functions, MCPs, and databases
- **Embeddable Widget** - Deploy chatbots on any website with a JavaScript snippet
- **Multi-tenancy** - WorkOS-powered organization isolation with SSO support
- **Analytics** - Track conversations, resolution rates, and user feedback

## Tech Stack

| Category | Technology |
|----------|------------|
| Backend | Laravel 12, PHP 8.4 |
| Frontend | Vue 3, Inertia.js, TypeScript |
| Styling | Tailwind CSS v4, reka-ui (shadcn/vue) |
| AI Integration | Prism PHP (LLM abstraction with tool calling) |
| Database | PostgreSQL + pgvector |
| Authentication | WorkOS (SSO & multi-tenancy) |
| Queues | Redis + Laravel Horizon |
| Real-time | Laravel Reverb + Echo (WebSockets) |

## Requirements

- PHP 8.4+
- Node.js 18+
- PostgreSQL with pgvector extension
- Redis (recommended for production)
- WorkOS account
- LLM API key (Anthropic or OpenAI)

## Installation

```bash
# Clone the repository
git clone <repository-url>
cd sapiensly

# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run database migrations
php artisan migrate

# Build frontend assets
npm run build
```

## Configuration

### Environment Variables

Copy `.env.example` to `.env` and configure the following:

```bash
# WorkOS Authentication
WORKOS_CLIENT_ID=your_client_id
WORKOS_API_KEY=your_api_key
WORKOS_REDIRECT_URL=http://localhost:8000/authenticate

# LLM Providers
ANTHROPIC_API_KEY=your_anthropic_key
OPENAI_API_KEY=your_openai_key

# Database (PostgreSQL recommended)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sapiensly
DB_USERNAME=postgres
DB_PASSWORD=your_password

# Queue & Cache (Redis for production)
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1

# AWS S3 (optional, for document storage)
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your_bucket
```

## Development

```bash
# Start development server (server + queue + logs + vite)
composer dev

# Start with SSR
composer dev:ssr

# Run tests
composer test

# Run specific test file
php artisan test tests/Feature/DashboardTest.php

# PHP formatting
./vendor/bin/pint

# Frontend linting
npm run lint

# Frontend formatting
npm run format

# Build production assets
npm run build

# Build embeddable widget
npm run build:widget
```

## Architecture

### Core Patterns

- **Modern Monolith** - Single deployable unit prioritizing development speed and enterprise robustness
- **Central Orchestrator** - Dynamic agent/tool invocation based on conversation state (no linear scripts)
- **Tooling Layer** - Agents interact with external systems through controlled internal APIs
- **Tenant-Aware RAG** - Vector search automatically filters by WorkOS Organization ID
- **Streaming Feedback** - Real-time agent responses via WebSockets

### Project Structure

```
app/
├── Http/Controllers/    # Request handlers
├── Models/              # Eloquent models
├── Services/            # Business logic
├── Jobs/                # Queue jobs
├── Enums/               # Type enumerations
└── Policies/            # Authorization

resources/js/
├── pages/               # Inertia page components
├── components/          # Vue components
├── composables/         # Vue composables
├── layouts/             # Page layouts
└── types/               # TypeScript definitions

routes/
├── web.php              # Main web routes
├── api.php              # API endpoints
├── chatbots.php         # Chatbot routes
├── agents.php           # Agent routes
└── ...                  # Feature-specific routes
```

## License

[License to be determined]
