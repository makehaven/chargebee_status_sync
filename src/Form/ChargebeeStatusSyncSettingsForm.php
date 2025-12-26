<?php

namespace Drupal\chargebee_status_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

class ChargebeeStatusSyncSettingsForm extends ConfigFormBase {
  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a ChargebeeStatusSyncSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chargebee_status_sync_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['chargebee_status_sync.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('chargebee_status_sync.settings');

    // Role selection dropdown.
    $roles = array_map(fn($role) => $role->label(), Role::loadMultiple());
    $form['chargebee_status_member_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Member Role'),
      '#options' => $roles,
      '#default_value' => $config->get('chargebee_status_member_role'),
      '#description' => $this->t('Select the role to assign or remove based on Chargebee subscription status.'),
    ];

    // Notification email.
    $form['chargebee_status_sync_notify_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Notification Email'),
      '#default_value' => $config->get('chargebee_status_sync_notify_email'),
      '#description' => $this->t('Enter the email address where notifications should be sent in case of errors. Leave empty if you do not wish to receive notifications.'),
    ];

    // Basic authentication toggle.
    $form['chargebee_status_basic_auth'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Basic Authentication for webhook'),
      '#default_value' => $config->get('chargebee_status_basic_auth'),
      '#description' => $this->t('If checked, the webhook endpoint will require Basic Authentication.'),
    ];

    // Webhook user field.
    $form['chargebee_status_sync_user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook User'),
      '#default_value' => $config->get('chargebee_status_sync_user'),
      '#description' => $this->t('Enter the username of the Drupal user that Chargebee will use to authenticate webhooks.'),
      '#required' => TRUE,
    ];

    // Webhook token field.
    $form['chargebee_status_sync_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook Token'),
      '#default_value' => $config->get('chargebee_status_sync_token'),
      '#description' => $this->t('Enter the token for the webhook. You can generate a new one if necessary.'),
      '#required' => TRUE,
    ];

    // Generate token button.
    $form['generate_token'] = [
      '#type' => 'button',
      '#value' => $this->t('Generate Token'),
      '#ajax' => [
        'callback' => '::generateToken',
        'wrapper' => 'edit-chargebee-status-sync-token',
      ],
    ];

    // Display the webhook URL.
    $webhook_url = $GLOBALS['base_url'] . '/chargebee-webhook/' . $config->get('chargebee_status_sync_token');
    $form['chargebee_status_sync_webhook_url'] = [
      '#markup' => $this->t('Use the following webhook URL for Chargebee: <strong>@url</strong>', ['@url' => $webhook_url]),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback to generate a new token.
   */
  public function generateToken(array &$form, FormStateInterface $form_state) {
    $new_token = Crypt::randomBytesBase64(32);
    $form_state->setValue('chargebee_status_sync_token', $new_token);
    $form['chargebee_status_sync_token']['#value'] = $new_token;
    return $form['chargebee_status_sync_token'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('chargebee_status_sync.settings')
      ->set('chargebee_status_member_role', $form_state->getValue('chargebee_status_member_role'))
      ->set('chargebee_status_sync_notify_email', $form_state->getValue('chargebee_status_sync_notify_email'))
      ->set('chargebee_status_basic_auth', $form_state->getValue('chargebee_status_basic_auth'))
      ->set('chargebee_status_sync_user', $form_state->getValue('chargebee_status_sync_user'))
      ->set('chargebee_status_sync_token', $form_state->getValue('chargebee_status_sync_token'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
