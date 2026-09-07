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

  /**
   * @param string[] $touched
   *   Les champs Drupal que la requête a réellement écrits. Vide (ou omis) : l'entité
   *   entière est validée — c'est le cas des créations et des médias, où tout vient de
   *   la requête. Sinon, seules les violations de ces champs sont retenues.
   */
  public static function assert(ContentEntityInterface $entity, array $touched = []): void {
    $violations = $entity->validate();
    if ($touched !== []) {
      // Le mécanisme du cœur (rest EntityResourceValidationTrait, jsonapi
      // EntityValidationTrait) : `filterByFields()` RETIRE les violations des champs
      // qu'on lui passe, on lui donne donc le complément des champs touchés. Le
      // pourquoi : une mise à jour qui ne mentionne pas un champ ne doit pas échouer
      // sur lui — un corps en `full_html` qu'un compte n'a pas le droit d'employer
      // bloquait jusqu'ici le simple renommage d'une entrée (défaut du 2026-09-07).
      // Les violations de niveau ENTITÉ (chemin de propriété vide, rendues sous
      // `_entity`) ne sont pas concernées : `EntityConstraintViolationList` les range à
      // part, hors de `filterByFields()`.
      $violations->filterByFields(array_diff(array_keys($entity->getFieldDefinitions()), $touched));
    }
    $renamed = self::RENAMED[$entity->getEntityTypeId()] ?? [];
    $errors = [];
    foreach ($violations as $violation) {
      $root = explode('.', (string) $violation->getPropertyPath())[0];
      $root = $renamed[$root] ?? $root;
      $errors[$root === '' ? '_entity' : $root][] = strip_tags((string) $violation->getMessage());
    }
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
  }

}
