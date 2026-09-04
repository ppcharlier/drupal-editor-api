<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Blueprint\BlueprintBuilder;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Payload\Capabilities;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /taxonomies — chaque vocabulaire avec son blueprint compact
 * (`blueprint` ET `blueprints`, pour compatibilité avec le contrat).
 */
final class TaxonomiesController extends ControllerBase {

  public function __construct(
    private readonly BlueprintBuilder $blueprints,
    private readonly Capabilities $capabilities,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('editor_api.blueprint_builder'), $container->get('editor_api.capabilities'));
  }

  public function index(): JsonResponse {
    $vocabularies = $this->entityTypeManager()->getStorage('taxonomy_vocabulary')->loadMultiple();
    uasort($vocabularies, fn($a, $b) => strcasecmp((string) $a->label(), (string) $b->label()));
    $data = [];
    foreach ($vocabularies as $vocabulary) {
      $blueprint = $this->blueprints->forVocabulary($vocabulary->id(), $this->currentUser());
      $data[] = [
        'handle' => $vocabulary->id(),
        'title' => (string) $vocabulary->label(),
        'blueprint' => $blueprint,
        'blueprints' => [$blueprint],
        'can' => $this->capabilities->forVocabulary($vocabulary->id(), $this->currentUser()),
      ];
    }
    return Envelope::data($data);
  }

}
