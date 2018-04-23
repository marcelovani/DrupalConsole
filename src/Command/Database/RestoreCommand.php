<?php

/**
 * @file
 * Contains \Drupal\Console\Command\Database\RestoreCommand.
 */

namespace Drupal\Console\Command\Database;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;
use Drupal\Console\Core\Command\Command;
use Drupal\Console\Command\Shared\ConnectTrait;

class RestoreCommand extends Command
{
    use ConnectTrait;

    /**
     * @var string
     */
    protected $appRoot;

    /**
     * RestoreCommand constructor.
     *
     * @param string $appRoot
     */
    public function __construct($appRoot)
    {
        $this->appRoot = $appRoot;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('database:restore')
            ->setDescription($this->trans('commands.database.restore.description'))
            ->addArgument(
                'database',
                InputArgument::OPTIONAL,
                $this->trans('commands.database.restore.arguments.database'),
                'default'
            )
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                $this->trans('commands.database.restore.options.file')
            )
            ->addOption(
               'yes',
               'y',
               InputOption::VALUE_NONE,
               $this->trans('application.options.yes')
            )
            ->setHelp($this->trans('commands.database.restore.help'))
            ->setAliases(['dbr'])
            ->enableMaintenance();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $database = $input->getArgument('database');
        $file = $input->getOption('file');
        $yes = $input->getOption('yes');
        $learning = $input->getOption('learning');
        $noInteraction = $input->getOption('no-interaction');
        $databaseConnection = $this->resolveConnection($database);

        if (!$file) {
            $this->getIo()->error(
                $this->trans('commands.database.restore.messages.no-file')
            );
            return 1;
        }

        if (strpos($file, '.sql.gz') !== false) {
            $catCommand = 'gunzip -c %s | ';
        } else {
            $catCommand = 'cat %s | ';
        }

        $commands = array();
        if ($databaseConnection['driver'] == 'mysql') {

          // Recreate database.
          $commands[] = array(
              'command' => 'database:drop',
              'options' => array(
                  'yes' => $yes,
                  'learning' => $learning,
                  'no-interaction' => $noInteraction,
              ),
          );
          $commands[] = array(
              'command' => 'database:create',
              'options' => array(
                  'learning' => $learning,
                  'no-interaction' => $noInteraction,
              ),
          );

          // Import dump.
          $commands[] = array(
              'command' => 'exec',
              'arguments' => array('bin' => sprintf(
                  $catCommand . 'mysql --user=%s --password=%s --host=%s --port=%s %s',
                  $file,
                  $databaseConnection['username'],
                  $databaseConnection['password'],
                  $databaseConnection['host'],
                  $databaseConnection['port'],
                  $databaseConnection['database']
              )),
            );
        } elseif ($databaseConnection['driver'] == 'pgsql') {
            $commands[] = array(
              'command' => 'exec',
              'arguments' => array('bin' => sprintf(
                  $catCommand . 'PGPASSWORD="%s" psql -w -U %s -h %s -p %s -d %s',
                  $file,
                  $databaseConnection['password'],
                  $databaseConnection['username'],
                  $databaseConnection['host'],
                  $databaseConnection['port'],
                  $databaseConnection['database']
              )),
            );
        }

        if ($this->runCommands($commands) != 0) {
            return 1;
        }

        $this->getIo()->success(
            sprintf(
              '%s %s',
              $this->trans('commands.database.restore.messages.success'),
              $file
            )
        );

        return 0;
    }
}
