<?php

declare(strict_types=1);

namespace Phue\Core\Database\Drivers;

use Phue\Core\Database\Drivers\Abstract\HttpDriverInterface;
use RuntimeException;

/**
 * Serverless HTTP Driver for Neon PostgreSQL.
 *
 * Executes PostgreSQL queries over Neon's serverless HTTP API (/sql) without pdo_pgsql.
 *
 * @package Phue\Core\Database\Drivers
 */
final class NeonDriver implements HttpDriverInterface
{
    /** @var string The target HTTP SQL endpoint URL. */
    private string $endpoint = '';

    /** @var string The Bearer authentication token. */
    private string $authToken = '';

    /**
     * Initializes the Neon Postgres HTTP driver.
     *
     * @param string|null $endpoint Database HTTP API endpoint (e.g., https://ep-proj-1234.neon.tech/sql).
     * @param string|null $authToken Bearer authentication token.
     */
    public function __construct(?string $endpoint = null, ?string $authToken = null)
    {
        if ($endpoint !== null) {
            $this->setEndpoint($endpoint);
        }
        if ($authToken !== null) {
            $this->setAuthToken($authToken);
        }
    }

    /**
     * Returns the target database engine dialect name.
     *
     * @return string Always returns 'pgsql' for PostgreSQL compatibility.
     */
    public function getName(): string
    {
        return 'pgsql';
    }

    /**
     * Sets the REST API endpoint URL for the Neon service.
     *
     * @param string $endpoint Fully qualified HTTP/HTTPS endpoint.
     * @return self
     */
    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = rtrim($endpoint, '/');
        if (!str_ends_with($this->endpoint, '/sql')) {
            $this->endpoint .= '/sql';
        }
        return $this;
    }

    /**
     * Sets the Bearer authentication token.
     *
     * @param string $token Bearer auth token.
     * @return self
     */
    public function setAuthToken(string $token): self
    {
        $this->authToken = $token;
        return $this;
    }

    /**
     * Executes a SELECT query over HTTP and returns the rows array.
     *
     * @param string $sql The SQL query string.
     * @param array<string|int, mixed> $params Bound parameter values.
     * @return array<int, array<string, mixed>> Result rows.
     */
    public function query(string $sql, array $params = []): array
    {
        $response = $this->sendSqlRequest($sql, $params);
        return $response['rows'] ?? [];
    }

    /**
     * Executes a write statement (INSERT, UPDATE, DELETE) over HTTP.
     *
     * @param string $sql The SQL query string.
     * @param array<string|int, mixed> $params Bound parameter values.
     * @return int Number of affected rows.
     */
    public function execute(string $sql, array $params = []): int
    {
        $response = $this->sendSqlRequest($sql, $params);
        return (int) ($response['rowCount'] ?? 0);
    }

    /**
     * Sends a query request to Neon HTTP SQL API.
     *
     * @param string $sql SQL query string.
     * @param array<string|int, mixed> $params Bound parameter values.
     * @return array<string, mixed> Decoded JSON response containing 'rows' and 'rowCount'.
     * @throws RuntimeException If endpoint/token are missing or HTTP request fails.
     */
    private function sendSqlRequest(string $sql, array $params): array
    {
        if (empty($this->endpoint) || empty($this->authToken)) {
            throw new RuntimeException('NeonDriver requires a valid endpoint and auth token.');
        }

        $payload = [
            'query' => $sql,
            'params' => array_values($params),
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->authToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 5,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false || $httpCode >= 400) {
            throw new RuntimeException("Neon HTTP request failed with code {$httpCode}: {$rawResponse}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $rawResponse, true);
        return $decoded;
    }
}