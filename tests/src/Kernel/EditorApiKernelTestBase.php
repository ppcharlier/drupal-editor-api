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
    'options', 'datetime', 'file', 'image', 'media', 'workflows', 'content_moderation',
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
    foreach ($permissions as $permission) {
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

}
