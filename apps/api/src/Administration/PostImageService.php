<?php

declare(strict_types=1);

namespace App\Administration;

use App\Security\AuthenticatedActor;
use App\Security\Authorization\AccessPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class PostImageService
{
    private const MAX_IMAGES = 5;
    private const MAX_SIZE = 5 * 1024 * 1024;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private Connection $db,
        private AccessPolicy $policy,
    ) {
    }

    public function upload(
        AuthenticatedActor $actor,
        int $postId,
        UploadedFile $file,
    ): array {
        if (!$this->policy->canManagePost($actor->userId, $postId)) {
            throw new NotFoundHttpException();
        }

        $count = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM post_images WHERE post_id = ?',
            [$postId],
        );

        if ($count >= self::MAX_IMAGES) {
            throw new UnprocessableEntityHttpException(
                'A publicação já possui o limite de 5 imagens.'
            );
        }

        if (!$file->isValid()) {
            throw new UnprocessableEntityHttpException(
                'Não foi possível receber a imagem.'
            );
        }

        $size = $file->getSize();

        if ($size === false || $size <= 0 || $size > self::MAX_SIZE) {
            throw new UnprocessableEntityHttpException(
                'A imagem deve possuir no máximo 5 MB.'
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file->getPathname());

        if ($mimeType === false || !isset(self::EXTENSIONS[$mimeType])) {
            throw new UnprocessableEntityHttpException(
                'Formato inválido. Utilize JPEG, PNG ou WebP.'
            );
        }

        $extension = self::EXTENSIONS[$mimeType];
        $storageName = bin2hex(random_bytes(24)).'.'.$extension;

        $directory = '/var/www/api/var/uploads/posts';

        if (!is_dir($directory) && !mkdir($directory, 0770, true)) {
            throw new \RuntimeException(
                'Não foi possível preparar o diretório de imagens.'
            );
        }

        $position = (int) $this->db->fetchOne(
            'SELECT COALESCE(MAX(position), -1) + 1
            FROM post_images
            WHERE post_id = ?',
            [$postId],
        );

        $originalName = $file->getClientOriginalName();

        $file->move($directory, $storageName);

        try {
            $row = $this->db->fetchAssociative(
                <<<'SQL'
                INSERT INTO post_images
                    (post_id, storage_name, original_name, mime_type, size, position, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                RETURNING *
                SQL,
                [
                    $postId,
                    $storageName,
                    $originalName,
                    $mimeType,
                    $size,
                    $position,
                ],
            );
        } catch (\Throwable $e) {
            @unlink($directory.'/'.$storageName);
            throw $e;
        }

        if ($row === false) {
            @unlink($directory.'/'.$storageName);

            throw new \RuntimeException(
                'Não foi possível registrar a imagem.'
            );
        }

        return [
            'id' => (int) $row['id'],
            'post_id' => (int) $row['post_id'],
            'mime_type' => $row['mime_type'],
            'size' => (int) $row['size'],
            'position' => (int) $row['position'],
        ];
    }

    public function getForRead(
    AuthenticatedActor $actor,
    int $postId,
    int $imageId,
): array {
    if (
        !$this->policy->canReadPost($actor->userId, $postId)
        && !$this->policy->canManagePost($actor->userId, $postId)
    ) {
        throw new NotFoundHttpException();
    }

    $row = $this->db->fetchAssociative(
        <<<'SQL'
        SELECT id, post_id, storage_name, original_name, mime_type, size, position
        FROM post_images
        WHERE id = ? AND post_id = ?
        SQL,
        [$imageId, $postId],
    );

    if ($row === false) {
        throw new NotFoundHttpException();
    }

    $path = '/var/www/api/var/uploads/posts/'.$row['storage_name'];

    if (!is_file($path)) {
        throw new NotFoundHttpException();
    }

    return [
        'id' => (int) $row['id'],
        'post_id' => (int) $row['post_id'],
        'path' => $path,
        'original_name' => $row['original_name'],
        'mime_type' => $row['mime_type'],
        'size' => (int) $row['size'],
        'position' => (int) $row['position'],
    ];
}
}