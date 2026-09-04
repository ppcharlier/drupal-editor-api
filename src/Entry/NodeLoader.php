<?php

declare(strict_types=1);

namespace Drupal\editor_api\Entry;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\editor_api\Http\ApiException;
use Drupal\node\NodeInterface;

/**
 * Charge un node par son id du contrat, ou 404. Vérifie qu'une collection existe.
 */
final class NodeLoader {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  public function load(string $id): NodeInterface {
    $node = ctype_digit($id) ? $this->entityTypeManager->getStorage('node')->load((int) $id) : NULL;
    if (!$node instanceof NodeInterface) {
      throw ApiException::notFound();
    }
    return $node;
  }

  public function assertCollection(string $collection): void {
    if ($this->entityTypeManager->getStorage('node_type')->load($collection) === NULL) {
      throw ApiException::notFound();
    }
  }

}
