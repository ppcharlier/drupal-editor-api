<?php

declare(strict_types=1);

namespace Drupal\editor_api\Media;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\editor_api\Entry\EntityValidation;
use Drupal\editor_api\Http\ApiException;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\UploadedFileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;

/**
 * Un fichier téléversé → un File permanent dans l'emplacement du champ source,
 * puis un Media du type, nommé d'après le fichier.
 *
 * Les validateurs et l'emplacement sont ceux du champ source (extensions,
 * taille, image), exactement ce que le formulaire du média appliquerait.
 * `FileExists::Rename` : un nom déjà pris est suffixé, jamais écrasé.
 *
 * Ce service agit comme l'utilisateur courant (`current_user`), honnêtement :
 * il ne bascule jamais le compte global. Un appelant hors requête HTTP
 * authentifiée (tâche cron, commande Drush) doit se placer lui-même sous le
 * bon compte avant d'appeler `upload()`.
 */
final class AssetUploader {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUploadHandlerInterface $uploadHandler,
    private readonly FileSystemInterface $fileSystem,
    private readonly MediaLoader $loader,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function upload(MediaTypeInterface $type, UploadedFileInterface $upload): MediaInterface {
    $fieldName = $this->loader->sourceFieldName($type);
    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->entityTypeManager->getStorage('media')->create(['bundle' => $type->id(), 'uid' => $this->currentUser->id()]);
    /** @var \Drupal\file\Plugin\Field\FieldType\FileItem $item */
    $item = $media->get($fieldName)->appendItem(['target_id' => NULL]);
    $destination = $item->getUploadLocation();
    $validators = $item->getUploadValidators();
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $result = $this->uploadHandler->handleFileUpload($upload, $validators, $destination, FileExists::Rename);
    if ($result->hasViolations()) {
      $messages = [];
      foreach ($result->getViolations() as $violation) {
        $messages[] = strip_tags((string) $violation->getMessage());
      }
      throw ApiException::validation(['file' => $messages]);
    }
    // Le fichier est déjà enregistré (temporaire) par `handleFileUpload()` :
    // on construit le média et on le valide AVANT de rendre le fichier
    // permanent, pour ne jamais laisser un fichier permanent orphelin si le
    // média est invalide (ex. un champ configurable requis manquant).
    $file = $result->getFile();
    $values = ['target_id' => $file->id()];
    if ($type->getSource()->getPluginId() === 'image') {
      // Le champ image exige un `alt` : le nom du fichier, à corriger ensuite par PATCH.
      $values['alt'] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
    }
    $media->set($fieldName, $values);
    $media->setName($file->getFilename());
    try {
      EntityValidation::assert($media);
    }
    catch (ApiException $e) {
      // Le média est invalide : le fichier temporaire ne doit pas rester.
      $file->delete();
      throw $e;
    }
    $file->setOwnerId($this->currentUser->id());
    $file->setPermanent();
    $file->save();
    // La validation ci-dessus a chargé (et mis en cache dans le champ) le
    // fichier référencé alors qu'il était encore temporaire : on réinjecte
    // l'objet devenu permanent, sinon les lectures suivantes (`sourceFile()`)
    // verraient l'ancien état en cache, jamais celui écrit ci-dessus.
    $media->get($fieldName)->entity = $file;
    $media->save();
    return $media;
  }

}
