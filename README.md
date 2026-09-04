# editor_api — module Drupal pour « Editor for Drupal »

Dépôt créé le 2026-09-04, encore vide : le module exposera le contrat
`/api/editor/v1` de l'addon Statamic `ppcharlier/statamic-editor-api` pour
l'app iOS « Editor for Drupal ». Sa spec reste à écrire (correspondance
collections ↔ types de contenu, entrées ↔ nodes, blueprints ↔ Field API,
termes ↔ taxonomie, assets ↔ media, révisions ↔ révisions cœur, conversion
HTML CKEditor ↔ JSON ProseMirror, jetons d'accès).

Bac à sable : `../editor-demo` (Drupal 11, port 8791) monte ce dossier dans
`web/modules/custom/editor_api`.
