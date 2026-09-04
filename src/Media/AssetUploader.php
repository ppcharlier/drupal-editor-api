<?php

declare(strict_types=1);

namespace Drupal\editor_api\Media;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
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
 */
final class AssetUploader {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUploadHandlerInterface $uploadHandler,
    private readonly FileSystemInterface $fileSystem,
    private readonly MediaLoader $loader,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  public function upload(MediaTypeInterface $type, UploadedFileInterface $upload, AccountInterface $account): MediaInterface {
    $fieldName = $this->loader->sourceFieldName($type);
    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->entityTypeManager->getStorage('media')->create(['bundle' => $type->id(), 'uid' => $account->id()]);
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
    $file = $result->getFile();
    $file->setOwnerId($account->id());
    $file->setPermanent();
    $file->save();

    $values = ['target_id' => $file->id()];
    if ($type->getSource()->getPluginId() === 'image') {
      // Le champ image exige un `alt` : le nom du fichier, à corriger ensuite par PATCH.
      $values['alt'] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
    }
    $media->set($fieldName, $values);
    $media->setName($file->getFilename());
    // `ReferenceAccessConstraint` vérifie l'accès au fichier référencé via
    // l'utilisateur courant global, pas via `$account` : ce service peut être
    // appelé hors d'une requête HTTP authentifiée (tests, futurs jobs), donc
    // on bascule explicitement dessus le temps de la validation et de
    // l'enregistrement, plutôt que de dépendre d'un utilisateur courant déjà
    // positionné par ailleurs.
    $this->accountSwitcher->switchTo($account);
    try {
      EntityValidation::assert($media);
      $media->save();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    return $media;
  }

}
