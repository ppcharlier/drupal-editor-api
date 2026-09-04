<?php

declare(strict_types=1);

namespace Drupal\editor_api\Entry;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\editor_api\Http\ApiException;

/**
 * `$entity->validate()` → 422 `validation_failed`, violations regroupées par champ.
 *
 * Les clés rendues sont celles du contrat : pour un terme, le libellé s'appelle
 * `title`, alors que Drupal le stocke dans le champ de base `name`.
 */
final class EntityValidation {

  private const RENAMED = ['taxonomy_term' => ['name' => 'title']];

  public static function assert(ContentEntityInterface $entity): void {
    $renamed = self::RENAMED[$entity->getEntityTypeId()] ?? [];
    $errors = [];
    foreach ($entity->validate() as $violation) {
      $root = explode('.', (string) $violation->getPropertyPath())[0];
      $root = $renamed[$root] ?? $root;
      $errors[$root === '' ? '_entity' : $root][] = strip_tags((string) $violation->getMessage());
    }
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
  }

}
