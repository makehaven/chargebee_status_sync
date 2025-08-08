<?php

namespace Drupal\chargebee_status_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Utility\Crypt;
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
    $roles = user_role_names(TRUE);
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

  protected function clearCancellationFields($customer_id) {
    $user = $this->getUserByCustomerId($customer_id);
    if ($user) {
        $user->set('field_member_reactivation_date', date('Y-m-d', time()));
        $user->set('field_member_end_date', NULL);
        $user->set('field_member_end_reason', NULL);
        $user->save();
        $this->logger->info('Cleared cancellation fields for user @uid', ['@uid' => $user->id()]);
    }
}



  protected function updateSubscriptionFields($customer_id, $end_date, $reason) {
    $user = $this->getUserByCustomerId($customer_id); // Existing helper function.
    if ($user) {
        $user->set('field_member_end_date', $end_date);
        $user->set('field_member_end_reason', $reason);
        $user->save();
        $this->logger->info('Updated cancellation fields for user @uid', ['@uid' => $user->id()]);
    } else {
        $this->logger->warning('No user found for customer ID @customer_id', ['@customer_id' => $customer_id]);
    }
}

  
  /**
   * Method to update the user's field based on subscription status.
   *
   * @param string $chargebee_customer_id
   *   The Chargebee customer ID.
   * @param bool $is_paused
   *   Indicates whether the subscription is paused or not.
   * @param bool $payment_failed
   *   Indicates whether the payment has failed or succeeded.
   */
  public function updateUserFields($chargebee_customer_id, $is_paused = NULL, $payment_failed = NULL) {
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    $users = $user_storage->loadByProperties(['field_user_chargebee_id' => $chargebee_customer_id]);

    if (!empty($users)) {
      /** @var \Drupal\user\Entity\User $user */
      $user = reset($users);
      $modified = FALSE;

      if ($is_paused !== NULL) {
        $user->set('field_chargebee_payment_pause', $is_paused ? 1 : 0);
        $modified = TRUE;
      }

      if ($payment_failed !== NULL) {
        $user->set('field_payment_failed', $payment_failed ? 1 : 0);
        $modified = TRUE;
      }

      if ($modified) {
        $user->save();
      }
    }
  }
}
