<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Création, mise à jour et suppression : valeurs, slug, conflits, deux modes.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class EntryWriteTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;
  private Term $bretagne;

  protected function setUp(): void {
    parent::setUp();
    $this->createNodeType('article');
    $this->createNodeType('page');
    $this->createBasicHtmlFormat();
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML', 'weight' => 1])->save();
    Vocabulary::create(['vid' => 'regions', 'name' => 'Regions'])->save();
    Vocabulary::create(['vid' => 'themes', 'name' => 'Themes'])->save();
    $this->bretagne = Term::create(['vid' => 'regions', 'name' => 'Bretagne']);
    $this->bretagne->save();
    foreach (['article', 'page'] as $bundle) {
      $this->createField($bundle, 'body', 'text_with_summary', [], [], 1, 'text_textarea_with_summary', 1, FALSE, 'Body');
    }
    $this->createField('article', 'field_regions', 'entity_reference', ['target_type' => 'taxonomy_term'], ['handler_settings' => ['target_bundles' => ['regions' => 'regions']]], -1, 'entity_reference_autocomplete', 2);
    $this->createField('article', 'field_favourite', 'boolean', [], [], 1, 'boolean_checkbox', 3);
    $this->createField('article', 'field_season', 'list_string', ['allowed_values' => ['spring' => 'Spring', 'summer' => 'Summer']], [], 1, 'options_select', 4);
    $this->createField('article', 'field_visited_on', 'datetime', ['datetime_type' => 'date'], [], 1, 'datetime_default', 5);
    // Option dont la valeur est la chaîne zéro : `false` ne doit jamais « valoir » '0' (comparaison lâche de PHP).
    $this->createField('article', 'field_rating', 'list_string', ['allowed_values' => ['0' => 'None', '1' => 'One']], [], 1, 'options_select', 6);
    $this->user = $this->createEditor([
      'access editor api', 'access content', 'create article content', 'edit any article content', 'delete any article content',
      'create page content', 'edit any page content', 'use text format basic_html', 'use text format full_html', 'view own unpublished content',
      'view latest version',
    ]);
  }

  private function post(string $collection, array $body, ?\Drupal\user\UserInterface $as = NULL): \Symfony\Component\HttpFoundation\Response {
    return $this->request('POST', "/api/editor/v1/collections/{$collection}/entries", $body, $this->bearer($as ?? $this->user));
  }

  public function testCreateRoundTripsValuesAndSlug(): void {
    $html = "<p>Sea &amp; <custom-tag data-x=\"1\">salt</custom-tag>  two  spaces</p>\n<figure class=\"wide\"><img src=\"/a.jpg\" alt=\"\"></figure>";
    $response = $this->post('article', [
      'slug' => 'low-tide',
      'date' => '2026-08-30',
      'message' => 'First draft',
      'data' => ['title' => 'Low tide', 'body' => $html, 'field_regions' => [(string) $this->bretagne->id()], 'field_favourite' => TRUE, 'field_season' => 'summer', 'field_visited_on' => '2026-08-29', 'field_rating' => '0'],
    ]);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $data = $this->decode($response)['data'];
    $this->assertSame('low-tide', $data['slug']);
    $this->assertSame('article', $data['collection']);
    $this->assertFalse($data['published']);
    $this->assertSame('draft', $data['status']);
    $this->assertStringStartsWith('2026-08-30T', $data['date']);
    $this->assertSame($html, $data['data']['body']);
    $this->assertSame([(string) $this->bretagne->id()], $data['data']['field_regions']);
    $this->assertTrue($data['data']['field_favourite']);
    $this->assertSame('summer', $data['data']['field_season']);
    $this->assertSame('0', $data['data']['field_rating']);
    // Champ date sans heure : le cœur relit 12:00:00 UTC (DateTimeComputed), seul le jour compte.
    $this->assertStringStartsWith('2026-08-29T', $data['data']['field_visited_on']);
    $this->assertSame((string) $this->user->id(), $data['author']['id']);

    $node = Node::load((int) $data['id']);
    $this->assertSame('basic_html', $node->get('body')->format);
    $this->assertSame('/article/low-tide', \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $node->id()));
    $this->assertSame('First draft', $node->getRevisionLogMessage());
  }

  public function testCreateValidationErrors(): void {
    $this->post('article', ['slug' => 'taken', 'data' => ['title' => 'A']]);
    $cases = [
      [['slug' => 'taken', 'data' => ['title' => 'B']], 422, 'uri_taken', 'slug'],
      [['slug' => 'Bad Slug', 'data' => ['title' => 'B']], 422, 'validation_failed', 'slug'],
      [['slug' => 'b', 'data' => ['title' => 'B', 'nope' => 1]], 422, 'unknown_field', 'nope'],
      [['slug' => 'b', 'data' => ['title' => 'B', 'slug' => 'x']], 422, 'unknown_field', 'slug'],
      [['slug' => 'b'], 422, 'validation_failed', 'data'],
      [['data' => ['title' => 'B']], 422, 'validation_failed', 'slug'],
      [['slug' => 'b', 'data' => ['body' => '<p>no title</p>']], 422, 'validation_failed', 'title'],
      [['slug' => 'b', 'date' => '30/08/2026', 'data' => ['title' => 'B']], 422, 'validation_failed', 'date'],
      [['slug' => 'b', 'message' => str_repeat('m', 501), 'data' => ['title' => 'B']], 422, 'validation_failed', 'message'],
      [['slug' => 'b', 'data' => ['title' => 'B', 'field_regions' => ['999']]], 422, 'validation_failed', 'field_regions'],
      [['slug' => 'b', 'data' => ['title' => 'B', 'field_season' => 'winter']], 422, 'validation_failed', 'field_season'],
      [['slug' => 'b', 'data' => ['title' => 'B', 'field_season' => FALSE]], 422, 'validation_failed', 'field_season'],
      [['slug' => 'b', 'data' => ['title' => 'B', 'field_rating' => FALSE]], 422, 'validation_failed', 'field_rating'],
      [['slug' => 'b', 'published' => 'yes', 'data' => ['title' => 'B']], 422, 'validation_failed', 'published'],
    ];
    foreach ($cases as [$body, $status, $code, $field]) {
      $response = $this->post('article', $body);
      $this->assertSame($status, $response->getStatusCode(), json_encode($body));
      $error = $this->decode($response)['error'];
      $this->assertSame($code, $error['code'], json_encode($body));
      $this->assertArrayHasKey($field, $error['errors'], json_encode($body));
    }
    $this->assertSame(404, $this->post('nope', ['slug' => 'b', 'data' => ['title' => 'B']])->getStatusCode());
  }

  public function testPublishedOnCreateNeedsPublishPermission(): void {
    $response = $this->post('page', ['slug' => 'about', 'published' => TRUE, 'data' => ['title' => 'About']]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('Not authorized to publish this resource.', $this->decode($response)['error']['message']);
    $admin = $this->createEditor(['access editor api', 'access content', 'create page content', 'administer nodes', 'use text format basic_html']);
    $data = $this->decode($this->post('page', ['slug' => 'about', 'published' => TRUE, 'data' => ['title' => 'About']], $admin))['data'];
    $this->assertTrue($data['published']);
  }

  public function testCreateWithoutCreatePermissionIsForbidden(): void {
    $reader = $this->createEditor(['access editor api', 'access content']);
    $this->assertSame(403, $this->post('article', ['slug' => 'x', 'data' => ['title' => 'X']], $reader)->getStatusCode());
  }

  public function testUpdateReplacesDataKeepsFormatAndHonoursBaseModified(): void {
    $id = $this->decode($this->post('page', ['slug' => 'about', 'data' => ['title' => 'About', 'body' => '<p>v1</p>']]))['data']['id'];
    Node::load((int) $id)->set('body', ['value' => '<p>v1</p>', 'format' => 'full_html'])->save();
    $headers = $this->bearer($this->user);
    $before = $this->decode($this->request('GET', "/api/editor/v1/entries/{$id}", NULL, $headers))['data'];

    $response = $this->request('PATCH', "/api/editor/v1/entries/{$id}", ['slug' => 'about-us', 'date' => '2026-09-01', 'data' => ['title' => 'About us', 'body' => '<p>v2 <b>bold</b></p>']], $headers + ['X-Base-Modified' => $before['last_modified']]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $after = $this->decode($response)['data'];
    $this->assertSame('about-us', $after['slug']);
    $this->assertSame('About us', $after['title']);
    $this->assertSame('<p>v2 <b>bold</b></p>', $after['data']['body']);
    $this->assertStringStartsWith('2026-09-01T', $after['date']);
    $this->assertSame('full_html', Node::load((int) $id)->get('body')->format);
    $this->assertSame('/page/about-us', \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $id));
    // L'ancien alias a été remplacé, pas doublé : `/page/about` ne résout plus vers ce node.
    $this->assertSame('/page/about', \Drupal::service('path_alias.manager')->getPathByAlias('/page/about'));
    $this->assertCount(1, \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['path' => '/node/' . $id]));

    $stale = $this->request('PATCH', "/api/editor/v1/entries/{$id}", ['data' => ['title' => 'Stale']], $headers + ['X-Base-Modified' => '2000-01-01T00:00:00+00:00']);
    $this->assertSame(409, $stale->getStatusCode());
    $this->assertSame('conflict', $this->decode($stale)['error']['code']);

    $published = $this->request('PATCH', "/api/editor/v1/entries/{$id}", ['published' => TRUE, 'data' => ['title' => 'X']], $headers);
    $this->assertSame(422, $published->getStatusCode());
    $this->assertArrayHasKey('published', $this->decode($published)['error']['errors']);

    $this->assertSame(422, $this->request('PATCH', "/api/editor/v1/entries/{$id}", ['slug' => 'x'], $headers)->getStatusCode());
  }

  public function testUpdateUnderModerationCreatesPendingDraft(): void {
    $this->enableEditorialWorkflow('article');
    $user = $this->createEditor(['access editor api', 'access content', 'create article content', 'edit any article content', 'use editorial transition publish', 'use editorial transition create_new_draft', 'use text format basic_html', 'view own unpublished content', 'view latest version']);
    $created = $this->decode($this->post('article', ['slug' => 'tide', 'published' => TRUE, 'data' => ['title' => 'Live']], $user))['data'];
    $this->assertTrue($created['published']);
    $headers = $this->bearer($user);
    $updated = $this->decode($this->request('PATCH', "/api/editor/v1/entries/{$created['id']}", ['data' => ['title' => 'Draft']], $headers))['data'];
    $this->assertSame('Draft', $updated['title']);
    $this->assertTrue($updated['published']);
    $this->assertTrue($updated['has_unpublished_changes']);
    $this->assertSame('Live', Node::load((int) $created['id'])->label());
    $list = $this->decode($this->request('GET', '/api/editor/v1/collections/article/entries', NULL, $headers))['data'][0];
    $this->assertSame('Live', $list['title']);
  }

  public function testUpdateWithoutModerationWritesDirectly(): void {
    $id = $this->decode($this->post('page', ['slug' => 'about', 'data' => ['title' => 'About']]))['data']['id'];
    $updated = $this->decode($this->request('PATCH', "/api/editor/v1/entries/{$id}", ['data' => ['title' => 'About v2']], $this->bearer($this->user)))['data'];
    $this->assertFalse($updated['has_unpublished_changes']);
    $this->assertSame('About v2', Node::load((int) $id)->label());
    // Toutes les révisions du node (revisionIds() est déprécié en 11.3 : requête d'entité).
    $vids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('nid', (int) $id)
      ->execute();
    $this->assertCount(2, $vids);
  }

  public function testWritingAFieldNeedsTheRightToUseItsTextFormat(): void {
    $id = $this->decode($this->post('page', ['slug' => 'about', 'data' => ['title' => 'About', 'body' => '<p>v1</p>']]))['data']['id'];
    Node::load((int) $id)->set('body', ['value' => '<p>v1</p>', 'format' => 'full_html'])->save();

    // Cet éditeur peut modifier la page, mais pas se servir de « full_html » :
    // écrire le corps signerait du contenu dans un format qui lui est interdit.
    $limited = $this->createEditor(['access editor api', 'access content', 'edit any page content', 'use text format basic_html']);
    $response = $this->request('PATCH', "/api/editor/v1/entries/{$id}", ['data' => ['body' => '<p>v2</p>']], $this->bearer($limited));
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $error = $this->decode($response)['error'];
    $this->assertSame('validation_failed', $error['code']);
    $this->assertArrayHasKey('body', $error['errors']);
    $this->assertSame('<p>v1</p>', Node::load((int) $id)->get('body')->value);

    // Le même PATCH par un compte qui a la permission passe, format conservé.
    $ok = $this->request('PATCH', "/api/editor/v1/entries/{$id}", ['data' => ['body' => '<p>v2</p>']], $this->bearer($this->user));
    $this->assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());
    $node = Node::load((int) $id);
    $this->assertSame('<p>v2</p>', $node->get('body')->value);
    $this->assertSame('full_html', $node->get('body')->format);
  }

  public function testLastModifiedOfTheWorkingCopyRoundTripsThroughBaseModified(): void {
    $this->enableEditorialWorkflow('article');
    // Les permissions de transition n'existent qu'une fois le workflow créé.
    $user = $this->createEditor([
      'access editor api', 'access content', 'create article content', 'edit any article content',
      'use editorial transition publish', 'use editorial transition create_new_draft',
      'use text format basic_html', 'view own unpublished content', 'view latest version',
    ]);
    $created = $this->decode($this->post('article', ['slug' => 'tide', 'published' => TRUE, 'data' => ['title' => 'Live']], $user))['data'];
    $headers = $this->bearer($user);

    // Chaque réponse annonce le `last_modified` de la copie de travail ; le
    // client le rejoue tel quel, la modification suivante ne doit pas être
    // déclarée périmée.
    $base = $created['last_modified'];
    foreach (['Draft v2', 'Draft v3'] as $title) {
      $response = $this->request('PATCH', "/api/editor/v1/entries/{$created['id']}", ['data' => ['title' => $title]], $headers + ['X-Base-Modified' => $base]);
      $this->assertSame(200, $response->getStatusCode(), $title . ' : ' . (string) $response->getContent());
      $data = $this->decode($response)['data'];
      $this->assertSame($title, $data['title']);
      $base = $data['last_modified'];
    }
  }

  public function testBaseModifiedRejectsAnythingButIso8601(): void {
    $id = $this->decode($this->post('page', ['slug' => 'about', 'data' => ['title' => 'About']]))['data']['id'];
    $response = $this->request('PATCH', "/api/editor/v1/entries/{$id}", ['data' => ['title' => 'X']], $this->bearer($this->user) + ['X-Base-Modified' => 'now']);
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $error = $this->decode($response)['error'];
    $this->assertSame('validation_failed', $error['code']);
    $this->assertArrayHasKey('X-Base-Modified', $error['errors']);
    $this->assertSame('About', Node::load((int) $id)->label());
  }

  public function testReferencesMustBeViewable(): void {
    // Un terme non publié n'est visible qu'avec « administer taxonomy » : le référencer est un 422.
    $hidden = Term::create(['vid' => 'regions', 'name' => 'Hidden', 'status' => 0]);
    $hidden->save();
    $response = $this->post('article', ['slug' => 'h', 'data' => ['title' => 'H', 'field_regions' => [(string) $hidden->id()]]]);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertArrayHasKey('field_regions', $this->decode($response)['error']['errors']);
    $admin = $this->createEditor(['access editor api', 'access content', 'create article content', 'administer taxonomy', 'use text format basic_html']);
    $this->assertSame(201, $this->post('article', ['slug' => 'h', 'data' => ['title' => 'H', 'field_regions' => [(string) $hidden->id()]]], $admin)->getStatusCode());
  }

  public function testDelete(): void {
    $id = $this->decode($this->post('article', ['slug' => 'x', 'data' => ['title' => 'X']]))['data']['id'];
    $reader = $this->createEditor(['access editor api', 'access content']);
    $this->assertSame(403, $this->request('DELETE', "/api/editor/v1/entries/{$id}", NULL, $this->bearer($reader))->getStatusCode());
    $this->assertSame(204, $this->request('DELETE', "/api/editor/v1/entries/{$id}", NULL, $this->bearer($this->user))->getStatusCode());
    $this->assertSame(404, $this->request('GET', "/api/editor/v1/entries/{$id}", NULL, $this->bearer($this->user))->getStatusCode());
  }

}
