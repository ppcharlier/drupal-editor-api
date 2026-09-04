<?php

declare(strict_types=1);

namespace Drupal\editor_api\Payload;

use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Blueprint\BlueprintBuilder;
use Drupal\editor_api\Term\TermSlug;
use Drupal\editor_api\Value\ValueReader;
use Drupal\taxonomy\TermInterface;

/**
 * La forme `TermSummary` du contrat.
 */
final class TermPayload {

  public function __construct(
    private readonly TermSlug $slug,
    private readonly Capabilities $capabilities,
    private readonly BlueprintBuilder $blueprints,
    private readonly ValueReader $reader,
  ) {}

  public function summary(TermInterface $term, AccountInterface $account): array {
    $described = $this->blueprints->describe('taxonomy_term', $term->bundle(), $account);
    return [
      'id' => $term->bundle() . '::' . $term->id(),
      'taxonomy' => $term->bundle(),
      'blueprint' => $term->bundle(),
      'slug' => $this->slug->read($term),
      'title' => (string) $term->label(),
      'published' => $term->isPublished(),
      'data' => $this->reader->readAll($term, $described['fields']),
      'can' => $this->capabilities->forTerm($term, $account),
    ];
  }

}
