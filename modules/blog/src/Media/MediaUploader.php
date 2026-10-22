<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Media;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Blog;

/**
 * Takes the multipart field `file` of an upload request (JSON API and Micropub media endpoints) into MediaStore.
 * The client's file name and type are never used: MediaStore names the file and picks its type and extension.
 */
final class MediaUploader
{
    public const FIELD = 'file';

    /** The types the post form takes (MediaStore::TYPES without audio). */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    private const CHUNK = 1 << 20;

    public function __construct(private readonly MediaStore $store, private readonly UrlGenerator $urls) {}

    /**
     * A non-empty $types allows only those of MediaStore::TYPES: any other sniffed type is a 415 "Choose a file of
     * type: ..." (the JSON API and Micropub pass none).
     *
     * @param list<string> $types
     * @return array<string, mixed> the media row
     * @throws HttpException 400 without one file in FIELD or for an interrupted upload, 413 too large, 415 or 422
     * @throws RuntimeException for an upload PHP could not save (no temp dir, disk, extension): a server fault
     */
    public function upload(Blog $blog, ServerRequestInterface $request, DateTimeImmutable $now, array $types = []): array
    {
        $file = $request->getUploadedFiles()[self::FIELD] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new HttpException(400, 'Upload one file in the multipart field "' . self::FIELD . '"');
        }
        match ($file->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw new HttpException(413, 'The file is larger than this server accepts'),
            UPLOAD_ERR_PARTIAL => throw new HttpException(400, 'The upload was interrupted'),
            UPLOAD_ERR_NO_FILE => throw new HttpException(400, 'Upload one file in the multipart field "' . self::FIELD . '"'),
            default => throw new RuntimeException('The upload failed with PHP upload error ' . $file->getError()),
        };
        try {
            return $this->store->ingest($blog, self::read($file), null, $now, $types);
        } catch (MediaRejected $e) {
            throw new HttpException($e->status, $e->getMessage());
        }
    }

    /**
     * The absolute public URL of a media row (the blog.media route).
     *
     * @param array<string, mixed> $row
     */
    public function url(array $row): string
    {
        return $this->urls->generate('blog.media', ['id' => (string) $row['id'], 'ext' => MediaStore::TYPES[(string) $row['mime']] ?? 'bin'], true);
    }

    /** Reads at most MAX_BYTES + 1 bytes, so an oversize upload is refused without loading all of it. */
    private static function read(UploadedFileInterface $file): string
    {
        $stream = $file->getStream();
        $limit = MediaStore::MAX_BYTES + 1;
        $bytes = '';
        while (!$stream->eof() && ($left = $limit - strlen($bytes)) > 0) {
            $chunk = $stream->read(min(self::CHUNK, $left));
            if ($chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }
        if (strlen($bytes) > MediaStore::MAX_BYTES) {
            throw new HttpException(413, 'The file is larger than ' . MediaStore::MAX_BYTES . ' bytes');
        }

        return $bytes;
    }
}
