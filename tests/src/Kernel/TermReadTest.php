<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * GET /taxonomies et GET /taxonomies/{vid}/terms : formes, tri, recherche, visibilité.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class TermReadTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;

  protected function setUp(): void {
    parent::setUp();
    $this->createBasicHtmlFormat();
    $this->createVocabulary('regions', 'Regions');
    $this->createVocabulary('themes', 'Themes');
    $this->createField('regions', 'field_country', 'string', ['max_length' => 60], [], 1, 'string_textfield', 5, FALSE, 'Country', 'taxonomy_term');
    $this->user = $this->createEditor(['access editor api', 'edit terms in regions', 'create terms in regions', 'use text format basic_html']);
  }

  public function testTaxonomiesCarryCompactBlueprints(): void {
    $response = $this->request('GET', '/api/editor/v1/taxonomies', NULL, $this->bearer($this->user));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $taxonomies = array_column($this->decode($response)['data'], NULL, 'handle');
    $this->assertSame(['regions', 'themes'], array_keys($taxonomies));
    $regions = $taxonomies['regions'];
    $this->assertSame(['handle', 'title', 'blueprint', 'blueprints', 'can'], array_keys($regions));
    $this->assertSame('Regions', $regions['title']);
    $this->assertSame('regions', $regions['blueprint']['handle']);
    $this->assertSame($regions['blueprint'], $regions['blueprints'][0]);
    $fields = array_column($regions['blueprint']['tabs'][0]['fields'], NULL, 'handle');
    $this->assertSame(['title', 'description', 'field_country', 'slug'], array_keys($fields));
    $this->assertSame('text', $fields['title']['type']);
    $this->assertTrue($fields['title']['required']);
    $this->assertSame('html', $fields['description']['type']);
    $this->assertTrue($fields['slug']['meta']);
    $this->assertSame(['create' => TRUE], $regions['can']);
    $this->assertSame(['create' => FALSE], $taxonomies['themes']['can']);
  }

  public function testTermListShapeSortSearchAndPagination(): void {
    $bretagne = $this->createTerm('regions', 'Bretagne', ['field_country' => 'FR', 'description' => ['value' => '<p>Ouest</p>', 'format' => 'basic_html']]);
    $bretagne->set('path', ['alias' => '/regions/bretagne'])->save();
    $this->createTerm('regions', 'Alpes');
    $this->createTerm('regions', 'Provence');
    $this->createTerm('themes', 'Randonnée');
    $headers = $this->bearer($this->user);

    $body = $this->decode($this->request('GET', '/api/editor/v1/taxonomies/regions/terms', NULL, $headers));
    $this->assertSame(['Alpes', 'Bretagne', 'Provence'], array_column($body['data'], 'title'));
    $this->assertSame(['total' => 3, 'current_page' => 1, 'per_page' => 25, 'last_page' => 1], $body['meta']);
    $term = array_column($body['data'], NULL, 'title')['Bretagne'];
    $this->assertSame(['id', 'taxonomy', 'blueprint', 'slug', 'title', 'published', 'data', 'can'], array_keys($term));
    $this->assertSame('regions::' . $bretagne->id(), $term['id']);
    $this->assertSame('regions', $term['taxonomy']);
    $this->assertSame('regions', $term['blueprint']);
    $this->assertSame('bretagne', $term['slug']);
    $this->assertTrue($term['published']);
    $this->assertSame(['title' => 'Bretagne', 'description' => '<p>Ouest</p>', 'field_country' => 'FR'], $term['data']);
    $this->assertSame(['edit' => TRUE, 'delete' => FALSE], $term['can']);
    $alpes = array_column($body['data'], NULL, 'title')['Alpes'];
    $this->assertSame((string) $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties(['name' => 'Alpes'])[array_key_first($this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties(['name' => 'Alpes']))]->id(), $alpes['slug']);

    $desc = $this->decode($this->request('GET', '/api/editor/v1/taxonomies/regions/terms?sort=-title', NULL, $headers));
    $this->assertSame(['Provence', 'Bretagne', 'Alpes'], array_column($desc['data'], 'title'));
    $search = $this->decode($this->request('GET', '/api/editor/v1/taxonomies/regions/terms?search=bre', NULL, $headers));
    $this->assertSame(['Bretagne'], array_column($search['data'], 'title'));
    $page = $this->decode($this->request('GET', '/api/editor/v1/taxonomies/regions/terms?per_page=2&page=2', NULL, $headers));
    $this->assertSame(['Provence'], array_column($page['data'], 'title'));
    $this->assertSame(2, $page['meta']['last_page']);
  }

  public function testInvalidParametersAndUnknownVocabulary(): void {
    $headers = $this->bearer($this->user);
    foreach (['sort=weight', 'per_page=0', 'per_page=101', 'page=0', 'search=' . str_repeat('a', 201)] as $query) {
      $response = $this->request('GET', '/api/editor/v1/taxonomies/regions/terms?' . $query, NULL, $headers);
      $this->assertSame(422, $response->getStatusCode(), $query);
    }
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/taxonomies/nope/terms', NULL, $headers)->getStatusCode());
  }

  public function testUnpublishedTermsNeedAdministerTaxonomy(): void {
    $this->createTerm('regions', 'Secret', ['status' => 0]);
    $this->createTerm('regions', 'Public');
    $list = $this->decode($this->request('GET', '/api/editor/v1/taxonomies/regions/terms', NULL, $this->bearer($this->user)));
    $this->assertSame(['Public'], array_column($list['data'], 'title'));
    $admin = $this->createEditor(['access editor api', 'administer taxonomy']);
    $list = $this->decode($this->request('GET', '/api/editor/v1/taxonomies/regions/terms', NULL, $this->bearer($admin)));
    $this->assertSame(['Public', 'Secret'], array_column($list['data'], 'title'));
    $this->assertFalse(array_column($list['data'], NULL, 'title')['Secret']['published']);

    // La règle de vue du cœur exige `access content` : `createEditor()`
    // l'accorde toujours, on monte donc le rôle et le compte à la main pour
    // éprouver un compte qui ne l'a pas du tout.
    $role = \Drupal\user\Entity\Role::create(['id' => 'no_content', 'label' => 'No content']);
    $role->grantPermission('access editor api');
    $role->save();
    $blind = \Drupal\user\Entity\User::create(['name' => 'blind', 'mail' => 'blind@example.com', 'pass' => 'secret-pass', 'status' => 1]);
    $blind->addRole($role->id());
    $blind->save();
    $list = $this->decode($this->request('GET', '/api/editor/v1/taxonomies/regions/terms', NULL, $this->bearer($blind)));
    $this->assertSame([], $list['data']);
    $this->assertSame(0, $list['meta']['total']);
  }

}
