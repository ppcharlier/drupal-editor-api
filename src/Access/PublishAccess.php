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
      if ($this->canTransition($workflow, $state->id(), $account)) {
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
    $current = $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()
      ? (string) $node->get('moderation_state')->value
      : $workflow->getTypePlugin()->getInitialState($node)->id();
    return $this->canTransition($workflow, $current, $account);
  }

  private function canTransition(WorkflowInterface $workflow, string $from, AccountInterface $account): bool {
    $plugin = $workflow->getTypePlugin();
    if (!$plugin->hasTransitionFromStateToState($from, self::PUBLISHED_STATE)) {
      return FALSE;
    }
    $transition = $plugin->getTransitionFromStateToState($from, self::PUBLISHED_STATE);
    return $account->hasPermission('use ' . $workflow->id() . ' transition ' . $transition->id());
  }

}
