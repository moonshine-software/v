<?php

declare(strict_types=1);

namespace App\ApiAdmin\ServiceConfig;

use Illuminate\Support\Facades\Log;

/**
 * DTO for per-panel administrator login mode settings.
 *
 * This is separate from ServiceConfig::$authType: auth_type describes how the
 * moonshine-v server talks to the upstream API, while admin_auth describes how
 * a human administrator logs into this panel.
 */
final readonly class AdminAuthConfig
{
    public const string MODE_MOONSHINE = 'moonshine';

    public const string MODE_DELEGATED_TOKEN = 'delegated_token';

    public const string MODE_OAUTH2_AUTHORIZATION_CODE = 'oauth2_authorization_code';

    /** @var list<string> */
    public const array MODES = [
        self::MODE_MOONSHINE,
        self::MODE_DELEGATED_TOKEN,
        self::MODE_OAUTH2_AUTHORIZATION_CODE,
    ];

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $mode = self::MODE_MOONSHINE,
        public string $validateEndpoint = '',
        public string $tokenUrl = '',
        public string $authorizeUrl = '',
        public array $scopes = [],
        public string $redirectUri = '',
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, string $service = ''): self
    {
        $mode = is_string($config['mode'] ?? null) ? trim($config['mode']) : self::MODE_MOONSHINE;
        if (! in_array($mode, self::MODES, true)) {
            Log::warning('AdminAuthConfig::fromArray invalid mode, falling back to moonshine', [
                'service' => $service,
                'mode' => $mode,
            ]);
            $mode = self::MODE_MOONSHINE;
        }

        $normalized = new self(
            mode: $mode,
            validateEndpoint: self::stringValue($config['validate_endpoint'] ?? null),
            tokenUrl: self::stringValue($config['token_url'] ?? null),
            authorizeUrl: self::stringValue($config['authorize_url'] ?? null),
            scopes: self::scopes($config['scopes'] ?? null),
            redirectUri: self::stringValue($config['redirect_uri'] ?? null),
        );

        Log::debug('AdminAuthConfig::fromArray normalized admin auth config', [
            'service' => $service,
            'mode' => $normalized->mode,
            'has_validate_endpoint' => $normalized->validateEndpoint !== '',
            'has_token_url' => $normalized->tokenUrl !== '',
            'has_authorize_url' => $normalized->authorizeUrl !== '',
            'scopes_count' => count($normalized->scopes),
            'has_redirect_uri' => $normalized->redirectUri !== '',
        ]);

        return $normalized;
    }

    /**
     * @return array{
     *     mode: string,
     *     validate_endpoint: string,
     *     token_url: string,
     *     authorize_url: string,
     *     scopes: list<string>,
     *     redirect_uri: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'validate_endpoint' => $this->validateEndpoint,
            'token_url' => $this->tokenUrl,
            'authorize_url' => $this->authorizeUrl,
            'scopes' => $this->scopes,
            'redirect_uri' => $this->redirectUri,
        ];
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<string>
     */
    private static function scopes(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s+/', trim($value)) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $scopes = [];
        foreach ($value as $scope) {
            if (! is_string($scope)) {
                continue;
            }

            $scope = trim($scope);
            if ($scope !== '') {
                $scopes[] = $scope;
            }
        }

        return $scopes
            |> array_unique(...)
            |> array_values(...);
    }
}
