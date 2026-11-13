<?php
// config/ai_config.php - AI Builder provider configuration.
//
// Setup (local XAMPP dev):
//   1. Create a free Gemini API key at https://aistudio.google.com/app/apikey
//      (sign in with a Google account, "Create API key", copy it).
//   2. Open the project's .env file (copy from .env.example if you don't
//      have one yet - it's gitignored, never commit it).
//   3. Set GEMINI_API_KEY=<your key> in .env. Leave GEMINI_MODEL as-is
//      unless you want a different model (check https://ai.google.dev/gemini-api/docs/models
//      for the current list of available/free-tier-eligible models before changing it).
//   4. Reload public/authenticated/api/ai_builder.php?action=ai_test while
//      logged in as a customer to confirm the key works (see handle_ai_test()
//      in that file) - it never echoes the key back, only connection status.
//
// The key is only ever read here, server-side, via getenv(). Nothing in
// public/ JS/HTML ever sees it - every Gemini call happens inside PHP
// (config/gemini_client.php), triggered from api/ai_builder.php.
require_once __DIR__ . '/env.php';

if (!defined('AI_PROVIDER')) {
    define('AI_PROVIDER', 'gemini');
}
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', (string) (getenv('GEMINI_API_KEY') ?: ''));
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', (string) (getenv('GEMINI_MODEL') ?: 'gemini-3.6-flash'));
}
if (!defined('GEMINI_API_BASE')) {
    define('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta/models/');
}

/** Whether a usable AI provider is configured (key present). Callers must still handle request-time failures (quota, network, invalid key). */
function ai_is_configured(): bool
{
    return AI_PROVIDER === 'gemini' && GEMINI_API_KEY !== '';
}
