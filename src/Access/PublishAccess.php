<?php

declare(strict_types=1);

namespace Drupal\editor_api\Access;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\workflows\WorkflowInterface;

/**
 * « Peut publier » : la transition du workflow si le type est modéré, sinon
 * `administer nodes` — le cœur n'a pas de permission « publier » par type.
 *
 * `content_moderation` est optionnel : sans lui, aucun bundle n'est modéré.
 */
final class PublishAccess {

  public const PUBLISHED_STATE = 'published';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?ModerationInformationInterface $moderation,
  ) {}

  public function isModeratedBundle(string $bundle): bool {
    if ($this->moderation === NULL) {
      return FALSE;
    }
    return $this->moderation->shouldModerateEntitiesOfBundle($this->entityTypeManager->getDefinition('node'), $bundle);
  }

  public function workflowFor(string $bundle): ?WorkflowInterface {
    if (!$this->isModeratedBundle($bundle)) {
      return NULL;
    }
    return $this->moderation->getWorkflowForEntityTypeAndBundle('node', $bundle);
  }

  public function canPublishBundle(string $bundle, AccountInterface $account): bool {
    $workflow = $this->workflowFor($bundle);
    if ($workflow === NULL) {
      return $account->hasPermission('administer nodes');
    }
    $plugin = $workflow->getTypePlugin();
    foreach ($plugin->getStates() as $state) {
      if ($this->canTransitionTo($workflow, $state->id(), self::PUBLISHED_STATE, $account)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function canPublish(NodeInterface $node, AccountInterface $account): bool {
    $workflow = $this->workflowFor($node->bundle());
    if ($workflow === NULL) {
      return $account->hasPermission('administer nodes');
    }
    return $this->canTransitionTo($workflow, $this->currentState($node, $workflow), self::PUBLISHED_STATE, $account);
  }

  /**
   * « Peut dépublier » : la transition de l'état courant vers l'état non publié
   * « révision par défaut » du workflow (archived) ; sans workflow, `administer nodes`.
   */
  public function canUnpublish(NodeInterface $node, AccountInterface $account): bool {
    $workflow = $this->workflowFor($node->bundle());
    if ($workflow === NULL) {
      return $account->hasPermission('administer nodes');
    }
    $target = self::unpublishedDefaultState($workflow);
    if ($target === NULL) {
      return FALSE;
    }
    return $this->canTransitionTo($workflow, $this->currentState($node, $workflow), $target, $account);
  }

  public static function unpublishedDefaultState(?WorkflowInterface $workflow): ?string {
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

  private function currentState(NodeInterface $node, WorkflowInterface $workflow): string {
    return $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()
      ? (string) $node->get('moderation_state')->value
      : $workflow->getTypePlugin()->getInitialState($node)->id();
  }

  private function canTransitionTo(WorkflowInterface $workflow, string $from, string $to, AccountInterface $account): bool {
    $plugin = $workflow->getTypePlugin();
    if (!$plugin->hasTransitionFromStateToState($from, $to)) {
      return FALSE;
    }
    $transition = $plugin->getTransitionFromStateToState($from, $to);
    return $account->hasPermission('use ' . $workflow->id() . ' transition ' . $transition->id());
  }

}
