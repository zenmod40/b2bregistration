# Changelog

Toutes les modifications notables de ce module sont documentées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et le module suit le [Versionnement sémantique](https://semver.org/lang/fr/).

## [1.0.4] - 2026-08-24

### Corrigé

- **Les champs professionnels apparaissaient vides sur la page « Mes informations ».** PrestaShop ne remplit le formulaire client qu'avec les propriétés de l'objet Customer ; les champs ajoutés par un module (SIRET, raison sociale, code APE, n° de TVA, site web, téléphone, pays) n'en font pas partie et étaient donc systématiquement rendus vides, alors que les informations étaient bien enregistrées côté demande B2B. Ils sont désormais pré-remplis depuis la demande du client.
- **Les modifications de ces champs sur « Mes informations » n'étaient pas enregistrées.** Le module ne traitait que la création de compte. Les corrections apportées par le client (raison sociale, coordonnées, identifiants) sont maintenant reportées sur sa demande B2B, ainsi que sur les champs natifs du client et de ses adresses. Si le SIRET ou le n° de TVA est modifié après coup, il repasse en « non vérifié » en back-office : la vérification d'origine ne porte plus sur la valeur saisie.
- Le dévoilement progressif des champs (qui n'affiche la suite du formulaire qu'une fois le SIRET validé) ne s'applique plus sur « Mes informations », où il masquait des champs déjà renseignés.

## [1.0.3] - 2026-08-21

### Modifié
- **Licence : GPL v3 vers Open Software License 3.0 (OSL-3.0).** Le cœur de PrestaShop est publié sous OSL-3.0, licence notoirement incompatible avec la GPL quelle que soit sa version. Un module ne pouvant fonctionner sans le cœur, la combinaison des deux ne peut satisfaire les deux copyleft à la fois, ce qui plaçait quiconque redistribue une boutique dans une situation insoluble. L'OSL-3.0 lève l'ambiguïté, aligne le module sur la licence de l'écosystème, et conserve ce qui comptait : l'obligation d'attribution et le partage des modifications. Les versions déjà publiées restent régies par la licence sous laquelle elles ont été distribuées.

## [1.0.2] - 2026-06-29

### Modifié

- La liste des autres modules ZM40 (écosystème) passe dans un **onglet dédié « Modules ZM40 »** de la page de configuration, au lieu d'un bloc en bas de page. L'onglet n'apparaît que si le feed renvoie des modules (fail-silent).

### Supprimé

- Code de diagnostic temporaire (méthode `dbg()`, appels associés, fichier `debug.php`) ajouté pour investiguer un souci d'affichage de modal et qui n'a plus lieu d'être en production.

## [1.0.1] - 2026-06-17

### Corrigé

- Compatibilité PrestaShop 9 : le contrôleur d'administration (Demandes B2B) utilisait la méthode de traduction legacy `l()`, retirée des contrôleurs admin en PrestaShop 9. Ajout d'une couche de compatibilité (traducteur Symfony, repli sur la chaîne source).

### Modifié

- Brique ZM40 Common renommée en classe unique au module (`Zm40CommonB2b`) pour éliminer tout risque de collision de classe avec d'autres modules ZM40 installés.
- Page de configuration réorganisée en onglets (Formulaire, Vérification SIRET / TVA, Workflow, Affectation de groupe, Intégration) avec guide d'obtention de la clé API INSEE.

## [1.0.0] - 2026-06-15

### Ajouté

- Première publication open source.
- Formulaire d'inscription professionnelle : raison sociale, SIRET, TVA intracommunautaire, code APE/NAF, site web, téléphone (champs activables et requis au choix).
- Option boutique réservée aux professionnels.
- Vérification du SIRET : clé de contrôle (Luhn) hors-ligne et, en option, vérification live via l'API Sirene de l'INSEE.
- Vérification du numéro de TVA intracommunautaire via VIES.
- Pré-remplissage automatique (raison sociale, APE, adresse) depuis l'INSEE et VIES, champs toujours modifiables.
- Affectation automatique de groupe par pays (pays de la boutique, UE, monde).
- Workflow configurable : approbation automatique ou modération manuelle, avec e-mails (client et employés).
- Page de modération en back-office (Clients > Demandes B2B).
- Intégration native opportuniste (fiche client et adresses) sans forcer le mode B2B global ; mode B2B natif activable en option.
- Compatibilité PrestaShop 1.7 / 8 / 9, multiboutique et multilingue.
