<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * GET /media/{uuid} : le média que porte un <drupal-media> d'un champ `html`, retrouvé par son
 * UUID. Sans contrainte de type — la balise peut viser une vidéo ou un document, qui n'est pas
 * un conteneur d'assets — et derrière le même contrôle d'accès que le détail d'un asset.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class MediaByUuidTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;

  protected function setUp(): void {
    parent::setUp();
    $this->createImageMediaType('image');
    $this->user = $this->createEditor(['access editor api', 'view media']);
  }

  public function testShowReturnsTheAssetSummaryOfTheMedia(): void {
    $media = $this->createImageMedia('beach.jpg', (int) $this->user->id(), ['alt' => 'Low tide']);

    $response = $this->request('GET', '/api/editor/v1/media/' . $media->uuid(), NULL, $this->bearer($this->user));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $asset = $this->decode($response)['data'];

    $this->assertSame($media->uuid(), $asset['uuid']);
    $this->assertSame('image::' . $media->id() . '/beach.jpg', $asset['id']);
    $this->assertStringEndsWith('/media/beach.jpg', $asset['url']);
    $this->assertStringContainsString('/styles/thumbnail/', $asset['thumbnail']);
    $this->assertSame(['alt' => 'Low tide', 'title' => ''], $asset['data']);

    // La MÊME forme que le détail servi par le conteneur : une seule définition du résumé.
    $detail = $this->decode($this->request('GET', '/api/editor/v1/assets/image/' . $media->id() . '/beach.jpg', NULL, $this->bearer($this->user)))['data'];
    $this->assertSame($detail, $asset);
  }

  public function testUnknownUuidIsNotFound(): void {
    $response = $this->request('GET', '/api/editor/v1/media/11111111-2222-3333-4444-555555555555', NULL, $this->bearer($this->user));
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('not_found', $this->decode($response)['error']['code']);
  }

  public function testAMediaTheAccountCannotSeeIsForbidden(): void {
    $owner = $this->createEditor(['access editor api', 'view media', 'view own unpublished media'], 'owner@example.com');
    $draft = $this->createImageMedia('draft.jpg', (int) $owner->id(), [], ['status' => 0]);

    $this->assertSame(403, $this->request('GET', '/api/editor/v1/media/' . $draft->uuid(), NULL, $this->bearer($this->user))->getStatusCode());
    $this->assertSame(200, $this->request('GET', '/api/editor/v1/media/' . $draft->uuid(), NULL, $this->bearer($owner))->getStatusCode());
  }

  /**
   * Le cas qui motive l'endpoint : un <drupal-media> peut viser un média dont le type n'est PAS
   * un conteneur d'assets. `GET /assets/{type}/…` le refuse (404 par `MediaLoader::container()`),
   * `GET /media/{uuid}` doit le servir — l'app a besoin d'une vignette, pas d'un fichier.
   */
  public function testAMediaOutsideAnyAssetContainerIsStillServed(): void {
    $type = \Drupal\media\Entity\MediaType::create(['id' => 'quote', 'label' => 'Quote', 'source' => 'test']);
    $type->save();
    $sourceField = $type->getSource()->createSourceField($type);
    $sourceField->getFieldStorageDefinition()->save();
    $sourceField->save();
    $type->set('source_configuration', ['source_field' => $sourceField->getName()])->save();

    $media = \Drupal\media\Entity\Media::create(['bundle' => 'quote', 'name' => 'Citation', 'uid' => $this->user->id()]);
    $media->save();

    $response = $this->request('GET', '/api/editor/v1/media/' . $media->uuid(), NULL, $this->bearer($this->user));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $asset = $this->decode($response)['data'];
    $this->assertSame($media->uuid(), $asset['uuid']);
    $this->assertNull($asset['url']);
    $this->assertFalse($asset['is_image']);
    // Une vignette existe quand même : l'icône générique de la source, posée par le cœur.
    $this->assertArrayHasKey('thumbnail', $asset);
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/assets/quote/' . $media->id() . '/x', NULL, $this->bearer($this->user))->getStatusCode());
  }

  public function testAuthenticationIsRequired(): void {
    $media = $this->createImageMedia('beach.jpg', (int) $this->user->id());
    $this->assertSame(401, $this->request('GET', '/api/editor/v1/media/' . $media->uuid())->getStatusCode());
  }

}
