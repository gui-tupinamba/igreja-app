<?php

declare(strict_types=1);

namespace App\Administration;

use App\Media\ImageProcessorService;
use App\Security\AuthenticatedActor;
use App\Security\Authorization\AccessPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class PostImageService
{
    private const MAX_IMAGES = 5;

    private const DIRECTORY =
        '/var/www/api/var/uploads/posts';

    public function __construct(
        private Connection $db,
        private AccessPolicy $policy,
        private ImageProcessorService $processor,
    ) {
    }

    public function upload(
        AuthenticatedActor $actor,
        int $postId,
        UploadedFile $file,
    ): array {
        if (
            !$this->policy->canManagePost(
                $actor->userId,
                $postId,
            )
        ) {
            throw new NotFoundHttpException();
        }

        /*
         * A partir daqui o arquivo original é:
         *
         * - validado;
         * - corrigido por EXIF;
         * - recortado;
         * - convertido para WebP;
         * - convertido em full/detail/feed.
         */
        $processed = $this->processor->process(
            $file,
            self::DIRECTORY,
        );

        $full = $processed['variants']['full'];

        try {
            if ($this->db->isTransactionActive()) {
                throw new \LogicException('Image uploads must own the outermost transaction.');
            }
            $row = $this->db->transactional(function () use ($actor, $postId, $processed, $full): array {
                $now = $this->guardMutation($actor);
                if ($this->db->fetchOne('SELECT id FROM posts WHERE id = ? FOR UPDATE', [$postId]) === false
                    || !$this->policy->canManagePost($actor->userId, $postId)) {
                    throw new NotFoundHttpException();
                }
                $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM post_images WHERE post_id = ?', [$postId]);
                if ($count >= self::MAX_IMAGES) {
                    throw new UnprocessableEntityHttpException('A publicação já possui o limite de 5 imagens.');
                }
                $position = (int) $this->db->fetchOne(
                    'SELECT COALESCE(MAX(position), -1) + 1 FROM post_images WHERE post_id = ?',
                    [$postId],
                );
                $created = $this->db->fetchAssociative(
                    <<<'SQL'
                    INSERT INTO post_images
                        (post_id, storage_name, original_name, mime_type, size, position, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    RETURNING *
                    SQL,
                    [$postId, $full['storage_name'], $processed['original_name'], $full['mime_type'],
                        $full['size'], $position, $now],
                );
                if ($created === false) {
                    throw new \RuntimeException('Não foi possível registrar a imagem.');
                }
                $this->audit($actor->userId, $postId, (int) $created['id'], $position, $now);

                return $created;
            });
        } catch (\Throwable $exception) {
            $this->removeProcessedFiles(
                $processed
            );

            throw $exception;
        }

        if ($row === false) {
            $this->removeProcessedFiles(
                $processed
            );

            throw new \RuntimeException(
                'Não foi possível registrar a imagem.'
            );
        }

        return [
            'id' => (int) $row['id'],

            'post_id' =>
                (int) $row['post_id'],

            'mime_type' =>
                $row['mime_type'],

            'size' =>
                (int) $row['size'],

            'position' =>
                (int) $row['position'],

            'width' =>
                $full['width'],

            'height' =>
                $full['height'],
        ];
    }

public function getForRead(
    AuthenticatedActor $actor,
    int $postId,
    int $imageId,
    string $variant = 'full',
): array {
    if (
        !$this->policy->canReadPost(
            $actor->userId,
            $postId,
        )
        &&
        !$this->policy->canManagePost(
            $actor->userId,
            $postId,
        )
    ) {
        throw new NotFoundHttpException();
    }

    if (
        !in_array(
            $variant,
            ['full', 'detail', 'feed'],
            true,
        )
    ) {
        throw new NotFoundHttpException();
    }

    $row = $this->db->fetchAssociative(
        <<<'SQL'
        SELECT
            id,
            post_id,
            storage_name,
            original_name,
            mime_type,
            size,
            position
        FROM post_images
        WHERE id = ?
        AND post_id = ?
        SQL,
        [
            $imageId,
            $postId,
        ],
    );

    if ($row === false) {
        throw new NotFoundHttpException();
    }

    $storageName = $row['storage_name'];

    /*
     * Imagens novas:
     *
     * abc-full.webp
     * abc-detail.webp
     * abc-feed.webp
     */
    if (
        preg_match(
            '/-full\.webp$/D',
            $storageName,
        ) === 1
    ) {
        $variantName = preg_replace(
            '/-full\.webp$/D',
            '-'.$variant.'.webp',
            $storageName,
        );

        if (!is_string($variantName)) {
            throw new NotFoundHttpException();
        }

        $path =
            self::DIRECTORY.
            '/'.
            $variantName;

        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }

        $size = filesize($path);

        if ($size === false) {
            throw new NotFoundHttpException();
        }

        return [
            'id' => (int) $row['id'],
            'post_id' => (int) $row['post_id'],
            'path' => $path,
            'original_name' =>
                $row['original_name'],
            'mime_type' => 'image/webp',
            'size' => $size,
            'position' =>
                (int) $row['position'],
            'variant' => $variant,
        ];
    }

    /*
     * Compatibilidade com imagens antigas.
     *
     * Como elas não possuem variantes, qualquer
     * pedido utiliza o arquivo original.
     */
    $path =
        self::DIRECTORY.
        '/'.
        $storageName;

    if (!is_file($path)) {
        throw new NotFoundHttpException();
    }

    return [
        'id' => (int) $row['id'],
        'post_id' => (int) $row['post_id'],
        'path' => $path,
        'original_name' =>
            $row['original_name'],
        'mime_type' =>
            $row['mime_type'],
        'size' =>
            (int) $row['size'],
        'position' =>
            (int) $row['position'],
        'variant' => 'original',
    ];
}

    private function removeProcessedFiles(
        array $processed,
    ): void {
        foreach (
            $processed['variants'] ?? []
            as $variant
        ) {
            $path = $variant['path'] ?? null;

            if (
                is_string($path)
                && is_file($path)
            ) {
                @unlink($path);
            }
        }
    }

    private function guardMutation(AuthenticatedActor $actor): string
    {
        $this->db->executeQuery('SELECT pg_advisory_xact_lock(841920041)');
        $user = $this->db->fetchAssociative('SELECT status FROM users WHERE id = ? FOR UPDATE', [$actor->userId]);
        $session = $this->db->fetchAssociative(
            'SELECT revoked_at, expires_at FROM auth_sessions WHERE id = ? AND user_id = ? FOR UPDATE',
            [$actor->sessionId, $actor->userId],
        );
        $now = (string) $this->db->fetchOne("SELECT date_trunc('second', clock_timestamp())");
        if ($user === false || $user['status'] !== 'ACTIVE' || $session === false
            || $session['revoked_at'] !== null
            || new DateTimeImmutable($session['expires_at']) <= new DateTimeImmutable($now)) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return $now;
    }

    private function audit(int $actorId, int $postId, int $imageId, int $position, string $now): void
    {
        $this->db->insert('audit_logs', [
            'actor_id' => $actorId, 'entity_type' => 'posts', 'entity_id' => $postId,
            'action' => 'post.image_uploaded',
            'metadata' => json_encode(['image_id' => $imageId, 'position' => $position], JSON_THROW_ON_ERROR),
            'request_id' => bin2hex(random_bytes(16)), 'created_at' => $now,
        ]);
    }
}
