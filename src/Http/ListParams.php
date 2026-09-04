<?php

declare(strict_types=1);

namespace Drupal\editor_api\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Les paramètres de liste du contrat, lus une fois pour les trois ressources.
 *
 * `page` ≥ 1, `per_page` 1–100 (défaut 25), `search` ≤ 200, `sort` dans une
 * allow-list avec préfixe `-`. Les erreurs sont accumulées dans `$errors`
 * (clé = paramètre) : l'appelant y ajoute ses propres contrôles puis lève un
 * seul `422 validation_failed`.
 */
final class ListParams {

  public function __construct(
    public readonly int $page,
    public readonly int $perPage,
    public readonly string $search,
    public readonly string $sort,
  ) {}

  /**
   * @param array<string, string> $sorts
   *   Handles de tri acceptés (handle => champ) ; vide = aucun tri accepté.
   */
  public static function fromRequest(Request $request, array $sorts, string $defaultSort, array &$errors, int $defaultPerPage = 25): self {
    $search = (string) $request->query->get('search', '');
    if (mb_strlen($search) > 200) {
      $errors['search'] = ['The search may not be greater than 200 characters.'];
    }
    $sort = (string) $request->query->get('sort', $defaultSort);
    if ($sorts !== [] && !isset($sorts[ltrim($sort, '-')])) {
      $errors['sort'] = ['The sort field is not allowed.'];
    }
    $page = (int) $request->query->get('page', 1);
    if ($page < 1) {
      $errors['page'] = ['The page must be at least 1.'];
    }
    $perPage = (int) $request->query->get('per_page', $defaultPerPage);
    if ($perPage < 1 || $perPage > 100) {
      $errors['per_page'] = ['The per_page must be between 1 and 100.'];
    }
    return new self($page, $perPage, $search, $sort);
  }

}
