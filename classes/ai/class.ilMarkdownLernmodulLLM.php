<?php
declare(strict_types=1);

namespace ai;

use platform\ilMarkdownLernmodulException;

/**
 * Abstract base class for all LLM providers (Large Language Models)
 * 
 * Defines common interface for AI providers like GWDG, Google Gemini, and OpenAI.
 * Each provider must implement these methods.
 * 
 * Benefits:
 * - Unified interface for all LLM providers
 * - Easy to add new providers via inheritance
 * - Guaranteed consistent method signatures
 * 
 * Adding new providers:
 * ```php
 * class ilMarkdownLernmodulAnthropic extends ilMarkdownLernmodulLLM {
 *     public function generateLernmodul(...) { // Implementation }
 *     protected function buildPrompt(...) { // Implementation }
 * }
 * ```
 * 
 * @package ai
 * @abstract
 */
abstract class ilMarkdownLernmodulLLM
{
    /**
     * Generate learning module pages in Markdown format using AI
     * 
     * Sends request to LLM provider and returns Markdown-formatted learning module pages.
     * The AI automatically determines the optimal number of pages based on topic complexity.
     * 
     * Provider must:
     * 1. Format prompt correctly (see buildPrompt)
     * 2. Send API request
     * 3. Validate response
     * 4. Extract and return Markdown text
     * 
     * @param string $user_prompt Learning module topic (e.g., "Introduction to Photosynthesis")
     * 
     * @return string Generated pages in Markdown format with:
     *                - Page titles (## Title)
     *                - Page content (## Content)
     *                - Pages separated by ---
     * 
     * @throws ilMarkdownLernmodulException If API call fails, rate limit exceeded, or response invalid
     * 
     * @example
     * ```php
     * $llm = new ilMarkdownLernmodulGoogleAI();
     * $pages = $llm->generateLernmodul("Climate Change");
     * // Returns: "## Title\nIntroduction\n\n## Content\nClimate change refers to...\n\n---\n\n..."
     * ```
     */
    abstract public function generateLernmodul(string $user_prompt): string;

    /**
     * Build final prompt for LLM provider
     * 
     * Combines system instructions (from config) with user input into complete prompt.
     * Each provider has different prompt formats:
     * 
     * - **OpenAI**: Separate system/user messages in JSON
     * - **Google**: Combined prompt with Markdown structures
     * - **GWDG**: System prompt + user content separated
     * 
     * Typical prompt structure:
     * ```
     * System: "You are an educational content generator..."
     * User: "Create a comprehensive learning module about {topic}"
     * ```
     * 
     * @param string $user_prompt Learning module topic
     * 
     * @return string Fully formatted prompt for the API
     * 
     * @example
     * ```php
     * $prompt = $this->buildPrompt("Photosynthesis");
     * // OpenAI format:
     * // [
     * //   {"role": "system", "content": "..."},
     * //   {"role": "user", "content": "Create learning module about Photosynthesis..."}
     * // ]
     * ```
     */
    abstract protected function buildPrompt(string $user_prompt): string;
}

