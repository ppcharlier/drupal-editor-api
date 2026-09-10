<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * GET /config : collections, modes de révision, taxonomies, conteneurs, capacités.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class ConfigTest extends EditorApiKernelTestBase {

  public function testConfigShape(): void {
    $this->createNodeType('article');
    $this->createNodeType('page');
    $this->enableEditorialWorkflow('article');
    Vocabulary::create(['vid' => 'regions', 'name' => 'Regions'])->save();
    $this->createImageMediaType('image');
    $this->config('system.site')->set('name', "Carnet d'Ailleurs")->save();
    // Le fuseau du SITE, distinct du fuseau PHP (Australia/Sydney, fixé par le socle Kernel) :
    // un réglage explicite prouve que /config lit bien `system.date`, pas l'horloge du process.
    $this->config('system.date')->set('timezone.default', 'Europe/Brussels')->save();

    $user = $this->createEditor(['access editor api', 'create article content', 'use editorial transition publish', 'create terms in regions', 'create media']);
    $response = $this->request('GET', '/api/editor/v1/config', NULL, $this->bearer($user));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $data = $this->decode($response)['data'];

    $this->assertSame([['handle' => 'default', 'name' => "Carnet d'Ailleurs", 'url' => 'http://localhost', 'locale' => 'en', 'default' => TRUE]], $data['sites']);
    $this->assertSame('Europe/Brussels', $data['timezone']);

    // Le catalogue des formats : l'app y lit les balises du format de CHAQUE corps, au lieu de
    // supposer celles du format par défaut du compte.
    $byId = array_column($data['text_formats'], NULL, 'id');
    $this->assertArrayHasKey('plain_text', $byId);
    $this->assertSame(['id', 'name', 'allowed_html', 'can'], array_keys($byId['plain_text']));
    $this->assertIsBool($byId['plain_text']['can']['use']);

    $collections = array_column($data['collections'], NULL, 'handle');
    $this->assertSame(['article', 'page'], array_keys($collections));
    $this->assertSame([
      'handle' => 'article', 'title' => 'Article', 'revisions_enabled' => TRUE, 'dated' => TRUE, 'structured' => FALSE,
      'blueprints' => ['article'], 'sites' => ['default'], 'can' => ['create' => TRUE, 'publish' => TRUE],
    ], $collections['article']);
    $this->assertFalse($collections['page']['revisions_enabled']);
    $this->assertSame(['create' => FALSE, 'publish' => FALSE], $collections['page']['can']);

    $this->assertSame([['handle' => 'regions', 'title' => 'Regions', 'blueprints' => ['regions'], 'sites' => ['default'], 'can' => ['create' => TRUE]]], $data['taxonomies']);
    $this->assertSame([['handle' => 'image', 'title' => 'Image', 'can' => ['upload' => TRUE]]], $data['asset_containers']);
    $this->assertSame([], $data['globals']);
    $this->assertSame([], $data['navigations']);
    $this->assertSame([], $data['forms']);
  }

  public function testPublishCapabilityWithoutWorkflowNeedsAdministerNodes(): void {
    $this->createNodeType('page');
    $editor = $this->createEditor(['access editor api', 'create page content']);
    $admin = $this->createEditor(['access editor api', 'create page content', 'administer nodes']);
    $page = fn($u) => array_column($this->decode($this->request('GET', '/api/editor/v1/config', NULL, $this->bearer($u)))['data']['collections'], NULL, 'handle')['page'];
    $this->assertSame(['create' => TRUE, 'publish' => FALSE], $page($editor)['can']);
    $this->assertSame(['create' => TRUE, 'publish' => TRUE], $page($admin)['can']);
  }

  public function testNodeCapabilities(): void {
    $this->createNodeType('article');
    $this->enableEditorialWorkflow('article');
    $author = $this->createEditor(['access editor api', 'create article content', 'edit own article content', 'use editorial transition publish']);
    $other = $this->createEditor(['access editor api', 'edit any article content', 'delete any article content', 'use editorial transition create_new_draft']);
    $noTransition = $this->createEditor(['access editor api', 'edit any article content']);
    $node = Node::create(['type' => 'article', 'title' => 'Low tide', 'uid' => $author->id(), 'moderation_state' => 'draft']);
    $node->save();
    $capabilities = $this->container->get('editor_api.capabilities');
    $this->assertSame(['edit' => TRUE, 'delete' => FALSE, 'publish' => TRUE], $capabilities->forNode($node, $author));
    $this->assertSame(['edit' => TRUE, 'delete' => TRUE, 'publish' => FALSE], $capabilities->forNode($node, $other));
    // Content Moderation refuse « update » à un utilisateur sans transition ouverte depuis l'état courant, exactement comme le ferait le formulaire d'édition.
    $this->assertSame(['edit' => FALSE, 'delete' => FALSE, 'publish' => FALSE], $capabilities->forNode($node, $noTransition));
  }

  /**
   * Quel serveur répond, pour une app qui sert plusieurs CMS (spec « Editor for CMS »,
   * 2026-09-08). Une constante, jamais un réglage : un site n'a aucune raison légitime de
   * mentir sur le CMS qui le sert.
   */
  public function testConfigAnnouncesTheCms(): void {
    $user = $this->createEditor();
    $config = $this->decode($this->request('GET', '/api/editor/v1/config', NULL, $this->bearer($user)))['data'];
    $this->assertSame('drupal', $config['cms']);
  }

  public function testConfigAnnouncesServerVersions(): void {
    $user = $this->createEditor();
    $config = $this->decode($this->request('GET', '/api/editor/v1/config', NULL, $this->bearer($user)))['data'];
    $this->assertSame(\Drupal::VERSION, $config['cms_version']);
    $this->assertSame('dev', $config['editor_api_version']);
  }

  public function testConfigNeedsPermission(): void {
    $user = $this->createEditor([]);
    $response = $this->request('GET', '/api/editor/v1/config', NULL, $this->bearer($user));
    $this->assertSame(403, $response->getStatusCode());
  }

  public function testTimezoneFallsBackToUtc(): void {
    // Sans réglage `system.date`, /config replie sur UTC — jamais le fuseau PHP du process.
    $this->config('system.date')->clear('timezone.default')->save();
    $user = $this->createEditor(['access editor api']);
    $data = $this->decode($this->request('GET', '/api/editor/v1/config', NULL, $this->bearer($user)))['data'];
    $this->assertSame('UTC', $data['timezone']);
  }

}
