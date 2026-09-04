<?php

declare(strict_types=1);

namespace Drupal\editor_api\Blueprint;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Http\ApiException;
use Drupal\field\FieldConfigInterface;

/**
 * Le blueprint compact d'un bundle, dans l'ordre de son formulaire par défaut.
 *
 * Un onglet `main`, `title` toujours premier, puis les champs visibles du
 * formulaire, puis `slug` et `date` en `meta: true`. Les handles sont les noms
 * machine Drupal, verbatim ; seul le champ libellé est renommé `title`.
 */
final class BlueprintBuilder {

  /**
   * Champs de base portés ailleurs dans le contrat, ou sans objet pour l'app.
   */
  private const EXCLUDED = [
    'uid', 'status', 'moderation_state', 'promote', 'sticky', 'path', 'created', 'changed', 'revision_log',
    'revision_timestamp', 'revision_uid', 'revision_default', 'revision_translation_affected', 'langcode',
    'default_langcode', 'content_translation_source', 'content_translation_outdated', 'menu_link', 'comment',
    'uuid', 'vid', 'nid', 'tid', 'type', 'weight', 'parent', 'publish_on', 'unpublish_on',
  ];

  /**
   * Champs de base exposés malgré tout, par type d'entité.
   */
  private const ALLOWED_BASE = ['taxonomy_term' => ['description']];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityDisplayRepositoryInterface $displays,
    private readonly FieldMapper $mapper,
  ) {}

  public function forNodeType(string $bundle, AccountInterface $account): array {
    return $this->describe('node', $bundle, $account)['blueprint'];
  }

  public function forVocabulary(string $vid, AccountInterface $account): array {
    return $this->describe('taxonomy_term', $vid, $account)['blueprint'];
  }

  /**
   * Le blueprint ET la table des champs (pour lire et écrire les valeurs).
   *
   * @return array{blueprint: array, fields: array<string, array{field_name: string, type: string, config: array, multiple: bool, cardinality: int, definition: \Drupal\Core\Field\FieldDefinitionInterface}>}
   */
  public function describe(string $entityTypeId, string $bundle, AccountInterface $account): array {
    $bundleEntity = $this->entityTypeManager->getStorage($this->entityTypeManager->getDefinition($entityTypeId)->getBundleEntityType())->load($bundle);
    if ($bundleEntity === NULL) {
      throw ApiException::notFound();
    }
    $definitions = $this->fieldManager->getFieldDefinitions($entityTypeId, $bundle);
    $labelKey = $this->entityTypeManager->getDefinition($entityTypeId)->getKey('label');
    $components = $this->displays->getFormDisplay($entityTypeId, $bundle)->getComponents();
    uasort($components, fn(array $a, array $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    $fields = ['title' => $this->fieldSpec('title', $definitions[$labelKey], $components[$labelKey] ?? NULL, $account)];
    foreach ($components as $name => $component) {
      if ($name === $labelKey || in_array($name, self::EXCLUDED, TRUE) || !isset($definitions[$name])) {
        continue;
      }
      $definition = $definitions[$name];
      if (!$definition instanceof FieldConfigInterface && !in_array($name, self::ALLOWED_BASE[$entityTypeId] ?? [], TRUE)) {
        continue;
      }
      $fields[$name] = $this->fieldSpec($name, $definition, $component, $account);
    }

    $specs = array_map(fn(array $f) => $f['spec'], $fields);
    $specs[] = ['handle' => 'slug', 'type' => 'slug', 'display' => 'Slug', 'instructions' => NULL, 'required' => TRUE, 'rules' => ['required'], 'meta' => TRUE, 'config' => []];
    if ($entityTypeId === 'node') {
      $specs[] = ['handle' => 'date', 'type' => 'date', 'display' => 'Date', 'instructions' => NULL, 'required' => FALSE, 'rules' => [], 'meta' => TRUE, 'config' => ['time_enabled' => FALSE]];
    }
    return [
      'blueprint' => [
        'handle' => $bundle,
        'title' => (string) $bundleEntity->label(),
        'tabs' => [['handle' => 'main', 'display' => 'Content', 'fields' => array_values($specs)]],
      ],
      'fields' => array_map(fn(array $f) => $f['table'], $fields),
    ];
  }

  private function fieldSpec(string $handle, FieldDefinitionInterface $definition, ?array $component, AccountInterface $account): array {
    $mapped = $this->mapper->map($definition, $component, $account);
    $rules = $definition->isRequired() ? ['required'] : [];
    if ($definition->getType() === 'string' && !$mapped['multiple'] && ($max = (int) $definition->getFieldStorageDefinition()->getSetting('max_length')) > 0) {
      $rules[] = 'max:' . $max;
    }
    $instructions = trim((string) $definition->getDescription());
    return [
      'spec' => [
        'handle' => $handle,
        'type' => $mapped['type'],
        'display' => (string) $definition->getLabel(),
        'instructions' => $instructions === '' ? NULL : $instructions,
        'required' => $definition->isRequired(),
        'rules' => $rules,
        'meta' => FALSE,
        'config' => $mapped['config'],
      ],
      'table' => [
        'field_name' => $definition->getName(),
        'type' => $mapped['type'],
        'config' => $mapped['config'],
        'multiple' => $mapped['multiple'],
        'cardinality' => $mapped['cardinality'],
        'definition' => $definition,
      ],
    ];
  }

}
