<?php

declare(strict_types=1);

namespace App\Administration;

use App\Media\ImageProcessorService;
use App\Security\AuthenticatedActor;
use App\Security\Authorization\AccessPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class EventImageService
{
    private const MAX_IMAGES = 5;

    private const DIRECTORY =
        '/var/www/api/var/uploads/events';

    public function __construct(
        private Connection $db,
        private AccessPolicy $policy,
        private ImageProcessorService $processor,
    ) {
    }

    public function upload(
        AuthenticatedActor $actor,
        int $eventId,
        UploadedFile $file,
    ): array {
        if (
            !$this->policy->canManageEvent(
                $actor->userId,
                $eventId,
            )
        ) {
            throw new NotFoundHttpException();
        }

        /*
         * Processa primeiro a imagem.
         *
         * Gera:
         * - full   3770x1200
         * - detail 1885x600
         * - feed   1131x360
         */
        $processed = $this->processor->process(
            $file,
            self::DIRECTORY,
        );

        $full = $processed['variants']['full'];

        try {
            $row = $this->db->transactional(
                function () use (
                    $actor,
                    $eventId,
                    $processed,
                    $full,
                ): array {
                    /*
                     * Bloqueia o evento para impedir dois uploads
                     * simultâneos de escolherem a mesma posição.
                     */
                    $exists = $this->db->fetchOne(
                        'SELECT id
                         FROM events
                         WHERE id = ?
                         FOR UPDATE',
                        [$eventId],
                    );

                    if ($exists === false) {
                        throw new NotFoundHttpException();
                    }

                    /*
                     * Revalida a permissão depois do lock.
                     */
                    if (
                        !$this->policy->canManageEvent(
                            $actor->userId,
                            $eventId,
                        )
                    ) {
                        throw new NotFoundHttpException();
                    }

                    $count = (int) $this->db->fetchOne(
                        'SELECT COUNT(*)
                         FROM event_images
                         WHERE event_id = ?',
                        [$eventId],
                    );

                    if ($count >= self::MAX_IMAGES) {
                        throw new UnprocessableEntityHttpException(
                            'O evento já possui o limite de 5 imagens.'
                        );
                    }

                    $position = (int) $this->db->fetchOne(
                        'SELECT COALESCE(MAX(position), -1) + 1
                         FROM event_images
                         WHERE event_id = ?',
                        [$eventId],
                    );

                    $row = $this->db->fetchAssociative(
                        <<<'SQL'
                        INSERT INTO event_images
                            (
                                event_id,
                                storage_name,
                                original_name,
                                mime_type,
                                size,
                                position,
                                created_at
                            )
                        VALUES
                            (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                        RETURNING *
                        SQL,
                        [
                            $eventId,
                            $full['storage_name'],
                            $processed['original_name'],
                            $full['mime_type'],
                            $full['size'],
                            $position,
                        ],
                    );

                    if ($row === false) {
                        throw new \RuntimeException(
                            'Não foi possível registrar a imagem do evento.'
                        );
                    }

                    return $row;
                },
            );
        } catch (\Throwable $exception) {
            $this->removeProcessedFiles(
                $processed
            );

            throw $exception;
        }

        return [
            'id' =>
                (int) $row['id'],

            'event_id' =>
                (int) $row['event_id'],

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
        int $eventId,
        int $imageId,
        string $variant = 'full',
    ): array {
        if (
            !$this->policy->canReadEvent(
                $actor->userId,
                $eventId,
            )
            &&
            !$this->policy->canManageEvent(
                $actor->userId,
                $eventId,
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
                event_id,
                storage_name,
                original_name,
                mime_type,
                size,
                position
            FROM event_images
            WHERE id = ?
              AND event_id = ?
            SQL,
            [
                $imageId,
                $eventId,
            ],
        );

        if ($row === false) {
            throw new NotFoundHttpException();
        }

        $storageName = $row['storage_name'];

        /*
         * Banco guarda:
         *
         * xxxxx-full.webp
         *
         * A variante solicitada é derivada:
         *
         * xxxxx-detail.webp
         * xxxxx-feed.webp
         */
        $variantName = preg_replace(
            '/-full\.webp$/D',
            '-'.$variant.'.webp',
            $storageName,
        );

        if (
            !is_string($variantName)
            ||
            $variantName === $storageName
            && $variant !== 'full'
        ) {
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

        if (
            $size === false
            ||
            $size <= 0
        ) {
            throw new NotFoundHttpException();
        }

        return [
            'id' =>
                (int) $row['id'],

            'event_id' =>
                (int) $row['event_id'],

            'path' =>
                $path,

            'original_name' =>
                $row['original_name'],

            'mime_type' =>
                'image/webp',

            'size' =>
                $size,

            'position' =>
                (int) $row['position'],

            'variant' =>
                $variant,
        ];
    }

    private function removeProcessedFiles(
        array $processed,
    ): void {
        foreach (
            $processed['variants'] ?? []
            as $variant
        ) {
            $path =
                $variant['path'] ?? null;

            if (
                is_string($path)
                &&
                is_file($path)
            ) {
                @unlink($path);
            }
        }
    }
}