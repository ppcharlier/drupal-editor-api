<?php

declare(strict_types=1);

namespace Drupal\Tests\editor_api\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Le module s'installe et le harnais PHPUnit fonctionne dans le conteneur.
 *
 * @group editor_api
 */
#[RunTestsInSeparateProcesses]
class ModuleInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'filter', 'node', 'taxonomy', 'path', 'path_alias', 'options', 'datetime', 'file', 'image', 'media', 'editor_api'];

  public function testModuleIsEnabled(): void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('editor_api'));
  }

}
