<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Media\MediaLoader;
use Drupal\editor_api\Payload\AssetPayload;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Le média derrière un `<drupal-media>` d'un champ `html`, retrouvé par son UUID.
 *
 * Séparé d'`AssetsController` parce qu'il ne parle pas de conteneurs : l'app arrive ici avec un
 * UUID lu dans du HTML, sans savoir de quel type de média il s'agit — ni même s'il en existe un.
 */
final class MediaController extends ControllerBase {

  public function __construct(
    private readonly MediaLoader $loader,
    private readonly AssetPayload $payload,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('editor_api.media_loader'),
      $container->get('editor_api.asset_payload'),
    );
  }

  public function show(string $uuid): JsonResponse {
    $media = $this->loader->loadByUuid($uuid);
    if (!$media->access('view')) {
      throw ApiException::forbidden('view');
    }
    return Envelope::data($this->payload->summary($media, $this->currentUser()));
  }

}
