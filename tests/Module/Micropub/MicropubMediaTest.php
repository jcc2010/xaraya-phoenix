<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Tests\Module\Blog\Media\MediaStoreTest;

final class MicropubMediaTest extends MicropubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMicropub();
    }

    /**
     * @param array<string, UploadedFile> $files
     * @param array<string, string> $form
     */
    private function upload(array $files, array $form = [], ?string $token = null): ResponseInterface
    {
        $request = (new ServerRequest('POST', '/micropub/media'))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withUploadedFiles($files)
            ->withParsedBody($form);
        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token));
        }

        return $this->app()->handle($request);
    }

    private function file(string $bytes, string $name = 'photo.png', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, strlen($bytes), $error, $name, 'image/png');
    }

    private function png(): UploadedFile
    {
        return $this->file((string) base64_decode(MediaStoreTest::PNG, true));
    }

    public function testStoresTheFileAndPointsAtIt(): void
    {
        $response = $this->upload(['file' => $this->png()]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-hjkmnp-tv-z]{26}\.png$#', $location);
        self::assertCount(1, glob($this->tmp . '/uploads/notes/*.png') ?: []);
        self::assertSame(200, $this->app()->handle(new ServerRequest('GET', (string) parse_url($location, PHP_URL_PATH)))->getStatusCode());
    }

    public function testTheTokenMayBeAMultipartField(): void
    {
        self::assertSame(201, $this->upload(['file' => $this->png()], ['access_token' => $this->token], '')->getStatusCode());
    }

    public function testErrorsUseMicropubCodes(): void
    {
        self::assertMicropubError($this->upload(['file' => $this->file("plain text\n", 'a.jpg')]), 400, 'invalid_request');
        self::assertMicropubError($this->upload([]), 400, 'invalid_request', 'Upload one file in the multipart field "file"');
        self::assertMicropubError($this->upload(['file' => $this->png()], [], $this->token('blog:write')), 403, 'insufficient_scope', 'The token lacks the media:write scope');
        self::assertMicropubError($this->upload(['file' => $this->png()], ['mp-destination' => 'nope']), 400, 'invalid_request', "Unknown destination 'nope'");
        self::assertSame([], glob($this->tmp . '/uploads/notes/*') ?: []);
    }

    public function testTooLargeAndMirrorDestinationsAre400(): void
    {
        $this->blogs($this->app())->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        self::assertMicropubError($this->upload(['file' => $this->file('', 'big.png', UPLOAD_ERR_INI_SIZE)]), 400, 'invalid_request');
        self::assertMicropubError($this->upload(['file' => $this->png()], ['mp-destination' => 'src']), 400, 'invalid_request', "Unknown destination 'src'");
    }

    public function testAPinnedTokenCannotUploadToAnotherBlog(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'other', 'title' => 'Other']);
        $pinned = $this->token('blog:write,media:write', ['--blog=other']);
        self::assertSame(201, $this->upload(['file' => $this->png()], [], $pinned)->getStatusCode());
        self::assertCount(1, glob($this->tmp . '/uploads/other/*.png') ?: []);
        self::assertMicropubError($this->upload(['file' => $this->png()], ['mp-destination' => 'notes'], $pinned), 403, 'forbidden');
    }

    public function testAUserBoundTokenNeedsAddToUpload(): void
    {
        $this->app()->container()->get(UserRepository::class)->create('mo', 'mo@example.com', 'correct horse battery', 'Mo', 'active', []);
        $response = $this->upload(['file' => $this->png()], [], $this->token('media:write', ['--user=mo']));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], glob($this->tmp . '/uploads/notes/*') ?: []);
    }
}
