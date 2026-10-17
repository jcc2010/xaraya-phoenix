<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

/** A 422 whose JSON error body carries `fields`: {name: message}. */
final class ValidationFailed extends HttpException
{
    /** @param array<string, string> $fields field name => message */
    public function __construct(private readonly array $fields, string $message = 'The request has invalid fields')
    {
        parent::__construct(422, $message);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
