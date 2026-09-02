<?php

declare(strict_types=1);

namespace Phue\Core\Database\Drivers;

use Phue\Core\Database\Drivers\Abstract\HttpDriverInterface;
use RuntimeException;

/**
 * Serverless HTTP Driver for Turso (libSQL at the Edge).
 *
 * Connects to Turso HTTP pipeline API (/v2/pipeline) without requiring pdo_sqlite.
 *
 * @package Phue\Core\Database\Drivers
 */
final class TursoDriver implements HttpDriverInterface
{
    /** @var string The target HTTP pipeline endpoint URL. */
    private string $endpoint = '';

    /** @var string The Bearer authentication token. */
    private string $authToken = '';

    /**
     * Initializes the Turso HTTP driver.
     *
     * @param string|null $endpoint Database HTTP API endpoint (e.g., https://db-org.turso.io/v2/pipeline).
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
     * @return string Always returns 'sqlite' for Turso libSQL compatibility.
     */
    public function getName(): string
    {
        return 'sqlite';
    }

    /**
     * Sets the REST API endpoint URL for the Turso service.
     *
     * @param string $endpoint Fully qualified HTTP/HTTPS endpoint.
     * @return self
     */
    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = rtrim($endpoint, '/');
        if (!str_contains($this->endpoint, '/v2/pipeline')) {
            $this->endpoint .= '/v2/pipeline';
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
     * Executes a SELECT query over HTTP and returns the parsed associative array.
     *
     * @param string $sql The SQL query string.
     * @param array<string|int, mixed> $params Bound parameter values.
     * @return array<int, array<string, mixed>> Mapped key-value result set.
     */
    public function query(string $sql, array $params = []): array
    {
        $response = $this->sendPipelineRequest($sql, $params);
        return $this->parseQueryResults($response);
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
        $response = $this->sendPipelineRequest($sql, $params);
        return $this->parseExecuteResults($response);
    }

    /**
     * Sends a POST request to the Turso v2 pipeline endpoint.
     *
     * @param string $sql SQL query string.
     * @param array<string|int, mixed> $params Bound parameter values.
     * @return array<string, mixed> Decoded JSON response.
     * @throws RuntimeException If endpoint/token are missing or HTTP request fails.
     */
    private function sendPipelineRequest(string $sql, array $params): array
    {
        if (empty($this->endpoint) || empty($this->authToken)) {
            throw new RuntimeException('TursoDriver requires a valid endpoint and auth token.');
        }

        $formattedParams = [];
        foreach ($params as $param) {
            if (is_int($param)) {
                $formattedParams[] = ['type' => 'integer', 'value' => (string) $param];
            } elseif (is_float($param)) {
                $formattedParams[] = ['type' => 'float', 'value' => $param];
            } elseif (is_null($param)) {
                $formattedParams[] = ['type' => 'null'];
            } else {
                $formattedParams[] = ['type' => 'text', 'value' => (string) $param];
            }
        }

        $payload = [
            'requests' => [
                [
                    'type' => 'execute',
                    'stmt' => [
                        'sql' => $sql,
                        'args' => $formattedParams,
                    ],
                ],
                ['type' => 'close'],
            ],
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
            throw new RuntimeException("Turso HTTP request failed with code {$httpCode}: {$rawResponse}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $rawResponse, true);
        return $decoded;
    }

    /**
     * Parses SELECT query response from Turso format into standard PHP key-value arrays.
     *
     * @param array<string, mixed> $response Raw JSON decoded response.
     * @return array<int, array<string, mixed>> Structured associative array rows.
     */
    private function parseQueryResults(array $response): array
    {
        $resultStmt = $response['results'][0]['response']['result'] ?? null;
        if ($resultStmt === null) {
            return [];
        }

        $cols = array_column($resultStmt['cols'] ?? [], 'name');
        $rows = $resultStmt['rows'] ?? [];

        $mapped = [];
        foreach ($rows as $row) {
            $record = [];
            foreach ($row as $index => $cell) {
                $colName = $cols[$index] ?? (string) $index;
                $record[$colName] = $cell['value'] ?? null;
            }
            $mapped[] = $record;
        }

        return $mapped;
    }

    /**
     * Parses EXECUTE response from Turso to return affected row count.
     *
     * @param array<string, mixed> $response Raw JSON decoded response.
     * @return int Number of affected rows.
     */
    private function parseExecuteResults(array $response): int
    {
        $resultStmt = $response['results'][0]['response']['result'] ?? null;
        if ($resultStmt === null) {
            return 0;
        }

        return (int) ($resultStmt['affected_row_count'] ?? 0);
    }
}