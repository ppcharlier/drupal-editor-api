<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * La date de l'entrée (`date`, `Y-m-d` top-level) est lue dans le FUSEAU DU SITE, et renvoyer le
 * jour courant ne touche pas à `created` (correctif du 2026-09-05). Avant : `Y-m-d` lu comme
 * minuit UTC, donc chaque enregistrement de l'app — qui renvoie toujours la date — reposait
 * `created` à minuit et, pour un site à l'ouest d'UTC, reculait d'un jour.
 */
#[RunTestsInSeparateProcesses]
class EntryDateTest extends EditorApiKernelTestBase {

  private \Drupal\user\UserInterface $user;

  protected function setUp(): void {
    parent::setUp();
    $this->config('system.date')->set('timezone.default', 'Europe/Brussels')->save();
    date_default_timezone_set('Europe/Brussels');
    $this->createNodeType('page');
    $this->createField('page', 'field_when', 'datetime', ['datetime_type' => 'datetime'], [], 1, 'datetime_default', 1);
    $this->createField('page', 'field_day', 'datetime', ['datetime_type' => 'date'], [], 1, 'datetime_default', 2);
    $this->user = $this->createEditor(['access editor api', 'access content', 'create page content', 'edit any page content', 'view own unpublished content']);
  }

  /**
   * Un champ date : une chaîne SANS décalage est une heure murale du site (l'app « définit »
   * un champ vide en `Y-m-d H:i` de l'appareil), une chaîne avec décalage ou `Z` un instant.
   */
  public function testDateFieldWithoutOffsetIsReadInTheSiteTimezone(): void {
    $cases = [
      '2026-06-15 19:30' => '2026-06-15T17:30:00+00:00',
      '2026-06-15T19:30:00+02:00' => '2026-06-15T17:30:00+00:00',
      '2026-06-15T17:30:00Z' => '2026-06-15T17:30:00+00:00',
      '2026-06-15T17:30:00+00:00' => '2026-06-15T17:30:00+00:00',
    ];
    foreach ($cases as $input => $expected) {
      $response = $this->request('POST', '/api/editor/v1/collections/page/entries', ['slug' => 'd-' . md5($input), 'data' => ['title' => 'D', 'field_when' => $input, 'field_day' => '2026-06-15']], $this->bearer($this->user));
      $this->assertSame(201, $response->getStatusCode(), $input . ' → ' . $response->getContent());
      $data = $this->decode($response)['data']['data'];
      $this->assertSame($expected, $data['field_when'], $input);
      $this->assertStringStartsWith('2026-06-15', $data['field_day'], $input);
    }
  }

  /**
   * Un node créé le 5 septembre à 00:30 à Bruxelles (22:30 UTC la veille).
   */
  private function nodeAtHalfPastMidnight(): Node {
    $node = Node::create(['type' => 'page', 'title' => 'Nuit', 'uid' => $this->user->id(), 'created' => 1788561000]);
    $node->save();
    return $node;
  }

  private function patch(Node $node, array $body): \Symfony\Component\HttpFoundation\Response {
    return $this->request('PATCH', '/api/editor/v1/entries/' . $node->id(), $body, $this->bearer($this->user));
  }

  public function testSendingBackTheSameLocalDayLeavesCreatedUntouched(): void {
    $node = $this->nodeAtHalfPastMidnight();
    $response = $this->patch($node, ['date' => '2026-09-05', 'data' => ['title' => 'Nuit']]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(1788561000, (int) Node::load((int) $node->id())->getCreatedTime());
    $this->assertSame('2026-09-04T22:30:00+00:00', $this->decode($response)['data']['date']);
  }

  public function testChangingTheDayKeepsTheTimeOfDayInTheSiteTimezone(): void {
    $node = $this->nodeAtHalfPastMidnight();
    $response = $this->patch($node, ['date' => '2026-09-10', 'data' => ['title' => 'Nuit']]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    // 10 septembre 00:30 à Bruxelles = 9 septembre 22:30 UTC.
    $this->assertSame('2026-09-09T22:30:00+00:00', $this->decode($response)['data']['date']);
  }

  public function testCreateWithDateIsMidnightInTheSiteTimezone(): void {
    $response = $this->request('POST', '/api/editor/v1/collections/page/entries', ['slug' => 'jour', 'date' => '2026-09-05', 'data' => ['title' => 'Jour']], $this->bearer($this->user));
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    // Minuit à Bruxelles (été) = 22:00 UTC la veille.
    $this->assertSame('2026-09-04T22:00:00+00:00', $this->decode($response)['data']['date']);
  }

  public function testInvalidDateStillFails(): void {
    $node = $this->nodeAtHalfPastMidnight();
    $response = $this->patch($node, ['date' => '05/09/2026', 'data' => ['title' => 'Nuit']]);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertArrayHasKey('date', $this->decode($response)['error']['errors']);
  }

}
