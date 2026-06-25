<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => env('AI_PROVIDER', 'anthropic'),
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Streaming Transport Timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | Applied by AppServiceProvider to streaming (SSE) HTTP requests only. The
    | idle timeout bounds the gap between stream chunks at the socket layer, so
    | a provider that opens the connection and then stalls aborts cleanly rather
    | than hanging until the queue worker's hard timeout. Keep the idle value
    | below the streaming jobs' timeout (280s) and the worker timeout (300s).
    |
    */

    'stream_idle_timeout' => (int) env('AI_STREAM_IDLE_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Ingestion
    |--------------------------------------------------------------------------
    |
    | Tunables for the PDF ingestion pipeline: how a PDF is classified as
    | digital (cheap PHP text extraction) vs scanned (OCR), the heuristic that
    | picks the OCR engine, the chat model paired with the OpenRouter file-parser
    | plugin, and the average tokens-per-page used to estimate cost before a
    | document is processed.
    |
    */

    'ingestion' => [
        // A PDF page counts as "having text" at or above this many characters.
        'min_chars_per_text_page' => 100,
        // If fewer than this fraction of pages have text, treat the PDF as scanned → OCR.
        'scanned_coverage_threshold' => 0.6,

        // OCR engine heuristic (auto): pick the cheap engine only for high-volume,
        // simple scans; otherwise default to the higher-quality engine.
        'ocr' => [
            'default_engine' => 'mistral-ocr',
            'cheap_engine' => 'cloudflare-ai',
            'bulk_pages' => 30,                 // "high volume" from here up
            'simple_bytes_per_page' => 120000,  // <= this ≈ a simple text scan
            // Chat model paired with the OpenRouter file-parser plugin for extraction.
            'model' => env('AI_INGESTION_OCR_MODEL', 'openai/gpt-4o-mini'),
        ],

        // Cost-estimation assumptions (pre-flight, before any text is extracted).
        'avg_tokens_per_page' => 600,
        'avg_chunk_tokens' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Caching
    |--------------------------------------------------------------------------
    |
    | Gates the explicit Anthropic `cache_control` markers on the chat's frozen
    | system prefix (system + instructions + rolling summary). When off, the
    | prefix is still kept stable (so OpenAI / OpenRouter-to-OpenAI auto-cache),
    | but no Anthropic cache breakpoint is emitted.
    |
    */
    'prompt_caching' => [
        'enabled' => env('AI_PROMPT_CACHING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
