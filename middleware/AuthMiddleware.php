<?php

namespace Middleware;

use Monolog\Logger;
use Medoo\Medoo;
use Core\Services\CacheService;
use Core\Helpers\MiddlewareHelper;
use Symfony\Component\HttpFoundation\JsonResponse;

class AuthMiddleware
{
    private Logger $logger;
    private Medoo $db;
    private CacheService $cache;

    private int $maxAttempts = 5;
    private int $blockDuration;
    private int $rateLimit = 100;
    private int $rateLimitDuration = 60;

    public function __construct(Logger $logger, Medoo $db, CacheService $cache)
    {
        $this->logger = $logger;
        $this->db = $db;
        $this->cache = $cache;
        $this->blockDuration = (int) ($_ENV['IP_BLOCK_DURATION'] ?? 3600);
        $this->maxAttempts = (int) ($_ENV['MAX_INVALID_ATTEMPTS'] ?? 5);
    }

    public function handle($request): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $jwtSecret = $_ENV['JWT_SECRET'] ?? '';

        if (empty($jwtSecret)) {
            $this->logger->info("AuthMiddleware: Public access allowed (JWT_SECRET is empty).", [
                'ip' => $ip,
                'user_agent' => $userAgent
            ]);
            return;
        }

        // Rate limiting
        $rateKey = MiddlewareHelper::sanitizeCacheKey("ratelimit", $ip);
        $rateCount = $this->cache->increment($rateKey, $this->rateLimitDuration);
        if ($rateCount > $this->rateLimit) {
            $this->reject(429, 'rate_limit_exceeded', "Rate limit exceeded.", ['count' => $rateCount]);
        }

        // IP blocked
        $blockedKey = MiddlewareHelper::sanitizeCacheKey("blocked_ip", $ip);
        if ($this->cache->get($blockedKey)) {
            $this->reject(403, 'ip_blocked', "IP blocked.");
        }

        // Headers
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        $clientApiKey = $headers['x-api-key']
            ?? $_SERVER['HTTP_X_API_KEY']
            ?? null;

        // Validate presence of x-api-key and JWT_SECRET
        $this->assertRequired([
            'x-api-key' => $clientApiKey,
            'JWT_SECRET' => $jwtSecret,
        ]);

        // Public routes (require x-api-key)
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = preg_replace('#^/v\d+(/|$)#', '/', $uri);
        $publicRoutes = require __DIR__ . '/../routes/public-routes.php';

        if (in_array('all-routes', $publicRoutes) || in_array($uri, $publicRoutes)) {
            if ($clientApiKey !== $jwtSecret) {
                $this->reject(403, 'invalid_api_key', "Invalid x-api-key on public route.", [
                    'provided_api_key' => $clientApiKey
                ]);
            }
            $this->logger->info("AuthMiddleware: Public route accessed.", [
                'uri' => $uri,
                'ip' => $ip,
                'user_agent' => $userAgent
            ]);
            return;
        }

        // Validate x-api-key
        if ($clientApiKey !== $jwtSecret) {
            $this->reject(403, 'invalid_api_key', "Invalid x-api-key.", [
                'provided_api_key' => $clientApiKey
            ]);
        }

        // Validate Authorization header
        $this->assertRequired([
            'Authorization' => $authHeader,
        ]);

        if (stripos($authHeader, 'Bearer ') !== 0) {
            $this->incrementInvalidAttempt($ip, $authHeader, 'error');
        }

        $token = trim(substr($authHeader, 7));
        $tokenHash = hash('sha256', $token);
        $sessionKey = MiddlewareHelper::sanitizeCacheKey("session", $tokenHash);

        // Check session cache first
        $userCache = $this->cache->get($sessionKey);

        if ($userCache) {
            $usuario = $userCache;
        } else {
            $usuario = $this->fetchUserByToken($tokenHash);
            if (!$usuario) {
                $this->incrementInvalidAttempt($ip, $authHeader, 'error');
            }
            $this->cache->set($sessionKey, $usuario, 7200);
        }

        $_SERVER['AUTH_USER'] = $usuario;

        $this->logger->info("AuthMiddleware: Authentication successful.", [
            'user_id' => $usuario['id'],
            'user_email' => $usuario['email'],
            'ip' => $ip,
            'user_agent' => $userAgent
        ]);
    }

    private function incrementInvalidAttempt(string $ip, string $authHeader, string $uri): void
    {
        // Não contar tentativas inválidas no logout
        if ($uri === '/auth/logout') {
            $this->reject(401, 'token_invalid_or_revoked', "Invalid token on logout.");
        }

        $attemptKey = MiddlewareHelper::sanitizeCacheKey("attempts", $ip);
        $attempts = $this->cache->increment($attemptKey, 600);

        if ($attempts >= $this->maxAttempts) {
            $blockedKey = MiddlewareHelper::sanitizeCacheKey("blocked_ip", $ip);
            $this->cache->set($blockedKey, true, $this->blockDuration);
            $this->reject(403, 'ip_blocked', "IP blocked after max attempts.", [
                'attempts' => $attempts
            ]);
        }

        $this->reject(401, 'token_invalid_or_revoked', "Invalid token attempt.", [
            'attempts' => $attempts,
            'authorization_header' => $authHeader
        ]);
    }

    private function fetchUserByToken(string $tokenHash): ?array
    {
        return $this->db->get('user_tokens (t)', [
            "[>]users (u)" => ["t.user_id" => "id"]
        ], [
            'u.id',
            'u.name',
            'u.email',
            'u.role_id'
        ], [
            "t.token_hash" => $tokenHash,
            "t.revoked" => 0
        ]);
    }

    private function reject(int $status, string $error, string $logMessage, array $context = []): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI';

        $logContext = array_merge([
            'ip' => $ip,
            'user_agent' => $userAgent
        ], $context);

        $this->logger->warning("AuthMiddleware: {$logMessage}", $logContext);
        (new JsonResponse(['error' => $error], $status))->send();
        exit;
    }

    private function assertRequired(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null || trim((string) $value) === '') {
                $this->reject(400, 'missing_' . strtolower($key), "Missing or empty: {$key}");
            }
        }
    }
}
