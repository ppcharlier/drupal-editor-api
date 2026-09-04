<?php

declare(strict_types=1);

namespace Drupal\editor_api\Media;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\editor_api\Controller\ConfigController;
use Drupal\editor_api\Http\ApiException;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;

/**
 * Un conteneur d'assets = un type de média à source fichier ou image ;
 * un asset = un média, adressé par `{mid}/{basename}`.
 */
final class MediaLoader {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  public function container(string $type): MediaTypeInterface {
    $mediaType = $this->entityTypeManager->getStorage('media_type')->load($type);
    if (!$mediaType instanceof MediaTypeInterface || !ConfigController::isFileBacked($mediaType)) {
      throw ApiException::notFound();
    }
    return $mediaType;
  }

  public function sourceFieldName(MediaTypeInterface $type): string {
    return (string) $type->getSource()->getConfiguration()['source_field'];
  }

  public function typeOf(MediaInterface $media): MediaTypeInterface {
    /** @var \Drupal\media\MediaTypeInterface $type */
    $type = $this->entityTypeManager->getStorage('media_type')->load($media->bundle());
    return $type;
  }

  public function sourceFile(MediaInterface $media): ?FileInterface {
    $file = $media->get($this->sourceFieldName($this->typeOf($media)))->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

  public function path(MediaInterface $media): string {
    $file = $this->sourceFile($media);
    return $media->id() . '/' . ($file ? $file->getFilename() : '');
  }

  public function load(string $type, string $path): MediaInterface {
    $this->container($type);
    [$mid, $basename] = explode('/', $path, 2) + [1 => NULL];
    $media = ctype_digit($mid) ? $this->entityTypeManager->getStorage('media')->load((int) $mid) : NULL;
    if (!$media instanceof MediaInterface || $media->bundle() !== $type) {
      throw ApiException::notFound();
    }
    $file = $this->sourceFile($media);
    if ($file === NULL || ($basename !== NULL && $file->getFilename() !== $basename)) {
      throw ApiException::notFound();
    }
    return $media;
  }

}
