<?php

declare(strict_types=1);

namespace Drupal\editor_api\Payload;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Access\PublishAccess;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

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
      'edit' => $node->access('update', $account),
      'delete' => $node->access('delete', $account),
      'publish' => $this->publish->canPublish($node, $account),
    ];
  }

  public function forCollection(string $bundle, AccountInterface $account): array {
    return [
      'create' => $this->entityTypeManager->getAccessControlHandler('node')->createAccess($bundle, $account),
      'publish' => $this->publish->canPublishBundle($bundle, $account),
    ];
  }

  public function forTerm(TermInterface $term, AccountInterface $account): array {
    return [
      'edit' => $term->access('update', $account),
      'delete' => $term->access('delete', $account),
    ];
  }

  public function forMedia(MediaInterface $media, AccountInterface $account): array {
    // Pas de dossiers : `move` est toujours faux.
    return [
      'edit' => $media->access('update', $account),
      'move' => FALSE,
      'rename' => $media->access('update', $account),
      'delete' => $media->access('delete', $account),
    ];
  }

  public function forVocabulary(string $vid, AccountInterface $account): array {
    return ['create' => $this->entityTypeManager->getAccessControlHandler('taxonomy_term')->createAccess($vid, $account)];
  }

  public function forMediaType(string $type, AccountInterface $account): array {
    return ['upload' => $this->entityTypeManager->getAccessControlHandler('media')->createAccess($type, $account)];
  }

}
