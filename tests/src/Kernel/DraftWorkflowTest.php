<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Publication, dépublication, historique et restauration, dans les deux modes.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class DraftWorkflowTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;
  private array $headers;

  protected function setUp(): void {
    parent::setUp();
    $this->createNodeType('article');
    $this->createNodeType('page');
    $this->createBasicHtmlFormat();
    $this->enableEditorialWorkflow('article');
    foreach (['article', 'page'] as $bundle) {
      $this->createField($bundle, 'body', 'text_with_summary', [], [], 1, 'text_textarea_with_summary', 1, FALSE, 'Body');
    }
    $this->user = $this->createEditor([
      'access editor api', 'access content', 'create article content', 'edit any article content', 'create page content', 'edit any page content',
      'administer nodes', 'view all revisions', 'view own unpublished content', 'use editorial transition publish', 'use editorial transition create_new_draft', 'use editorial transition archive',
      'use text format basic_html',
    ], 'jane@example.com');
    $this->headers = $this->bearer($this->user);
  }

  private function createEntry(string $collection, string $slug, string $title, bool $published = FALSE): array {
    return $this->decode($this->request('POST', "/api/editor/v1/collections/{$collection}/entries", ['slug' => $slug, 'published' => $published, 'data' => ['title' => $title]], $this->headers))['data'];
  }

  private function call(string $method, string $path, ?array $body = NULL): array {
    $response = $this->request($method, '/api/editor/v1' . $path, $body, $this->headers);
    return ['status' => $response->getStatusCode(), 'body' => $this->decode($response)];
  }

  public function testModeratedLifecycle(): void {
    $entry = $this->createEntry('article', 'tide', 'Draft v1');
    $this->assertFalse($entry['published']);

    $nothing = $this->call('DELETE', "/entries/{$entry['id']}/published");
    $this->assertSame([422, 'nothing_to_unpublish'], [$nothing['status'], $nothing['body']['error']['code']]);

    $published = $this->call('POST', "/entries/{$entry['id']}/published", ['message' => 'Go live']);
    $this->assertSame(200, $published['status'], json_encode($published));
    $this->assertTrue($published['body']['data']['published']);
    $this->assertFalse($published['body']['data']['has_unpublished_changes']);

    $again = $this->call('POST', "/entries/{$entry['id']}/published");
    $this->assertSame([422, 'nothing_to_publish'], [$again['status'], $again['body']['error']['code']]);

    $this->call('PATCH', "/entries/{$entry['id']}", ['data' => ['title' => 'Draft v2'], 'message' => 'Working on it']);
    $detail = $this->call('GET', "/entries/{$entry['id']}")['body']['data'];
    $this->assertSame(['Draft v2', TRUE, TRUE], [$detail['title'], $detail['published'], $detail['has_unpublished_changes']]);

    $stale = $this->request('POST', "/api/editor/v1/entries/{$entry['id']}/published", NULL, $this->headers + ['X-Base-Modified' => '2000-01-01T00:00:00+00:00']);
    $this->assertSame(409, $stale->getStatusCode());

    $live = $this->call('POST', "/entries/{$entry['id']}/published", ['message' => 'Second release'])['body']['data'];
    $this->assertSame(['Draft v2', TRUE, FALSE], [$live['title'], $live['published'], $live['has_unpublished_changes']]);
    $this->assertSame('Draft v2', Node::load((int) $entry['id'])->label());

    $revisions = $this->call('GET', "/entries/{$entry['id']}/revisions")['body']['data'];
    $this->assertSame(['publish', 'revision', 'publish', 'revision'], array_column($revisions, 'action'));
    $this->assertSame(['Second release', 'Working on it', 'Go live', NULL], array_column($revisions, 'message'));
    $this->assertSame(['id' => (string) $this->user->id(), 'name' => $this->user->getDisplayName(), 'email' => 'jane@example.com'], $revisions[0]['user']);
    $this->assertGreaterThan((int) $revisions[1]['id'], (int) $revisions[0]['id']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $revisions[0]['date']);

    $restored = $this->call('POST', "/entries/{$entry['id']}/revisions/{$revisions[3]['id']}/restore")['body']['data'];
    $this->assertSame(['Draft v1', TRUE, TRUE], [$restored['title'], $restored['published'], $restored['has_unpublished_changes']]);
    $this->assertSame('Draft v2', Node::load((int) $entry['id'])->label());

    $unknown = $this->call('POST', "/entries/{$entry['id']}/revisions/999999/restore");
    $this->assertSame([404, 'revision_not_found'], [$unknown['status'], $unknown['body']['error']['code']]);

    $unpublished = $this->call('DELETE', "/entries/{$entry['id']}/published", ['message' => 'Archive'])['body']['data'];
    $this->assertFalse($unpublished['published']);
    $this->assertFalse(Node::load((int) $entry['id'])->isPublished());
    $this->assertSame('archived', Node::load((int) $entry['id'])->get('moderation_state')->value);
  }

  public function testRestoreOnDraftReplacesTheDraftInPlace(): void {
    $entry = $this->createEntry('article', 'x', 'v1');
    $this->call('PATCH', "/entries/{$entry['id']}", ['data' => ['title' => 'v2']]);
    $first = $this->call('GET', "/entries/{$entry['id']}/revisions")['body']['data'];
    $restored = $this->call('POST', "/entries/{$entry['id']}/revisions/{$first[1]['id']}/restore")['body']['data'];
    $this->assertSame(['v1', FALSE, FALSE], [$restored['title'], $restored['published'], $restored['has_unpublished_changes']]);
    $this->assertSame('v1', Node::load((int) $entry['id'])->label());
  }

  public function testDirectModeTogglesStatusAndHasNoRevisionsEndpoint(): void {
    $entry = $this->createEntry('page', 'about', 'About');
    $published = $this->call('POST', "/entries/{$entry['id']}/published")['body']['data'];
    $this->assertTrue($published['published']);
    $this->assertTrue(Node::load((int) $entry['id'])->isPublished());
    $again = $this->call('POST', "/entries/{$entry['id']}/published");
    $this->assertSame('nothing_to_publish', $again['body']['error']['code']);
    $unpublished = $this->call('DELETE', "/entries/{$entry['id']}/published")['body']['data'];
    $this->assertFalse($unpublished['published']);
    foreach (['GET' => "/entries/{$entry['id']}/revisions", 'POST' => "/entries/{$entry['id']}/revisions/1/restore"] as $method => $path) {
      $result = $this->call($method, $path);
      $this->assertSame([422, 'revisions_disabled'], [$result['status'], $result['body']['error']['code']], $path);
    }
  }

  public function testPublishNeedsThePermission(): void {
    $entry = $this->createEntry('article', 'x', 'X');
    $writer = $this->createEditor(['access editor api', 'access content', 'edit any article content', 'use editorial transition create_new_draft']);
    $response = $this->request('POST', "/api/editor/v1/entries/{$entry['id']}/published", NULL, $this->bearer($writer));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('Not authorized to publish this resource.', $this->decode($response)['error']['message']);
    $response = $this->request('GET', "/api/editor/v1/entries/{$entry['id']}/revisions", NULL, $this->bearer($writer));
    $this->assertSame(200, $response->getStatusCode());
  }

}
