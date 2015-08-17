<?php

/**
 * @file
 * Contains \Drupal\rng_quick\Form\RegisterBlockForm.
 */

namespace Drupal\rng_quick\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rng\EventManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\rng\Entity\Registration;
use Drupal\user\Entity\User;

/**
 * Builds the form for the quick registration block.
 */
class RegisterBlockForm extends FormBase {

  /**
   * The RNG event manager.
   *
   * @var EventManagerInterface
   */
  protected $eventManager;

  /**
   * The event entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $event;

  /**
   * Constructs a new RegisterBlockForm instance.
   *
   * @param \Drupal\rng\EventManagerInterface $event_manager
   *   The RNG event manager.
   */
  public function __construct(EventManagerInterface $event_manager) {
    $this->eventManager = $event_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('rng.event_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rng_quick_register_block_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityInterface $event
   *   The event entity.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $event = NULL) {
    $this->event = $event;
    $event_meta = $this->eventManager->getMeta($event);

    $registration_types = $event_meta->getRegistrationTypes();
    if (1 == count($registration_types)) {
      $form['description']['#markup'] = $this->t('Register %user for %event.', [
        '%event' => $event->label(),
        '%user' => \Drupal::currentUser()->getUsername(),
      ]);
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Create registration'),
        '#button_type' => 'primary',
      ];

      // RegisterBlock::blockAccess() already checked for self.
      if (($event_meta->countProxyIdentities() - 1) > 0) {
        $form['actions']['other'] = [
          '#type' => 'submit',
          '#value' => $this->t('Other person'),
        ];
      }
    }
    else {
      $form['description']['#markup'] = $this->t('Multiple registration types are available for this event. Click register to continue.');
      // redirect to /register
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Register'),
        '#button_type' => 'primary',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $event = $this->event;
    $event_meta = $this->eventManager->getMeta($event);
    $user = User::load(\Drupal::currentUser()->id());
    $route_parameters = [
      $event->getEntityTypeId() => $event->id(),
    ];

    $registration_types = $event_meta->getRegistrationTypes();
    if (1 == count($registration_types)) {
      $registration_type = reset($registration_types);
      if ($form_state->getValue('op') == $this->t('Other person')) {
        $route_parameters['registration_type'] = $registration_type->id();
        $form_state->setRedirect('rng.event.' . $event->getEntityTypeId() . '.register', $route_parameters);
        return;
      }

      $registration = Registration::create([
        'type' => $registration_type->id(),
      ]);
      $registration
        ->addIdentity($user)
        ->setEvent($event);
      if (!$registration->validate()->count()) {
        $registration->save();
        drupal_set_message($this->t('@entity_type has been created.', [
          '@entity_type' => $registration->getEntityType()->getLabel(),
        ]));
        if ($registration->access('view')) {
          $form_state->setRedirectUrl($registration->urlInfo());
          return;
        }
      }
    }
    else {
      $form_state->setRedirect('rng.event.' . $event->getEntityTypeId() . '.register.type_list', $route_parameters);
      return;
    }

    drupal_set_message($this->t('Unable to create registration.'), 'error');
  }
}
