<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Http\RequestBody;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * L'envelope d'erreur couvre chaque exception sous le préfixe, et rien en dehors.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class ErrorEnvelopeTest extends EditorApiKernelTestBase {

  public function testUnexpectedExceptionIsServerErrorWithoutLeak(): void {
    $response = $this->request('GET', '/api/editor/v1/_boom');
    $this->assertSame(500, $response->getStatusCode());
    $body = $this->decode($response);
    $this->assertSame('server_error', $body['error']['code']);
    $this->assertStringNotContainsString('secret detail', (string) $response->getContent());
    $this->assertArrayNotHasKey('errors', $body['error']);
  }

  public function testHttpExceptionKeepsStatusAsHttpError(): void {
    $response = $this->request('GET', '/api/editor/v1/_teapot');
    $this->assertSame(418, $response->getStatusCode());
    $this->assertSame('http_error', $this->decode($response)['error']['code']);
  }

  public function testAccessDeniedIsForbidden(): void {
    $response = $this->request('GET', '/api/editor/v1/_forbidden');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', $this->decode($response)['error']['code']);
  }

  public function testUnknownPathUnderPrefixIsNotFound(): void {
    $response = $this->request('GET', '/api/editor/v1/nope');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('not_found', $this->decode($response)['error']['code']);
  }

  public function testOutsidePrefixIsUntouched(): void {
    // Sonde 418 (et non la sonde RuntimeException `_boom`) : toute exception
    // générique (non-HTTP) qui atteint `ExceptionLoggingSubscriber::onError()`
    // appelle inconditionnellement `error_log()` (core), ce qui écrit sur
    // STDERR et fait échouer ce test sous `beStrictAboutOutputDuringTests`
    // (phpunit.xml du site) — indépendamment du fait que la réponse finale
    // soit correcte. Une HttpException 4xx passe par `onClientError()`, qui
    // ne journalise pas ainsi. La frontière testée reste le préfixe, pas le
    // type d'exception : les autres tests couvrent déjà les deux cas sous le
    // préfixe.
    $response = $this->request('GET', '/_outside_boom', NULL, ['Accept' => 'text/html']);
    $this->assertSame(418, $response->getStatusCode());
    $this->assertStringStartsNotWith('application/json', (string) $response->headers->get('Content-Type'));
  }

  public function testEnvelopeShapes(): void {
    $data = json_decode((string) Envelope::data(['id' => '1'])->getContent(), TRUE);
    $this->assertSame(['data' => ['id' => '1']], $data);

    $page = Envelope::page([['id' => '1']], 42, 2, 25, ['folders_total' => 3]);
    $this->assertSame(['total' => 42, 'current_page' => 2, 'per_page' => 25, 'last_page' => 2, 'folders_total' => 3], json_decode((string) $page->getContent(), TRUE)['meta']);

    $error = Envelope::error(ApiException::validation(['slug' => ['The slug field is required.']]));
    $this->assertSame(422, $error->getStatusCode());
    $this->assertSame(['code' => 'validation_failed', 'message' => 'The given data was invalid.', 'errors' => ['slug' => ['The slug field is required.']]], json_decode((string) $error->getContent(), TRUE)['error']);

    $this->assertSame('/assets/a.jpg', json_decode((string) Envelope::data(['url' => '/assets/a.jpg'])->getContent(), TRUE)['data']['url']);
    $this->assertStringContainsString('"url":"/assets/a.jpg"', (string) Envelope::data(['url' => '/assets/a.jpg'])->getContent());
    $this->assertSame(204, Envelope::noContent()->getStatusCode());
  }

  public function testRequestBodyRejectsMalformedJson(): void {
    $this->assertSame(['a' => 1], RequestBody::json(Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":1}')));
    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('Malformed JSON body.');
    RequestBody::json(Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":'));
  }

  public function testRequestBodyRejectsNonObject(): void {
    $this->expectException(ApiException::class);
    RequestBody::json(Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[1,2]'));
  }

}
