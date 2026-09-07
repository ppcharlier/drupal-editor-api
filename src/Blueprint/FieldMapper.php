<?php

declare(strict_types=1);

namespace Drupal\editor_api\Blueprint;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Value\FormattedText;

/**
 * Une définition de champ Drupal → un type de champ Editor et sa `config`.
 *
 * Table de la spec §6. Un type inconnu ressort verbatim avec une config vide :
 * l'app le montre en lecture seule.
 */
final class FieldMapper {

  public function __construct(private readonly FormattedText $formatted) {}

  /**
   * @return array{type: string, config: array, multiple: bool, cardinality: int}
   */
  public function map(FieldDefinitionInterface $def, ?array $component, AccountInterface $account): array {
    $storage = $def->getFieldStorageDefinition();
    $cardinality = $storage->getCardinality();
    $multiple = $cardinality !== 1;
    $widget = (string) ($component['type'] ?? '');
    [$type, $config] = match ($def->getType()) {
      'string' => $multiple ? ['list', []] : ['text', array_filter(['character_limit' => (int) $storage->getSetting('max_length')])],
      'string_long' => ['textarea', []],
      'text_long', 'text_with_summary' => $this->html($def, $account),
      'boolean' => ['toggle', []],
      'list_string', 'list_integer', 'list_float' => $this->choice($storage, $widget, $multiple),
      'integer' => ['integer', array_filter(['min' => $def->getSetting('min'), 'max' => $def->getSetting('max')], fn($v) => $v !== NULL && $v !== '')],
      'datetime' => ['date', ['time_enabled' => $storage->getSetting('datetime_type') === 'datetime']],
      'entity_reference' => $this->reference($def, $multiple, $cardinality),
      default => [$def->getType(), []],
    };
    return ['type' => $type, 'config' => $config, 'multiple' => $multiple, 'cardinality' => $cardinality];
  }

  private function html(FieldDefinitionInterface $def, AccountInterface $account): array {
    // Le format d'un NOUVEL item, et les balises de CE format. Le format de la valeur EXISTANTE,
    // lui, voyage avec l'entrée (clé `formats`) : c'est lui que l'app suit pour éditer.
    $format = $this->formatted->defaultFormat($account, $def);
    return ['html', ['format' => $format, 'allowed_html' => $this->formatted->allowedHtml($format)]];
  }

  private function choice(FieldStorageDefinitionInterface $storage, string $widget, bool $multiple): array {
    $options = [];
    foreach ((array) $storage->getSetting('allowed_values') as $value => $label) {
      $options[] = ['value' => $value, 'label' => (string) $label];
    }
    $buttons = $widget === 'options_buttons';
    if ($multiple) {
      return $buttons ? ['checkboxes', ['options' => $options]] : ['select', ['options' => $options, 'multiple' => TRUE]];
    }
    return $buttons ? ['button_group', ['options' => $options]] : ['select', ['options' => $options]];
  }

  private function reference(FieldDefinitionInterface $def, bool $multiple, int $cardinality): array {
    $bundles = array_values(array_keys((array) ($def->getSetting('handler_settings')['target_bundles'] ?? [])));
    $max = $multiple ? ($cardinality > 0 ? $cardinality : NULL) : 1;
    // Pas d'`array_filter` par défaut ici : `[]` est une valeur signifiante (toute vocabulaire), pas une absence.
    return match ($def->getSetting('target_type')) {
      'taxonomy_term' => ['terms', ['taxonomies' => $bundles] + ($max === NULL ? [] : ['max_items' => $max])],
      'media' => ['assets', ($bundles === [] ? [] : ['container' => $bundles[0]]) + ($max === NULL ? [] : ['max_files' => $max])],
      'user' => ['users', $max === NULL ? [] : ['max_items' => $max]],
      default => ['entity_reference', []],
    };
  }

}
