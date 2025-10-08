<?php

namespace Drupal\gestio_equipaments\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;

class ConfirmDeleteForm extends ConfirmFormBase {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * El codi de l'equipament a eliminar.
   *
   * @var string
   */
  protected $codi_equipament;
  
  /**
   * El nom de l'equipament a eliminar.
   *
   * @var string
   */
  protected $nom_equipament;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gestio_equipaments_confirm_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t("Estàs segur que vols eliminar l'equipament @nom (@codi)?", [
      '@nom' => $this->nom_equipament,
      '@codi' => $this->codi_equipament,
    ]);
  }
  
  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t("Aquesta acció no es pot desfer. S'eliminaran totes les dades associades a aquest equipament, incloent els registres de la base de dades i les entrades als arxius CSV.");
  }


  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('gestio_equipaments.list');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $codi_equipament = NULL) {
    $this->codi_equipament = $codi_equipament;
    
    $equipament = $this->database->select('nou_formulari_equipaments', 'e')
      ->fields('e', ['nom_equipament'])
      ->condition('codi_equipament', $this->codi_equipament)
      ->execute()->fetchField();
      
    $this->nom_equipament = $equipament ?: $this->t('desconegut');
    
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (empty($this->codi_equipament)) {
      $this->messenger()->addError($this->t('No s\'ha pogut identificar l\'equipament a eliminar.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $transaction = $this->database->startTransaction();
    try {
      // 1. Eliminar de la taula principal d'equipaments.
      $this->database->delete('nou_formulari_equipaments')
        ->condition('codi_equipament', $this->codi_equipament)
        ->execute();

      // 2. Eliminar les dades de formulari associades a la base de dades.
      $this->database->delete('nou_formulari_dades_formulari')
        ->condition('codi_equipament', $this->codi_equipament)
        ->execute();

      // 3. Eliminar dels arxius CSV.
      $resultat_csv = $this->eliminaEquipamentCsv($this->codi_equipament);

      if (!$resultat_csv['success']) {
        throw new \Exception($resultat_csv['message']);
      }

      $this->messenger()->addStatus($this->t("L'equipament @nom (@codi) i totes les seves dades han estat eliminats.", [
        '@nom' => $this->nom_equipament, 
        '@codi' => $this->codi_equipament
      ]));
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->messenger()->addError($this->t("Error en eliminar l'equipament @codi: @error", ['@codi' => $this->codi_equipament, '@error' => $e->getMessage()]));
      $this->getLogger('gestio_equipaments')->error($e->getMessage());
    }
    
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  private function eliminaEquipamentCsv($codi_equipament) {
    $equipaments_enviats_csv = \Drupal::root() . '/modules/custom/nou_formulari/equipaments_enviats.csv';
    $dades_formulari_csv = \Drupal::root() . '/modules/custom/nou_formulari/dades_formulari.csv';

    $resultat_enviats = $this->eliminaLiniaCsv($equipaments_enviats_csv, $codi_equipament, 0);
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

  private function eliminaLiniaCsv($csv_path, $codi_equipament, $columna, $manté_primera_fila = FALSE) {
    if (!file_exists($csv_path) || !is_readable($csv_path)) {
        return TRUE; // L'arxiu no existeix, operació "exitosa".
    }
    if (!is_writable(dirname($csv_path))) {
        return FALSE; // No tenim permisos per crear l'arxiu temporal.
    }
    $temp_path = $csv_path . '.tmp';

    $handle = @fopen($csv_path, 'r');
    $temp_handle = @fopen($temp_path, 'w');

    if (!$handle || !$temp_handle) { return FALSE; }
    
    if (flock($handle, LOCK_EX)) {
      $linia_num = 0;
      while (($data = fgetcsv($handle)) !== FALSE) {
        $linia_num++;
        if ($linia_num == 1 && $manté_primera_fila) {
          fputcsv($temp_handle, $data);
          continue;
        }
        if (!isset($data[$columna]) || $data[$columna] != $codi_equipament) {
          fputcsv($temp_handle, $data);
        }
      }
      flock($handle, LOCK_UN);
      fclose($handle);
      fclose($temp_handle);
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