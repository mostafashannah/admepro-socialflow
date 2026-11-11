<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
// SocialFlow AI Configuration
// IMPORTANT: Set your Anthropic API key here
// Get your key from: https://console.anthropic.com/

define('ANTHROPIC_API_KEY', 'YOUR_ANTHROPIC_API_KEY_HERE');
