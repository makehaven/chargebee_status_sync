<?php

namespace Drupal\chargebee_status_sync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\chargebee_status_sync\Form\ChargebeeStatusSyncSettingsForm;
use Drupal\chargebee_status_sync\Service\PlanManager;
use Drupal\user\Entity\User;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\profile\Entity\Profile;

class ChargebeeWebhookController extends ControllerBase {
  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Plan manager service.
   *
   * @var \Drupal\chargebee_status_sync\Service\PlanManager
   */
  protected PlanManager $planManager;

  /**
   * Constructs a ChargebeeWebhookController object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, PlanManager $plan_manager) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('chargebee_status_sync');
    $this->planManager = $plan_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('chargebee_status_sync.plan_manager')
    );
  }

  /**
   * Listener for Chargebee webhook.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   * @param string $token
   *   The token from the URL.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function listener(Request $request, $token) {
    // Retrieve the configuration.
    $config = $this->configFactory->get('chargebee_status_sync.settings');
    $stored_token = $config->get('chargebee_status_sync_token');

    // Verify the token.
    if (!hash_equals((string) $stored_token, (string) $token)) {
      $this->logger->error('Access denied: Invalid token.');
      return new Response('Access denied: Invalid token.', 403);
    }

    // Decode the incoming data.
    $data = json_decode($request->getContent(), TRUE);

    // Log the raw JSON payload for debugging.
    $this->logger->notice('Received raw webhook payload: @payload', ['@payload' => $request->getContent()]);

    if (empty($data) || !isset($data['event_type'])) {
      $this->logger->error('Invalid data received in the webhook.');
      return new Response('Invalid data.', 400);
    }

    // Log the received event type.
    // $this->logger->notice('Received event type: @event_type', ['@event_type' => $data['event_type']]);

    // Get the Chargebee customer ID from the event data.
    $customer_id = $data['content']['customer']['id'] ?? NULL;
    if (!$customer_id) {
      $this->logger->error('Customer ID not found in the event data.');
      return new Response('Customer ID not found.', 400);
    }

// Handle different event types.
switch ($data['event_type']) {
  case 'subscription_scheduled_pause_removed':
      $this->logger->notice('Handling scheduled pause removed for customer ID: @customer_id', ['@customer_id' => $customer_id]);
      $this->updateUserFields($customer_id, FALSE);
      break;

  case 'subscription_paused':
      $this->logger->notice('Handling subscription paused for customer ID: @customer_id', ['@customer_id' => $customer_id]);
      $this->updateUserFields($customer_id, TRUE);
      break;

  case 'subscription_resumed':
      $this->logger->notice('Handling subscription resumed for customer ID: @customer_id', ['@customer_id' => $customer_id]);
      $this->updateUserFields($customer_id, FALSE);
      break;

    case 'subscription_cancelled':
      $user = $this->getUserByCustomerId($customer_id);
      $profile = $this->getUserProfileByCustomerId($customer_id);
      $end_date = date('Y-m-d', $data['content']['subscription']['current_term_end']);
      $cancel_reason = $data['content']['subscription']['cancel_reason_code'] ?? 'Unknown';
      $this->logger->notice('Handling subscription cancelled for customer ID: @customer_id', ['@customer_id' => $customer_id]);
    
      // Update cancellation fields in the profile.
      $this->updateSubscriptionFields($customer_id, $end_date, $cancel_reason);
    
      // Clear the pause field if the user exists.
     if ($user) {
         $this->clearUserPauseField($user);
                }
    
        // Remove the member role.
        $this->removeMemberRole($customer_id);
    
        break;
    
            case 'subscription_created':
            case 'subscription_updated':
            case 'subscription_reactivated':
                $plan_amount_cents = $data['content']['subscription']['plan_amount'] ?? NULL;
                $plan_amount = $plan_amount_cents !== NULL ? $plan_amount_cents / 100 : NULL;
                $plan_id = $data['content']['subscription']['plan_id'] ?? 'Unknown';
                $currency = $data['content']['subscription']['currency_code'] ?? NULL;
                $user = $this->getUserByCustomerId($customer_id);
                $plan_term = NULL;

                if ($plan_amount !== NULL) {
                    $this->logger->notice('Handling subscription event for customer ID: @customer_id with plan amount: @amount', [
                        '@customer_id' => $customer_id,
                        '@amount' => $plan_amount,
                    ]);
                    $this->updateMonthlyPaymentField($customer_id, $plan_amount);
                }
                else {
                    $this->logger->warning('No plan amount found in webhook for customer ID: @customer_id', [
                        '@customer_id' => $customer_id,
                    ]);
                }

                if (!empty($plan_id)) {
                    $plan_term = $this->planManager->upsertPlan($plan_id, [
                        'amount' => $plan_amount,
                        'currency' => $currency,
                        'provider' => 'chargebee',
                    ]);
                }

                $this->logger->notice('Updating plan for customer ID: @customer_id to: @plan_id', [
                    '@customer_id' => $customer_id,
                    '@plan_id' => $plan_id,
                ]);
                $this->updateUserPlan($customer_id, $plan_id);

                if ($plan_term && $user) {
                    $this->planManager->assignMembershipTypeToUser($user, $plan_term);
                }

                if ($data['event_type'] === 'subscription_reactivated') {
                    $this->clearCancellationFields($customer_id);
                    if ($user) {
                        $this->clearUserPauseField($user);
                    }
                }

                break;

  case 'payment_failed':
      $this->logger->notice('Handling payment failed for customer ID: @customer_id', ['@customer_id' => $customer_id]);
      $this->updateUserFields($customer_id, NULL, TRUE);
      break;

  case 'payment_succeeded':
      $this->logger->notice('Handling payment succeeded for customer ID: @customer_id', ['@customer_id' => $customer_id]);
      $this->updateUserFields($customer_id, NULL, FALSE);
      break;

  default:
      $this->logger->warning('Unhandled event type: @event_type for customer ID: @customer_id', [
          '@event_type' => $data['event_type'],
          '@customer_id' => $customer_id,
      ]);
      break;
}

return new Response('Webhook received successfully.', 200);


  }


  protected function updateSubscriptionFields($customer_id, $end_date, $reason) {
    $profile = $this->getUserProfileByCustomerId($customer_id);
    if ($profile) {
        // Use the correct field from the webhook data.
        $mapped_reason = $this->mapCancellationReason($reason);

        // Log the raw and mapped cancellation reason.
        $this->logger->notice('Received raw cancellation reason: @raw_reason', ['@raw_reason' => $reason]);
        $this->logger->notice('Mapped cancellation reason: @mapped_reason', ['@mapped_reason' => $mapped_reason]);

        $profile->set('field_member_end_date', $end_date);
        $profile->set('field_member_end_reason', $mapped_reason);
        $profile->save();

       // $this->logger->info('Updated cancellation fields for profile @id', ['@id' => $profile->id()]);
    } else {
        $this->logger->warning('No profile found for Chargebee ID @customer_id', ['@customer_id' => $customer_id]);
    }
}




protected function removeMemberRole($customer_id) {
  $user = $this->getUserByCustomerId($customer_id);
  if ($user) {
      $member_role_id = \Drupal::config('chargebee_status_sync.settings')->get('chargebee_status_member_role');
      if ($member_role_id && $user->hasRole($member_role_id)) {
          $this->logger->notice('Removing Member Role for user ID: @user_id', ['@user_id' => $user->id()]);
          $user->removeRole($member_role_id);
          $user->save();
      }
  } else {
      $this->logger->error('No user found for Chargebee customer ID: @customer_id', ['@customer_id' => $customer_id]);
  }
}

protected function clearUserPauseField(User $user) {
    if ($user->hasField('field_chargebee_payment_pause') && !$user->get('field_chargebee_payment_pause')->isEmpty()) {
        $this->logger->notice('Clearing pause status for user ID: @user_id', ['@user_id' => $user->id()]);
        $user->set('field_chargebee_payment_pause', 0);
        $user->save();
    }
}


protected function getUserProfileByCustomerId($customer_id) {
  // Load the user by Chargebee customer ID.
  $query = \Drupal::entityQuery('user')
      ->condition('field_user_chargebee_id', $customer_id) // Correct field name here
      ->range(0, 1)
      ->accessCheck(FALSE); // Disable access checks for this query.

  $uids = $query->execute();

  if (!empty($uids)) {
      $user = \Drupal\user\Entity\User::load(reset($uids));
      // Load the profile entity.
      $profiles = \Drupal::entityTypeManager()
          ->getStorage('profile')
          ->loadByProperties([
              'uid' => $user->id(),
              'type' => 'main', // Adjust 'main' to your profile type.
          ]);

      if (!empty($profiles)) {
          return reset($profiles); // Return the first profile of the type.
      }
  }

  // Log a warning if no profile is found.
  $this->logger->warning('No profile found for Chargebee ID @id', ['@id' => $customer_id]);
  return NULL;
}


protected function clearCancellationFields($customer_id) {
  $profile = $this->getUserProfileByCustomerId($customer_id);
  if ($profile) {
      $profile_modified = FALSE;

      // Clear the end date field.
      if ($profile->hasField('field_member_end_date') && !$profile->get('field_member_end_date')->isEmpty()) {
          $this->logger->notice('Clearing end date for profile ID: @profile_id', ['@profile_id' => $profile->id()]);
          $profile->set('field_member_end_date', NULL);
          $profile_modified = TRUE;
      }

      // Clear the end reason field.
      if ($profile->hasField('field_member_end_reason') && !$profile->get('field_member_end_reason')->isEmpty()) {
          $this->logger->notice('Clearing end reason for profile ID: @profile_id', ['@profile_id' => $profile->id()]);
          $profile->set('field_member_end_reason', NULL);
          $profile_modified = TRUE;
      }

      // Set the reactivation date field.
      if ($profile->hasField('field_member_reactivation_date')) {
          $reactivation_date = date('Y-m-d', time()); // Current date
          $profile->set('field_member_reactivation_date', $reactivation_date);
          $this->logger->notice('Setting reactivation date for profile ID: @profile_id to: @date', [
              '@profile_id' => $profile->id(),
              '@date' => $reactivation_date,
          ]);
          $profile_modified = TRUE;
      }

      // Clear the pause field if it exists.
      if ($profile->hasField('field_chargebee_payment_pause') && !$profile->get('field_chargebee_payment_pause')->isEmpty()) {
          $this->logger->notice('Clearing pause status for profile ID: @profile_id', ['@profile_id' => $profile->id()]);
          $profile->set('field_chargebee_payment_pause', 0);
          $profile_modified = TRUE;
      }

      // Add Member Role.
      $user = $profile->getOwner();
      $member_role_id = \Drupal::config('chargebee_status_sync.settings')->get('chargebee_status_member_role');
      if ($member_role_id && !$user->hasRole($member_role_id)) {
          $this->logger->notice('Adding Member Role for user ID: @user_id', ['@user_id' => $user->id()]);
          $user->addRole($member_role_id);
          $user->save();
      }

      // Save the profile if it was modified.
      if ($profile_modified) {
          $profile->setNewRevision(TRUE);
          $profile->setRevisionLogMessage('Cleared membership end date, reason, and pause status; reactivation date set.');
          $profile->save();
          $this->logger->info('Updated cancellation fields for profile ID: @profile_id', ['@profile_id' => $profile->id()]);
      }
  } else {
      $this->logger->warning('No profile found for Chargebee ID @customer_id', ['@customer_id' => $customer_id]);
  }
}


protected function mapCancellationReason($cancel_reason) {
  // Default to "unknown" if no reason is provided.
  if (empty($cancel_reason)) {
      return 'unknown';
  }

  // Normalize the reason for partial matching (lowercase and trim).
  $normalized_reason = strtolower(trim($cancel_reason));

  // Map Chargebee reasons to machine names using unique phrases.
  $reason_map = [
      'time' => 'time',                           // Matches "Time Constraints".
      'engage' => 'engagement',                   // Matches "Engagement (Lack of)".
      'financ' => 'cost',                         // Matches "Financial Considerations".
      'relocat' => 'relocation',                  // Matches "Relocation".
      'equip' => 'equipment',                     // Matches "Facility & Equipment Insufficiencies".
      'manag' => 'management',                    // Matches "Management & Communication Dissatisfaction".
      'commu' => 'community',                     // Matches "Community/Culture Dissatisfaction".
      'orient' => 'orientation',                  // Matches "Orientation, Did Not Complete".
      'term' => 'predefined',                     // Matches "Term/Project (planned) Completed".
      'payment' => '3rdparty',                    // Matches "Payment End (External Employer/Program)".
      'discip' => 'removed',                      // Matches "Disciplinary Removal".
      'other' => 'other',                         // Matches "Other".
  ];

  // Iterate through the map and find the first partial match.
  foreach ($reason_map as $keyword => $mapped_reason) {
      if (strpos($normalized_reason, $keyword) !== FALSE) {
          return $mapped_reason;
      }
  }

  // Default to "unknown" if no match is found.
  return 'unknown';
}


protected function getUserByCustomerId($customer_id) {
  $query = \Drupal::entityQuery('user')
      ->condition('field_user_chargebee_id', $customer_id) // Ensure this field matches your configuration.
      ->range(0, 1)
      ->accessCheck(FALSE);

  $uids = $query->execute();

  if (!empty($uids)) {
      return User::load(reset($uids));
  }

  $this->logger->warning('No user found with Chargebee customer ID: @customer_id', ['@customer_id' => $customer_id]);
  return NULL;
}


protected function updateUserPlan($customer_id, $plan_id) {
    // Load the user by Chargebee customer ID.
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    $users = $user_storage->loadByProperties(['field_user_chargebee_id' => $customer_id]);

    if (!empty($users)) {
        /** @var \Drupal\user\Entity\User $user */
        $user = reset($users);

        // Update the plan field.
        if ($user->hasField('field_user_chargebee_plan')) {
            $user->set('field_user_chargebee_plan', $plan_id);
            $user->save();

            // Log the update.
            $this->logger->info('Updated plan field for user ID: @uid to: @plan_id', [
                '@uid' => $user->id(),
                '@plan_id' => $plan_id,
            ]);
        }
    } else {
        $this->logger->warning('No user found for Chargebee ID: @customer_id', ['@customer_id' => $customer_id]);
    }
}


protected function updateMonthlyPaymentField($customer_id, $plan_amount) {
  $profile = $this->getUserProfileByCustomerId($customer_id);

  if ($profile) {
      if ($profile->hasField('field_member_payment_monthly')) {
          $this->logger->notice('Updating monthly payment for profile ID: @profile_id to: @amount', [
              '@profile_id' => $profile->id(),
              '@amount' => $plan_amount,
          ]);
          $profile->set('field_member_payment_monthly', $plan_amount);
          $profile->setNewRevision(TRUE);
          $profile->setRevisionLogMessage('Updated monthly payment to ' . $plan_amount);
          $profile->save();
      } else {
          $this->logger->warning('Profile ID: @profile_id does not have field_member_payment_monthly', [
              '@profile_id' => $profile->id(),
          ]);
      }
  } else {
      $this->logger->error('No profile found for customer ID: @customer_id', ['@customer_id' => $customer_id]);
  }
}


protected function clearReactivationFields($profile) {
  $modified = FALSE;

  if ($profile->hasField('field_member_end_date')) {
      $profile->set('field_member_end_date', NULL);
      $modified = TRUE;
  }

  if ($profile->hasField('field_member_end_reason')) {
      $profile->set('field_member_end_reason', NULL);
      $modified = TRUE;
  }

  if ($profile->hasField('field_chargebee_payment_pause')) {
      $profile->set('field_chargebee_payment_pause', 0);
      $modified = TRUE;
  }

  return $modified;
}



  /**
   * Method to update the user's field based on subscription status.
   *
   * @param string $chargebee_customer_id
   *   The Chargebee customer ID.
   * @param bool|null $is_paused
   *   Indicates whether the subscription is paused or not.
   * @param bool|null $payment_failed
   *   Indicates whether the payment has failed or succeeded.
   * @param int|null $end_date
   *   The end date timestamp of the subscription if cancelled.
   * @param string|null $cancel_reason
   *   The reason for subscription cancellation.
   * @param bool|null $reactivated
   *   Indicates whether the subscription has been reactivated.
   */
  protected function updateUserFields($chargebee_customer_id, $is_paused = NULL, $payment_failed = NULL, $end_date = NULL, $cancel_reason = NULL, $reactivated = NULL) {
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    $users = $user_storage->loadByProperties(['field_user_chargebee_id' => $chargebee_customer_id]);

    if (empty($users)) {
        $this->logger->error('No user found with Chargebee customer ID: @customer_id', ['@customer_id' => $chargebee_customer_id]);
        return;
    }

    /** @var \Drupal\user\Entity\User $user */
    $user = reset($users);
    $modified = FALSE;

    // Update pause status.
    if ($is_paused !== NULL) {
        $this->logger->notice('Updating pause status for user ID: @user_id to: @status', [
            '@user_id' => $user->id(),
            '@status' => $is_paused ? 'paused' : 'active',
        ]);
        $user->set('field_chargebee_payment_pause', $is_paused ? 1 : 0);
        $modified = TRUE;
    }

    // Update payment status.
    if ($payment_failed !== NULL) {
        $this->logger->notice('Updating payment status for user ID: @user_id to: @status', [
            '@user_id' => $user->id(),
            '@status' => $payment_failed ? 'failed' : 'succeeded',
        ]);
        $user->set('field_payment_failed', $payment_failed ? 1 : 0);
        $modified = TRUE;
    }

    // Load the user's profile to update additional fields.
    $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
    $profiles = $profile_storage->loadByProperties(['uid' => $user->id(), 'type' => 'main']);

    if (!empty($profiles)) {
        /** @var \Drupal\profile\Entity\Profile $profile */
        $profile = reset($profiles);

        if ($reactivated) {
            // Handle reactivation.
            $this->logger->notice('Clearing reactivation fields for profile ID: @profile_id', ['@profile_id' => $profile->id()]);
            $this->clearReactivationFields($profile);
            $profile->save();
        }

        if ($end_date !== NULL) {
            // Handle cancellation fields.
            $profile->set('field_member_end_date', $end_date);
            $mapped_reason = $this->mapCancellationReason($cancel_reason);
            $profile->set('field_member_end_reason', $mapped_reason);
            $this->logger->notice('Set cancellation fields for profile ID: @profile_id', ['@profile_id' => $profile->id()]);
            $profile->save();
        }
    }

    // Add or remove Member Role based on reactivation or cancellation.
    $member_role_id = \Drupal::config('chargebee_status_sync.settings')->get('chargebee_status_member_role');
    if ($member_role_id) {
        // Add role for reactivation.
        if ($reactivated && !$user->hasRole($member_role_id)) {
            $this->logger->notice('Adding Member Role for user ID: @user_id during reactivation', ['@user_id' => $user->id()]);
            $user->addRole($member_role_id);
            $modified = TRUE;
        }

        // Remove role for cancellation.
        if ($end_date !== NULL && $user->hasRole($member_role_id)) {
            $this->logger->notice('Removing Member Role for user ID: @user_id due to cancellation', ['@user_id' => $user->id()]);
            $user->removeRole($member_role_id);
            $modified = TRUE;
        }
    } else {
        $this->logger->warning('Member Role ID not configured. Skipping role update.');
    }

    // Save user changes.
    if ($modified) {
        $user->save();
    }
}


}
