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

  public static function assertNotStale(Request $request, NodeInterface $latest): void {
    $base = $request->headers->get(self::HEADER);
    if ($base === NULL || $base === '') {
      return;
    }
    try {
      $baseTime = (new \DateTimeImmutable($base))->getTimestamp();
    }
    catch (\Exception) {
      throw ApiException::validation([self::HEADER => ['The header must be an ISO 8601 date.']]);
    }
    if ((int) $latest->getChangedTime() > $baseTime) {
      throw ApiException::conflict('The entry was modified since you loaded it.');
    }
  }

}
