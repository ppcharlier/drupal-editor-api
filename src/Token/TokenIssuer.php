<?php

declare(strict_types=1);

namespace Drupal\editor_api\Token;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\editor_api\Entity\Token;
use Drupal\user\UserInterface;

/**
 * Émet, retrouve, rafraîchit et révoque les jetons.
 */
final class TokenIssuer {

  public const PREFIX = 'editor_api_';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  public function issue(UserInterface $user, string $deviceName): IssuedToken {
    $plain = self::PREFIX . bin2hex(random_bytes(20));
    $now = $this->time->getRequestTime();
    $ttlDays = (int) $this->configFactory->get('editor_api.settings')->get('token_ttl_days');
    $expires = $ttlDays > 0 ? $now + $ttlDays * 86400 : 0;
    /** @var \Drupal\editor_api\Entity\Token $token */
    $token = $this->storage()->create([
      'uid' => $user->id(),
      'name' => mb_substr($deviceName, 0, 100),
      'token_hash' => hash('sha256', $plain),
      'expires' => $expires,
      'last_used' => 0,
    ]);
    $token->save();
    return new IssuedToken($plain, $expires ?: NULL, $token);
  }

  public function find(string $plain): ?Token {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('token_hash', hash('sha256', $plain))
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    /** @var \Drupal\editor_api\Entity\Token|null $token */
    $token = $this->storage()->load(reset($ids));
    return $token;
  }

  /**
   * Note l'usage, au plus une fois par minute pour ne pas écrire à chaque appel.
   */
  public function touch(Token $token): void {
    $now = $this->time->getRequestTime();
    if ($now - $token->getLastUsed() >= 60) {
      $token->set('last_used', $now)->save();
    }
  }

  public function revoke(Token $token): void {
    $token->delete();
  }

  private function storage(): \Drupal\Core\Entity\EntityStorageInterface {
    return $this->entityTypeManager->getStorage('editor_api_token');
  }

}
