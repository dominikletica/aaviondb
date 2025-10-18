<?php

declare(strict_types=1);

namespace AavionDB\Core;

use AavionDB\Core\Exceptions\CommandException;
use JsonSerializable;

/**
 * Value object encapsulating unified command responses across interfaces.
 */
final class CommandResponse implements JsonSerializable
{
    private string $status;

    private string $action;

    private string $message;

    /**
     * @var mixed
     */
    private $data;

    /**
     * @var array<string, mixed>
     */
    private array $meta;

    /**
     * @param mixed                     $data
     * @param array<string, mixed>      $meta
     */
    private function __construct(string $status, string $action, string $message, $data, array $meta = [])
    {
        $this->status = $status;
        $this->action = $action;
        $this->message = $message;
        $this->data = $data;
        $this->meta = $meta;
    }

    public static function success(string $action, $data = null, string $message = 'ok', array $meta = []): self
    {
        return new self('ok', $action, $message, $data, $meta);
    }

    public static function error(string $action, string $message, array $meta = []): self
    {
        return new self('error', $action, $message, null, $meta);
    }

    /**
     * @param mixed                $payload
     * @param array<string, mixed> $parameters
     */
    public static function fromPayload(string $action, $payload, array $parameters = []): self
    {
        if ($payload instanceof self) {
            return $payload;
        }

        if (\is_array($payload) && isset($payload['status'])) {
            $status = (string) $payload['status'];
            $message = isset($payload['message']) ? (string) $payload['message'] : ($status === 'ok' ? 'ok' : 'error');
            $data = $payload['data'] ?? null;
            $meta = $payload['meta'] ?? [];

            if (!\is_array($meta)) {
                throw new CommandException(sprintf('Meta information for command "%s" must be an array.', $action));
            }

            return new self($status, $action, $message, $data, $meta);
        }

        if ($payload === null) {
            return self::success($action, null);
        }

        if (\is_scalar($payload)) {
            return self::success($action, $payload);
        }

        if (\is_array($payload)) {
            return self::success($action, $payload);
        }

        return self::success($action, $payload, 'ok', [
            'parameters' => $parameters,
            'note' => 'Non-array payload returned; stored as-is.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'action' => $this->action,
            'message' => $this->message,
            'data' => $this->data,
            'meta' => $this->meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

