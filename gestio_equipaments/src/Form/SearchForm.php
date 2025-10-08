<?php

namespace Drupal\gestio_equipaments\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class SearchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gestio_equipaments_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('gestio_equipaments.list')->toString();

    $form['search'] = [
      '#type' => 'search',
      '#title' => $this->t('Cerca'),
      '#title_display' => 'invisible',
      '#placeholder' => $this->t('Cerca per codi, nom, municipi, comarca...'), // TEXT MODIFICAT
      '#default_value' => $this->getRequest()->query->get('search', ''),
      '#attributes' => ['class' => ['form-control']],
    ];

    $form['actions'] = [
      '#type' => 'actions'
    ];
    
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cerca'),
    ];

    if ($this->getRequest()->query->get('search')) {
        $form['actions']['clear'] = [
            '#type' => 'link',
            '#title' => $this->t('Neteja'),
            '#url' => Url::fromRoute('gestio_equipaments.list'),
            '#attributes' => ['class' => ['button']],
        ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No fa res, ja que el formulari envia per GET.
  }
}