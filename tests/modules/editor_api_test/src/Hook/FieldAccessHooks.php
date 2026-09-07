<?php

declare(strict_types=1);

namespace Drupal\editor_api_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Rend un champ non modifiable, à la demande d'un test.
 *
 * Drupal n'a aucun moyen natif de retirer le droit d'éditer UN champ : c'est ce que font des
 * modules contribués comme Field Permissions, par ce hook précisément. Un test qui veut éprouver
 * ce cas doit donc l'implanter lui-même. Le nom du champ verrouillé vit dans l'état, pour que
 * seuls les tests qui le posent en subissent l'effet.
 */
final class FieldAccessHooks {

  public const STATE_KEY = 'editor_api_test.locked_field';

  #[Hook('entity_field_access')]
  public function entityFieldAccess(
    string $operation,
    FieldDefinitionInterface $field_definition,
    AccountInterface $account,
    ?FieldItemListInterface $items = NULL,
  ): AccessResultInterface {
    $locked = \Drupal::state()->get(self::STATE_KEY);
    if ($operation === 'edit' && $locked !== NULL && $field_definition->getName() === $locked) {
      // `setCacheMaxAge(0)` : l'état change d'un test à l'autre, aucune mise en cache ne doit
      // survivre à ce changement.
      return AccessResult::forbidden('Locked by editor_api_test.')->setCacheMaxAge(0);
    }
    return AccessResult::neutral()->setCacheMaxAge(0);
  }

}
