```
# Chargebee Status Sync

Introduction

This Drupal 10 module provides a mechanism to synchronize subscription and payment status updates from Chargebee to your Drupal site. It listens for webhooks from Chargebee and updates user roles, and user/profile fields accordingly.

Requirements

* Drupal 10
* Field Module (core)
* Profile Module (contrib) - Implied by the controller logic that interacts with profile entities.
* User fields for storing Chargebee customer ID, plan, and other statuses.
* Profile fields for storing membership end date, end reason, reactivation date, and monthly payment.

Installation

1.  Place the `chargebee_status_sync` module in your `modules/custom` directory.
2.  Enable the module through the Drupal UI or by using Drush: drush en chargebee_status_sync

Configuration

1.  Navigate to the configuration page at /admin/config/services/chargebee_portal/status-sync or by going to "Administration" -> "Configuration" -> "Web services" and clicking on "Chargebee Status Sync".
2.  Member Role: Select the role that will be assigned to active subscribers and removed upon cancellation.
3.  Notification Email: Enter an email address to receive notifications in case of webhook processing errors. Leave blank to disable notifications.
4.  Enable Basic Authentication for webhook: Check this box if you want to use Basic Authentication for the webhook endpoint.
5.  Webhook User: The Drupal username that Chargebee will use for webhook authentication.
6.  Webhook Token: A security token for the webhook URL. You can generate a new one using the "Generate Token" button.
7.  Click "Save configuration".

Features

* Role-Based Access Control: Automatically assigns and removes a specified member role based on the user's subscription status.
* User Field Updates: Updates user fields to reflect the current subscription and payment status from Chargebee. This includes:
    * Pause status
    * Payment failure status
    * Chargebee plan ID
* Profile Field Updates: Updates profile fields with details about subscription changes, including:
    * Membership end date and cancellation reason.
    * Membership reactivation date.
    * Monthly payment amount.
* Webhook Handling: Listens for a variety of Chargebee webhook events to keep user data in sync. Handled events include:
    * subscription_created
    * subscription_updated
    * subscription_reactivated
    * subscription_cancelled
    * subscription_paused
    * subscription_resumed
    * subscription_scheduled_pause_removed
    * payment_succeeded
    * payment_failed
* Logging: Provides detailed logging for incoming webhooks, processed events, and any errors that occur, which can be viewed in Drupal's log reports.
* Secure Webhook Endpoint: Uses a token-based URL to secure the webhook listener and offers an option for Basic Authentication.

Webhook Usage

After configuring the module, you need to set up a webhook in your Chargebee account.

1.  In your Chargebee account, go to the webhooks settings.
2.  Create a new webhook.
3.  For the "Webhook URL", use the URL displayed on the module's settings page, which will be in the format: https://your-drupal-site.com/chargebee-webhook/{token}.
4.  If you enabled Basic Authentication in the module settings, you will also need to configure the "Webhook User" and the user's password in the Chargebee webhook settings.
5.  Configure the webhook to send the events listed in the "Features" section.
6.  Save the webhook in Chargebee.
```
