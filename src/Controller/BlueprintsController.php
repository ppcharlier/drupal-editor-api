<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Blueprint\BlueprintBuilder;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Un blueprint par collection : la liste en a un, le détail exige son handle.
 */
final class BlueprintsController extends ControllerBase {

  public function __construct(private readonly BlueprintBuilder $blueprints) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('editor_api.blueprint_builder'));
  }

  public function index(string $collection): JsonResponse {
    return Envelope::data([$this->blueprints->forNodeType($collection, $this->currentUser())]);
  }

  public function show(string $collection, string $blueprint): JsonResponse {
    if ($blueprint !== $collection) {
      throw ApiException::notFound();
    }
    return Envelope::data($this->blueprints->forNodeType($collection, $this->currentUser()));
  }

}
