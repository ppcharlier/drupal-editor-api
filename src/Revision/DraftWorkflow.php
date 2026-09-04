<?php

declare(strict_types=1);

namespace Drupal\editor_api\Revision;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\editor_api\Access\PublishAccess;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Payload\EntryPayload;
use Drupal\node\NodeInterface;
use Drupal\workflows\WorkflowInterface;

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
    private readonly EntryPayload $payload,
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

  public function hasPending(NodeInterface $node): bool {
    return $this->payload->hasPendingRevision($node);
  }

  public function publish(NodeInterface $node, ?string $message): NodeInterface {
    if ($this->isModerated($node)) {
      if ($node->isPublished() && !$this->hasPending($node)) {
        throw ApiException::nothingToPublish();
      }
      $working = $this->payload->workingCopy($node);
      $this->stamp($working, $message);
      $working->set('moderation_state', self::PUBLISHED);
      $working->save();
      return $this->reload($node);
    }
    if ($node->isPublished()) {
      throw ApiException::nothingToPublish();
    }
    $this->stamp($node, $message);
    $node->setPublished();
    $node->save();
    return $this->reload($node);
  }

  public function unpublish(NodeInterface $node, ?string $message): NodeInterface {
    if (!$node->isPublished()) {
      throw ApiException::nothingToUnpublish();
    }
    if ($this->isModerated($node)) {
      $state = self::unpublishedDefaultState($this->publish->workflowFor($node->bundle()));
      if ($state === NULL) {
        throw ApiException::validation([], 'This workflow has no unpublished default-revision state.');
      }
      $working = $this->payload->workingCopy($node);
      $this->stamp($working, $message);
      $working->set('moderation_state', $state);
      $working->save();
      return $this->reload($node);
    }
    $this->stamp($node, $message);
    $node->setUnpublished();
    $node->save();
    return $this->reload($node);
  }

  /**
   * L'historique, plus récent d'abord.
   */
  public function revisions(NodeInterface $node): array {
    $this->assertRevisionsEnabled($node);
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $vids = $this->revisionIds($node);
    rsort($vids, SORT_NUMERIC);
    $revisions = [];
    foreach ($vids as $vid) {
      /** @var \Drupal\node\NodeInterface $revision */
      $revision = $storage->loadRevision($vid);
      $user = $revision->getRevisionUser();
      $message = trim((string) $revision->getRevisionLogMessage());
      $revisions[] = [
        'id' => (string) $vid,
        'action' => $revision->isPublished() ? 'publish' : 'revision',
        'date' => EntryPayload::iso($revision->getRevisionCreationTime()),
        'message' => $message === '' ? NULL : $message,
        'user' => $user && (int) $user->id() > 0 ? ['id' => (string) $user->id(), 'name' => $user->getDisplayName(), 'email' => (string) $user->getEmail()] : NULL,
      ];
    }
    return $revisions;
  }

  /**
   * Copie les valeurs d'une révision dans une nouvelle : brouillon en attente
   * si le node est publié, remplacement du brouillon sinon (sémantique CP).
   */
  public function restore(NodeInterface $node, string $vid, array $fields): NodeInterface {
    $this->assertRevisionsEnabled($node);
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    if (!ctype_digit($vid) || !in_array((int) $vid, $this->revisionIds($node), TRUE)) {
      throw ApiException::revisionNotFound();
    }
    /** @var \Drupal\node\NodeInterface $target */
    $target = $storage->loadRevision((int) $vid);
    $working = $this->payload->workingCopy($node);
    foreach ($fields as $field) {
      $working->set($field['field_name'], $target->get($field['field_name'])->getValue());
    }
    $working->setTitle($target->label());
    $working->setCreatedTime($target->getCreatedTime());
    $this->stamp($working, "Restored revision {$vid}");
    return $this->saveEdit($working);
  }

  private function assertRevisionsEnabled(NodeInterface $node): void {
    if (!$this->isModerated($node)) {
      throw ApiException::revisionsDisabled();
    }
  }

  private static function unpublishedDefaultState(?WorkflowInterface $workflow): ?string {
    if ($workflow === NULL) {
      return NULL;
    }
    /** @var \Drupal\content_moderation\ContentModerationState $state */
    foreach ($workflow->getTypePlugin()->getStates() as $state) {
      if (!$state->isPublishedState() && $state->isDefaultRevisionState()) {
        return $state->id();
      }
    }
    return NULL;
  }

  /**
   * Les vids de toutes les révisions, croissant : `NodeStorage::revisionIds()`
   * est dépréciée en 11.3 (retrait prévu en 12.0) au profit d'une requête
   * d'entité explicite ; `allRevisions()` indexe déjà le résultat par vid.
   */
  private function revisionIds(NodeInterface $node): array {
    $storage = $this->entityTypeManager->getStorage('node');
    return array_map('intval', array_keys(
      $storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('nid', $node->id())->sort('vid')->execute()
    ));
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
