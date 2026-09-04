<?php

declare(strict_types=1);

namespace Drupal\editor_api\Token;

use Drupal\editor_api\Entity\Token;

/**
 * Le résultat d'une émission : le jeton en clair, rendu une seule fois.
 */
final readonly class IssuedToken {

  public function __construct(
    public string $plain,
    public ?int $expiresAt,
    public Token $token,
  ) {}

  public function expiresAtIso(): ?string {
    return $this->expiresAt === NULL ? NULL : (new \DateTimeImmutable('@' . $this->expiresAt))->format(DATE_ATOM);
  }

}
