<?php

namespace Drupal\chargebee_status_sync\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\chargebee_status_sync\Service\PlanManager;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Chargebee status sync.
 */
class ChargebeeStatusSyncCommands extends DrushCommands {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The plan manager service.
   */
  protected PlanManager $planManager;

  /**
   * Constructs the commands service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, PlanManager $plan_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->planManager = $plan_manager;
  }

  /**
   * Dry-run or apply membership type mapping for a single user.
   *
   * @command chargebee:sync-membership-type
   * @option uid User ID to check.
   * @option apply Apply the membership type change.
   * @usage drush chargebee:sync-membership-type --uid=5417
   * @usage drush chargebee:sync-membership-type --uid=5417 --apply
   */
  public function syncMembershipType(array $options = [
    'uid' => NULL,
    'apply' => FALSE,
  ]): void {
    $uid = isset($options['uid']) ? (int) $options['uid'] : 0;
    $apply = !empty($options['apply']);

    if ($uid <= 0) {
      $this->logger()->error('Please provide a valid --uid.');
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      $this->logger()->error('User {uid} not found.', ['uid' => $uid]);
      return;
    }

    if (!$user->hasField('field_user_chargebee_plan') || $user->get('field_user_chargebee_plan')->isEmpty()) {
      $this->logger()->warning('User {uid} has no Chargebee plan ID.', ['uid' => $uid]);
      return;
    }

    $plan_id = trim((string) $user->get('field_user_chargebee_plan')->value);
    if ($plan_id === '') {
      $this->logger()->warning('User {uid} has an empty Chargebee plan ID.', ['uid' => $uid]);
      return;
    }

    $plan_term = $this->planManager->getPlanTermForPlanId($plan_id);
    if (!$plan_term) {
      $this->logger()->warning('No billing_plan term found for plan ID {plan}.', ['plan' => $plan_id]);
      return;
    }

    $target_tid = $this->planManager->getMembershipTypeTargetIdFromPlanTerm($plan_term);
    if (!$target_tid) {
      $this->logger()->warning('Plan term {plan} has no membership type mapping.', ['plan' => $plan_term->label()]);
      return;
    }

    $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
      'uid' => $uid,
      'type' => 'main',
    ]);
    if (empty($profiles)) {
      $this->logger()->warning('No main profile found for user {uid}.', ['uid' => $uid]);
      return;
    }

    $profile = reset($profiles);
    if (!$profile->hasField('field_membership_type')) {
      $this->logger()->warning('Profile for user {uid} is missing field_membership_type.', ['uid' => $uid]);
      return;
    }

    $current_tid = (int) $profile->get('field_membership_type')->target_id;
    if ($current_tid === $target_tid) {
      $this->logger()->notice('No change needed. User {uid} already set to membership type {tid}.', [
        'uid' => $uid,
        'tid' => $target_tid,
      ]);
      return;
    }

    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($target_tid);
    $label = $term ? $term->label() : (string) $target_tid;

    if ($apply) {
      $profile->set('field_membership_type', $target_tid);
      $profile->save();
      $this->logger()->notice('Updated user {uid} membership type to {label} ({tid}).', [
        'uid' => $uid,
        'label' => $label,
        'tid' => $target_tid,
      ]);
      return;
    }

    $this->logger()->notice('Dry run: would update user {uid} membership type to {label} ({tid}). Current {current}.', [
      'uid' => $uid,
      'label' => $label,
      'tid' => $target_tid,
      'current' => $current_tid ?: 'none',
    ]);
  }

}
