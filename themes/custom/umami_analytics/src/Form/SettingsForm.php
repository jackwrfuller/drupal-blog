<?php

namespace Drupal\umami_analytics\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\umami_analytics\JavascriptLocalCache;
use Drupal\user\RoleInterface;
use Drupal\user\Entity\Role;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Umami_Analytics settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The umami analytics local javascript cache manager.
   *
   * @var JavascriptLocalCache
   */
  protected JavascriptLocalCache $uaJavascript;

  /**
   * The constructor method.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param JavascriptLocalCache $umami_analytics_javascript
   *   The JS Local Cache service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typedConfigManager, JavascriptLocalCache $umami_analytics_javascript) {
    parent::__construct($config_factory, $typedConfigManager);
    $this->uaJavascript = $umami_analytics_javascript;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('umami_analytics.javascript_cache')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'umami_analytics_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['umami_analytics.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('umami_analytics.settings');

    $form['src'] = [
      '#title' => $this->t('Umami URL'),
      '#type' => 'textfield',
      '#default_value' => $config->get('src'),
      '#attributes' => [
        'placeholder' => 'https://mywebsite/umami.js',
      ],
    ];

    $form['website_id'] = [
      '#title' => $this->t('Web site ID'),
      '#type' => 'textfield',
      '#default_value' => $config->get('website_id'),
      '#attributes' => [
        'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
      ],
    ];

    // Visibility settings.
    $form['tracking_scope'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Tracking scope'),
    ];

    $form['tracking']['domain_tracking'] = [
      '#type' => 'details',
      '#title' => $this->t('Domains'),
      '#group' => 'tracking_scope',
    ];

    $multiple_domains = [];
    foreach (['.com', '.net', '.org'] as $tldomain) {
      $host = $_SERVER['HTTP_HOST'];
      $domain = substr($host, 0, strrpos($host, '.'));
      if (count(explode('.', $host)) > 2 && !is_numeric(str_replace('.', '', $host))) {
        $multiple_domains[] = $domain . $tldomain;
      }
      // IP addresses or localhost.
      else {
        $multiple_domains[] = 'www.example' . $tldomain;
      }
    }

    $form['tracking']['domain_tracking']['domain_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('What are you tracking?'),
      '#options' => [
        0 => $this->t('A single domain (default)'),
        1 => $this->t('Multiple domains'),
      ],
      0 => [
        '#description' => $this->t('Domain: @domain', ['@domain' => $_SERVER['HTTP_HOST']]),
      ],
      1 => [
        '#description' => $this->t('Examples: @domains', ['@domains' => implode(', ', $multiple_domains)]),
      ],
      '#default_value' => $config->get('domain_mode'),
    ];
    $form['tracking']['domain_tracking']['domains'] = [
      '#title' => $this->t('List of domains'),
      '#type' => 'textfield',
      '#default_value' => $config->get('domains'),
      '#description' => $this->t('If you selected "Multiple domains" above, enter all related domains separated by comma.'),
      '#states' => [
        'enabled' => [
          ':input[name="domain_mode"]' => ['value' => '1'],
        ],
        'required' => [
          ':input[name="domain_mode"]' => ['value' => '1'],
        ],
      ],
    ];

    // Page specific visibility configurations.
    $form['tracking']['page_visibility_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Pages'),
      '#group' => 'tracking_scope',
    ];
    $form['tracking']['page_visibility_settings']['visibility_request_path_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Add tracking to specific pages'),
      '#options' => [
        0 => $this->t('Every page except the listed pages'),
        1 => $this->t('The listed pages only'),
      ],
      '#default_value' => $config->get('visibility.request_path_mode'),
    ];
    $visibility_request_path_pages = $config->get('visibility.request_path_pages');
    $form['tracking']['page_visibility_settings']['visibility_request_path_pages'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Pages'),
      '#title_display' => 'invisible',
      '#default_value' => !empty($visibility_request_path_pages) ? $visibility_request_path_pages : '',
      '#description' => $this->t("Specify pages by using their paths. Enter one path per line. The '*' character is a wildcard. Example paths are %blog for the blog page and %blog-wildcard for every personal blog. %front is the front page.",
        ['%blog' => '/blog', '%blog-wildcard' => '/blog/*', '%front' => '<front>']
      ),
      '#rows' => 10,
    ];

    // Render the role overview.
    $form['tracking']['role_visibility_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Roles'),
      '#group' => 'tracking_scope',
    ];
    $form['tracking']['role_visibility_settings']['visibility_user_role_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Add tracking for specific roles'),
      '#options' => [
        0 => $this->t('Add to the selected roles only'),
        1 => $this->t('Add to every role except the selected ones'),
      ],
      '#default_value' => $config->get('visibility.user_role_mode'),
    ];
    $visibility_user_role_roles = $config->get('visibility.user_role_roles');

    $roles = Role::loadMultiple();
    $role_names =  array_map(fn(RoleInterface $role) => Html::escape($role->label()), $roles);
    $form['tracking']['role_visibility_settings']['visibility_user_role_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#default_value' => !empty($visibility_user_role_roles) ? $visibility_user_role_roles : [],
      '#options' => $role_names,
      '#description' => $this->t('If none of the roles are selected, all users will be tracked. If a user has any of the roles checked, that user will be tracked (or excluded, depending on the setting above).'),
    ];

    return parent::buildForm($form, $form_state);

    // @TODO

    // Advanced feature configurations.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['local_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Locally cache tracking code file'),
      '#description' => $this->t("If checked, the tracking code file is retrieved from Umami Analytics and cached locally. It is updated daily from Umami's servers to ensure updates to tracking code are reflected in the local copy. Do not activate this until after Umami Analytics has confirmed that site tracking is working!"),
      '#default_value' => $config->get('local_cache'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Trim some text values.
    $form_state->setValue('visibility_request_path_pages', trim($form_state->getValue('visibility_request_path_pages')));
    $form_state->setValue('domains', trim($form_state->getValue('domains')));

    $form_state->setValue('visibility_user_role_roles', array_filter($form_state->getValue('visibility_user_role_roles') ?? []));

    // If multiple top-level domains has been selected, a domain names list is
    // required.
    if ($form_state->getValue('domain_mode') == 1 && $form_state->isValueEmpty('domains')) {
      $form_state->setErrorByName('domains', $this->t('A list of top-level domains is required if <em>Multiple top-level domains</em> has been selected.'));
    }

    // Verify that every path is prefixed with a slash, but don't check PHP
    // code snippets and do not check for slashes if no paths configured.
    if ($form_state->getValue('visibility_request_path_mode') != 2 && !empty($form_state->getValue('visibility_request_path_pages'))) {
      $pages = preg_split('/(\r\n?|\n)/', $form_state->getValue('visibility_request_path_pages'));
      foreach ($pages as $page) {
        if (strpos($page, '/') !== 0 && $page !== '<front>') {
          $form_state->setErrorByName('visibility_request_path_pages', $this->t('Path "@page" not prefixed with slash.', ['@page' => $page]));
          // Drupal forms show one error only.
          break;
        }
      }
    }

    // @TODO.
    return;

    // Clear obsolete local cache if cache has been disabled.
    if ($form_state->isValueEmpty('local_cache') && $form['advanced']['local_cache']['#default_value']) {
      $this->gaJavascript->clearJsCache();
      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('umami_analytics.settings');

    $config
      ->set('src', $form_state->getValue('src'))
      ->set('website_id', $form_state->getValue('website_id'))
      ->set('domain_mode', $form_state->getValue('domain_mode'))
      ->set('domains', $form_state->getValue('domains'))
      ->set('visibility.request_path_mode', $form_state->getValue('visibility_request_path_mode'))
      ->set('visibility.request_path_pages', $form_state->getValue('visibility_request_path_pages'))
      ->set('visibility.user_role_mode', $form_state->getValue('visibility_user_role_mode'))
      ->set('visibility.user_role_roles', $form_state->getValue('visibility_user_role_roles'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
