<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Blueprint compact d'un type de contenu : ordre du formulaire, correspondance des types.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class BlueprintTest extends EditorApiKernelTestBase {

  private function buildPlaceType(): void {
    $this->createNodeType('place', 'Place');
    Vocabulary::create(['vid' => 'regions', 'name' => 'Regions'])->save();
    $this->createImageMediaType('image');
    $this->createBasicHtmlFormat();
    $this->createField('place', 'field_kind', 'list_string', ['allowed_values' => ['stay' => 'Place to stay', 'table' => 'Restaurant']], [], 1, 'options_select', 1, TRUE, 'Kind');
    $this->createField('place', 'field_intro', 'text_long', [], [], 1, 'text_textarea', 2, FALSE, 'Introduction');
    $this->createField('place', 'field_highlights', 'string', ['max_length' => 120], [], -1, 'string_textfield', 3);
    $this->createField('place', 'field_amenities', 'list_string', ['allowed_values' => ['wifi' => 'Wi-Fi', 'parking' => 'Parking']], [], -1, 'options_buttons', 4);
    $this->createField('place', 'field_languages', 'list_string', ['allowed_values' => ['fr' => 'French', 'en' => 'English']], [], 3, 'options_select', 5);
    $this->createField('place', 'field_photo', 'entity_reference', ['target_type' => 'media'], ['handler_settings' => ['target_bundles' => ['image' => 'image']]], 1, 'media_library_widget', 6);
    $this->createField('place', 'field_regions', 'entity_reference', ['target_type' => 'taxonomy_term'], ['handler_settings' => ['target_bundles' => ['regions' => 'regions']]], -1, 'entity_reference_autocomplete', 7);
    $this->createField('place', 'field_favourite', 'boolean', [], [], 1, 'boolean_checkbox', 8);
    $this->createField('place', 'field_price_range', 'list_string', ['allowed_values' => ['budget' => 'Budget', 'mid' => 'Mid-range']], [], 1, 'options_buttons', 9);
    $this->createField('place', 'field_capacity', 'integer', [], ['min' => 1, 'max' => 40], 1, 'number', 10);
    $this->createField('place', 'field_visited_on', 'datetime', ['datetime_type' => 'datetime'], [], 1, 'datetime_default', 11);
    $this->createField('place', 'field_author', 'entity_reference', ['target_type' => 'user'], [], 1, 'entity_reference_autocomplete', 12);
    $this->createField('place', 'field_link', 'link', [], [], 1, 'link_default', 13);
    $this->createField('place', 'field_any_term', 'entity_reference', ['target_type' => 'taxonomy_term'], [], -1, 'entity_reference_autocomplete', 14);
    // Un champ hors formulaire n'apparaît pas.
    $this->createField('place', 'field_hidden', 'string', [], [], 1, NULL, 99);
    \Drupal::service('entity_display.repository')->getFormDisplay('node', 'place')->removeComponent('field_hidden')->save();
  }

  public function testBlueprintFollowsFormOrderAndMapsTypes(): void {
    $this->buildPlaceType();
    $user = $this->createEditor(['access editor api', 'use text format basic_html']);
    $response = $this->request('GET', '/api/editor/v1/collections/place/blueprints/place', NULL, $this->bearer($user));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $blueprint = $this->decode($response)['data'];

    $this->assertSame('place', $blueprint['handle']);
    $this->assertSame('Place', $blueprint['title']);
    $this->assertCount(1, $blueprint['tabs']);
    $fields = array_column($blueprint['tabs'][0]['fields'], NULL, 'handle');
    $this->assertSame([
      'title', 'field_kind', 'field_intro', 'field_highlights', 'field_amenities', 'field_languages', 'field_photo', 'field_regions',
      'field_favourite', 'field_price_range', 'field_capacity', 'field_visited_on', 'field_author', 'field_link', 'field_any_term', 'slug', 'date',
    ], array_keys($fields));

    $this->assertSame(['handle' => 'title', 'type' => 'text', 'display' => 'Title', 'instructions' => NULL, 'required' => TRUE, 'rules' => ['required', 'max:255'], 'meta' => FALSE, 'config' => ['character_limit' => 255]], $fields['title']);
    $this->assertSame('select', $fields['field_kind']['type']);
    $this->assertSame([['value' => 'stay', 'label' => 'Place to stay'], ['value' => 'table', 'label' => 'Restaurant']], $fields['field_kind']['config']['options']);
    $this->assertTrue($fields['field_kind']['required']);
    $this->assertSame(['required'], $fields['field_kind']['rules']);

    $this->assertSame('html', $fields['field_intro']['type']);
    $this->assertSame('basic_html', $fields['field_intro']['config']['format']);
    $this->assertSame(['tag' => 'a', 'attributes' => ['href', 'hreflang']], $fields['field_intro']['config']['allowed_html'][0]);
    $this->assertSame(['tag' => 'p', 'attributes' => []], $fields['field_intro']['config']['allowed_html'][3]);

    $this->assertSame('list', $fields['field_highlights']['type']);
    $this->assertSame('checkboxes', $fields['field_amenities']['type']);
    $this->assertSame(['options' => [['value' => 'fr', 'label' => 'French'], ['value' => 'en', 'label' => 'English']], 'multiple' => TRUE], $fields['field_languages']['config']);
    $this->assertSame('select', $fields['field_languages']['type']);
    $this->assertSame(['type' => 'assets', 'config' => ['container' => 'image', 'max_files' => 1]], ['type' => $fields['field_photo']['type'], 'config' => $fields['field_photo']['config']]);
    $this->assertSame(['type' => 'terms', 'config' => ['taxonomies' => ['regions']]], ['type' => $fields['field_regions']['type'], 'config' => $fields['field_regions']['config']]);
    $this->assertSame('toggle', $fields['field_favourite']['type']);
    $this->assertSame('button_group', $fields['field_price_range']['type']);
    $this->assertSame(['type' => 'integer', 'config' => ['min' => 1, 'max' => 40]], ['type' => $fields['field_capacity']['type'], 'config' => $fields['field_capacity']['config']]);
    $this->assertSame(['type' => 'date', 'config' => ['time_enabled' => TRUE]], ['type' => $fields['field_visited_on']['type'], 'config' => $fields['field_visited_on']['config']]);
    $this->assertSame(['type' => 'users', 'config' => ['max_items' => 1]], ['type' => $fields['field_author']['type'], 'config' => $fields['field_author']['config']]);
    $this->assertSame(['type' => 'link', 'config' => []], ['type' => $fields['field_link']['type'], 'config' => $fields['field_link']['config']]);
    $this->assertSame(['type' => 'terms', 'config' => ['taxonomies' => []]], ['type' => $fields['field_any_term']['type'], 'config' => $fields['field_any_term']['config']]);

    $this->assertSame(['handle' => 'slug', 'type' => 'slug', 'display' => 'Slug', 'instructions' => NULL, 'required' => TRUE, 'rules' => ['required'], 'meta' => TRUE, 'config' => []], $fields['slug']);
    $this->assertSame(['handle' => 'date', 'type' => 'date', 'display' => 'Date', 'instructions' => NULL, 'required' => FALSE, 'rules' => [], 'meta' => TRUE, 'config' => ['time_enabled' => FALSE]], $fields['date']);
  }

  public function testIndexListsTheSingleBlueprintAndUnknownHandlesAre404(): void {
    $this->createNodeType('page');
    $user = $this->createEditor();
    $list = $this->decode($this->request('GET', '/api/editor/v1/collections/page/blueprints', NULL, $this->bearer($user)))['data'];
    $this->assertCount(1, $list);
    $this->assertSame('page', $list[0]['handle']);
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/collections/page/blueprints/other', NULL, $this->bearer($user))->getStatusCode());
    $this->assertSame(404, $this->request('GET', '/api/editor/v1/collections/nope/blueprints', NULL, $this->bearer($user))->getStatusCode());
  }

  public function testDefaultFormatFallsBackToPlainTextWithoutPermission(): void {
    $this->createNodeType('page');
    $this->createBasicHtmlFormat();
    $this->createField('page', 'body', 'text_with_summary', [], [], 1, 'text_textarea_with_summary', 1, FALSE, 'Body');
    $user = $this->createEditor(['access editor api']);
    $fields = array_column($this->decode($this->request('GET', '/api/editor/v1/collections/page/blueprints/page', NULL, $this->bearer($user)))['data']['tabs'][0]['fields'], NULL, 'handle');
    $this->assertSame('plain_text', $fields['body']['config']['format']);
    $this->assertSame([], $fields['body']['config']['allowed_html']);
  }

  /**
   * Le `format` d'un champ `html` est ce qu'un NOUVEL item recevra, et il respecte les
   * `allowed_formats` du champ — un champ restreint à `full_html` recevait `basic_html`.
   */
  public function testTheHtmlFieldFormatHonoursTheFieldsAllowedFormats(): void {
    $this->createNodeType('page');
    $this->createBasicHtmlFormat();
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML', 'weight' => 1])->save();
    $this->createField('page', 'field_riche', 'text_long', [], ['allowed_formats' => ['full_html']]);
    $wide = $this->createEditor(['access editor api', 'use text format basic_html', 'use text format full_html'], 'wide@example.com');

    $fields = array_column($this->decode($this->request('GET', '/api/editor/v1/collections/page/blueprints/page', NULL, $this->bearer($wide)))['data']['tabs'][0]['fields'], NULL, 'handle');
    $this->assertSame('full_html', $fields['field_riche']['config']['format']);
    $this->assertSame([], $fields['field_riche']['config']['allowed_html'], 'full_html n\'a pas de filtre_html');
  }

}
