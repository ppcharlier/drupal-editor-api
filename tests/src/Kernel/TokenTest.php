<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\editor_api\Entity\Token;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Émission, usage, expiration et révocation des jetons ; /me.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class TokenTest extends EditorApiKernelTestBase {

  public function testIssueAndFind(): void {
    $user = $this->createEditor();
    $issuer = $this->container->get('editor_api.token_issuer');
    $issued = $issuer->issue($user, "Jane's iPhone");
    $this->assertStringStartsWith('editor_api_', $issued->plain);
    $this->assertSame(40, strlen(substr($issued->plain, 11)));
    $this->assertNotNull($issued->expiresAt);
    $found = $issuer->find($issued->plain);
    $this->assertInstanceOf(Token::class, $found);
    $this->assertSame((int) $user->id(), $found->getOwnerId());
    $this->assertSame("Jane's iPhone", $found->getDeviceName());
    $this->assertNull($issuer->find('editor_api_' . str_repeat('0', 40)));
    $this->assertNotSame($issued->plain, $found->getHash());
    // Seule l'empreinte SHA-256 est stockée.
    $this->assertSame(hash('sha256', $issued->plain), $found->getHash());
  }

  public function testSignInMeAndRevoke(): void {
    $this->createEditor(['access editor api'], 'jane@example.com', 'secret-pass');
    $response = $this->request('POST', '/api/editor/v1/auth/tokens', ['email' => 'jane@example.com', 'password' => 'secret-pass', 'device_name' => 'phpunit']);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $data = $this->decode($response)['data'];
    $this->assertStringStartsWith('editor_api_', $data['token']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['expires_at']);

    $headers = ['Authorization' => 'Bearer ' . $data['token']];
    $me = $this->decode($this->request('GET', '/api/editor/v1/me', NULL, $headers))['data'];
    $this->assertSame('jane@example.com', $me['email']);
    $this->assertIsString($me['id']);
    $this->assertFalse($me['super']);
    $this->assertContains('access editor api', $me['permissions']);
    $this->assertArrayHasKey('avatar', $me);

    $this->assertSame(204, $this->request('DELETE', '/api/editor/v1/auth/tokens/current', NULL, $headers)->getStatusCode());
    $response = $this->request('GET', '/api/editor/v1/me', NULL, $headers);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('unauthenticated', $this->decode($response)['error']['code']);
  }

  public function testSuperUserReportsStar(): void {
    $user = $this->createEditor(['access editor api', 'administer nodes']);
    $me = $this->decode($this->request('GET', '/api/editor/v1/me', NULL, $this->bearer($user)))['data'];
    $this->assertTrue($me['super']);
    $this->assertSame(['*'], $me['permissions']);
  }

  public function testWrongPasswordThenFlood(): void {
    $this->createEditor(['access editor api'], 'jane@example.com', 'secret-pass');
    for ($i = 0; $i < 5; $i++) {
      $response = $this->request('POST', '/api/editor/v1/auth/tokens', ['email' => 'jane@example.com', 'password' => 'wrong', 'device_name' => 'x']);
      $this->assertSame(401, $response->getStatusCode());
      $this->assertSame('invalid_credentials', $this->decode($response)['error']['code']);
    }
    $response = $this->request('POST', '/api/editor/v1/auth/tokens', ['email' => 'jane@example.com', 'password' => 'secret-pass', 'device_name' => 'x']);
    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('rate_limited', $this->decode($response)['error']['code']);
  }

  public function testUnknownEmailIsInvalidCredentials(): void {
    $response = $this->request('POST', '/api/editor/v1/auth/tokens', ['email' => 'nobody@example.com', 'password' => 'x', 'device_name' => 'x']);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('invalid_credentials', $this->decode($response)['error']['code']);
  }

  public function testMissingFieldsAreValidationFailed(): void {
    $response = $this->request('POST', '/api/editor/v1/auth/tokens', ['email' => 'jane@example.com']);
    $this->assertSame(422, $response->getStatusCode());
    $error = $this->decode($response)['error'];
    $this->assertSame('validation_failed', $error['code']);
    $this->assertSame(['password', 'device_name'], array_keys($error['errors']));
  }

  public function testWithoutPermissionIsForbiddenAfterPasswordCheck(): void {
    $this->createEditor([], 'jane@example.com', 'secret-pass');
    $response = $this->request('POST', '/api/editor/v1/auth/tokens', ['email' => 'jane@example.com', 'password' => 'secret-pass', 'device_name' => 'x']);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', $this->decode($response)['error']['code']);
  }

  public function testExpiredTokenIsTokenExpired(): void {
    $user = $this->createEditor();
    $issued = $this->container->get('editor_api.token_issuer')->issue($user, 'old');
    $issued->token->set('expires', $this->container->get('datetime.time')->getRequestTime() - 1)->save();
    $response = $this->request('GET', '/api/editor/v1/me', NULL, ['Authorization' => 'Bearer ' . $issued->plain]);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('token_expired', $this->decode($response)['error']['code']);
  }

  public function testNeverExpiringTokenWhenTtlIsZero(): void {
    $this->config('editor_api.settings')->set('token_ttl_days', 0)->save();
    $user = $this->createEditor();
    $issued = $this->container->get('editor_api.token_issuer')->issue($user, 'forever');
    $this->assertNull($issued->expiresAt);
    $this->assertNull($issued->expiresAtIso());
    $this->assertSame(200, $this->request('GET', '/api/editor/v1/me', NULL, ['Authorization' => 'Bearer ' . $issued->plain])->getStatusCode());
  }

  public function testBlockedUserAndMalformedHeader(): void {
    $user = $this->createEditor();
    $headers = $this->bearer($user);
    $user->block()->save();
    $this->assertSame(401, $this->request('GET', '/api/editor/v1/me', NULL, $headers)->getStatusCode());
    $response = $this->request('GET', '/api/editor/v1/me', NULL, ['Authorization' => 'Token abc']);
    $this->assertSame('unauthenticated', $this->decode($response)['error']['code']);
  }

  public function testChangingThePasswordRevokesTheTokens(): void {
    $user = $this->createEditor();
    $issuer = $this->container->get('editor_api.token_issuer');
    $issued = $issuer->issue($user, 'phone');
    $headers = ['Authorization' => 'Bearer ' . $issued->plain];
    $this->assertSame(200, $this->request('GET', '/api/editor/v1/me', NULL, $headers)->getStatusCode());

    $user->setPassword('another-pass')->save();
    $this->assertNull($issuer->find($issued->plain));
    $response = $this->request('GET', '/api/editor/v1/me', NULL, $headers);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('unauthenticated', $this->decode($response)['error']['code']);
  }

  public function testBlockingTheAccountRevokesTheTokens(): void {
    $user = $this->createEditor();
    $issuer = $this->container->get('editor_api.token_issuer');
    $issued = $issuer->issue($user, 'phone');
    $user->block()->save();
    $this->assertNull($issuer->find($issued->plain));
  }

  public function testPurgeExpiredRemovesOnlyExpiredTokens(): void {
    $user = $this->createEditor();
    $issuer = $this->container->get('editor_api.token_issuer');
    $now = $this->container->get('datetime.time')->getRequestTime();
    $stale = $issuer->issue($user, 'old');
    $stale->token->set('expires', $now - 1)->save();
    $live = $issuer->issue($user, 'new');

    $this->assertSame(1, $issuer->purgeExpired($now));
    $this->assertNull($issuer->find($stale->plain));
    $this->assertNotNull($issuer->find($live->plain));
  }

  public function testEncodedPrefixStillNeedsAToken(): void {
    // `/api/editor/v%31/me` est le même chemin une fois décodé : le fournisseur
    // doit s'y appliquer, sinon l'absence de jeton devient un refus anonyme.
    $response = $this->request('GET', '/api/editor/v%31/me');
    $this->assertSame(401, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('unauthenticated', $this->decode($response)['error']['code']);
  }

  public function testTokenWithoutPermissionIsForbiddenOnProtectedRoute(): void {
    $user = $this->createEditor([]);
    $response = $this->request('GET', '/api/editor/v1/me', NULL, $this->bearer($user));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', $this->decode($response)['error']['code']);
  }

}
