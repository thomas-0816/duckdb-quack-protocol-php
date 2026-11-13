<?php

namespace Quack\Protocol\Error;

/**
 * A server error, including metadata supplied by newer protocol
 * implementations.
 *
 * Messages and metadata are untrusted and may contain SQL, credentials, or
 * server paths. This class does not sanitize them; callers must apply their own
 * disclosure policy.
 */
final class ServerError
{
    private const RETIRE_CONNECTION_MESSAGES = [
        'Invalid connection id',
        'Connection does not exist / already disconnected',
    ];

    private ?string $exceptionType = null;

    /** @var array<string,string> */
    private array $extraInfo = [];

    private bool $mustInvalidate = false;

    private function __construct(private readonly string $message)
    {
    }

    public static function new(string $message): self
    {
        return new self($message);
    }

    public function withExceptionType(?string $exceptionType): self
    {
        $this->exceptionType = ($exceptionType === null || $exceptionType === '')
            ? null
            : $exceptionType;
        return $this;
    }

    public function withExtraInfo(string $key, string $value): self
    {
        $this->extraInfo[$key] = $value;
        ksort($this->extraInfo, SORT_STRING);
        return $this;
    }

    public function withInvalidation(bool $mustInvalidate): self
    {
        $this->mustInvalidate = $mustInvalidate;
        return $this;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function exceptionType(): ?ExceptionType
    {
        return $this->exceptionType === null
            ? null
            : ExceptionType::from($this->exceptionType);
    }

    public function exceptionTypeRaw(): ?string
    {
        return $this->exceptionType;
    }

    /** @return array<string,string> */
    public function extraInfo(): array
    {
        return $this->extraInfo;
    }

    public function errorSubtype(): ?ErrorSubtype
    {
        $raw = $this->errorSubtypeRaw();
        return $raw === null ? null : ErrorSubtype::from($raw);
    }

    public function errorSubtypeRaw(): ?string
    {
        return $this->extraInfo['error_subtype'] ?? null;
    }

    public function mustInvalidate(): bool
    {
        return $this->mustInvalidate;
    }

    /**
     * Whether the affected session must be retired. This says nothing about
     * whether a query may be replayed.
     */
    public function isFatal(): bool
    {
        if ($this->mustInvalidate) {
            return true;
        }
        $type = $this->exceptionType();
        if ($type !== null && ($type->is('Fatal') || $type->is('Internal'))) {
            return true;
        }
        foreach (['FATAL Error:', 'Fatal Error:', 'INTERNAL Error:', 'Internal Error:'] as $prefix) {
            if (str_starts_with($this->message, $prefix)) {
                return true;
            }
        }
        return in_array($this->message, self::RETIRE_CONNECTION_MESSAGES, true);
    }

    public function __toString(): string
    {
        return $this->message;
    }
}
