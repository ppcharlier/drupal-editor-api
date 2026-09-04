<?php

declare(strict_types=1);

namespace Drupal\editor_api\Payload;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Blueprint\BlueprintBuilder;
use Drupal\editor_api\Entry\SlugAlias;
use Drupal\editor_api\Value\ValueReader;
use Drupal\node\NodeInterface;

/**
 * Les formes `summary` et `detail` d'une entrée.
 *
 * Quand une révision en attente existe, le détail décrit LE BROUILLON
 * (titre, date, data) et le node publié pour `status`/`published`.
 */
final class EntryPayload {

  public const SITE = 'default';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SlugAlias $slug,
    private readonly Capabilities $capabilities,
    private readonly BlueprintBuilder $blueprints,
    private readonly ValueReader $reader,
    private readonly ?ModerationInformationInterface $moderation,
  ) {}

  public function summary(NodeInterface $node, AccountInterface $account): array {
    $owner = $node->getOwner();
    return [
      'id' => (string) $node->id(),
      'collection' => $node->bundle(),
      'slug' => $this->slug->read($node),
      'title' => $node->label(),
      'status' => $node->isPublished() ? 'published' : 'draft',
      'published' => $node->isPublished(),
      'date' => self::iso($node->getCreatedTime()),
      'has_unpublished_changes' => $this->hasPendingRevision($node),
      'last_modified' => self::iso($node->getChangedTime()),
      'author' => $owner && (int) $owner->id() > 0 ? ['id' => (string) $owner->id(), 'name' => $owner->getDisplayName()] : NULL,
      'can' => $this->capabilities->forNode($node, $account),
    ];
  }

  public function detail(NodeInterface $node, AccountInterface $account): array {
    $working = $this->workingCopy($node, $account);
    $described = $this->blueprints->describe('node', $node->bundle(), $account);
    $payload = $this->summary($node, $account);
    $payload['title'] = $working->label();
    $payload['date'] = self::iso($working->getCreatedTime());
    // `last_modified` décrit la copie de travail lue, pas la révision par défaut :
    // c'est cette valeur que le client renvoie en `X-Base-Modified`, et c'est à la
    // copie de travail que le contrôle de fraîcheur la compare.
    $payload['last_modified'] = self::iso($working->getChangedTime());
    return $payload + [
      'blueprint' => $node->bundle(),
      'data' => $this->reader->readAll($working, $described['fields']),
      'site' => self::SITE,
      'localizations' => [['site' => self::SITE, 'id' => (string) $node->id()]],
    ];
  }

  public function hasPendingRevision(NodeInterface $node): bool {
    return $this->moderation !== NULL && $this->moderation->isModeratedEntity($node) && $this->moderation->hasPendingRevision($node);
  }

  /**
   * La copie de travail : le brouillon en attente s'il existe, sinon le node.
   *
   * Avec un `$viewer`, le brouillon n'est rendu qu'à qui a le droit de le voir
   * (`view latest version` de content_moderation, ou le contournement complet) —
   * les chemins d'écriture appellent sans viewer, ils travaillent toujours sur
   * la dernière révision.
   */
  public function workingCopy(NodeInterface $node, ?AccountInterface $viewer = NULL): NodeInterface {
    if (!$this->hasPendingRevision($node)) {
      return $node;
    }
    if ($viewer !== NULL && !$viewer->hasPermission('bypass node access') && !$viewer->hasPermission('view latest version')) {
      return $node;
    }
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    /** @var \Drupal\node\NodeInterface $latest */
    $latest = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
    return $latest;
  }

  /**
   * `int|string` : `NodeInterface::getCreatedTime()`/`getChangedTime()` sont
   * documentées `@return int` mais non typées ; une fois le node rechargé
   * depuis le stockage SQL, `$this->get('created')->value` revient en
   * chaîne (`PDO::ATTR_STRINGIFY_FETCHES` côté \Drupal\Core\Database).
   */
  public static function iso(int|string|null $timestamp): ?string {
    return $timestamp === NULL ? NULL : (new \DateTimeImmutable('@' . $timestamp))->format(DATE_ATOM);
  }

}
