<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Module\Blog\Http\Api\ApiAccess;
use Xaraya\Module\Blog\Media\MediaUploader;
use Xaraya\Module\Tokens\Token;

/**
 * POST /micropub/media (scope media:write): the multipart field `file` is stored for the blog chosen as for a post
 * (mp-destination, the token's blog, or the only native blog); 201 with Location = the absolute media URL.
 * MicropubErrors turns the uploader's 413 and 415 into 400 invalid_request.
 */
final class MicropubMediaController extends Controller
{
    public function __construct(
        private readonly MediaUploader $uploader,
        private readonly Destination $destination,
        private readonly Access $access,
    ) {}

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $form = $request->getParsedBody();
        $uid = is_array($form) && is_string($form['mp-destination'] ?? null) && $form['mp-destination'] !== '' ? $form['mp-destination'] : null;
        $blog = $this->destination->choose($uid, ApiAccess::allowCreate(Token::fromRequest($request), $this->access));
        $row = $this->uploader->upload($blog, $request, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return new Response(201, ['Location' => $this->uploader->url($row), 'Cache-Control' => 'no-store']);
    }
}
