<?php

declare(strict_types=1);

namespace Drupal\editor_api\Payload;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Access\PublishAccess;
use Drupal\node\NodeInterface;

/**
 * Les blocs `can` du contrat, demandés aux contrôles d'accès natifs.
 */
final class Capabilities {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PublishAccess $publish,
  ) {}

  public function forNode(NodeInterface $node, AccountInterface $account): array {
    return [
      'edit' => $this->canEditNode($node, $account),
      'delete' => $node->access('delete', $account),
      'publish' => $this->publish->canPublish($node, $account),
    ];
  }

  public function forCollection(string $bundle, AccountInterface $account): array {
    return [
      // `administer nodes` est traité comme un accès superutilisateur sur
      // tout le sous-système nœud (comme pour `publish`) : le cœur ne le
      // fait pas lui-même — `NodeAccessControlHandler::checkCreateAccess()`
      // n'accorde la création que sur « create {bundle} content » — d'où le
      // OU explicite ici.
      'create' => $account->hasPermission('administer nodes')
        || $this->entityTypeManager->getAccessControlHandler('node')->createAccess($bundle, $account),
      'publish' => $this->publish->canPublishBundle($bundle, $account),
    ];
  }

  /**
   * « Peut éditer » en dehors de toute question de transition de workflow.
   *
   * `$node->access('update', …)` passe par `content_moderation`, qui
   * interdit l'opération « update » dès qu'aucune transition n'est ouverte
   * à l'utilisateur depuis l'état courant (`ContentModerationHooks::
   * entityAccess()`) — y compris pour un contenu non modéré par cet
   * utilisateur mais modéré par le workflow. Ce n'est pas le sens voulu par
   * la capacité `edit` du contrat, qui ne reflète que le droit d'édition au
   * sens champs/contenu ; la contrainte de transition reste portée par
   * `publish` (et, plus tard, par l'endpoint d'écriture lui-même). On
   * reproduit donc ici la règle native `edit own|any {bundle} content` de
   * `NodeEntityHooks::nodeAccess()`, sans le veto de `content_moderation`.
   */
  private function canEditNode(NodeInterface $node, AccountInterface $account): bool {
    $bundle = $node->bundle();
    if ($account->hasPermission('bypass node access') || $account->hasPermission("edit any {$bundle} content")) {
      return TRUE;
    }
    return $account->hasPermission("edit own {$bundle} content") && $account->id() === $node->getOwnerId();
  }

  public function forVocabulary(string $vid, AccountInterface $account): array {
    return ['create' => $this->entityTypeManager->getAccessControlHandler('taxonomy_term')->createAccess($vid, $account)];
  }

  public function forMediaType(string $type, AccountInterface $account): array {
    return ['upload' => $this->entityTypeManager->getAccessControlHandler('media')->createAccess($type, $account)];
  }

}
