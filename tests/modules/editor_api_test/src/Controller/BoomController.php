<?php

declare(strict_types=1);

namespace Drupal\editor_api_test\Controller;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sondes : une exception brute, une HTTP 418, une 403.
 */
final class BoomController {

  public function boom(): never {
    throw new \RuntimeException('secret detail');
  }

  public function teapot(): never {
    throw new HttpException(418, "I'm a teapot");
  }

  public function forbidden(): never {
    throw new AccessDeniedHttpException('nope');
  }

}
