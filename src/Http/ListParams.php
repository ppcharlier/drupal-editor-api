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
 *
 * Deux points d'entrée : `fromRequest()` pour les ressources avec recherche
 * et tri (entries, terms) ; `pagination()` pour celles qui n'en ont pas
 * (assets), afin de ne pas valider un `search` que l'endpoint ne lit jamais.
 */
final class ListParams {

  public function __construct(
    public readonly int $page,
    public readonly int $perPage,
    public readonly string $search,
    public readonly string $sort,
  ) {}

  /**
   * Pagination seule (page, per_page) pour les ressources sans recherche ni tri.
   */
  public static function pagination(Request $request, array &$errors, int $defaultPerPage = 25): self {
    $page = (int) $request->query->get('page', 1);
    if ($page < 1) {
      $errors['page'] = ['The page must be at least 1.'];
    }
    $perPage = (int) $request->query->get('per_page', $defaultPerPage);
    if ($perPage < 1 || $perPage > 100) {
      $errors['per_page'] = ['The per_page must be between 1 and 100.'];
    }
    return new self($page, $perPage, '', '');
  }

  /**
   * @param array<string, string> $sorts
   *   Handles de tri acceptés (handle => champ) ; vide = aucun contrôle du tri.
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
    $base = self::pagination($request, $errors, $defaultPerPage);
    return new self($base->page, $base->perPage, $search, $sort);
  }

}
