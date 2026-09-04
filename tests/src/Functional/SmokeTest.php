<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Functional;

use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Bout en bout par HTTP réel : connexion, config, création, lecture, suppression.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class SmokeTest extends BrowserTestBase {

  protected $defaultTheme = 'stark';

  protected static $modules = ['node', 'path', 'editor_api', 'media', 'image', 'file'];

  public function testSignInThenCreateAndReadAnEntry(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $user = $this->drupalCreateUser(['access editor api', 'access content', 'create article content', 'edit any article content', 'delete any article content', 'view own unpublished content']);
    $client = $this->getHttpClient();
    $base = rtrim($this->baseUrl, '/') . '/api/editor/v1';
    $json = fn(array $body) => [RequestOptions::JSON => $body, RequestOptions::HTTP_ERRORS => FALSE];

    $signIn = $client->post($base . '/auth/tokens', $json(['email' => $user->getEmail(), 'password' => $user->passRaw, 'device_name' => 'phpunit']));
    $this->assertSame(201, $signIn->getStatusCode(), (string) $signIn->getBody());
    $token = json_decode((string) $signIn->getBody(), TRUE)['data']['token'];
    $auth = fn(array $extra = []) => [RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $token], RequestOptions::HTTP_ERRORS => FALSE] + $extra;

    $configResponse = $client->get($base . '/config', $auth());
    $this->assertSame(200, $configResponse->getStatusCode(), (string) $configResponse->getBody());
    $config = json_decode((string) $configResponse->getBody(), TRUE)['data'];
    $this->assertSame('article', $config['collections'][0]['handle']);

    $created = $client->post($base . '/collections/article/entries', $auth([RequestOptions::JSON => ['slug' => 'hello', 'data' => ['title' => 'Hello']]]));
    $this->assertSame(201, $created->getStatusCode(), (string) $created->getBody());
    $entry = json_decode((string) $created->getBody(), TRUE)['data'];
    $this->assertSame('hello', $entry['slug']);

    $readResponse = $client->get($base . '/entries/' . $entry['id'], $auth());
    $this->assertSame(200, $readResponse->getStatusCode(), (string) $readResponse->getBody());
    $read = json_decode((string) $readResponse->getBody(), TRUE)['data'];
    $this->assertSame('Hello', $read['data']['title']);

    // Un vrai POST multipart : c'est le seul endroit où FormUploadedFile (move_uploaded_file) est exercé.
    $type = \Drupal\media\Entity\MediaType::create(['id' => 'image', 'label' => 'Image', 'source' => 'image']);
    $type->save();
    $sourceField = $type->getSource()->createSourceField($type);
    $sourceField->getFieldStorageDefinition()->save();
    $sourceField->save();
    $type->set('source_configuration', ['source_field' => $sourceField->getName()])->save();
    $this->grantPermissions(\Drupal\user\Entity\Role::load($user->getRoles(TRUE)[0]), ['view media', 'create media']);
    $image = imagecreatetruecolor(8, 8);
    $tmp = $this->container->get('file_system')->getTempDirectory() . '/smoke.png';
    imagepng($image, $tmp);
    $uploaded = $client->post($base . '/assets/image', $auth([RequestOptions::MULTIPART => [['name' => 'file', 'contents' => fopen($tmp, 'r'), 'filename' => 'smoke.png']]]));
    $this->assertSame(201, $uploaded->getStatusCode(), (string) $uploaded->getBody());
    $asset = json_decode((string) $uploaded->getBody(), TRUE)['data'];
    $this->assertSame('smoke.png', $asset['basename']);
    $this->assertSame(200, $client->get($asset['url'], [RequestOptions::HTTP_ERRORS => FALSE])->getStatusCode());

    $this->assertSame(204, $client->delete($base . '/entries/' . $entry['id'], $auth())->getStatusCode());
    $this->assertSame(401, $client->get($base . '/me', [RequestOptions::HTTP_ERRORS => FALSE])->getStatusCode());
  }

}
