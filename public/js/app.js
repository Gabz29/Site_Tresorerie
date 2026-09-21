/* ==========================================================================
   JUMÃO — interactions côté navigateur

   ⚠ RÈGLE VALABLE POUR TOUT CE FICHIER : le JavaScript est un CONFORT,
   jamais une protection. Tout ce qui est fait ici peut être désactivé,
   modifié ou contourné par n'importe qui en dix secondes. Les contrôles qui
   comptent — droits, validations, taille et type des fichiers — sont faits
   côté serveur, en PHP, et le restent.
   ========================================================================== */

(function () {
  'use strict';

  /* ------------------------------------------------------------------------
     RÉDUCTION DES PHOTOS AVANT ENVOI

     LE PROBLÈME. Les justificatifs sont surtout des photos de tickets prises
     au téléphone : 3 à 5 Mo pour un capteur de 12 mégapixels. Or PHP refuse
     par défaut les fichiers de plus de 2 Mo (upload_max_filesize), et refuse
     AVANT que le code de l'application ne s'exécute.

     LA SOLUTION. Redimensionner l'image dans le navigateur avant l'envoi.
     Un ticket de caisse reste parfaitement lisible en 1600 px de large, et
     pèse alors environ 300 Ko au lieu de 4 Mo.

     L'INTÉRÊT PRINCIPAL n'est pas de contourner la limite : c'est que le
     fichier n'atteint plus jamais cette taille. L'application fonctionnera
     donc sur le serveur ISEN quelle que soit leur configuration, sans avoir
     à leur demander quoi que ce soit. En prime, l'envoi est bien plus rapide
     en 4G.

     Les PDF ne sont jamais touchés : une facture PDF dépasse rarement
     500 Ko, et il n'existe pas de moyen simple de la recompresser.
     ---------------------------------------------------------------------- */

  var LARGEUR_MAX = 1600;        // pixels — au-delà, on réduit
  var SEUIL_OCTETS = 800 * 1024; // en dessous, inutile d'y toucher
  var QUALITE_JPEG = 0.82;       // compromis lisibilité / poids

  var champ = document.getElementById('justificatif');

  if (!champ || typeof HTMLCanvasElement === 'undefined') {
    return; // page sans champ fichier, ou navigateur trop ancien
  }

  var formulaire = champ.form;
  var enCours = false;

  formulaire.addEventListener('submit', function (evenement) {
    // Deuxième passage : la compression est finie, on laisse partir.
    if (enCours) {
      return;
    }

    var fichier = champ.files && champ.files[0];

    if (!fichier || !doitEtreReduit(fichier)) {
      return;
    }

    // La réduction est asynchrone : on retient l'envoi le temps de la faire.
    evenement.preventDefault();
    signaler('Optimisation de l’image…');

    reduire(fichier)
      .then(function (reduit) {
        // On ne garde le résultat que s'il est réellement plus léger : sur
        // une image déjà optimisée, recompresser peut l'alourdir.
        if (reduit && reduit.size < fichier.size) {
          remplacerFichier(reduit);
        }
      })
      .catch(function () {
        /* Format non décodable (HEIC sur certains navigateurs), image
           corrompue... On n'empêche pas l'envoi : le serveur tranchera,
           et affichera un message clair si le fichier est trop gros. */
      })
      .then(function () {
        enCours = true;
        signaler('');
        formulaire.submit();
      });
  });

  function doitEtreReduit(fichier) {
    return fichier.type.indexOf('image/') === 0 && fichier.size > SEUIL_OCTETS;
  }

  function reduire(fichier) {
    // imageOrientation: 'from-image' respecte l'orientation EXIF. Sans cela,
    // les photos prises en tenant le téléphone verticalement ressortent
    // couchées : l'appareil enregistre l'image à plat et note la rotation à
    // part, information que le redimensionnement perdrait.
    return createImageBitmap(fichier, { imageOrientation: 'from-image' })
      .then(function (image) {
        var ratio = Math.min(1, LARGEUR_MAX / Math.max(image.width, image.height));

        var toile = document.createElement('canvas');
        toile.width = Math.round(image.width * ratio);
        toile.height = Math.round(image.height * ratio);

        toile.getContext('2d').drawImage(image, 0, 0, toile.width, toile.height);
        image.close();

        return new Promise(function (resoudre) {
          toile.toBlob(resoudre, 'image/jpeg', QUALITE_JPEG);
        });
      });
  }

  function remplacerFichier(blob) {
    // On ne peut pas écrire directement dans input.files : la propriété est
    // en lecture seule, précisément pour qu'une page ne puisse pas envoyer
    // un fichier à l'insu de l'utilisateur. DataTransfer est la seule façon
    // prévue de constituer une liste de fichiers par programme.
    var transfert = new DataTransfer();

    transfert.items.add(new File([blob], nomJpeg(champ.files[0].name), {
      type: 'image/jpeg',
      lastModified: Date.now()
    }));

    champ.files = transfert.files;
  }

  function nomJpeg(nom) {
    return nom.replace(/\.[^.]+$/, '') + '.jpg';
  }

  function signaler(texte) {
    var zone = document.getElementById('etat-justificatif');

    if (zone) {
      zone.textContent = texte;
    }
  }
})();
