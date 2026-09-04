<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Blueprint\BlueprintBuilder;
use Drupal\editor_api\Entry\EntityValidation;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Http\RequestBody;
use Drupal\editor_api\Payload\TermPayload;
use Drupal\editor_api\Query\TermQuery;
use Drupal\editor_api\Term\TermSlug;
use Drupal\editor_api\Value\ValueWriter;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Termes : liste, création, mise à jour (par slug ou tid), suppression.
 */
final class TermsController extends ControllerBase {

  public function __construct(
    private readonly TermQuery $query,
    private readonly TermPayload $payload,
    private readonly TermSlug $slug,
    private readonly BlueprintBuilder $blueprints,
    private readonly ValueWriter $writer,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('editor_api.term_query'),
      $container->get('editor_api.term_payload'),
      $container->get('editor_api.term_slug'),
      $container->get('editor_api.blueprint_builder'),
      $container->get('editor_api.value_writer'),
    );
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

  public function store(Request $request, string $taxonomy): JsonResponse {
    $this->assertVocabulary($taxonomy);
    if (!$this->entityTypeManager()->getAccessControlHandler('taxonomy_term')->createAccess($taxonomy)) {
      throw ApiException::forbidden('create');
    }
    $body = RequestBody::json($request);
    $errors = [];
    if (!is_string($body['slug'] ?? NULL) || $body['slug'] === '') {
      $errors['slug'] = ['The slug field is required.'];
    }
    if (!is_array($body['data'] ?? NULL)) {
      $errors['data'] = ['The data field is required.'];
    }
    $published = $body['published'] ?? TRUE;
    if (!is_bool($published)) {
      $errors['published'] = ['The published field must be true or false.'];
    }
    self::checkBlueprint($body, $taxonomy, $errors);
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    $described = $this->blueprints->describe('taxonomy_term', $taxonomy, $this->currentUser());
    /** @var \Drupal\taxonomy\TermInterface $term */
    $term = $this->entityTypeManager()->getStorage('taxonomy_term')->create(['vid' => $taxonomy]);
    $this->writer->write($term, $body['data'], $described['fields'], $this->currentUser());
    $published ? $term->setPublished() : $term->setUnpublished();
    $this->slug->apply($term, $body['slug']);
    // Le contrat nomme le champ « title » ; EntityValidation regroupe les
    // violations par premier segment du chemin de propriété, qui pour le
    // champ de base de l'entité est « name » — on le renomme ici.
    if ((string) $term->label() === '') {
      throw ApiException::validation(['title' => ['The title field is required.']]);
    }
    EntityValidation::assert($term);
    $term->save();
    return Envelope::data($this->payload->summary($term, $this->currentUser()), 201);
  }

  public function update(Request $request, string $taxonomy, string $slug): JsonResponse {
    $this->assertVocabulary($taxonomy);
    $term = $this->slug->resolve($taxonomy, $slug);
    if (!$term->access('update')) {
      throw ApiException::forbidden('edit');
    }
    $body = RequestBody::json($request);
    $errors = [];
    if (!is_array($body['data'] ?? NULL)) {
      $errors['data'] = ['The data field is required.'];
    }
    if (array_key_exists('slug', $body) && (!is_string($body['slug']) || $body['slug'] === '')) {
      $errors['slug'] = ['The slug must be a non-empty string.'];
    }
    if (array_key_exists('published', $body) && !is_bool($body['published'])) {
      $errors['published'] = ['The published field must be true or false.'];
    }
    self::checkBlueprint($body, $taxonomy, $errors);
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    $described = $this->blueprints->describe('taxonomy_term', $taxonomy, $this->currentUser());
    $this->writer->write($term, $body['data'], $described['fields'], $this->currentUser());
    if (isset($body['published'])) {
      $body['published'] ? $term->setPublished() : $term->setUnpublished();
    }
    if (isset($body['slug'])) {
      $this->slug->apply($term, $body['slug']);
    }
    // Même renommage qu'à la création : « title » plutôt que « name ».
    if ((string) $term->label() === '') {
      throw ApiException::validation(['title' => ['The title field is required.']]);
    }
    EntityValidation::assert($term);
    $term->save();
    return Envelope::data($this->payload->summary($term, $this->currentUser()));
  }

  public function destroy(string $taxonomy, string $slug): Response {
    $this->assertVocabulary($taxonomy);
    $term = $this->slug->resolve($taxonomy, $slug);
    if (!$term->access('delete')) {
      throw ApiException::forbidden('delete');
    }
    $term->delete();
    return Envelope::noContent();
  }

  /**
   * `blueprint`, s'il est envoyé, ne peut être que le vocabulaire lui-même.
   */
  private static function checkBlueprint(array $body, string $taxonomy, array &$errors): void {
    if (array_key_exists('blueprint', $body) && $body['blueprint'] !== NULL && $body['blueprint'] !== $taxonomy) {
      $errors['blueprint'] = ["The blueprint must be {$taxonomy}."];
    }
  }

  private function assertVocabulary(string $vid): void {
    if ($this->entityTypeManager()->getStorage('taxonomy_vocabulary')->load($vid) === NULL) {
      throw ApiException::notFound();
    }
  }

}
