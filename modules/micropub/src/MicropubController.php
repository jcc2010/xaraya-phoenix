<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Module\Tokens\Token;

/** POST /micropub. Every Micropub action maps onto PostWriter and needs the blog:write scope. */
final class MicropubController extends Controller
{
    public function __construct(
        private readonly PostWriter $writer,
        private readonly Mf2Mapper $mapper,
        private readonly Destination $destination,
        private readonly ItemSerializer $items,
        private readonly Config $config,
    ) {}

    public function post(ServerRequestInterface $request): ResponseInterface
    {
        $input = MicropubInput::fromRequest($request);

        return match ($input->action) {
            null, 'create' => $this->create($request, $input),
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

    private function appUrl(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }
}
