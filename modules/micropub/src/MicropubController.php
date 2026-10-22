<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Http\Api\ApiAccess;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Module\Tokens\Token;

/** POST /micropub and its GET queries. Every action maps onto PostWriter; all of them need the blog:write scope. */
final class MicropubController extends Controller
{
    public function __construct(
        private readonly PostWriter $writer,
        private readonly Mf2Mapper $mapper,
        private readonly Destination $destination,
        private readonly PostLocator $locator,
        private readonly BlogRepository $blogs,
        private readonly ItemSerializer $items,
        private readonly UrlGenerator $urls,
        private readonly Config $config,
        private readonly Access $access,
    ) {}

    /** GET /micropub?q=config|source|syndicate-to */
    public function query(ServerRequestInterface $request): ResponseInterface
    {
        $token = Token::fromRequest($request);
        parse_str($request->getUri()->getQuery(), $query);
        $q = $query['q'] ?? null;
        $body = match ($q) {
            'config' => [
                'media-endpoint' => $this->urls->generate('micropub.media', [], true),
                'destination' => array_map(static fn(Blog $blog): array => ['uid' => $blog->handle, 'name' => $blog->title], $this->destination->choices($token)),
                'syndicate-to' => [],
            ],
            'source' => $this->source($request, $query),
            'syndicate-to' => ['syndicate-to' => []],
            default => throw new HttpException(400, is_string($q) ? "Unknown query '" . mb_scrub($q, 'UTF-8') . "'" : 'Send q=config, q=source or q=syndicate-to'),
        };

        return $this->json($body)->withHeader('Cache-Control', 'no-store');
    }

    public function post(ServerRequestInterface $request): ResponseInterface
    {
        $input = MicropubInput::fromRequest($request);

        return match ($input->action) {
            null, 'create' => $this->create($request, $input),
            'update' => $this->update($request, $input),
            'delete' => $this->delete($request, $input),
            'undelete' => $this->undelete($request, $input),
            default => throw new HttpException(400, "Unknown action '{$input->action}'"),
        };
    }

    private function create(ServerRequestInterface $request, MicropubInput $input): ResponseInterface
    {
        if ($input->type !== 'entry') {
            throw new HttpException(400, 'Only h-entry posts are supported');
        }
        if ($request->getUploadedFiles() !== []) {
            throw new HttpException(400, 'Upload files to the media endpoint');
        }
        $blog = $this->destination->choose($input->command('mp-destination'), Token::fromRequest($request));
        $post = $this->writer->create($blog, $this->mapper->toItem($input->properties));

        return new Response(201, ['Location' => $this->items->url($blog, $post, $this->appUrl())]);
    }

    /**
     * action=update (JSON only): replace, add and delete operate on the post's h-entry properties (as q=source
     * shows them); only the touched properties are written back, and date_modified is set to now.
     */
    private function update(ServerRequestInterface $request, MicropubInput $input): ResponseInterface
    {
        if ($input->json === []) {
            throw new HttpException(400, 'Send updates as JSON');
        }
        [$blog, $post] = $this->locate($request, $input, 'edit');
        $current = $this->items->item($blog, $post, $this->appUrl());
        $properties = $this->mapper->fromItem($current, PostWriter::hasDerivedTitle($post));
        $touched = [];
        foreach (['replace', 'add'] as $operation) {
            foreach (self::operation($input->json, $operation) as $property => $values) {
                $property = (string) $property;
                if (!is_array($values) || !array_is_list($values)) {
                    throw new HttpException(400, "{$operation}.{$property} must be a list of values");
                }
                $properties[$property] = $operation === 'replace' ? $values : [...($properties[$property] ?? []), ...$values];
                $touched[] = $property;
            }
        }
        $delete = $input->json['delete'] ?? [];
        if (!is_array($delete)) {
            throw new HttpException(400, 'delete must be a list of properties or an object of values');
        }
        foreach ($delete as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value)) {
                    throw new HttpException(400, 'delete must list property names');
                }
                unset($properties[$value]);
                $touched[] = $value;
            } elseif (is_array($value)) {
                $properties[$key] = array_values(array_filter($properties[$key] ?? [], static fn(mixed $v): bool => !in_array($v, $value, true)));
                $touched[] = $key;
            } else {
                throw new HttpException(400, "delete.{$key} must be a list of values");
            }
        }
        $patch = $this->mapper->patchFor($properties, $touched, $current);
        $patch['date_modified'] = gmdate(DATE_ATOM);
        $this->writer->patch($post, $patch);

        return new Response(204);
    }

    private function delete(ServerRequestInterface $request, MicropubInput $input): ResponseInterface
    {
        [, $post] = $this->locate($request, $input, 'delete');
        $this->writer->delete($post);

        return new Response(204);
    }

    private function undelete(ServerRequestInterface $request, MicropubInput $input): ResponseInterface
    {
        [, $post] = $this->locate($request, $input, 'delete');
        $this->writer->undelete($post);

        return new Response(204);
    }

    /**
     * q=source: the post's h-entry, or only the requested properties[] (then without "type"). A deleted post is
     * "not found" here, as for a post that never existed.
     *
     * @param array<array-key, mixed> $query
     * @return array<string, mixed>
     */
    private function source(ServerRequestInterface $request, array $query): array
    {
        $url = $query['url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new HttpException(400, 'q=source needs a url');
        }
        [$blog, $post] = $this->find($request, $url);
        if ($post->status === 'deleted') {
            throw new HttpException(400, 'No post at ' . mb_scrub($url, 'UTF-8'));
        }
        $properties = $this->mapper->fromItem($this->items->item($blog, $post, $this->appUrl()), PostWriter::hasDerivedTitle($post));
        $wanted = $query['properties'] ?? null;
        if ($wanted === null) {
            return ['type' => ['h-entry'], 'properties' => (object) $properties];
        }
        $wanted = array_filter(is_array($wanted) ? $wanted : [$wanted], 'is_string');

        // An empty mf2 properties set is still a JSON object, never `[]`.
        return ['properties' => (object) array_intersect_key($properties, array_flip($wanted))];
    }

    /**
     * The post the url names and its blog, once the token may write to that blog and, for a token that acts as a
     * user, that user may $level ("edit" or "delete") the post, as in the admin.
     *
     * @return array{0: Blog, 1: Post}
     */
    private function locate(ServerRequestInterface $request, MicropubInput $input, string $level): array
    {
        if ($input->url === null) {
            throw new HttpException(400, "action={$input->action} needs a url");
        }
        [$blog, $post] = $this->find($request, $input->url);
        ApiAccess::allowPost($request, $blog, $post, $this->access, $level);

        return [$blog, $post];
    }

    /** @return array{0: Blog, 1: Post} */
    private function find(ServerRequestInterface $request, string $url): array
    {
        $post = $this->locator->find($url) ?? throw new HttpException(400, 'No post at ' . mb_scrub($url, 'UTF-8'));
        $blog = $this->blogs->findById($post->blogId) ?? throw new HttpException(400, 'No post at ' . mb_scrub($url, 'UTF-8'));
        ApiAccess::allow($request, $blog);

        return [$blog, $post];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<array-key, mixed>
     */
    private static function operation(array $json, string $name): array
    {
        $value = $json[$name] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new HttpException(400, "{$name} must be an object of properties");
        }

        return $value;
    }

    private function appUrl(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }
}
