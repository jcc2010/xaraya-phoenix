<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http\Api;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Media\MediaUploader;
use Xaraya\Module\Blog\Write\PostWriter;

/** POST /api/blogs/{handle}/media (scope media:write): stores one upload, 201 with the absolute media URL. */
final class MediaApiController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly MediaUploader $uploader,
        private readonly Access $access,
    ) {}

    /** @param array<string, string> $params */
    public function upload(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $blog = $this->blogs->find($params['handle']) ?? throw new NotFound("Unknown blog '{$params['handle']}'");
        ApiAccess::allowCreate(ApiAccess::allow($request, $blog), $this->access);
        PostWriter::assertNative($blog);
        $row = $this->uploader->upload($blog, $request, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $url = $this->uploader->url($row);

        return $this->json([
            'url' => $url,
            'type' => (string) $row['mime'],
            'bytes' => (int) $row['bytes'],
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
        ], 201)->withHeader('Location', $url)->withHeader('Cache-Control', 'no-store');
    }
}
