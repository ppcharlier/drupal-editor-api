<?php

declare(strict_types=1);

namespace Drupal\editor_api\Value;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Http\ApiException;

/**
 * JSON du contrat → valeurs de champ. Aucune clé hors blueprint ne passe.
 *
 * Scalaire ou tableau sont acceptés dans les deux sens (convention Statamic :
 * un client « Control Panel » envoie `["id"]`, un client qui renvoie `GET` envoie
 * `"id"`). Une référence inexistante ou hors périmètre est un 422, jamais un
 * abandon silencieux.
 */
final class ValueWriter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormattedText $formatted,
  ) {}

  /**
   * @param array $fields
   *   La table `fields` de BlueprintBuilder::describe().
   */
  public function write(ContentEntityInterface $entity, array $data, array $fields, AccountInterface $account): void {
    foreach (array_keys($data) as $handle) {
      if (!isset($fields[$handle])) {
        throw ApiException::unknownField((string) $handle);
      }
    }
    $errors = [];
    foreach ($data as $handle => $value) {
      $field = $fields[$handle];
      try {
        $entity->set($field['field_name'], $this->convert($entity, $field, $value, $account));
      }
      catch (FieldValueError $e) {
        $errors[$handle] = [$e->getMessage()];
      }
    }
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
  }

  private function convert(ContentEntityInterface $entity, array $field, mixed $value, AccountInterface $account): array {
    if ($value === NULL) {
      return [];
    }
    $list = is_array($value) && array_is_list($value) ? $value : [$value];
    if (!$field['multiple'] && count($list) > 1) {
      throw new FieldValueError('This field accepts a single value.');
    }
    if ($field['multiple'] && $field['cardinality'] > 0 && count($list) > $field['cardinality']) {
      throw new FieldValueError("This field accepts at most {$field['cardinality']} values.");
    }
    $items = [];
    foreach ($list as $delta => $one) {
      $items[] = $this->item($entity, $field, $one, (int) $delta, $account);
    }
    return $items;
  }

  private function item(ContentEntityInterface $entity, array $field, mixed $value, int $delta, AccountInterface $account): array {
    $type = $field['type'];
    switch ($type) {
      case 'text':
      case 'textarea':
      case 'list':
        return ['value' => self::string($value)];

      case 'html':
        $existing = $entity->get($field['field_name'])->get($delta);
        return $this->formatted->itemValue(self::string($value), $existing?->format, $account);

      case 'toggle':
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1'], TRUE)) {
          throw new FieldValueError('This field must be true or false.');
        }
        return ['value' => (int) (bool) $value];

      case 'select':
      case 'button_group':
      case 'checkboxes':
      case 'radio':
        // Comparaison STRICTE, en chaînes : un booléen ou un tableau n'est jamais une option,
        // et `false` ne doit pas « valoir » l'option '0' (comparaison lâche de PHP).
        $allowed = array_map('strval', array_column($field['config']['options'] ?? [], 'value'));
        if (is_bool($value) || !is_scalar($value) || !in_array((string) $value, $allowed, TRUE)) {
          throw new FieldValueError('The selected value is invalid.');
        }
        return ['value' => $value];

      case 'integer':
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
          throw new FieldValueError('This field must be an integer.');
        }
        return ['value' => (int) $value];

      case 'date':
        return ['value' => self::dateValue(self::string($value), $field)];

      case 'terms':
        return ['target_id' => $this->termId(self::string($value), $field['config']['taxonomies'] ?? [])];

      case 'assets':
        return ['target_id' => $this->mediaId(self::string($value), $field['config']['container'] ?? NULL)];

      case 'users':
        $uid = self::string($value);
        if (!ctype_digit($uid) || $this->entityTypeManager->getStorage('user')->load((int) $uid) === NULL) {
          throw new FieldValueError('The selected user does not exist.');
        }
        return ['target_id' => (int) $uid];

      default:
        return is_array($value) ? $value : ['value' => $value];
    }
  }

  private static function string(mixed $value): string {
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
      throw new FieldValueError('This field must be a string.');
    }
    return (string) $value;
  }

  /**
   * Accepte `Y-m-d` et ISO 8601 ; stocke au format du champ, en UTC.
   */
  private static function dateValue(string $input, array $field): string {
    try {
      $date = new \DateTimeImmutable($input, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      throw new FieldValueError('This field must be a valid date.');
    }
    $dateOnly = ($field['definition']->getFieldStorageDefinition()->getSetting('datetime_type') ?? 'datetime') === 'date';
    return $date->setTimezone(new \DateTimeZone('UTC'))->format($dateOnly ? 'Y-m-d' : 'Y-m-d\TH:i:s');
  }

  private function termId(string $value, array $taxonomies): int {
    // `regions::12` ou `12`.
    $parts = explode('::', $value, 2);
    $tid = count($parts) === 2 ? $parts[1] : $parts[0];
    $term = ctype_digit($tid) ? $this->entityTypeManager->getStorage('taxonomy_term')->load((int) $tid) : NULL;
    if ($term === NULL || ($taxonomies !== [] && !in_array($term->bundle(), $taxonomies, TRUE))) {
      throw new FieldValueError("The term {$value} does not exist in the allowed taxonomies.");
    }
    return (int) $term->id();
  }

  private function mediaId(string $value, ?string $container): int {
    // `image::12/photo.jpg` ou `12/photo.jpg`.
    $parts = explode('::', $value, 2);
    $path = count($parts) === 2 ? $parts[1] : $parts[0];
    $mid = explode('/', $path, 2)[0];
    $media = ctype_digit($mid) ? $this->entityTypeManager->getStorage('media')->load((int) $mid) : NULL;
    if ($media === NULL || ($container !== NULL && $media->bundle() !== $container)) {
      throw new FieldValueError("The asset {$value} does not exist in this container.");
    }
    return (int) $media->id();
  }

}
