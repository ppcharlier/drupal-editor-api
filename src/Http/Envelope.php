<?php

declare(strict_types=1);

namespace Drupal\editor_api\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les trois formes de réponse du contrat.
 */
final class Envelope {

  private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

  public static function data(mixed $data, int $status = 200): JsonResponse {
    return self::json(['data' => $data], $status);
  }

  public static function page(array $items, int $total, int $page, int $perPage, array $extraMeta = []): JsonResponse {
    $meta = [
      'total' => $total,
      'current_page' => $page,
      'per_page' => $perPage,
      'last_page' => max(1, (int) ceil($total / $perPage)),
    ] + $extraMeta;
    return self::json(['data' => $items, 'meta' => $meta], 200);
  }

  public static function noContent(): Response {
    return new Response('', 204);
  }

  public static function error(ApiException $e): JsonResponse {
    $error = ['code' => $e->code, 'message' => $e->getMessage()];
    if ($e->errors !== []) {
      $error['errors'] = $e->errors;
    }
    return self::json(['error' => $error], $e->status);
  }

  private static function json(array $payload, int $status): JsonResponse {
    $response = new JsonResponse(NULL, $status);
    $response->setEncodingOptions(self::JSON_FLAGS);
    $response->setData($payload);
    // Jamais de cache : les réponses dépendent du jeton et changent à chaque écriture.
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
