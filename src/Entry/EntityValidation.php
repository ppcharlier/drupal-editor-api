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
   * @param string[]|null $touched
   *   Les champs Drupal que la requête a réellement écrits. Deux valeurs distinctes, et
   *   la différence porte toute la propriété de sûreté :
   *   - NULL (omis) : l'entité entière est validée. C'est le cas des créations et des
   *     deux chemins média, où tout ce qui est dans l'entité vient de la requête.
   *   - un tableau, VIDE COMPRIS : seules les violations de ces champs sont retenues.
   *     Vide veut donc dire « ne valider aucun champ », et non « tout valider » : une
   *     requête qui n'écrit rien (un PATCH de terme avec une carte vide, sans `slug` ni
   *     `published`) ne protège aucune donnée en validant tout, elle ne peut que
   *     produire un 422 fantôme sur un champ auquel elle n'a pas touché.
   */
  public static function assert(ContentEntityInterface $entity, ?array $touched = NULL): void {
    $violations = $entity->validate();
    // Une violation portant sur un champ que le compte ne peut pas ÉDITER ne lui est pas
    // opposable : il n'a aucun moyen de la lever. C'est ce que fait le cœur (`rest`
    // EntityResourceValidationTrait, `jsonapi` EntityValidationTrait), et sans quoi un champ à
    // la fois REQUIS et réservé à un autre rôle rendrait toute création impossible. Le compte
    // considéré est celui de la requête en cours, comme dans le cœur.
    //
    // Cette ligne ne masque aucune écriture : `ValueWriter::write` refuse en 403, AVANT toute
    // écriture, tout champ reçu que le compte ne peut pas éditer. Ce qui reste ici ne peut donc
    // venir que de valeurs déjà en base ou posées par le module lui-même.
    $violations->filterByFieldAccess();
    if ($touched !== NULL) {
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
