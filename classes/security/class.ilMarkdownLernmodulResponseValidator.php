<?php
declare(strict_types=1);

namespace security;

/**
 * API Response Schema Validator
 * 
 * Validates API responses against expected structure:
 * - Schema validation (required fields present)
 * - Security checks (script tags, SQL patterns, etc.)
 * - Quiz format validation (questions, options, correct answers)
 * 
 * Prevents:
 * - Injection attacks via malformed responses
 * - DoS via oversized responses (100KB limit)
 * - Invalid quiz structures
 * 
 * @package security
 */
class ilMarkdownLernmodulResponseValidator
{
    /**
     * Validate OpenAI response structure
     * 
     * Checks:
     * - choices[0].message.content exists and is non-empty string
     * - No suspicious patterns (scripts, SQL, javascript:)
     * 
     * @param array $response Decoded JSON response
     * @throws \Exception If invalid structure or security violation
     */
    public static function validateOpenAIResponse(array $response): void
    {
        // Check required top-level fields
        if (!isset($response['choices']) || !is_array($response['choices'])) {
            throw new \Exception('Invalid OpenAI response: missing or invalid "choices" field');
        }
        
        if (empty($response['choices'])) {
            throw new \Exception('Invalid OpenAI response: empty "choices" array');
        }
        
        // Validate first choice
        $choice = $response['choices'][0];
        
        if (!isset($choice['message']) || !is_array($choice['message'])) {
            throw new \Exception('Invalid OpenAI response: missing or invalid "message" field');
        }
        
        if (!isset($choice['message']['content']) || !is_string($choice['message']['content'])) {
            throw new \Exception('Invalid OpenAI response: missing or invalid "content" field');
        }
        
        // Validate content is not empty
        if (trim($choice['message']['content']) === '') {
            throw new \Exception('Invalid OpenAI response: empty content');
        }
        
        // Check for suspicious patterns that might indicate injection
        self::validateContentSafety($choice['message']['content']);
    }
    
    /**
     * Validate Google Gemini response structure
     * 
     * Checks:
     * - candidates[0].content.parts[0].text exists and is non-empty
     * - No suspicious patterns
     * 
     * @param array $response Decoded JSON response
     * @throws \Exception If invalid structure or security violation
     */
    public static function validateGoogleResponse(array $response): void
    {
        // Check required top-level fields
        if (!isset($response['candidates']) || !is_array($response['candidates'])) {
            throw new \Exception('Invalid Google AI response: missing or invalid "candidates" field');
        }
        
        if (empty($response['candidates'])) {
            throw new \Exception('Invalid Google AI response: empty "candidates" array');
        }
        
        // Validate first candidate
        $candidate = $response['candidates'][0];
        
        if (!isset($candidate['content']) || !is_array($candidate['content'])) {
            throw new \Exception('Invalid Google AI response: missing or invalid "content" field');
        }
        
        if (!isset($candidate['content']['parts']) || !is_array($candidate['content']['parts'])) {
            throw new \Exception('Invalid Google AI response: missing or invalid "parts" field');
        }
        
        if (empty($candidate['content']['parts'])) {
            throw new \Exception('Invalid Google AI response: empty "parts" array');
        }
        
        if (!isset($candidate['content']['parts'][0]['text']) || !is_string($candidate['content']['parts'][0]['text'])) {
            throw new \Exception('Invalid Google AI response: missing or invalid "text" field');
        }
        
        // Validate content is not empty
        if (trim($candidate['content']['parts'][0]['text']) === '') {
            throw new \Exception('Invalid Google AI response: empty text');
        }
        
        // Check for suspicious patterns
        self::validateContentSafety($candidate['content']['parts'][0]['text']);
    }
    
    /**
     * Validate GWDG API response structure
     * @throws \Exception if response is invalid
     */
    public static function validateGWDGResponse(array $response): void
    {
        // GWDG uses OpenAI-compatible API
        self::validateOpenAIResponse($response);
    }
    
    /**
     * Check content for injection patterns
     * 
     * Rejects:
     * - <script> tags
     * - <?php code
     * - SQL keywords (DROP, DELETE, UPDATE, INSERT)
     * - javascript: protocol in markdown images
     * - Content > 100KB
     * 
     * @param string $content Response content
     * @throws \Exception If suspicious pattern detected
     */
    private static function validateContentSafety(string $content): void
    {
        // Check for script tags
        if (preg_match('/<script\b[^>]*>/i', $content)) {
            throw new \Exception('Security violation: script tag detected in API response');
        }
        
        // Check for suspicious PHP code
        if (preg_match('/<\?php/i', $content)) {
            throw new \Exception('Security violation: PHP code detected in API response');
        }
        
        // Check for SQL injection patterns in markdown (should not be present)
        if (preg_match('/;\s*(DROP|DELETE|UPDATE|INSERT)\s+/i', $content)) {
            throw new \Exception('Security violation: SQL-like pattern detected in API response');
        }
        
        // Check for markdown image with javascript protocol
        if (preg_match('/!\[.*?\]\(javascript:/i', $content)) {
            throw new \Exception('Security violation: javascript protocol in markdown image');
        }
        
        // Check for excessive length (potential DoS)
        if (strlen($content) > 100000) { // 100KB limit
            throw new \Exception('Security violation: response content exceeds maximum length');
        }
    }
    
    /**
     * Validate markdown quiz format
     * 
     * Requirements:
     * - Questions end with '?'
     * - Each question has exactly 4 options (- [x] or - [ ])
     * - Exactly 1 correct answer per question
     * 
     * @param string $markdown Quiz content
     * @return array Parsed questions with validation
     * @throws \Exception If format invalid (with detailed error messages)
     */
    public static function validateMarkdownLernmodulFormat(string $markdown): array
    {
        $lines = explode("\n", trim($markdown));
        $questions = [];
        $currentQuestion = null;
        $errors = [];
        
        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            
            // Skip empty lines
            if ($line === '') {
                if ($currentQuestion !== null && count($currentQuestion['options']) >= 4) {
                    $questions[] = $currentQuestion;
                    $currentQuestion = null;
                }
                continue;
            }
            
            // Check if it's an option line
            if (preg_match('/^-\s*\[([ x])\]\s*(.+)$/i', $line, $matches)) {
                if ($currentQuestion === null) {
                    $errors[] = "Line " . ($lineNum + 1) . ": Option found without question";
                    continue;
                }
                
                $currentQuestion['options'][] = [
                    'checked' => strtolower($matches[1]) === 'x',
                    'text' => trim($matches[2])
                ];
            } else {
                // Must be a question (should end with ?)
                if (!str_ends_with($line, '?')) {
                    $errors[] = "Line " . ($lineNum + 1) . ": Question must end with '?'";
                }
                
                // Save previous question if exists
                if ($currentQuestion !== null && count($currentQuestion['options']) >= 4) {
                    $questions[] = $currentQuestion;
                }
                
                $currentQuestion = [
                    'question' => $line,
                    'options' => []
                ];
            }
        }
        
        // Save last question
        if ($currentQuestion !== null && count($currentQuestion['options']) >= 4) {
            $questions[] = $currentQuestion;
        }
        
        // Validate each question
        foreach ($questions as $idx => $question) {
            $questionNum = $idx + 1;
            
            // Check has exactly 4 options
            if (count($question['options']) !== 4) {
                $errors[] = "Question {$questionNum}: Must have exactly 4 options (found " . count($question['options']) . ")";
            }
            
            // Check exactly one correct answer
            $correctCount = 0;
            foreach ($question['options'] as $option) {
                if ($option['checked']) {
                    $correctCount++;
                }
            }
            
            if ($correctCount !== 1) {
                $errors[] = "Question {$questionNum}: Must have exactly 1 correct answer (found {$correctCount})";
            }
        }
        
        if (!empty($errors)) {
            throw new \Exception("Quiz validation failed:\n" . implode("\n", $errors));
        }
        
        return $questions;
    }
    
    /**
     * Validate and parse flashcard format
     * 
     * Expected format:
     * ## Front
     * Question text...
     * ## Back
     * Answer text...
     * ---
     * ## Front
     * ...
     * 
     * @param string $content Markdown content from AI
     * @return array Array of flashcards with 'front' and 'back' keys
     * @throws \Exception If format is invalid
     */
    public static function validateLernmodulFormat(string $content): array
    {
        $pages = [];
        $errors = [];
        
        // Debug: Log the raw content to see what we're receiving
        error_log("=== VALIDATOR DEBUG ===");
        error_log("Raw content length: " . strlen($content));
        error_log("First 500 chars of content: " . substr($content, 0, 500));
        
        // Split by --- delimiter (try different variations)
        $page_blocks = preg_split('/\n---\n|\n---\s*\n|^---$|^---\s*$/m', $content);
        
        error_log("Number of blocks after split: " . count($page_blocks));
        
        foreach ($page_blocks as $idx => $block) {
            $block = trim($block);
            if (empty($block)) {
                continue;
            }
            
            error_log("Block " . ($idx + 1) . " first 200 chars: " . substr($block, 0, 200));
            
            // Check for ## Title and ## Content markers (try variations)
            $has_title_marker = preg_match('/##\s*(Title|title)/i', $block);
            $has_content = preg_match('/##\s*(Content|content)/i', $block);
            
            if (!$has_content) {
                $errors[] = "Page " . ($idx + 1) . ": Missing '## Content' marker";
                error_log("Page " . ($idx + 1) . " missing content marker");
                continue;
            }
            
            // Split by ## Content to get title and content sections
            $parts = preg_split('/##\s*(Content|content)/i', $block, 2);
            if (count($parts) !== 2) {
                $errors[] = "Page " . ($idx + 1) . ": Could not split title and content";
                continue;
            }
            
            // Extract title
            $title = '';
            if ($has_title_marker) {
                $title = preg_replace('/##\s*(Title|title)/i', '', $parts[0]);
                $title = trim($title);
            } else {
                // Fallback: accept first H2 heading (## ...) before content as title
                if (preg_match('/^##\s*(?!Content\b)(.+)$/mi', $parts[0], $m)) {
                    $title = trim($m[1]);
                } else {
                    $errors[] = "Page " . ($idx + 1) . ": Missing '## Title' marker";
                    error_log("Page " . ($idx + 1) . " missing title marker (no H2 heading found)");
                    continue;
                }
            }
            
            $content_text = trim($parts[1]);
            
            if (empty($title)) {
                $errors[] = "Page " . ($idx + 1) . ": Title is empty";
            }
            
            if (empty($content_text)) {
                $errors[] = "Page " . ($idx + 1) . ": Content is empty";
            }
            
            if (!empty($title) && !empty($content_text)) {
                $pages[] = [
                    'title' => $title,
                    'content' => $content_text
                ];
                error_log("Page " . ($idx + 1) . " successfully parsed: title=" . substr($title, 0, 50));
            }
        }
        
        error_log("Total valid pages parsed: " . count($pages));
        
        if (empty($pages)) {
            if (!empty($errors)) {
                throw new \Exception("Lernmodul validation failed:\n" . implode("\n", $errors));
            } else {
                throw new \Exception("No valid pages found in response");
            }
        }
        
        return $pages;
    }
}
