<?php
declare(strict_types=1);

namespace ai;

use platform\ilMarkdownLernmodulConfig;
use platform\ilMarkdownLernmodulException;
use security\ilMarkdownLernmodulCircuitBreaker;
use security\ilMarkdownLernmodulResponseValidator;
use security\ilMarkdownLernmodulRequestSigner;
use security\ilMarkdownLernmodulCertificatePinner;

require_once dirname(__DIR__) . '/platform/class.ilMarkdownLernmodulConfig.php';
require_once dirname(__DIR__) . '/platform/class.ilMarkdownLernmodulException.php';
require_once dirname(__DIR__) . '/security/class.ilMarkdownLernmodulCircuitBreaker.php';
require_once dirname(__DIR__) . '/security/class.ilMarkdownLernmodulResponseValidator.php';
require_once dirname(__DIR__) . '/security/class.ilMarkdownLernmodulRequestSigner.php';
require_once dirname(__DIR__) . '/security/class.ilMarkdownLernmodulCertificatePinner.php';
require_once __DIR__ . '/class.ilMarkdownLernmodulLLM.php';

/**
 * Google Gemini AI Provider for MarkdownLernmodul
 * 
 * Integrates Google Gemini API for learning module generation.
 * 
 * Supported Models:
 * - gemini-2.0-flash-exp (recommended, fast)
 * - gemini-pro (higher quality, slower)
 * 
 * API Docs: https://ai.google.dev/docs/gemini_api_overview
 * API Key: https://makersuite.google.com/app/apikey
 * 
 * Security: Circuit Breaker, 30s timeout, JSON validation
 * 
 * @package ai
 */
class ilMarkdownLernmodulGoogleAI extends ilMarkdownLernmodulLLM
{
    /** @var string Google API key from config */
    private string $api_key;
    
    /** @var string Model name (e.g., "gemini-2.0-flash-exp") */
    private string $model;

    /**
     * Constructor
     * 
     * @param string $api_key Google AI API key
     * @param string $model Model identifier
     */
    public function __construct(string $api_key, string $model)
    {
        $this->api_key = $api_key;
        $this->model = $model;
    }

    /**
     * Generate learning module using Google Gemini API
     * 
     * @param string $user_prompt Learning module topic
     * @return string Generated pages in Markdown format
     * @throws ilMarkdownLernmodulException On API errors or timeout
     */
    public function generateLernmodul(string $user_prompt): string
    {
        $serviceName = 'google';
        
        try {
            // Check Circuit Breaker (is API available?)
            ilMarkdownLernmodulCircuitBreaker::checkAvailability($serviceName);
            
            // Build full prompt
            $prompt = $this->buildPrompt($user_prompt);
            
            // Call Google API
            $response = $this->callAPI($prompt);
            
            // Parse and clean response
            $parsed = $this->parseResponse($response);
            
            // Record success
            ilMarkdownLernmodulCircuitBreaker::recordSuccess($serviceName);
            
            return $parsed;
            
        } catch (\Exception $e) {
            // Record failure (too many failures will open circuit)
            ilMarkdownLernmodulCircuitBreaker::recordFailure($serviceName);
            throw $e;
        }
    }

    /**
     * Build prompt for Google Gemini API
     * 
     * @param string $user_prompt Learning module topic
     * @return string Final prompt text
     */
    protected function buildPrompt(string $user_prompt): string
    {
        // Load system prompt from config
        ilMarkdownLernmodulConfig::load();
        $system_prompt = ilMarkdownLernmodulConfig::get('system_prompt');
        
        $format_rules = "FORMAT:\n" .
            "## Title\n" .
            "Your Title Here\n\n" .
            "## Content\n" .
            "Your content here...\n\n" .
            "---\n\n" .
            "RULES:\n" .
            "- Each page MUST start with '## Title'\n" .
            "- Then MUST have '## Content'\n" .
            "- Separate pages with '---'\n" .
            "- Start immediately with '## Title'\n" .
            "- Do NOT use '## Front' or '## Back'";
        
        // Fallback if no system prompt configured
        if (empty($system_prompt)) {
            $system_prompt = "Generate learning module pages in markdown format.\n\n" . $format_rules;
        }
        
        // Safety: enforce format rules even if a legacy prompt is stored
        if (!preg_match('/##\s*Title/i', $system_prompt) || !preg_match('/##\s*Content/i', $system_prompt)) {
            $system_prompt .= "\n\n" . $format_rules;
        }
        
        // Combine system prompt with user input
        $final_prompt = $system_prompt . "\n\n" . $user_prompt;
        
        return $final_prompt;
    }

    /**
     * Call Google Gemini API
     * 
     * Endpoint: generativelanguage.googleapis.com/v1/models/{model}:generateContent
     * 
     * @param string $prompt Full prompt text
     * @return string Generated text from API
     * @throws ilMarkdownLernmodulException On network errors or invalid response
     */
    private function callAPI(string $prompt): string
    {
        // Check if API key is configured
        if (empty($this->api_key)) {
            throw new ilMarkdownLernmodulException("Google API key is not configured");
        }

        // Build API URL with model name and API key (URL-encoded for security)
        $url = "https://generativelanguage.googleapis.com/v1/models/" . 
               urlencode($this->model) . ":generateContent?key=" . urlencode($this->api_key);

        // Build request payload in Google-specific format
        $payload = [
            "contents" => [
                [
                    "parts" => [
                        [
                            "text" => $prompt
                        ]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.7,      // Creativity: 0.0=deterministic, 1.0=very creative
                "maxOutputTokens" => 2000  // Max response length (~1500 words)
            ]
        ];

        // Initialize CURL
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ilMarkdownLernmodulException("Failed to initialize CURL");
        }

        // Set CURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Check for CURL errors
        if ($response === false) {
            throw new ilMarkdownLernmodulException("API call failed: " . $error);
        }

        // Check HTTP status code
        if ($http_code !== 200) {
            $decoded = json_decode($response, true);
            $error_msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Unknown error';
            throw new ilMarkdownLernmodulException("Google API returned status code " . $http_code . ": " . $error_msg);
        }

        // Parse JSON response
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ilMarkdownLernmodulException("Invalid API response format");
        }

        // Extract text from nested response structure (candidates[0]->content->parts[0]->text)
        if (!isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            throw new ilMarkdownLernmodulException("Could not extract text from Google API response");
        }

        return $decoded['candidates'][0]['content']['parts'][0]['text'];
    }

    /**
     * Clean and validate API response
     * 
     * Google sometimes returns markdown in code blocks:
     * ```markdown
     * # Question 1
     * ...
     * ```
     * 
     * This function removes the outer code block markers.
     * 
     * @param string $response Raw text from API
     * @return string Cleaned markdown text
     */
    private function parseResponse(string $response): string
    {
        // Remove markdown code block wrapper if present (```markdown\n ... \n```)
        $response = preg_replace('/^```markdown\n/', '', $response);
        $response = preg_replace('/\n```$/', '', $response);
        
        // Remove leading/trailing whitespace
        $response = trim($response);
        
        return $response;
    }
}
