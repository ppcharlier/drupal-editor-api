<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\filter\Entity\FilterFormat;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Le catalogue des formats de texte et le format qu'un nouvel item reçoit.
 *
 * Le pourquoi de ce fichier : l'app doit éditer un corps avec les balises de SON format, pas
 * celles du format par défaut du compte — un corps en `full_html` sur un compte dont le défaut
 * est `basic_html` perdait ses tableaux et ses médias (constats B1 et A1 des passes iOS).
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class TextFormatCatalogueTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;

  protected function setUp(): void {
    parent::setUp();
    $this->createBasicHtmlFormat();
    // Un format sans filtre `filter_html` : « aucune restriction », la convention du contrat.
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML', 'weight' => 1])->save();
    // Désactivé : il ne doit apparaître nulle part.
    FilterFormat::create(['format' => 'retire', 'name' => 'Retiré', 'weight' => 2, 'status' => FALSE])->save();
    $this->user = $this->createEditor(['access editor api', 'use text format basic_html']);
  }

  public function testTheCatalogueListsEveryEnabledFormatInDrupalOrder(): void {
    $catalogue = $this->container->get('editor_api.formatted_text')->catalogue($this->user);

    // Poids réels : basic_html=0, full_html=1, plain_text=10 (poids par défaut du module filter).
    // `plain_text` vient donc en dernier, pas en tête comme le brouillon de ce test le supposait.
    $this->assertSame(['basic_html', 'full_html', 'plain_text'], array_column($catalogue, 'id'), 'les formats activés, dans l\'ordre de Drupal');
    $this->assertSame(['id', 'name', 'allowed_html', 'can'], array_keys($catalogue[0]));
    $this->assertSame('Basic HTML', $catalogue[0]['name']);
    $this->assertSame(['tag' => 'a', 'attributes' => ['href', 'hreflang']], $catalogue[0]['allowed_html'][0]);
    $this->assertSame([], $catalogue[1]['allowed_html'], 'sans filtre_html : aucune restriction');
  }

  /**
   * Le catalogue liste AUSSI ce que le compte ne peut pas employer : sans cela l'app ne saurait
   * pas nommer le format d'un corps qu'elle affiche en lecture seule.
   */
  public function testTheCatalogueTellsWhichFormatsTheAccountMayUse(): void {
    $catalogue = $this->container->get('editor_api.formatted_text')->catalogue($this->user);
    $byId = array_column($catalogue, NULL, 'id');

    $this->assertSame(['use' => TRUE], $byId['basic_html']['can']);
    $this->assertSame(['use' => FALSE], $byId['full_html']['can']);
  }

  public function testTheFormatOfAValueIsTheFirstItems(): void {
    $formatted = $this->container->get('editor_api.formatted_text');
    $this->createNodeType('page');
    $this->createField('page', 'field_intro', 'text_long');
    // `createNodeType()` ne passe pas par le profil standard : le champ `body` n'existe pas tant
    // qu'on ne le crée pas soi-même (voir les autres tests Kernel du module).
    $this->createField('page', 'body', 'text_with_summary');
    $node = \Drupal\node\Entity\Node::create([
      'type' => 'page', 'title' => 'T', 'uid' => $this->user->id(),
      'field_intro' => ['value' => '<p>a</p>', 'format' => 'full_html'],
    ]);
    $node->save();

    $this->assertSame('full_html', $formatted->formatOf($node->get('field_intro')));
    $this->assertNull($formatted->formatOf($node->get('body')), 'un champ vide n\'a pas de format');
  }

  /**
   * Le défaut corrigé : un champ restreint à `full_html` recevait `basic_html`, le premier format
   * employable par le compte, parce que les `allowed_formats` du champ n'étaient pas lus.
   */
  public function testTheDefaultFormatHonoursTheFieldsAllowedFormats(): void {
    $formatted = $this->container->get('editor_api.formatted_text');
    $this->createNodeType('page');
    $this->createField('page', 'field_libre', 'text_long');
    $this->createField('page', 'field_riche', 'text_long', [], ['allowed_formats' => ['full_html']]);
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'page');
    $wide = $this->createEditor(['access editor api', 'use text format basic_html', 'use text format full_html'], 'wide@example.com');

    // `basic_html` (poids 0) précède `plain_text` (poids 10) : c'est le vrai premier format
    // employable par le compte, pas `plain_text` comme le brouillon de ce test le supposait.
    $this->assertSame('full_html', $formatted->defaultFormat($wide, $definitions['field_riche']));
    $this->assertSame('basic_html', $formatted->defaultFormat($wide, $definitions['field_libre']), 'sans restriction : le défaut du compte');
    $this->assertSame('basic_html', $formatted->defaultFormat($wide), 'sans champ : le comportement d\'avant');
    // Aucun des formats permis n'est employable : on rend le défaut du compte, et l'écriture se
    // heurtera au 422 d'`itemValue` plutôt qu'à un format inventé.
    $this->assertSame('basic_html', $formatted->defaultFormat($this->user, $definitions['field_riche']));
  }

}
