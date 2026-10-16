<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector;

use ITuoiProfessionistiDigitali\Connector\Exceptions\PlatformNotConfiguredException;

/**
 * The package configuration, typed once instead of read as mixed everywhere.
 */
final readonly class ConnectorConfig
{
    public function __construct(
        public ?string $url = null,
        public ?string $clientId = null,
        public ?string $clientSecret = null,
        public ?string $signingSecret = null,
        public ?string $webhookPath = 'platform/webhook',
        public bool $replyToPings = true,
        public ?string $queue = null,
        public int $timeout = 10,
        public int $retries = 2,
        public bool $validatePayloads = true,
        public int $catalogTtl = 3600,
        public int $fileTimeout = 120,
        public string $filesDisk = 'local',
    ) {}

    /**
     * @param  array<mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            url: self::string($config, 'url'),
            clientId: self::string($config, 'client_id'),
            clientSecret: self::string($config, 'client_secret'),
            signingSecret: self::string($config, 'signing_secret'),
            webhookPath: self::string($config, 'webhook_path'),
            replyToPings: (bool) ($config['reply_to_pings'] ?? true),
            queue: self::string($config, 'queue'),
            timeout: self::integer($config, 'timeout', 10),
            retries: self::integer($config, 'retries', 2),
            validatePayloads: (bool) ($config['validate_payloads'] ?? true),
            catalogTtl: self::integer($config, 'catalog_ttl', 3600),
            fileTimeout: self::integer($config, 'file_timeout', 120),
            filesDisk: self::string($config, 'files_disk') ?? 'local',
        );
    }

    public function isConnected(): bool
    {
        return $this->url !== null && $this->clientId !== null && $this->clientSecret !== null;
    }

    /**
     * @throws PlatformNotConfiguredException
     */
    public function apiUrl(string $path): string
    {
        return $this->baseUrl().Contract::API_PREFIX.'/'.mb_ltrim($path, '/');
    }

    /**
     * @throws PlatformNotConfiguredException
     */
    public function tokenUrl(): string
    {
        return $this->baseUrl().'/oauth/token';
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function string(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function integer(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @throws PlatformNotConfiguredException
     */
    private function baseUrl(): string
    {
        if (!$this->isConnected())
        {
            throw new PlatformNotConfiguredException;
        }

        return mb_rtrim((string) $this->url, '/');
    }
}
