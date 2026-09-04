<?php

declare(strict_types=1);

namespace Drupal\editor_api\Payload;

/**
 * Trie des entités de configuration par libellé, sans sensibilité à la casse.
 */
final class LabelSort {

  public static function byLabel(array $entities): array {
    uasort($entities, fn($a, $b) => strcasecmp((string) $a->label(), (string) $b->label()));
    return $entities;
  }

}
