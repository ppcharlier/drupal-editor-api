<?php

declare(strict_types=1);

namespace Drupal\editor_api\Value;

/**
 * Une valeur qu'un champ ne peut pas prendre ; devient une erreur 422 par champ.
 */
final class FieldValueError extends \RuntimeException {}
