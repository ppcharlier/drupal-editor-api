<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Media\MediaLoader;
use Drupal\editor_api\Payload\AssetPayload;
use Drupal\editor_api\Query\MediaQuery;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Assets : liste et détail (tâche 3) ; upload, mise à jour, suppression (tâche 4).
 */
final class AssetsController extends ControllerBase {

  public function __construct(
    private readonly MediaLoader $loader,
    private readonly MediaQuery $query,
    private readonly AssetPayload $payload,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('editor_api.media_loader'), $container->get('editor_api.media_query'), $container->get('editor_api.asset_payload'));
  }

  public function index(Request $request, string $container): JsonResponse {
    $this->loader->container($container);
    $errors = [];
    if ((string) $request->query->get('folder', '') !== '') {
      $errors['folder'] = ['Folders are not supported by this container.'];
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
    $result = $this->query->run($this->currentUser(), $container, $page, $perPage);
    $assets = array_map(fn(MediaInterface $media) => $this->payload->summary($media, $this->currentUser()), $result['items']);
    return Envelope::page(['assets' => $assets, 'folders' => []], $result['total'], $page, $perPage, ['folders_total' => 0, 'folders_last_page' => 1]);
  }

  public function show(string $container, string $mid, string $basename): JsonResponse {
    // La route déclare `{mid}` et `{basename}` séparément (voir
    // editor_api.routing.yml) ; `path` du contrat reste `{mid}/{basename}`.
    $media = $this->loader->load($container, $mid . '/' . $basename);
    if (!$media->access('view')) {
      throw ApiException::forbidden('view');
    }
    return Envelope::data($this->payload->summary($media, $this->currentUser()));
  }

}
