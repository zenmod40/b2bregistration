/**
 * Inscription B2B - Front : bascule B2B/B2C, vérification live SIRET/TVA,
 * pré-remplissage NON destructif (ne remplit que les champs vides, jamais de
 * readonly — le client peut toujours corriger une adresse obsolète).
 *
 * @license GPL-3.0-or-later
 */
(function () {
    'use strict';

    if (typeof b2rConfig === 'undefined') {
        return;
    }

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function field(name) { return document.querySelector('[name="' + name + '"]'); }

    var form = null;
    // Ordre UX : SIRET en premier (il auto-remplit le reste), puis Raison sociale,
    // APE, TVA, Site web, Téléphone, Pays. Cet ordre est utilisé pour réordonner
    // les champs dans le DOM après leur rendu par PrestaShop.
    var proInputs = ['b2r_siret', 'b2r_company', 'b2r_ape', 'b2r_vat', 'b2r_website', 'b2r_phone', 'b2r_country'];

    // État de la dernière validation SIRET (utilisé pour bloquer le submit + gate progressif).
    // 'idle' : pas saisi / trop court
    // 'loading' : XHR en cours
    // 'valid' : INSEE/Luhn OK
    // 'invalid' : INSEE/Luhn KO ou SIREN au lieu de SIRET
    // 'unreachable' : INSEE injoignable (on laisse passer, modération côté admin)
    var siretState = 'idle';
    // Mode "reveal progressif" : on n'affiche que le champ SIRET tant qu'il n'est pas validé.
    // Activé seulement si la vérif INSEE est disponible (sinon on ne peut pas gate).
    var progressiveReveal = !!(b2rConfig.enableSiret && b2rConfig.enableInsee);

    function getProBlockNodes() {
        var nodes = [];
        proInputs.forEach(function (n) {
            var el = field(n);
            if (el) {
                var group = el.closest('.form-group') || el.parentNode;
                if (group) { nodes.push(group); }
            }
        });
        var upload = document.querySelector('[data-b2r-upload]');
        if (upload) { nodes.push(upload); }
        return nodes;
    }

    function setProVisible(visible) {
        // Masquer/afficher le wrapper entier (sinon le titre "Informations
        // professionnelles" via .b2r-pro-fields::before resterait visible).
        var wrapper = document.querySelector('.b2r-pro-fields');
        if (wrapper) {
            wrapper.style.display = visible ? '' : 'none';
        }
        // Filet : on toggle aussi chaque form-group au cas où le wrapper
        // n'aurait pas été créé (thème custom où la replace JS a échoué).
        getProBlockNodes().forEach(function (node) {
            node.style.display = visible ? '' : 'none';
        });
        // Active l'enctype multipart si l'upload de pièce est présent.
        if (visible && form && document.querySelector('[data-b2r-upload]')) {
            form.setAttribute('enctype', 'multipart/form-data');
        }
    }

    // Remplit un champ uniquement s'il est vide, et le laisse modifiable.
    // Marque la valeur auto-remplie pour suppression des contrôles inutiles
    // (ex: pas de check VIES sur une TVA qu'on vient de calculer nous-même).
    function fillIfEmpty(name, value) {
        if (!value) { return; }
        var el = field(name);
        if (el && el.value.trim() === '') {
            el.value = value;
            el.setAttribute('data-b2r-autofilled', '1');
            el.setAttribute('data-b2r-autofilled-value', value);
            markAutofilled(el);
        }
    }

    function markAutofilled(el) {
        el.classList.add('b2r-autofilled');
        // Pas de texte "pré-rempli, modifiable" : le highlight visuel (fond vert)
        // suffit. Ajouter un libellé inviterait à modifier sans nécessité.
        // Si l'utilisateur édite, on retire la marque visuelle.
        el.addEventListener('input', function () {
            el.classList.remove('b2r-autofilled');
        }, { once: true });
    }

    function setStatus(el, state, message) {
        if (!el) { return; }
        var group = el.closest('.form-group') || el.parentNode;
        if (!group) { return; }
        var box = group.querySelector('.b2r-check');
        if (!box) {
            box = document.createElement('div');
            box.className = 'b2r-check';
            group.appendChild(box);
        }
        box.className = 'b2r-check b2r-check-' + state;
        box.textContent = message;
    }

    function callApi(fieldName, value, el) {
        setStatus(el, 'loading', b2rConfig.i18n.checking);
        var body = 'field=' + encodeURIComponent(fieldName) +
            '&value=' + encodeURIComponent(value) +
            '&token=' + encodeURIComponent(b2rConfig.token);

        fetch(b2rConfig.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (data) {
            handleResult(fieldName, data, el);
        }).catch(function () {
            // Fail-soft : on n'empêche jamais la soumission.
            setStatus(el, 'unknown', b2rConfig.i18n.unreachable);
        });
    }

    function handleResult(fieldName, data, el) {
        if (!data || !data.checked) {
            setStatus(el, 'idle', '');
            if (fieldName === 'siret') { siretState = 'idle'; updateRevealAndError(); }
            return;
        }
        if (!data.reachable) {
            setStatus(el, 'unknown', b2rConfig.i18n.unreachable);
            // INSEE injoignable → on traite comme "OK pour soumettre" (modération admin),
            // et on révèle les champs pour ne pas bloquer le client.
            if (fieldName === 'siret') { siretState = 'unreachable'; updateRevealAndError(); }
            return;
        }

        var okMsg = fieldName === 'siret' ? b2rConfig.i18n.siretValid : b2rConfig.i18n.vatValid;
        var koMsgDefault = fieldName === 'siret' ? b2rConfig.i18n.siretInvalid : b2rConfig.i18n.vatInvalid;
        // Si le backend fournit un message custom (ex : SIREN à la place du SIRET),
        // on le privilégie sur le libellé générique — il porte une info actionnable.
        var koMsg = (data && typeof data.message === 'string' && data.message !== '') ? data.message : koMsgDefault;
        setStatus(el, data.valid ? 'valid' : 'invalid', data.valid ? okMsg : koMsg);

        if (fieldName === 'siret') {
            siretState = data.valid ? 'valid' : 'invalid';
            updateRevealAndError();
        }

        if (data.valid && b2rConfig.autofill && data.autofill) {
            fillIfEmpty('b2r_company', data.autofill.company);
            fillIfEmpty('b2r_ape', data.autofill.ape);
            // TVA FR calculée depuis le SIREN (déterministe). Le champ reste éditable.
            fillIfEmpty('b2r_vat', data.autofill.vat);
            // Synchronise silencieusement le pays détecté depuis le code postal
            // INSEE (FR / MC / GP / RE / etc) avec le dropdown caché b2r_country.
            // L'admin recevra ainsi le bon pays dans la demande B2B.
            if (fieldName === 'siret' && data.autofill.id_country) {
                var countryEl = field('b2r_country');
                if (countryEl && countryEl.tagName === 'SELECT') {
                    countryEl.value = String(data.autofill.id_country);
                }
            }
            // Adresse INSEE affichée en INFO sous le champ SIRET (pas pré-remplie ailleurs).
            // L'adresse de livraison/facturation est saisie plus tard par le client.
            if (fieldName === 'siret' && data.autofill.address_display) {
                showInseeAddress(el, data.autofill.address_display);
            }
        }
    }

    // Affiche l'adresse INSEE en info sous le champ SIRET (option A : non destructif).
    function showInseeAddress(siretEl, addressText) {
        if (!siretEl || !addressText) { return; }
        var group = siretEl.closest('.form-group') || siretEl.parentNode;
        if (!group) { return; }
        var box = group.querySelector('.b2r-insee-address');
        if (!box) {
            box = document.createElement('div');
            box.className = 'b2r-insee-address';
            group.appendChild(box);
        }
        box.innerHTML = '<span class="b2r-insee-address-label">Siège (INSEE)</span>' +
            addressText.replace(/[<>]/g, '');
    }

    // Filtre les options du dropdown pays en mode étranger : on retire FR, MC,
    // et les DROM-COM (ceux qui ONT un SIRET — par définition pas étrangers).
    // En mode FR, on restaure toutes les options.
    function filterCountrySelect(isForeign) {
        var sel = field('b2r_country');
        if (!sel || sel.tagName !== 'SELECT') { return; }
        var inseeIds = (b2rConfig.inseeIdCountries || []).map(String);

        for (var i = 0; i < sel.options.length; i++) {
            var opt = sel.options[i];
            var isInsee = inseeIds.indexOf(String(opt.value)) !== -1;
            if (isForeign && isInsee) {
                opt.disabled = true;
                opt.hidden = true;            // browsers modernes
                opt.style.display = 'none';   // fallback
            } else {
                opt.disabled = false;
                opt.hidden = false;
                opt.style.display = '';
            }
        }
        // Si la valeur courante vient d'être masquée, on bascule sur la 1re option visible.
        if (isForeign && inseeIds.indexOf(String(sel.value)) !== -1) {
            for (var j = 0; j < sel.options.length; j++) {
                if (!sel.options[j].disabled && sel.options[j].value) {
                    sel.value = sel.options[j].value;
                    break;
                }
            }
        }
    }

    // Bascule entre mode FR (SIRET visible, pays masqué) et mode étranger
    // (SIRET caché, pays + raison sociale + TVA visibles d'office, pas de reveal).
    function setForeignMode(isForeign) {
        var wrapper = document.querySelector('.b2r-pro-fields');
        if (!wrapper) { return; }
        var toggle = wrapper.querySelector('.b2r-foreign-toggle');

        wrapper.classList.toggle('b2r-foreign-mode', isForeign);

        if (isForeign) {
            // Mode étranger : pas de gating SIRET, on affiche tous les champs.
            wrapper.classList.remove('b2r-await-siret');
            // SIRET non requis, on retire required pour ne pas bloquer le submit.
            var siretEl = field('b2r_siret');
            if (siretEl) {
                siretEl.value = '';
                siretEl.removeAttribute('required');
            }
            siretState = 'idle';
            clearSubmitError();
            // Filtre le dropdown pays : on retire FR/MC/DROM-COM (les "SIRET pays").
            filterCountrySelect(true);

            // Pays = pivot : on le replace en TÊTE du wrapper (juste après le toggle).
            // Raison sociale et TVA deviennent required car on n'a pas d'autre identifiant.
            var countryGroup = wrapper.querySelector('.b2r-group-country');
            if (countryGroup && wrapper.firstChild !== countryGroup) {
                // Position : après le toggle s'il existe, sinon en tête.
                var refToggle = wrapper.querySelector('.b2r-foreign-toggle');
                if (refToggle && refToggle.nextSibling) {
                    wrapper.insertBefore(countryGroup, refToggle.nextSibling);
                } else {
                    wrapper.insertBefore(countryGroup, wrapper.firstChild);
                }
            }
            var foreignCompany = field('b2r_company');
            if (foreignCompany) { foreignCompany.setAttribute('required', 'required'); }
            var foreignCountry = field('b2r_country');
            if (foreignCountry) { foreignCountry.setAttribute('required', 'required'); }

            if (toggle) {
                toggle.setAttribute('data-mode', 'foreign');
                toggle.textContent = "Revenir à la saisie SIRET (France / Outre-Mer)";
            }
        } else {
            // Retour mode FR : SIRET visible, on remet le gating, on reset country choice.
            // Et on restaure toutes les options du dropdown (FR/MC/DROM-COM réintégrés).
            filterCountrySelect(false);
            var countryEl = field('b2r_country');
            if (countryEl && countryEl.tagName === 'SELECT') {
                // On reset à FR si présent dans la liste (sera réactualisé par
                // l'autofill INSEE au moment de la validation SIRET).
                for (var i = 0; i < countryEl.options.length; i++) {
                    if ((countryEl.options[i].text || '').toLowerCase().indexOf('france') !== -1) {
                        countryEl.value = countryEl.options[i].value;
                        break;
                    }
                }
                countryEl.removeAttribute('required'); // caché en mode FR → ne pas requis
            }
            if (b2rConfig.requireSiret) {
                var siretEl2 = field('b2r_siret');
                if (siretEl2) { siretEl2.setAttribute('required', 'required'); }
            }
            if (progressiveReveal) {
                wrapper.classList.add('b2r-await-siret');
            }
            if (toggle) {
                toggle.setAttribute('data-mode', 'fr');
                toggle.textContent = "Pas de SIRET — entreprise hors France";
            }
        }
    }

    // Reveal/hide des autres champs B2B selon l'état du SIRET, et affichage de
    // l'erreur de soumission si pertinent.
    function updateRevealAndError() {
        var wrapper = document.querySelector('.b2r-pro-fields');
        if (!wrapper) { return; }

        if (!progressiveReveal) {
            // Sans vérif live INSEE, on ne peut pas gate → on affiche tout.
            wrapper.classList.remove('b2r-await-siret');
            clearSubmitError();
            return;
        }

        // Reveal seulement si SIRET valide OU INSEE injoignable (fail-soft).
        var canReveal = (siretState === 'valid' || siretState === 'unreachable');
        wrapper.classList.toggle('b2r-await-siret', !canReveal);

        // L'erreur de submit ne persiste que si on est en état invalide explicite.
        if (siretState !== 'invalid') {
            clearSubmitError();
        }
    }

    function showSubmitError(message) {
        var prepend = document.querySelector('.b2r-form-prepend');
        if (!prepend) { return; }
        var box = prepend.querySelector('.b2r-submit-error');
        if (!box) {
            box = document.createElement('div');
            box.className = 'b2r-submit-error';
            box.setAttribute('role', 'alert');
            prepend.insertBefore(box, prepend.firstChild);
        }
        box.textContent = message;
        // Scroll dans la viewport pour le rendre visible.
        try { box.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
    }

    function clearSubmitError() {
        var box = document.querySelector('.b2r-submit-error');
        if (box && box.parentNode) { box.parentNode.removeChild(box); }
    }

    function debounce(fn, wait) {
        var t;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, wait);
        };
    }

    function init() {
        var siret = field('b2r_siret');
        var vat = field('b2r_vat');
        var company = field('b2r_company');
        var hiddenIsPro = document.getElementById('b2r-is-pro-hidden');
        var tiles = document.querySelectorAll('.b2r-account-type input[type="radio"][name="b2r_account_type"]');
        form = (siret && siret.form) || (vat && vat.form) || $('#customer-form form') || $('form');

        // 1) Replace le bloc tuiles en TÊTE du <form>.
        var prepend = document.querySelector('.b2r-form-prepend');
        if (form && prepend && form.firstChild !== prepend) {
            form.insertBefore(prepend, form.firstChild);
        }

        // 2) Replace les champs B2B (rendus en bas par additionalCustomerFormFields)
        //    juste sous les tuiles, dans l'ordre UX optimisé.
        if (prepend) {
            var proWrapper = prepend.querySelector('.b2r-pro-fields');
            if (!proWrapper) {
                proWrapper = document.createElement('div');
                proWrapper.className = 'b2r-pro-fields';
                prepend.appendChild(proWrapper);
            }
            proInputs.forEach(function (name) {
                var el = field(name);
                if (!el) { return; }
                var group = el.closest('.form-group') || el.parentNode;
                if (group && group.parentNode !== proWrapper) {
                    proWrapper.appendChild(group);
                }
                // Marqueurs sur les form-group → utilisés par le CSS pour
                // gérer reveal progressif (SIRET) et mode étranger (country, etc).
                if (group) {
                    if (name === 'b2r_siret') { group.classList.add('b2r-group-siret'); }
                    if (name === 'b2r_country') { group.classList.add('b2r-group-country'); }
                    if (name === 'b2r_company') { group.classList.add('b2r-group-company'); }
                    if (name === 'b2r_vat') { group.classList.add('b2r-group-vat'); }
                    if (name === 'b2r_ape') { group.classList.add('b2r-group-ape'); }
                }
            });

            // Hint + toggle "Mon entreprise n'est pas en France" sous le SIRET.
            // Placés en SIBLING (pas dans la form-group) pour ne pas squatter
            // la 3e colonne du grid col-3/col-6/col-3 du Classic theme.
            var siretGroup = proWrapper.querySelector('.b2r-group-siret');
            if (siretGroup && siretGroup.parentNode) {
                if (progressiveReveal && !proWrapper.querySelector('.b2r-await-hint')) {
                    var hint = document.createElement('div');
                    hint.className = 'b2r-await-hint';
                    hint.textContent = 'Saisissez votre SIRET (France métropolitaine, DROM-COM ou Monaco) — la raison sociale, le code APE et le n° de TVA se rempliront automatiquement.';
                    siretGroup.parentNode.insertBefore(hint, siretGroup.nextSibling);
                }
                if (!proWrapper.querySelector('.b2r-foreign-toggle')) {
                    var toggle = document.createElement('a');
                    toggle.href = '#';
                    toggle.className = 'b2r-foreign-toggle';
                    toggle.setAttribute('data-mode', 'fr');
                    toggle.textContent = "Pas de SIRET — entreprise hors France";
                    toggle.addEventListener('click', function (e) {
                        e.preventDefault();
                        var isForeign = toggle.getAttribute('data-mode') === 'fr';
                        setForeignMode(isForeign);
                    });
                    // Insère AVANT le hint (juste après la form-group SIRET, puis hint dessous).
                    var refNode = proWrapper.querySelector('.b2r-await-hint');
                    if (refNode) {
                        siretGroup.parentNode.insertBefore(toggle, refNode);
                    } else {
                        siretGroup.parentNode.insertBefore(toggle, siretGroup.nextSibling);
                    }
                }
            }

            if (progressiveReveal) {
                proWrapper.classList.add('b2r-await-siret');
            }
        }

        // Bascule particulier/professionnel.
        function applyMode(isPro) {
            setProVisible(isPro);
            if (hiddenIsPro) { hiddenIsPro.value = isPro ? '1' : '0'; }
            if (company) {
                if (isPro) { company.setAttribute('required', 'required'); }
                else { company.removeAttribute('required'); }
            }
            // Tout élément marqué data-b2r-pro-only="1" suit le mode (intro CMS,
            // upload Kbis, checkbox CGV).
            document.querySelectorAll('[data-b2r-pro-only="1"]').forEach(function (el) {
                el.style.display = isPro ? '' : 'none';
            });
            // CGV : la checkbox required ne doit pas bloquer le submit en mode particulier.
            var terms = document.querySelector('input[name="b2r_terms_accepted"]');
            if (terms) {
                if (isPro) { terms.setAttribute('required', 'required'); }
                else { terms.removeAttribute('required'); }
            }
        }

        // Auto-sélection du mode Pro si l'URL contient ?b2r_pro=1
        // (utilisé par le lien "Inscription Pro" du header).
        try {
            var url = new URL(window.location.href);
            if (url.searchParams.get('b2r_pro') === '1') {
                var proTile = document.querySelector('.b2r-account-type input[type="radio"][data-b2r-pro="1"]');
                if (proTile) {
                    proTile.checked = true;
                    // Décoche la tuile Particulier pour cohérence visuelle.
                    var partTile = document.querySelector('.b2r-account-type input[type="radio"][data-b2r-pro="0"]');
                    if (partTile) { partTile.checked = false; }
                }
            }
        } catch (e) { /* URL API peut manquer sur très vieux browsers */ }

        if (b2rConfig.onlyPro) {
            applyMode(true);
        } else if (tiles.length) {
            // Initialisation depuis l'état coché des tuiles.
            var checked = document.querySelector('.b2r-account-type input[type="radio"]:checked');
            applyMode(!!(checked && checked.getAttribute('data-b2r-pro') === '1'));
            tiles.forEach(function (input) {
                input.addEventListener('change', function () {
                    applyMode(input.getAttribute('data-b2r-pro') === '1');
                });
            });
        } else {
            applyMode(true);
        }

        if (siret && b2rConfig.enableSiret) {
            // Helper : efface les champs que NOUS avions auto-remplis (data-b2r-autofilled).
            // Appelé avant chaque nouvelle validation SIRET pour éviter les valeurs zombies
            // d'un précédent SIRET, et au reset du champ.
            function resetAutofilledFields() {
                ['b2r_company', 'b2r_ape', 'b2r_vat'].forEach(function (name) {
                    var el = field(name);
                    if (el && el.getAttribute('data-b2r-autofilled') === '1') {
                        el.value = '';
                        el.classList.remove('b2r-autofilled');
                        el.removeAttribute('data-b2r-autofilled');
                        el.removeAttribute('data-b2r-autofilled-value');
                    }
                });
                // Adresse INSEE résiduelle.
                var group = siret.closest('.form-group') || siret.parentNode;
                if (group) {
                    var box = group.querySelector('.b2r-insee-address');
                    if (box && box.parentNode) { box.parentNode.removeChild(box); }
                }
            }

            var checkSiret = debounce(function () {
                var v = siret.value.replace(/[^0-9]/g, '');
                if (v.length >= 9) {
                    // Nouvelle saisie SIRET → on efface les pré-remplissages d'un éventuel
                    // SIRET précédent avant d'appeler INSEE (sinon fillIfEmpty laisse les
                    // anciennes valeurs : "raison sociale du 1er SIRET" + "address INSEE du 1er").
                    resetAutofilledFields();
                    callApi('siret', v, siret);
                } else {
                    // SIRET vidé/raccourci : on revient à l'état initial
                    // (attente, toggle visible, pas d'adresse INSEE résiduelle).
                    setStatus(siret, 'idle', '');
                    siretState = 'idle';
                    resetAutofilledFields();
                    updateRevealAndError();
                }
            }, 600);
            siret.addEventListener('blur', checkSiret);
            siret.addEventListener('input', checkSiret);
        }

        if (vat && b2rConfig.enableVies) {
            var checkVat = debounce(function () {
                // Si la valeur a été auto-calculée par nous (depuis le SIREN)
                // et n'a pas été modifiée par le client → on saute le check VIES.
                // On lui fait confiance (algo déterministe), et on évite le bruit
                // "Vérification indisponible" quand VIES est saturé.
                var autoVal = vat.getAttribute('data-b2r-autofilled-value');
                if (autoVal && autoVal === vat.value) {
                    setStatus(vat, 'idle', '');
                    return;
                }
                // L'utilisateur a modifié la valeur → on nettoie le marqueur auto
                // et on relance VIES sur la nouvelle saisie.
                if (autoVal && autoVal !== vat.value) {
                    vat.removeAttribute('data-b2r-autofilled');
                    vat.removeAttribute('data-b2r-autofilled-value');
                }

                var v = vat.value.replace(/[^A-Za-z0-9]/g, '');
                if (v.length >= 4) { callApi('vat', v, vat); }
                else { setStatus(vat, 'idle', ''); }
            }, 600);
            vat.addEventListener('blur', checkVat);
            vat.addEventListener('input', checkVat);
        }

        // Intercepte le submit : si Pro + SIRET requis + vérif live INSEE dispo
        // + état SIRET = invalide → on bloque la soumission avec message clair.
        // Évite la création d'un compte client qu'on devrait ensuite supprimer.
        if (form) {
            form.addEventListener('submit', function (e) {
                var isProSubmit = !!(hiddenIsPro && parseInt(hiddenIsPro.value, 10) === 1);
                if (!isProSubmit) { return; }
                if (!progressiveReveal || !b2rConfig.requireSiret) { return; }

                // Recheck l'état + filet : si le champ a une valeur ≥9 chiffres
                // sans validation aboutie (ex: user a soumis trop vite), on bloque.
                var rawSiret = siret ? siret.value.replace(/[^0-9]/g, '') : '';
                var hasSiretValue = rawSiret.length >= 9;

                if (siretState === 'invalid' || (hasSiretValue && siretState === 'idle')) {
                    e.preventDefault();
                    e.stopPropagation();
                    showSubmitError(b2rConfig.i18n.submitBlocked);
                    if (siret) { try { siret.focus(); } catch (err) {} }
                    return false;
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
