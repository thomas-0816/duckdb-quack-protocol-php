<?php

declare(strict_types=1);

namespace Quack\Protocol\Messages;

final class MessageHeader
{
    public function __construct(
        public readonly MessageType $messageType,
        public readonly ?string $connectionId = null,
        public readonly int|string|null $clientQueryId = null,
    ) {
    }

    public static function new(MessageType $messageType): self
    {
        return new self($messageType);
    }

    public function withConnection(string $connectionId): self
    {
        return new self($this->messageType, $connectionId, $this->clientQueryId);
    }

    public function withClientQueryId(int|string $clientQueryId): self
    {
        return new self($this->messageType, $this->connectionId, $clientQueryId);
    }
}
