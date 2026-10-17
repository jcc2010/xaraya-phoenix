<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Http\Api\JsonBody;

/**
 * A Micropub POST, form-encoded or JSON, normalised: the h type ("entry"), the action and its url, mf2 properties
 * (every value a list) and the mp-* commands. JSON update bodies keep their replace/add/delete objects in $json.
 */
final class MicropubInput
{
    /**
     * @param array<string, list<mixed>> $properties
     * @param array<string, list<mixed>> $commands
     * @param array<string, mixed> $json the decoded JSON body ([] for a form)
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $action,
        public readonly ?string $url,
        public readonly array $properties,
        public readonly array $commands,
        public readonly array $json,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $mediaType = self::mediaType($request);
        if ($mediaType === 'application/json') {
            return self::fromJson(JsonBody::decode($request, ['application/json']));
        }
        if ($mediaType === 'application/x-www-form-urlencoded' || $mediaType === 'multipart/form-data') {
            $form = $request->getParsedBody();

            return self::fromForm(is_array($form) ? $form : []);
        }
        throw new HttpException(415, 'Send application/x-www-form-urlencoded, multipart/form-data or application/json');
    }

    public static function mediaType(ServerRequestInterface $request): string
    {
        return strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
    }

    /** The first value of an mp-* command, when it is a string. */
    public function command(string $name): ?string
    {
        $value = $this->commands[$name][0] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<array-key, mixed> $form */
    private static function fromForm(array $form): self
    {
        $type = 'entry';
        $action = $url = null;
        $properties = $commands = [];
        foreach ($form as $key => $value) {
            $key = (string) $key;
            match (true) {
                $key === 'h' => $type = self::string($value, 'h'),
                $key === 'action' => $action = self::string($value, 'action'),
                $key === 'url' => $url = self::string($value, 'url'),
                str_starts_with($key, 'mp-') => $commands[$key] = self::values($value),
                default => $properties[$key] = self::values($value),
            };
        }

        return new self($type, $action, $url, $properties, $commands, []);
    }

    /** @param array<string, mixed> $json */
    private static function fromJson(array $json): self
    {
        $type = 'entry';
        if (array_key_exists('type', $json)) {
            $types = $json['type'];
            $first = is_array($types) && array_is_list($types) ? ($types[0] ?? null) : null;
            if (!is_string($first) || !str_starts_with($first, 'h-')) {
                throw new HttpException(400, 'type must be a list like ["h-entry"]');
            }
            $type = substr($first, 2);
        }
        $properties = $commands = [];
        $raw = $json['properties'] ?? [];
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new HttpException(400, 'properties must be an object');
        }
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (!is_array($value) || !array_is_list($value)) {
                throw new HttpException(400, "Property {$key} must be a list of values");
            }
            str_starts_with($key, 'mp-') ? $commands[$key] = $value : $properties[$key] = $value;
        }
        $action = isset($json['action']) ? self::string($json['action'], 'action') : null;
        $url = isset($json['url']) ? self::string($json['url'], 'url') : null;

        return new self($type, $action, $url, $properties, $commands, $json);
    }

    private static function string(mixed $value, string $name): string
    {
        if (!is_string($value) || $value === '') {
            throw new HttpException(400, "{$name} must be a string");
        }

        return $value;
    }

    /** @return list<mixed> a form value as an mf2 value list: "x" => ["x"], x[]=a&x[]=b => ["a","b"], x[html]=… => [{html: …}] */
    private static function values(mixed $value): array
    {
        if (is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }

        return [$value];
    }
}
