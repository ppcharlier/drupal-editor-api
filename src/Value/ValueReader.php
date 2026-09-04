<?php

declare(strict_types=1);

namespace Drupal\editor_api\Value;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\editor_api\Term\TermSlug;
use Drupal\media\MediaInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Valeurs de champ → JSON du contrat : scalaire si cardinalité 1, tableau sinon.
 */
final class ValueReader {

  public function __construct(
    private readonly TermSlug $termSlug,
  ) {}

  /**
   * @param array $fields
   *   La table `fields` de BlueprintBuilder::describe().
   */
  public function readAll(ContentEntityInterface $entity, array $fields): array {
    $data = [];
    foreach ($fields as $handle => $field) {
      $data[$handle] = $this->read($entity->get($field['field_name']), $field);
    }
    return $data;
  }

  public function read(FieldItemListInterface $items, array $field): mixed {
    $values = [];
    foreach ($items as $item) {
      $value = $this->one($item, $field['type']);
      if ($value !== NULL) {
        $values[] = $value;
      }
    }
    return $field['multiple'] ? $values : ($values[0] ?? NULL);
  }

  private function one(FieldItemInterface $item, string $type): mixed {
    return match ($type) {
      'text', 'textarea', 'html', 'list' => (string) $item->value,
      'toggle' => (bool) $item->value,
      'select', 'button_group', 'checkboxes', 'radio' => $item->value,
      'integer' => (int) $item->value,
      'date' => self::isoDate($item),
      // Un `terms` vaut le slug du terme, comme les valeurs que l'app compare
      // aux `slug` de /taxonomies/{vocab}/terms ; une référence orpheline est
      // ignorée, comme pour les médias.
      'terms' => $item->entity instanceof TermInterface ? $this->termSlug->read($item->entity) : NULL,
      'users' => $item->target_id === NULL ? NULL : (string) $item->target_id,
      'assets' => self::assetPath($item),
      default => $item->getValue(),
    };
  }

  private static function isoDate(FieldItemInterface $item): ?string {
    /** @var \Drupal\Core\Datetime\DrupalDateTime|null $date */
    $date = $item->date;
    return $date ? $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM) : NULL;
  }

  public static function assetPath(FieldItemInterface $item): ?string {
    $media = $item->entity;
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    $file = $media->get($media->getSource()->getConfiguration()['source_field'])->entity;
    return $file ? $media->id() . '/' . $file->getFilename() : NULL;
  }

}
