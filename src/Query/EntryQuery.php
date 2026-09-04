<?php

declare(strict_types=1);

namespace Drupal\editor_api\Query;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * La liste d'une collection : filtre de statut, recherche sur le titre, tri
 * allow-listé, pagination. `scheduled` et `expired` sont vides : le cœur ne
 * planifie pas.
 */
final class EntryQuery {

  public const SORTS = ['title' => 'title', 'date' => 'created', 'last_modified' => 'changed'];
  public const STATUSES = ['any', 'published', 'draft', 'scheduled', 'expired'];

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * @return array{items: \Drupal\node\NodeInterface[], total: int}
   */
  public function run(AccountInterface $account, string $bundle, string $status, ?string $search, string $sort, int $page, int $perPage): array {
    if (in_array($status, ['scheduled', 'expired'], TRUE)) {
      return ['items' => [], 'total' => 0];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('type', $bundle);
    // Sans module de grants, `accessCheck(TRUE)` ne filtre rien : on applique la règle
    // de vue du cœur nous-mêmes — publié pour tous, non publié pour le contournement,
    // pour « view any unpublished content » (défini par content_moderation) et pour
    // le propriétaire qui a « view own unpublished content ».
    if (!$account->hasPermission('bypass node access') && !$account->hasPermission('view any unpublished content')) {
      if ($account->hasPermission('view own unpublished content')) {
        $query->condition($query->orConditionGroup()->condition('status', 1)->condition('uid', $account->id()));
      }
      else {
        $query->condition('status', 1);
      }
    }
    if ($status === 'published') {
      $query->condition('status', 1);
    }
    elseif ($status === 'draft') {
      $query->condition('status', 0);
    }
    if ($search !== NULL && $search !== '') {
      $query->condition('title', $search, 'CONTAINS');
    }
    $total = (int) (clone $query)->count()->execute();
    $descending = str_starts_with($sort, '-');
    $query->sort(self::SORTS[ltrim($sort, '-')], $descending ? 'DESC' : 'ASC')
      ->sort('nid', 'ASC')
      ->range(($page - 1) * $perPage, $perPage);
    return ['items' => array_values($storage->loadMultiple($query->execute())), 'total' => $total];
  }

}
