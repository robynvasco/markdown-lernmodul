<?php
declare(strict_types=1);

namespace security;

/**
 * Circuit Breaker Pattern for API Resilience
 * 
 * Prevents cascading failures by temporarily disabling failing services.
 * 
 * States:
 * - CLOSED: Normal operation
 * - OPEN: Service disabled (after 5 failures)
 * - HALF_OPEN: Testing recovery (after 60s timeout)
 * 
 * Thresholds:
 * - Open circuit after 5 failures
 * - Retry after 60 seconds
 * - Require 2 successes to close
 * 
 * @package security
 */
class ilMarkdownLernmodulCircuitBreaker
{
    // Circuit states
    private const STATE_CLOSED = 'closed';     // Normal operation
    private const STATE_OPEN = 'open';         // Service disabled due to failures
    private const STATE_HALF_OPEN = 'half_open'; // Testing if service recovered
    
    // Configuration
    private const FAILURE_THRESHOLD = 5;        // Failures before opening circuit
    private const TIMEOUT_SECONDS = 60;         // Time before attempting recovery
    private const SUCCESS_THRESHOLD = 2;        // Successes needed to close circuit
    
    /**
     * Record successful API call
     * 
     * In HALF_OPEN state: increments success counter, closes circuit after 2 successes
     * In CLOSED state: resets failure counter
     * 
     * @param string $service Service name (e.g., 'openai', 'google', 'gwdg')
     */
    public static function recordSuccess(string $service): void
    {
        if (!isset($_SESSION['mdquiz_circuit_breaker'])) {
            $_SESSION['mdquiz_circuit_breaker'] = [];
        }
        
        $state = self::getState($service);
        
        if ($state === self::STATE_HALF_OPEN) {
            // Increment success counter
            $_SESSION['mdquiz_circuit_breaker'][$service]['successes'] = 
                ($_SESSION['mdquiz_circuit_breaker'][$service]['successes'] ?? 0) + 1;
            
            // Close circuit if enough successes
            if ($_SESSION['mdquiz_circuit_breaker'][$service]['successes'] >= self::SUCCESS_THRESHOLD) {
                $_SESSION['mdquiz_circuit_breaker'][$service] = [
                    'state' => self::STATE_CLOSED,
                    'failures' => 0,
                    'successes' => 0,
                    'last_failure_time' => null
                ];
            }
        } elseif ($state === self::STATE_CLOSED) {
            // Reset failure counter on success
            $_SESSION['mdquiz_circuit_breaker'][$service]['failures'] = 0;
        }
    }
    
    /**
     * Record failed API call
     * 
     * In HALF_OPEN state: reopens circuit immediately
     * In CLOSED state: increments failure counter, opens after 5 failures
     * 
     * @param string $service Service name
     */
    public static function recordFailure(string $service): void
    {
        if (!isset($_SESSION['mdquiz_circuit_breaker'])) {
            $_SESSION['mdquiz_circuit_breaker'] = [];
        }
        
        if (!isset($_SESSION['mdquiz_circuit_breaker'][$service])) {
            $_SESSION['mdquiz_circuit_breaker'][$service] = [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'successes' => 0,
                'last_failure_time' => null
            ];
        }
        
        $state = self::getState($service);
        
        if ($state === self::STATE_HALF_OPEN) {
            // Failure during recovery - reopen circuit
            $_SESSION['mdquiz_circuit_breaker'][$service]['state'] = self::STATE_OPEN;
            $_SESSION['mdquiz_circuit_breaker'][$service]['last_failure_time'] = time();
            $_SESSION['mdquiz_circuit_breaker'][$service]['successes'] = 0;
        } elseif ($state === self::STATE_CLOSED) {
            // Increment failure counter
            $_SESSION['mdquiz_circuit_breaker'][$service]['failures']++;
            $_SESSION['mdquiz_circuit_breaker'][$service]['last_failure_time'] = time();
            
            // Open circuit if threshold exceeded
            if ($_SESSION['mdquiz_circuit_breaker'][$service]['failures'] >= self::FAILURE_THRESHOLD) {
                $_SESSION['mdquiz_circuit_breaker'][$service]['state'] = self::STATE_OPEN;
            }
        }
    }
    
    /**
     * Check if service is available
     * 
     * If OPEN: checks if timeout expired (60s), moves to HALF_OPEN if ready
     * 
     * @param string $service Service name
     * @throws \Exception If circuit is open and timeout not expired
     */
    public static function checkAvailability(string $service): void
    {
        $state = self::getState($service);
        
        if ($state === self::STATE_OPEN) {
            $lastFailure = $_SESSION['mdquiz_circuit_breaker'][$service]['last_failure_time'] ?? 0;
            $timeSinceFailure = time() - $lastFailure;
            
            // Check if timeout expired - move to half-open
            if ($timeSinceFailure >= self::TIMEOUT_SECONDS) {
                $_SESSION['mdquiz_circuit_breaker'][$service]['state'] = self::STATE_HALF_OPEN;
                $_SESSION['mdquiz_circuit_breaker'][$service]['successes'] = 0;
            } else {
                $remainingSeconds = self::TIMEOUT_SECONDS - $timeSinceFailure;
                throw new \Exception(
                    "Service '{$service}' is temporarily unavailable due to repeated failures. " .
                    "Please try again in {$remainingSeconds} seconds."
                );
            }
        }
    }
    
    /**
     * Get current circuit state
     * 
     * @param string $service Service name
     * @return string 'closed', 'open', or 'half_open'
     */
    public static function getState(string $service): string
    {
        if (!isset($_SESSION['mdquiz_circuit_breaker'][$service])) {
            return self::STATE_CLOSED;
        }
        
        return $_SESSION['mdquiz_circuit_breaker'][$service]['state'] ?? self::STATE_CLOSED;
    }
    
    /**
     * Get status for all services (admin dashboard)
     * 
     * @return array Service status with state, failures, successes, last_failure_time
     */
    public static function getStatus(): array
    {
        if (!isset($_SESSION['mdquiz_circuit_breaker'])) {
            return [];
        }
        
        $status = [];
        foreach ($_SESSION['mdquiz_circuit_breaker'] as $service => $data) {
            $status[$service] = [
                'state' => $data['state'] ?? self::STATE_CLOSED,
                'failures' => $data['failures'] ?? 0,
                'successes' => $data['successes'] ?? 0,
                'last_failure_time' => $data['last_failure_time'] ?? null
            ];
        }
        
        return $status;
    }
    
    /**
     * Reset circuit breaker for a service (admin function)
     */
    public static function reset(string $service): void
    {
        if (isset($_SESSION['mdquiz_circuit_breaker'][$service])) {
            unset($_SESSION['mdquiz_circuit_breaker'][$service]);
        }
    }
    
    /**
     * Reset all circuit breakers (admin function)
     */
    public static function resetAll(): void
    {
        $_SESSION['mdquiz_circuit_breaker'] = [];
    }
}
