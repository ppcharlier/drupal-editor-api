<?php

declare(strict_types=1);

namespace Drupal\editor_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\FileExistsException;
use Drupal\Core\File\FileExists;
use Drupal\editor_api\Entry\EntityValidation;
use Drupal\editor_api\Http\ApiException;
use Drupal\editor_api\Http\Envelope;
use Drupal\editor_api\Http\RequestBody;
use Drupal\editor_api\Media\AssetUploader;
use Drupal\editor_api\Media\MediaLoader;
use Drupal\editor_api\Payload\AssetPayload;
use Drupal\editor_api\Query\MediaQuery;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assets : liste et détail (tâche 3) ; upload, mise à jour, suppression (tâche 4).
 */
final class AssetsController extends ControllerBase {

  public function __construct(
    private readonly MediaLoader $loader,
    private readonly MediaQuery $query,
    private readonly AssetPayload $payload,
    private readonly AssetUploader $uploader,
    private readonly FileRepositoryInterface $files,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('editor_api.media_loader'),
      $container->get('editor_api.media_query'),
      $container->get('editor_api.asset_payload'),
      $container->get('editor_api.asset_uploader'),
      $container->get('file.repository'),
      $container->get('logger.channel.editor_api'),
    );
  }

  /**
   * La racine s'écrit indifféremment `""` ou `"/"` : le client iOS envoie
   * toujours `folder=/` pour le dossier racine. Seul un reste non vide après
   * suppression des slashes désigne un vrai sous-dossier, non supporté ici.
   */
  private static function folder(string $value): string {
    return trim($value, '/');
  }

  public function index(Request $request, string $container): JsonResponse {
    $this->loader->container($container);
    $errors = [];
    if (self::folder((string) $request->query->get('folder', '')) !== '') {
      $errors['folder'] = ['Folders are not supported by this container.'];
    }
    $page = (int) $request->query->get('page', 1);
    $perPage = (int) $request->query->get('per_page', 25);
    if ($page < 1) {
      $errors['page'] = ['The page must be at least 1.'];
    }
    if ($perPage < 1 || $perPage > 100) {
      $errors['per_page'] = ['The per_page must be between 1 and 100.'];
    }
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    $result = $this->query->run($this->currentUser(), $container, $page, $perPage);
    $assets = array_map(fn(MediaInterface $media) => $this->payload->summary($media, $this->currentUser()), $result['items']);
    return Envelope::page(['assets' => $assets, 'folders' => []], $result['total'], $page, $perPage, ['folders_total' => 0, 'folders_last_page' => 1]);
  }

  public function show(string $container, string $mid, string $basename): JsonResponse {
    // La route déclare `{mid}` et `{basename}` séparément (voir
    // editor_api.routing.yml) ; `path` du contrat reste `{mid}/{basename}`.
    $media = $this->loader->load($container, $mid . '/' . $basename);
    if (!$media->access('view')) {
      throw ApiException::forbidden('view');
    }
    return Envelope::data($this->payload->summary($media, $this->currentUser()));
  }

  public function store(Request $request, string $container): JsonResponse {
    $type = $this->loader->container($container);
    if (!$this->entityTypeManager()->getAccessControlHandler('media')->createAccess($type->id())) {
      throw ApiException::forbidden('upload');
    }
    $errors = [];
    if (self::folder((string) $request->request->get('folder', '')) !== '') {
      $errors['folder'] = ['Folders are not supported by this container.'];
    }
    $upload = $request->files->get('file');
    if (!$upload instanceof UploadedFile) {
      $errors['file'] = ['The file field is required.'];
    }
    elseif (!$upload->isValid()) {
      $errors['file'] = [$upload->getErrorMessage()];
    }
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    $media = $this->uploader->upload($type, new FormUploadedFile($upload));
    return Envelope::data($this->payload->summary($media, $this->currentUser()), 201);
  }

  public function update(Request $request, string $container, string $mid, string $basename): JsonResponse {
    // Mêmes deux segments littéraux que `show()` (voir editor_api.routing.yml).
    $media = $this->loader->load($container, $mid . '/' . $basename);
    // Un média que le compte ne peut pas voir n'existe pas pour lui : le refus
    // précède toute lecture du corps, sinon un corps vide ou tout en `null`
    // traverserait la méthode sans jamais rencontrer de contrôle d'accès.
    if (!$media->access('view')) {
      throw ApiException::forbidden('view');
    }
    $type = $this->loader->typeOf($media);
    $body = RequestBody::json($request);
    // Un `null` explicite vaut absence : `{"data": null}` ne demande rien et
    // ne doit ni contourner les contrôles d'accès ni passer pour une écriture.
    foreach (['filename', 'folder', 'data'] as $key) {
      if (array_key_exists($key, $body) && $body[$key] === NULL) {
        unset($body[$key]);
      }
    }
    $errors = [];
    if (!array_key_exists('filename', $body) && !array_key_exists('folder', $body) && !array_key_exists('data', $body)) {
      $errors['filename'] = ['Send at least one of filename, folder, data.'];
    }
    if (array_key_exists('folder', $body)) {
      $errors['folder'] = ['Folders are not supported by this container.'];
    }
    $filename = $body['filename'] ?? NULL;
    if ($filename !== NULL && (!is_string($filename) || $filename === '' || str_contains($filename, '..') || preg_match('#[/\\\\\x00]#', $filename))) {
      $errors['filename'] = ['The filename may not contain slashes, backslashes or "..".'];
    }
    elseif (is_string($filename) && mb_strlen($filename) > 200) {
      $errors['filename'] = ['The filename may not be greater than 200 characters.'];
    }
    $data = $body['data'] ?? NULL;
    if ($data !== NULL && !is_array($data)) {
      $errors['data'] = ['The data field must be an object.'];
    }
    if ($errors !== []) {
      throw ApiException::validation($errors);
    }
    // Les permissions passent AVANT le contrôle des noms de champs de `data` :
    // un compte non autorisé reçoit un 403 sans jamais apprendre quels noms
    // de champs sont valides.
    if (array_key_exists('filename', $body) && !$media->access('update')) {
      throw ApiException::forbidden('rename');
    }
    if (array_key_exists('data', $body) && !$media->access('update')) {
      throw ApiException::forbidden('edit');
    }
    $allowed = $type->getSource()->getPluginId() === 'image' ? ['alt', 'title'] : ['description'];
    foreach (array_keys($data ?? []) as $key) {
      if (!in_array($key, $allowed, TRUE)) {
        throw ApiException::unknownField((string) $key);
      }
    }
    if ($data !== NULL) {
      $item = $media->get($this->loader->sourceFieldName($type))->first();
      foreach ($data as $key => $value) {
        $item->set($key, is_scalar($value) || $value === NULL ? (string) $value : '');
      }
    }
    // Les mêmes contraintes que la création (ex. `alt` requis par le champ
    // image) s'appliquent à une mise à jour : validation avant écriture. Elle
    // doit aussi précéder le renommage du fichier ci-dessous : aucune
    // écriture irréversible avant la validation.
    EntityValidation::assert($media);
    if ($filename !== NULL) {
      $file = $this->loader->sourceFile($media);
      $extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
      $target = dirname($file->getFileUri()) . '/' . $filename . ($extension !== '' ? '.' . $extension : '');
      try {
        $moved = $this->files->move($file, $target, FileExists::Error);
      }
      catch (FileExistsException) {
        throw ApiException::validation(['filename' => ['A file with this name already exists.']]);
      }
      catch (FileException $e) {
        // Le message du cœur décrit le système de fichiers (chemins réels,
        // permissions) : on le journalise et on rend au client un message fixe.
        $this->logger->error('Renaming the file of media @mid failed: @message', [
          '@mid' => (string) $media->id(),
          '@message' => $e->getMessage(),
        ]);
        throw ApiException::validation(['filename' => ['The file could not be renamed.']]);
      }
      // `FileRepository::move()` avec `FileExists::Error` ne renomme le
      // champ `filename` que dans les cas Rename/Replace (voir sa source) :
      // il déplace l'URI mais laisse l'ancien nom en base. On le corrige ici
      // à partir de la cible, qui porte déjà le nouveau nom.
      $moved->setFilename(basename($target));
      $moved->save();
      // `move()` clone l'entité source : `$file` en mémoire (et la référence
      // mise en cache par le champ) reste l'ancien objet, jamais mis à jour.
      // On réinjecte explicitement l'entité déplacée dans le champ pour que
      // la réponse (via `sourceFile()`) porte le nouveau nom, pas l'ancien.
      $media->get($this->loader->sourceFieldName($type))->entity = $moved;
      $media->setName($moved->getFilename());
    }
    $media->save();
    return Envelope::data($this->payload->summary($media, $this->currentUser()));
  }

  public function destroy(string $container, string $mid, string $basename): Response {
    // Mêmes deux segments littéraux que `show()` (voir editor_api.routing.yml).
    $media = $this->loader->load($container, $mid . '/' . $basename);
    if (!$media->access('delete')) {
      throw ApiException::forbidden('delete');
    }
    $file = $this->loader->sourceFile($media);
    $media->delete();
    $file?->delete();
    return Envelope::noContent();
  }

}
