<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * GET /assets/{type} et GET /assets/{type}/{path} : conteneurs, forme, pagination, visibilité.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class AssetReadTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;

  protected function setUp(): void {
    parent::setUp();
    $this->createImageMediaType('image');
    $this->user = $this->createEditor(['access editor api', 'view media', 'edit any image media']);
  }

  public function testListAndDetailShape(): void {
    $beach = $this->createImageMedia('beach.jpg', (int) $this->user->id(), ['alt' => 'Low tide', 'title' => 'Beach']);
    $this->createImageMedia('alps.jpg', (int) $this->user->id());
    $headers = $this->bearer($this->user);

    $response = $this->request('GET', '/api/editor/v1/assets/image', NULL, $headers);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $body = $this->decode($response);
    $this->assertSame([], $body['data']['folders']);
    $this->assertSame(['alps.jpg', 'beach.jpg'], array_column($body['data']['assets'], 'basename'));
    $this->assertSame(['total' => 2, 'current_page' => 1, 'per_page' => 25, 'last_page' => 1, 'folders_total' => 0, 'folders_last_page' => 1], $body['meta']);

    $asset = $body['data']['assets'][1];
    $this->assertSame(['id', 'path', 'url', 'filename', 'basename', 'extension', 'folder', 'size', 'mime_type', 'is_image', 'last_modified', 'data', 'can', 'embed'], array_keys($asset));
    $this->assertSame('image::' . $beach->id() . '/beach.jpg', $asset['id']);
    $this->assertSame($beach->id() . '/beach.jpg', $asset['path']);
    $this->assertStringStartsWith('http', $asset['url']);
    $this->assertStringEndsWith('/media/beach.jpg', $asset['url']);
    $this->assertSame('beach', $asset['filename']);
    $this->assertSame('jpg', $asset['extension']);
    $this->assertSame('', $asset['folder']);
    $this->assertGreaterThan(0, $asset['size']);
    $this->assertSame('image/jpeg', $asset['mime_type']);
    $this->assertTrue($asset['is_image']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $asset['last_modified']);
    $this->assertSame(['alt' => 'Low tide', 'title' => 'Beach'], $asset['data']);
    $this->assertSame(['edit' => TRUE, 'move' => FALSE, 'rename' => TRUE, 'delete' => FALSE], $asset['can']);
    $sourceField = \Drupal\media\Entity\MediaType::load('image')->getSource()->getConfiguration()['source_field'];
    $file = \Drupal\file\Entity\File::load((int) $beach->get($sourceField)->target_id);
    $this->assertSame(['entity_type' => 'file', 'uuid' => $file->uuid()], $asset['embed']);

    $detail = $this->decode($this->request('GET', '/api/editor/v1/assets/image/' . $beach->id() . '/beach.jpg', NULL, $headers))['data'];
    $this->assertSame($asset, $detail);

    $page = $this->decode($this->request('GET', '/api/editor/v1/assets/image?per_page=1&page=2', NULL, $headers));
    $this->assertSame(['beach.jpg'], array_column($page['data']['assets'], 'basename'));
    $this->assertSame(2, $page['meta']['last_page']);
  }

  /**
   * Spec de l'éditeur HTML §7.1 : `embed` porte l'UUID du FICHIER source (celui que le filtre
   * `editor_file_reference` lit dans `data-entity-uuid`), et la clé est absente — pas nulle — pour
   * un média sans fichier, comme `url` est nulle dans ce cas.
   */
  public function testEmbedIsAbsentForAMediaWithoutFile(): void {
    $type = \Drupal\media\Entity\MediaType::load('image');
    $orphan = \Drupal\media\Entity\Media::create(['bundle' => 'image', 'name' => 'orphan', 'uid' => $this->user->id()]);
    $orphan->save();
    $this->createImageMedia('beach.jpg', (int) $this->user->id());
    $assets = $this->decode($this->request('GET', '/api/editor/v1/assets/image', NULL, $this->bearer($this->user)))['data']['assets'];
    $byName = array_column($assets, NULL, 'basename');
    $this->assertArrayHasKey('embed', $byName['beach.jpg']);
    $this->assertSame('file', $byName['beach.jpg']['embed']['entity_type']);
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $byName['beach.jpg']['embed']['uuid']);
    $this->assertArrayNotHasKey('embed', $byName['']);
    $this->assertNull($byName['']['url']);
  }

  public function testUnknownContainerPathAndFolder(): void {
    $beach = $this->createImageMedia('beach.jpg', (int) $this->user->id());
    $headers = $this->bearer($this->user);
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/assets/nope', NULL, $headers)->getStatusCode());
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/assets/image/' . $beach->id() . '/other.jpg', NULL, $headers)->getStatusCode());
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/assets/image/999/beach.jpg', NULL, $headers)->getStatusCode());
    $response = $this->request('GET', '/api/editor/v1/assets/image?folder=photos', NULL, $headers);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertArrayHasKey('folder', $this->decode($response)['error']['errors']);
    $this->assertSame(200, $this->request('GET', '/api/editor/v1/assets/image?folder=', NULL, $headers)->getStatusCode());
    // La racine s'écrit aussi `/` — c'est ce que le client iOS envoie toujours.
    $this->assertSame(200, $this->request('GET', '/api/editor/v1/assets/image?folder=/', NULL, $headers)->getStatusCode());
    $this->assertSame(200, $this->request('GET', '/api/editor/v1/assets/image?folder=%2F', NULL, $headers)->getStatusCode());
  }

  public function testVisibilityFollowsMediaAccess(): void {
    $owner = $this->createEditor(['access editor api', 'view media', 'view own unpublished media']);
    $this->createImageMedia('draft.jpg', (int) $owner->id(), [], ['status' => 0]);
    $this->createImageMedia('live.jpg', (int) $this->user->id());
    $list = fn($u) => array_column($this->decode($this->request('GET', '/api/editor/v1/assets/image', NULL, $this->bearer($u)))['data']['assets'], 'basename');
    $this->assertSame(['live.jpg'], $list($this->user));
    $this->assertSame(['draft.jpg', 'live.jpg'], $list($owner));
    $admin = $this->createEditor(['access editor api', 'administer media']);
    $this->assertSame(['draft.jpg', 'live.jpg'], $list($admin));
    $noView = $this->createEditor(['access editor api']);
    $response = $this->request('GET', '/api/editor/v1/assets/image', NULL, $this->bearer($noView));
    $this->assertSame(0, $this->decode($response)['meta']['total']);
  }

}
