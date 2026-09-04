<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\editor_api\Authentication\Provider\TokenAuth;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Http\RequestBody;
use Drupal\editor_api\Token\TokenIssuer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /auth/tokens (public, limité par IP) et DELETE /auth/tokens/current.
 */
final class TokensController extends ControllerBase {

  private const FLOOD_NAME = 'editor_api.failed_login_ip';
  private const FLOOD_THRESHOLD = 5;
  private const FLOOD_WINDOW = 900;

  public function __construct(
    private readonly TokenIssuer $issuer,
    private readonly PasswordInterface $password,
    private readonly FloodInterface $flood,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('editor_api.token_issuer'), $container->get('password'), $container->get('flood'));
  }

  public function store(Request $request): JsonResponse {
    $body = RequestBody::json($request);
    $errors = [];
    foreach (['email', 'password', 'device_name'] as $key) {
      if (!is_string($body[$key] ?? NULL) || $body[$key] === '') {
        $errors[$key] = ["The {$key} field is required."];
      }
    }
    if (isset($body['device_name']) && is_string($body['device_name']) && mb_strlen($body['device_name']) > 100) {
      $errors['device_name'] = ['The device_name may not be greater than 100 characters.'];
    }
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    $ip = $request->getClientIp() ?? 'unknown';
    if (!$this->flood->isAllowed(self::FLOOD_NAME, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW, $ip)) {
      throw ApiException::rateLimited();
    }
    $users = $this->entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $body['email']]);
    /** @var \Drupal\user\UserInterface|false $user */
    $user = reset($users);
    // Le mot de passe est vérifié AVANT la permission : la réponse ne révèle rien sur les comptes inconnus.
    if (!$user || $user->isBlocked() || !$this->password->check($body['password'], (string) $user->getPassword())) {
      $this->flood->register(self::FLOOD_NAME, self::FLOOD_WINDOW, $ip);
      throw ApiException::invalidCredentials();
    }
    if (!$user->hasPermission('access editor api')) {
      throw ApiException::forbidden('access');
    }
    $this->flood->clear(self::FLOOD_NAME, $ip);
    $issued = $this->issuer->issue($user, $body['device_name']);
    return Envelope::data(['token' => $issued->plain, 'expires_at' => $issued->expiresAtIso()], 201);
  }

  public function destroyCurrent(Request $request): Response {
    /** @var \Drupal\editor_api\Entity\Token $token */
    $token = $request->attributes->get(TokenAuth::REQUEST_ATTRIBUTE);
    $this->issuer->revoke($token);
    return Envelope::noContent();
  }

}
