# Changelog

Toutes les modifications notables de ce module sont documentées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et le module suit le [Versionnement sémantique](https://semver.org/lang/fr/).

## [1.1.1] - 2026-09-30

### Sécurité

- **Correctif de sécurité, mise à jour recommandée.** Le SIRET « obligatoire » n'était imposé que dans le navigateur, et le refus automatique des identifiants invalides ne s'appliquait pas à un SIRET absent : un visiteur pouvait obtenir le groupe professionnel, et ses tarifs, sans identifiant valide. Le SIRET obligatoire est désormais contrôlé sur le serveur, un SIRET absent compte comme invalide, et le pays soumis doit exister et être actif. Le détail sera publié ultérieurement dans une note de sécurité.
- **Validation automatique plus stricte.** Sans modération, une demande n'est validée automatiquement que si l'entreprise est confirmée par l'INSEE (SIRET) ou par VIES (TVA). Un SIRET qui passe seulement la clé de contrôle ne prouve pas l'existence de l'entreprise : la demande part en modération. Un service INSEE ou VIES injoignable met aussi la demande en attente au lieu de la valider.
- **Droits des employés.** Valider ou refuser une demande exige le droit de modification sur l'onglet. Un profil en lecture seule pouvait jusqu'ici le faire.
- **Limite des vérifications.** En plus de la limite par visiteur, un plafond global de 30 vérifications SIRET/TVA par minute (quota de l'API Sirene) couvre la vérification en direct et l'inscription ; au-delà, aucune requête n'est envoyée et la demande part en modération.

### Ajouté

- **Boutons Approuver et Refuser sur la fiche d'une demande.** Ils n'existaient que dans la liste. Approuver est proposé tant que la demande est en attente, Refuser tant qu'elle n'est pas déjà refusée (avec confirmation) ; les deux exigent le droit de modification.
- **Vérifications en clair sur la fiche.** Le résultat des contrôles SIRET et TVA s'affichait en données brutes ; il est désormais rédigé en phrases (« Entreprise vérifiée auprès de l'INSEE : … », « Format valide, mais l'existence de l'entreprise n'a pas été vérifiée… »), avec un lien vers l'Annuaire des entreprises quand l'entreprise n'est pas confirmée. Le statut s'affiche avec le même badge que dans la liste.

## [1.1.0] - 2026-09-27

### Ajouté

- **Modération appelable hors back-office.** Valider, refuser et revérifier une demande passent par une classe publique, `B2bWorkflow`, utilisée par le back-office comme par l'application de gestion Régie : mêmes e-mails, mêmes groupes, quel que soit le point d'entrée.
- **Revérification SIRET / TVA.** Un service INSEE ou VIES injoignable à l'inscription laissait la demande en « non vérifié » pour toujours. Les vérifications activées en configuration peuvent désormais être relancées ; un service encore injoignable laisse le résultat précédent en place.
- **Historique des demandes.** Création, validation, refus, revérification et modification des informations par le client sont consignés : qui, quand, depuis où (back-office, boutique, Régie), avec le motif d'un refus. La table est créée à la mise à jour ; les demandes existantes ne sont pas modifiées.

### Corrigé

- **Refuser un compte déjà validé lui laissait ses prix professionnels.** Le statut passait à « refusé » mais le client restait dans le groupe pro, qui restait son groupe par défaut. Le refus retire désormais les groupes ajoutés par la validation et rend au client son groupe par défaut d'avant, ou le groupe « Client » de la boutique. Pour un compte validé avant cette version, ce sont les groupes que le module affecte à sa zone qui sont retirés.
- **Double validation.** Un double clic, ou un formulaire resté ouvert, renvoyait l'e-mail d'activation ; une demande refusée pouvait aussi être validée sans contrôle. Les transitions sont maintenant vérifiées en base : une demande déjà dans l'état demandé n'est pas modifiée et aucun e-mail ne part. Valider une demande dont le compte client a été supprimé (refus automatique) est refusé avec un message clair, au lieu d'un échec muet.
- **La page de CGV professionnelles n'était jamais proposée.** La configuration enregistrait la page choisie sous une clé et l'inscription la lisait sous une autre : la case à cocher des CGV n'apparaissait pas. Elle apparaît désormais côté Pro dès qu'une page est choisie.
- **Refus automatique à l'inscription : page d'erreur sur PrestaShop 1.7.** Quand le refus automatique des identifiants invalides est activé, le module supprime le compte pendant l'inscription ; PrestaShop 1.7 poursuivait avec ce client disparu et s'arrêtait sur une erreur. Le client est désormais déconnecté aussitôt son compte supprimé, et l'inscription se termine normalement.
- **Mail « Bienvenue » envoyé à un compte refusé automatiquement.** PrestaShop envoie ce mail avant de passer la main au module : le blocage prévu arrivait trop tard, et le client recevait « compte créé » en même temps que « demande refusée ». Le refus est maintenant décidé dès l'envoi du mail, qui ne part plus.
- Le back-office signale quand aucun groupe professionnel n'a pu être affecté (zone désactivée ou sans groupe), quand les groupes n'ont pas pu être retirés, et quand l'e-mail au client n'est pas parti, au lieu de confirmer en silence.

## [1.0.6] - 2026-09-04

### Corrigé

- **Une règle d'affectation pointant vers un groupe supprimé envoyait le client validé dans le vide.** Les règles conservent l'identifiant du groupe choisi ; si ce groupe est supprimé ensuite, la règle subsiste et reste comptée comme valide. Le repli sur le groupe par défaut ne se déclenchant qu'en l'absence totale de règle, il ne jouait pas : le client sortait de la validation affecté à un groupe inexistant, donc sans tarifs professionnels, et rien ne le signalait. Les règles dont le groupe n'existe plus sont désormais ignorées, ce qui rend au groupe par défaut son rôle de filet. La sélection affichée en configuration est filtrée de la même façon.

## [1.0.5] - 2026-08-29

### Ajouté

- **Mentions de TVA sur la facture, selon le régime réellement appliqué à la commande.** Une vente facturée sans TVA doit indiquer le fondement de l'exonération, et ce fondement diffère selon les cas : livraison intracommunautaire de biens, prestation de services intracommunautaire, exportation hors Union européenne, territoire hors champ d'application de la TVA. PrestaShop ne propose qu'un texte libre unique, identique pour toutes les factures, ce qui obligeait à traiter ces cas à la main. Un onglet « Mentions sur facture » permet désormais de saisir un texte par cas, dans chaque langue de la boutique ; le module ajoute celui qui correspond, et seulement si la facture ne porte aucune TVA.
- Le cas est déduit de la commande — pays de taxation et TVA appliquée — et non de la fiche client au moment où la facture est rééditée : modifier ou invalider un numéro de TVA ne réécrit pas une facture déjà émise.
- Les champs sont vides par défaut et le restent tant que le marchand ne les remplit pas. La formulation usuelle de chaque cas est proposée en aide de saisie, mais n'est jamais écrite automatiquement : ces textes engagent le marchand et supposent une boutique française.
- Le texte libre de facture configuré dans PrestaShop reste utilisé ; la mention s'y ajoute au lieu de le remplacer. Il reste l'endroit approprié pour ce qui ne dépend pas du client, comme la franchise en base ou l'autoliquidation en sous-traitance.

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
