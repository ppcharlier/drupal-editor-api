<?php

declare(strict_types=1);

namespace Drupal\editor_api\Payload;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Media\MediaLoader;
use Drupal\media\MediaInterface;

/**
 * La forme `AssetSummary` du contrat, pour un média à source fichier ou image.
 */
final class AssetPayload {

  public function __construct(
    private readonly MediaLoader $loader,
    private readonly Capabilities $capabilities,
    private readonly FileUrlGeneratorInterface $urls,
  ) {}

  public function summary(MediaInterface $media, AccountInterface $account): array {
    $type = $this->loader->typeOf($media);
    $file = $this->loader->sourceFile($media);
    $basename = $file ? $file->getFilename() : '';
    $isImage = $type->getSource()->getPluginId() === 'image';
    $item = $media->get($this->loader->sourceFieldName($type))->first();
    $data = $isImage
      ? ['alt' => (string) ($item->alt ?? ''), 'title' => (string) ($item->title ?? '')]
      : ['description' => (string) ($item->description ?? '')];
    return [
      'id' => $type->id() . '::' . $media->id() . '/' . $basename,
      'path' => $media->id() . '/' . $basename,
      'url' => $file ? $this->urls->generateAbsoluteString($file->getFileUri()) : NULL,
      'filename' => pathinfo($basename, PATHINFO_FILENAME),
      'basename' => $basename,
      'extension' => pathinfo($basename, PATHINFO_EXTENSION),
      'folder' => '',
      'size' => $file ? (int) $file->getSize() : NULL,
      'mime_type' => $file ? (string) $file->getMimeType() : NULL,
      'is_image' => $isImage,
      'last_modified' => EntryPayload::iso($media->getChangedTime()),
      'data' => $data,
      'can' => $this->capabilities->forMedia($media, $account),
    ];
  }

}
