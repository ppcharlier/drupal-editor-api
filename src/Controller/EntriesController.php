<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Access\PublishAccess;
use Drupal\editor_api\Blueprint\BlueprintBuilder;
use Drupal\editor_api\Entry\BaseModified;
use Drupal\editor_api\Entry\EntityValidation;
use Drupal\editor_api\Entry\NodeLoader;
use Drupal\editor_api\Entry\SlugAlias;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Http\ListParams;
use Drupal\editor_api\Http\RequestBody;
use Drupal\editor_api\Payload\EntryPayload;
use Drupal\editor_api\Query\EntryQuery;
use Drupal\editor_api\Revision\DraftWorkflow;
use Drupal\editor_api\Value\ValueWriter;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrées : liste, détail, création, mise à jour, suppression.
 */
final class EntriesController extends ControllerBase {

  public function __construct(
    private readonly EntryQuery $query,
    private readonly EntryPayload $payload,
    private readonly NodeLoader $loader,
    private readonly BlueprintBuilder $blueprints,
    private readonly ValueWriter $writer,
    private readonly SlugAlias $slug,
    private readonly DraftWorkflow $workflow,
    private readonly PublishAccess $publish,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('editor_api.entry_query'),
      $container->get('editor_api.entry_payload'),
      $container->get('editor_api.node_loader'),
      $container->get('editor_api.blueprint_builder'),
      $container->get('editor_api.value_writer'),
      $container->get('editor_api.slug_alias'),
      $container->get('editor_api.draft_workflow'),
      $container->get('editor_api.publish_access'),
    );
  }

  public function index(Request $request, string $collection): JsonResponse {
    $this->loader->assertCollection($collection);
    $errors = [];
    $status = (string) $request->query->get('status', 'any');
    if (!in_array($status, EntryQuery::STATUSES, TRUE)) {
      $errors['status'] = ['The selected status is invalid.'];
    }
    $params = ListParams::fromRequest($request, EntryQuery::SORTS, '-date', $errors);
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    $result = $this->query->run($this->currentUser(), $collection, $status, $params->search, $params->sort, $params->page, $params->perPage);
    $items = array_map(fn(NodeInterface $node) => $this->payload->summary($node, $this->currentUser()), $result['items']);
    return Envelope::page($items, $result['total'], $params->page, $params->perPage);
  }

  public function show(string $id): JsonResponse {
    $node = $this->loader->load($id);
    if (!$node->access('view')) {
      throw ApiException::forbidden('view');
    }
    return Envelope::data($this->payload->detail($node, $this->currentUser()));
  }

  public function store(Request $request, string $collection): JsonResponse {
    $this->loader->assertCollection($collection);
    if (!$this->entityTypeManager()->getAccessControlHandler('node')->createAccess($collection)) {
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
    $published = $body['published'] ?? FALSE;
    if (!is_bool($published)) {
      $errors['published'] = ['The published field must be true or false.'];
    }
    $date = self::parseDate($body, $errors);
    $message = self::parseMessage($body, $errors);
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    if ($published && !$this->publish->canPublishBundle($collection, $this->currentUser())) {
      throw ApiException::forbidden('publish');
    }
    $described = $this->blueprints->describe('node', $collection, $this->currentUser());
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager()->getStorage('node')->create(['type' => $collection, 'uid' => $this->currentUser()->id()]);
    if ($date !== NULL) {
      $node->setCreatedTime($this->createdFor($date, NULL));
    }
    $this->writer->write($node, $body['data'], $described['fields'], $this->currentUser());
    $this->slug->apply($node, $body['slug']);
    $this->workflow->stamp($node, $message);
    $this->workflow->prepare($node, $published);
    EntityValidation::assert($node);
    $node = $this->workflow->create($node);
    return Envelope::data($this->payload->detail($node, $this->currentUser()), 201);
  }

  public function update(Request $request, string $id): JsonResponse {
    $node = $this->loader->load($id);
    if (!$node->access('update')) {
      throw ApiException::forbidden('edit');
    }
    $working = $this->payload->workingCopy($node);
    BaseModified::assertNotStale($request, $working);
    $body = RequestBody::json($request);
    $errors = [];
    if (array_key_exists('published', $body)) {
      $errors['published'] = ['Publishing has its own endpoints.'];
    }
    if (!is_array($body['data'] ?? NULL)) {
      $errors['data'] = ['The data field is required.'];
    }
    if (array_key_exists('slug', $body) && (!is_string($body['slug']) || $body['slug'] === '')) {
      $errors['slug'] = ['The slug must be a non-empty string.'];
    }
    $date = self::parseDate($body, $errors);
    $message = self::parseMessage($body, $errors);
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    $described = $this->blueprints->describe('node', $node->bundle(), $this->currentUser());
    $this->writer->write($working, $body['data'], $described['fields'], $this->currentUser());
    if (isset($body['slug'])) {
      $this->slug->apply($working, $body['slug']);
    }
    if ($date !== NULL) {
      $working->setCreatedTime($this->createdFor($date, (int) $working->getCreatedTime()));
    }
    $this->workflow->stamp($working, $message);
    // Une modification est un brouillon sous modération, et ne change pas le
    // statut en mode direct : `$touchStatus` à FALSE.
    $this->workflow->prepare($working, FALSE, FALSE);
    EntityValidation::assert($working);
    $fresh = $this->workflow->saveEdit($working);
    return Envelope::data($this->payload->detail($fresh, $this->currentUser()));
  }

  public function destroy(string $id): Response {
    $node = $this->loader->load($id);
    if (!$node->access('delete')) {
      throw ApiException::forbidden('delete');
    }
    $node->delete();
    return Envelope::noContent();
  }

  /**
   * `date` : `Y-m-d` strict, rendu tel quel ; NULL si absent.
   */
  private static function parseDate(array $body, array &$errors): ?string {
    if (!array_key_exists('date', $body) || $body['date'] === NULL) {
      return NULL;
    }
    $date = is_string($body['date']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $body['date'], new \DateTimeZone('UTC')) : FALSE;
    if ($date === FALSE || $date->format('Y-m-d') !== $body['date']) {
      $errors['date'] = ['The date must be formatted as Y-m-d.'];
      return NULL;
    }
    return $body['date'];
  }

  /**
   * Le `created` qui correspond au jour `Y-m-d` demandé, lu dans le FUSEAU DU SITE.
   *
   * Un client renvoie la date à chaque enregistrement (l'app iOS, toujours) : si le jour est
   * déjà celui de `created`, `created` ne bouge pas ; s'il change, l'heure du jour est
   * conservée. À la création, minuit du site. Avant (2026-09-05), `Y-m-d` valait minuit UTC :
   * chaque enregistrement effaçait l'heure et, à l'ouest d'UTC, reculait le jour affiché.
   */
  private function createdFor(string $day, ?int $current): int {
    // Le fuseau du SITE, pas celui de la requête : Drupal aligne le fuseau PHP sur la
    // préférence de l'utilisateur courant, et la date d'une entrée n'en dépend pas.
    $zone = new \DateTimeZone($this->config('system.date')->get('timezone.default') ?: 'UTC');
    if ($current !== NULL) {
      $existing = (new \DateTimeImmutable('@' . $current))->setTimezone($zone);
      if ($existing->format('Y-m-d') === $day) {
        return $current;
      }
      $time = $existing->format('H:i:s');
    }
    else {
      $time = '00:00:00';
    }
    return (new \DateTimeImmutable("{$day} {$time}", $zone))->getTimestamp();
  }

  private static function parseMessage(array $body, array &$errors): ?string {
    if (!array_key_exists('message', $body) || $body['message'] === NULL) {
      return NULL;
    }
    if (!is_string($body['message']) || mb_strlen($body['message']) > 500) {
      $errors['message'] = ['The message may not be greater than 500 characters.'];
      return NULL;
    }
    return $body['message'];
  }

}
