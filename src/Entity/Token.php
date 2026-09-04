<?php

declare(strict_types=1);

namespace Drupal\editor_api\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Un jeton d'accès de l'app : un utilisateur, un appareil, une empreinte.
 *
 * Le jeton en clair n'est jamais stocké : seule son empreinte SHA-256 l'est.
 * Révoquer = supprimer l'entité. Entité interne : pas d'UI, pas de routes.
 */
#[ContentEntityType(
  id: 'editor_api_token',
  label: new TranslatableMarkup('Editor API token'),
  entity_keys: ['id' => 'id', 'uuid' => 'uuid'],
  base_table: 'editor_api_token',
  admin_permission: 'administer users',
  internal: TRUE,
)]
final class Token extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Device name'))
      ->setSetting('max_length', 100)
      ->setRequired(TRUE);
    $fields['token_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Token hash (SHA-256)'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));
    $fields['expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Expires (0 = never)'))
      ->setDefaultValue(0);
    $fields['last_used'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Last used'))
      ->setDefaultValue(0);
    return $fields;
  }

  public function getOwnerId(): int {
    return (int) $this->get('uid')->target_id;
  }

  public function getDeviceName(): string {
    return (string) $this->get('name')->value;
  }

  public function getHash(): string {
    return (string) $this->get('token_hash')->value;
  }

  public function getExpires(): int {
    return (int) $this->get('expires')->value;
  }

  public function getLastUsed(): int {
    return (int) $this->get('last_used')->value;
  }

  public function isExpired(int $now): bool {
    $expires = $this->getExpires();
    return $expires > 0 && $expires <= $now;
  }

}
