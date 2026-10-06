<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Domain exception that renders the agreed error envelope.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        /** @var array<string, array<int, string>> */
        public readonly array $errors = [],
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    public function toResponse(): array
    {
        $payload = [
            'success' => false,
            'message' => $this->getMessage(),
            'code' => $this->errorCode->value,
        ];

        if ($this->errors !== []) {
            $payload['errors'] = $this->errors;
        }

        return $payload;
    }

    public static function validation(string $message, array $errors = []): self
    {
        return new self(ErrorCode::ValidationError, $message, $errors);
    }

    public static function conflict(ErrorCode $code, string $message, array $context = []): self
    {
        return new self($code, $message, context: $context);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(ErrorCode::NotFound, $message);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self(ErrorCode::Unauthorized, $message);
    }
}
