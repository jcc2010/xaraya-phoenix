<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use FilesystemIterator;
use Nyholm\Psr7\UploadedFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Tests\Module\Blog\Media\MediaStoreTest;
use Xaraya\Tests\Support\Html;

final class ImageUploadTest extends AdminTestCase
{
    private function file(string $name, string $bytes, string $type, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, strlen($bytes), $error, $name, $type);
    }

    private function png(): string
    {
        return (string) base64_decode(MediaStoreTest::PNG, true);
    }

    private function mediaRows(App $app): int
    {
        return (int) $this->db($app)->fetchValue('SELECT COUNT(*) FROM {media}');
    }

    /** The files under the uploads directory (no partial file may be left behind). */
    private function uploadedFiles(): int
    {
        $dir = $this->tmp . '/uploads';
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $count += $file->isFile() ? 1 : 0;
        }

        return $count;
    }

    public function testAnUploadedImageBecomesThePostsImage(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'photo', 'content' => '', 'save' => '1'], ['file' => $this->file('p.png', $this->png(), 'image/png')]);
        self::assertSame(303, $response->getStatusCode(), $browser->body());
        $post = $this->posts($app)->page($blog->id, $this->now(), null, 1)[0];
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-hjkmnp-tv-z]{26}\.png$#', (string) $post->image);
        self::assertSame([['url' => $post->image, 'width' => 1, 'height' => 1]], $post->doc['_athenana']['images'] ?? null);

        $browser->get('/admin/blog/post/' . $post->id);
        $browser->post('/admin/blog/post/' . $post->id, ['kind' => 'photo', 'content' => '<p>caption</p>', 'remove_image' => '1', 'save' => '1']);
        $saved = $this->posts($app)->find($post->id);
        self::assertNull($saved?->image);
        self::assertArrayNotHasKey('images', $saved->doc['_athenana'] ?? []);
    }

    public function testOnlyImagesAreAcceptedAndARefusalStoresNothing(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $mp3 = "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x64" . str_repeat("\x00", 413), 4);
        foreach ([['a.mp3', $mp3, 'audio/mpeg'], ['shell.png', "<?php echo 'x';\n", 'image/png']] as [$name, $bytes, $type]) {
            $browser->get('/admin/blog/notes/posts/new');
            $response = $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>x</p>', 'save' => '1'], ['file' => $this->file($name, $bytes, $type)]);
            self::assertSame(422, $response->getStatusCode(), $name);
            self::assertStringContainsString('id="post-file-error" role="alert">Choose a file of type: image/jpeg', Html::normalize($browser->body()), $name);
        }
        self::assertSame(0, $this->mediaRows($app));
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
    }

    public function testAnUploadSurvivesAValidationErrorButNotAForeignUploadId(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $other = $this->nativeBlog($app, ['handle' => 'other', 'title' => 'Other']);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'photo', 'content' => '', 'date_published' => 'nope', 'save' => '1'], ['file' => $this->file('p.png', $this->png(), 'image/png')]);
        self::assertSame(422, $browser->last?->getStatusCode());
        self::assertSame(1, preg_match('/name="upload_id" value="([0-9a-hjkmnp-tv-z]{26})"/', $browser->body(), $m));
        $browser->post('/admin/blog/notes/posts/new', ['kind' => 'photo', 'content' => '', 'upload_id' => $m[1], 'save' => '1']);
        self::assertNotNull($this->posts($app)->page($blog->id, $this->now(), null, 1)[0]->image);

        $browser->get('/admin/blog/other/posts/new');
        $response = $browser->post('/admin/blog/other/posts/new', ['kind' => 'photo', 'content' => '', 'upload_id' => $m[1], 'save' => '1']);
        self::assertSame(422, $response->getStatusCode(), 'another blog’s upload is ignored, so the photo has no content');
        self::assertSame(0, $this->posts($app)->adminCounts($other->id, $this->now())['published']);
    }

    public function testAnUploadIdOfNonImageMediaIsIgnored(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $mp3 = "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x64" . str_repeat("\x00", 413), 4);
        $row = $app->container()->get(MediaStore::class)->ingest($blog, $mp3, null, $this->now());
        self::assertSame('audio/mpeg', $row['mime']);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', ['kind' => 'photo', 'content' => '', 'upload_id' => (string) $row['id'], 'save' => '1']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringNotContainsString('name="upload_id"', $browser->body());
    }

    public function testPreviewStoresNothingAndSaysTheImageUploadsOnSave(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>x</p>', 'preview' => '1'], ['file' => $this->file('p.png', $this->png(), 'image/png')]);
        self::assertSame(200, $response->getStatusCode(), $browser->body());
        $html = Html::normalize($browser->body());
        self::assertStringContainsString('id="post-file-hint">Images are uploaded when you save, not on Preview: choose the file again before saving.', $html);
        self::assertStringContainsString('aria-describedby="post-file-hint"', $html);
        self::assertSame(0, $this->mediaRows($app));
        self::assertSame(0, $this->uploadedFiles());
    }

    public function testTooLargeAndFailedUploadsAreInline422sAndStoreNothing(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $cases = [
            'ini size' => [$this->file('a.png', '', 'image/png', UPLOAD_ERR_INI_SIZE), 'The file is larger than this server accepts'],
            'partial' => [$this->file('b.png', '', 'image/png', UPLOAD_ERR_PARTIAL), 'The upload was interrupted'],
            'no tmp dir' => [$this->file('c.png', '', 'image/png', UPLOAD_ERR_NO_TMP_DIR), 'The upload failed; try again'],
            'over the cap' => [$this->file('d.png', $this->png() . str_repeat("\0", 10_000_000), 'image/png'), 'The file is larger than 10000000 bytes'],
        ];
        foreach ($cases as $case => [$file, $message]) {
            $browser->get('/admin/blog/notes/posts/new');
            $response = $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>x</p>', 'save' => '1'], ['file' => $file]);
            self::assertSame(422, $response->getStatusCode(), $case);
            $html = Html::normalize($browser->body());
            self::assertStringContainsString('id="post-file-error" role="alert">' . $message, $html, $case);
            self::assertStringContainsString('name="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" aria-invalid="true" aria-describedby="post-file-error"', $html, $case);
        }
        self::assertSame(0, $this->mediaRows($app));
        self::assertSame(0, $this->uploadedFiles());
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
    }

    public function testAMultipartPostWithoutATokenIsRefused(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->upload('/admin/blog/notes/posts/new', ['_csrf' => '', 'kind' => 'photo', 'content' => '', 'save' => '1'], ['file' => $this->file('p.png', $this->png(), 'image/png')]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->mediaRows($app));
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
    }

    public function testOnEditAnUploadReplacesTheImagesAndOtherwiseTheyAreKept(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $post = $this->postAs($app, $ada, $blog, [
            'content_html' => '<p>two</p>', 'image' => 'https://cdn.test/1.png',
            '_athenana' => ['kind' => 'photo', 'images' => [['url' => 'https://cdn.test/1.png', 'width' => 10, 'height' => 10], ['url' => 'https://cdn.test/2.png']]],
        ]);
        $html = $this->both('/admin/blog/post/' . $post->id, 'ada');
        self::assertStringContainsString('<form class="admin-form" method="post" action="/admin/blog/post/' . $post->id . '" enctype="multipart/form-data">', $html);
        self::assertStringContainsString('<img class="admin-image" src="https://cdn.test/1.png" alt="">', $html);
        self::assertStringContainsString('id="post-remove-image" name="remove_image" type="checkbox" value="1"', $html);
        self::assertStringNotContainsString('name="upload_id"', $html);

        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/post/' . $post->id);
        $fields = ['kind' => 'photo', 'content' => '<p>two, edited</p>', 'save' => '1'];
        self::assertSame(303, $browser->post('/admin/blog/post/' . $post->id, $fields)->getStatusCode());
        $kept = $this->posts($app)->find($post->id);
        self::assertSame('https://cdn.test/1.png', $kept?->image);
        self::assertCount(2, $kept->doc['_athenana']['images'] ?? [], 'a save without an upload keeps every image');

        $browser->get('/admin/blog/post/' . $post->id);
        self::assertSame(303, $browser->upload('/admin/blog/post/' . $post->id, $fields, ['file' => $this->file('p.png', $this->png(), 'image/png')])->getStatusCode());
        $replaced = $this->posts($app)->find($post->id);
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-hjkmnp-tv-z]{26}\.png$#', (string) $replaced?->image);
        self::assertSame([['url' => $replaced->image, 'width' => 1, 'height' => 1]], $replaced->doc['_athenana']['images'] ?? null);
    }

    public function testThe422StateWithAnUploadRendersTheSameInBothEngines(): void
    {
        $this->nativeBlog($this->site());
        $this->user($this->site(), 'ada', ['Authors']);
        $html = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = $this->signIn($this->site($engine), 'ada');
            $browser->get('/admin/blog/notes/posts/new');
            $response = $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'photo', 'content' => '', 'date_published' => 'nope', 'save' => '1'], ['file' => $this->file('p.png', $this->png(), 'image/png')]);
            self::assertSame(422, $response->getStatusCode(), $engine);
            // The same bytes are stored twice (one row per upload), so the ids differ: mask them.
            $html[$engine] = Html::normalize(preg_replace(['/name="_csrf" value="[^"]*"/', '/[0-9a-hjkmnp-tv-z]{26}/'], ['name="_csrf" value=""', 'ULID'], $browser->body()) ?? '');
        }
        self::assertSame($html['php'], $html['twig']);
        $main = self::main($html['php']);
        self::assertStringContainsString('enctype="multipart/form-data"', $main);
        self::assertStringContainsString('<label for="post-file">Image</label>', $main);
        self::assertStringContainsString('<img class="admin-image" src="http://xar.test/media/ULID.png" alt=""><input type="hidden" name="upload_id" value="ULID">', $main);
        self::assertStringNotContainsString('name="remove_image"', $main, 'a new post has nothing to remove');

        // A preview with a file shows the notice the same way in both engines.
        $previews = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = $this->signIn($this->site($engine), 'ada');
            $browser->get('/admin/blog/notes/posts/new');
            $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>x</p>', 'preview' => '1'], ['file' => $this->file('p.png', $this->png(), 'image/png')]);
            $previews[$engine] = Html::normalize(preg_replace(['/name="_csrf" value="[^"]*"/', '/[0-9a-hjkmnp-tv-z]{26}/'], ['name="_csrf" value=""', 'ULID'], $browser->body()) ?? '');
        }
        self::assertSame($previews['php'], $previews['twig']);
    }

    public function testAMirrorBlogTakesNoUpload(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena'], $this->now());
        $this->user($app, 'ed', ['Editors']);
        $browser = $this->signIn($app, 'ed');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->upload('/admin/blog/mirror/posts/new', ['kind' => 'photo', 'content' => '', 'save' => '1'], ['file' => $this->file('p.png', $this->png(), 'image/png')]);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(0, $this->mediaRows($app));
    }
}
