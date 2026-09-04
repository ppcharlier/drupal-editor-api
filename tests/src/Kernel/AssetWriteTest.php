<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\editor_api\Http\ApiException;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload (service), PATCH (renommage, métadonnées), DELETE, et droits.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class AssetWriteTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;
  private array $headers;

  protected function setUp(): void {
    parent::setUp();
    $this->createImageMediaType('image');
    $this->user = $this->createEditor(['access editor api', 'view media', 'create media', 'edit any image media', 'delete any image media']);
    $this->headers = $this->bearer($this->user);
  }

  private function uploaded(string $basename): InputStreamUploadedFile {
    $path = $this->createTestImage($basename);
    return new InputStreamUploadedFile($basename, $basename, $path, filesize($path));
  }

  public function testUploaderCreatesFileAndMedia(): void {
    $this->container->get('current_user')->setAccount($this->user);
    $media = $this->container->get('editor_api.asset_uploader')->upload(MediaType::load('image'), $this->uploaded('beach.jpg'));
    $this->assertSame('image', $media->bundle());
    $this->assertSame('beach.jpg', $media->getName());
    $this->assertSame((int) $this->user->id(), (int) $media->getOwnerId());
    $file = $this->container->get('editor_api.media_loader')->sourceFile($media);
    $this->assertTrue($file->isPermanent());
    $this->assertStringStartsWith('public://', $file->getFileUri());
    $this->assertFileExists($this->container->get('file_system')->realpath($file->getFileUri()));
    $summary = $this->container->get('editor_api.asset_payload')->summary($media, $this->user);
    $this->assertSame($media->id() . '/beach.jpg', $summary['path']);
    $this->assertSame(['alt' => 'beach', 'title' => ''], $summary['data']);

    // Un second fichier du même nom est renommé, jamais écrasé.
    $second = $this->container->get('editor_api.asset_uploader')->upload(MediaType::load('image'), $this->uploaded('beach.jpg'));
    $this->assertSame('beach_0.jpg', $this->container->get('editor_api.media_loader')->sourceFile($second)->getFilename());
  }

  public function testUploaderRejectsForbiddenExtension(): void {
    $this->container->get('current_user')->setAccount($this->user);
    $path = $this->container->get('file_system')->getTempDirectory() . '/evil.php';
    file_put_contents($path, '<?php echo 1;');
    try {
      $this->container->get('editor_api.asset_uploader')->upload(MediaType::load('image'), new InputStreamUploadedFile('evil.php', 'evil.php', $path, filesize($path)));
      $this->fail('Expected a validation error.');
    }
    catch (ApiException $e) {
      $this->assertSame(422, $e->status);
      $this->assertArrayHasKey('file', $e->errors);
    }
  }

  public function testStoreErrorPathsOverHttp(): void {
    $response = $this->requestMultipart('/api/editor/v1/assets/image', [], [], $this->headers);
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $this->assertArrayHasKey('file', $this->decode($response)['error']['errors']);

    $file = new UploadedFile($this->createTestImage('beach.jpg'), 'beach.jpg', 'image/jpeg', NULL, TRUE);
    $response = $this->requestMultipart('/api/editor/v1/assets/image', ['file' => $file], ['folder' => 'photos'], $this->headers);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertArrayHasKey('folder', $this->decode($response)['error']['errors']);

    $reader = $this->createEditor(['access editor api', 'view media']);
    $response = $this->requestMultipart('/api/editor/v1/assets/image', ['file' => $file], [], $this->bearer($reader));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('Not authorized to upload this resource.', $this->decode($response)['error']['message']);
    $this->assertSame(404, $this->requestMultipart('/api/editor/v1/assets/nope', ['file' => $file], [], $this->headers)->getStatusCode());
  }

  public function testUpdateRenamesAndEditsMetadata(): void {
    $media = $this->createImageMedia('beach.jpg', (int) $this->user->id(), ['alt' => 'Old alt']);
    $path = $media->id() . '/beach.jpg';
    $before = $this->container->get('file_system')->realpath('public://media/beach.jpg');

    $response = $this->request('PATCH', '/api/editor/v1/assets/image/' . $path, ['filename' => 'low-tide', 'data' => ['alt' => 'Low tide', 'title' => 'Beach']], $this->headers);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $asset = $this->decode($response)['data'];
    $this->assertSame('low-tide.jpg', $asset['basename']);
    $this->assertSame($media->id() . '/low-tide.jpg', $asset['path']);
    $this->assertSame(['alt' => 'Low tide', 'title' => 'Beach'], $asset['data']);
    $this->assertFileDoesNotExist($before);
    $this->assertFileExists($this->container->get('file_system')->realpath('public://media/low-tide.jpg'));
    $this->assertSame('low-tide.jpg', Media::load((int) $media->id())->getName());

    $this->assertSame(404, $this->request('GET', '/api/editor/v1/assets/image/' . $path, NULL, $this->headers)->getStatusCode());
    $newPath = $media->id() . '/low-tide.jpg';
    $cases = [
      [[], 'validation_failed'],
      [['folder' => 'photos'], 'validation_failed'],
      [['filename' => '../x'], 'validation_failed'],
      [['filename' => 'a/b'], 'validation_failed'],
      [['data' => ['nope' => 1]], 'unknown_field'],
    ];
    foreach ($cases as [$body, $code]) {
      $response = $this->request('PATCH', '/api/editor/v1/assets/image/' . $newPath, $body, $this->headers);
      $this->assertSame(422, $response->getStatusCode(), json_encode($body));
      $this->assertSame($code, $this->decode($response)['error']['code'], json_encode($body));
    }
    $this->createImageMedia('taken.jpg', (int) $this->user->id());
    $response = $this->request('PATCH', '/api/editor/v1/assets/image/' . $newPath, ['filename' => 'taken'], $this->headers);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertArrayHasKey('filename', $this->decode($response)['error']['errors']);
  }

  public function testUpdateAndDeleteNeedPermissions(): void {
    $media = $this->createImageMedia('beach.jpg', (int) $this->user->id());
    $path = $media->id() . '/beach.jpg';
    $reader = $this->createEditor(['access editor api', 'view media']);
    $response = $this->request('PATCH', '/api/editor/v1/assets/image/' . $path, ['filename' => 'x'], $this->bearer($reader));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('Not authorized to rename this resource.', $this->decode($response)['error']['message']);
    $response = $this->request('PATCH', '/api/editor/v1/assets/image/' . $path, ['data' => ['alt' => 'x']], $this->bearer($reader));
    $this->assertSame('Not authorized to edit this resource.', $this->decode($response)['error']['message']);
    $response = $this->request('DELETE', '/api/editor/v1/assets/image/' . $path, NULL, $this->bearer($reader));
    $this->assertSame('Not authorized to delete this resource.', $this->decode($response)['error']['message']);
    $this->assertFileExists($this->container->get('file_system')->realpath('public://media/beach.jpg'));
  }

  public function testDeleteRemovesMediaAndFile(): void {
    $media = $this->createImageMedia('beach.jpg', (int) $this->user->id());
    $path = $media->id() . '/beach.jpg';
    $real = $this->container->get('file_system')->realpath('public://media/beach.jpg');
    $this->assertSame(204, $this->request('DELETE', '/api/editor/v1/assets/image/' . $path, NULL, $this->headers)->getStatusCode());
    $this->assertNull(Media::load((int) $media->id()));
    $this->assertFileDoesNotExist($real);
    $this->assertSame(404, $this->request('DELETE', '/api/editor/v1/assets/image/' . $path, NULL, $this->headers)->getStatusCode());
  }

}
