<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\taxonomy\Entity\Term;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * POST / PATCH / DELETE des termes : slug, unicité, valeurs, droits.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class TermWriteTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;
  private array $headers;

  protected function setUp(): void {
    parent::setUp();
    $this->createBasicHtmlFormat();
    $this->createVocabulary('regions', 'Regions');
    $this->createField('regions', 'field_country', 'string', ['max_length' => 60], [], 1, 'string_textfield', 5, FALSE, 'Country', 'taxonomy_term');
    $this->user = $this->createEditor(['access editor api', 'create terms in regions', 'edit terms in regions', 'delete terms in regions', 'use text format basic_html']);
    $this->headers = $this->bearer($this->user);
  }

  public function testCreateUpdateDelete(): void {
    $response = $this->request('POST', '/api/editor/v1/taxonomies/regions/terms', ['slug' => 'bretagne', 'data' => ['title' => 'Bretagne', 'description' => '<p>Ouest</p>', 'field_country' => 'FR']], $this->headers);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $term = $this->decode($response)['data'];
    $this->assertSame('bretagne', $term['slug']);
    $this->assertSame('Bretagne', $term['title']);
    $this->assertTrue($term['published']);
    $this->assertSame(['title' => 'Bretagne', 'description' => '<p>Ouest</p>', 'field_country' => 'FR'], $term['data']);
    $tid = (int) explode('::', $term['id'])[1];
    $this->assertSame('/regions/bretagne', $this->container->get('path_alias.manager')->getAliasByPath('/taxonomy/term/' . $tid));
    $this->assertSame('basic_html', Term::load($tid)->get('description')->format);

    $updated = $this->request('PATCH', '/api/editor/v1/taxonomies/regions/terms/bretagne', ['slug' => 'bretagne-sud', 'published' => FALSE, 'data' => ['title' => 'Bretagne Sud', 'field_country' => 'FR']], $this->headers);
    $this->assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
    $data = $this->decode($updated)['data'];
    $this->assertSame('bretagne-sud', $data['slug']);
    $this->assertSame('Bretagne Sud', $data['title']);
    $this->assertFalse($data['published']);
    $this->assertSame('<p>Ouest</p>', $data['data']['description']);
    $this->assertSame(404, $this->request('PATCH', '/api/editor/v1/taxonomies/regions/terms/bretagne', ['data' => ['title' => 'x']], $this->headers)->getStatusCode());

    $byTid = $this->request('PATCH', "/api/editor/v1/taxonomies/regions/terms/{$tid}", ['data' => ['title' => 'Bretagne']], $this->headers);
    $this->assertSame(200, $byTid->getStatusCode());
    $this->assertSame('bretagne-sud', $this->decode($byTid)['data']['slug']);

    $this->assertSame(204, $this->request('DELETE', '/api/editor/v1/taxonomies/regions/terms/bretagne-sud', NULL, $this->headers)->getStatusCode());
    $this->assertNull(Term::load($tid));
    $this->assertSame(404, $this->request('DELETE', '/api/editor/v1/taxonomies/regions/terms/bretagne-sud', NULL, $this->headers)->getStatusCode());
  }

  public function testValidationErrors(): void {
    $this->request('POST', '/api/editor/v1/taxonomies/regions/terms', ['slug' => 'taken', 'data' => ['title' => 'Taken']], $this->headers);
    $cases = [
      [['slug' => 'taken', 'data' => ['title' => 'B']], 'validation_failed', 'slug'],
      [['slug' => 'Bad Slug', 'data' => ['title' => 'B']], 'validation_failed', 'slug'],
      [['data' => ['title' => 'B']], 'validation_failed', 'slug'],
      [['slug' => 'b'], 'validation_failed', 'data'],
      [['slug' => 'b', 'data' => ['description' => '<p>no title</p>']], 'validation_failed', 'title'],
      // Le champ de base `name` borne à 255 : la violation ressort sous la clé
      // du contrat, `title`, et jamais sous `name`.
      [['slug' => 'b', 'data' => ['title' => str_repeat('a', 300)]], 'validation_failed', 'title'],
      [['slug' => 'b', 'data' => ['title' => 'B', 'nope' => 1]], 'unknown_field', 'nope'],
      [['slug' => 'b', 'blueprint' => 'themes', 'data' => ['title' => 'B']], 'validation_failed', 'blueprint'],
      [['slug' => 'b', 'published' => 'yes', 'data' => ['title' => 'B']], 'validation_failed', 'published'],
    ];
    foreach ($cases as [$body, $code, $field]) {
      $response = $this->request('POST', '/api/editor/v1/taxonomies/regions/terms', $body, $this->headers);
      $this->assertSame(422, $response->getStatusCode(), json_encode($body));
      $error = $this->decode($response)['error'];
      $this->assertSame($code, $error['code'], json_encode($body));
      $this->assertArrayHasKey($field, $error['errors'], json_encode($body));
    }
    $this->assertSame(404, $this->request('POST', '/api/editor/v1/taxonomies/nope/terms', ['slug' => 'b', 'data' => ['title' => 'B']], $this->headers)->getStatusCode());
  }

  /**
   * Même défaut que sur les entrées (filmé le 2026-09-07) : un compte qui ne peut pas
   * employer le format de la description ne pouvait plus renommer le terme, alors que sa
   * requête ne portait que sur le titre.
   */
  public function testUpdateOnlyValidatesTheFieldsItTouches(): void {
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML', 'weight' => 1])->save();
    $created = $this->decode($this->request('POST', '/api/editor/v1/taxonomies/regions/terms', ['slug' => 'bretagne', 'data' => ['title' => 'Bretagne', 'description' => '<p>Ouest</p>']], $this->headers))['data'];
    $tid = (int) explode('::', $created['id'])[1];
    Term::load($tid)->set('description', ['value' => '<p>Ouest</p>', 'format' => 'full_html'])->save();

    $limited = $this->createEditor(['access editor api', 'edit terms in regions', 'use text format basic_html']);
    $response = $this->request('PATCH', '/api/editor/v1/taxonomies/regions/terms/bretagne', ['data' => ['title' => 'Bretagne Sud']], $this->bearer($limited));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $term = Term::load($tid);
    $this->assertSame('Bretagne Sud', $term->label());
    $this->assertSame('<p>Ouest</p>', $term->get('description')->value);
    $this->assertSame('full_html', $term->get('description')->format);

    // Le miroir : la description DANS la carte reste refusée, sous sa propre clé.
    $refused = $this->request('PATCH', '/api/editor/v1/taxonomies/regions/terms/bretagne', ['data' => ['description' => '<p>Sud</p>']], $this->bearer($limited));
    $this->assertSame(422, $refused->getStatusCode(), (string) $refused->getContent());
    $this->assertArrayHasKey('description', $this->decode($refused)['error']['errors']);
  }

  public function testPermissions(): void {
    $created = $this->decode($this->request('POST', '/api/editor/v1/taxonomies/regions/terms', ['slug' => 'alpes', 'data' => ['title' => 'Alpes']], $this->headers))['data'];
    $reader = $this->createEditor(['access editor api']);
    $headers = $this->bearer($reader);
    $response = $this->request('POST', '/api/editor/v1/taxonomies/regions/terms', ['slug' => 'x', 'data' => ['title' => 'X']], $headers);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('Not authorized to create this resource.', $this->decode($response)['error']['message']);
    $response = $this->request('PATCH', '/api/editor/v1/taxonomies/regions/terms/alpes', ['data' => ['title' => 'Y']], $headers);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('Not authorized to edit this resource.', $this->decode($response)['error']['message']);
    $response = $this->request('DELETE', '/api/editor/v1/taxonomies/regions/terms/alpes', NULL, $headers);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('Not authorized to delete this resource.', $this->decode($response)['error']['message']);
    $this->assertSame('Alpes', Term::load((int) explode('::', $created['id'])[1])->label());
  }

}
