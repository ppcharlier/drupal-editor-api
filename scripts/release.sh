#!/usr/bin/env bash
# Prépare les archives d'une version du module telles que drupal.org les attend :
#   scripts/release.sh 1.0.0-alpha1
# produit dist/editor_api-1.0.0-alpha1.tar.gz et .zip depuis HEAD, avec le bloc
# `version`/`project`/`datestamp` que l'empaqueteur de drupal.org ajoute à editor_api.info.yml.
#
# Pourquoi un script plutôt que l'empaqueteur de drupal.org : le dépôt est privé (GitHub), la
# fiche drupal.org n'existe pas encore, et la version doit pourtant être lisible par Drupal
# (`version:` de l'info.yml) et par l'app (page « À propos », rapports d'état).
#
# Seules des versions ALPHA sont acceptées pour l'instant : le contrat /api/editor/v1 est
# stable, le module ne l'est pas encore (Statamic reste la référence). Élargir la regex quand
# une bêta sera décidée — délibérément, pas par oubli.
set -euo pipefail

usage() {
  echo "usage : scripts/release.sh <version>   (ex. 1.0.0-alpha1 — versions alpha seulement)" >&2
  exit 2
}

version="${1:-}"
[[ -n "$version" ]] || usage
if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+-alpha[0-9]+$ ]]; then
  echo "refusé : « $version » — seules des versions alpha (N.N.N-alphaN) sont publiées pour l'instant." >&2
  exit 1
fi

root="$(git rev-parse --show-toplevel)"
cd "$root"
if [[ -n "$(git status --porcelain)" ]]; then
  echo "refusé : l'arbre de travail n'est pas propre — commite ou écarte d'abord." >&2
  exit 1
fi

project="editor_api"
name="${project}-${version}"
dist="$root/dist"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$dist"

# `git archive` applique les `export-ignore` de .gitattributes : notes de conception, captures
# et le fichier .gitattributes lui-même restent hors de l'archive, comme sur drupal.org.
git archive --format=tar --prefix="${project}/" HEAD | tar -x -C "$work"

info="$work/${project}/${project}.info.yml"
[[ -f "$info" ]] || { echo "introuvable : $info" >&2; exit 1; }
if grep -q '^version:' "$info"; then
  echo "refusé : $project.info.yml porte déjà une ligne version:." >&2
  exit 1
fi
now="$(date -u +%s)"
day="$(date -u +%Y-%m-%d)"
cat >> "$info" <<INFO

# Information added by Drupal.org packaging script on ${day}
version: ${version}
project: ${project}
datestamp: ${now}
INFO

(cd "$work" && tar -czf "$dist/${name}.tar.gz" "${project}")
(cd "$work" && rm -f "$dist/${name}.zip" && zip -qr "$dist/${name}.zip" "${project}")

# Le script vérifie son propre résultat : la version est lisible dans l'archive, et rien de ce
# qui doit rester privé n'y est.
tar -xzOf "$dist/${name}.tar.gz" "${project}/${project}.info.yml" | grep -q "^version: ${version}\$" \
  || { echo "échec : version absente de l'info.yml archivé" >&2; exit 1; }
if tar -tzf "$dist/${name}.tar.gz" | grep -qE '^editor_api/(docs/superpowers|\.superpowers|\.gitattributes)'; then
  echo "échec : des fichiers privés sont dans l'archive" >&2
  exit 1
fi

echo "archives prêtes :"
shasum -a 256 "$dist/${name}.tar.gz" "$dist/${name}.zip"
echo
echo "tag à poser quand la version est décidée (non fait par ce script) :"
echo "  git tag -a ${version} -m 'editor_api ${version}' && git push origin ${version}"
