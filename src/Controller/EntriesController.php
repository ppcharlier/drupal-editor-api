<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Payload\EntryPayload;
use Drupal\editor_api\Query\EntryQuery;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Entrées : liste et détail (tâche 5) ; création, mise à jour, suppression (tâche 6).
 */
final class EntriesController extends ControllerBase {

  public function __construct(
    private readonly EntryQuery $query,
    private readonly EntryPayload $payload,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('editor_api.entry_query'), $container->get('editor_api.entry_payload'));
  }

  public function index(Request $request, string $collection): JsonResponse {
    $this->assertCollection($collection);
    $errors = [];
    $status = (string) $request->query->get('status', 'any');
    if (!in_array($status, EntryQuery::STATUSES, TRUE)) {
      $errors['status'] = ['The selected status is invalid.'];
    }
    $search = (string) $request->query->get('search', '');
    if (mb_strlen($search) > 200) {
      $errors['search'] = ['The search may not be greater than 200 characters.'];
    }
    $sort = (string) $request->query->get('sort', '-date');
    if (!isset(EntryQuery::SORTS[ltrim($sort, '-')])) {
      $errors['sort'] = ['The sort field is not allowed.'];
    }
    $page = (int) $request->query->get('page', 1);
    $perPage = (int) $request->query->get('per_page', 25);
    if ($page < 1) {
      $errors['page'] = ['The page must be at least 1.'];
    }
    if ($perPage < 1 || $perPage > 100) {
      $errors['per_page'] = ['The per_page must be between 1 and 100.'];
    }
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    $result = $this->query->run($this->currentUser(), $collection, $status, $search, $sort, $page, $perPage);
    $items = array_map(fn(NodeInterface $node) => $this->payload->summary($node, $this->currentUser()), $result['items']);
    return Envelope::page($items, $result['total'], $page, $perPage);
  }

  public function show(string $id): JsonResponse {
    $node = $this->load($id);
    if (!$node->access('view')) {
      throw ApiException::forbidden('view');
    }
    return Envelope::data($this->payload->detail($node, $this->currentUser()));
  }

  protected function assertCollection(string $collection): void {
    if ($this->entityTypeManager()->getStorage('node_type')->load($collection) === NULL) {
      throw ApiException::notFound();
    }
  }

  protected function load(string $id): NodeInterface {
    $node = ctype_digit($id) ? $this->entityTypeManager()->getStorage('node')->load((int) $id) : NULL;
    if (!$node instanceof NodeInterface) {
      throw ApiException::notFound();
    }
    return $node;
  }

}
