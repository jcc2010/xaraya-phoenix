<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Kernel\View\Page;

final class HelloController extends Controller
{
    public function __construct(
        private readonly Connection $db,
        private readonly EventDispatcher $events,
        private readonly DisplayHooks $hooks,
    ) {}

    /** @param array<string, string> $params */
    public function index(ServerRequestInterface $request, array $params): Page
    {
        return $this->view('hello::index', ['greetings' => $this->greetings()], 'Hello');
    }

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): Page
    {
        $row = $this->db->select('greetings')->where('id', '=', $params['id'])->first() ?? $this->notFound('No such greeting');
        $greeting = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'created' => (string) $row['created']];

        return $this->view('hello::show', ['greeting' => $greeting], 'Hello, ' . $greeting['name']);
    }

    /** @param array<string, string> $params */
    public function feed(ServerRequestInterface $request, array $params): ResponseInterface
    {
        return $this->json(['greetings' => array_column($this->greetings(), 'name')]);
    }

    /** @param array<string, string> $params */
    public function create(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $id = $this->insert($params['name']);
        $body = $request->getParsedBody();
        /** @var array<string, mixed> $input */
        $input = is_array($body) ? $body : [];
        $this->hooks->call('item.form.save', ['module' => 'hello', 'itemtype' => 'greeting', 'id' => $id, 'name' => $params['name']], $input);

        return $this->json(['id' => $id], 201);
    }

    /** Administrators only (route middleware auth:hello.greeting.admin). */
    public function admin(): Page
    {
        return $this->view('hello::admin', ['count' => count($this->greetings())], 'Greetings admin');
    }

    /** POST /api/hello/greetings with {"name": "ada"}: needs a bearer token with the hello:write scope. */
    public function apiCreate(ServerRequestInterface $request): ResponseInterface
    {
        $input = json_decode((string) $request->getBody(), true);
        $name = is_array($input) ? ($input['name'] ?? null) : null;
        if (!is_string($name) || preg_match('/^[a-z]{1,64}$/D', $name) !== 1) {
            throw new ValidationFailed(['name' => 'Must be 1 to 64 lower-case letters']);
        }

        return $this->json(['id' => $this->insert($name)], 201);
    }

    private function insert(string $name): string
    {
        $id = Ulid::generate();
        $this->db->insert('greetings', ['id' => $id, 'name' => $name, 'created' => new DateTimeImmutable()]);
        $this->events->dispatch(new ItemCreated('hello', 'greeting', $id, ['name' => $name]));

        return $id;
    }

    /** @return list<array{id: string, name: string}> */
    private function greetings(): array
    {
        $rows = $this->db->select('greetings')->columns('id', 'name')->orderBy('created')->orderBy('id')->all();

        return array_map(static fn(array $row): array => ['id' => (string) $row['id'], 'name' => (string) $row['name']], $rows);
    }
}
