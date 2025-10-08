<?php

namespace Drupal\nou_formulari\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class NouFormulariController extends ControllerBase {

  /**
   * Funció per eliminar un equipament.
   */
  public function deleteEquipament(Request $request) {
	// Obtenim el codi d'equipament de la petició AJAX.
	$codi_equipament = $request->request->get('codi_equipament');

	// Verifiquem que l'usuari té permisos per eliminar equipaments.
	// Pots ajustar el permís segons les teves necessitats.
	if (!\Drupal::currentUser()->hasPermission('access content')) {
	  return new JsonResponse([
		'success' => FALSE,
		'message' => $this->t('No tens permís per realitzar aquesta acció.'),
	  ]);
	}

	// Cridem a la funció que gestiona l'eliminació.
	$resultat = $this->eliminaEquipamentCsv($codi_equipament);

	if ($resultat['success']) {
	  return new JsonResponse([
		'success' => TRUE,
		'message' => $this->t('Equipament eliminat correctament.'),
		// Pots retornar el nou HTML del comptador si cal actualitzar-lo al client.
	  ]);
	}
	else {
	  return new JsonResponse([
		'success' => FALSE,
		'message' => $resultat['message'],
	  ]);
	}
  }

  /**
   * Funció que elimina l'equipament dels arxius CSV.
   */
  private function eliminaEquipamentCsv($codi_equipament) {
	// Ruta dels arxius CSV.
	$equipaments_enviats_csv = \Drupal::root() . '/modules/custom/nou_formulari/equipaments_enviats.csv';
	$dades_formulari_csv = \Drupal::root() . '/modules/custom/nou_formulari/dades_formulari.csv';

	// Eliminar la línia corresponent en equipaments_enviats.csv
	$resultat_enviats = $this->eliminaLiniaCsv($equipaments_enviats_csv, $codi_equipament, 0);

	// Eliminar la línia corresponent en dades_formulari.csv (sense eliminar la primera línia)
	$resultat_dades = $this->eliminaLiniaCsv($dades_formulari_csv, $codi_equipament, 5, TRUE);

	if ($resultat_enviats && $resultat_dades) {
	  return ['success' => TRUE];
	}
	else {
	  return [
		'success' => FALSE,
		'message' => $this->t('No s\'ha pogut eliminar l\'equipament dels arxius CSV.'),
	  ];
	}
  }

  /**
   * Funció per eliminar una línia d'un arxiu CSV basat en un valor d'una columna.
   */
  private function eliminaLiniaCsv($csv_path, $codi_equipament, $columna, $manté_primera_fila = FALSE) {
	$temp_path = $csv_path . '.tmp';

	// Bloquegem l'arxiu per evitar problemes de concurrència.
	$handle = fopen($csv_path, 'r');
	$temp_handle = fopen($temp_path, 'w');

	if (flock($handle, LOCK_EX)) {
	  $linia_num = 0;
	  while (($data = fgetcsv($handle)) !== FALSE) {
		$linia_num++;
		// Si hem de mantenir la primera fila (capçalera) i estem en la primera línia.
		if ($linia_num == 1 && $manté_primera_fila) {
		  fputcsv($temp_handle, $data);
		  continue;
		}
		if ($data[$columna] != $codi_equipament) {
		  fputcsv($temp_handle, $data);
		}
	  }
	  fflush($temp_handle);
	  fclose($handle);
	  fclose($temp_handle);
	  // Reemplaçar l'arxiu original pel nou.
	  rename($temp_path, $csv_path);
	  return TRUE;
	}
	else {
	  fclose($handle);
	  fclose($temp_handle);
	  return FALSE;
	}
  }
}
