<?php

declare(strict_types=1);

namespace Drupal\change_proposals\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Which entity types accept proposals, expiry, notifications.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'change_proposals_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['change_proposals.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('change_proposals.settings');
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition instanceof ContentEntityTypeInterface && $definition->getBaseTable() && !$definition->isInternal() && $id !== 'change_proposal') {
        $options[$id] = $definition->getLabel();
      }
    }
    asort($options);
    $form['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Entity types that accept proposals'),
      '#options' => $options,
      '#default_value' => $config->get('entity_types') ?? ['node'],
    ];
    $form['blocked_fields'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fields that can never be proposed'),
      '#default_value' => implode("\n", $config->get('blocked_fields') ?? []),
      '#description' => $this->t('One field machine name per line. Entity keys (id, uuid, bundle, owner, revision metadata) are always blocked.'),
      '#rows' => 6,
    ];
    $form['expire_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Unreviewed proposals expire after'),
      '#field_suffix' => $this->t('days'),
      '#default_value' => $config->get('expire_days') ?? 14,
      '#min' => 1,
      '#max' => 365,
    ];
    $form['notify_on_new'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email reviewers when a proposal arrives'),
      '#default_value' => $config->get('notify_on_new') ?? TRUE,
    ];
    $form['notify_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reviewer email(s)'),
      '#default_value' => $config->get('notify_email') ?? '',
      '#description' => $this->t('Comma-separated. Empty: the site email address.'),
    ];
    $form['webhook_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Webhook URL'),
      '#default_value' => $config->get('webhook_url') ?? '',
      '#description' => $this->t('Receives a JSON POST on proposal.created / approved / rejected / failed / expired. Slack incoming webhooks work as-is.'),
    ];
    $form['webhook_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook bearer token'),
      '#default_value' => $config->get('webhook_token') ?? '',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $form_state->getValue('blocked_fields')) ?: []), 'strlen'));
    $this->config('change_proposals.settings')
      ->set('entity_types', array_values(array_filter($form_state->getValue('entity_types'))))
      ->set('blocked_fields', $lines)
      ->set('expire_days', (int) $form_state->getValue('expire_days'))
      ->set('notify_on_new', (bool) $form_state->getValue('notify_on_new'))
      ->set('notify_email', trim((string) $form_state->getValue('notify_email')))
      ->set('webhook_url', trim((string) $form_state->getValue('webhook_url')))
      ->set('webhook_token', trim((string) $form_state->getValue('webhook_token')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
