// Línia d'embolcall IIFE ORIGINAL.
(function ($, Drupal) { 
  // EL TEU CODI ORIGINAL - NO ES TOCA RES AQUÍ (Behavior per esborrar)
  Drupal.behaviors.nouFormulari = {
    attach: function (context, settings) {
      var $elements = $('.delete-equipament', context);

      $elements.off('click'); // Important: jQuery 'once' és millor que off/on manuals

      $elements.on('click', function (e) {
        e.preventDefault();
        var codiEquipament = $(this).data('codi');
        var $link = $(this);

        if (!confirm(Drupal.t('Estàs segur que vols esborrar el formulari enviat per aquest equipament?'))) {
          return;
        }

        $.ajax({
          url: Drupal.url('nou_formulari/delete_equipament'),
          type: 'POST',
          data: {
            codi_equipament: codiEquipament
          },
          dataType: 'json',
          success: function (response) {
            if (response.success) {
              // Selector original, podria no cobrir tots els estats si canvien les classes CSS
              $link.closest('.equipament-enviat').fadeOut(function () { 
                $(this).remove();
                location.reload();
              });
            } else {
              alert(response.message);
            }
          },
          error: function () {
            alert(Drupal.t('Hi ha hagut un error en eliminar l\'equipament.'));
          }
        });
      });
    }
  };

  // EL TEU CODI ORIGINAL - NO ES TOCA RES AQUÍ (Behavior per al comptador)
  Drupal.behaviors.nouFormulariSubitemCounter = {
    attach: function (context, settings) {
      const onceFn = (typeof drupalĐi !== 'undefined' && drupalĐi.once) ? drupalĐi.once : (typeof once !== 'undefined' ? once : null);

      if (!onceFn) {
        // console.warn("Drupal 'once' function not found. Subitem counter might not work as expected with AJAX updates.");
      }

      const detailsSelector = '.accions-millora-details';
      const detailsElements = onceFn ? 
                              onceFn('nou-formulari-counter-details', detailsSelector, context) : 
                              $(detailsSelector, context).not('.nou-formulari-counter-processed');
      
      if (!onceFn) {
         $(detailsElements).addClass('nou-formulari-counter-processed');
      }

      $(detailsElements).each(function() {
        const detailsElement = this; 
        const $detailsElement = $(this); 
        const summaryElement = detailsElement.querySelector('summary');
        if (!summaryElement) return true; 

        const titleBaseSpan = summaryElement.querySelector('.details-title-base');
        const counterSpan = summaryElement.querySelector('.details-title-counter');
        const titleBaseText = $detailsElement.data('title-base-text') || Drupal.t('Accions de millora'); // Utilitzem Drupal.t per si vols traduir "Accions de millora"
        const totalSubitems = parseInt($detailsElement.data('total-subitems'), 10);
        const $checkboxes = $detailsElement.find('.subitem-action-checkbox');

        function updateSummaryText() {
          if (!titleBaseSpan || !counterSpan || isNaN(totalSubitems)) {
            return;
          }
          let checkedCount = 0;
          $checkboxes.each(function() {
            if (this.checked) {
              checkedCount++;
            }
          });
          
          titleBaseSpan.textContent = titleBaseText;
          if (checkedCount > 0) {
            counterSpan.textContent = ' ' + checkedCount + '/' + totalSubitems;
          } else {
            counterSpan.textContent = ''; 
          }
        }
        
        $checkboxes.off('change.nouFormulariCounter').on('change.nouFormulariCounter', updateSummaryText);
        updateSummaryText();
      });
    }
  };
  
  
  // ***** INICI DE LA MODIFICACIÓ *****
  // NOU Behavior per a la lògica de l'any de compromís (MODIFICAT).
  Drupal.behaviors.nouFormulariAnyCompromis = {
    attach: function (context, settings) {
      // Determinem si 'once' està disponible.
      const onceFn = (typeof drupalĐi !== 'undefined' && drupalĐi.once) ? drupalĐi.once : (typeof once !== 'undefined' ? once : null);
      const selectorCheckboxes = '.subitem-action-checkbox'; // Classe dels checkboxes de subítem.
  
      // Apliquem 'once' per processar cada checkbox una sola vegada.
      // Si 'onceFn' no està disponible, fem fallback a una selecció més simple
      // (menys ideal per a AJAX, però manté la compatibilitat amb el teu codi original).
      const checkboxes = onceFn ?
                         onceFn('nou-formulari-any-compromis', selectorCheckboxes, context) :
                         $(selectorCheckboxes, context).not('.nou-formulari-any-compromis-processed');
      
      // Si 'onceFn' no estava disponible, marquem manualment els elements processats.
      if (!onceFn) {
        $(checkboxes).addClass('nou-formulari-any-compromis-processed');
      }
  
      // Iterem sobre els checkboxes que s'han de processar.
      $(checkboxes).each(function() {
        // const $checkbox = $(this); // Referència al checkbox actual (jQuery object).
        // const checkboxName = $checkbox.attr('name'); // Nom del checkbox.
        // const $detailsWrapper = $checkbox.closest('.accions-millora-details'); // Contenidor <details>.
        // let $dateSelect; // Variable per al select d'any.

        // No necessitem trobar el 'dateSelect' explícitament aquí si no el modificarem.
        /*
        if (!checkboxName) return true; // Si no té nom, saltem al següent.
  
        if ($checkbox.hasClass('custom-action-checkbox')) {
          let dateFieldName = checkboxName.replace(/_custom_chk(\]|)$/, '_custom_plan_date$1');
          $dateSelect = $detailsWrapper.find('select[name="' + dateFieldName + '"]');
        } else {
          let dateFieldName = checkboxName.replace(/(\]|)$/, '_date$1');
          $dateSelect = $detailsWrapper.find('select[name="' + dateFieldName + '"]');
        }
        */

        // LA LÒGICA QUE CANVIAVA L'ANY DE '2025' A '2030' HA ESTAT ELIMINADA.
        // La funció 'updateAnyCompromis' ja no es crida ni es defineix aquí
        // perquè la seva única acció era causar el problema.

        // El comportament de mostrar/ocultar el select d'any (i el seu valor per defecte)
        // es gestiona correctament per PHP (#states i #default_value)
        // basant-se en les dades de la base de dades.
      });
    }
  };
  // ***** FI DE LA MODIFICACIOLIACIÓ *****
  
})(jQuery, Drupal);