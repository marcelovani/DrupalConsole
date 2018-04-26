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

    private $envParameters = [
        'environment' => 'dev',
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
        $ymlFile = $input->getOption('load-from-yml');
        if (!empty($ymlFile)) {
            // Convert the alias for home directory into the real path i.e. ~/.console.
            if (substr($ymlFile, 0, 2) == '~/') {
                $ymlFile = getenv('HOME') . '/' . substr($ymlFile, 2, strlen($ymlFile));
            }
            $siteConfig = Yaml::parse(file_get_contents($ymlFile));
        }

        foreach ($this->envParameters as $key => $value) {
            if (key == 'server_root' && isset($this->envParameters['drupal_root'])) {
                $value = $this->envParameters['drupal_root'] . '/web';
            }
            // Override default option using config from yml.
            if (isset($env) && isset($siteConfig)) {
                $config = $siteConfig[$env];
                switch ($key) {
                    case 'database_name':
                        $value = isset($config['db']['name']) ? $config['db']['name'] : $value;
                        break;

                    case 'database_user':
                        $value = isset($config['db']['user']) ? $config['db']['user'] : $value;
                        break;

                    case 'database_password':
                        $value = isset($config['db']['pass']) ? $config['db']['pass'] : $value;
                        break;

                    case 'database_host':
                        $value = isset($config['db']['host']) ? $config['db']['host'] : $value;
                        break;

                    case 'database_port':
                        $value = isset($config['db']['port']) ? $config['db']['port'] : $value;
                        break;

                    case 'host_name':
                        $value = isset($config['host']) ? $config['host'] : $value;
                        break;

                    case 'host_port':
                        $value = isset($config['port']) ? $config['port'] : $value;
                        break;

                    case 'drupal_root':
                        $value = isset($config['root']) ? $config['root'] : $value;
                        break;

                    case 'server_root':
                        $value = isset($config['server-root']) ? $config['server-root'] : $value;
                        break;
                }
            }

            $this->envParameters[$key] = $this->getIo()->ask(
                'Enter value for ' . strtoupper($key),
                $value
            );

            // Store the env.
            if ($key == 'environment') {
                $env = $this->envParameters[$key];
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fs = new Filesystem();
        $loadFromEnv = $input->getOption('load-from-env');
        $loadSettings = $input->getOption('load-settings');
        if ($loadFromEnv) {
            $this->envParameters['load_from_env'] = $loadFromEnv;
        }
        if ($loadSettings) {
            $this->envParameters['load_settings'] = $loadSettings;
        }
        $this->copySettingsFile($fs);
        $this->copyEnvFile($fs);

        $this->generator->setIo($this->getIo());
        $this->generator->generate($this->envParameters);
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
