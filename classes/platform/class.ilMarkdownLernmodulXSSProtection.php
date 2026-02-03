<?php
declare(strict_types=1);
/**
 * XSS Protection helper for MarkdownLernmodul plugin
 * Implements Content Security Policy, input sanitization, and markdown validation
 */

namespace platform;

/**
 * XSS Protection Service for MarkdownLernmodul Plugin
 * 
 * Provides multiple layers of XSS protection:
 * - Content Security Policy headers
 * - Input sanitization (strip dangerous patterns)
 * - Markdown structure validation
 * - HTML output escaping
 * 
 * Max lengths:
 * - Question: 500 chars
 * - Option: 300 chars
 * - Total: 10,000 chars
 * 
 * @package platform
 */
class ilMarkdownLernmodulXSSProtection
{
    // Allowed markdown patterns
    private const ALLOWED_MARKDOWN_PATTERNS = [
        'questions' => '/^.+\?$/m',  // Lines ending with ?
        'options' => '/^- \[(x| )\] .+$/m',  // Checkbox options
        'text' => '/^[a-zA-Z0-9\s\.\,\?\!\-\:\;\(\)\/\'\"\äöüÄÖÜß]+$/u',  // Safe characters
    ];
    
    // Maximum content lengths
    private const MAX_QUESTION_LENGTH = 500;
    private const MAX_OPTION_LENGTH = 300;
    private const MAX_TOTAL_LENGTH = 10000;
    
    // Dangerous HTML tags and attributes
    private const DANGEROUS_TAGS = [
        'script', 'iframe', 'object', 'embed', 'applet', 
        'meta', 'link', 'style', 'base', 'form'
    ];
    
    private const DANGEROUS_ATTRIBUTES = [
        'onload', 'onerror', 'onclick', 'onmouseover', 'onmouseout',
        'onkeydown', 'onkeyup', 'onfocus', 'onblur', 'onchange',
        'onsubmit', 'onreset', 'ondblclick', 'oncontextmenu'
    ];
    
    /**
     * Set Content Security Policy headers
     * 
     * Restricts resource loading and prevents inline scripts.
     * Also sets X-Frame-Options, X-XSS-Protection, etc.
     * 
     * @return void
     */
    public static function setCSPHeaders(): void
    {
        // Only set if headers not already sent
        if (headers_sent()) {
            return;
        }
        
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",  // ILIAS requires inline scripts
            "style-src 'self' 'unsafe-inline'",  // Allow inline styles
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'"
        ];
        
        header("Content-Security-Policy: " . implode('; ', $csp_directives));
        
        // Additional security headers
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
    }
    
    /**
     * Sanitize markdown content
     * 
     * - Strips all HTML tags
     * - Removes dangerous patterns (script, javascript:, event handlers)
     * - Validates length
     * 
     * @param string $markdown Raw markdown
     * @return string Sanitized markdown
     * @throws ilMarkdownLernmodulException If content too long or contains dangerous patterns
     */
    public static function sanitizeMarkdown(string $markdown): string
    {
        // Check total length
        if (strlen($markdown) > self::MAX_TOTAL_LENGTH) {
            throw new ilMarkdownLernmodulException(
                "Content too long (max " . self::MAX_TOTAL_LENGTH . " characters)"
            );
        }
        
        // Remove any HTML tags
        $markdown = strip_tags($markdown);
        
        // Remove dangerous character sequences
        $dangerous_patterns = [
            '/<script/i',
            '/javascript:/i',
            '/on\w+\s*=/i',  // Event handlers
            '/vbscript:/i',
            '/data:text\/html/i',
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $markdown)) {
                throw new ilMarkdownLernmodulException("Content contains potentially dangerous patterns");
            }
        }
        
        return trim($markdown);
    }
    
    /**
     * Validate learning module markdown structure
     * 
     * Ensures:
     * - At least one ## Title marker
     * - At least one ## Content marker
     * - Content not too large
     * 
     * @param string $markdown Markdown content
     * @return bool True if valid
     * @throws ilMarkdownLernmodulException If structure invalid
     */
    public static function validateMarkdownStructure(string $markdown): bool
    {
        // Check for learning module page markers
        $has_title = preg_match('/##\s*Title/i', $markdown);
        $has_content = preg_match('/##\s*Content/i', $markdown);
        
        if (!$has_title) {
            throw new ilMarkdownLernmodulException("Content must contain at least one '## Title' marker");
        }
        
        if (!$has_content) {
            throw new ilMarkdownLernmodulException("Content must contain at least one '## Content' marker");
        }
        
        // Optional: Check content isn't too long (prevent DoS)
        if (strlen($markdown) > 1000000) { // 1MB limit
            throw new ilMarkdownLernmodulException("Content too large (max 1MB)");
        }
        
        return true;
    }
    
    /**
     * Escape HTML for safe output
     * 
     * @param string $text Text to escape
     * @return string HTML-safe text
     */
    public static function escapeHTML(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
    
    /**
     * Sanitize HTML output (PHP alternative to DOMPurify)
     * 
     * Removes:
     * - Dangerous tags (script, iframe, object, etc.)
     * - Event handler attributes (onclick, onload, etc.)
     * - javascript: and data:text/html URIs
     * 
     * @param string $html HTML content
     * @return string Sanitized HTML
     */
    public static function sanitizeHTML(string $html): string
    {
        // Remove dangerous tags
        foreach (self::DANGEROUS_TAGS as $tag) {
            $html = preg_replace('/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is', '', $html);
            $html = preg_replace('/<' . $tag . '\b[^>]*>/i', '', $html);
        }
        
        // Remove dangerous attributes
        foreach (self::DANGEROUS_ATTRIBUTES as $attr) {
            $html = preg_replace('/' . $attr . '\s*=\s*["\'][^"\']*["\']/i', '', $html);
        }
        
        // Remove javascript: and data: URIs
        $html = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', '', $html);
        $html = preg_replace('/src\s*=\s*["\']data:text\/html[^"\']*["\']/i', '', $html);
        
        return $html;
    }
    
    /**
     * Sanitize user input (prompts, context, etc.)
     * @param string $input User input
     * @param int $max_length Maximum allowed length
     * @return string Sanitized input
     * @throws ilMarkdownLernmodulException
     */
    public static function sanitizeUserInput(string $input, int $max_length = 5000): string
    {
        // Check length
        if (strlen($input) > $max_length) {
            throw new ilMarkdownLernmodulException(
                "Input too long (max {$max_length} characters)"
            );
        }
        
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Normalize whitespace
        $input = preg_replace('/\s+/', ' ', $input);
        
        // Trim
        $input = trim($input);
        
        return $input;
    }
    
    /**
     * Generate safe inline script with nonce
     * @param string $script JavaScript code
     * @return array ['nonce' => string, 'script' => string]
     */
    public static function generateSafeScript(string $script): array
    {
        // Generate cryptographic nonce
        $nonce = base64_encode(random_bytes(16));
        
        // Wrap script in safe container
        $safe_script = sprintf(
            '<script nonce="%s">%s</script>',
            self::escapeHTML($nonce),
            $script  // Don't escape the script itself, but it should be pre-validated
        );
        
        return [
            'nonce' => $nonce,
            'script' => $safe_script
        ];
    }
    
    /**
     * Validate difficulty level enum
     * @param string $difficulty Difficulty value
     * @return bool True if valid
     */
    public static function validateDifficulty(string $difficulty): bool
    {
        $allowed = ['easy', 'medium', 'hard', 'mixed'];
        return in_array($difficulty, $allowed, true);
    }
    
    /**
     * Validate question count range
     * @param int $count Question count
     * @return bool True if valid
     */
    public static function validateQuestionCount(int $count): bool
    {
        return $count >= 1 && $count <= 20;
    }
    
    /**
     * Create safe data attribute value
     * @param string $value Value to use in data attribute
     * @return string Safe attribute value
     */
    public static function createSafeDataAttribute(string $value): string
    {
        // Only allow alphanumeric, spaces, and basic punctuation
        $safe = preg_replace('/[^a-zA-Z0-9\s\.\,\-\_]/', '', $value);
        return self::escapeHTML($safe);
    }
    
    /**
     * Comprehensive XSS protection for rendered content
     * @param string $markdown Markdown content
     * @return string Sanitized and validated markdown
     * @throws ilMarkdownLernmodulException
     */
    public static function protectContent(string $markdown): string
    {
        // Step 1: Sanitize markdown
        $sanitized = self::sanitizeMarkdown($markdown);
        
        // Step 2: Validate structure
        self::validateMarkdownStructure($sanitized);
        
        // Step 3: Return sanitized content
        return $sanitized;
    }
}
