<?php

declare(strict_types=1);

namespace Drupal\editor_api\Entry;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\editor_api\Http\ApiException;

/**
 * `$entity->validate()` → 422 `validation_failed`, violations regroupées par champ.
 */
final class EntityValidation {

  public static function assert(ContentEntityInterface $entity): void {
    $errors = [];
    foreach ($entity->validate() as $violation) {
      $root = explode('.', (string) $violation->getPropertyPath())[0];
      $errors[$root === '' ? '_entity' : $root][] = strip_tags((string) $violation->getMessage());
    }
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
  }

}
