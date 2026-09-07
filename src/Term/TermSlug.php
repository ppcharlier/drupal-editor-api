<?php

declare(strict_types=1);

namespace Drupal\editor_api\Term;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\editor_api\Entry\SlugAlias;
use Drupal\editor_api\Http\ApiException;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Le `slug` d'un terme ↔ son alias d'URL (`/{vocab}/{slug}`), comme pour un node.
 *
 * Les routes du contrat adressent un terme par son slug : on résout d'abord
 * l'alias, puis le tid en repli (un terme sans alias a son tid pour slug).
 */
final class TermSlug {

  public function __construct(
    private readonly AliasManagerInterface $aliasManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  public function read(TermInterface $term): string {
    $system = '/taxonomy/term/' . $term->id();
    if (!$this->moduleHandler->moduleExists('path') || $term->isNew()) {
      return (string) $term->id();
    }
    $alias = $this->aliasManager->getAliasByPath($system, $term->language()->getId());
    return $alias === $system ? (string) $term->id() : basename($alias);
  }

  public function aliasFor(string $vid, string $slug): string {
    return '/' . $vid . '/' . $slug;
  }

  public function assertAvailable(string $alias, ?TermInterface $except): void {
    $path = $this->aliasManager->getPathByAlias($alias);
    $ownPath = $except !== NULL && !$except->isNew() ? '/taxonomy/term/' . $except->id() : NULL;
    if ($path !== $alias && $path !== $ownPath) {
      throw ApiException::validation(['slug' => ['The slug is already taken in this taxonomy.']]);
    }
  }

  /**
   * @return string[]
   *   Le champ de base touché — `path`, et rien du tout sans le module `path`.
   */
  public function apply(TermInterface $term, string $slug): array {
    if (!$this->moduleHandler->moduleExists('path')) {
      return [];
    }
    SlugAlias::validate($slug);
    $alias = $this->aliasFor($term->bundle(), $slug);
    $this->assertAvailable($alias, $term);
    // Conserver le pid existant : sans lui, PathItem::postSave() ne retrouve
    // plus l'alias par (chemin, alias, langue) — puisque l'alias vient de
    // changer — et crée un second alias au lieu de renommer celui du terme,
    // laissant l'ancien slug répondre encore après un changement de slug.
    $pid = $term->isNew() ? NULL : $term->get('path')->pid;
    $term->set('path', ['alias' => $alias, 'pid' => $pid]);
    return ['path'];
  }

  /**
   * Le terme désigné par `{slug}` dans le vocabulaire, ou 404.
   */
  public function resolve(string $vid, string $slug): TermInterface {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term = NULL;
    if ($this->moduleHandler->moduleExists('path')) {
      $path = $this->aliasManager->getPathByAlias($this->aliasFor($vid, $slug));
      if (preg_match('#^/taxonomy/term/(\d+)$#', $path, $m)) {
        $term = $storage->load((int) $m[1]);
      }
    }
    if ($term === NULL && ctype_digit($slug)) {
      $term = $storage->load((int) $slug);
    }
    if (!$term instanceof TermInterface || $term->bundle() !== $vid) {
      throw ApiException::notFound();
    }
    return $term;
  }

}
