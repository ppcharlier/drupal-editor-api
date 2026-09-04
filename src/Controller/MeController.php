<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\editor_api\Http\Envelope;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /me — l'utilisateur du jeton.
 */
final class MeController extends ControllerBase {

  public function show(): JsonResponse {
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    return Envelope::data(self::payload($user));
  }

  /**
   * Forme `/me` du contrat. `super` = uid 1 ou `administer nodes`.
   */
  public static function payload(UserInterface $user): array {
    $super = (int) $user->id() === 1 || $user->hasPermission('administer nodes');
    $permissions = [];
    if (!$super) {
      foreach ($user->getRoles() as $rid) {
        $role = \Drupal\user\Entity\Role::load($rid);
        if ($role) {
          $permissions = array_merge($permissions, $role->getPermissions());
        }
      }
      $permissions = array_values(array_unique($permissions));
      sort($permissions);
    }
    $avatar = NULL;
    if ($user->hasField('user_picture') && !$user->get('user_picture')->isEmpty()) {
      $file = $user->get('user_picture')->entity;
      $avatar = $file ? \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()) : NULL;
    }
    return [
      'id' => (string) $user->id(),
      'name' => $user->getDisplayName(),
      'email' => (string) $user->getEmail(),
      'avatar' => $avatar,
      'super' => $super,
      'permissions' => $super ? ['*'] : $permissions,
    ];
  }

}
