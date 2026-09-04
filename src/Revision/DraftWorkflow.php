<?php

declare(strict_types=1);

namespace Drupal\editor_api\Revision;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\editor_api\Access\PublishAccess;
use Drupal\node\NodeInterface;

/**
 * Le modèle d'écriture du contrat, dans les deux modes.
 *
 * Sous Content Moderation : une modification est une révision `draft`, en
 * attente (non par défaut) si le node est publié — c'est content_moderation
 * qui décide de `isDefaultRevision` d'après l'état. Sans modération : une
 * révision ordinaire, le statut ne bouge pas.
 */
final class DraftWorkflow {

  public const DRAFT = 'draft';
  public const PUBLISHED = 'published';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PublishAccess $publish,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function isModerated(NodeInterface $node): bool {
    return $this->publish->isModeratedBundle($node->bundle());
  }

  /**
   * Date, signe et journalise la révision à venir ; `changed` passe à maintenant
   * (AVANT la validation, pour la contrainte EntityChanged du cœur).
   */
  public function stamp(NodeInterface $node, ?string $message): void {
    $now = $this->time->getRequestTime();
    $node->setNewRevision(TRUE);
    $node->setRevisionCreationTime($now);
    $node->setRevisionUserId((int) $this->currentUser->id());
    $node->setRevisionLogMessage($message ?? '');
    $node->setChangedTime($now);
  }

  public function create(NodeInterface $node, bool $published): NodeInterface {
    if ($this->isModerated($node)) {
      $node->set('moderation_state', $published ? self::PUBLISHED : self::DRAFT);
    }
    else {
      // `setPublished()` ne prend aucun argument (il publie inconditionnellement) :
      // le pendant pour dépublier est `setUnpublished()`.
      $published ? $node->setPublished() : $node->setUnpublished();
    }
    $node->save();
    return $this->reload($node);
  }

  public function saveEdit(NodeInterface $node): NodeInterface {
    if ($this->isModerated($node)) {
      $node->set('moderation_state', self::DRAFT);
    }
    $node->save();
    return $this->reload($node);
  }

  /**
   * La révision par défaut, relue depuis le stockage.
   */
  public function reload(NodeInterface $node): NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$node->id()]);
    /** @var \Drupal\node\NodeInterface $fresh */
    $fresh = $storage->load($node->id());
    return $fresh;
  }

}
