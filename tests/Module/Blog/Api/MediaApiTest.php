<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Api;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Tests\Module\Blog\Media\MediaStoreTest;

final class MediaApiTest extends ApiTestCase
{
    /** A valid 1x1 GIF. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $app = $this->enableTokens();
        $this->nativeBlog($app);
        $this->nativeBlog($app, ['handle' => 'other', 'title' => 'Other']);
        $this->token = $this->token('media:write');
    }

    /** Writes a fixture file into the test's temp dir and returns its path. */
    private function fixture(string $name, string $bytes): string
    {
        $path = $this->tmp . '/fixtures/' . $name;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $bytes);

        return $path;
    }

    /** @param array<string, mixed> $files */
    private function upload(array $files, ?string $token = null, string $handle = 'notes'): ResponseInterface
    {
        $request = (new ServerRequest('POST', "/api/blogs/{$handle}/media"))
            ->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withUploadedFiles($files);

        return $this->app()->handle($request);
    }

    private static function file(string $path, string $clientName = 'photo.png', string $clientType = 'image/png', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile($path, is_file($path) ? (int) filesize($path) : 0, $error, $clientName, $clientType);
    }

    private function png(): string
    {
        return $this->fixture('tiny.png', (string) base64_decode(MediaStoreTest::PNG, true));
    }

    private function mediaRows(): int
    {
        return (int) $this->db($this->app())->fetchValue('SELECT COUNT(*) FROM {media}');
    }

    /** @return list<string> every file under the uploads directory, dot files (temporary writes) included */
    private function uploadedFiles(): array
    {
        if (!is_dir($this->tmp . '/uploads')) {
            return [];
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tmp . '/uploads', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /** @return array<string, mixed> the error object of an API error response */
    private static function error(ResponseInterface $response): array
    {
        $error = self::json($response)['error'] ?? null;
        self::assertIsArray($error, (string) $response->getBody());

        /** @var array<string, mixed> $error */
        return $error;
    }

    public function testStoresAnUploadUnderAUlidNameWhateverTheClientSays(): void
    {
        $response = $this->upload(['file' => self::file($this->png(), '../../evil.php', 'text/html')]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-hjkmnp-tv-z]{26}\.png$#', $location);
        self::assertSame(['url' => $location, 'type' => 'image/png', 'bytes' => 70, 'width' => 1, 'height' => 1], self::json($response));

        $files = glob($this->tmp . '/uploads/notes/*') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('#/uploads/notes/[0-9a-hjkmnp-tv-z]{26}\.png$#', $files[0]);
        self::assertSame([], glob($this->tmp . '/*.php') ?: [], 'nothing escapes the uploads directory');
        self::assertSame(['notes'], array_map('basename', glob($this->tmp . '/uploads/*') ?: []));

        $served = $this->app()->handle(new ServerRequest('GET', (string) parse_url($location, PHP_URL_PATH)));
        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/png', $served->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
        self::assertSame((string) file_get_contents($this->png()), (string) $served->getBody());
    }

    public function testTheClientFileNameNeverReachesTheStoredPath(): void
    {
        $uploads = (string) realpath($this->tmp) . '/uploads/notes/';
        foreach (['../../x.php', 'evil.php', '/etc/passwd', 'photo.png.php', "..\\..\\x.php", '.htaccess'] as $i => $name) {
            // Distinct bytes each time, so no upload reuses an earlier file.
            $bytes = (string) base64_decode(MediaStoreTest::PNG, true) . str_repeat("\0", $i);
            $response = $this->upload(['file' => self::file($this->fixture("f{$i}.png", $bytes), $name, 'application/x-php')]);
            self::assertSame(201, $response->getStatusCode(), $name);
        }

        $files = $this->uploadedFiles();
        self::assertCount(6, $files);
        foreach ($files as $file) {
            self::assertStringStartsWith($uploads, (string) realpath($file));
            self::assertMatchesRegularExpression('#^[0-9a-hjkmnp-tv-z]{26}\.png$#', basename($file));
        }
        self::assertSame([], glob($this->tmp . '/*.php') ?: []);
    }

    public function testATextFileNamedJpgIs415(): void
    {
        $response = $this->upload(['file' => self::file($this->fixture('notes.jpg', "just some text\n"), 'notes.jpg', 'image/jpeg')]);
        self::assertSame(415, $response->getStatusCode());
        self::assertStringStartsWith('Files of type text/plain are not allowed', (string) self::error($response)['message']);
        self::assertSame(0, $this->mediaRows());
        self::assertSame([], $this->uploadedFiles());
    }

    public function testAPhpScriptNamedJpgIs415(): void
    {
        $script = $this->fixture('shell.jpg', "<?php system(\$_GET['c']); ?>\n");
        $response = $this->upload(['file' => self::file($script, 'shell.jpg', 'image/jpeg')]);
        self::assertSame(415, $response->getStatusCode());
        self::assertStringStartsWith('Files of type text/x-php are not allowed', (string) self::error($response)['message']);
        self::assertSame(0, $this->mediaRows());
        self::assertSame([], $this->uploadedFiles());
    }

    public function testAnImageWithAScriptInsideIsKeptOnlyAsAnImage(): void
    {
        // A real polyglot: a valid GIF that also carries PHP. It is allowed, as the image it is, and can never run:
        // the extension comes from the detected type and it is served as image/gif with nosniff.
        $poly = (string) base64_decode(self::GIF, true) . "<?php system(\$_GET['c']); ?>";
        $response = $this->upload(['file' => self::file($this->fixture('poly.php', $poly), 'poly.php', 'application/x-php')]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('image/gif', self::json($response)['type']);

        $files = $this->uploadedFiles();
        self::assertCount(1, $files);
        self::assertStringEndsWith('.gif', $files[0]);

        $served = $this->app()->handle(new ServerRequest('GET', (string) parse_url($response->getHeaderLine('Location'), PHP_URL_PATH)));
        self::assertSame('image/gif', $served->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAnImageOverFiftyMegapixelsIs422(): void
    {
        // A GIF header claiming 10000x10000 (100 megapixels): finfo calls it image/gif, the size cap refuses it.
        $huge = 'GIF89a' . pack('vv', 10000, 10000) . "\x00\x00\x00";
        $response = $this->upload(['file' => self::file($this->fixture('huge.gif', $huge), 'huge.gif', 'image/gif')]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('The image is 10000×10000; images may have at most 50 million pixels', self::error($response)['message']);
        self::assertSame(0, $this->mediaRows());
    }

    public function testAnEmptyFileIs422(): void
    {
        $response = $this->upload(['file' => self::file($this->fixture('empty.png', ''))]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('The file is empty', self::error($response)['message']);
        self::assertSame(0, $this->mediaRows());
    }

    public function testAnOversizeFileIs413AndLeavesNothingBehind(): void
    {
        $path = $this->fixture('big.png', (string) base64_decode(MediaStoreTest::PNG, true) . str_repeat("\0", MediaStore::MAX_BYTES));
        $response = $this->upload(['file' => self::file($path)]);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame('The file is larger than ' . MediaStore::MAX_BYTES . ' bytes', self::error($response)['message']);
        self::assertSame(0, $this->mediaRows());
        self::assertSame([], $this->uploadedFiles());
    }

    public function testReadsAtMostOneByteOverTheCap(): void
    {
        $stream = Stream::create(str_repeat("\0", MediaStore::MAX_BYTES + 3_000_000));
        $stream->rewind();
        $response = $this->upload(['file' => new UploadedFile($stream, MediaStore::MAX_BYTES + 3_000_000, UPLOAD_ERR_OK, 'big.png', 'image/png')]);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(MediaStore::MAX_BYTES + 1, $stream->tell());
    }

    public function testPhpUploadErrorsAreMapped(): void
    {
        $cases = [
            UPLOAD_ERR_INI_SIZE => 413,
            UPLOAD_ERR_FORM_SIZE => 413,
            UPLOAD_ERR_PARTIAL => 400,
            UPLOAD_ERR_NO_FILE => 400,
            UPLOAD_ERR_NO_TMP_DIR => 500,
            UPLOAD_ERR_CANT_WRITE => 500,
            UPLOAD_ERR_EXTENSION => 500,
        ];
        foreach ($cases as $error => $status) {
            $response = $this->upload(['file' => self::file('/nonexistent', 'a.png', 'image/png', $error)]);
            self::assertSame($status, $response->getStatusCode(), "error {$error}");
        }
        self::assertSame(0, $this->mediaRows());
    }

    public function testMultipartWithoutAFileFieldIs400(): void
    {
        foreach ([[], ['photo' => self::file($this->png())], ['file' => [self::file($this->png())]]] as $files) {
            $response = $this->upload($files);
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('Upload one file in the multipart field "file"', self::error($response)['message']);
        }
        self::assertSame(0, $this->mediaRows());
    }

    public function testScopeBlogPinModeAndHandleAreChecked(): void
    {
        self::assertSame(401, $this->upload(['file' => self::file($this->png())], 'not-a-token')->getStatusCode());
        self::assertSame(403, $this->upload(['file' => self::file($this->png())], $this->token('blog:write'))->getStatusCode());
        self::assertSame(403, $this->upload(['file' => self::file($this->png())], $this->token('media:write', ['--blog=other']))->getStatusCode());
        self::assertSame(404, $this->upload(['file' => self::file($this->png())], null, 'nope')->getStatusCode());
        $this->blogs($this->app())->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        $mirror = $this->upload(['file' => self::file($this->png())], null, 'src');
        self::assertSame(409, $mirror->getStatusCode());
        self::assertSame("Blog 'src' is a mirror: its posts come from its source", self::error($mirror)['message']);
        self::assertSame(0, $this->mediaRows());
        self::assertSame([], $this->uploadedFiles());
    }

    public function testAUserBoundTokenNeedsAddToUpload(): void
    {
        $this->app()->container()->get(UserRepository::class)->create('mo', 'mo@example.com', 'correct horse battery', 'Mo', 'active', []);
        $response = $this->upload(['file' => self::file($this->png())], $this->token('media:write', ['--user=mo']));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->mediaRows());

        $this->app()->container()->get(UserRepository::class)->create('ada', 'ada@example.com', 'correct horse battery', 'Ada', 'active', ['Authors']);
        $response = $this->upload(['file' => self::file($this->png())], $this->token('media:write', ['--user=ada']));
        self::assertSame(201, $response->getStatusCode(), 'an Author may upload');
    }
}
