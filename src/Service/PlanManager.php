<?php

namespace Drupal\chargebee_status_sync\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;

/**
 * Manages taxonomy terms that represent billing plans.
 */
class PlanManager {

  /**
   * Vocabulary machine name for plan terms.
   */
  protected const VOCABULARY = 'billing_plan';

  /**
   * Field machine name containing the external plan identifier.
   */
  protected const PLAN_ID_FIELD = 'field_plan_id';

  /**
   * Field storing plan amount.
   */
  protected const PLAN_AMOUNT_FIELD = 'field_plan_amount';

  /**
   * Field storing currency code.
   */
  protected const PLAN_CURRENCY_FIELD = 'field_plan_currency';

  /**
   * Field storing provider label.
   */
  protected const PLAN_PROVIDER_FIELD = 'field_plan_provider';

  /**
   * Field storing linked membership type.
   */
  protected const PLAN_MEMBERSHIP_FIELD = 'field_membership_type';

  /**
   * Target profile bundle.
   */
  protected const PROFILE_TYPE = 'main';

  /**
   * Membership field on the profile entity.
   */
  protected const PROFILE_MEMBERSHIP_FIELD = 'field_membership_type';

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Logger channel.
   */
  protected $logger;

  /**
   * Constructs the plan manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('chargebee_status_sync');
  }

  /**
   * Create or update a taxonomy term representing a plan.
   */
  public function upsertPlan(string $plan_id, array $metadata = []): ?TermInterface {
    if (empty($plan_id)) {
      return NULL;
    }

    $term = $this->loadPlanByIdentifier($plan_id);
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $changed = FALSE;

    if (!$term) {
      $values = [
        'vid' => static::VOCABULARY,
        'name' => $metadata['label'] ?? $plan_id,
        static::PLAN_ID_FIELD => $plan_id,
      ];
      $term = $storage->create($values);
      $changed = TRUE;
    }

    if (!empty($metadata['label']) && $term->label() !== $metadata['label']) {
      $term->setName($metadata['label']);
      $changed = TRUE;
    }

    if (isset($metadata['provider']) && $term->hasField(static::PLAN_PROVIDER_FIELD)) {
      $provider = (string) $metadata['provider'];
      if ($term->get(static::PLAN_PROVIDER_FIELD)->value !== $provider) {
        $term->set(static::PLAN_PROVIDER_FIELD, $provider);
        $changed = TRUE;
      }
    }

    if (isset($metadata['currency']) && $term->hasField(static::PLAN_CURRENCY_FIELD)) {
      $currency = strtoupper($metadata['currency']);
      if ($term->get(static::PLAN_CURRENCY_FIELD)->value !== $currency) {
        $term->set(static::PLAN_CURRENCY_FIELD, $currency);
        $changed = TRUE;
      }
    }

    if (isset($metadata['amount']) && $term->hasField(static::PLAN_AMOUNT_FIELD)) {
      $amount = number_format((float) $metadata['amount'], 2, '.', '');
      if ($term->get(static::PLAN_AMOUNT_FIELD)->value !== $amount) {
        $term->set(static::PLAN_AMOUNT_FIELD, $amount);
        $changed = TRUE;
      }
    }

    if ($changed) {
      $term->save();
    }

    return $term;
  }

  /**
   * Apply the plan's membership mapping to the user's profile.
   */
  public function assignMembershipTypeToUser(UserInterface $user, TermInterface $plan_term): void {
    if (!$plan_term->hasField(static::PLAN_MEMBERSHIP_FIELD) || $plan_term->get(static::PLAN_MEMBERSHIP_FIELD)->isEmpty()) {
      $this->logger->notice('Plan @plan has no membership type mapping.', ['@plan' => $plan_term->label()]);
      return;
    }

    $membership_target = (int) $plan_term->get(static::PLAN_MEMBERSHIP_FIELD)->target_id;
    if (empty($membership_target)) {
      return;
    }

    $this->applyMembershipTypeToUser($user, $membership_target, $plan_term->label());
  }

  /**
   * Apply the mapped membership type for a plan ID to the user's profile.
   */
  public function assignMembershipTypeByPlanId(UserInterface $user, string $plan_id, bool $log_missing = TRUE): bool {
    $plan_term = $this->getPlanTermForPlanId($plan_id);
    if (!$plan_term instanceof TermInterface) {
      if ($log_missing) {
        $this->logger->notice('No membership type mapping found for plan ID @plan.', ['@plan' => $plan_id]);
      }
      return FALSE;
    }

    if (!$plan_term->hasField(static::PLAN_MEMBERSHIP_FIELD) || $plan_term->get(static::PLAN_MEMBERSHIP_FIELD)->isEmpty()) {
      if ($log_missing) {
        $this->logger->notice('Plan @plan has no membership type mapping.', ['@plan' => $plan_term->label()]);
      }
      return FALSE;
    }

    $membership_target = (int) $plan_term->get(static::PLAN_MEMBERSHIP_FIELD)->target_id;
    if (empty($membership_target)) {
      return FALSE;
    }

    return $this->applyMembershipTypeToUser($user, $membership_target, $plan_term->label());
  }

  /**
   * Apply a membership type to the user's main profile.
   */
  protected function applyMembershipTypeToUser(UserInterface $user, int $membership_target, string $context): bool {
    $profile_storage = $this->entityTypeManager->getStorage('profile');
    $profiles = $profile_storage->loadByProperties([
      'uid' => $user->id(),
      'type' => static::PROFILE_TYPE,
    ]);

    if (empty($profiles)) {
      $this->logger->warning('No main profile found for user @uid when applying membership type.', ['@uid' => $user->id()]);
      return FALSE;
    }

    /** @var \Drupal\profile\Entity\ProfileInterface $profile */
    $profile = reset($profiles);
    if (!$profile instanceof ProfileInterface || !$profile->hasField(static::PROFILE_MEMBERSHIP_FIELD)) {
      $this->logger->warning('Profile for user @uid is missing the membership field.', ['@uid' => $user->id()]);
      return FALSE;
    }

    $current_value = $profile->get(static::PROFILE_MEMBERSHIP_FIELD)->target_id;
    if ((int) $current_value === (int) $membership_target) {
      return FALSE;
    }

    $profile->set(static::PROFILE_MEMBERSHIP_FIELD, $membership_target);
    $profile->save();
    $this->logger->info('Updated membership type for user @uid based on plan @plan.', [
      '@uid' => $user->id(),
      '@plan' => $context,
    ]);
    return TRUE;
  }

  /**
   * Helper to load the taxonomy term for a plan identifier.
   */
  protected function loadPlanByIdentifier(string $plan_id): ?TermInterface {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', static::VOCABULARY)
      ->condition(static::PLAN_ID_FIELD, $plan_id)
      ->range(0, 1)
      ->execute();

    if (!$tids) {
      return NULL;
    }

    /** @var \Drupal\taxonomy\TermInterface $term */
    $term = $storage->load(reset($tids));
    return $term instanceof TermInterface ? $term : NULL;
  }

  /**
   * Load the plan term for a Chargebee plan ID.
   */
  public function getPlanTermForPlanId(string $plan_id): ?TermInterface {
    return $this->loadPlanByIdentifier($plan_id);
  }

  /**
   * Get the membership type target ID from a plan term.
   */
  public function getMembershipTypeTargetIdFromPlanTerm(TermInterface $plan_term): ?int {
    if (!$plan_term->hasField(static::PLAN_MEMBERSHIP_FIELD) || $plan_term->get(static::PLAN_MEMBERSHIP_FIELD)->isEmpty()) {
      return NULL;
    }
    $target = (int) $plan_term->get(static::PLAN_MEMBERSHIP_FIELD)->target_id;
    return $target ?: NULL;
  }

}
