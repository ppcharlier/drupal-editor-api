<?php

declare(strict_types=1);

namespace Drupal\editor_api\Query;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * La liste d'un type de média, triée par nom. Règle de vue de
 * MediaAccessControlHandler posée dans la requête : tout pour `administer media` ;
 * sinon le publié requiert `view media`, et les non publiés du propriétaire
 * requièrent `view own unpublished media` — les deux permissions sont
 * indépendantes (`checkAccess()` du cœur ne fait jamais retomber l'une sur
 * l'autre).
 */
final class MediaQuery {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * @return array{items: \Drupal\media\MediaInterface[], total: int}
   */
  public function run(AccountInterface $account, string $type, int $page, int $perPage): array {
    $storage = $this->entityTypeManager->getStorage('media');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('bundle', $type);
    if (!$account->hasPermission('administer media')) {
      $group = $query->orConditionGroup();
      $visible = FALSE;
      if ($account->hasPermission('view media')) {
        $group->condition('status', 1);
        $visible = TRUE;
      }
      if ($account->hasPermission('view own unpublished media')) {
        $group->condition($query->andConditionGroup()->condition('status', 0)->condition('uid', $account->id()));
        $visible = TRUE;
      }
      // Aucune des deux permissions : rien n'est visible.
      $visible ? $query->condition($group) : $query->condition('mid', -1, '=');
    }
    $total = (int) (clone $query)->count()->execute();
    $query->sort('name', 'ASC')->sort('mid', 'ASC')->range(($page - 1) * $perPage, $perPage);
    return ['items' => array_values($storage->loadMultiple($query->execute())), 'total' => $total];
  }

}
