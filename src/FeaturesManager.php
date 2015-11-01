<?php

/**
 * @file
 * Contains \Drupal\features\FeaturesManager.
 */

namespace Drupal\features;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Utility\NestedArray;
use Drupal\features\FeaturesAssignerInterface;
use Drupal\features\FeaturesBundleInterface;
use Drupal\features\FeaturesGeneratorInterface;
use Drupal\features\FeaturesExtensionStorages;
use Drupal\features\FeaturesExtensionStoragesInterface;
use Drupal\features\FeaturesManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The FeaturesManager provides helper functions for building packages.
 */
class FeaturesManager implements FeaturesManagerInterface {
  use StringTranslationTrait;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The target storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

  /**
   * The extension storages.
   *
   * @var \Drupal\features\FeaturesExtensionStoragesInterface
   */
  protected $extensionStorages;

  /**
   * The configuration manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The Features settings.
   *
   * @var array
   */
  protected $settings;

  /**
   * The Features assignment settings.
   *
   * @var array
   */
  protected $assignmentSettings;

  /**
   * The configuration present on the site.
   *
   * @var array
   */
  private $configCollection;

  /**
   * The packages to be generated.
   *
   * @var array
   */
  protected $packages;

  /**
   * The package assigner.
   *
   * @var \Drupal\features\FeaturesAssigner
   */
  protected $assigner;

  /**
   * Constructs a FeaturesManager object.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   The target storage.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   The configuration manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(EntityManagerInterface $entity_manager, ConfigFactoryInterface $config_factory,
                              StorageInterface $config_storage, ConfigManagerInterface $config_manager,
                              ModuleHandlerInterface $module_handler) {
    $this->entityManager = $entity_manager;
    $this->configStorage = $config_storage;
    $this->configManager = $config_manager;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->settings = $config_factory->getEditable('features.settings');
    $this->assignmentSettings = $config_factory->getEditable('features.assignment');
    $this->extensionStorages = new FeaturesExtensionStorages($this->configStorage);
    $this->extensionStorages->addStorage(InstallStorage::CONFIG_INSTALL_DIRECTORY);
    $this->extensionStorages->addStorage(InstallStorage::CONFIG_OPTIONAL_DIRECTORY);
    $this->packages = [];
    $this->configCollection = [];
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveStorage() {
    return $this->configStorage;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionStorages() {
    return $this->extensionStorages;
  }

  /**
   * {@inheritdoc}
   */
  public function getFullName($type, $name) {
    if ($type == FeaturesManagerInterface::SYSTEM_SIMPLE_CONFIG || !$type) {
      return $name;
    }

    $definition = $this->entityManager->getDefinition($type);
    $prefix = $definition->getConfigPrefix() . '.';
    return $prefix . $name;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigType($fullname) {
    $result = array(
      'type' => '',
      'name_short' => '',
    );
    $prefix = FeaturesManagerInterface::SYSTEM_SIMPLE_CONFIG . '.';
    if (strpos($fullname, $prefix)) {
      $result['type'] = FeaturesManagerInterface::SYSTEM_SIMPLE_CONFIG;
      $result['name_short'] = substr($fullname, strlen($prefix));
    }
    else {
      foreach ($this->entityManager->getDefinitions() as $entity_type => $definition) {
        if ($definition->isSubclassOf('Drupal\Core\Config\Entity\ConfigEntityInterface')) {
          $prefix = $definition->getConfigPrefix() . '.';
          if (strpos($fullname, $prefix) === 0) {
            $result['type'] = $entity_type;
            $result['name_short'] = substr($fullname, strlen($prefix));
          }
        }
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function reset() {
    $this->packages = [];
    // Don't use getConfigCollection because reset() may be called in
    // cases where we don't need to load config.
    foreach ($this->configCollection as &$config) {
      $config['package'] = NULL;
    }
    // Clean up the $config pass by reference.
    unset($config);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigCollection($reset = FALSE) {
    $this->initConfigCollection($reset);
    return $this->configCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfigCollection(array $config_collection) {
    $this->configCollection = $config_collection;
  }

  /**
   * {@inheritdoc}
   */
  public function getPackages() {
    return $this->packages;
  }

  /**
   * {@inheritdoc}
   */
  public function setPackages(array $packages) {
    $this->packages = $packages;
  }

  /**
   * {@inheritdoc}
   */
  public function getPackage($machine_name) {
    if (isset($this->packages[$machine_name])) {
      return $this->packages[$machine_name];
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function savePackage(array &$package) {
    if (!empty($package['machine_name'])) {
      $this->packages[$package['machine_name']] = $package;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function filterPackages(array $packages, $namespace = '', $only_exported = FALSE) {
    $result = array();
    foreach ($packages as $key => $package) {
      // A package matches the namespace if:
      // - it's prefixed with the namespace, or
      // - it's assigned to a bundle named for the namespace, or
      // - we're looking only for exported packages and it's not exported.
      if (empty($namespace) || (strpos($package['machine_name'], $namespace . '_') === 0) ||
        (isset($package['bundle']) && $package['bundle'] === $namespace) ||
        ($only_exported && $package['status'] === FeaturesManagerInterface::STATUS_NO_EXPORT)) {
        $result[$key] = $package;
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getAssigner() {
    if (empty($this->assigner)) {
      $this->setAssigner(\Drupal::service('features_assigner'));
    }
    return $this->assigner;
  }

  /**
   * {@inheritdoc}
   */
  public function setAssigner(FeaturesAssignerInterface $assigner) {
    $this->assigner = $assigner;
    $this->reset();
  }

  /**
   * {@inheritdoc}
   */
  public function getGenerator() {
    return $this->generator;
  }

  /**
   * {@inheritdoc}
   */
  public function setGenerator(FeaturesGeneratorInterface $generator) {
    $this->generator = $generator;
  }

  /**
   * {@inheritdoc}
   */
  public function getExportSettings() {
    return $this->settings->get('export');
  }

  /**
   * {@inheritdoc}
   */
  public function getSettings() {
    return $this->settings;
  }

  /**
   * Returns the path to an extension info.yml file.
   *
   * @param mixed $extension
   *   The string name of an extension, or a full Extension object/
   * @param string $type
   *   The type of extension/
   *
   * @return string
   *   A file path.
   */
  protected function getExtensionPath($extension, $type = 'module') {
    if (is_string($extension)) {
      return drupal_get_filename($type, $extension);
    }
    else {
      return $extension->getPathname();
    }
  }

  /**
   * Returns the contents of an extensions info.yml file.
   *
   * @param mixed $extension
   *   The string name of an extension, or a full Extension object.
   *
   * @return array
   *   An array representing data in an info.yml file.
   */
  protected function getExtensionInfo($extension, $type = 'module') {
    $info_file_uri = $this->getExtensionPath($extension, $type);
    return \Drupal::service('info_parser')->parse($info_file_uri);
  }

  /**
   * {@inheritdoc}
   */
  public function isFeatureModule($module, FeaturesBundleInterface $bundle = NULL) {
    $info = $this->getExtensionInfo($module);
    if (isset($info['features'])) {
      // If no bundle was requested, it's enough that this is a feature.
      if (is_null($bundle)) {
        return TRUE;
      }
      // If the default bundle was requested, look for features where
      // the bundle is not set.
      elseif ($bundle->isDefault()) {
        return !isset($info['features']['bundle']);
      }
      // If we have a bundle name, look for it.
      else {
        return (isset($info['features']['bundle']) && ($info['features']['bundle'] == $bundle->getMachineName()));
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getExistingPackages($enabled = FALSE, FeaturesBundleInterface $bundle = NULL) {
    $result = array();
    if ($enabled) {
      $modules = $this->moduleHandler->getModuleList();
    }
    else {
      // ModuleHandler::getModuleList() returns data only for installed
      // modules. We want to search all possible exports for Features that
      // might be disabled.
      $listing = new ExtensionDiscovery(\Drupal::root());
      $modules = $listing->scan('module');
    }

    // Find features modules that are in the current bundle.
    foreach ($modules as $name => $module) {
      if ($this->isFeatureModule($module, $bundle)) {
        $short_name = $bundle ? $bundle->getShortName($name) : $name;
        $result[$short_name] = $this->getExtensionInfo($module);
        $result[$short_name]['status'] = $this->moduleHandler->moduleExists($name)
          ? FeaturesManagerInterface::STATUS_ENABLED
          : FeaturesManagerInterface::STATUS_DISABLED;
      }
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function listPackageDirectories(array $machine_names = array(), FeaturesBundleInterface $bundle = NULL) {
    if (empty($machine_names)) {
      $machine_names = array_keys($this->getPackages());
    }

    // If the bundle is a profile, then add the profile's machine name.
    if (isset($bundle) && $bundle->isProfile() && !in_array($bundle->getProfileName(), $machine_names)) {
      $machine_names[] = $bundle->getProfileName();
    }

    $modules = $this->getAllModules($bundle);
    // Filter to include only the requested packages.
    $modules = array_intersect_key($modules, array_fill_keys($machine_names, NULL));
    $directories = array();
    foreach ($modules as $name => $module) {
      $directories[$name] = $module->getPath();
    }

    return $directories;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllModules(FeaturesBundleInterface $bundle = NULL) {
    // ModuleHandler::getModuleDirectories() returns data only for installed
    // modules. system_rebuild_module_data() includes only the site's install
    // profile directory, while we may need to include a custom profile.
    // @see _system_rebuild_module_data().
    $listing = new ExtensionDiscovery(\Drupal::root());

    $profile_directories = [];
    // Register the install profile.
    $installed_profile = drupal_get_profile();
    if ($installed_profile) {
      $profile_directories[] = drupal_get_path('profile', $installed_profile);
    }
    if (isset($bundle) && $bundle->isProfile()) {
      $profile_directory = 'profiles/' . $bundle->getProfileName();
      if (($bundle->getProfileName() != $installed_profile) && is_dir($profile_directory)) {
        $profile_directories[] = $profile_directory;
      }
    }
    $listing->setProfileDirectories($profile_directories);

    // Find modules.
    $modules = $listing->scan('module');

    // Find installation profiles.
    $profiles = $listing->scan('profile');

    foreach ($profiles as $key => $profile) {
      $modules[$key] = $profile;
    }

    $return = array();
    // Detect modules by namespace.
    // If namespace is provided but is empty, then match all modules.
    foreach ($modules as $module_name => $extension) {
      if ($this->isFeatureModule($extension) && (!isset($bundle) || $bundle->isDefault() || $bundle->inBundle($module_name))) {
        $return[$module_name] = $extension;
      }
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function initPackage($machine_name, $name = NULL, $description = '', $type = 'module') {
    if (!isset($this->packages[$machine_name])) {
      return $this->packages[$machine_name] = $this->getProject($machine_name, $name, $description, $type);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function initPackageFromInfo($machine_name, $info) {
    $package = $this->initPackage($machine_name, $info['name'], !empty($info['description']) ? $info['description'] : '');
    $bundle = $this->getAssigner()->findBundle($info);
    $package['bundle'] = isset($bundle) ? $bundle->getMachineName() : '';
    $package['info'] = $info;
    $package['config_orig'] = $this->listExtensionConfig($machine_name);
    $this->savePackage($package);
    return $package;
  }

  /**
   * {@inheritdoc}
   */
  public function assignConfigPackage($package_name, array $item_names, $force = FALSE) {
    $config_collection = $this->getConfigCollection();

    $packages =& $this->packages;
    if (isset($packages[$package_name])) {
      $package =& $packages[$package_name];
    }
    else {
      throw new \Exception($this->t('Failed to package @package_name. Package not found.', ['@package_name' => $package_name]));
    }

    foreach ($item_names as $item_name) {
      if (isset($config_collection[$item_name])) {
        // Add to the package if:
        // - force is set or
        //   - the item hasn't already been assigned elsewhere, and
        //   - the package hasn't been excluded.
        // - and the item isn't already in the package.

        // Determine if the item is provided by an extension.
        $extension_provided = $config_collection[$item_name]['extension_provided'] === TRUE;
        // If this is the profile bundle, we can reassign extension-provided configuration.
        $already_assigned = !empty($config_collection[$item_name]['package']) && !($extension_provided && $this->getAssigner()->getBundle()->isProfilePackage($package['machine_name']));
        $excluded_from_package = in_array($package_name, $config_collection[$item_name]['package_excluded']);
        $already_in_package = in_array($item_name, $package['config']);
        if (($force || (!$already_assigned && !$excluded_from_package)) && !$already_in_package) {
          // Add the item to the package's config array.
          $package['config'][] = $item_name;
          // Mark the item as already assigned.
          $config_collection[$item_name]['package'] = $package_name;
          // For configuration in the InstallStorage::CONFIG_INSTALL_DIRECTORY
          // directory, set any module dependencies of the configuration item
          // as package dependencies.
          // As its name implies, the core-provided
          // InstallStorage::CONFIG_OPTIONAL_DIRECTORY should not create
          // dependencies.
          if ($config_collection[$item_name]['subdirectory'] === InstallStorage::CONFIG_INSTALL_DIRECTORY && isset($config_collection[$item_name]['data']['dependencies']['module'])) {
            $dependencies =& $package['dependencies'];
            $this->mergeUniqueItems($dependencies, $config_collection[$item_name]['data']['dependencies']['module']);
          }
        }
      }
    }

    $this->setConfigCollection($config_collection);
  }

  /**
   * {@inheritdoc}
   */
  public function assignConfigByPattern(array $patterns) {
    // Regular expressions for items that are likely to generate false
    // positives when assigned by pattern.
    $false_positives = [
      // Blocks with the page title should not be assigned to a 'page' package.
      '/block\.block\..*_page_title/',
    ];
    $config_collection = $this->getConfigCollection();
    // Reverse sort by key so that child package will claim items before parent
    // package. E.g., event_registration will claim before event.
    krsort($config_collection);
    foreach ($patterns as $pattern => $machine_name) {
      if (isset($this->packages[$machine_name])) {
        foreach ($config_collection as $item_name => $item) {
          // Test for and skip false positives.
          foreach ($false_positives as $false_positive) {
            if (preg_match($false_positive, $item_name)) {
              continue 2;
            }
          }

          if (empty($item['package']) && preg_match('/[_\-.]' . $pattern . '[_\-.]/', '.' . $item['name_short'] . '.')) {
            try {
              $this->assignConfigPackage($machine_name, [$item_name]);
            }
            catch (\Exception $exception) {
              \Drupal::logger('features')->error($exception->getMessage());
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function assignConfigDependents(array $item_names = NULL, $package = NULL) {
    $config_collection = $this->getConfigCollection();
    if (empty($item_names)) {
      $item_names = array_keys($config_collection);
    }
    foreach ($item_names as $item_name) {
      if (!empty($config_collection[$item_name]['package'])) {
        foreach ($config_collection[$item_name]['dependents'] as $dependent_item_name) {
          if (isset($config_collection[$dependent_item_name]) && (!empty($package) || empty($config_collection[$dependent_item_name]['package']))) {
            try {
              $package_name = !empty($package) ? $package : $config_collection[$item_name]['package'];
              // If a Package is specified, force assign it to the given
              // package.
              $this->assignConfigPackage($package_name, [$dependent_item_name], !empty($package));
            }
            catch (\Exception $exception) {
              \Drupal::logger('features')->error($exception->getMessage());
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function assignInterPackageDependencies(array &$packages) {
    $config_collection = $this->getConfigCollection();
    foreach ($packages as &$package) {
      foreach ($package['config'] as $item_name) {
        if (!empty($config_collection[$item_name]['data']['dependencies']['config'])) {
          foreach ($config_collection[$item_name]['data']['dependencies']['config'] as $dependency_name) {
            if (isset($config_collection[$dependency_name])) {
              // If the required item is assigned to one of the packages, add
              // a dependency on that package.
              if (!empty($config_collection[$dependency_name]['package']) && array_key_exists($config_collection[$dependency_name]['package'], $packages)) {
                $this->mergeUniqueItems($package['dependencies'], [$this->getAssigner()->getBundle()->getFullName($config_collection[$dependency_name]['machine_name'])]);
              }
              // Otherwise, if the dependency is provided by an existing
              // feature, add a dependency on that feature.
              elseif (!empty($config_collection[$dependency_name]['providing_feature'])) {
                $this->mergeUniqueItems($package['dependencies'], [$config_collection[$dependency_name]['providing_feature']]);
              }
            }
          }
        }
      }
    }
  }

  /**
   * Merges a set of new item into an array and sorts the result.
   *
   * Only unique values are retained.
   *
   * @param array &$items
   *   An array of items.
   * @param array $new_items
   *   An array of new items to be merged in.
   */
  protected function mergeUniqueItems(&$items, $new_items) {
    $items = array_unique(array_merge($items, $new_items));
    sort($items);
  }

  /**
   * Initializes and returns a package or profile array.
   *
   * @param string $machine_name
   *   Machine name of the package.
   * @param string $name
   *   Human readable name of the package.
   * @param string $description
   *   Description of the package.
   * @param string $type
   *   Type of project.
   *
   * @return array
   *   An array with the following keys:
   *   - 'machine_name': machine name of the project such as 'example_article'.
   *     'article'.
   *   - 'name': human readable name of the project such as 'Example Article'.
   *   - 'description': description of the project.
   *   - 'type': type of Drupal project ('profile' or 'module').
   *   - 'core': Drupal core compatibility ('8.x'),
   *   - 'dependencies': array of module dependencies.
   *   - 'themes': array of names of themes to enable.
   *   - 'config': array of names of configuration items.
   *   - 'status': the status of the project module
   *   - 'directory': the extension's directory.
   *   - 'files' array of files, each having the following keys:
   *      - 'filename': the name of the file.
   *      - 'subdirectory': any subdirectory of the file within the extension
   *         directory.
   *      - 'string': the contents of the file.
   */
  protected function getProject($machine_name, $name = NULL, $description = '', $type = 'module') {
    $project = [
      'machine_name' => $machine_name,
      'name' => $name,
      'description' => $description,
      'type' => $type,
      'core' => '8.x',
      'dependencies' => [],
      'themes' => [],
      'config' => [],
      'status' => FeaturesManagerInterface::STATUS_DEFAULT,
      'version' => '',
      'state' => FeaturesManagerInterface::STATE_DEFAULT,
      'directory' => $machine_name,
      'files' => []
    ];
    if ($type == 'module') {
      $this->setPackageNames($project);
    }

    return $project;
  }

  /**
   * Fills in module-specific properties, such as status and version.
   *
   * @param array &$package
   *   A package array, passed by reference.
   */
  protected function setPackageNames(array &$package) {
    $module_list = $this->getAllModules();
    $full_name = $this->getAssigner()->getBundle()->getFullName($package['machine_name']);
    if (isset($module_list[$full_name])) {
      $package['status'] = $this->moduleHandler->moduleExists($full_name)
        ? FeaturesManagerInterface::STATUS_ENABLED
        : FeaturesManagerInterface::STATUS_DISABLED;
      $info = $this->getExtensionInfo($full_name);
      if (!empty($info)) {
        $package['version'] = isset($info['version']) ? $info['version'] : '';
      }
    }
  }

  /**
   * Generates and adds .info.yml files to a package.
   *
   * @param array $package
   *   The package.
   */
  protected function addInfoFile(array &$package) {
    // Filter to standard keys of the profiles that we will use in info files.
    $info_keys = [
      'name',
      'description',
      'type',
      'core',
      'dependencies',
      'themes',
      'version'
    ];
    $info = array_intersect_key($package, array_fill_keys($info_keys, NULL));

    // Assign to a "package" named for the profile.
    if (isset($package['bundle'])) {
      $bundle = $this->getAssigner()->getBundle($package['bundle']);
    }
    // Save the current bundle in the info file so the package
    // can be reloaded later by the AssignmentPackages plugin.
    if (isset($bundle) && !$bundle->isDefault()) {
      $info['package'] = $bundle->getName();
      $info['features']['bundle'] = $bundle->getMachineName();
    }
    else {
      unset($info['features']['bundle']);
    }

    if (!empty($package['config'])) {
      foreach (array('excluded', 'required') as $constraint) {
        if (!empty($package[$constraint])) {
          $info['features'][$constraint] = $package[$constraint];
        }
        else {
          unset($info['features'][$constraint]);
        }
      }

      if (empty($info['features'])) {
        $info['features'] = TRUE;
      }
    }

    // The name and description need to be cast as strings from the
    // TranslatableMarkup objects returned by t() to avoid raising an
    // InvalidDataTypeException on Yaml serialization.
    foreach (array('name', 'description') as $key) {
      $info[$key] = (string) $info[$key];
    }

    // Add profile-specific info data.
    if ($info['type'] == 'profile') {
      // Set the distribution name.
      $info['distribution'] = [
        'name' => $info['name']
      ];
    }

    $package['files']['info'] = [
      'filename' => $package['machine_name'] . '.info.yml',
      'subdirectory' => NULL,
      // Filter to remove any empty keys, e.g., an empty themes array.
      'string' => Yaml::encode(array_filter($info))
    ];
  }

  /**
   * Generates and adds files to a given package or profile.
   */
  protected function addPackageFiles(array &$package) {
    $config_collection = $this->getConfigCollection();
    // Ensure the directory reflects the current full machine name.
    $package['directory'] = $package['machine_name'];
    // Only add files if there is at least one piece of configuration
    // present.
    if (!empty($package['config'])) {
      // Add .info.yml files.
      $this->addInfoFile($package);

      // Add configuration files.
      foreach ($package['config'] as $name) {
        $config = $config_collection[$name];
        // The UUID is site-specfic, so don't export it.
        if ($entity_type_id = $this->configManager->getEntityTypeIdByName($name)) {
          unset($config['data']['uuid']);
        }
        // User roles include all permissions currently assigned to them. To
        // avoid extraneous additions, reset permissions.
        if ($config['type'] == 'user_role') {
          $config['data']['permissions'] = [];
        }
        $package['files'][$name] = [
          'filename' => $config['name'] . '.yml',
          'subdirectory' => $config['subdirectory'],
          'string' => Yaml::encode($config['data'])
        ];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mergeInfoArray(array $info1, array $info2, array $keys = array()) {
    // If keys were specified, use only those.
    if (!empty($keys)) {
      $info2 = array_intersect_key($info2, array_fill_keys($keys, NULL));
    }

    // Ensure the entire 'features' data is replaced by new data.
    if (isset($info2['features'])) {
      unset($info1['features']);
    }

    $info = NestedArray::mergeDeep($info1, $info2);

    // Process the dependencies and themes keys.
    $keys = ['dependencies', 'themes'];
    foreach ($keys as $key) {
      if (isset($info[$key]) && is_array($info[$key])) {
        // NestedArray::mergeDeep() may produce duplicate values.
        $info[$key] = array_unique($info[$key]);
        sort($info[$key]);
      }
    }
    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function listConfigTypes($bundles_only = FALSE) {
    $definitions = [];
    foreach ($this->entityManager->getDefinitions() as $entity_type => $definition) {
      if ($definition->isSubclassOf('Drupal\Core\Config\Entity\ConfigEntityInterface')) {
        if (!$bundles_only || $definition->getBundleOf()) {
          $definitions[$entity_type] = $definition;
        }
      }
    }
    $entity_types = array_map(function (EntityTypeInterface $definition) {
      return $definition->getLabel();
    }, $definitions);
    // Sort the entity types by label, then add the simple config to the top.
    uasort($entity_types, 'strnatcasecmp');
    return $bundles_only ? $entity_types : [
      FeaturesManagerInterface::SYSTEM_SIMPLE_CONFIG => $this->t('Simple configuration'),
    ] + $entity_types;
  }

  /**
   * {@inheritdoc}
   */
  public function getModuleList(array $names = array(), $namespace = NULL) {
    // Get all modules regardless of enabled/disabled status.
    $modules = $this->getAllModules();
    if (!empty($names) || !empty($namespace)) {
      $return = [];

      // Detect modules by name.
      foreach ($names as $name) {
        if (!empty($name) && isset($modules[$name])) {
          $return[$name] = $modules[$name];
        }
      }

      // Detect modules by namespace.
      // If namespace is provided but is empty, then match all modules.
      if (isset($namespace)) {
        foreach ($modules as $module_name => $extension) {
          if (empty($namespace) || (strpos($module_name, $namespace) === 0)) {
            $return[$module_name] = $extension;
          }
        }
      }
      return $return;
    }
    return $modules;
  }

  /**
   * {@inheritdoc}
   */
  public function listExtensionConfig($extension) {
    // Convert to Component object if it is a string
    if (is_string($extension)) {
      // In case this is the short form machine name, convert to full form.
      $extension = $this->getAssigner()->getBundle()->getFullName($extension);
      $pathname = drupal_get_filename('module', $extension);
      $extension = new Extension(\Drupal::root(), 'module', $pathname);
    }
    return $this->extensionStorages->listExtensionConfig($extension);
  }

  /**
   * {@inheritdoc}
   */
  public function listExistingConfig($enabled = FALSE, FeaturesBundleInterface $bundle = NULL) {
    $config = array();
    $existing = $this->getExistingPackages($enabled, $bundle);
    foreach ($existing as $name => $info) {
      // Keys are configuration item names and values are providing extension
      // name.
      $new_config = array_fill_keys($this->listExtensionConfig($name), $name);
      $config = array_merge($config, $new_config);
    }
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function listConfigByType($config_type) {
    // For a given entity type, load all entities.
    if ($config_type && $config_type !== FeaturesManagerInterface::SYSTEM_SIMPLE_CONFIG) {
      $entity_storage = $this->entityManager->getStorage($config_type);
      $names = [];
      foreach ($entity_storage->loadMultiple() as $entity) {
        $entity_id = $entity->id();
        $label = $entity->label() ?: $entity_id;
        $names[$entity_id] = $label;
      }
    }
    // Handle simple configuration.
    else {
      $definitions = [];
      foreach ($this->entityManager->getDefinitions() as $entity_type => $definition) {
        if ($definition->isSubclassOf('Drupal\Core\Config\Entity\ConfigEntityInterface')) {
          $definitions[$entity_type] = $definition;
        }
      }
      // Gather the config entity prefixes.
      $config_prefixes = array_map(function (EntityTypeInterface $definition) {
        return $definition->getConfigPrefix() . '.';
      }, $definitions);

      // Find all config, and then filter our anything matching a config prefix.
      $names = $this->configStorage->listAll();
      $names = array_combine($names, $names);
      foreach ($names as $item_name) {
        foreach ($config_prefixes as $config_prefix) {
          if (strpos($item_name, $config_prefix) === 0) {
            unset($names[$item_name]);
          }
        }
      }
    }
    return $names;
  }

  /**
   * Loads configuration from storage into a property.
   */
  protected function initConfigCollection($reset = FALSE) {
    if ($reset || empty($this->configCollection)) {
      $config_collection = [];
      $config_types = $this->listConfigTypes();
      $dependency_manager = $this->configManager->getConfigDependencyManager();
      // List configuration provided by installed features.
      $existing_config = $this->listExistingConfig(TRUE);
      foreach (array_keys($config_types) as $config_type) {
        $config = $this->listConfigByType($config_type);
        foreach ($config as $item_name => $label) {
          $name = $this->getFullName($config_type, $item_name);
          $data = $this->configStorage->read($name);

          // Compute dependent config.
          $dependent_list = $dependency_manager->getDependentEntities('config', $name);
          $dependents = array();
          foreach ($dependent_list as $config_name => $item) {
            if (!isset($dependents[$config_name])) {
              $dependents[$config_name] = $config_name;
            }
            // Grab any dependent graph paths.
            if (isset($item['reverse_paths'])) {
              foreach ($item['reverse_paths'] as $dependent_name => $value) {
                if ($value && !isset($dependents[$dependent_name])) {
                  $dependents[$dependent_name] = $dependent_name;
                }
              }
            }
          }

          $config_collection[$name] = [
            'name' => $name,
            'name_short' => $item_name,
            'label' => $label,
            'type' => $config_type,
            'data' => $data,
            'dependents' => array_keys($dependents),
            // Default to the install directory.
            'subdirectory' => InstallStorage::CONFIG_INSTALL_DIRECTORY,
            'package' => '',
            'extension_provided' => NULL,
            'providing_feature' => isset($existing_config[$name]) ? $existing_config[$name] : NULL,
            'package_excluded' => [],
          ];
        }
      }
      $this->setConfigCollection($config_collection);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function prepareFiles(array &$packages) {
    foreach ($packages as &$package) {
      $this->addPackageFiles($package);
    }
    // Clean up the $package pass by reference.
    unset($package);
  }

  /**
   * {@inheritdoc}
   */
  public function getExportInfo($package, FeaturesBundleInterface $bundle = NULL) {

    $full_name = $package['machine_name'];

    $path = '';

    // Adjust export directory to be in profile.
    if (isset($bundle) && $bundle->isProfile()) {
      $path .= 'profiles/' . $bundle->getProfileName();
    }

    // If this is not the profile package, nest the directory.
    if (!isset($bundle) || !$bundle->isProfilePackage($package['machine_name'])) {
      $path .= empty($path) ? 'modules' : '/modules';
      $export_settings = $this->getExportSettings();
      if (!empty($export_settings['folder'])) {
        $path .= '/' . $export_settings['folder'];
      }
    }

    return array($full_name, $path);
  }

  /**
   * {@inheritdoc}
   */
  public function detectOverrides(array $feature, $include_new = FALSE) {
    $config_diff = \Drupal::service('config_update.config_diff');

    $different = array();
    foreach ($feature['config'] as $name) {
      $active = $this->configStorage->read($name);
      $extension = $this->extensionStorages->read($name);
      $extension = !empty($extension) ? $extension : array();
      if (($include_new || !empty($extension)) && !$config_diff->same($extension, $active)) {
        $different[] = $name;
      }
    }

    if (!empty($different)) {
      $feature['state'] = FeaturesManagerInterface::STATE_OVERRIDDEN;
    }
    return $different;
  }

  /**
   * {@inheritdoc}
   */
  public function detectNew(array $feature) {
    $result = array();
    foreach ($feature['config'] as $name) {
      $extension = $this->extensionStorages->read($name);
      if (empty($extension)) {
        $result[] = $name;
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function detectMissing(array $feature) {
    $config = $this->getConfigCollection();
    $result = array();
    foreach ($feature['config_orig'] as $name) {
      if (!isset($config[$name])) {
        $result[] = $name;
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function reorderMissing(array $missing) {
    $list = array();
    $result = array();
    foreach ($missing as $full_name) {
      $this->addConfigList($full_name, $list);
    }
    foreach ($list as $full_name) {
      if (in_array($full_name, $missing)) {
        $result[] = $full_name;
      }
    }
    return $result;
  }

  protected function addConfigList($full_name, &$list) {
    if (!in_array($full_name, $list)) {
      array_unshift($list, $full_name);
      $value = $this->extensionStorages->read($full_name);
      if (isset($value['dependencies']['config'])) {
        foreach ($value['dependencies']['config'] as $config_name) {
          $this->addConfigList($config_name, $list);
        }
      }
    }
  }

    /**
   * {@inheritdoc}
   */
  public function statusLabel($status) {
    switch ($status) {
      case FeaturesManagerInterface::STATUS_NO_EXPORT:
        return t('Not exported');

      case FeaturesManagerInterface::STATUS_DISABLED:
        return t('Uninstalled');

      case FeaturesManagerInterface::STATUS_ENABLED:
        return t('Enabled');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function stateLabel($state) {
    switch ($state) {
      case FeaturesManagerInterface::STATE_DEFAULT:
        return t('Default');

      case FeaturesManagerInterface::STATE_OVERRIDDEN:
        return t('Changed');
    }
  }

}
