<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Liste et détail des entrées : filtres, tri, pagination, valeurs, brouillon en attente.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class EntryReadTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;

  protected function setUp(): void {
    parent::setUp();
    $this->createNodeType('article');
    $this->createBasicHtmlFormat();
    Vocabulary::create(['vid' => 'regions', 'name' => 'Regions'])->save();
    $this->createField('article', 'body', 'text_with_summary', [], [], 1, 'text_textarea_with_summary', 1, FALSE, 'Body');
    $this->createField('article', 'field_regions', 'entity_reference', ['target_type' => 'taxonomy_term'], ['handler_settings' => ['target_bundles' => ['regions' => 'regions']]], -1, 'entity_reference_autocomplete', 2);
    $this->createField('article', 'field_favourite', 'boolean', [], [], 1, 'boolean_checkbox', 3);
    $this->user = $this->createEditor(['access editor api', 'access content', 'edit any article content', 'use text format basic_html', 'view own unpublished content', 'view latest version']);
  }

  private function article(string $title, bool $published, int $created, array $extra = []): Node {
    $node = Node::create(['type' => 'article', 'title' => $title, 'status' => $published ? 1 : 0, 'created' => $created, 'changed' => $created, 'uid' => $this->user->id()] + $extra);
    $node->save();
    return $node;
  }

  public function testListFiltersSortsAndPaginates(): void {
    $this->article('Low tide', TRUE, 1000);
    $this->article('High tide', FALSE, 2000);
    $this->article('Fog', TRUE, 3000);
    $headers = $this->bearer($this->user);

    $body = $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries', NULL, $headers));
    $this->assertSame(['Fog', 'High tide', 'Low tide'], array_column($body['data'], 'title'));
    $this->assertSame(['total' => 3, 'current_page' => 1, 'per_page' => 25, 'last_page' => 1], $body['meta']);
    $first = $body['data'][0];
    $this->assertSame(['id', 'collection', 'slug', 'title', 'status', 'published', 'date', 'has_unpublished_changes', 'last_modified', 'author', 'can'], array_keys($first));
    $this->assertSame('published', $first['status']);
    $this->assertSame('1970-01-01T00:50:00+00:00', $first['date']);
    $this->assertSame(['id' => (string) $this->user->id(), 'name' => $this->user->getDisplayName()], $first['author']);
    $this->assertSame(['edit' => TRUE, 'delete' => FALSE, 'publish' => FALSE], $first['can']);
    $this->assertIsString($first['id']);
    $this->assertSame($first['id'], $first['slug']);

    $published = $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries?status=published&sort=title', NULL, $headers));
    $this->assertSame(['Fog', 'Low tide'], array_column($published['data'], 'title'));
    $draft = $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries?status=draft', NULL, $headers));
    $this->assertSame(['High tide'], array_column($draft['data'], 'title'));
    $this->assertSame(0, $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries?status=scheduled', NULL, $headers))['meta']['total']);

    $search = $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries?search=tide&sort=-title', NULL, $headers));
    $this->assertSame(['Low tide', 'High tide'], array_column($search['data'], 'title'));

    $page = $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries?per_page=2&page=2', NULL, $headers));
    $this->assertSame(['Low tide'], array_column($page['data'], 'title'));
    $this->assertSame(['total' => 3, 'current_page' => 2, 'per_page' => 2, 'last_page' => 2], $page['meta']);
  }

  public function testInvalidParametersAreValidationFailed(): void {
    $headers = $this->bearer($this->user);
    foreach (['sort=changed', 'status=nope', 'per_page=0', 'per_page=101', 'search=' . str_repeat('a', 201)] as $query) {
      $response = $this->request('GET', '/api/editor/v1/collections/article/entries?' . $query, NULL, $headers);
      $this->assertSame(422, $response->getStatusCode(), $query);
      $this->assertSame('validation_failed', $this->decode($response)['error']['code'], $query);
    }
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/collections/nope/entries', NULL, $headers)->getStatusCode());
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/entries/999', NULL, $headers)->getStatusCode());
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/entries/abc', NULL, $headers)->getStatusCode());
  }

  public function testDetailCarriesDataBlueprintAndSlug(): void {
    $term = Term::create(['vid' => 'regions', 'name' => 'Bretagne', 'path' => ['alias' => '/regions/bretagne']]);
    $term->save();
    $node = $this->article('Low tide', TRUE, 1000, [
      'body' => ['value' => '<p>Sea &amp; <custom-tag data-x="1">salt</custom-tag>  spaces</p>', 'format' => 'basic_html'],
      'field_regions' => [['target_id' => $term->id()]],
      'field_favourite' => 1,
      'path' => ['alias' => '/articles/low-tide'],
    ]);
    $detail = $this->decode($this->request('GET', '/api/editor/v1/entries/' . $node->id(), NULL, $this->bearer($this->user)))['data'];
    $this->assertSame('low-tide', $detail['slug']);
    $this->assertSame('article', $detail['blueprint']);
    $this->assertSame('default', $detail['site']);
    $this->assertSame([['site' => 'default', 'id' => (string) $node->id()]], $detail['localizations']);
    $this->assertSame([
      'title' => 'Low tide',
      'body' => '<p>Sea &amp; <custom-tag data-x="1">salt</custom-tag>  spaces</p>',
      'field_regions' => ['bretagne'],
      'field_favourite' => TRUE,
    ], $detail['data']);
    $this->assertFalse($detail['has_unpublished_changes']);
  }

  public function testTermValuesAreSlugsWithTheTidAsFallback(): void {
    $bretagne = Term::create(['vid' => 'regions', 'name' => 'Bretagne', 'path' => ['alias' => '/regions/bretagne']]);
    $bretagne->save();
    // Un terme sans alias n'a pas de slug : son tid en tient lieu (TermSlug::read).
    $alpes = Term::create(['vid' => 'regions', 'name' => 'Alpes']);
    $alpes->save();
    $node = $this->article('Two regions', TRUE, 1000, [
      // Le troisième delta pointe un terme qui n'existe pas : une référence
      // orpheline est ignorée, comme pour les médias.
      'field_regions' => [['target_id' => $bretagne->id()], ['target_id' => $alpes->id()], ['target_id' => 999]],
    ]);
    $detail = $this->decode($this->request('GET', '/api/editor/v1/entries/' . $node->id(), NULL, $this->bearer($this->user)))['data'];
    $this->assertSame(['bretagne', (string) $alpes->id()], $detail['data']['field_regions']);
  }

  public function testDetailDescribesThePendingDraftButLiveStatus(): void {
    $this->enableEditorialWorkflow('article');
    $node = $this->article('Live title', TRUE, 1000, ['moderation_state' => 'published']);
    $node->setNewRevision(TRUE);
    $node->set('moderation_state', 'draft');
    $node->setTitle('Draft title');
    $node->set('body', ['value' => '<p>draft</p>', 'format' => 'basic_html']);
    $node->save();

    $headers = $this->bearer($this->user);
    $list = $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries', NULL, $headers))['data'][0];
    $this->assertSame('Live title', $list['title']);
    $this->assertTrue($list['has_unpublished_changes']);

    $detail = $this->decode($this->request('GET', '/api/editor/v1/entries/' . $node->id(), NULL, $headers))['data'];
    $this->assertSame('Draft title', $detail['title']);
    $this->assertSame('<p>draft</p>', $detail['data']['body']);
    $this->assertSame('published', $detail['status']);
    $this->assertTrue($detail['published']);
    $this->assertTrue($detail['has_unpublished_changes']);
  }

  public function testPendingDraftNeedsViewLatestVersion(): void {
    $this->enableEditorialWorkflow('article');
    $node = $this->article('Live title', TRUE, 1000, [
      'moderation_state' => 'published',
      'body' => ['value' => '<p>live</p>', 'format' => 'basic_html'],
    ]);
    $node->setNewRevision(TRUE);
    $node->set('moderation_state', 'draft');
    $node->setTitle('Draft title');
    $node->set('body', ['value' => '<p>draft</p>', 'format' => 'basic_html']);
    $node->save();

    // Un simple lecteur voit la révision par défaut : le brouillon ne lui est pas
    // servi. Le drapeau, lui, reste vrai — il dit qu'il existe des changements,
    // pas ce qu'ils contiennent.
    $reader = $this->createEditor(['access editor api']);
    $detail = $this->decode($this->request('GET', '/api/editor/v1/entries/' . $node->id(), NULL, $this->bearer($reader)))['data'];
    $this->assertSame('Live title', $detail['title']);
    $this->assertSame('<p>live</p>', $detail['data']['body']);
    $this->assertTrue($detail['has_unpublished_changes']);

    // L'éditeur, qui a « view latest version », reçoit bien le brouillon.
    $editor = $this->decode($this->request('GET', '/api/editor/v1/entries/' . $node->id(), NULL, $this->bearer($this->user)))['data'];
    $this->assertSame('Draft title', $editor['title']);
    $this->assertSame('<p>draft</p>', $editor['data']['body']);
  }

  public function testViewAnyUnpublishedContentListsOthersDrafts(): void {
    $this->article('Someone else draft', FALSE, 1000);
    $moderator = $this->createEditor(['access editor api', 'access content', 'view any unpublished content']);
    $list = $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries', NULL, $this->bearer($moderator)));
    $this->assertSame(['Someone else draft'], array_column($list['data'], 'title'));
  }

  public function testViewAccessIsEnforced(): void {
    $node = $this->article('Secret', FALSE, 1000);
    $reader = $this->createEditor(['access editor api', 'access content']);
    $response = $this->request('GET', '/api/editor/v1/entries/' . $node->id(), NULL, $this->bearer($reader));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('Not authorized to view this resource.', $this->decode($response)['error']['message']);
    $list = $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries', NULL, $this->bearer($reader)));
    $this->assertSame(0, $list['meta']['total']);
  }

}
