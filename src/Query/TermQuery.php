<?php

declare(strict_types=1);

namespace Drupal\editor_api\Query;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * La liste d'un vocabulaire : recherche sur le nom, tri allow-listé, pagination.
 *
 * `slug` trie aussi par `name` : les alias suivent les noms. Les termes non
 * publiés ne sont visibles qu'avec `administer taxonomy` (règle de
 * TermAccessControlHandler, posée dans la requête).
 */
final class TermQuery {

  public const SORTS = ['title' => 'name', 'slug' => 'name'];

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * @return array{items: \Drupal\taxonomy\TermInterface[], total: int}
   */
  public function run(AccountInterface $account, string $vid, ?string $search, string $sort, int $page, int $perPage): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('vid', $vid);
    if (!$account->hasPermission('administer taxonomy')) {
      $query->condition('status', 1);
    }
    if ($search !== NULL && $search !== '') {
      $query->condition('name', $search, 'CONTAINS');
    }
    $total = (int) (clone $query)->count()->execute();
    $descending = str_starts_with($sort, '-');
    $query->sort(self::SORTS[ltrim($sort, '-')], $descending ? 'DESC' : 'ASC')
      ->sort('tid', 'ASC')
      ->range(($page - 1) * $perPage, $perPage);
    return ['items' => array_values($storage->loadMultiple($query->execute())), 'total' => $total];
  }

}
