<?php

declare(strict_types=1);

namespace Drupal\editor_api\Value;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
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
   * Tous les formats ACTIVÉS du site, dans l'ordre de Drupal, avec le droit du compte à s'en
   * servir.
   *
   * Le pourquoi de « tous » et pas « ceux du compte » : l'app doit pouvoir NOMMER le format d'un
   * corps qu'elle affiche sans pouvoir le modifier. `can.use` faux lui dit d'ouvrir en lecture
   * seule plutôt que de laisser l'utilisateur écrire pour se prendre un 422.
   */
  public function catalogue(AccountInterface $account): array {
    $catalogue = [];
    foreach ($this->formats->getAllFormats() as $format) {
      $catalogue[] = [
        'id' => $format->id(),
        'name' => (string) $format->label(),
        'allowed_html' => $this->allowedHtml($format->id()),
        'can' => ['use' => $format->access('use', $account)],
      ];
    }
    return $catalogue;
  }

  /**
   * Le format de la valeur d'un champ : celui de son PREMIER item, `NULL` si le champ est vide.
   *
   * Les VALEURS de l'item plutôt que ses propriétés nommées : la carte des valeurs répond
   * simplement « absente » sur un champ qui n'a pas de colonne `format`, là où `$item->format`
   * dépendrait du type de champ. Limite assumée du contrat : un champ multivalué dont les items
   * mêlent deux formats est annoncé avec celui du premier ; l'écriture, elle, conserve le format
   * propre de chaque item.
   */
  public function formatOf(FieldItemListInterface $items): ?string {
    $format = $items->first()?->getValue()['format'] ?? NULL;
    return is_string($format) && $format !== '' ? $format : NULL;
  }

  /**
   * Le format qu'un NOUVEL item recevra : le premier que le compte peut employer ET que le champ
   * permet.
   *
   * Les `allowed_formats` du champ (réglage du cœur sur les champs texte) étaient ignorés : un
   * champ restreint à `full_html` recevait `basic_html`. On parcourt les formats du compte dans
   * l'ordre de Drupal — le même que celui du widget du cœur — et on garde le premier permis.
   * Quand aucun format permis n'est employable, on rend le défaut du compte : `itemValue()`
   * refusera l'écriture d'un 422, ce qui vaut mieux qu'un format inventé.
   */
  public function defaultFormat(AccountInterface $account, ?FieldDefinitionInterface $field = NULL): string {
    $permitted = array_filter((array) ($field?->getSetting('allowed_formats') ?? []));
    if ($permitted !== []) {
      foreach ($this->formats->getFormatsForAccount($account) as $format) {
        if (in_array($format->id(), $permitted, TRUE)) {
          return $format->id();
        }
      }
    }
    return $this->formats->getDefaultFormat($account)->id();
  }

  /**
   * La valeur d'un item : le format existant est CONSERVÉ, mais seulement si le
   * compte a le droit de s'en servir — sinon écrire reviendrait à signer du
   * contenu dans un format (full_html, par exemple) qu'on ne peut pas employer.
   * Le refus est une erreur de champ : `ValueWriter` en fait un 422 sur ce champ.
   */
  public function itemValue(string $html, ?string $existingFormat, AccountInterface $account): array {
    if ($existingFormat === NULL || $existingFormat === '') {
      return ['value' => $html, 'format' => $this->defaultFormat($account)];
    }
    $format = FilterFormat::load($existingFormat);
    if ($format === NULL || !$format->access('use', $account)) {
      throw new FieldValueError('You may not use the text format of this field.');
    }
    return ['value' => $html, 'format' => $existingFormat];
  }

}
