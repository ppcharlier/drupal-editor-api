<?php

declare(strict_types=1);

namespace Drupal\editor_api\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sous /api/editor/, toute exception devient une envelope d'erreur du contrat.
 *
 * Priorité au-dessus des gestionnaires HTML et JSON du cœur, pour qu'aucune
 * page d'erreur Drupal ne parte vers l'app. Le message d'une exception
 * imprévue n'est jamais renvoyé : il est journalisé.
 */
final class ErrorEnvelopeSubscriber implements EventSubscriberInterface {

  public const PREFIX = '/api/editor/';

  public function __construct(private readonly LoggerInterface $logger) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::EXCEPTION => ['onException', 200]];
  }

  public function onException(ExceptionEvent $event): void {
    // Chemin décodé, comme dans TokenAuth::applies() : `/api/editor/v%31/…` est
    // sous le préfixe et doit recevoir une envelope, pas une page d'erreur Drupal.
    if (!str_starts_with(rawurldecode($event->getRequest()->getPathInfo()), self::PREFIX)) {
      return;
    }
    $exception = $event->getThrowable();
    $api = match (TRUE) {
      $exception instanceof ApiException => $exception,
      $exception instanceof HttpExceptionInterface => self::fromHttp($exception),
      default => $this->fromUnexpected($exception),
    };
    $response = Envelope::error($api);
    if ($exception instanceof HttpExceptionInterface) {
      $response->headers->add($exception->getHeaders());
    }
    $event->setResponse($response);
  }

  private static function fromHttp(HttpExceptionInterface $e): ApiException {
    return match ($e->getStatusCode()) {
      401 => ApiException::unauthenticated(),
      403 => ApiException::forbidden('access'),
      404 => ApiException::notFound(),
      429 => ApiException::rateLimited(),
      default => ApiException::of($e->getStatusCode(), 'http_error', $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error.'),
    };
  }

  private function fromUnexpected(\Throwable $e): ApiException {
    $this->logger->error('Editor API: @class: @message in @file:@line', [
      '@class' => $e::class, '@message' => $e->getMessage(), '@file' => $e->getFile(), '@line' => $e->getLine(),
    ]);
    return ApiException::of(500, 'server_error', 'Unexpected server error.');
  }

}
