<?php

namespace platform;

/**
 * Rate Limiter for MarkdownLernmodul Plugin
 * 
 * Implements session-based rate limiting:
 * - AI API calls: 20 per hour
 * - File processing: 20 per hour
 * - Quiz generation cooldown: 10 seconds
 * - Concurrent requests: max 3
 * 
 * Uses PHP sessions to track timestamps per user.
 * 
 * @package platform
 * @version 1.0.0
 */
class ilMarkdownLernmodulRateLimiter
{
    // Rate limit constants
    private const API_CALLS_PER_HOUR = 20;
    private const FILE_PROCESSING_PER_HOUR = 20;
    private const QUIZ_GENERATION_COOLDOWN_SECONDS = 10;
    private const MAX_CONCURRENT_REQUESTS = 3;
    
    // Session keys
    private const SESSION_PREFIX = 'mdquiz_ratelimit_';
    private const KEY_API_CALLS = 'api_calls';
    private const KEY_FILE_PROCESSING = 'file_processing';
    private const KEY_LAST_QUIZ_GEN = 'last_quiz_generation';
    private const KEY_CONCURRENT = 'concurrent_requests';
    
    /**
     * Initialize PHP session if not started
     */
    private static function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Get rate limit data from session
     * 
     * @param string $key Rate limit key
     * @return array [timestamps => int[], count => int]
     */
    private static function getRateLimitData(string $key): array
    {
        self::initSession();
        $session_key = self::SESSION_PREFIX . $key;
        
        if (!isset($_SESSION[$session_key])) {
            $_SESSION[$session_key] = [
                'timestamps' => [],
                'count' => 0
            ];
        }
        
        return $_SESSION[$session_key];
    }
    
    /**
     * Set rate limit data in session
     * 
     * @param string $key Rate limit key
     * @param array $data Rate limit data
     */
    private static function setRateLimitData(string $key, array $data): void
    {
        self::initSession();
        $session_key = self::SESSION_PREFIX . $key;
        $_SESSION[$session_key] = $data;
    }
    
    /**
     * Remove timestamps older than 1 hour
     * 
     * @param array $timestamps Unix timestamps
     * @return array Filtered timestamps
     */
    private static function cleanOldTimestamps(array $timestamps): array
    {
        $one_hour_ago = time() - 3600;
        return array_filter($timestamps, function($timestamp) use ($one_hour_ago) {
            return $timestamp > $one_hour_ago;
        });
    }
    
    /**
     * Check if API call is allowed (under rate limit)
     * 
     * @return bool True if under limit, false if exceeded
     */
    public static function checkApiCallLimit(): bool
    {
        $data = self::getRateLimitData(self::KEY_API_CALLS);
        $data['timestamps'] = self::cleanOldTimestamps($data['timestamps']);
        
        return count($data['timestamps']) < self::API_CALLS_PER_HOUR;
    }
    
    /**
     * Record API call timestamp
     * 
     * @throws \Exception If rate limit exceeded
     */
    public static function recordApiCall(): void
    {
        if (!self::checkApiCallLimit()) {
            $remaining_time = self::getTimeUntilReset(self::KEY_API_CALLS);
            throw new \Exception(
                "API rate limit exceeded. You can make " . self::API_CALLS_PER_HOUR . 
                " API calls per hour. Please wait " . $remaining_time . " minutes."
            );
        }
        
        $data = self::getRateLimitData(self::KEY_API_CALLS);
        $data['timestamps'][] = time();
        $data['count'] = count($data['timestamps']);
        self::setRateLimitData(self::KEY_API_CALLS, $data);
    }
    
    /**
     * Check if file processing is allowed
     * 
     * @return bool True if allowed, false if rate limit exceeded
     */
    public static function checkFileProcessingLimit(): bool
    {
        $data = self::getRateLimitData(self::KEY_FILE_PROCESSING);
        $data['timestamps'] = self::cleanOldTimestamps($data['timestamps']);
        
        return count($data['timestamps']) < self::FILE_PROCESSING_PER_HOUR;
    }
    
    /**
     * Record a file processing request
     * 
     * @throws \Exception If rate limit exceeded
     */
    public static function recordFileProcessing(): void
    {
        if (!self::checkFileProcessingLimit()) {
            $remaining_time = self::getTimeUntilReset(self::KEY_FILE_PROCESSING);
            throw new \Exception(
                "File processing rate limit exceeded. You can process " . 
                self::FILE_PROCESSING_PER_HOUR . " files per hour. Please wait " . 
                $remaining_time . " minutes."
            );
        }
        
        $data = self::getRateLimitData(self::KEY_FILE_PROCESSING);
        $data['timestamps'][] = time();
        $data['count'] = count($data['timestamps']);
        self::setRateLimitData(self::KEY_FILE_PROCESSING, $data);
    }
    
    /**
     * Check if learning module generation is allowed (cooldown check)
     * 
     * @return bool True if allowed, false if in cooldown
     */
    public static function checkQuizGenerationCooldown(): bool
    {
        self::initSession();
        $session_key = self::SESSION_PREFIX . self::KEY_LAST_QUIZ_GEN;
        
        if (!isset($_SESSION[$session_key])) {
            return true;
        }
        
        $last_generation = $_SESSION[$session_key];
        $time_elapsed = time() - $last_generation;
        
        return $time_elapsed >= self::QUIZ_GENERATION_COOLDOWN_SECONDS;
    }
    
    /**
     * Record a learning module generation
     * 
     * @throws \Exception If cooldown period not elapsed
     */
    public static function recordQuizGeneration(): void
    {
        if (!self::checkQuizGenerationCooldown()) {
            $remaining_seconds = self::getQuizGenerationCooldownRemaining();
            throw new \Exception(
                "Quiz generation cooldown active. Please wait " . 
                $remaining_seconds . " seconds before generating another quiz."
            );
        }
        
        self::initSession();
        $session_key = self::SESSION_PREFIX . self::KEY_LAST_QUIZ_GEN;
        $_SESSION[$session_key] = time();
    }
    
    /**
     * Get remaining learning module generation cooldown in seconds
     * 
     * @return int Remaining seconds (0 if no cooldown)
     */
    public static function getQuizGenerationCooldownRemaining(): int
    {
        self::initSession();
        $session_key = self::SESSION_PREFIX . self::KEY_LAST_QUIZ_GEN;
        
        if (!isset($_SESSION[$session_key])) {
            return 0;
        }
        
        $last_generation = $_SESSION[$session_key];
        $time_elapsed = time() - $last_generation;
        $remaining = self::QUIZ_GENERATION_COOLDOWN_SECONDS - $time_elapsed;
        
        return max(0, $remaining);
    }
    
    /**
     * Check if concurrent request limit is exceeded
     * 
     * @return bool True if allowed, false if too many concurrent requests
     */
    public static function checkConcurrentLimit(): bool
    {
        self::initSession();
        $session_key = self::SESSION_PREFIX . self::KEY_CONCURRENT;
        
        if (!isset($_SESSION[$session_key])) {
            $_SESSION[$session_key] = 0;
        }
        
        return $_SESSION[$session_key] < self::MAX_CONCURRENT_REQUESTS;
    }
    
    /**
     * Increment concurrent request counter
     * 
     * @throws \Exception If concurrent limit exceeded
     */
    public static function incrementConcurrent(): void
    {
        if (!self::checkConcurrentLimit()) {
            throw new \Exception(
                "Too many concurrent requests. Maximum " . 
                self::MAX_CONCURRENT_REQUESTS . " allowed."
            );
        }
        
        self::initSession();
        $session_key = self::SESSION_PREFIX . self::KEY_CONCURRENT;
        $_SESSION[$session_key] = ($_SESSION[$session_key] ?? 0) + 1;
    }
    
    /**
     * Decrement concurrent request counter
     */
    public static function decrementConcurrent(): void
    {
        self::initSession();
        $session_key = self::SESSION_PREFIX . self::KEY_CONCURRENT;
        
        if (isset($_SESSION[$session_key]) && $_SESSION[$session_key] > 0) {
            $_SESSION[$session_key]--;
        }
    }
    
    /**
     * Get time until rate limit resets (in minutes)
     * 
     * @param string $key Rate limit key
     * @return int Minutes until reset
     */
    private static function getTimeUntilReset(string $key): int
    {
        $data = self::getRateLimitData($key);
        
        if (empty($data['timestamps'])) {
            return 0;
        }
        
        $oldest_timestamp = min($data['timestamps']);
        $one_hour_later = $oldest_timestamp + 3600;
        $seconds_remaining = $one_hour_later - time();
        
        return max(0, (int)ceil($seconds_remaining / 60));
    }
    
    /**
     * Get current rate limit status for display
     * 
     * @return array Status information
     */
    public static function getStatus(): array
    {
        $api_data = self::getRateLimitData(self::KEY_API_CALLS);
        $api_data['timestamps'] = self::cleanOldTimestamps($api_data['timestamps']);
        
        $file_data = self::getRateLimitData(self::KEY_FILE_PROCESSING);
        $file_data['timestamps'] = self::cleanOldTimestamps($file_data['timestamps']);
        
        return [
            'api_calls' => [
                'used' => count($api_data['timestamps']),
                'limit' => self::API_CALLS_PER_HOUR,
                'remaining' => self::API_CALLS_PER_HOUR - count($api_data['timestamps']),
                'reset_in_minutes' => self::getTimeUntilReset(self::KEY_API_CALLS)
            ],
            'file_processing' => [
                'used' => count($file_data['timestamps']),
                'limit' => self::FILE_PROCESSING_PER_HOUR,
                'remaining' => self::FILE_PROCESSING_PER_HOUR - count($file_data['timestamps']),
                'reset_in_minutes' => self::getTimeUntilReset(self::KEY_FILE_PROCESSING)
            ],
            'quiz_generation' => [
                'cooldown_seconds' => self::QUIZ_GENERATION_COOLDOWN_SECONDS,
                'remaining_seconds' => self::getQuizGenerationCooldownRemaining(),
                'can_generate' => self::checkQuizGenerationCooldown()
            ],
            'concurrent' => [
                'current' => $_SESSION[self::SESSION_PREFIX . self::KEY_CONCURRENT] ?? 0,
                'limit' => self::MAX_CONCURRENT_REQUESTS
            ]
        ];
    }
    
    /**
     * Reset all rate limits for current user (admin/testing only)
     */
    public static function resetAll(): void
    {
        self::initSession();
        
        $keys = [
            self::KEY_API_CALLS,
            self::KEY_FILE_PROCESSING,
            self::KEY_LAST_QUIZ_GEN,
            self::KEY_CONCURRENT
        ];
        
        foreach ($keys as $key) {
            $session_key = self::SESSION_PREFIX . $key;
            unset($_SESSION[$session_key]);
        }
    }
}
