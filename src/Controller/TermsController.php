<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Payload\TermPayload;
use Drupal\editor_api\Query\TermQuery;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Termes : liste (tâche 1) ; création, mise à jour, suppression (tâche 2).
 */
final class TermsController extends ControllerBase {

  public function __construct(
    private readonly TermQuery $query,
    private readonly TermPayload $payload,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('editor_api.term_query'), $container->get('editor_api.term_payload'));
  }

  public function index(Request $request, string $taxonomy): JsonResponse {
    $this->assertVocabulary($taxonomy);
    $errors = [];
    $search = (string) $request->query->get('search', '');
    if (mb_strlen($search) > 200) {
      $errors['search'] = ['The search may not be greater than 200 characters.'];
    }
    $sort = (string) $request->query->get('sort', 'title');
    if (!isset(TermQuery::SORTS[ltrim($sort, '-')])) {
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
    $result = $this->query->run($this->currentUser(), $taxonomy, $search, $sort, $page, $perPage);
    $items = array_map(fn(TermInterface $term) => $this->payload->summary($term, $this->currentUser()), $result['items']);
    return Envelope::page($items, $result['total'], $page, $perPage);
  }

  protected function assertVocabulary(string $vid): void {
    if ($this->entityTypeManager()->getStorage('taxonomy_vocabulary')->load($vid) === NULL) {
      throw ApiException::notFound();
    }
  }

}
