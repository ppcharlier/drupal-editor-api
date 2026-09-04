<?php

declare(strict_types=1);

namespace Drupal\editor_api\Authentication\Provider;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Token\TokenIssuer;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authentifie les requêtes du préfixe par jeton Bearer.
 *
 * S'applique à TOUT le préfixe /api/editor/v1/ sauf la création de jeton :
 * un jeton absent, malformé, inconnu ou révoqué est un 401 `unauthenticated`
 * AVANT le routage, comme le contrat l'exige — jamais un 403 anonyme. Les
 * routes du module déclarent `_auth: [editor_api]`, ce qui exclut le cookie
 * (et donc toute question de CSRF).
 */
final class TokenAuth implements AuthenticationProviderInterface {

  public const PREFIX = '/api/editor/v1/';
  public const PUBLIC_PATH = '/api/editor/v1/auth/tokens';
  public const REQUEST_ATTRIBUTE = 'editor_api_token';

  public function __construct(
    private readonly TokenIssuer $issuer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request): bool {
    $path = $request->getPathInfo();
    if (!str_starts_with($path, self::PREFIX)) {
      return FALSE;
    }
    return !($request->isMethod('POST') && rtrim($path, '/') === self::PUBLIC_PATH);
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request): ?AccountInterface {
    $header = (string) $request->headers->get('Authorization', '');
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
      throw ApiException::unauthenticated();
    }
    $token = $this->issuer->find($m[1]);
    if ($token === NULL) {
      throw ApiException::unauthenticated();
    }
    if ($token->isExpired($this->time->getRequestTime())) {
      throw ApiException::tokenExpired();
    }
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->entityTypeManager->getStorage('user')->load($token->getOwnerId());
    if ($user === NULL || $user->isBlocked()) {
      throw ApiException::unauthenticated();
    }
    $this->issuer->touch($token);
    $request->attributes->set(self::REQUEST_ATTRIBUTE, $token);
    return $user;
  }

}
