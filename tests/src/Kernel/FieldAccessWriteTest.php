<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\editor_api_test\Hook\FieldAccessHooks;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Écrire dans un champ que le compte n'a pas le droit de modifier.
 *
 * Drupal contrôle l'accès champ par champ (`FieldItemList::access('edit')`), et des modules comme
 * Field Permissions s'en servent pour réserver un champ à certains rôles. `ValueWriter` écrivait
 * tout champ présent au blueprint sans jamais poser la question : un compte pouvant modifier
 * l'entité pouvait donc écrire dans un champ qui lui était fermé.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class FieldAccessWriteTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;

  protected function setUp(): void {
    parent::setUp();
    $this->createNodeType('article');
    $this->createBasicHtmlFormat();
    $this->createField('article', 'field_note', 'string');
    $this->user = $this->createEditor([
      'access editor api', 'access content', 'create article content',
      'edit any article content', 'use text format basic_html',
    ]);
  }

  private function lock(?string $fieldName): void {
    \Drupal::state()->set(FieldAccessHooks::STATE_KEY, $fieldName);
  }

  private function create(array $data): array {
    $response = $this->request(
      'POST', '/api/editor/v1/collections/article/entries',
      ['slug' => 'note-' . uniqid(), 'data' => $data], $this->bearer($this->user));
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return $this->decode($response)['data'];
  }

  /**
   * Le témoin : sans verrou, rien ne change. Il tient la garantie que les 403 ci-dessous
   * viennent bien du verrou et non du contrôle lui-même.
   */
  public function testWithoutLockTheFieldIsWrittenAsBefore(): void {
    $created = $this->create(['title' => 'Note', 'field_note' => 'visible']);
    $this->assertSame('visible', $created['data']['field_note']);

    $updated = $this->request(
      'PATCH', "/api/editor/v1/entries/{$created['id']}",
      ['data' => ['field_note' => 'changée']], $this->bearer($this->user));
    $this->assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
    $this->assertSame('changée', $this->decode($updated)['data']['data']['field_note']);
  }

  public function testUpdatingALockedFieldIsForbidden(): void {
    $created = $this->create(['title' => 'Note', 'field_note' => 'origine']);
    $this->lock('field_note');

    $response = $this->request(
      'PATCH', "/api/editor/v1/entries/{$created['id']}",
      ['data' => ['field_note' => 'tentative']], $this->bearer($this->user));

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('forbidden', $this->decode($response)['error']['code']);
    // Rien n'a été écrit : le refus précède l'écriture, il ne la défait pas.
    $this->assertSame('origine', Node::load((int) $created['id'])->get('field_note')->value);
  }

  /**
   * Le pendant qui donne sa valeur au correctif : verrouiller un champ ne doit pas bloquer les
   * AUTRES. C'est la même propriété que la validation restreinte a établie pour les contraintes.
   */
  public function testAnUntouchedLockedFieldDoesNotBlockTheRest(): void {
    $created = $this->create(['title' => 'Note', 'field_note' => 'origine']);
    $this->lock('field_note');

    $response = $this->request(
      'PATCH', "/api/editor/v1/entries/{$created['id']}",
      ['data' => ['title' => 'Titre changé']], $this->bearer($this->user));

    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $node = Node::load((int) $created['id']);
    $this->assertSame('Titre changé', $node->label());
    $this->assertSame('origine', $node->get('field_note')->value);
  }

  /**
   * Une CRÉATION passe par le même écrivain, sur une entité qui n'existe pas encore — le cas où
   * l'accès par champ se comporte le moins comme on l'attend.
   */
  public function testCreatingWithALockedFieldIsForbidden(): void {
    $this->lock('field_note');

    $response = $this->request(
      'POST', '/api/editor/v1/collections/article/entries',
      ['slug' => 'neuve', 'data' => ['title' => 'Neuve', 'field_note' => 'tentative']],
      $this->bearer($this->user));

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * Et une création qui ne touche PAS le champ verrouillé reste possible : sans cela, un seul
   * champ réservé rendrait la collection entière inutilisable.
   */
  public function testCreatingWithoutTheLockedFieldStillWorks(): void {
    $this->lock('field_note');

    $response = $this->request(
      'POST', '/api/editor/v1/collections/article/entries',
      ['slug' => 'sans-note', 'data' => ['title' => 'Sans note']], $this->bearer($this->user));

    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * Les termes passent par le même écrivain, avec un champ de base renommé par le contrat
   * (`name` → `title`).
   */
  public function testTermsGoThroughTheSameCheck(): void {
    Vocabulary::create(['vid' => 'regions', 'name' => 'Regions'])->save();
    $this->createField('regions', 'field_note', 'string', entityType: 'taxonomy_term');
    $term = Term::create(['vid' => 'regions', 'name' => 'Bretagne', 'field_note' => 'origine']);
    $term->save();
    $user = $this->createEditor([
      'access editor api', 'access content', 'administer taxonomy', 'use text format basic_html',
    ], 'terms@example.com');
    $this->lock('field_note');

    $response = $this->request(
      'PATCH', '/api/editor/v1/taxonomies/regions/terms/' . $term->id(),
      ['data' => ['field_note' => 'tentative']], $this->bearer($user));

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * L'autre moitié du contrôle d'accès par champ : une contrainte portant sur un champ que le
   * compte ne peut pas éditer ne doit pas lui être opposée — il ne peut rien y faire. C'est ce
   * que `filterByFieldAccess()` fait dans le cœur (`rest`, `jsonapi`), et que la validation du
   * module ignorait : un champ REQUIS mais verrouillé rendait toute création impossible.
   */
  public function testAConstraintOnALockedFieldIsNotHeldAgainstTheAccount(): void {
    $this->createField('article', 'field_locked_required', 'string', required: TRUE);
    $this->lock('field_locked_required');

    $response = $this->request(
      'POST', '/api/editor/v1/collections/article/entries',
      ['slug' => 'requis-verrouille', 'data' => ['title' => 'Requis verrouillé']],
      $this->bearer($this->user));

    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * Le témoin du précédent : sans verrou, le champ requis reste requis. Sans lui, le test
   * ci-dessus passerait aussi bien si la contrainte n'existait pas.
   */
  public function testTheSameConstraintStillAppliesWithoutTheLock(): void {
    $this->createField('article', 'field_locked_required', 'string', required: TRUE);

    $response = $this->request(
      'POST', '/api/editor/v1/collections/article/entries',
      ['slug' => 'requis-libre', 'data' => ['title' => 'Requis libre']],
      $this->bearer($this->user));

    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $this->assertArrayHasKey('field_locked_required', $this->decode($response)['error']['errors']);
  }

}
