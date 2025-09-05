<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Recorder;

use InvalidArgumentException;

/**
 * Represents a single recorded action with all its metadata
 */
final readonly class ActionData
{
    public function __construct(
        public string $type,
        public array $data,
        public int $timestamp,
        public string $url,
        public string $sessionId,
        public int $sequence,
        public ?array $viewport,
        public array $metadata
    ) {
    }

    /**
     * Convert to array format for serialization
     * 
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'data' => $this->data,
            'timestamp' => $this->timestamp,
            'url' => $this->url,
            'sessionId' => $this->sessionId,
            'sequence' => $this->sequence,
            'viewport' => $this->viewport,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create from array format
     * 
     * @param array<string, mixed> $data Array data
     * @return self
     * @throws InvalidArgumentException If required fields are missing
     */
    public static function fromArray(array $data): self
    {
        $required = ['type', 'data', 'timestamp', 'sessionId', 'sequence'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        return new self(
            type: (string)$data['type'],
            data: is_array($data['data']) ? $data['data'] : [],
            timestamp: (int)$data['timestamp'],
            url: (string)($data['url'] ?? ''),
            sessionId: (string)$data['sessionId'],
            sequence: (int)$data['sequence'],
            viewport: is_array($data['viewport']) ? $data['viewport'] : null,
            metadata: is_array($data['metadata']) ? $data['metadata'] : []
        );
    }
}
