<?php

declare(strict_types=1);

namespace Drupal\editor_api\Value;

use Drupal\Core\Session\AccountInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\filter\FilterFormatRepositoryInterface;

/**
 * Champs texte formaté : la chaîne HTML voyage VERBATIM, le format est conservé.
 *
 * Le module ne nettoie ni ne convertit : c'est le client qui respecte
 * `allowed_html`, et les filtres de Drupal qui font leur travail au rendu.
 */
final class FormattedText {

  public function __construct(private readonly FilterFormatRepositoryInterface $formats) {}

  /**
   * Éléments et attributs permis par le filtre `filter_html` d'un format.
   *
   * `[]` quand le format n'existe pas ou n'a pas ce filtre : tout est permis.
   */
  public function allowedHtml(string $format): array {
    $entity = FilterFormat::load($format);
    if ($entity === NULL || !$entity->filters()->has('filter_html')) {
      return [];
    }
    $filter = $entity->filters('filter_html');
    if (!$filter->status) {
      return [];
    }
    preg_match_all('/<([a-z0-9-]+)([^>]*)>/i', (string) ($filter->settings['allowed_html'] ?? ''), $matches, PREG_SET_ORDER);
    $allowed = [];
    foreach ($matches as $match) {
      $attributes = [];
      foreach (preg_split('/\s+/', trim($match[2])) ?: [] as $attribute) {
        if ($attribute !== '') {
          // `<ol start type='1 A I'>` : on garde le nom, pas la liste de valeurs.
          $attributes[] = preg_replace('/=.*$/s', '', $attribute);
        }
      }
      $allowed[] = ['tag' => strtolower($match[1]), 'attributes' => $attributes];
    }
    return $allowed;
  }

  /**
   * Le format qu'un nouvel item recevra : le premier que l'utilisateur peut employer.
   */
  public function defaultFormat(AccountInterface $account): string {
    return $this->formats->getDefaultFormat($account)->id();
  }

  public function itemValue(string $html, ?string $existingFormat, AccountInterface $account): array {
    return ['value' => $html, 'format' => $existingFormat ?: $this->defaultFormat($account)];
  }

}
