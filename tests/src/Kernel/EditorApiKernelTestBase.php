<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base des Kernel tests : modules, schémas, et requêtes HTTP via http_kernel.
 *
 * Passer par `http_kernel` fait traverser routage, authentification, contrôle
 * d'accès et abonnés aux exceptions — tout ce que le contrat exige — sans
 * serveur web ni BrowserTestBase.
 */
#[RunTestsInSeparateProcesses]
abstract class EditorApiKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'taxonomy', 'path', 'path_alias',
    'options', 'datetime', 'file', 'image', 'media', 'media_library', 'views', 'workflows', 'content_moderation', 'link',
    'editor_api', 'editor_api_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('editor_api_token');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'editor_api']);
    // L'utilisateur 0 (anonyme) doit exister pour les contrôles d'accès.
    \Drupal::entityTypeManager()->getStorage('user')->create(['uid' => 0, 'name' => ''])->save();
    // L'utilisateur 1 (root) contourne toute vérification de permission dans
    // Drupal — sans lui, le premier `createEditor()` de chaque test hériterait
    // de cet uid et fausserait les tests qui vérifient un refus de permission.
    // On le consomme ici pour que les utilisateurs de test partent à partir
    // de l'uid 2.
    \Drupal::entityTypeManager()->getStorage('user')->create(['uid' => 1, 'name' => 'root'])->save();
  }

  /**
   * Envoie une requête à travers le kernel complet.
   */
  protected function request(string $method, string $uri, ?array $json = NULL, array $headers = []): Response {
    $server = ['HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $content = $json === NULL ? NULL : json_encode($json, JSON_THROW_ON_ERROR);
    if ($content !== NULL) {
      $server['CONTENT_TYPE'] = 'application/json';
    }
    $request = Request::create($uri, $method, [], [], [], $server, $content);
    $response = $this->container->get('http_kernel')->handle($request);
    $this->container->get('http_kernel')->terminate($request, $response);
    return $response;
  }

  /**
   * Décode une réponse JSON en tableau.
   */
  protected function decode(Response $response): array {
    $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), $response->getContent());
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Crée un utilisateur actif avec un rôle portant les permissions données.
   */
  protected function createEditor(array $permissions = ['access editor api'], string $mail = 'jane@example.com', string $pass = 'secret-pass'): \Drupal\user\UserInterface {
    static $n = 0;
    $n++;
    $role = \Drupal\user\Entity\Role::create(['id' => 'role_' . $n, 'label' => 'Role ' . $n]);
    // `access content` est un préalable du cœur de Drupal à toute vérification
    // d'accès sur les nœuds (NodeAccessControlHandler::access()/createAccess()
    // la vérifient avant même la permission de type) ; elle n'est pas listée
    // dans le contrat, on l'accorde donc systématiquement ici plutôt que dans
    // chaque appel de test.
    foreach ([...$permissions, 'access content'] as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $user = \Drupal\user\Entity\User::create(['name' => 'user' . $n, 'mail' => $mail, 'pass' => $pass, 'status' => 1]);
    $user->addRole($role->id());
    $user->save();
    return $user;
  }

  /**
   * En-têtes d'une requête authentifiée par un jeton neuf de cet utilisateur.
   */
  protected function bearer(\Drupal\user\UserInterface $user): array {
    $issued = $this->container->get('editor_api.token_issuer')->issue($user, 'phpunit');
    return ['Authorization' => 'Bearer ' . $issued->plain];
  }

  protected function createNodeType(string $type, ?string $label = NULL): \Drupal\node\Entity\NodeType {
    $node_type = \Drupal\node\Entity\NodeType::create(['type' => $type, 'name' => $label ?? ucfirst($type)]);
    $node_type->save();
    // Le formulaire par défaut existe dès qu'un affichage est demandé ; on le crée pour y ordonner les champs.
    \Drupal::service('entity_display.repository')->getFormDisplay('node', $type)->save();
    return $node_type;
  }

  /**
   * Workflow « editorial » du profil standard : draft, published, archived.
   */
  protected function enableEditorialWorkflow(string ...$bundles): \Drupal\workflows\Entity\Workflow {
    $workflow = \Drupal\workflows\Entity\Workflow::load('editorial')
      ?? \Drupal\workflows\Entity\Workflow::create(['id' => 'editorial', 'label' => 'Editorial', 'type' => 'content_moderation']);
    /** @var \Drupal\content_moderation\Plugin\WorkflowType\ContentModeration $plugin */
    $plugin = $workflow->getTypePlugin();
    if (!$plugin->hasState('archived')) {
      $plugin->addState('archived', 'Archived');
      $configuration = $plugin->getConfiguration();
      $configuration['states']['archived'] += ['published' => FALSE, 'default_revision' => TRUE];
      $plugin->setConfiguration($configuration);
      $plugin->addTransition('archive', 'Archive', ['published'], 'archived');
      $plugin->addTransition('archived_draft', 'Restore to Draft', ['archived'], 'draft');
    }
    foreach ($bundles as $bundle) {
      $plugin->addEntityTypeAndBundle('node', $bundle);
    }
    $workflow->save();
    return $workflow;
  }

  protected function createImageMediaType(string $id = 'image'): \Drupal\media\Entity\MediaType {
    $type = \Drupal\media\Entity\MediaType::create(['id' => $id, 'label' => ucfirst($id), 'source' => 'image']);
    $type->save();
    $source_field = $type->getSource()->createSourceField($type);
    $source_field->getFieldStorageDefinition()->save();
    $source_field->save();
    $type->set('source_configuration', ['source_field' => $source_field->getName()])->save();
    return $type;
  }

  /**
   * Crée un champ configurable, sa storage, et le place dans le formulaire par défaut.
   */
  protected function createField(string $bundle, string $name, string $type, array $storage = [], array $settings = [], int $cardinality = 1, ?string $widget = NULL, int $weight = 0, bool $required = FALSE, ?string $label = NULL, string $entityType = 'node'): \Drupal\field\Entity\FieldConfig {
    if (!\Drupal\field\Entity\FieldStorageConfig::loadByName($entityType, $name)) {
      \Drupal\field\Entity\FieldStorageConfig::create([
        'field_name' => $name, 'entity_type' => $entityType, 'type' => $type, 'cardinality' => $cardinality, 'settings' => $storage,
      ])->save();
    }
    $field = \Drupal\field\Entity\FieldConfig::create([
      'field_name' => $name, 'entity_type' => $entityType, 'bundle' => $bundle, 'label' => $label ?? ucfirst(str_replace('field_', '', $name)),
      'required' => $required, 'settings' => $settings,
    ]);
    $field->save();
    $display = \Drupal::service('entity_display.repository')->getFormDisplay($entityType, $bundle);
    $options = ['weight' => $weight];
    if ($widget !== NULL) {
      $options['type'] = $widget;
    }
    $display->setComponent($name, $options)->save();
    return $field;
  }

  protected function createBasicHtmlFormat(): \Drupal\filter\Entity\FilterFormat {
    $format = \Drupal\filter\Entity\FilterFormat::create([
      'format' => 'basic_html', 'name' => 'Basic HTML', 'weight' => 0,
      'filters' => ['filter_html' => ['status' => TRUE, 'settings' => ['allowed_html' => '<a href hreflang> <em> <strong> <p> <h2 id> <ul> <ol> <li> <img src alt>']]],
    ]);
    $format->save();
    return $format;
  }

}
