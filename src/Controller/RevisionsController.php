<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Blueprint\BlueprintBuilder;
use Drupal\editor_api\Entry\NodeLoader;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Payload\EntryPayload;
use Drupal\editor_api\Revision\DraftWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /entries/{id}/revisions ; POST /entries/{id}/revisions/{revision}/restore.
 */
final class RevisionsController extends ControllerBase {

  public function __construct(
    private readonly NodeLoader $loader,
    private readonly DraftWorkflow $workflow,
    private readonly EntryPayload $payload,
    private readonly BlueprintBuilder $blueprints,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('editor_api.node_loader'), $container->get('editor_api.draft_workflow'), $container->get('editor_api.entry_payload'), $container->get('editor_api.blueprint_builder'));
  }

  public function index(string $id): JsonResponse {
    $node = $this->loader->load($id);
    if (!$node->access('view')) {
      throw ApiException::forbidden('view');
    }
    return Envelope::data($this->workflow->revisions($node));
  }

  public function restore(string $id, string $revision): JsonResponse {
    $node = $this->loader->load($id);
    if (!$node->access('update')) {
      throw ApiException::forbidden('edit');
    }
    $fields = $this->blueprints->describe('node', $node->bundle(), $this->currentUser())['fields'];
    $fresh = $this->workflow->restore($node, $revision, $fields);
    return Envelope::data($this->payload->detail($fresh, $this->currentUser()));
  }

}
