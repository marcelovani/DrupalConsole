<?php

namespace Drupal\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\GenerateCommand;
use Symfony\Component\Filesystem\Filesystem;
use Drupal\Component\Utility\Crypt;
use Drupal\Console\Generator\DotenvInitGenerator;
use Webmozart\PathUtil\Path;
use Symfony\Component\Yaml\Yaml;

/**
 * Class InitCommand
 *
 * @package Drupal\Console\Command\Dotenv
 */
class DotenvInitCommand extends GenerateCommand
{
    /**
     * @var DotenvInitGenerator
     */
    protected $generator;

    private $defaultParameters = [
        'environment' => 'develop',
        'database_name' => 'drupal',
        'database_user' => 'drupal',
        'database_password' => 'drupal',
        'database_host' => 'mariadb',
        'database_port' => '3306',
        'host_name' => 'drupal.develop',
        'host_port' => '80',
        'drupal_root' => '/var/www/html',
        'server_root' => '/var/www/html/web'
    ];

    private $envParameters = [];

    /**
     * InitCommand constructor.
     *
     * @param DotenvInitGenerator $generator
     */
    public function __construct(
        DotenvInitGenerator $generator
    ) {
        $this->generator = $generator;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('dotenv:init')
            ->setDescription($this->trans('commands.dotenv.init.description'))
            ->addOption(
                'load-from-env',
                null,
                InputOption::VALUE_NONE,
                $this->trans('commands.dotenv.init.options.load-from-env')
            )
            ->addOption(
                'load-settings',
                null,
                InputOption::VALUE_NONE,
                $this->trans('commands.dotenv.init.options.load-settings')
            )
            ->addOption(
                'load-from-yml',
                '~/.console/sites/local.yml',
                InputOption::VALUE_OPTIONAL,
                $this->trans('commands.dotenv.init.options.load-yml')
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $this->populateEnvParameters($input);

        foreach ($this->envParameters as $key => $value) {
            if ($key == 'server_root' && isset($this->envParameters['drupal_root'])) {
                $value = $this->envParameters['drupal_root'] . '/web';
            }

            // Let the user modify the parameters.
            $this->envParameters[$key] = $this->getIo()->askEmpty(
                'Enter value for ' . strtoupper($key),
                $value
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fs = new Filesystem();

        $loadFromEnv = $input->getOption('load-from-env');

        $loadYml = $input->getOption('load-from-yml');
        if ($loadYml) {
            if (empty($this->envParameters)) {
                $this->populateEnvParameters($input);
            }
            $this->envParameters['load_yml'] = $loadYml;
        }

        if (empty($this->envParameters)) {
            $this->envParameters = $this->defaultParameters;
        }

        if ($loadFromEnv) {
            $this->envParameters['load_from_env'] = $loadFromEnv;
        }

        $loadSettings = $input->getOption('load-settings');
        if ($loadSettings) {
            $this->envParameters['load_settings'] = $loadSettings;
        }

        $this->copySettingsFile($fs);
        $this->copyEnvFile($fs);

        $this->generator->setIo($this->getIo());
        $this->generator->generate($this->envParameters);
    }

    /**
     * Populates the envParameters from default parameters or external source.
     *
     * @param InputInterface $input.
     **/
    protected function populateEnvParameters($input) {
        $env = $input->getOption('env');
        $ymlFile = $input->getOption('load-from-yml');

        $parameters = null;
        if (!empty($ymlFile)) {
            $parameters = $this->loadFromYml($ymlFile, $env);
        }

        if (!empty($parameters)) {
            $this->envParameters = $parameters;
        }
        else {
            $this->envParameters = $this->defaultParameters;
        }
    }

    /**
     * Loads parameters from a site yml file.
     *
     * @param string $file The full path of the yml file.
     * @param string $env The environment i.e. dev.
     *
     * @return array $parameters List of parameters.
     */
    protected function loadFromYml($file, $env) {
        $parameters = [];

        // Convert the alias for home directory into the real path i.e. ~/.console.
        if (substr($file, 0, 2) == '~/') {
            $file = getenv('HOME') . '/' . substr($file, 2, strlen($file));
        }

        $contents = Yaml::parse(file_get_contents($file));

        if (!isset($contents[$env])) {
            $this->getIo()->warning($this->trans('commands.dotenv.init.messages.env-not-found'));
            return false;
        }

        $items = $contents[$env];

        $parameters['environment'] = $env;
        $parameters['database_name'] = isset($items['db']['name']) ? $items['db']['name'] : '';
        $parameters['database_user'] = isset($items['db']['user']) ? $items['db']['user'] : '';
        $parameters['database_password'] = isset($items['db']['pass']) ? $items['db']['pass'] : '';
        $parameters['database_host'] = isset($items['db']['host']) ? $items['db']['host'] : '';
        $parameters['database_port'] = isset($items['db']['port']) ? $items['db']['port'] : '3306';
        $parameters['host_name'] = isset($items['host-name']) ? $items['host-name'] : '';
        $parameters['host_port'] = isset($items['host-port']) ? $items['host-port'] : '80';
        $parameters['drupal_root'] = isset($items['root']) ? $items['root'] : '';
        $parameters['server_root'] = isset($items['server-root']) ? $items['server-root'] : '';

        return $parameters;
    }

    protected function copySettingsFile(Filesystem $fs)
    {
        $sourceFile = $this->drupalFinder
                ->getDrupalRoot() . '/sites/default/default.settings.php';
        $destinationFile = $this->drupalFinder
                ->getDrupalRoot() . '/sites/default/settings.php';

        $directory = dirname($sourceFile);
        $permissions = fileperms($directory);
        $fs->chmod($directory, 0755);

        $this->validateFileExists($fs, $sourceFile);
        $this->backUpFile($fs, $destinationFile);

        $fs->copy(
            $sourceFile,
            $destinationFile
        );

        $this->validateFileExists($fs, $destinationFile);

        include_once $this->drupalFinder->getDrupalRoot() . '/core/includes/bootstrap.inc';
        include_once $this->drupalFinder->getDrupalRoot() . '/core/includes/install.inc';

        $settings['config_directories'] = [
            CONFIG_SYNC_DIRECTORY => (object) [
                'value' => Path::makeRelative(
                    $this->drupalFinder->getComposerRoot() . '/config/sync',
                    $this->drupalFinder->getDrupalRoot()
                ),
                'required' => true,
            ],
        ];

        $settings['settings']['hash_salt'] = (object) [
            'value'    => Crypt::randomBytesBase64(55),
            'required' => true,
        ];

        drupal_rewrite_settings($settings, $destinationFile);

        $this->showFileCreatedMessage($destinationFile);

        $fs->chmod($directory, $permissions);
    }

    private function copyEnvFile(Filesystem $fs)
    {
        $sourceFiles = [
            $this->drupalFinder->getComposerRoot() . '/example.gitignore',
            $this->drupalFinder->getComposerRoot() . '/.gitignore'
        ];

        $sourceFile = $this->validateFileExists($fs, $sourceFiles);

        $destinationFile = $this->drupalFinder
                ->getComposerRoot() . '/.gitignore';

        if ($sourceFile !== $destinationFile) {
            $this->backUpFile($fs, $destinationFile);
        }

        $fs->copy(
            $sourceFile,
            $destinationFile
        );

        $this->validateFileExists($fs, $destinationFile);

        $gitIgnoreContent = file_get_contents($destinationFile);
        $gitIgnoreDistFile = $this->drupalFinder->getComposerRoot() .
            $this->drupalFinder->getConsolePath() .
            'templates/files/.gitignore.dist';
        $gitIgnoreDistContent = file_get_contents($gitIgnoreDistFile);

        if (strpos($gitIgnoreContent, '.env') === false) {
            file_put_contents(
                $destinationFile,
                $gitIgnoreContent .
                $gitIgnoreDistContent
            );
        }

        $this->showFileCreatedMessage($destinationFile);
    }
}
