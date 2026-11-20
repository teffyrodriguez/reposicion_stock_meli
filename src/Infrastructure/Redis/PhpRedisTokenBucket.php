<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\Redis;

use Contabilium\ReposicionFull\Contracts\RateLimiterInterface;
use Redis;

/**
 * Token bucket compartido entre workers (Client ID ML).
 * Default ~25 RPM reservados al proceso (ajustable).
 */
final class PhpRedisTokenBucket implements RateLimiterInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly float $maxTokens = 25.0,
        private readonly float $refillPerSecond = 25.0 / 60.0,
        private readonly string $key = RedisKeys::RATE_LIMIT,
    ) {
    }

    public function tryAcquire(): bool
    {
        $script = <<<'LUA'
local key = KEYS[1]
local maxTokens = tonumber(ARGV[1])
local refillPerSecond = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

local data = redis.call('HMGET', key, 'tokens', 'updatedAt', 'penalizedUntil')
local tokens = tonumber(data[1])
local updatedAt = tonumber(data[2])
local penalizedUntil = tonumber(data[3]) or 0

if now < penalizedUntil then
  return 0
end

if tokens == nil then
  tokens = maxTokens
  updatedAt = now
end

local elapsed = math.max(0, now - updatedAt)
tokens = math.min(maxTokens, tokens + (elapsed * refillPerSecond))
updatedAt = now

if tokens < 1 then
  redis.call('HMSET', key, 'tokens', tokens, 'updatedAt', updatedAt, 'penalizedUntil', penalizedUntil)
  redis.call('EXPIRE', key, 3600)
  return 0
end

tokens = tokens - 1
redis.call('HMSET', key, 'tokens', tokens, 'updatedAt', updatedAt, 'penalizedUntil', penalizedUntil)
redis.call('EXPIRE', key, 3600)
return 1
LUA;

        $ok = $this->redis->eval(
            $script,
            [
                $this->key,
                (string) $this->maxTokens,
                (string) $this->refillPerSecond,
                (string) microtime(true),
            ],
            1
        );

        return (int) $ok === 1;
    }

    public function penalize(int $seconds = 5): void
    {
        $now = microtime(true);
        $this->redis->hMSet($this->key, [
            'tokens' => 0,
            'updatedAt' => (string) $now,
            'penalizedUntil' => (string) ($now + $seconds),
        ]);
        $this->redis->expire($this->key, 3600);
    }
}
