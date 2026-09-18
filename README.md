# Inscription B2B

[![Téléchargements](https://img.shields.io/github/downloads/zenmod40/b2bregistration/total.svg)](https://github.com/zenmod40/b2bregistration/releases) [![Version](https://img.shields.io/github/v/release/zenmod40/b2bregistration)](https://github.com/zenmod40/b2bregistration/releases/latest)

> **[Page du module sur zm40.com](https://zm40.com/b2bregistration)** · [Documentation](https://zm40.com/b2bregistration/documentation) · [Changelog](https://zm40.com/b2bregistration/changelog)

Module PrestaShop d'inscription professionnelle (B2B) : champs dédiés, vérification du SIRET et du numéro de TVA intracommunautaire, pré-remplissage automatique, affectation de groupe par pays et workflow de modération.

Compatible PrestaShop 1.7, 8 et 9. Module libre et open source sous licence OSL 3.0, par ZM40.

## Fonctionnalités

- Formulaire d'inscription enrichi pour les professionnels : raison sociale, SIRET, numéro de TVA intracommunautaire, code APE/NAF, site web, téléphone. Chaque champ est activable et peut être rendu obligatoire.
- Option « boutique réservée aux professionnels » : masque l'inscription des particuliers.
- Vérification du SIRET : contrôle de la clé (Luhn) hors-ligne et instantané, puis, en option, vérification live via l'API Sirene de l'INSEE (existence réelle et établissement actif).
- Vérification du numéro de TVA intracommunautaire via le service public européen VIES (gratuit, sans clé).
- Pré-remplissage automatique de la raison sociale, du code APE et de l'adresse à partir du SIRET (INSEE) ou du numéro de TVA (VIES). Les champs pré-remplis restent toujours modifiables.
- Affectation automatique à un ou plusieurs groupes clients selon le pays : pays de la boutique, Union européenne, reste du monde.
- Workflow configurable : approbation automatique ou modération manuelle en back-office, avec e-mails au client (en attente, validé, refusé) et notification aux employés.
- Page de modération dédiée dans le back-office (Clients > Demandes B2B).
- Intégration native opportuniste : le SIRET, la société et le numéro de TVA sont reportés sur la fiche client et les adresses (factures correctes), sans forcer le mode B2B global. Le mode B2B natif PrestaShop reste activable en option.

## Compatibilité

- PrestaShop 1.7.x, 8.x, 9.x
- PHP 7.2 à 8.x
- Multiboutique et multilingue

## Installation

1. Déposer le dossier `b2bregistration` dans le répertoire `modules/` de votre boutique (ou installer le ZIP via le back-office).
2. Installer le module depuis Modules > Gestionnaire de modules.
3. À l'installation, un groupe « Professionnels » est créé et une règle d'affectation par défaut est posée pour le pays de la boutique.

## Configuration

Tout se configure depuis la page du module :

- Champs du formulaire et obligation.
- Activation de la vérification SIRET (Luhn / INSEE) et TVA (VIES).
- Clé API INSEE (compte gratuit sur le portail api.insee.fr) pour la vérification live et le pré-remplissage du SIRET.
- Mode d'approbation : automatique ou modération manuelle.
- Règles d'affectation de groupe par pays (pays de la boutique, UE, monde).
- Activation optionnelle du mode B2B natif PrestaShop.

La modération des demandes se fait dans Clients > Demandes B2B.

## Confidentialité

- Les vérifications SIRET (INSEE) et TVA (VIES) envoient uniquement l'identifiant saisi par le client aux services officiels concernés (api.insee.fr, ec.europa.eu/VIES), le temps de la vérification. Aucune autre donnée de votre boutique n'est transmise.
- Le module vérifie périodiquement (au maximum une fois par jour) si une nouvelle version est disponible via l'API publique de GitHub, et récupère la liste des autres modules ZM40 depuis zm40.com. Ces requêtes sont anonymes : aucune donnée de votre boutique n'est transmise. Vous pouvez tout désactiver dans la configuration du module (interrupteur « réseau »).

## Support et services

Le code est offert. Le support gratuit se limite aux bugs reproductibles (issues GitHub). L'installation, la configuration, l'adaptation à votre thème, le débogage spécifique et les développements sur-mesure sont des prestations : [zm40.com](https://zm40.com).

Une version compatible ThirtyBees / PrestaShop 1.6 peut être étudiée sur demande.

## Contribuer

Les pull requests sont les bienvenues. Merci d'ouvrir une issue avant les changements importants.

## Licence

OSL 3.0 — voir le fichier LICENSE.
