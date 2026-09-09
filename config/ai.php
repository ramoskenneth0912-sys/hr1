<?php
/**
 * AI Resume Matching — provider configuration.
 *
 * The default engine is the built-in on-premise screening engine ("local"):
 *   - no external API, credentials, or network dependency
 *   - always available, never blocks application submission
 *   - computes the match server-side from the job posting + resume text
 *
 * An OpenAI-compatible remote provider can be enabled via environment
 * variables instead of editing this file (same pattern as config/database.php):
 *
 *   AI_SCREENING_PROVIDER   = local | openai-compatible
 *   AI_SCREENING_API_URL    = https://api.openai.com/v1/chat/completions
 *   AI_SCREENING_API_KEY    = <secret, read from environment only>
 *   AI_SCREENING_MODEL      = gpt-4o-mini
 *   AI_SCREENING_TIMEOUT_SECONDS = 20
 *   AI_SCREENING_MAX_RESUME_CHARS = 12000   (privacy: only this much resume text is sent)
 *   AI_SCREENING_MAX_SUMMARY_CHARS   = 1500
 *
 * Secrets are read server-side only and are never rendered into HTML or
 * stored in the database. If the remote provider is unreachable, misconfigured,
 * or returns an invalid response, the analysis is marked failed/unavailable and
 * the application itself is never affected.
 */

define('AI_SCREENING_PROVIDER', strtolower(trim((string) (getenv('AI_SCREENING_PROVIDER') ?: 'local'))));

define('AI_SCREENING_API_URL', rtrim(trim((string) (getenv('AI_SCREENING_API_URL') ?: '')), '/'));

define('AI_SCREENING_API_KEY', (string) (getenv('AI_SCREENING_API_KEY') !== false ? getenv('AI_SCREENING_API_KEY') : ''));

define('AI_SCREENING_MODEL', trim((string) (getenv('AI_SCREENING_MODEL') ?: 'gpt-4o-mini')));

define('AI_SCREENING_TIMEOUT_SECONDS', max(1, (int) (getenv('AI_SCREENING_TIMEOUT_SECONDS') ?: 20)));

define('AI_SCREENING_MAX_RESUME_CHARS', max(500, (int) (getenv('AI_SCREENING_MAX_RESUME_CHARS') ?: 12000)));

define('AI_SCREENING_MAX_SUMMARY_CHARS', max(50, (int) (getenv('AI_SCREENING_MAX_SUMMARY_CHARS') ?: 1500)));

/**
 * Hybrid screening engine v2 (default ON).
 *
 * AI_SCREENING_SEMANTIC = on | off
 *   Enables the semantic / contextual keyword layer (hybrid mode). When "off"
 *   the engine transparently falls back to the original local rule-based
 *   matcher, so the system always works.
 *
 * AI_SCREENING_VERSION
 *   Version tag stored on every new screening row. Existing rows keep NULL so
 *   old results are never silently relabeled as the new engine.
 */
define('AI_SCREENING_SEMANTIC', strtolower(trim((string) (getenv('AI_SCREENING_SEMANTIC') ?: 'on'))) === 'on');

define('AI_SCREENING_VERSION', trim((string) (getenv('AI_SCREENING_VERSION') ?: 'hybrid-v2')));