<?php

declare(strict_types=1);

namespace Drupal\editor_api\Entry;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\editor_api\Http\ApiException;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Le `slug` du contrat ↔ l'alias d'URL du node (`/{bundle}/{slug}`).
 *
 * Sans le module `path`, le slug est le nid en lecture et ignoré en écriture.
 */
final class SlugAlias {

  public function __construct(
    private readonly AliasManagerInterface $aliasManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  public function read(NodeInterface $node): string {
    $system = '/node/' . $node->id();
    if (!$this->moduleHandler->moduleExists('path') || $node->isNew()) {
      return (string) $node->id();
    }
    $alias = $this->aliasManager->getAliasByPath($system, $node->language()->getId());
    return $alias === $system ? (string) $node->id() : basename($alias);
  }

  public static function validate(string $slug): void {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
      throw ApiException::validation(['slug' => ['The slug may only contain lowercase letters, digits and hyphens.']]);
    }
  }

  public function aliasFor(NodeInterface $node, string $slug): string {
    return '/' . $node->bundle() . '/' . $slug;
  }

  public function assertAvailable(string $alias, ?NodeInterface $except): void {
    $path = $this->aliasManager->getPathByAlias($alias);
    $ownPath = $except !== NULL && !$except->isNew() ? '/node/' . $except->id() : NULL;
    if ($path !== $alias && $path !== $ownPath) {
      throw ApiException::of(422, 'uri_taken', "The URI {$alias} is already taken.", ['slug' => ["The URI {$alias} is already taken."]]);
    }
  }

  public function apply(NodeInterface $node, string $slug): void {
    if (!$this->moduleHandler->moduleExists('path')) {
      return;
    }
    self::validate($slug);
    $alias = $this->aliasFor($node, $slug);
    $this->assertAvailable($alias, $node);
    $node->set('path', ['alias' => $alias]);
  }

}
