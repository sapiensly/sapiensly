# Sapiensly: Technical Executive Overview

## 1. Functional Objective (The "What")
Sapiensly is a **B2B SaaS Platform for Autonomous Agent Orchestration**. Its mission is to transform the digital workforce from passive (chatbots) to active (agents that execute tasks).

### MVP Focus: Autonomous Customer Service
The immediate goal is to deploy a "digital squad" that integrates into a company's support flow to resolve Level 1 and 2 tickets without human intervention.

### Value Stream
1.  **Ingestion:** Receives customer queries (via API/Web).
2.  **Orchestration (The Agent Triad):**
    * **Triage Agent:** Classifies intent, urgency, and sentiment.
    * **Knowledge Agent (RAG):** Searches for answers in the company's private documentation (Manuals, FAQs) while strictly respecting tenant security.
    * **Action Agent:** Executes real-world operations (Check order status, process refunds, update records) using existing business logic.
3.  **Audit & Delivery:** Generates the final response to the customer and logs a detailed "Audit Trail" of decisions for human supervision.

---

## 2. The Tech Stack (The "How")
We have defined a **"Modern Monolith"** architecture that prioritizes development speed, enterprise robustness, and interface reactivity.

* **Core Backend:** **Laravel 12**. Leverages the framework's maturity for routing, validation, and data management.
* **Artificial Intelligence:** **Prism**. A PHP abstraction layer to connect with LLMs (Anthropic/OpenAI) and manage "Tool Calling."
* **Hybrid Database:** **PostgreSQL**.
    * *Relational:* User data, chats, and logs.
    * *Vector:* **pgvector** to store knowledge embeddings (long-term memory) within the same infrastructure.
* **Identity & Multi-tenancy:** **WorkOS**. Enterprise authentication management (SSO) and strict data isolation per Organization.
* **Async & Real-time:** **Redis + Laravel Horizon** (for AI processing queues) and **Laravel Reverb + Echo** (WebSockets for live token streaming).
* **Frontend:** **Vue.js + Inertia.js**. Single Page Application (SPA) built within Laravel.
* **UI/UX:** **TailwindCSS + Shadcn-Vue**. Modern, accessible, and clean visual components.

---

## 3. Logical Architecture (The Structure)
The architecture is designed for **security by design** in a multi-tenant environment.



### A. The Brain (Central Orchestrator)
There is no linear "script." The backend acts as a conductor, evaluating the current state of the conversation and dynamically deciding which agent or tool to invoke next.

### B. The Hands (Tooling Layer)
Your existing Laravel packages are encapsulated as **AI Tools**. The system exposes specific functions (Internal API) that agents can "call" to interact with the real world.
* *Key:* Agents do not touch the database directly; they operate through these controlled tools.

### C. Secure Memory (Tenant-Aware RAG)
The Retrieval-Augmented Generation (RAG) system is strictly isolated.
* When an agent searches for information, the system automatically injects an **Organization Filter (WorkOS ID)**. This ensures an agent never "remembers" or accesses data from another client, preventing cross-tenant data leaks.

### D. The Nervous System (Streaming Feedback)
Since AI inference takes time, the architecture decouples the request from the response.
* User sends message -> Job is queued -> Agent "thinks" and "acts" -> Each step is streamed in real-time to the frontend via WebSockets. This offers a transparent UX where the user sees the bot working, rather than just waiting.

---

**Summary:**
Sapiensly leverages the robustness of **Laravel** and the security of **WorkOS** to govern AI models that don't just chat, but **execute** your existing business rules through a reactive and transparent interface.
