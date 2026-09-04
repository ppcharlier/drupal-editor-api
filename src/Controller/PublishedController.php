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
    $node = $this->authorized($id);
    BaseModified::assertNotStale($request, $this->payload->workingCopy($node));
    $fresh = $this->workflow->publish($node, self::message($request));
    return Envelope::data($this->payload->detail($fresh, $this->currentUser()));
  }

  public function destroy(Request $request, string $id): JsonResponse {
    $node = $this->authorized($id);
    $fresh = $this->workflow->unpublish($node, self::message($request));
    return Envelope::data($this->payload->detail($fresh, $this->currentUser()));
  }

  private function authorized(string $id): \Drupal\node\NodeInterface {
    $node = $this->loader->load($id);
    if (!$node->access('update') || !$this->publish->canPublish($node, $this->currentUser())) {
      throw ApiException::forbidden('publish');
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
