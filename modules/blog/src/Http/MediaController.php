<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Module\Blog\Media\MediaStore;

final class MediaController extends Controller
{
    public function __construct(private readonly MediaStore $store) {}

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $row = $this->store->find($params['id']);
        if ($row === null || (MediaStore::TYPES[(string) $row['mime']] ?? null) !== $params['ext']) {
            throw new NotFound();
        }
        $path = $this->store->absolutePath($row);
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new NotFound();
        }

        $headers = [
            'Content-Type' => (string) $row['mime'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];
        $size = filesize($path);
        if ($size !== false) {
            $headers['Content-Length'] = (string) $size;
        }

        return new Response(200, $headers, Stream::create($handle));
    }
}
