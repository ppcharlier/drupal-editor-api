<?php

declare(strict_types=1);

namespace Drupal\editor_api\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Décode le corps JSON d'une requête, strictement.
 *
 * Aucun nettoyage des chaînes : espaces et chaînes vides sont conservés tels
 * quels (fidélité byte pour byte du contrat).
 */
final class RequestBody {

  public static function json(Request $request): array {
    $content = (string) $request->getContent();
    if (trim($content) === '') {
      return [];
    }
    try {
      $decoded = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw ApiException::validation([], 'Malformed JSON body.');
    }
    if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
      throw ApiException::validation([], 'The request body must be a JSON object.');
    }
    return $decoded;
  }

}
