<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Throw this anywhere to stop and return an HTTP error as JSON:
 *   throw new HttpException(404, 'Beat not found');
 *   throw new HttpException(422, 'Invalid input', ['phone' => 'Required']);
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self(404, $what . ' not found');
    }

    public static function forbidden(string $message = 'You are not allowed to do this'): self
    {
        return new self(403, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, $message);
    }

    public static function invalid(string $message, array $errors = []): self
    {
        return new self(422, $message, $errors);
    }
}
