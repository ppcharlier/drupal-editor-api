<?php

declare(strict_types=1);

namespace Drupal\editor_api\Payload;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Media\MediaLoader;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;

/**
 * La forme `AssetSummary` du contrat, pour un média à source fichier ou image.
 */
final class AssetPayload {

  public function __construct(
    private readonly MediaLoader $loader,
    private readonly Capabilities $capabilities,
    private readonly FileUrlGeneratorInterface $urls,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function summary(MediaInterface $media, AccountInterface $account): array {
    $type = $this->loader->typeOf($media);
    $file = $this->loader->sourceFile($media);
    $basename = $file ? $file->getFilename() : '';
    // `path` du contrat vaut `{mid}/{basename}` : une seule définition, celle
    // de `MediaLoader::path()`, qui sert aussi de suffixe à l'`id`.
    $path = $this->loader->path($media);
    $isImage = $type->getSource()->getPluginId() === 'image';
    // Le champ source peut être vide (média sans fichier) : `first()` rend
    // alors NULL, et les métadonnées valent la chaîne vide.
    $item = $media->get($this->loader->sourceFieldName($type))->first();
    // Les VALEURS de l'item plutôt que ses propriétés : `$item->description` lève une
    // `InvalidArgumentException` sur un champ qui n'a pas cette propriété, et `GET /media/{uuid}`
    // sert désormais des médias de n'importe quelle source — un champ `string`, un lien. Le
    // tableau des valeurs, lui, répond simplement « absente ».
    $values = $item ? $item->getValue() : [];
    $data = $isImage
      ? ['alt' => (string) ($values['alt'] ?? ''), 'title' => (string) ($values['title'] ?? '')]
      : ['description' => (string) ($values['description'] ?? '')];
    $thumbnail = $this->thumbnailUrl($media);
    $summary = [
      'id' => $type->id() . '::' . $path,
      // L'UUID du MÉDIA, ce que `data-entity-uuid` d'un <drupal-media> porte — à ne pas confondre
      // avec celui d'`embed`, qui est l'UUID du FICHIER attendu par une <img>.
      'uuid' => $media->uuid(),
      'path' => $path,
      'url' => $file ? $this->urls->generateAbsoluteString($file->getFileUri()) : NULL,
      'thumbnail' => $thumbnail,
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
    // Posée dans le littéral pour tenir sa place dans l'ordre des clés, retirée quand il n'y a
    // pas de vignette : comme `embed`, le contrat dit « optionnelle », pas « nullable ».
    if ($thumbnail === NULL) {
      unset($summary['thumbnail']);
    }
    // Spec de l'éditeur HTML §7.1 : l'app recopie ces deux valeurs dans `data-entity-type` et
    // `data-entity-uuid` d'une <img> insérée, pour que le filtre de suivi d'usage du cœur
    // (`editor_file_reference`) reconnaisse le fichier. C'est l'UUID du FICHIER, pas du média :
    // `basic_html` n'autorise pas <drupal-media>, seul <img data-entity-type="file"> y passe.
    // Clé ABSENTE sans fichier : le contrat dit « optionnel », pas « nullable ».
    if ($file) {
      $summary['embed'] = ['entity_type' => 'file', 'uuid' => $file->uuid()];
    }
    return $summary;
  }

  /**
   * L'URL absolue de la vignette du média, `NULL` quand il n'en a pas.
   *
   * Le cœur maintient un champ `thumbnail` sur TOUT média — l'image elle-même pour une source
   * image, l'icône générique de la source sinon —, ce qui donne à l'app une vignette pour un
   * `<drupal-media>` qui vise une vidéo ou un document, là où `url` (le fichier source) ne dit
   * rien d'affichable. Le style `thumbnail` du cœur quand il est là : l'app affiche une capsule,
   * pas l'original. `image_style` existe toujours ici, `media` dépend d'`image`.
   */
  private function thumbnailUrl(MediaInterface $media): ?string {
    if (!$media->hasField('thumbnail')) {
      return NULL;
    }
    $file = $media->get('thumbnail')->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $uri = $file->getFileUri();
    $style = $this->entityTypeManager->getStorage('image_style')->load('thumbnail');
    return $style instanceof ImageStyleInterface
      ? $style->buildUrl($uri)
      : $this->urls->generateAbsoluteString($uri);
  }

}
