<?php

declare(strict_types=1);

namespace Phue\Core\I18n;

/**
 * Represents a snapshot of the translation catalog.
 */
final readonly class CatalogSnapshot
{
    /**
     * @param string $locale
     * @param array<string, string> $messages
     */
    public function __construct(
        private string $locale,
        private array $messages,
    ) {
    }

    /**
     * Returns the locale of the catalog snapshot.
     * @return string
     */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Returns the messages of the catalog snapshot.
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * Returns the payload for the catalog snapshot.
     * @return array{locale: string, messages: array<string, string>}
     */
    public function toPayload(): array
    {
        return [
            'locale' => $this->locale,
            'messages' => $this->messages,
        ];
    }
}