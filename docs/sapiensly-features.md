# Sapiensly — Feature Catalog

> A functional reference of what Sapiensly does, written as context for AI assistants.
> It describes product capabilities from the user's point of view — not code, routes, or
> internals.

## What Sapiensly is

Sapiensly is a B2B SaaS platform for **autonomous AI agent orchestration** and AI‑assisted
work. It turns passive chatbots into active agents that can reason over a company's own
knowledge and take real actions through controlled tools. Around that core it bundles a set of
AI workspaces — a general chat, a multi‑model debate room, a no‑code app builder, deployable
chatbots, a knowledge library, and document tooling — all under strict, organization‑based data
isolation.

---

## Core concepts

- **Workspaces (Personal vs Organization).** Every user works in either their **Personal**
  space or an **Organization** they belong to, and can switch between them. Data (chats, agents,
  knowledge bases, providers, etc.) is strictly scoped to the active workspace — you never see
  another tenant's data.
- **Sharing / visibility.** Resources are either **private** to you or **shared with your
  organization**, so teammates in the same org can reuse them.
- **Streaming answers.** AI responses stream in live, token by token, so you watch the assistant
  "think" instead of waiting for a finished block.
- **Knowledge bases (RAG).** You can give the AI your own documents so it answers **from your
  content** with citations, instead of only general knowledge.
- **Tools.** You can let the AI **take actions** — call your APIs, query a database, or use
  external connectors — rather than just talk.
- **Agents.** Reusable AI configurations (model + instructions + knowledge + tools) that power
  Chat, Debate, Chatbots, Flows, and Teams.
- **Quick switchers.** Chat and Debate are two sides of one workspace (toggle between them);
  Agents and Multi‑agents likewise share one manager.

---

## Chat

A Claude‑style general assistant for everyday questions and work.

- Pick any model you have access to and **switch models mid‑conversation**.
- Choose one of **your agents** as the responder — it then answers with its own model, prompt,
  knowledge bases, and tools (when an agent is selected, the Search and Tools controls are
  managed by the agent).
- **Projects** group related chats, add shared custom instructions, and attach knowledge bases
  so those chats can answer from your documents.
- Attach files (images, PDF, text, audio) to a message.
- Toggle **web search** and enable **tools** for a turn.
- **Artifacts:** substantial deliverables (full code files, HTML pages, SVGs, long documents)
  render in a side panel you can view fullscreen, copy, or download.
- Conversation **history** grouped by date (Today / Yesterday / …), with rename, delete, and a
  Stop button to halt a response.

### Agent @mention (multi-agent deliberation)

Bring several agents into one thread and have them deliberate, then close on a concrete action.

- Type `@` in the composer to pick one or more **agents** (autocomplete by name; up to 5 per
  message). Each appears as a removable chip.
- The mentioned agents respond **one at a time, in order**, each answering from its own
  knowledge bases, tools, and web search — and **seeing the previous agents' replies**, so it can
  build on or push back against them. Each reply streams live with a colored agent avatar and
  **data pills** showing the sources it drew on.
- After the last agent answers, the thread is **synthesized into a single action proposal** — a
  named, parametrized recommendation rendered as an **Action Card** (who agreed, the parameters,
  and a short rationale) instead of another wall of text. You can also re‑run synthesis manually.
- **Execute** the card in one click to close the thread (recorded inline), or **dismiss** it. If
  the agents don't reach a clear recommendation, the thread says so instead of forcing an action.

## IA Debate

Convene a council of AI models to deliberate a decision and converge on an answer.

- State a problem and pick **2–9 participants** — any mix of **models and agents** — plus a
  **moderator** model and a max number of rounds.
- Round 1: each participant states an opening position. Following rounds: each one reads the
  others' arguments and **rebuts, concedes, or refines** its stance.
- The **moderator** judges consensus after every round (showing agreements, disagreements, and a
  consensus meter) and **stops early** once the council converges.
- Ends with a **Conclusions** panel: recommendation, points of consensus, open questions, and
  each model's final stance.
- The whole process streams live, and all output stays in the **language of the question**.
- Past debates are saved in a history list.

## Agents

Build reusable, autonomous agents and chat with them.

- **Agent types:**
  - **General** — does it all on its own: triages the request, answers from knowledge, and acts
    with tools in a single agent.
  - **Triage** — classifies intent and can run guided conversational flows.
  - **Knowledge** — answers from attached knowledge bases (RAG).
  - **Action** — executes operations through attached tools.
- Configure name, description, keywords, model, system prompt, attached **knowledge bases** and
  **tools**, and tuning (retrieval depth/threshold, tool timeout/retries).
- **Test/chat** with an agent directly, and **duplicate** an agent to start a variant.

## Multi-agents (Agent Teams)

Orchestrate a coordinated team instead of a single agent.

- Compose a triad — **triage → knowledge → action** — that collaborates to handle a request,
  routing it to the right specialist.
- Chat with the team and watch it hand off between members.
- Lives inside the Agents manager (switch between **Agents** and **Multi‑agents**).

## Apps (no-code builder)

Build internal applications by describing them in plain language.

- Tell an **AI builder** what you want; it generates the app's data objects, pages, and
  workflows for you.
- **Review, approve, reject, or revert** the builder's changes, with version history and a
  visual review step; you can even import a wireframe as a starting point.
- Manage the app's **records** (its data) directly.
- **Run** the finished app at a shareable URL with working pages, actions, file uploads, and
  workflows — no code required.

## Flows

Design guided conversation flows for agents.

- Build branching flows with **menus, scripted messages, AI intent classification, and handoff**
  to a human or another agent.
- Attach a flow to a triage agent so conversations follow a defined path.
- **Test** the flow interactively before activating it.

## Chatbots

Package an assistant as a customer‑facing chatbot.

- Configure a chatbot and **preview** it live.
- Review its **conversation logs** and **analytics**.
- Deploy it to any website with an **embeddable widget** (a copy‑paste snippet); the widget runs
  full conversations with feedback and session handling.

## Tools

Connect capabilities that agents and chat can call to take action.

- Tool types: **REST API**, **GraphQL**, **Database query**, **MCP servers**, function, and
  **tool groups** (bundles).
- Validate a tool's connection and organize tools for reuse across agents.

## Knowledge Bases

Give the AI your own documents to answer from.

- Add content by **uploading files or pointing at URLs**; documents are automatically split into
  chunks and indexed for **semantic search** (RAG).
- Attach a knowledge base to agents or to chat **Projects** so answers draw on it (with sources).
- Reprocess documents when they change; everything stays **isolated per workspace**.

## Documents

Create, generate, and manage documents with AI.

- **Create** documents manually or **generate** them with AI, then **refine** and edit them.
- Organize documents into **folders**, move them around, and **download** them.
- **Share** a document publicly via a link.

## AI Providers

Connect the AI model providers your workspace uses.

- Add providers such as **Anthropic, OpenAI, Gemini, Mistral, Cohere** (and more) with your API
  keys, and choose which of their models to enable.
- Set the **default chat** provider and the **default embeddings** provider.
- Add providers **one at a time** without disturbing your existing defaults, and **test the
  connection** before saving.

## Cloud Providers

Bring your own infrastructure for tenant data.

- Connect **object storage** (S3‑compatible) for file uploads and attachments.
- Connect a **PostgreSQL database** (with pgvector) to hold your records and the vector store;
  test the connection and install the vector extension from the UI.

## Integrations

Connect and operate external APIs.

- Define integrations with **environments and variables**, including **OAuth 2.0** setup with
  auto‑discovery and dynamic client registration.
- Save reusable **requests** (or run ad‑hoc calls), start from **templates**, and review an
  **execution history**.

## WhatsApp

Run AI‑assisted WhatsApp customer service.

- Connect a WhatsApp number (Meta Cloud API) and manage approved **message templates**.
- Receive inbound messages automatically; an AI can reply, and a shared **inbox** lets a human
  **take over, reply, and release** conversations (human handoff).
- View **analytics** on activity.

## Admin panel (sysadmins)

Platform administration for sysadmin users.

- Manage **users**: invite, block/unblock, impersonate, reset two‑factor, resend verification.
- Configure **access control**, the **AI model catalog**, default models, and usage.
- Monitor **cloud and stack health**.

## Settings & accounts

- Edit your **profile** and **appearance** (light/dark theme).
- **Create an organization** and **invite members**; switch between Personal and Organization
  workspaces.
- Register and sign in with **email + password**, **two‑factor authentication**, **email
  verification**, and password reset.

---

## How the pieces fit together

- **Knowledge bases** and **tools** are the building blocks that give agents their knowledge and
  their ability to act.
- **Agents** (and **Multi‑agent teams**) are reused everywhere: in **Chat**, in **IA Debate**, as
  **Chatbots**, and inside **Flows**.
- **AI Providers**, **Cloud Providers**, and **Integrations** are the infrastructure those
  features draw on (models, storage/database, external APIs).
- **Apps**, **Documents**, **Chatbots**, and **WhatsApp** are delivery surfaces that turn the
  above into things end users and customers actually use.
- Everything operates inside a **workspace** (Personal or Organization) with strict data
  isolation and private/shared visibility.
