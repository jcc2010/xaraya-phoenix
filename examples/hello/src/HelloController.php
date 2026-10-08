<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Support\Ulid;

final class HelloController extends Controller
{
    public function __construct(private readonly Connection $db, private readonly EventDispatcher $events) {}

    /** @param array<string, string> $params */
    public function index(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $items = '';
        foreach ($this->names() as $name) {
            $items .= '<li>Hello, ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '!</li>';
        }

        return $this->html("<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>Hello</title></head><body><ul>{$items}</ul></body></html>\n");
    }

    /** @param array<string, string> $params */
    public function feed(ServerRequestInterface $request, array $params): ResponseInterface
    {
        return $this->json(['greetings' => $this->names()]);
    }

    /** @param array<string, string> $params */
    public function create(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $id = Ulid::generate();
        $this->db->insert('greetings', ['id' => $id, 'name' => $params['name'], 'created' => new DateTimeImmutable()]);
        $this->events->dispatch(new ItemCreated('hello', 'greeting', $id, ['name' => $params['name']]));

        return $this->json(['id' => $id], 201);
    }

    /** @return list<string> */
    private function names(): array
    {
        $rows = $this->db->select('greetings')->columns('name')->orderBy('created')->orderBy('id')->all();

        return array_map(fn(array $row): string => (string) $row['name'], $rows);
    }
}
