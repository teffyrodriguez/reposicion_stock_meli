<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Console;

use Contabilium\ReposicionFull\Consumer\ConsumerConfig;
use Contabilium\ReposicionFull\Consumer\ConsumerWorker;
use Contabilium\ReposicionFull\Freshness\MlWeeklyWindow;
use Contabilium\ReposicionFull\Contracts\MercadoLibreClientInterface;
use Contabilium\ReposicionFull\Infrastructure\MercadoLibre\HttpMercadoLibreClient;
use Contabilium\ReposicionFull\Infrastructure\MercadoLibre\MockMercadoLibreClient;
use Contabilium\ReposicionFull\Infrastructure\MercadoLibre\SqlAccessTokenResolver;
use Contabilium\ReposicionFull\Infrastructure\Redis\PhpRedisCheckpointStore;
use Contabilium\ReposicionFull\Infrastructure\Redis\PhpRedisQueue;
use Contabilium\ReposicionFull\Infrastructure\Redis\PhpRedisTokenBucket;
use Contabilium\ReposicionFull\Infrastructure\Sql\SqlEligibleProductRepository;
use Contabilium\ReposicionFull\Infrastructure\Sql\SqlRecommendationRepository;
use Contabilium\ReposicionFull\Producer\ProducerConfig;
use Contabilium\ReposicionFull\Producer\ProducerJob;
use Contabilium\ReposicionFull\Support\SystemClock;
use PDO;
use Redis;

/** Arma productor y consumidor con Redis, SQL Server y el GET de Mercado Libre. */
final class App
{
    /** @param array<string, string> $file */
    private function __construct(
        private readonly array $file,
        private readonly string $root,
    ) {
    }

    public static function boot(string $root): self
    {
        return new self(Env::loadFile($root . DIRECTORY_SEPARATOR . '.env'), $root);
    }

    public function env(string $key, ?string $default = null): string
    {
        $process = getenv($key);

        return Env::pick($process === false ? false : $process, $this->file, $key, $default);
    }

    public function producer(ProducerConfig $config): ProducerJob
    {
        $this->assertDatabaseConfigured();
        $redis = $this->redis();

        return new ProducerJob(
            eligibleRepository: new SqlEligibleProductRepository($this->pdo()),
            queue: $this->queue($redis),
            checkpoint: new PhpRedisCheckpointStore($redis),
            weeklyWindow: $this->weeklyWindow(),
            clock: new SystemClock(),
            config: $config,
            logger: new StderrLogger(),
        );
    }

    public function consumer(ConsumerConfig $config): ConsumerWorker
    {
        $mock = $this->envFlag('ML_MOCK');
        $path = trim($this->env('ML_REPLENISHMENT_PATH', ''));
        if (!$mock) {
            if (!function_exists('curl_init')) {
                throw new \RuntimeException('Falta la extensión curl.');
            }
            if ($path === '') {
                throw new \RuntimeException(
                    'Falta ML_REPLENISHMENT_PATH en .env. Es la ruta del GET de recomendación, con {meLiUserProductId}.'
                );
            }
        }
        $this->assertDatabaseConfigured();

        $redis = $this->redis();
        $cola = $this->queue($redis);
        var_dump($cola);
        var_dump("USA MOCK", $mock);
        $pdo = $this->pdo();
        $rpm = (float) $this->env('ML_RESERVED_RPM', '25');
        if ($rpm <= 0) {
            throw new \RuntimeException('ML_RESERVED_RPM tiene que ser mayor a 0.');
        }

        return new ConsumerWorker(
            queue: $cola,
            recommendations: new SqlRecommendationRepository($pdo),
            meliClient: $this->meliClient($mock, $pdo, $path),
            rateLimiter: new PhpRedisTokenBucket(
                redis: $redis,
                maxTokens: $rpm,
                refillPerSecond: $rpm / 60,
            ),
            weeklyWindow: $this->weeklyWindow(),
            clock: new SystemClock(),
            config: $config,
            logger: new StderrLogger(),
        );
    }

    /**
     * @param array<string, string|array<int, string>> $opts
     */
    public static function optionInt(array $opts, string $name, int $fallback, int $min = 1): int
    {
        if (!isset($opts[$name])) {
            return $fallback;
        }

        $raw = $opts[$name];
        if (is_array($raw)) {
            $raw = (string) end($raw);
        }
        if (!is_string($raw) || preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < $min) {
            throw new \InvalidArgumentException("El parámetro --{$name} tiene que ser un entero mayor o igual a {$min}.");
        }

        return (int) $raw;
    }

    public function intEnv(string $key, int $default, int $min = 1): int
    {
        $raw = trim($this->env($key, (string) $default));
        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < $min) {
            throw new \RuntimeException($key . ' tiene que ser un entero mayor o igual a ' . $min . '.');
        }

        return (int) $raw;
    }

    private function meliClient(bool $mock, PDO $pdo, string $path): MercadoLibreClientInterface
    {
        $logger = new StderrLogger();
        if ($mock) {
            return new MockMercadoLibreClient($this->mockFile(), $logger);
        }

        $country = trim($this->env('ML_COUNTRY', ''));
        if ($country === '') {
            throw new \RuntimeException(
                'Falta ML_COUNTRY en .env. Se envía como parámetro country en el GET de recomendación.'
            );
        }

        return new HttpMercadoLibreClient(
            tokens: new SqlAccessTokenResolver($pdo),
            baseUrl: $this->env('ML_API_BASE', 'https://api.mercadolibre.com'),
            pathTemplate: $path,
            country: $country,
            timeoutSeconds: $this->intEnv('ML_HTTP_TIMEOUT', 20, 1),
        );
    }

    private function mockFile(): string
    {
        $configured = trim($this->env('ML_MOCK_FILE', 'fixtures/meli-replenishment.json'));
        if ($configured === '') {
            $configured = 'fixtures/meli-replenishment.json';
        }
        if (str_starts_with($configured, '/') || str_starts_with($configured, '\\') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $configured) === 1) {
            return $configured;
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);
    }

    private function envFlag(string $key): bool
    {
        return in_array(strtolower(trim($this->env($key, '0'))), ['1', 'true', 'yes', 'on'], true);
    }

    private function redis(): Redis
    {
        if (!class_exists(Redis::class)) {
            throw new \RuntimeException('Falta la extensión phpredis (php_redis).');
        }

        $redis = new Redis();
        $host = $this->env('REDIS_HOST', '127.0.0.1');
        $port = $this->intEnv('REDIS_PORT', 6379, 1);
        $timeout = (float) $this->env('REDIS_TIMEOUT', '2.5');

        try {
            $connected = $redis->connect($host, $port, $timeout);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'No se pudo conectar a Redis en ' . $host . ':' . $port . ': ' . $e->getMessage(),
                0,
                $e,
            );
        }
        if ($connected !== true) {
            throw new \RuntimeException('No se pudo conectar a Redis en ' . $host . ':' . $port . '.');
        }

        $username = $this->env('REDIS_USERNAME', '');
        $password = $this->env('REDIS_PASSWORD', '');
        if ($username !== '') {
            $authed = $redis->auth([$username, $password]);
        } elseif ($password !== '') {
            $authed = $redis->auth($password);
        } else {
            $authed = true;
        }
        if (!$authed) {
            throw new \RuntimeException('Redis rechazó la autenticación.');
        }

        $database = $this->intEnv('REDIS_DB', 0, 0);
        if ($database !== 0 && !$redis->select($database)) {
            throw new \RuntimeException('No se pudo seleccionar la base Redis ' . $database . '.');
        }

        return $redis;
    }

    private function pdo(): PDO
    {
        $dsn = trim($this->env('DB_DSN', ''));
        if ($dsn === '') {
            throw new \RuntimeException('Falta DB_DSN en .env.');
        }
        if (str_starts_with($dsn, 'sqlsrv:') && !extension_loaded('pdo_sqlsrv')) {
            throw new \RuntimeException('Falta la extensión pdo_sqlsrv.');
        }

        $user = $this->env('DB_USER', '');
        $password = $this->env('DB_PASSWORD', '');

        try {
            return new PDO($dsn, $user === '' ? null : $user, $password === '' ? null : $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('No se pudo conectar a SQL Server: ' . $e->getMessage(), 0, $e);
        }
    }

    private function queue(Redis $redis): PhpRedisQueue
    {
        return new PhpRedisQueue(
            redis: $redis,
            dedupTtlSeconds: $this->intEnv('REDIS_DEDUP_TTL', 1800, 1),
            visibilityTimeoutSeconds: $this->intEnv('CONSUMER_VISIBILITY_TIMEOUT', 120, 1),
        );
    }

    private function weeklyWindow(): MlWeeklyWindow
    {
        $hour = $this->intEnv('ML_REFRESH_HOUR', 0, 0);
        $minute = $this->intEnv('ML_REFRESH_MINUTE', 0, 0);
        if ($hour > 23 || $minute > 59) {
            throw new \RuntimeException('ML_REFRESH_HOUR va de 0 a 23 y ML_REFRESH_MINUTE de 0 a 59.');
        }

        $timezone = $this->env('ML_REFRESH_TIMEZONE', 'America/Argentina/Buenos_Aires');
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \RuntimeException('ML_REFRESH_TIMEZONE no es una zona horaria válida.');
        }

        return new MlWeeklyWindow(
            timezone: $timezone,
            refreshHour: $hour,
            refreshMinute: $minute,
        );
    }

    private function assertDatabaseConfigured(): void
    {
        if (trim($this->env('DB_DSN', '')) === '') {
            throw new \RuntimeException('Falta DB_DSN en .env. Copiá .env.example a .env y completá la conexión.');
        }
    }
}
