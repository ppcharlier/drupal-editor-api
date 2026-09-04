<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Access\PublishAccess;
use Drupal\editor_api\Entry\BaseModified;
use Drupal\editor_api\Entry\NodeLoader;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Http\RequestBody;
use Drupal\editor_api\Payload\EntryPayload;
use Drupal\editor_api\Revision\DraftWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /entries/{id}/published publie la copie de travail ; DELETE dépublie.
 */
final class PublishedController extends ControllerBase {

  public function __construct(
    private readonly NodeLoader $loader,
    private readonly DraftWorkflow $workflow,
    private readonly EntryPayload $payload,
    private readonly PublishAccess $publish,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('editor_api.node_loader'), $container->get('editor_api.draft_workflow'), $container->get('editor_api.entry_payload'), $container->get('editor_api.publish_access'));
  }

  public function store(Request $request, string $id): JsonResponse {
    $node = $this->authorized($id, 'publish', fn ($node) => $this->publish->canPublish($node, $this->currentUser()));
    BaseModified::assertNotStale($request, $this->payload->workingCopy($node));
    $fresh = $this->workflow->publish($node, self::message($request));
    return Envelope::data($this->payload->detail($fresh, $this->currentUser()));
  }

  public function destroy(Request $request, string $id): JsonResponse {
    // L'état se vérifie avant la transition : un brouillon jamais publié répond
    // "rien à dépublier" quels que soient les droits, plutôt qu'un refus d'accès
    // causé par l'absence structurelle d'une transition draft → archived.
    $node = $this->authorized($id, 'unpublish', fn ($node) => $this->publish->canUnpublish($node, $this->currentUser()), function (\Drupal\node\NodeInterface $node): void {
      if (!$node->isPublished()) {
        throw ApiException::nothingToUnpublish();
      }
    });
    $fresh = $this->workflow->unpublish($node, self::message($request));
    return Envelope::data($this->payload->detail($fresh, $this->currentUser()));
  }

  private function authorized(string $id, string $ability, callable $check, ?callable $stateCheck = NULL): \Drupal\node\NodeInterface {
    $node = $this->loader->load($id);
    if (!$node->access('update')) {
      throw ApiException::forbidden($ability);
    }
    if ($stateCheck !== NULL) {
      $stateCheck($node);
    }
    if (!$check($node)) {
      throw ApiException::forbidden($ability);
    }
    return $node;
  }

  private static function message(Request $request): ?string {
    $body = RequestBody::json($request);
    if (!array_key_exists('message', $body) || $body['message'] === NULL) {
      return NULL;
    }
    if (!is_string($body['message']) || mb_strlen($body['message']) > 500) {
      throw ApiException::validation(['message' => ['The message may not be greater than 500 characters.']]);
    }
    return $body['message'];
  }

}
