<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Access\PublishAccess;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Payload\Capabilities;
use Drupal\media\MediaTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET /config — tout ce que l'app doit savoir pour bâtir son interface.
 */
final class ConfigController extends ControllerBase {

  public const SITE_HANDLE = 'default';

  public function __construct(
    private readonly Capabilities $capabilities,
    private readonly PublishAccess $publish,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('editor_api.capabilities'), $container->get('editor_api.publish_access'));
  }

  public function show(Request $request): JsonResponse {
    $account = $this->currentUser();
    $collections = [];
    foreach ($this->sorted($this->entityTypeManager()->getStorage('node_type')->loadMultiple()) as $type) {
      $collections[] = [
        'handle' => $type->id(),
        'title' => $type->label(),
        'revisions_enabled' => $this->publish->isModeratedBundle($type->id()),
        'dated' => TRUE,
        'structured' => FALSE,
        'blueprints' => [$type->id()],
        'sites' => [self::SITE_HANDLE],
        'can' => $this->capabilities->forCollection($type->id(), $account),
      ];
    }
    $taxonomies = [];
    foreach ($this->sorted($this->entityTypeManager()->getStorage('taxonomy_vocabulary')->loadMultiple()) as $vocabulary) {
      $taxonomies[] = [
        'handle' => $vocabulary->id(),
        'title' => $vocabulary->label(),
        'blueprints' => [$vocabulary->id()],
        'sites' => [self::SITE_HANDLE],
        'can' => $this->capabilities->forVocabulary($vocabulary->id(), $account),
      ];
    }
    $containers = [];
    foreach ($this->sorted($this->entityTypeManager()->getStorage('media_type')->loadMultiple()) as $mediaType) {
      if (!self::isFileBacked($mediaType)) {
        continue;
      }
      $containers[] = [
        'handle' => $mediaType->id(),
        'title' => $mediaType->label(),
        'can' => $this->capabilities->forMediaType($mediaType->id(), $account),
      ];
    }
    return Envelope::data([
      'sites' => [[
        'handle' => self::SITE_HANDLE,
        'name' => (string) $this->config('system.site')->get('name'),
        'url' => $request->getSchemeAndHttpHost() . $request->getBasePath(),
        'locale' => $this->languageManager()->getDefaultLanguage()->getId(),
        'default' => TRUE,
      ]],
      'collections' => $collections,
      'asset_containers' => $containers,
      'taxonomies' => $taxonomies,
      'globals' => [],
      'navigations' => [],
      'forms' => [],
    ]);
  }

  /**
   * Un conteneur d'assets = un type de média dont la source est un fichier ou une image.
   */
  public static function isFileBacked(MediaTypeInterface $type): bool {
    return in_array($type->getSource()->getPluginId(), ['image', 'file'], TRUE);
  }

  private function sorted(array $entities): array {
    uasort($entities, fn($a, $b) => strcasecmp((string) $a->label(), (string) $b->label()));
    return $entities;
  }

}
