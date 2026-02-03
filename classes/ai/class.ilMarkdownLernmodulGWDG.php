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
 * GWDG Academic Cloud LLM Provider for MarkdownLernmodul
 * 
 * Integrates GWDG Academic Cloud API for learning module generation.
 * 
 * Supported Models:
 * - meta-llama/Meta-Llama-3.1-8B-Instruct
 * - meta-llama/Meta-Llama-3.1-70B-Instruct
 * - mistralai/Mistral-7B-Instruct-v0.3
 * 
 * API Docs: https://chat-ai.academiccloud.de/docs
 * Features: OpenAI-compatible API, SSE streaming, free for German universities, GDPR-compliant
 * Security: Circuit Breaker, 30s timeout, Bearer token auth
 * 
 * @author Robyn
 * @version 1.0
 */
class ilMarkdownLernmodulGWDG extends ilMarkdownLernmodulLLM
{
    /** @var string GWDG API key */
    private string $api_key;
    
    /** @var string Model identifier (e.g., "meta-llama/Meta-Llama-3.1-70B-Instruct") */
    private string $model;

    /**
     * Constructor
     * 
     * @param string $api_key GWDG API key from admin config
     * @param string $model Model name (must be supported by GWDG)
     */
    public function __construct(string $api_key, string $model)
    {
        $this->api_key = $api_key;
        $this->model = $model;
    }

    /**
     * Generate learning module using GWDG Academic Cloud API
     * 
     * @param string $user_prompt Learning module topic
     * @return string Generated pages in Markdown format
     * @throws ilMarkdownLernmodulException On API errors or Circuit Breaker open
     */
    public function generateLernmodul(string $user_prompt): string
    {
        $serviceName = 'gwdg';
        
        try {
            // Check Circuit Breaker availability
            ilMarkdownLernmodulCircuitBreaker::checkAvailability($serviceName);
            
            // Build final prompt
            $prompt = $this->buildPrompt($user_prompt);
            
            // Call GWDG API
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
     * Build prompt from system template and user input
     * 
     * @param string $user_prompt User's topic
     * @return string Final combined prompt
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
     * Call GWDG Academic Cloud API
     * 
     * Endpoint: https://chat-ai.academiccloud.de/v1/chat/completions
     * Format: OpenAI-compatible (Chat Completions)
     * 
     * @param string $prompt Full prompt (system + user)
     * @return string Generated quiz text from API
     * @throws ilMarkdownLernmodulException On missing config, network errors, or HTTP errors
     */
    private function callAPI(string $prompt): string
    {
        // Check if API key and model are configured
        if (empty($this->api_key) || empty($this->model)) {
            throw new ilMarkdownLernmodulException("GWDG configuration is incomplete");
        }

        // API endpoint
        $endpoint = 'https://chat-ai.academiccloud.de/v1/chat/completions';
        
        // Build request payload (OpenAI-compatible format)
        $payload = [
            "model" => $this->model,  // e.g., "meta-llama/Meta-Llama-3.1-70B-Instruct"
            "messages" => [
                [
                    "role" => "user",      // Role: user, system, assistant
                    "content" => $prompt   // Full prompt
                ]
            ],
            "temperature" => 0.7,      // Creativity: 0.0=deterministic, 1.0=very creative
            "max_tokens" => 2000       // Max response length (~1500 words)
        ];

        // Initialize CURL
        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new ilMarkdownLernmodulException("Failed to initialize CURL");
        }

        // Set CURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->api_key  // Bearer token auth
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

        // Check HTTP status code (200 = OK, 401 = Unauthorized, 429 = Rate Limit, 500 = Server Error)
        if ($http_code !== 200) {
            throw new ilMarkdownLernmodulException("API returned status code: " . $http_code);
        }

        // Parse JSON response
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
            throw new ilMarkdownLernmodulException("Invalid API response format");
        }

        // Extract generated text (path: choices[0]->message->content)
        return $decoded['choices'][0]['message']['content'];
    }

    /**
     * Clean and validate API response
     * 
     * Removes markdown code block wrappers if present
     * 
     * @param string $response Raw text from API
     * @return string Cleaned markdown text
     */
    private function parseResponse(string $response): string
    {
        // Remove markdown code block wrapper if present
        $response = preg_replace('/^```markdown\n/', '', $response);
        $response = preg_replace('/\n```$/', '', $response);
        
        // Remove leading/trailing whitespace
        $response = trim($response);

        return $response;
    }
}
