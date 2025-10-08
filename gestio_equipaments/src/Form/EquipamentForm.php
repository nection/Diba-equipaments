<?php

namespace Drupal\gestio_equipaments\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

class EquipamentForm extends FormBase {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Class constructor.
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
    return 'gestio_equipaments_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $codi_equipament = NULL) {
    $equipament = [];
    if ($codi_equipament) {
      $query = $this->database->select('nou_formulari_equipaments', 'e')
        ->fields('e')
        ->condition('codi_equipament', $codi_equipament)
        ->range(0, 1);
      $equipament = $query->execute()->fetchAssoc();
    }
    
    $form_state->set('is_edit', (bool) $equipament);
    $form_state->set('original_codi', $codi_equipament);

    $form['codi_equipament'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Codi d'Equipament"),
      '#description' => $this->t("Un identificador únic. Només lletres, números i guions (_-). NO es podrà canviar un cop creat."),
      '#default_value' => $equipament['codi_equipament'] ?? '',
      '#required' => TRUE,
      '#disabled' => (bool) $equipament,
    ];

    $form['nom_equipament'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Nom de l'Equipament"),
      '#default_value' => $equipament['nom_equipament'] ?? '',
      '#required' => TRUE,
    ];

    $form['municipi'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Municipi'),
      '#description' => $this->t("Escriu el nom del municipi."),
      '#default_value' => $equipament['municipi'] ?? '',
      '#required' => TRUE,
    ];
    
    // NOU CAMP "COMARCA"
    $form['comarca'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Comarca'),
      '#description' => $this->t("Escriu el nom de la comarca."),
      '#default_value' => $equipament['comarca'] ?? '',
      '#required' => TRUE, // El podem deixar opcional si vols. Canvia a TRUE si ha de ser obligatori.
    ];

    $form['espai_principal'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Espai Principal'),
      '#description' => $this->t("Tipologia de l'espai, p.ex. 'Biblioteca', 'Casal de Joves', etc."),
      '#default_value' => $equipament['espai_principal'] ?? '',
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Desar'),
      '#button_type' => 'primary',
    ];
     $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel·lar'),
      '#url' => Url::fromRoute('gestio_equipaments.list'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    if (!$form_state->get('is_edit')) {
      $codi = $form_state->getValue('codi_equipament');

      if (!preg_match('/^[a-zA-Z0-9_-]+$/', $codi)) {
        $form_state->setErrorByName('codi_equipament', $this->t("El codi d'equipament només pot contenir lletres, números, guions (-) i guions baixos (_)."));
      }

      $query = $this->database->select('nou_formulari_equipaments', 'e')
        ->fields('e', ['codi_equipament'])
        ->condition('codi_equipament', $codi)
        ->range(0, 1);
      $result = $query->execute()->fetchField();

      if ($result) {
        $form_state->setErrorByName('codi_equipament', $this->t("El codi d'equipament '@codi' ja existeix.", ['@codi' => $codi]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $is_edit = $form_state->get('is_edit');
    $values = $form_state->getValues();

    $fields = [
      'nom_equipament' => $values['nom_equipament'],
      'municipi' => $values['municipi'],
      'comarca' => $values['comarca'], // NOU CAMP
      'espai_principal' => $values['espai_principal'],
    ];

    if ($is_edit) {
      $codi_original = $form_state->get('original_codi');
      try {
        $this->database->update('nou_formulari_equipaments')
          ->fields($fields)
          ->condition('codi_equipament', $codi_original)
          ->execute();
        $this->messenger()->addStatus($this->t("L'equipament '@nom' s'ha actualitzat.", ['@nom' => $values['nom_equipament']]));
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t("Error en actualitzar l'equipament."));
        $this->getLogger('gestio_equipaments')->error($e->getMessage());
      }
    }
    else {
      $fields['codi_equipament'] = $values['codi_equipament'];
      try {
        $this->database->insert('nou_formulari_equipaments')
          ->fields($fields)
          ->execute();
        $this->messenger()->addStatus($this->t("L'equipament '@nom' s'ha creat.", ['@nom' => $values['nom_equipament']]));
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t("Error en crear l'equipament."));
        $this->getLogger('gestio_equipaments')->error($e->getMessage());
      }
    }

    $form_state->setRedirect('gestio_equipaments.list');
  }
}