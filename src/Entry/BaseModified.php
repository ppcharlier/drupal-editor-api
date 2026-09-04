<?php

declare(strict_types=1);

namespace Drupal\editor_api\Entry;

use Drupal\editor_api\Http\ApiException;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `X-Base-Modified` : le client dit ce qu'il a lu ; si l'entrée a bougé depuis, 409.
 *
 * Absent = « écrase, je sais ce que je fais » : le contrôle est opt-in par requête.
 */
final class BaseModified {

  public const HEADER = 'X-Base-Modified';

  /**
   * Les formes acceptées : celle que l'API émet (`DATE_ATOM`), puis les deux
   * variantes ISO 8601 courantes des clients — suffixe `Z` et fractions de
   * seconde. Tout le reste est refusé : `strtotime()` acceptait « now » ou
   * « yesterday » et transformait une faute du client en écrasement silencieux.
   */
  private const FORMATS = [DATE_ATOM, 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.uP'];

  public static function assertNotStale(Request $request, NodeInterface $latest): void {
    $base = $request->headers->get(self::HEADER);
    if ($base === NULL || $base === '') {
      return;
    }
    $baseTime = self::parse($base);
    if ($baseTime === NULL) {
      throw ApiException::validation([self::HEADER => ['The header must be an ISO 8601 date.']]);
    }
    if ((int) $latest->getChangedTime() > $baseTime) {
      throw ApiException::conflict('The entry was modified since you loaded it.');
    }
  }

  /**
   * L'horodatage, ou NULL si aucune forme acceptée ne correspond EXACTEMENT
   * (`createFromFormat()` tolère des données en trop avec un simple avertissement :
   * on refuse aussi les avertissements).
   */
  private static function parse(string $base): ?int {
    foreach (self::FORMATS as $format) {
      $date = \DateTimeImmutable::createFromFormat($format, $base, new \DateTimeZone('UTC'));
      if ($date !== FALSE && \DateTimeImmutable::getLastErrors() === FALSE) {
        return $date->getTimestamp();
      }
    }
    return NULL;
  }

}
