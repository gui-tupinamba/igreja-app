<?php

declare(strict_types=1);

namespace App\Media;

use GdImage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class ImageProcessorService
{
    private const MAX_SIZE = 5 * 1024 * 1024;

    private const MAX_PIXELS = 30_000_000;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Todas as versões usam exatamente a mesma proporção 3770:1200.
     */
    private const VARIANTS = [
        'full' => [
            'width' => 3770,
            'height' => 1200,
            'quality' => 88,
        ],
        'detail' => [
            'width' => 1885,
            'height' => 600,
            'quality' => 86,
        ],
        'feed' => [
            'width' => 1131,
            'height' => 360,
            'quality' => 82,
        ],
    ];

    public function process(
        UploadedFile $file,
        string $directory,
    ): array {
        $this->validateUpload($file);

        $mimeType = $this->detectMimeType(
            $file->getPathname()
        );

        $dimensions = @getimagesize(
            $file->getPathname()
        );

        if ($dimensions === false) {
            throw new UnprocessableEntityHttpException(
                'Não foi possível identificar a imagem.'
            );
        }

        [$originalWidth, $originalHeight] =
            $dimensions;

        if (
            $originalWidth <= 0 ||
            $originalHeight <= 0 ||
            ($originalWidth * $originalHeight)
                > self::MAX_PIXELS
        ) {
            throw new UnprocessableEntityHttpException(
                'A resolução da imagem é inválida ou muito grande.'
            );
        }

        $source = $this->loadImage(
            $file->getPathname(),
            $mimeType,
        );

        $source = $this->applyOrientation(
            $source,
            $file->getPathname(),
            $mimeType,
        );

        $this->prepareDirectory($directory);

        $baseName = bin2hex(random_bytes(24));

        $createdFiles = [];
        $variants = [];

        try {
            foreach (
                self::VARIANTS as $name => $config
            ) {
                $processed = $this->cropAndResize(
                    $source,
                    $config['width'],
                    $config['height'],
                );

                $storageName =
                    $baseName.'-'.$name.'.webp';

                $path =
                    rtrim($directory, '/').
                    '/'.
                    $storageName;

                if (
                    !imagewebp(
                        $processed,
                        $path,
                        $config['quality'],
                    )
                ) {
                    imagedestroy($processed);

                    throw new \RuntimeException(
                        'Não foi possível salvar a imagem processada.'
                    );
                }

                imagedestroy($processed);

                clearstatcache(true, $path);

                $size = filesize($path);

                if (
                    $size === false ||
                    $size <= 0
                ) {
                    throw new \RuntimeException(
                        'A imagem processada ficou inválida.'
                    );
                }

                $createdFiles[] = $path;

                $variants[$name] = [
                    'storage_name' => $storageName,
                    'path' => $path,
                    'width' => $config['width'],
                    'height' => $config['height'],
                    'size' => $size,
                    'mime_type' => 'image/webp',
                ];
            }
        } catch (\Throwable $exception) {
            foreach ($createdFiles as $path) {
                @unlink($path);
            }

            throw $exception;
        } finally {
            imagedestroy($source);
        }

        return [
            'original_name' =>
                $file->getClientOriginalName(),

            'original_width' => $originalWidth,
            'original_height' => $originalHeight,

            'mime_type' => 'image/webp',

            'variants' => $variants,
        ];
    }

    private function validateUpload(
        UploadedFile $file,
    ): void {
        if (!$file->isValid()) {
            throw new UnprocessableEntityHttpException(
                'Não foi possível receber a imagem.'
            );
        }

        $size = $file->getSize();

        if (
            $size === false ||
            $size <= 0 ||
            $size > self::MAX_SIZE
        ) {
            throw new UnprocessableEntityHttpException(
                'A imagem deve possuir no máximo 5 MB.'
            );
        }
    }

    private function detectMimeType(
        string $path,
    ): string {
        $finfo = new \finfo(
            FILEINFO_MIME_TYPE
        );

        $mimeType = $finfo->file($path);

        if (
            $mimeType === false ||
            !in_array(
                $mimeType,
                self::ALLOWED_MIME_TYPES,
                true,
            )
        ) {
            throw new UnprocessableEntityHttpException(
                'Formato inválido. Utilize JPEG, PNG ou WebP.'
            );
        }

        return $mimeType;
    }

    private function loadImage(
        string $path,
        string $mimeType,
    ): GdImage {
        $image = match ($mimeType) {
            'image/jpeg' =>
                @imagecreatefromjpeg($path),

            'image/png' =>
                @imagecreatefrompng($path),

            'image/webp' =>
                @imagecreatefromwebp($path),

            default => false,
        };

        if (!$image instanceof GdImage) {
            throw new UnprocessableEntityHttpException(
                'Não foi possível processar a imagem.'
            );
        }

        return $image;
    }

    private function cropAndResize(
        GdImage $source,
        int $targetWidth,
        int $targetHeight,
    ): GdImage {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $sourceRatio =
            $sourceWidth / $sourceHeight;

        $targetRatio =
            $targetWidth / $targetHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;

            $cropWidth = (int) round(
                $sourceHeight * $targetRatio
            );

            $sourceX = (int) floor(
                ($sourceWidth - $cropWidth) / 2
            );

            $sourceY = 0;
        } else {
            $cropWidth = $sourceWidth;

            $cropHeight = (int) round(
                $sourceWidth / $targetRatio
            );

            $sourceX = 0;

            $sourceY = (int) floor(
                ($sourceHeight - $cropHeight) / 2
            );
        }

        $destination = imagecreatetruecolor(
            $targetWidth,
            $targetHeight,
        );

        if (!$destination instanceof GdImage) {
            throw new \RuntimeException(
                'Não foi possível criar a imagem de destino.'
            );
        }

        imagealphablending(
            $destination,
            false
        );

        imagesavealpha(
            $destination,
            true
        );

        $transparent = imagecolorallocatealpha(
            $destination,
            0,
            0,
            0,
            127,
        );

        imagefill(
            $destination,
            0,
            0,
            $transparent,
        );

        $success = imagecopyresampled(
            $destination,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight,
        );

        if (!$success) {
            imagedestroy($destination);

            throw new \RuntimeException(
                'Não foi possível redimensionar a imagem.'
            );
        }

        return $destination;
    }

    private function applyOrientation(
        GdImage $image,
        string $path,
        string $mimeType,
    ): GdImage {
        if (
            $mimeType !== 'image/jpeg' ||
            !function_exists('exif_read_data')
        ) {
            return $image;
        }

        $exif = @exif_read_data($path);

        if (!is_array($exif)) {
            return $image;
        }

        $orientation =
            (int) ($exif['Orientation'] ?? 1);

        switch ($orientation) {
            case 2:
                imageflip(
                    $image,
                    IMG_FLIP_HORIZONTAL
                );
                break;

            case 3:
                $image = $this->rotate(
                    $image,
                    180
                );
                break;

            case 4:
                imageflip(
                    $image,
                    IMG_FLIP_VERTICAL
                );
                break;

            case 5:
                imageflip(
                    $image,
                    IMG_FLIP_HORIZONTAL
                );

                $image = $this->rotate(
                    $image,
                    90
                );
                break;

            case 6:
                $image = $this->rotate(
                    $image,
                    -90
                );
                break;

            case 7:
                imageflip(
                    $image,
                    IMG_FLIP_HORIZONTAL
                );

                $image = $this->rotate(
                    $image,
                    -90
                );
                break;

            case 8:
                $image = $this->rotate(
                    $image,
                    90
                );
                break;
        }

        return $image;
    }

    private function rotate(
        GdImage $image,
        int $angle,
    ): GdImage {
        $rotated = imagerotate(
            $image,
            $angle,
            0
        );

        if (!$rotated instanceof GdImage) {
            throw new \RuntimeException(
                'Não foi possível corrigir a orientação da imagem.'
            );
        }

        imagedestroy($image);

        return $rotated;
    }

    private function prepareDirectory(
        string $directory,
    ): void {
        if (
            !is_dir($directory) &&
            !mkdir(
                $directory,
                0770,
                true,
            )
        ) {
            throw new \RuntimeException(
                'Não foi possível preparar o diretório de imagens.'
            );
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException(
                'O diretório de imagens não permite escrita.'
            );
        }
    }
}