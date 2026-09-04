<?php

declare(strict_types=1);

namespace Drupal\editor_api\Http;

/**
 * Une erreur du contrat : statut HTTP, code stable, message, détail par champ.
 *
 * Les codes sont ceux de la documentation de l'addon Statamic ; aucun autre
 * n'est émis. `errors` n'apparaît dans l'envelope que s'il est non vide.
 *
 * `$code` n'est ni `readonly` ni typé : `\Exception` déclare déjà une
 * propriété `$code` (non readonly, non typée), et PHP 8.4 interdit à une
 * classe fille de la redéclarer readonly (« Cannot redeclare non-readonly
 * property Exception::$code as readonly ») ou de lui ajouter un type
 * (« Type of ApiException::$code must not be defined (as in class
 * Exception) »). Elle reste publique et immuable en pratique : rien dans ce
 * module ne la réassigne après construction.
 */
final class ApiException extends \RuntimeException {

  public $code;

  public function __construct(
    public readonly int $status,
    string $code,
    string $message,
    public readonly array $errors = [],
  ) {
    parent::__construct($message, $status);
    $this->code = $code;
  }

  public static function of(int $status, string $code, string $message, array $errors = []): self {
    return new self($status, $code, $message, $errors);
  }

  public static function notFound(string $message = 'Not found.'): self {
    return new self(404, 'not_found', $message);
  }

  public static function revisionNotFound(): self {
    return new self(404, 'revision_not_found', 'Revision not found.');
  }

  public static function forbidden(string $ability): self {
    return new self(403, 'forbidden', "Not authorized to {$ability} this resource.");
  }

  public static function validation(array $errors, string $message = 'The given data was invalid.'): self {
    return new self(422, 'validation_failed', $message, $errors);
  }

  public static function unknownField(string $field): self {
    return new self(422, 'unknown_field', "Unknown field: {$field}.", [$field => ['This field is not part of the blueprint.']]);
  }

  public static function unauthenticated(): self {
    return new self(401, 'unauthenticated', 'Unauthenticated.');
  }

  public static function tokenExpired(): self {
    return new self(401, 'token_expired', 'Token expired. Sign in again.');
  }

  public static function invalidCredentials(): self {
    return new self(401, 'invalid_credentials', 'Invalid credentials.');
  }

  public static function conflict(string $message, array $errors = []): self {
    return new self(409, 'conflict', $message, $errors);
  }

  public static function rateLimited(): self {
    return new self(429, 'rate_limited', 'Too many attempts. Try again later.');
  }

  public static function nothingToPublish(): self {
    return new self(422, 'nothing_to_publish', 'There are no unpublished changes to publish.');
  }

  public static function nothingToUnpublish(): self {
    return new self(422, 'nothing_to_unpublish', 'The entry is already unpublished.');
  }

  public static function revisionsDisabled(): self {
    return new self(422, 'revisions_disabled', 'Revisions are disabled for this collection.');
  }

}
